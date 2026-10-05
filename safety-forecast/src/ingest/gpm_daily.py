"""
Downloads NASA GPM IMERG Final Daily V07B (GPM_3IMERGDF.07) using OPeNDAP DAP2.

Dates: 2020-10-01 to 2025-09-30 (1,826 days, the Final Run range).
Area: 30 cells (5 lon x 6 lat) over Balayan Bay and the Verde Island Passage.

Notes:
1. All 30 cells are kept in the raw file (no averaging yet).
2. Bilinear and closest-cell values are computed at the site (13.6874 N, 120.8931 E).
3. Units from .das: mm/day.
4. Dimensions from .das: [time][lon][lat].
5. Dates are in UTC (PHT = UTC+8).
6. Saved in its own gpm_daily folder so it doesn't get mixed with the half-hourly data.
"""

import os
import sys
import time
import json
import calendar
from pathlib import Path
from concurrent.futures import ThreadPoolExecutor, as_completed
import netrc
import requests
import numpy as np
import pandas as pd

SAFETY_DIR = Path(__file__).resolve().parents[2]
if str(SAFETY_DIR / "src" / "ingest") not in sys.path:
    sys.path.insert(0, str(SAFETY_DIR / "src" / "ingest"))

from config import SITE_LAT, SITE_LON

RAW_DIR = SAFETY_DIR / "data" / "raw" / "gpm_daily"
INTERIM_FILE = SAFETY_DIR / "data" / "interim" / "gpm_daily_precip.parquet"
CELLS_USED_FILE = RAW_DIR / "cells_used.json"
SUMMARY_FILE = RAW_DIR / "gpm_daily_summary.json"

START_DATE = "2020-10-01"
END_DATE = "2025-09-30"  # Exact verified last available day of IMERG Final V07B

BASE_OPENDAP = "https://gpm1.gesdisc.eosdis.nasa.gov/opendap/GPM_L3/GPM_3IMERGDF.07"
WORKERS = 5

# Grid for the Balayan Bay box
LON_INDICES = [3007, 3008, 3009, 3010, 3011]
LAT_INDICES = [1035, 1036, 1037, 1038, 1039, 1040]

LON_COORDS = [120.75, 120.85, 120.95, 121.05, 121.15]
LAT_COORDS = [13.55, 13.65, 13.75, 13.85, 13.95, 14.05]


def get_auth():
    netrc_path = os.path.expanduser("~/_netrc")
    n = netrc.netrc(netrc_path)
    user, _, pwd = n.authenticators("urs.earthdata.nasa.gov")
    return user, pwd


def write_cells_used_manifest():
    """Write cells_used.json with the 30 cells and the site weights."""
    dx = (SITE_LON - 120.85) / 0.1
    dy = (SITE_LAT - 13.65) / 0.1

    w_sw = float((1.0 - dx) * (1.0 - dy))
    w_se = float(dx * (1.0 - dy))
    w_nw = float((1.0 - dx) * dy)
    w_ne = float(dx * dy)

    manifest = {
        "dataset": "GPM_3IMERGDF.07 (V07B Daily Final)",
        "source_units": "mm/day",
        "time_convention": "UTC (00:00:00Z to 23:59:59Z, offset to PHT is +08:00)",
        "total_cells": 30,
        "lon_coordinates": LON_COORDS,
        "lat_coordinates": LAT_COORDS,
        "site_target": {
            "name": "Bagalangit / Mainit Point, Mabini, Batangas",
            "latitude": SITE_LAT,
            "longitude": SITE_LON
        },
        "extraction_methods": {
            "bilinear_interpolation": {
                "description": "2D bilinear interpolation from 4 surrounding grid corners",
                "corners": [
                    {"corner": "SW", "lat": 13.65, "lon": 120.85, "weight": round(w_sw, 5), "character": "Marine (Balayan Bay)"},
                    {"corner": "SE", "lat": 13.65, "lon": 120.95, "weight": round(w_se, 5), "character": "Coastal / Shore"},
                    {"corner": "NW", "lat": 13.75, "lon": 120.85, "weight": round(w_nw, 5), "character": "Marine / Bay"},
                    {"corner": "NE", "lat": 13.75, "lon": 120.95, "weight": round(w_ne, 5), "character": "Inland / Mabini Peninsula"}
                ]
            },
            "nearest_cell": {
                "description": "Nearest offshore marine cell to dive site",
                "lat": 13.65,
                "lon": 120.85,
                "distance_km": 6.34
            },
            "box_mean": {
                "description": "Unweighted arithmetic average of all 30 cells (includes Batangas mountains)",
                "recommended_for_site": False,
                "scientific_warning": "Box average includes inland mountain orography which inflates dive site marine rainfall."
            }
        }
    }
    RAW_DIR.mkdir(parents=True, exist_ok=True)
    with open(CELLS_USED_FILE, "w", encoding="utf-8") as f:
        json.dump(manifest, f, indent=2)
    print(f"Wrote cells manifest to: {CELLS_USED_FILE}", flush=True)


