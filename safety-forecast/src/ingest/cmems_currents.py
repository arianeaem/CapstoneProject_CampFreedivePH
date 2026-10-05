"""
Source: CMEMS Global Ocean Physics Analysis and Forecast (GLOBAL_ANALYSISFORECAST_PHY_001_024)
Gets:   east (uo) and north (vo) surface current, plus utide and vtide (1/12 deg = 0.083 deg).
Login:  same `copernicusmarine login` as cmems_waves.py.
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
    CURRENT_SMOC_START_DATE, CURRENT_SMOC_END_DATE,
    RAW_DIR, INTERIM_DIR, target_hourly_index, TARGET_TIMEZONE, VARIABLES
)
from spatial_extraction import extract_nearest_ocean_cell

DATASET_ID = "cmems_mod_glo_phy_anfc_merged-uv_PT1H-i"  # 1/12° SMOC hourly instantaneous currents (with tides)
OUT_RAW_DIR = RAW_DIR / "cmems_currents"
OUT_INTERIM = INTERIM_DIR / "cmems_currents.parquet"


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


def download(start_date: str = CURRENT_SMOC_START_DATE, end_date: str = CURRENT_SMOC_END_DATE):
    OUT_RAW_DIR.mkdir(parents=True, exist_ok=True)
    failed_months = []
    ym_list = list(_year_months(start_date, end_date))
    print(f"[CMEMS Currents] Starting download for {len(ym_list)} months ({start_date} to {end_date})...")

    for year, month in ym_list:
        out_file = OUT_RAW_DIR / f"currents_{year}_{month:02d}.nc"
        if out_file.exists() and out_file.stat().st_size > 1000:
            print(f"Skipping currents {year}-{month:02d}, already downloaded ({out_file.stat().st_size} bytes)")
            continue

        out_file.parent.mkdir(parents=True, exist_ok=True)
        days_in_month = calendar.monthrange(year, month)[1]
        start_dt = f"{year}-{month:02d}-01T00:00:00"
        end_dt = f"{year}-{month:02d}-{days_in_month:02d}T23:59:59"
        
        # Stop at end_date if it's in the same month
        if f"{year}-{month:02d}" == end_date[:7]:
            end_dt = f"{end_date}T23:59:59"

        print(f"Downloading SMOC currents for {year}-{month:02d} ({start_dt} to {end_dt})...")
        try:
            cm.subset(
                dataset_id=DATASET_ID,
                variables=VARIABLES["currents"],
                minimum_longitude=LON_MIN, maximum_longitude=LON_MAX,
                minimum_latitude=LAT_MIN, maximum_latitude=LAT_MAX,
                minimum_depth=0, maximum_depth=1,
                start_datetime=start_dt, end_datetime=end_dt,
                output_filename=str(out_file),
            )
            if not out_file.exists() or out_file.stat().st_size == 0:
                print(f"[WARNING] Downloaded file for {year}-{month:02d} is empty!")
                failed_months.append((year, month, "empty file"))
        except Exception as e:
            print(f"[ERROR] Failed downloading currents for {year}-{month:02d}: {e}")
            failed_months.append((year, month, str(e)))

    if failed_months:
        print(f"[SUMMARY] Current download finished with {len(failed_months)} failed/empty months: {failed_months}")
    else:
        print("[SUMMARY] All current months successfully downloaded!")


def to_interim(start_date: str = CURRENT_SMOC_START_DATE, end_date: str = CURRENT_SMOC_END_DATE):
    raw_files = sorted(OUT_RAW_DIR.glob("currents_*.nc"))
    if not raw_files:
        # Use the single big file if there is one
        single_raw = RAW_DIR / "cmems_currents.nc"
        if single_raw.exists():
            raw_files = [single_raw]
        else:
            raise FileNotFoundError(f"No currents NetCDF files found in {OUT_RAW_DIR}")

    print(f"[CMEMS Currents] Processing {len(raw_files)} monthly current files...")
    extracted_dfs = []
    meta = None
    for f in raw_files:
        try:
            ds = xr.open_dataset(f)
            primary_v = "utotal" if "utotal" in ds.data_vars else "uo"
            extracted, m = extract_nearest_ocean_cell(ds, primary_var=primary_v)
            if meta is None:
                meta = m
            df_m = extracted.to_dataframe().reset_index()
            extracted_dfs.append(df_m)
        except Exception as e:
            print(f"[ERROR] Failed reading {f.name}: {e}")

    df_raw = pd.concat(extracted_dfs).drop_duplicates(subset=["time"]).sort_values("time")
    print(f"[CMEMS Currents SMOC] Extracted nearest ocean cell: ({meta['selected_lat']}° N, {meta['selected_lon']}° E), distance: {meta['distance_km']} km")
    native_times = set(pd.to_datetime(df_raw["time"]).dt.tz_localize("UTC").dt.tz_convert(TARGET_TIMEZONE))

    cols = {}
    if "utotal" in df_raw.columns:
        cols["utotal"] = "current_u"
        cols["vtotal"] = "current_v"
        cols["uo"] = "eulerian_u"
        cols["vo"] = "eulerian_v"
    else:
        cols["uo"] = "current_u"
        cols["vo"] = "current_v"

    if "utide" in df_raw.columns:
        cols["utide"] = "tide_u"
    if "vtide" in df_raw.columns:
        cols["vtide"] = "tide_v"

    if "vsdx" in df_raw.columns:
        cols["vsdx"] = "stokes_u"
    if "vsdy" in df_raw.columns:
        cols["vsdy"] = "stokes_v"

    df = df_raw.rename(columns=cols)
    df["time_pht"] = pd.to_datetime(df["time"]).dt.tz_localize("UTC").dt.tz_convert(TARGET_TIMEZONE)
    
    keep_cols = [c for c in ["current_u", "current_v", "eulerian_u", "eulerian_v", "tide_u", "tide_v", "stokes_u", "stokes_v"] if c in df.columns]
    df = df.set_index("time_pht")[keep_cols]
    df = df[~df.index.duplicated(keep="first")].sort_index()

    # Reindex to the full hourly index of the SMOC range (2020-11-01 to 2026-10-03)
    full_idx = target_hourly_index(start_date, end_date)
    df = df.reindex(full_idx)
    is_missing = df["current_u"].isna()
    if is_missing.any():
        print(f"[NOTICE] {is_missing.sum()} missing hours interpolated via time interpolation")
        df = df.interpolate(method="time").ffill()

    df["current_speed"] = np.sqrt(df["current_u"] ** 2 + df["current_v"] ** 2)
    df["current_dir"] = (np.degrees(np.arctan2(df["current_v"], df["current_u"]))) % 360

    if "eulerian_u" in df.columns and "eulerian_v" in df.columns:
        df["eulerian_speed"] = np.sqrt(df["eulerian_u"] ** 2 + df["eulerian_v"] ** 2)
        df["eulerian_dir"] = (np.degrees(np.arctan2(df["eulerian_v"], df["eulerian_u"]))) % 360

    if "tide_u" in df.columns and "tide_v" in df.columns:
        df["tide_speed"] = np.sqrt(df["tide_u"] ** 2 + df["tide_v"] ** 2)
        df["tide_dir"] = (np.degrees(np.arctan2(df["tide_v"], df["tide_u"]))) % 360

    if "stokes_u" in df.columns and "stokes_v" in df.columns:
        df["stokes_speed"] = np.sqrt(df["stokes_u"] ** 2 + df["stokes_v"] ** 2)
        df["stokes_dir"] = (np.degrees(np.arctan2(df["stokes_v"], df["stokes_u"]))) % 360

    # Log how well the parts add up
    if all(k in df.columns for k in ["current_u", "eulerian_u", "tide_u", "stokes_u"]):
        u_closure = np.abs(df["current_u"] - (df["eulerian_u"] + df["tide_u"] + df["stokes_u"]))
        v_closure = np.abs(df["current_v"] - (df["eulerian_v"] + df["tide_v"] + df["stokes_v"]))
        print(f"[Closure Test] Max |utotal - (uo+utide+vsdx)|: {u_closure.max():.6f} m/s, Mean: {u_closure.mean():.6f} m/s")
        print(f"[Closure Test] Max |vtotal - (vo+vtide+vsdy)|: {v_closure.max():.6f} m/s, Mean: {v_closure.mean():.6f} m/s")

    # Flag so the ML code knows which hours are interpolated
    df["current_is_interpolated"] = [ts not in native_times for ts in df.index]

    # Mark the last 24 hours as provisional (SMOC is 24h late)
    df["is_provisional"] = False
    if len(df) >= 24:
        df.iloc[-24:, df.columns.get_loc("is_provisional")] = True

    OUT_INTERIM.parent.mkdir(parents=True, exist_ok=True)
    df.to_parquet(OUT_INTERIM)
    print(f"[CMEMS Currents] Wrote {len(df)} hourly rows -> {OUT_INTERIM}")
    return df


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description="Ingest CMEMS SMOC Current data")
    parser.add_argument("--start-date", default=CURRENT_SMOC_START_DATE, help="Start date (YYYY-MM-DD)")
    parser.add_argument("--end-date", default=CURRENT_SMOC_END_DATE, help="End date (YYYY-MM-DD)")
    parser.add_argument("--to-interim-only", action="store_true", help="Only process existing raw files to interim")
    args = parser.parse_args()

    if args.to_interim_only:
        to_interim(args.start_date, args.end_date)
    else:
        download(args.start_date, args.end_date)
        to_interim(args.start_date, args.end_date)