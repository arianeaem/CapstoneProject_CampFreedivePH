"""
Benchmark script: Test NASA GPM IMERG DAP2 subsetting throughput across different worker pool sizes.
Tests 3 days (144 granules) at 20, 48, and 96 workers to measure:
- Total elapsed time (s)
- Throughput (granules / second)
- Average request latency (s)
- Projected full multi-year download duration
"""

import sys
import time
import argparse
from pathlib import Path
from datetime import datetime, timedelta
from concurrent.futures import ThreadPoolExecutor, as_completed
import numpy as np
import requests
from requests.adapters import HTTPAdapter
from urllib3.util.retry import Retry

# Ensure config is available
sys.path.insert(0, str(Path(__file__).resolve().parent))
from config import LAT_MIN, LAT_MAX, LON_MIN, LON_MAX

# OPeNDAP indices
LAT_IDX_MIN = int(np.floor((LAT_MIN + 89.95) / 0.1))
LAT_IDX_MAX = int(np.ceil((LAT_MAX + 89.95) / 0.1))
LON_IDX_MIN = int(np.floor((LON_MIN + 179.95) / 0.1))
LON_IDX_MAX = int(np.ceil((LON_MAX + 179.95) / 0.1))

BASE_URL = "https://gpm1.gesdisc.eosdis.nasa.gov/opendap/GPM_L3/GPM_3IMERGHH.07"


def build_urls(start_dt: datetime, end_dt: datetime):
    urls = []
    curr = start_dt
    while curr <= end_dt:
        year = curr.year
        doy = curr.timetuple().tm_yday
        hhmm = curr.strftime("%H%M")
        start_min = curr.hour * 60 + curr.minute
        end_min = start_min + 29
        end_hhmm = f"{end_min // 60:02d}{end_min % 60:02d}"
        url = (
            f"{BASE_URL}/{year}/{doy:03d}/"
            f"3B-HHR.MS.MRG.3IMERG.{curr.strftime('%Y%m%d')}-S{hhmm}00-E{end_hhmm}59.{start_min:04d}.V07B.HDF5.nc4"
            f"?precipitation[0:1:0][{LON_IDX_MIN}:1:{LON_IDX_MAX}][{LAT_IDX_MIN}:1:{LAT_IDX_MAX}]"
        )
        urls.append((curr, url))
        curr += timedelta(minutes=30)
    return urls


def test_worker_pool(worker_count: int, urls: list) -> dict:
    session = requests.Session()
    retries = Retry(total=3, backoff_factor=1.0, status_forcelist=[429, 500, 502, 503, 504])
    adapter = HTTPAdapter(max_retries=retries, pool_connections=worker_count * 2, pool_maxsize=worker_count * 2)
    session.mount("https://", adapter)

    def fetch(item):
        t0 = time.time()
        dt, url = item
        try:
            resp = session.get(url, timeout=30)
            latency = time.time() - t0
            return resp.status_code == 200, latency
        except Exception:
            return False, time.time() - t0

    t_start = time.time()
    success_count = 0
    latencies = []

    with ThreadPoolExecutor(max_workers=worker_count) as executor:
        futures = [executor.submit(fetch, u) for u in urls]
        for f in as_completed(futures):
            ok, lat = f.result()
            if ok:
                success_count += 1
            latencies.append(lat)

    elapsed = time.time() - t_start
    throughput = len(urls) / elapsed if elapsed > 0 else 0
    avg_latency = np.mean(latencies) if latencies else 0

    return {
        "workers": worker_count,
        "granules": len(urls),
        "success": success_count,
        "elapsed_sec": elapsed,
        "granules_per_sec": throughput,
        "avg_latency_sec": avg_latency,
        "sec_per_day": (elapsed / len(urls)) * 48,
    }


def main():
    parser = argparse.ArgumentParser(description="Test NASA GPM IMERG worker pools for 3 days")
    parser.add_argument("--pools", nargs="+", type=int, default=[20, 48, 96], help="Worker pool sizes to benchmark")
    args = parser.parse_args()

    # 3 days: 2024-01-01 00:00 to 2024-01-03 23:30 (144 granules)
    start_dt = datetime(2024, 1, 1, 0, 0)
    end_dt = datetime(2024, 1, 3, 23, 30)
    urls = build_urls(start_dt, end_dt)
    print(f"Loaded {len(urls)} granules for 3 benchmark test days ({start_dt.strftime('%Y-%m-%d')} to {end_dt.strftime('%Y-%m-%d')}).\n")

    results = []
    print(f"{'Workers':<10} | {'Elapsed (s)':<12} | {'Throughput (gr/s)':<18} | {'Avg Latency (s)':<16} | {'Sec/Day':<10} | {'Est. Final (1826d)':<18}")
    print("-" * 95)

    for w in args.pools:
        res = test_worker_pool(w, urls)
        results.append(res)
        sec_per_day = res["sec_per_day"]
        est_final_hours = (sec_per_day * 1826) / 3600
        print(f"{res['workers']:<10} | {res['elapsed_sec']:<12.1f} | {res['granules_per_sec']:<18.2f} | {res['avg_latency_sec']:<16.2f} | {sec_per_day:<10.1f} | {est_final_hours:<18.1f} hrs")

    print("\nBenchmark complete! Use the fastest worker pool for multi-year ingest.")


if __name__ == "__main__":
    main()
