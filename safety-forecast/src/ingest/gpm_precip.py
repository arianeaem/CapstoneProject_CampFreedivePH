"""
Source: NASA GPM IMERG Final Run V07B (GPM_3IMERGHH.07) & Late Run V07 (GPM_3IMERGHHL.07)
Access: Remote spatial subsetting via NASA OPeNDAP DAP2

Optimized Architecture:
- Concurrent ThreadPoolExecutor with 48 worker threads (benchmarked optimal at ~3.3 granules/sec).
- HTTP connection pooling (size 96+) with exponential backoff on 429/500/502/503/504.
- Month-by-month incremental checkpointing: saves gpm_{run_type}_{year}_{month}.nc per month.
- Auto-resume: verifies existing month files by size and granule count, skipping completed months.
- Persistent failure log (gpm_failures.log) and JSON progress summary (gpm_ingestion_summary.json).
- Scientific integrity: missing values preserved as NaN; no blind interpolation.
"""

from datetime import datetime, timedelta, timezone
import os
import sys
import time
import json
import random
import argparse
from pathlib import Path
import calendar
from concurrent.futures import ThreadPoolExecutor, as_completed
import numpy as np
import pandas as pd
import requests
from requests.adapters import HTTPAdapter
from tqdm import tqdm
from urllib3.util.retry import Retry
import xarray as xr

from config import (
    LON_MIN, LON_MAX, LAT_MIN, LAT_MAX,
    IMERG_FINAL_START_DATE, IMERG_FINAL_END_DATE,
    IMERG_LATE_START_DATE, IMERG_LATE_END_DATE,
    RAW_DIR, INTERIM_DIR, VARIABLES
)
from spatial_extraction import extract_imerg

RAW_SUBDIR = RAW_DIR / "gpm_precip"
OUT_INTERIM = INTERIM_DIR / "gpm_precip.parquet"
FAILURE_LOG = RAW_SUBDIR / "gpm_failures.log"
SUMMARY_FILE = RAW_SUBDIR / "gpm_ingestion_summary.json"

DEFAULT_WORKERS = 48

LAT_IDX_MIN = int(np.floor((LAT_MIN + 89.95) / 0.1))
LAT_IDX_MAX = int(np.ceil((LAT_MAX + 89.95) / 0.1))
LON_IDX_MIN = int(np.floor((LON_MIN + 179.95) / 0.1))
LON_IDX_MAX = int(np.ceil((LON_MAX + 179.95) / 0.1))

LAT_COORDS = np.array([round(-89.95 + idx * 0.1, 2) for idx in range(LAT_IDX_MIN, LAT_IDX_MAX + 1)], dtype=np.float32)
LON_COORDS = np.array([round(-179.95 + idx * 0.1, 2) for idx in range(LON_IDX_MIN, LON_IDX_MAX + 1)], dtype=np.float32)


def create_session(pool_size: int = DEFAULT_WORKERS) -> requests.Session:
    """Configures a thread-safe requests session with connection pooling."""
    session = requests.Session()
    retries = Retry(
        total=5,
        backoff_factor=1.5,
        status_forcelist=[429, 500, 502, 503, 504],
        allowed_methods=["GET"],
        raise_on_status=False,
    )
    adapter = HTTPAdapter(
        max_retries=retries,
        pool_connections=max(pool_size * 2, 96),
        pool_maxsize=max(pool_size * 2, 96),
    )
    session.mount("https://", adapter)
    session.mount("http://", adapter)
    return session


