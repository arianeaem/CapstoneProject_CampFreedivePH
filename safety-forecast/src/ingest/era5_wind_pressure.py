"""
Source: ECMWF ERA5 Reanalysis, via the Copernicus Climate Data Store (CDS) API.
Pulls:  10u, 10v (wind vectors), i10fg (instantaneous 10m gust), msl (sea level pressure).
Auth:   requires a ~/.cdsapirc file with your CDS API key (from cds.climate.copernicus.eu).

Features:
- Requests one month per CDS call to respect CDS cost limits.
- Supports concurrent worker threads (default 2) to cut queue wait times.
- Auto-resume: checks file existence and size (>1000 bytes).
- Ensures parent directories exist prior to each CDS retrieve call.
- Spatial extraction via 4-corner bilinear interpolation (u/v/msl) and max-of-4-corners (gust).
- Reindexes onto full multi-year hourly canonical index localized to Asia/Manila (PHT).
"""

import sys
import argparse
import calendar
from pathlib import Path
from concurrent.futures import ThreadPoolExecutor, as_completed
import numpy as np
import pandas as pd
import xarray as xr
import cdsapi

from config import (
    LON_MIN, LON_MAX, LAT_MIN, LAT_MAX,
    ERA5_START_DATE, ERA5_END_DATE,
    RAW_DIR, INTERIM_DIR, target_hourly_index, TARGET_TIMEZONE, VARIABLES
)
from spatial_extraction import extract_era5_bilinear

OUT_RAW_DIR = RAW_DIR / "era5_wind_pressure"
OUT_INTERIM = INTERIM_DIR / "era5_wind_pressure.parquet"


def _year_months(start_date: str, end_date: str):
    start_year, start_month = int(start_date[:4]), int(start_date[5:7])
    end_year, end_month = int(end_date[:4]), int(end_date[5:7])
    y, m = start_year, start_month
    while (y, m) <= (end_year, end_month):
        yield y, m
        m += 1
        if m > 12:
            m = 1
            y += 1


def _download_single_month(c, year: int, month: int, out_file: Path) -> bool:
    if out_file.exists() and out_file.stat().st_size > 1000:
        print(f"Skipping ERA5 {year}-{month:02d}, already downloaded ({out_file.stat().st_size} bytes)")
        return True

    # Guarantee parent directory exists right before requesting and saving
    out_file.parent.mkdir(parents=True, exist_ok=True)
    days_in_month = calendar.monthrange(year, month)[1]
    print(f"Requesting ERA5 {year}-{month:02d} ({days_in_month} days) -> {out_file.name}...")

    try:
        c.retrieve(
            "reanalysis-era5-single-levels",
            {
                "product_type": "reanalysis",
                "variable": VARIABLES["era5"],
                "year": [str(year)],
                "month": [f"{month:02d}"],
                "day": [f"{d:02d}" for d in range(1, days_in_month + 1)],
                "time": [f"{h:02d}:00" for h in range(24)],
                "area": [LAT_MAX, LON_MIN, LAT_MIN, LON_MAX],  # [North, West, South, East]
                "format": "netcdf",
            },
            str(out_file),
        )
        if out_file.exists() and out_file.stat().st_size > 1000:
            print(f"  Successfully saved {out_file.name} ({out_file.stat().st_size} bytes)")
            return True
        else:
            print(f"[WARNING] ERA5 {year}-{month:02d} downloaded file is missing or suspiciously small!")
            return False
    except Exception as e:
        print(f"[ERROR] Failed downloading ERA5 for {year}-{month:02d}: {e}")
        return False


def download(start_date: str = ERA5_START_DATE, end_date: str = ERA5_END_DATE, workers: int = 2):
    OUT_RAW_DIR.mkdir(parents=True, exist_ok=True)
    c = cdsapi.Client()
    ym_list = list(_year_months(start_date, end_date))
    print(f"[ERA5] Starting download for {len(ym_list)} months ({start_date} to {end_date}) with {workers} worker(s)...")

    if workers <= 1:
        for year, month in ym_list:
            out_file = OUT_RAW_DIR / f"era5_{year}_{month:02d}.nc"
            _download_single_month(c, year, month, out_file)
    else:
        with ThreadPoolExecutor(max_workers=workers) as executor:
            future_to_ym = {
                executor.submit(_download_single_month, c, year, month, OUT_RAW_DIR / f"era5_{year}_{month:02d}.nc"): (year, month)
                for year, month in ym_list
            }
            for future in as_completed(future_to_ym):
                year, month = future_to_ym[future]
                try:
                    success = future.result()
                    if not success:
                        print(f"[RETRY NEEDED] ERA5 {year}-{month:02d} failed.")
                except Exception as exc:
                    print(f"[EXCEPTION] ERA5 {year}-{month:02d} raised: {exc}")

    print("[ERA5] Download batch complete.")


