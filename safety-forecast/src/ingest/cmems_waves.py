"""
Source: CMEMS Global Ocean Waves Analysis and Forecast (GLOBAL_ANALYSISFORECAST_WAV_001_027)
Gets:   Hs, Tp, swell height, wind wave height - 1/12 deg (0.083 deg), every 3 hours.
Login:  run `copernicusmarine login` once before running this script.
"""

import sys
import argparse
import calendar
from pathlib import Path
import numpy as np
import pandas as pd
import xarray as xr
import copernicusmarine as cm

from config import (
    LON_MIN, LON_MAX, LAT_MIN, LAT_MAX,
    WAVE_ANALYSIS_START_DATE, WAVE_ANALYSIS_END_DATE,
    RAW_DIR, INTERIM_DIR, target_hourly_index, TARGET_TIMEZONE, VARIABLES
)
from spatial_extraction import extract_nearest_ocean_cell

DATASET_ID = "cmems_mod_glo_wav_anfc_0.083deg_PT3H-i"  # 1/12° (0.083°) analysis/forecast product
OUT_RAW_DIR = RAW_DIR / "cmems_waves"
OUT_INTERIM = INTERIM_DIR / "cmems_waves.parquet"


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


def download(start_date: str = WAVE_ANALYSIS_START_DATE, end_date: str = WAVE_ANALYSIS_END_DATE):
    OUT_RAW_DIR.mkdir(parents=True, exist_ok=True)
    failed_months = []
    ym_list = list(_year_months(start_date, end_date))
    print(f"[CMEMS Waves] Starting download for {len(ym_list)} months ({start_date} to {end_date})...")

    for year, month in ym_list:
        out_file = OUT_RAW_DIR / f"waves_{year}_{month:02d}.nc"
        if out_file.exists() and out_file.stat().st_size > 1000:
            print(f"Skipping waves {year}-{month:02d}, already downloaded ({out_file.stat().st_size} bytes)")
            continue

        out_file.parent.mkdir(parents=True, exist_ok=True)
        days_in_month = calendar.monthrange(year, month)[1]
        start_dt = f"{year}-{month:02d}-01T00:00:00"
        end_dt = f"{year}-{month:02d}-{days_in_month:02d}T23:59:59"

        # Stop at end_date if it's in the same month
        if f"{year}-{month:02d}" == end_date[:7]:
            end_dt = f"{end_date}T23:59:59"

        print(f"Downloading wave analysis for {year}-{month:02d} ({start_dt} to {end_dt})...")
        try:
            cm.subset(
                dataset_id=DATASET_ID,
                variables=VARIABLES["waves"],
                minimum_longitude=LON_MIN, maximum_longitude=LON_MAX,
                minimum_latitude=LAT_MIN, maximum_latitude=LAT_MAX,
                start_datetime=start_dt, end_datetime=end_dt,
                output_filename=str(out_file),
            )
            if not out_file.exists() or out_file.stat().st_size == 0:
                print(f"[WARNING] Downloaded file for {year}-{month:02d} is empty!")
                failed_months.append((year, month, "empty file"))
        except Exception as e:
            print(f"[ERROR] Failed downloading waves for {year}-{month:02d}: {e}")
            failed_months.append((year, month, str(e)))

    if failed_months:
        print(f"[SUMMARY] Wave download finished with {len(failed_months)} failed/empty months: {failed_months}")
    else:
        print("[SUMMARY] All wave months successfully downloaded!")


def to_interim(start_date: str = WAVE_ANALYSIS_START_DATE, end_date: str = WAVE_ANALYSIS_END_DATE):
    raw_files = sorted(OUT_RAW_DIR.glob("waves_*.nc"))
    if not raw_files:
        single_raw = RAW_DIR / "cmems_waves.nc"
        if single_raw.exists():
            raw_files = [single_raw]
        else:
            raise FileNotFoundError(f"No waves NetCDF files found in {OUT_RAW_DIR}")

    print(f"[CMEMS Waves] Processing {len(raw_files)} monthly wave files...")
    extracted_dfs = []
    meta = None
    for f in raw_files:
        try:
            ds = xr.open_dataset(f)
            extracted, m = extract_nearest_ocean_cell(ds, primary_var="VHM0")
            if meta is None:
                meta = m
            df_m = extracted.to_dataframe().reset_index()
            extracted_dfs.append(df_m)
        except Exception as e:
            print(f"[ERROR] Failed reading {f.name}: {e}")

    df_raw = pd.concat(extracted_dfs).drop_duplicates(subset=["time"]).sort_values("time")
    print(f"[CMEMS Waves] Extracted nearest ocean cell: ({meta['selected_lat']}° N, {meta['selected_lon']}° E), distance: {meta['distance_km']} km")
    
    native_times = set(pd.to_datetime(df_raw["time"]).dt.tz_localize("UTC").dt.tz_convert(TARGET_TIMEZONE))

    # Combine the two swell parts (SW1 and SW2)
    sw1 = df_raw["VHM0_SW1"].fillna(0) if "VHM0_SW1" in df_raw else 0
    sw2 = df_raw["VHM0_SW2"].fillna(0) if "VHM0_SW2" in df_raw else 0
    total_swell = np.sqrt(sw1**2 + sw2**2)

    df = pd.DataFrame({
        "time": df_raw["time"],
        "hs": df_raw["VHM0"],
        "tp": df_raw["VTPK"],  # Peak wave period (consistent with PRD 1.0s MAE)
        "swell_height": total_swell,
        "wind_wave_height": df_raw.get("VHM0_WW", np.nan),
    })
    df["time_pht"] = pd.to_datetime(df["time"]).dt.tz_localize("UTC").dt.tz_convert(TARGET_TIMEZONE)
    df = df.set_index("time_pht")[["hs", "tp", "swell_height", "wind_wave_height"]]
    df = df[~df.index.duplicated(keep="first")].sort_index()

    # 3-hourly to hourly with linear interpolation (only between real values)
    df = df.resample("1h").interpolate(method="linear", limit_area="inside")

    # Reindex to the full hourly index of the wave range (2022-11-01 03:00:00 to 2026-10-03)
    full_idx = target_hourly_index(start_date, end_date)
    df = df.reindex(full_idx)
    if df["hs"].isna().any():
        df = df.interpolate(method="time", limit_area="inside").ffill()

    # Flag so the ML code knows which hours are interpolated
    df["wave_is_interpolated"] = [ts not in native_times for ts in df.index]

    # Mark the last 12 hours as provisional (waves are 12h late)
    df["is_provisional"] = False
    if len(df) >= 12:
        df.iloc[-12:, df.columns.get_loc("is_provisional")] = True

    OUT_INTERIM.parent.mkdir(parents=True, exist_ok=True)
    df.to_parquet(OUT_INTERIM)
    print(f"[CMEMS Waves] Wrote {len(df)} hourly rows -> {OUT_INTERIM}")
    return df


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description="Ingest CMEMS 1/12° Wave Analysis data")
    parser.add_argument("--start-date", default=WAVE_ANALYSIS_START_DATE, help="Start date (YYYY-MM-DD)")
    parser.add_argument("--end-date", default=WAVE_ANALYSIS_END_DATE, help="End date (YYYY-MM-DD)")
    parser.add_argument("--to-interim-only", action="store_true", help="Only process existing raw files to interim")
    args = parser.parse_args()

    if args.to_interim_only:
        to_interim(args.start_date, args.end_date)
    else:
        download(args.start_date, args.end_date)
        to_interim(args.start_date, args.end_date)