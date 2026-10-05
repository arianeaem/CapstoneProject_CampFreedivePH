"""
Direct Measurement of CMEMS Analysis Archive Boundaries.

1. Inspects existing test_wave_083.nc time coordinate.
2. Measures subset availability across 2020, 2021, 2022, 2023, 2024.
Uses thread timeout to ensure individual requests do not hang.
"""

from concurrent.futures import ThreadPoolExecutor, TimeoutError
import sys
from pathlib import Path
import xarray as xr
import copernicusmarine as cm

print("=" * 80)
print("1. INSPECTING EXISTING test_wave_083.nc ON DISK")
print("=" * 80)

wave_file = Path("safety-forecast/data/cache/test_wave_083.nc")
if wave_file.exists():
    ds_w = xr.open_dataset(wave_file)
    print(f"File path:    {wave_file.resolve()}")
    print(f"File size:    {wave_file.stat().st_size} bytes")
    print(f"Time dim:     {len(ds_w.time)} timestamps")
    print(f"Time range:   {ds_w.time.values[0]} -> {ds_w.time.values[-1]}")
    print(f"Variables:    {list(ds_w.data_vars.keys())}")
    print(f"Coordinates:  {list(ds_w.coords.keys())}")
    print(f"Attributes:   Title={ds_w.attrs.get('title')}, Institution={ds_w.attrs.get('institution')}")
else:
    print(f"File not found: {wave_file}")

print("\n" + "=" * 80)
print("2. MEASURING CMEMS 1/12° WAVE ANALYSIS (cmems_mod_glo_wav_anfc_0.083deg_PT3H-i)")
print("=" * 80)

def probe_wave(dt_str: str):
    out_nc = f"probe_wave_{dt_str[:10]}.nc"
    try:
        cm.subset(
            dataset_id="cmems_mod_glo_wav_anfc_0.083deg_PT3H-i",
            variables=["VHM0"],
            minimum_longitude=120.85,
            maximum_longitude=120.95,
            minimum_latitude=13.65,
            maximum_latitude=13.72,
            start_datetime=f"{dt_str}T00:00:00",
            end_datetime=f"{dt_str}T03:00:00",
            output_filename=out_nc,
        )
        p = Path(out_nc)
        size = p.stat().st_size if p.exists() else 0
        if p.exists():
            p.unlink()  # clean up
        return True, f"AVAILABLE ({size} bytes)"
    except Exception as e:
        return False, f"FAILED: {type(e).__name__}: {str(e)[:100]}"

def run_with_timeout(func, arg, timeout_sec=45):
    with ThreadPoolExecutor(max_workers=1) as executor:
        future = executor.submit(func, arg)
        try:
            return future.result(timeout=timeout_sec)
        except TimeoutError:
            return False, f"TIMEOUT after {timeout_sec}s (likely out of range / cold tier)"
        except Exception as e:
            return False, f"ERROR: {e}"

test_wave_dates = [
    "2024-01-01",
    "2023-01-01",
    "2022-11-01",
    "2022-06-01",
    "2021-06-01",
    "2020-11-01",
]

for d in test_wave_dates:
    print(f"Probing Wave Analysis date: {d}...", end=" ", flush=True)
    ok, msg = run_with_timeout(probe_wave, d, timeout_sec=40)
    print(f"-> {msg}", flush=True)

print("\n" + "=" * 80)
print("3. MEASURING CMEMS 1/12° HOURLY CURRENT (cmems_mod_glo_phy_anfc_merged-uv_PT1H-i / SMOC)")
print("=" * 80)

def probe_current(dt_str: str):
    out_nc = f"probe_curr_{dt_str[:10]}.nc"
    try:
        cm.subset(
            dataset_id="cmems_mod_glo_phy_anfc_merged-uv_PT1H-i",
            variables=["uo"],
            minimum_longitude=120.80,
            maximum_longitude=120.90,
            minimum_latitude=13.65,
            maximum_latitude=13.72,
            start_datetime=f"{dt_str}T00:00:00",
            end_datetime=f"{dt_str}T01:00:00",
            output_filename=out_nc,
        )
        p = Path(out_nc)
        size = p.stat().st_size if p.exists() else 0
        if p.exists():
            p.unlink()  # clean up
        return True, f"AVAILABLE ({size} bytes)"
    except Exception as e:
        return False, f"FAILED: {type(e).__name__}: {str(e)[:100]}"

test_curr_dates = [
    "2024-01-01",
    "2023-01-01",
    "2022-11-01",
    "2022-06-01",
    "2021-06-01",
    "2020-11-01",
]

for d in test_curr_dates:
    print(f"Probing Current SMOC date: {d}...", end=" ", flush=True)
    ok, msg = run_with_timeout(probe_current, d, timeout_sec=40)
    print(f"-> {msg}", flush=True)
