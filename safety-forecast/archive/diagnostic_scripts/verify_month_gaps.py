"""
Verify temporal continuity of one full month for SMOC hourly currents and 1/12° waves.
Checks:
1. SMOC 2021-01-01 to 2021-01-31 (earliest year): exactly 744 hours, delta = 1h, 0 gaps, 0 NaNs.
2. Wave 2023-01-01 to 2023-01-31: exactly 248 3-hourly steps, delta = 3h, 0 gaps, 0 NaNs.
"""

from pathlib import Path
import numpy as np
import pandas as pd
import xarray as xr
import copernicusmarine as cm

scratch_dir = Path("safety-forecast/data/scratch")
scratch_dir.mkdir(parents=True, exist_ok=True)

print("=" * 80)
print("1. CHECKING FULL MONTH CONTINUITY: SMOC HOURLY CURRENTS (2021-01)")
print("=" * 80)
curr_file = scratch_dir / "smoc_2021_01.nc"
try:
    cm.subset(
        dataset_id="cmems_mod_glo_phy_anfc_merged-uv_PT1H-i",
        variables=["uo", "vo"],
        minimum_longitude=120.83, maximum_longitude=120.85,
        minimum_latitude=13.66, maximum_latitude=13.68,
        minimum_depth=0, maximum_depth=1,
        start_datetime="2021-01-01T00:00:00",
        end_datetime="2021-01-31T23:00:00",
        output_filename=str(curr_file),
    )
    ds_c = xr.open_dataset(curr_file)
    times_c = pd.to_datetime(ds_c["time"].values)
    print(f"SMOC 2021-01 total time steps: {len(times_c)}")
    print(f"Start: {times_c[0]}, End: {times_c[-1]}")
    diffs_c = pd.Series(times_c).diff().dropna()
    unexpected_c = diffs_c[diffs_c != pd.Timedelta(hours=1)]
    print(f"Unexpected time deltas (not 1h): {len(unexpected_c)}")
    nan_c = np.isnan(ds_c["uo"].values).sum()
    print(f"NaN count in uo: {nan_c}")
except Exception as e:
    print(f"SMOC 2021-01 test error: {e}")

print("\n" + "=" * 80)
print("2. CHECKING FULL MONTH CONTINUITY: 1/12° WAVE ANALYSIS (2023-01)")
print("=" * 80)
wave_file = scratch_dir / "wave_2023_01.nc"
try:
    import numpy as np
    cm.subset(
        dataset_id="cmems_mod_glo_wav_anfc_0.083deg_PT3H-i",
        variables=["VHM0"],
        minimum_longitude=120.91, maximum_longitude=120.93,
        minimum_latitude=13.66, maximum_latitude=13.68,
        start_datetime="2023-01-01T00:00:00",
        end_datetime="2023-01-31T21:00:00",
        output_filename=str(wave_file),
    )
    ds_w = xr.open_dataset(wave_file)
    times_w = pd.to_datetime(ds_w["time"].values)
    print(f"Wave 2023-01 total time steps: {len(times_w)}")
    print(f"Start: {times_w[0]}, End: {times_w[-1]}")
    diffs_w = pd.Series(times_w).diff().dropna()
    unexpected_w = diffs_w[diffs_w != pd.Timedelta(hours=3)]
    print(f"Unexpected time deltas (not 3h): {len(unexpected_w)}")
    nan_w = np.isnan(ds_w["VHM0"].values).sum()
    print(f"NaN count in VHM0: {nan_w}")
except Exception as e:
    print(f"Wave 2023-01 test error: {e}")