def to_interim(start_date: str = ERA5_START_DATE, end_date: str = ERA5_END_DATE):
    files = sorted(OUT_RAW_DIR.glob("era5_*.nc"))
    if not files:
        raise FileNotFoundError(f"No monthly ERA5 files found in {OUT_RAW_DIR} — run download() first")

    print(f"[ERA5] Processing {len(files)} monthly files for interim dataset...")
    ds = xr.open_mfdataset(files, combine="by_coords")
    
    # 2D Bilinear interpolation across 4 surrounding grid corners (and max corner for gust)
    interp_ds, meta = extract_era5_bilinear(ds)
    print(f"[ERA5] Spatial extraction completed for ({meta['target_lat']}° N, {meta['target_lon']}° E)")

    df = interp_ds.to_dataframe().reset_index()

    # ECMWF's newer CDS backend renamed the time coordinate from 'time' to 'valid_time'
    if "valid_time" in df.columns and "time" not in df.columns:
        df = df.rename(columns={"valid_time": "time"})

    df = df.rename(columns={
        "u10": "wind_u", "v10": "wind_v", "i10fg": "wind_gust", "msl": "slp",
    })

    # Calculate wind speed and meteorological direction from interpolated u/v vectors
    df["wind_speed"] = np.sqrt(df["wind_u"] ** 2 + df["wind_v"] ** 2)
    df["wind_dir"] = (270 - np.degrees(np.arctan2(df["wind_v"], df["wind_u"]))) % 360
    df["slp"] = df["slp"] / 100.0  # Pa -> hPa

    # ERA5 is native hourly reanalysis
    df["era5_is_interpolated"] = False

    # Localize UTC timestamps to Asia/Manila (PHT)
    df["time_pht"] = pd.to_datetime(df["time"]).dt.tz_localize("UTC").dt.tz_convert(TARGET_TIMEZONE)
    df = df.set_index("time_pht")[["wind_u", "wind_v", "wind_speed", "wind_gust", "wind_dir", "slp", "era5_is_interpolated"]]
    df = df[~df.index.duplicated(keep="first")].sort_index()

    # Physical lower bound: gust is at least sustained wind speed
    df["wind_gust"] = np.maximum(df["wind_gust"], df["wind_speed"])

    # Reindex across realized hourly steps to prevent trailing unreleased forecast NaNs
    realized_idx = pd.date_range(start=df.index[0], end=df.index[-1], freq="1h")
    missing = realized_idx.difference(df.index)
    if len(missing) > 0:
        print(f"[WARNING] {len(missing)} hours missing from ERA5 pull")
    df = df.reindex(realized_idx)

    # Operational lag: 120h (5 days)
    last_obs_utc = df.index[-1].tz_convert("UTC")
    cutoff_utc = last_obs_utc - pd.Timedelta(hours=120)
    df["is_provisional"] = df.index.tz_convert("UTC") > cutoff_utc

    # Revision risk: ERA5T trailing ~90 days (~3 months) subject to ECMWF monthly revisions
    revision_cutoff_utc = last_obs_utc - pd.Timedelta(days=90)
    df["era5t_revision_risk"] = df.index.tz_convert("UTC") >= revision_cutoff_utc

    OUT_INTERIM.parent.mkdir(parents=True, exist_ok=True)
    df.to_parquet(OUT_INTERIM)
    print(f"[ERA5] Wrote {len(df)} hourly rows -> {OUT_INTERIM}")
    return df


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description="Ingest ECMWF ERA5 Reanalysis data")
    parser.add_argument("--start-date", default=ERA5_START_DATE, help="Start date (YYYY-MM-DD)")
    parser.add_argument("--end-date", default=ERA5_END_DATE, help="End date (YYYY-MM-DD)")
    parser.add_argument("--workers", type=int, default=2, help="Number of concurrent CDS download workers")
    parser.add_argument("--to-interim-only", action="store_true", help="Only process existing raw files to interim")
    args = parser.parse_args()

    if args.to_interim_only:
        to_interim(args.start_date, args.end_date)
    else:
        download(args.start_date, args.end_date, workers=args.workers)
        to_interim(args.start_date, args.end_date)