def build_opendap_url(dt: pd.Timestamp, run_type: str = "final") -> str:
    year = dt.strftime("%Y")
    doy = dt.strftime("%j")
    d_str = dt.strftime("%Y%m%d")
    s_str = dt.strftime("%H%M%S")
    dt_end = dt + timedelta(minutes=29, seconds=59)
    e_str = dt_end.strftime("%H%M%S")
    minutes = dt.hour * 60 + dt.minute

    if run_type == "late":
        base_url = f"https://gpm1.gesdisc.eosdis.nasa.gov/opendap/GPM_L3/GPM_3IMERGHHL.07/{year}/{doy}"
        fn = f"3B-HHR-L.MS.MRG.3IMERG.{d_str}-S{s_str}-E{e_str}.{minutes:04d}.V07B.HDF5"
    else:
        base_url = f"https://gpm1.gesdisc.eosdis.nasa.gov/opendap/GPM_L3/GPM_3IMERGHH.07/{year}/{doy}"
        fn = f"3B-HHR.MS.MRG.3IMERG.{d_str}-S{s_str}-E{e_str}.{minutes:04d}.V07B.HDF5"

    var_name = VARIABLES["imerg"][0]
    constraint = f"{var_name}[0:1:0][{LON_IDX_MIN}:1:{LON_IDX_MAX}][{LAT_IDX_MIN}:1:{LAT_IDX_MAX}]"
    return f"{base_url}/{fn}.ascii?{constraint}"


def log_failure(dt: pd.Timestamp, run_type: str, url: str, error: str):
    RAW_SUBDIR.mkdir(parents=True, exist_ok=True)
    with open(FAILURE_LOG, "a", encoding="utf-8") as f:
        f.write(f"{datetime.now(timezone.utc).isoformat()} | {run_type} | {dt.isoformat()} | {url} | {error}\n")


def fetch_slice_grid(dt: pd.Timestamp, session: requests.Session, run_type: str = "final", max_retries: int = 5) -> np.ndarray:
    url = build_opendap_url(dt, run_type=run_type)
    for attempt in range(1, max_retries + 1):
        try:
            time.sleep(random.uniform(0.01, 0.04))
            response = session.get(url, timeout=25)
            if response.status_code in (429, 502, 503, 504):
                if attempt == max_retries:
                    log_failure(dt, run_type, url, f"Exhausted {max_retries} retries with HTTP {response.status_code}")
                sleep_time = (1.5 ** attempt) + random.uniform(0.5, 1.5)
                time.sleep(sleep_time)
                continue

            response.raise_for_status()
            rows = []
            for line in response.text.splitlines():
                if line.startswith("precipitation["):
                    parts = line.split(",", 1)
                    if len(parts) > 1:
                        vals = [float(x.strip()) for x in parts[1].split(",") if x.strip()]
                        vals = [v if v >= 0.0 else np.nan for v in vals]
                        rows.append(vals)
            if len(rows) == len(LON_COORDS):
                return np.array(rows, dtype=np.float32)
            elif attempt == max_retries:
                log_failure(dt, run_type, url, f"Incomplete DAP response: got {len(rows)}/{len(LON_COORDS)} rows (HTTP {response.status_code})")
        except Exception as e:
            if attempt == max_retries:
                log_failure(dt, run_type, url, str(e))
            time.sleep((1.5 ** attempt) + random.uniform(0.5, 1.5))

    return np.full((len(LON_COORDS), len(LAT_COORDS)), np.nan, dtype=np.float32)


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


def update_summary(summary_data: dict):
    existing = {}
    if SUMMARY_FILE.exists():
        try:
            with open(SUMMARY_FILE, "r", encoding="utf-8") as f:
                existing = json.load(f)
        except Exception:
            existing = {}
    key = f"{summary_data['run_type']}_{summary_data['year']}_{summary_data['month']:02d}"
    existing[key] = summary_data
    with open(SUMMARY_FILE, "w", encoding="utf-8") as f:
        json.dump(existing, f, indent=2)