def build_day_url(year: int, month: int, day: int) -> str:
    date_str = f"{year:04d}{month:02d}{day:02d}"
    filename = f"3B-DAY.MS.MRG.3IMERG.{date_str}-S000000-E235959.V07B.nc4"
    slice_query = f"?precipitation[0:1:0][{LON_INDICES[0]}:1:{LON_INDICES[-1]}][{LAT_INDICES[0]}:1:{LAT_INDICES[-1]}]"
    return f"{BASE_OPENDAP}/{year:04d}/{month:02d}/{filename}.ascii{slice_query}"


def parse_dap2_ascii(text: str) -> np.ndarray:
    """Read the DAP2 text output into a (5, 6) array (lon, lat)."""
    grid = []
    for line in text.splitlines():
        if "precipitation.precipitation[" in line:
            parts = [p.strip() for p in line.split(",")]
            row = [float(x) for x in parts[1:]]
            grid.append(row)
    arr = np.array(grid, dtype=np.float32)
    # FillValue -9999.9 -> NaN
    arr[arr < -9000.0] = np.nan
    return arr


def fetch_single_day(session: requests.Session, auth: tuple, year: int, month: int, day: int):
    url = build_day_url(year, month, day)
    date_str = f"{year:04d}-{month:02d}-{day:02d}"
    for attempt in range(4):
        try:
            r = session.get(url, auth=auth, timeout=30)
            if r.status_code == 200:
                grid = parse_dap2_ascii(r.text)
                if grid.shape == (5, 6):
                    return {"date": date_str, "status": 200, "grid": grid}
                else:
                    return {"date": date_str, "status": "bad_shape", "grid": None}
            elif r.status_code in [429, 500, 502, 503, 504]:
                time.sleep(1.5 * (attempt + 1))
            elif r.status_code == 404:
                return {"date": date_str, "status": 404, "grid": None}
            else:
                return {"date": date_str, "status": r.status_code, "grid": None}
        except Exception:
            time.sleep(1.5 * (attempt + 1))
    return {"date": date_str, "status": "failed", "grid": None}


def ingest_month(session: requests.Session, auth: tuple, year: int, month: int) -> pd.DataFrame:
    """Download one month of daily data and return the 30 cells as a DataFrame."""
    num_days = calendar.monthrange(year, month)[1]
    month_str = f"{year:04d}_{month:02d}"
    checkpoint_file = RAW_DIR / f"gpm_daily_{month_str}.parquet"

    if checkpoint_file.exists():
        print(f"Month {month_str} already exists on disk, skipping download.", flush=True)
        return pd.read_parquet(checkpoint_file)

    t0 = time.time()
    month_results = []
    with ThreadPoolExecutor(max_workers=WORKERS) as executor:
        futures = {executor.submit(fetch_single_day, session, auth, year, month, d): d for d in range(1, num_days + 1)}
        for fut in as_completed(futures):
            res = fut.result()
            month_results.append(res)

    month_results.sort(key=lambda x: x["date"])
    elapsed = time.time() - t0
    success = sum(1 for r in month_results if r["status"] == 200)

    rows = []
    for r in month_results:
        d = r["date"]
        grid = r["grid"]
        if grid is not None:
            for i, lon in enumerate(LON_COORDS):
                for j, lat in enumerate(LAT_COORDS):
                    rows.append({
                        "date": d,
                        "lon": lon,
                        "lat": lat,
                        "precipitation_mm_day": float(grid[i, j])
                    })
        else:
            # Save the failed or missing day
            for lon in LON_COORDS:
                for lat in LAT_COORDS:
                    rows.append({
                        "date": d,
                        "lon": lon,
                        "lat": lat,
                        "precipitation_mm_day": np.nan
                    })

    df_month = pd.DataFrame(rows)
    df_month.to_parquet(checkpoint_file, index=False)
    print(f"Month {month_str}: {success}/{num_days} days ingested in {elapsed:.1f}s -> {checkpoint_file.name}", flush=True)
    return df_month


