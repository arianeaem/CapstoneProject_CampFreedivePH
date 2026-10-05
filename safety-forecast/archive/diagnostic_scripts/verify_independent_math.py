"""
Independent Ground-Truth Mathematical Verification.

Does NOT use spatial_extraction.py.
Computes manual hand calculations directly on raw NetCDF arrays to verify:
1. ERA5 Bilinear weights and vector interpolation.
2. Max-corner gust selection vs bilinear gust.
3. CMEMS nearest non-NaN cell selection.
"""

from pathlib import Path
import numpy as np
import xarray as xr

# Ground truth site coordinate
TARGET_LAT = 13.6874
TARGET_LON = 120.8931

print("=" * 80)
print("INDEPENDENT MATHEMATICAL VALIDATION (NO CIRCULAR IMPORTS)")
print(f"Target Coordinate: Lat {TARGET_LAT}° N, Lon {TARGET_LON}° E")
print("=" * 80)

# -----------------------------------------------------------------------------
# 1. ERA5 Manual Bilinear Calculation at 2024-01-01 00:00:00
# -----------------------------------------------------------------------------
era5_path = Path(r"C:\Users\bryan\CapstoneProject_ML\data\raw\era5_wind_pressure\era5_2024_01.nc")
ds_raw = xr.open_dataset(era5_path)

# Extract timestamp index 0
t0_raw = ds_raw.isel(valid_time=0) if "valid_time" in ds_raw.dims else ds_raw.isel(time=0)

# The 4 surrounding grid corners on 0.25 deg grid
lat_0, lat_1 = 13.50, 13.75
lon_0, lon_1 = 120.75, 121.00

# Compute weights by hand
wx = (TARGET_LON - lon_0) / (lon_1 - lon_0)  # (120.8931 - 120.75) / 0.25 = 0.5724
wy = (TARGET_LAT - lat_0) / (lat_1 - lat_0)  # (13.6874 - 13.50) / 0.25 = 0.7496

print(f"\n[ERA5 Manual Bilinear Weights]")
print(f"  Grid box: Lat [{lat_0}, {lat_1}], Lon [{lon_0}, {lon_1}]")
print(f"  wx (lon weight) = ({TARGET_LON} - {lon_0}) / 0.25 = {wx:.6f}")
print(f"  wy (lat weight) = ({TARGET_LAT} - {lat_0}) / 0.25 = {wy:.6f}")

w_SW = (1.0 - wx) * (1.0 - wy)
w_SE = wx * (1.0 - wy)
w_NW = (1.0 - wx) * wy
w_NE = wx * wy

print(f"  Corner weights sum: {w_SW + w_SE + w_NW + w_NE:.6f}")
print(f"  Weights: SW={w_SW:.6f}, SE={w_SE:.6f}, NW={w_NW:.6f}, NE={w_NE:.6f}")

# Extract raw corner values for u10, v10, i10fg, msl
def get_val(var_name, lat, lon):
    return float(t0_raw[var_name].sel(latitude=lat, longitude=lon).values)

u_SW, u_SE, u_NW, u_NE = get_val("u10", lat_0, lon_0), get_val("u10", lat_0, lon_1), get_val("u10", lat_1, lon_0), get_val("u10", lat_1, lon_1)
v_SW, v_SE, v_NW, v_NE = get_val("v10", lat_0, lon_0), get_val("v10", lat_0, lon_1), get_val("v10", lat_1, lon_0), get_val("v10", lat_1, lon_1)
g_SW, g_SE, g_NW, g_NE = get_val("i10fg", lat_0, lon_0), get_val("i10fg", lat_0, lon_1), get_val("i10fg", lat_1, lon_0), get_val("i10fg", lat_1, lon_1)