def download(start_date: str = None, end_date: str = None, run_type: str = "final", max_workers: int = DEFAULT_WORKERS) -> list[Path]:
    """
    Downloads NASA GPM IMERG month by month with auto-resume.
    Saves gpm_{run_type}_{year}_{month:02d}.nc per completed month.
    """
    RAW_SUBDIR.mkdir(parents=True, exist_ok=True)
    if start_date is None:
        start_date = IMERG_FINAL_START_DATE if run_type == "final" else IMERG_LATE_START_DATE
    if end_date is None:
        end_date = IMERG_FINAL_END_DATE if run_type == "final" else IMERG_LATE_END_DATE

    ym_list = list(_year_months(start_date, end_date))
    print("=" * 80)
    print(f"NASA GPM IMERG ({run_type.upper()} RUN) MONTHLY INGESTION")
    print(f"Window:     {start_date} to {end_date} ({len(ym_list)} months)")
    print(f"Storage:    {RAW_SUBDIR}")
    print(f"Workers:    {max_workers} concurrent threads")
    print(f"Failures:   {FAILURE_LOG}")
    print("=" * 80)

    session = create_session(pool_size=max_workers)
    saved_files = []

    for year, month in ym_list:
        out_file = RAW_SUBDIR / f"gpm_{run_type}_{year}_{month:02d}.nc"
        days_in_month = calendar.monthrange(year, month)[1]

        m_start = f"{year}-{month:02d}-01 00:00:00"
        m_end = f"{year}-{month:02d}-{days_in_month:02d} 23:30:00"

        # Cap if month is at the boundary
        if f"{year}-{month:02d}" == start_date[:7]:
            m_start = f"{start_date} 00:00:00"
        if f"{year}-{month:02d}" == end_date[:7]:
            m_end = f"{end_date} 23:30:00"

        month_timestamps = pd.date_range(start=m_start, end=m_end, freq="30min")

        # Auto-resume check: skip if month file exists and has full granule count
        if out_file.exists() and out_file.stat().st_size > 1000:
            try:
                with xr.open_dataset(out_file) as ds_check:
                    if len(ds_check.time) >= len(month_timestamps):
                        print(f"Skipping {out_file.name}, already downloaded ({len(ds_check.time)} granules, {out_file.stat().st_size / 1024:.1f} KB).")
                        saved_files.append(out_file)
                        continue
            except Exception:
                pass

        t_month_start = time.time()
        print(f"\n[{year}-{month:02d}] Fetching {len(month_timestamps)} granules via {max_workers} workers...")

        grids = [np.zeros((len(LON_COORDS), len(LAT_COORDS)), dtype=np.float32) for _ in month_timestamps]

        def _fetch(i_dt):
            i, dt = i_dt
            return i, fetch_slice_grid(dt, session, run_type=run_type)

        pbar = tqdm(total=len(month_timestamps), desc=f"GPM {run_type.upper()} {year}-{month:02d}", dynamic_ncols=True)
        with ThreadPoolExecutor(max_workers=max_workers) as executor:
            futures = [executor.submit(_fetch, (i, dt)) for i, dt in enumerate(month_timestamps)]
            for future in as_completed(futures):
                i, grid = future.result()
                grids[i] = grid
                pbar.update(1)
        pbar.close()

        month_3d = np.array(grids, dtype=np.float32)
        month_ds = xr.Dataset(
            data_vars={"precipitation": (["time", "lon", "lat"], month_3d)},
            coords={"time": pd.DatetimeIndex(month_timestamps), "lon": LON_COORDS, "lat": LAT_COORDS},
            attrs={
                "title": f"NASA GPM IMERG {run_type.upper()} Run V07B Remote Spatial Subset",
                "region": "Balayan Bay / Verde Island Passage",
                "units": "mm/hr",
            },
        )

        temp_out = RAW_SUBDIR / f"{out_file.name}.tmp"
        month_ds.to_netcdf(temp_out)
        if out_file.exists():
            out_file.unlink()
        temp_out.rename(out_file)

        elapsed = time.time() - t_month_start
        rate = len(month_timestamps) / elapsed if elapsed > 0 else 0
        nan_count = int(np.isnan(month_3d).sum())
        total_cells = month_3d.size

        print(f"Saved -> {out_file.name} ({out_file.stat().st_size / 1024:.1f} KB in {elapsed:.1f}s, {rate:.2f} gr/s)")
        saved_files.append(out_file)

        update_summary({
            "run_type": run_type,
            "year": year,
            "month": month,
            "granules": len(month_timestamps),
            "file": out_file.name,
            "size_bytes": out_file.stat().st_size,
            "elapsed_sec": round(elapsed, 1),
            "rate_gr_per_sec": round(rate, 2),
            "nan_cells": nan_count,
            "total_cells": total_cells,
            "timestamp_utc": datetime.now(timezone.utc).isoformat(),
        })

    print(f"\n[GPM] Finished {run_type.upper()} ingestion: {len(saved_files)} monthly files saved in {RAW_SUBDIR}")
    return saved_files


