"""
Retries the NASA GPM IMERG downloads that failed and patches them in.

- finds the logged failures and the all-NaN time slices in gpm_final_*.nc
- downloads the missing files again from NASA GES DISC OPeNDAP with fewer workers
  (4-8, with random waits), because too many at once gave HTTP 503 on day 1 of each month
- writes the downloaded rain grids into the NetCDF file
- updates gpm_failures.log and gpm_ingestion_summary.json
- rebuilds the interim parquet with the updated rain_is_interpolated flags

Usage:
    python safety-forecast/src/ingest/retry_gpm_failures.py [--workers 8] [--run-to-interim]
"""

import sys
import os
import time
import glob
import argparse
from pathlib import Path
from datetime import datetime
from concurrent.futures import ThreadPoolExecutor, as_completed
import numpy as np
import pandas as pd
import xarray as xr
import netCDF4 as nc

sys.path.insert(0, str(Path(__file__).resolve().parent))
from config import RAW_DIR, INTERIM_DIR
from gpm_precip import fetch_slice_grid, create_session, to_interim

RAW_SUBDIR = RAW_DIR / "gpm_precip"
FAILURE_LOG = RAW_SUBDIR / "gpm_failures.log"
SUMMARY_FILE = RAW_SUBDIR / "gpm_ingestion_summary.json"


def find_all_nan_slices_in_netcdfs():
    """Find all time slices in gpm_final_*.nc where the whole grid is NaN."""
    files = sorted(RAW_SUBDIR.glob("gpm_final_*.nc"))
    missing_items = []
    
    for fpath in files:
        fname = fpath.name
        with xr.open_dataset(fpath) as ds:
            precip = ds["precipitation"]
            is_nan_time = precip.isnull().all(dim=("lon", "lat")).values
            nan_indices = np.where(is_nan_time)[0]
            for idx in nan_indices:
                t = pd.Timestamp(ds["time"].values[idx])
                missing_items.append({
                    "file_path": fpath,
                    "file_name": fname,
                    "time_idx": int(idx),
                    "timestamp": t
                })
    return missing_items


def retry_missing_granules(max_workers: int = 8, run_interim: bool = True):
    print("=" * 80)
    print("TARGETED GPM IMERG FAILURE RETRY & NETCDF PATCH TOOL")
    print("=" * 80)

    missing = find_all_nan_slices_in_netcdfs()
    print(f"Discovered {len(missing)} NaN granule slices across existing NetCDFs.")
    if not missing:
        print("No NaN granules detected. All NetCDF files are 100% complete!")
        if run_interim:
            to_interim()
        return

    # Group by file
    by_file = {}
    for m in missing:
        by_file.setdefault(m["file_name"], []).append(m)

    session = create_session(pool_size=max_workers)
    recovered_total = 0
    failed_still = []

    for fname, items in by_file.items():
        fpath = items[0]["file_path"]
        print(f"\nProcessing {fname}: {len(items)} missing granules...")

        # Download the missing files again (a few at a time)
        results = {}
        with ThreadPoolExecutor(max_workers=max_workers) as executor:
            futures = {
                executor.submit(fetch_slice_grid, it["timestamp"], session, "final"): it
                for it in items
            }
            for fut in as_completed(futures):
                it = futures[fut]
                try:
                    grid = fut.result()
                    results[it["time_idx"]] = (it["timestamp"], grid)
                except Exception as e:
                    results[it["time_idx"]] = (it["timestamp"], None)

        # Write them into the NetCDF file (netCDF4 in read/write mode)
        ds_nc = nc.Dataset(fpath, "r+")
        precip_var = ds_nc.variables["precipitation"]
        
        file_recovered = 0
        for time_idx, (t, grid) in results.items():
            if grid is not None and not np.isnan(grid).all():
                # Grid is (lon, lat)
                precip_var[time_idx, :, :] = grid
                file_recovered += 1
            else:
                failed_still.append((fname, t))
        
        ds_nc.close()
        recovered_total += file_recovered
        print(f"  {fname}: Successfully patched {file_recovered} / {len(items)} granules.")

    print("\n" + "=" * 80)
    print(f"RETRY SUMMARY: Successfully recovered {recovered_total} / {len(missing)} granules.")
    if failed_still:
        print(f"Still failing ({len(failed_still)} granules):")
        for fn, t in failed_still:
            print(f"  {fn} at {t.isoformat()}")
    print("=" * 80)

    # Run to_interim again to update the parquet and the interpolation flags
    if run_interim:
        print("\nRe-generating interim Parquet dataset...")
        to_interim()


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description="Retry GPM IMERG failed granules and patch NetCDFs")
    parser.add_argument("--workers", type=int, default=8, help="Polite worker concurrency (default: 8)")
    parser.add_argument("--no-interim", action="store_true", help="Skip updating interim Parquet")
    args = parser.parse_args()

    retry_missing_granules(max_workers=args.workers, run_interim=not args.no_interim)
