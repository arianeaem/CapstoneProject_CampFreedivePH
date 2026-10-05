"""
Times the daily IMERG (GPM_3IMERGDF.07) download for 1 month (30 days).

Downloads only our area with OPeNDAP DAP2 using several workers, to see how fast
it is (sec/day, total for 30 days) and if OPeNDAP is fast enough for 5 years
(~1,826 days) or if we need Harmony.
"""

import os
import sys
import time
import calendar
from concurrent.futures import ThreadPoolExecutor, as_completed
import netrc
import requests
import numpy as np
import pandas as pd

# Coordinates for Balayan Bay: [13.5:14.0, 120.7:121.1]
# LON 120.7 -> 3007, 121.1 -> 3011
# LAT 13.5 -> 1035, 14.0 -> 1040
LON_IDX_MIN, LON_IDX_MAX = 3007, 3011
LAT_IDX_MIN, LAT_IDX_MAX = 1035, 1040

BASE_OPENDAP = "https://gpm1.gesdisc.eosdis.nasa.gov/opendap/GPM_L3/GPM_3IMERGDF.07"
WORKERS = 5


def get_auth():
    netrc_path = os.path.expanduser("~/_netrc")
    n = netrc.netrc(netrc_path)
    user, _, pwd = n.authenticators("urs.earthdata.nasa.gov")
    return user, pwd


def build_day_url(year: int, month: int, day: int) -> str:
    date_str = f"{year:04d}{month:02d}{day:02d}"
    filename = f"3B-DAY.MS.MRG.3IMERG.{date_str}-S000000-E235959.V07B.nc4"
    slice_query = f"?precipitation[0:1:0][{LON_IDX_MIN}:1:{LON_IDX_MAX}][{LAT_IDX_MIN}:1:{LAT_IDX_MAX}]"
    return f"{BASE_OPENDAP}/{year:04d}/{month:02d}/{filename}.ascii{slice_query}"


def fetch_day(session: requests.Session, auth: tuple, year: int, month: int, day: int):
    url = build_day_url(year, month, day)
    t0 = time.time()
    for attempt in range(4):
        try:
            r = session.get(url, auth=auth, timeout=25)
            if r.status_code == 200:
                elapsed = time.time() - t0
                return {"day": day, "status": 200, "elapsed": elapsed, "bytes": len(r.text)}
            elif r.status_code in [429, 500, 502, 503, 504]:
                time.sleep(1.5 * (attempt + 1))
            else:
                return {"day": day, "status": r.status_code, "elapsed": time.time() - t0, "bytes": 0}
        except Exception as e:
            time.sleep(1.5 * (attempt + 1))
    return {"day": day, "status": "failed", "elapsed": time.time() - t0, "bytes": 0}


def run_benchmark(year: int = 2024, month: int = 6):
    user, pwd = get_auth()
    auth = (user, pwd)
    num_days = calendar.monthrange(year, month)[1]

    print("=" * 85, flush=True)
    print(f"BENCHMARK: DAILY IMERG (GPM_3IMERGDF.07) OPENDAP INGESTION")
    print(f"Target Month: {year:04d}-{month:02d} ({num_days} days) | Concurrent Workers: {WORKERS}")
    print(f"Spatial Slice: Lon [{LON_IDX_MIN}:{LON_IDX_MAX}], Lat [{LAT_IDX_MIN}:{LAT_IDX_MAX}]")
    print("=" * 85, flush=True)

    session = requests.Session()
    adapter = requests.adapters.HTTPAdapter(pool_connections=WORKERS * 2, pool_maxsize=WORKERS * 2)
    session.mount("https://", adapter)

    t_start = time.time()
    results = []

    with ThreadPoolExecutor(max_workers=WORKERS) as executor:
        futures = {executor.submit(fetch_day, session, auth, year, month, d): d for d in range(1, num_days + 1)}
        for fut in as_completed(futures):
            res = fut.result()
            results.append(res)
            print(f"  Day {res['day']:02d}: Status {res['status']} in {res['elapsed']:.2f}s ({res['bytes']} bytes)", flush=True)

    total_time = time.time() - t_start
    success_count = sum(1 for r in results if r["status"] == 200)

    print("\n" + "=" * 85, flush=True)
    print("BENCHMARK SUMMARY RESULTS", flush=True)
    print("=" * 85, flush=True)
    print(f"Total Days Processed:     {num_days}", flush=True)
    print(f"Successful Days (200 OK): {success_count} / {num_days} ({success_count/num_days*100:.1f}%)", flush=True)
    print(f"Total Month Wall Time:    {total_time:.2f} seconds ({total_time / 60.0:.2f} minutes)", flush=True)
    print(f"Effective Rate:           {total_time / num_days:.2f} seconds per day", flush=True)
    print(f"Throughput:               {num_days / total_time:.2f} days per second", flush=True)

    # Estimate for 5 years (~1,826 days)
    total_5y_days = 1826
    est_5y_sec = (total_time / num_days) * total_5y_days
    est_5y_min = est_5y_sec / 60.0

    print("-" * 85, flush=True)
    print(f"ESTIMATED TIME FOR FULL 5 YEARS ({total_5y_days} days):", flush=True)
    print(f"  Estimated Wall Time:    {est_5y_min:.1f} minutes ({est_5y_min / 60.0:.2f} hours)", flush=True)
    print("=" * 85, flush=True)

    if est_5y_min <= 45:
        print(f"[DECISION]: OPeNDAP is extremely fast ({est_5y_min:.1f} min for 5y). NO HARMONY NEEDED!", flush=True)
    else:
        print(f"[DECISION]: OPeNDAP is slow ({est_5y_min:.1f} min). Test Harmony batch.", flush=True)


if __name__ == "__main__":
    run_benchmark(2024, 6)
