"""
Gets the values at our dive site from the gridded data.

Used by both the download (to_interim) and the check (check_coordinate_cells)
so they always pick the same cell.

Rules:
1. Site: Lat = 13.6874 N, Lon = 120.8931 E.
2. CMEMS waves: closest ocean cell that isn't NaN.
3. CMEMS currents: closest ocean cell that isn't NaN.
4. ERA5: bilinear interpolation of u10, v10, i10fg, msl from the 4 corners around the site.
   Wind speed and direction are computed from the interpolated u/v.
5. GPM IMERG rain: bilinear interpolation (or closest cell).
"""

from typing import Tuple, Dict, Any, List
import numpy as np
import xarray as xr

from config import SITE_LAT, SITE_LON

EARTH_RADIUS_KM = 6371.0


def haversine_distance_km(lat1: float, lon1: float, lat2: float, lon2: float) -> float:
    """Distance between two points in km."""
    phi1, phi2 = np.radians(lat1), np.radians(lat2)
    delta_phi = np.radians(lat2 - lat1)
    delta_lambda = np.radians(lon2 - lon1)
    a = (np.sin(delta_phi / 2.0) ** 2 +
         np.cos(phi1) * np.cos(phi2) * np.sin(delta_lambda / 2.0) ** 2)
    c = 2.0 * np.arctan2(np.sqrt(a), np.sqrt(1.0 - a))
    return float(EARTH_RADIUS_KM * c)


def _get_lat_lon_keys(ds: xr.Dataset) -> Tuple[str, str]:
    """Find the names of the lat and lon coordinates."""
    lat_key = next((k for k in ["latitude", "lat"] if k in ds.coords or k in ds.dims), None)
    lon_key = next((k for k in ["longitude", "lon"] if k in ds.coords or k in ds.dims), None)
    if not lat_key or not lon_key:
        raise KeyError(f"Could not identify lat/lon coordinates in dataset: {list(ds.coords.keys())}")
    return lat_key, lon_key


def extract_nearest_ocean_cell(
    ds: xr.Dataset,
    target_lat: float = SITE_LAT,
    target_lon: float = SITE_LON,
    primary_var: str = None
) -> Tuple[xr.Dataset, Dict[str, Any]]:
    """
    Find the closest ocean cell (not NaN) to (target_lat, target_lon).
    Returns (dataset_at_cell, info_dict).
    """
    lat_key, lon_key = _get_lat_lon_keys(ds)
    
    # If no variable is given, use the first one
    if not primary_var:
        primary_var = list(ds.data_vars.keys())[0]

    da = ds[primary_var]
    
    # Remove extra dimensions (e.g. time, depth) so we can check which cells have values
    spatial_slice = da
    for dim in spatial_slice.dims:
        if dim not in (lat_key, lon_key):
            spatial_slice = spatial_slice.isel({dim: 0})
            
    # Grid coordinates
    lat_vals = ds[lat_key].values
    lon_vals = ds[lon_key].values

    valid_cells: List[Tuple[float, float, float, float]] = []  # (dist_km, lat, lon, sample_val)
    
    for clat in lat_vals:
        for clon in lon_vals:
            val = float(spatial_slice.sel({lat_key: clat, lon_key: clon}, method="nearest").values)
            if not np.isnan(val):
                dist_km = haversine_distance_km(target_lat, target_lon, float(clat), float(clon))
                valid_cells.append((dist_km, float(clat), float(clon), val))

    if not valid_cells:
        raise ValueError(f"No valid non-NaN ocean cells found in dataset for variable '{primary_var}'.")

    # Closest first
    valid_cells.sort(key=lambda x: x[0])
    best_dist_km, best_lat, best_lon, sample_val = valid_cells[0]

    # Pick that point in the full dataset
    extracted = ds.sel({lat_key: best_lat, lon_key: best_lon}, method="nearest")

    metadata = {
        "target_lat": target_lat,
        "target_lon": target_lon,
        "selected_lat": round(best_lat, 4),
        "selected_lon": round(best_lon, 4),
        "distance_km": round(best_dist_km, 2),
        "primary_var": primary_var,
        "sample_val": round(sample_val, 4),
        "is_land_masked": False,
    }

    return extracted, metadata


def extract_era5_bilinear(
    ds: xr.Dataset,
    target_lat: float = SITE_LAT,
    target_lon: float = SITE_LON
) -> Tuple[xr.Dataset, Dict[str, Any]]:
    """
    Bilinear interpolation from the 4 grid corners for ERA5.
    Interpolates u10, v10, i10fg, msl, then computes wind speed and direction from u/v.
    """
    lat_key, lon_key = _get_lat_lon_keys(ds)
    
    # Bilinear interpolation for the smooth fields
    interp_ds = ds.interp({lat_key: target_lat, lon_key: target_lon}, method="linear")

    # For gusts (i10fg) interpolation lowers the peaks, so we take
    # the max of the 4 corners instead (PRD).
    lats = np.sort(ds[lat_key].values)
    lons = np.sort(ds[lon_key].values)
    
    lat_below = float(lats[lats <= target_lat][-1]) if any(lats <= target_lat) else float(lats[0])
    lat_above = float(lats[lats >= target_lat][0]) if any(lats >= target_lat) else float(lats[-1])
    lon_below = float(lons[lons <= target_lon][-1]) if any(lons <= target_lon) else float(lons[0])
    lon_above = float(lons[lons >= target_lon][0]) if any(lons >= target_lon) else float(lons[-1])

    lat_slice = slice(lat_above, lat_below) if ds[lat_key].values[0] > ds[lat_key].values[-1] else slice(lat_below, lat_above)
    lon_slice = slice(lon_below, lon_above)
    box_ds = ds.sel({lat_key: lat_slice, lon_key: lon_slice})
    if "i10fg" in box_ds:
        interp_ds["i10fg"] = box_ds["i10fg"].max(dim=[lat_key, lon_key])

    corners = [
        {"position": "NW", "latitude": lat_above, "longitude": lon_below},
        {"position": "NE", "latitude": lat_above, "longitude": lon_above},
        {"position": "SW", "latitude": lat_below, "longitude": lon_below},
        {"position": "SE", "latitude": lat_below, "longitude": lon_above},
    ]

    metadata = {
        "target_lat": target_lat,
        "target_lon": target_lon,
        "method": "bilinear_u_v_msl_with_max_corner_gust",
        "surrounding_corners": corners,
    }

    return interp_ds, metadata


def extract_imerg(
    ds: xr.Dataset,
    target_lat: float = SITE_LAT,
    target_lon: float = SITE_LON,
    method: str = "linear"
) -> Tuple[xr.Dataset, Dict[str, Any]]:
    """
    Get the GPM IMERG values at the site (bilinear interpolation by default).
    """
    lat_key, lon_key = _get_lat_lon_keys(ds)
    
    try:
        interp_ds = ds.interp({lat_key: target_lat, lon_key: target_lon}, method=method)
    except Exception:
        # If interpolation fails, use the closest cell
        interp_ds = ds.sel({lat_key: target_lat, lon_key: target_lon}, method="nearest")
        method = "nearest"

    metadata = {
        "target_lat": target_lat,
        "target_lon": target_lon,
        "method": f"imerg_{method}",
    }

    return interp_ds, metadata