def run_full_daily_ingestion():
    RAW_DIR.mkdir(parents=True, exist_ok=True)
    write_cells_used_manifest()

    user, pwd = get_auth()
    auth = (user, pwd)

    session = requests.Session()
    adapter = requests.adapters.HTTPAdapter(pool_connections=WORKERS * 2, pool_maxsize=WORKERS * 2)
    session.mount("https://", adapter)

    start_dt = pd.Timestamp(START_DATE)
    end_dt = pd.Timestamp(END_DATE)

    print("=" * 85, flush=True)
    print("FULL NASA GPM IMERG DAILY V07B (GPM_3IMERGDF.07) INGESTION")
    print(f"Window: {START_DATE} to {END_DATE} (1,826 days | 5.0 years)")
    print(f"Storage: Raw 30 cells in {RAW_DIR}")
    print(f"Workers: {WORKERS} concurrent threads")
    print("=" * 85, flush=True)

    cur = start_dt
    all_months = []
    t_start = time.time()

    while cur <= end_dt:
        y, m = cur.year, cur.month
        df_m = ingest_month(session, auth, y, m)
        all_months.append(df_m)
        # Next month
        cur = (cur.replace(day=1) + pd.Timedelta(days=32)).replace(day=1)

    df_all_cells = pd.concat(all_months).reset_index(drop=True)
    total_grid_file = RAW_DIR / "gpm_daily_grid_30cells.parquet"
    df_all_cells.to_parquet(total_grid_file, index=False)
    print(f"\nWrote full 30-cell raw archive -> {total_grid_file} ({len(df_all_cells)} cell-days)", flush=True)

    # -------------------------------------------------------------------------
    # Values at the dive site
    # -------------------------------------------------------------------------
    print("\nComputing site-extracted daily rainfall time series...", flush=True)
    dx = (SITE_LON - 120.85) / 0.1
    dy = (SITE_LAT - 13.65) / 0.1

    dates = sorted(df_all_cells["date"].unique())
    site_records = []

    for d in dates:
        day_df = df_all_cells[df_all_cells["date"] == d]
        p_sw = day_df[(day_df["lon"] == 120.85) & (day_df["lat"] == 13.65)]["precipitation_mm_day"].values[0]
        p_se = day_df[(day_df["lon"] == 120.95) & (day_df["lat"] == 13.65)]["precipitation_mm_day"].values[0]
        p_nw = day_df[(day_df["lon"] == 120.85) & (day_df["lat"] == 13.75)]["precipitation_mm_day"].values[0]
        p_ne = day_df[(day_df["lon"] == 120.95) & (day_df["lat"] == 13.75)]["precipitation_mm_day"].values[0]

        # Bilinear
        p_bilinear = float((1 - dx) * (1 - dy) * p_sw + dx * (1 - dy) * p_se + (1 - dx) * dy * p_nw + dx * dy * p_ne)
        # Closest (SW ocean cell)
        p_nearest = float(p_sw)
        # Box average
        p_mean = float(day_df["precipitation_mm_day"].mean())

        site_records.append({
            "date": d,
            "precip_bilinear_mm_day": round(p_bilinear, 4),
            "precip_nearest_mm_day": round(p_nearest, 4),
            "precip_box_mean_mm_day": round(p_mean, 4),
            "rain_rate_site_mm_hr": round(p_bilinear / 24.0, 4)
        })

    site_df = pd.DataFrame(site_records)
    site_df.set_index("date", inplace=True)
    INTERIM_FILE.parent.mkdir(parents=True, exist_ok=True)
    site_df.to_parquet(INTERIM_FILE)
    print(f"Saved site-extracted series -> {INTERIM_FILE} ({len(site_df)} days)", flush=True)

    total_wall_min = (time.time() - t_start) / 60.0
    print("\n" + "=" * 85, flush=True)
    print(f"DAILY IMERG INGESTION COMPLETED IN {total_wall_min:.1f} MINUTES!")
    print(f"Dates Covered: {site_df.index.min()} to {site_df.index.max()} (N={len(site_df)})")
    print(f"Mean Site Rain: {site_df['precip_bilinear_mm_day'].mean():.2f} mm/day | Max: {site_df['precip_bilinear_mm_day'].max():.2f} mm/day")
    print("=" * 85, flush=True)


if __name__ == "__main__":
    run_full_daily_ingestion()
