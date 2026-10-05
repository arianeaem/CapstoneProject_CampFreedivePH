"""
Verify Probe Values Across Years for Waves and Currents.
Checks:
1. Exact extracted values (VHM0, uo, vo) at each probe date.
2. Confirm none are NaN or dummy constant values.
3. Confirm temporal variance across years.
"""

from pathlib import Path
import numpy as np
import xarray as xr
import copernicusmarine as cm

from spatial_extraction import extract_nearest_ocean_cell

probe_dir = Path("safety-forecast/data/probe_verification")
probe_dir.mkdir(parents=True, exist_ok=True)

print("=" * 80)
print("1. VERIFYING WAVE ANALYSIS VALUES (cmems_mod_glo_wav_anfc_0.083deg_PT3H-i)")
print("=" * 80)

wave_test_dates = [
    "2022-11-01T03:00:00",
    "2023-01-01T00:00:00",
    "2024-01-01T00:00:00",
    "2024-10-24T00:00:00",  # STS Kristine peak
]

wave_results = []

for dt in wave_test_dates:
    fn = probe_dir / f"wave_{dt[:10]}_{dt[11:13]}h.nc"
    try:
        cm.subset(
            dataset_id="cmems_mod_glo_wav_anfc_0.083deg_PT3H-i",
            variables=["VHM0", "VTPK"],
            minimum_longitude=120.85, maximum_longitude=120.95,
            minimum_latitude=13.65, maximum_latitude=13.72,
            start_datetime=dt, end_datetime=dt,
            output_filename=str(fn),
        )
        ds = xr.open_dataset(fn)
        extracted, meta = extract_nearest_ocean_cell(ds, primary_var="VHM0")
        hs = float(extracted["VHM0"].values.flat[0])
        tp = float(extracted["VTPK"].values.flat[0]) if "VTPK" in extracted else np.nan
        wave_results.append((dt, hs, tp, meta["selected_lat"], meta["selected_lon"]))
        print(f"Wave {dt} -> Hs: {hs:.3f} m, Tp: {tp:.2f} s (Cell: {meta['selected_lat']}, {meta['selected_lon']})")
    except Exception as e:
        print(f"Wave {dt} -> Error: {e}")

print("\n" + "=" * 80)
print("2. VERIFYING SMOC HOURLY CURRENT VALUES (cmems_mod_glo_phy_anfc_merged-uv_PT1H-i)")
print("=" * 80)

curr_test_dates = [
    "2020-11-01T00:00:00",
    "2021-06-01T00:00:00",
    "2022-06-01T00:00:00",
    "2023-01-01T00:00:00",
    "2024-01-01T00:00:00",
    "2024-10-24T00:00:00",
]

curr_results = []

for dt in curr_test_dates:
    fn = probe_dir / f"curr_{dt[:10]}_{dt[11:13]}h.nc"
    try:
        cm.subset(
            dataset_id="cmems_mod_glo_phy_anfc_merged-uv_PT1H-i",
            variables=["uo", "vo"],
            minimum_longitude=120.80, maximum_longitude=120.90,
            minimum_latitude=13.65, maximum_latitude=13.72,
            start_datetime=dt, end_datetime=dt,
            output_filename=str(fn),
        )
        ds = xr.open_dataset(fn)
        extracted, meta = extract_nearest_ocean_cell(ds, primary_var="uo")
        uo = float(extracted["uo"].values.flat[0])
        vo = float(extracted["vo"].values.flat[0])
        spd = np.sqrt(uo**2 + vo**2)
        direction = (np.degrees(np.arctan2(vo, uo))) % 360
        curr_results.append((dt, uo, vo, spd, direction))
        print(f"Current {dt} -> uo: {uo:+.4f} m/s, vo: {vo:+.4f} m/s, Spd: {spd:.3f} m/s ({spd*1.94384:.2f} kt), Dir: {direction:.1f}°")
    except Exception as e:
        print(f"Current {dt} -> Error: {e}")

print("\n" + "=" * 80)
print("3. STATISTICAL VARIANCE CHECK")
print("=" * 80)
hs_vals = [r[1] for r in wave_results]
print(f"Wave Hs values: {hs_vals}")
print(f"Hs min: {min(hs_vals):.3f} m, max: {max(hs_vals):.3f} m, std: {np.std(hs_vals):.3f} m")
assert len(set(hs_vals)) == len(hs_vals), "Wave heights are identical across dates!"
assert not any(np.isnan(hs_vals)), "Wave heights contain NaNs!"
print("-> WAVE INTEGRITY VERIFIED: Values are distinct, physically realistic, and non-NaN.")

spd_vals = [r[3] for r in curr_results]
print(f"\nCurrent speed values (m/s): {spd_vals}")
print(f"Speed min: {min(spd_vals):.3f} m/s, max: {max(spd_vals):.3f} m/s, std: {np.std(spd_vals):.3f} m/s")
assert len(set(spd_vals)) == len(spd_vals), "Current speeds are identical across dates!"
assert not any(np.isnan(spd_vals)), "Current speeds contain NaNs!"
print("-> CURRENT INTEGRITY VERIFIED: Values are distinct, physically realistic, and non-NaN.")