def to_interim() -> Path:
    OUT_INTERIM.parent.mkdir(parents=True, exist_ok=True)
    raw_files = sorted(RAW_SUBDIR.glob("gpm_*.nc"))
    if not raw_files:
        raise FileNotFoundError(f"No GPM IMERG monthly NetCDF files found in {RAW_SUBDIR}")

    print(f"[GPM IMERG] Processing {len(raw_files)} monthly GPM files for spatial extraction...")
    dfs = []
    for f in raw_files:
        try:
            ds = xr.open_dataset(f)
            interp_ds, meta = extract_imerg(ds)
            da_point = interp_ds["precipitation"]
            df_30min = da_point.to_dataframe(name="rain_rate_mm_hr")[["rain_rate_mm_hr"]]
            dfs.append(df_30min)
        except Exception as e:
            print(f"Error processing {f.name}: {e}")

    df_all_30min = pd.concat(dfs).sort_index()
    df_all_30min = df_all_30min[~df_all_30min.index.duplicated(keep="last")]

    # Resample 30min -> 1h mean
    df_hourly = df_all_30min.resample("1h").mean()
    df_hourly.index.name = "timestamp"

    # Explicitly track interpolated rain hours (never interpolate silently!)
    nan_mask = df_hourly["rain_rate_mm_hr"].isna()
    df_hourly["rain_is_interpolated"] = nan_mask
    if nan_mask.any():
        df_hourly["rain_rate_mm_hr"] = df_hourly["rain_rate_mm_hr"].interpolate(method="time", limit_direction="both")

    total_hours = len(df_hourly)
    interp_hours = int(df_hourly["rain_is_interpolated"].sum())
    print(f"Hourly precipitation aggregation: {total_hours} rows ({interp_hours} interpolated, {interp_hours/total_hours*100:.2f}%)")

    df_hourly.to_parquet(OUT_INTERIM)
    print(f"[GPM IMERG] Wrote {len(df_hourly)} hourly rows -> {OUT_INTERIM}")
    return OUT_INTERIM


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description="NASA GPM IMERG Monthly Ingestion via Remote OPeNDAP Subsetting")
    parser.add_argument("--run-type", choices=["final", "late"], default="final",
                        help="IMERG run type: 'final' (default: 2020-10 to 2025-09) or 'late' (2025-04 to 2026-10)")
    parser.add_argument("--start-date", type=str, default=None, help="Start date (YYYY-MM-DD)")
    parser.add_argument("--end-date", type=str, default=None, help="End date (YYYY-MM-DD)")
    parser.add_argument("--workers", type=int, default=DEFAULT_WORKERS, help=f"Concurrent request threads (default: {DEFAULT_WORKERS})")
    parser.add_argument("--to-interim-only", action="store_true", help="Only process existing raw NetCDFs to interim Parquet")
    args = parser.parse_args()

    if args.to_interim_only:
        to_interim()
    else:
        download(start_date=args.start_date, end_date=args.end_date, run_type=args.run_type, max_workers=args.workers)
        to_interim()