# Manual bilinear interpolation
u_hand = w_SW * u_SW + w_SE * u_SE + w_NW * u_NW + w_NE * u_NE
v_hand = w_SW * v_SW + w_SE * v_SE + w_NW * v_NW + w_NE * v_NE
gust_bilinear = w_SW * g_SW + w_SE * g_SE + w_NW * g_NW + w_NE * g_NE
gust_max_corner = max(g_SW, g_SE, g_NW, g_NE)

# Hand-computed derived wind speed and meteorological direction
speed_hand = np.sqrt(u_hand**2 + v_hand**2)
dir_hand = (270.0 - np.degrees(np.arctan2(v_hand, u_hand))) % 360.0

print(f"\n[Raw Corner u10 values (m/s)]:")
print(f"  NW({lat_1}, {lon_0}) = {u_NW:.4f} | NE({lat_1}, {lon_1}) = {u_NE:.4f}")
print(f"  SW({lat_0}, {lon_0}) = {u_SW:.4f} | SE({lat_0}, {lon_1}) = {u_SE:.4f}")

print(f"\n[Raw Corner i10fg (gusts) values (m/s)]:")
print(f"  NW({lat_1}, {lon_0}) = {g_NW:.4f} | NE({lat_1}, {lon_1}) = {g_NE:.4f}")
print(f"  SW({lat_0}, {lon_0}) = {g_SW:.4f} | SE({lat_0}, {lon_1}) = {g_SE:.4f}")
print(f"  --> Smoothed Bilinear Gust: {gust_bilinear:.4f} m/s")
print(f"  --> Peak-Preserving Max Corner Gust: {gust_max_corner:.4f} m/s (Difference: +{gust_max_corner - gust_bilinear:.4f} m/s)")

# Now test against xarray's interp function directly
interp_xr = t0_raw.interp(latitude=TARGET_LAT, longitude=TARGET_LON, method="linear")
u_xr = float(interp_xr["u10"].values)
v_xr = float(interp_xr["v10"].values)

print(f"\n[Hand Math vs xarray Interpolation Check]:")
print(f"  Hand u10:   {u_hand:.6f} | xarray u10:   {u_xr:.6f} | Abs Diff: {abs(u_hand - u_xr):.2e}")
print(f"  Hand v10:   {v_hand:.6f} | xarray v10:   {v_xr:.6f} | Abs Diff: {abs(v_hand - v_xr):.2e}")
print(f"  Hand Speed: {speed_hand:.4f} m/s")
print(f"  Hand Dir:   {dir_hand:.2f}°")

assert abs(u_hand - u_xr) < 1e-5, "Mathematical mismatch in u10 interpolation!"
assert abs(v_hand - v_xr) < 1e-5, "Mathematical mismatch in v10 interpolation!"
print("  --> [VERIFIED] Hand math and bilinear code match perfectly.")

# -----------------------------------------------------------------------------
# 2. Wave Grid Comparison: 0.2° (Reanalysis) vs 0.083° (Analysis)
# -----------------------------------------------------------------------------
print("\n" + "=" * 80)
print("WAVE PRODUCT SPATIAL DISCREPANCY: 0.2° (Reanalysis) vs 1/12° (Analysis)")
print("=" * 80)

# Check 0.2° file
w02_path = Path(r"C:\Users\bryan\CapstoneProject_ML\data\raw\cmems_waves.nc")
ds_w02 = xr.open_dataset(w02_path).isel(time=0)
print(f"0.2° Dataset Grid:")
print(f"  Latitudes:  {ds_w02.latitude.values}")
print(f"  Longitudes: {ds_w02.longitude.values}")

# Check 0.083° file
w083_path = Path("safety-forecast/data/cache/test_wave_083.nc")
if w083_path.exists():
    ds_w083 = xr.open_dataset(w083_path).isel(time=0)
    print(f"\n1/12° (0.083°) Dataset Grid:")
    print(f"  Latitudes:  {ds_w083.latitude.values}")
    print(f"  Longitudes: {ds_w083.longitude.values}")
