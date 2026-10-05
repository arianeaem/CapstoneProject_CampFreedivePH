import copernicusmarine as cm
import sys

print("Python version:", sys.version, flush=True)

test_targets = [
    # Analysis datasets
    ("cmems_mod_glo_wav_anfc_0.083deg_PT3H-i", ["VHM0"], ["2020-11-01T00:00:00", "2021-01-01T00:00:00", "2022-01-01T00:00:00", "2022-06-01T00:00:00", "2024-01-01T00:00:00"]),
    ("cmems_mod_glo_phy_anfc_merged-uv_PT1H-i", ["uo"], ["2020-11-01T00:00:00", "2022-06-01T00:00:00", "2024-01-01T00:00:00"]),
    ("cmems_mod_glo_phy-cur_anfc_0.083deg_PT6H-i", ["uo"], ["2020-11-01T00:00:00", "2022-06-01T00:00:00", "2024-01-01T00:00:00"]),
    # Reanalysis / Multiyear datasets
    ("cmems_mod_glo_wav_my_0.2deg_PT3H-i", ["VHM0"], ["2020-11-01T00:00:00", "2022-01-01T00:00:00", "2024-01-01T00:00:00", "2024-12-31T00:00:00"]),
    ("cmems_mod_glo_phy_my_0.083deg_P1D-m", ["uo"], ["2020-11-01T00:00:00", "2022-01-01T00:00:00", "2024-01-01T00:00:00", "2024-12-31T00:00:00"]),
]

for did, vars_to_get, test_dts in test_targets:
    print(f"\n==========================================", flush=True)
    print(f"PROBING: {did}", flush=True)
    print(f"==========================================", flush=True)
    for dt in test_dts:
        print(f"Testing start_datetime={dt}...", end=" ", flush=True)
        try:
            res = cm.subset(
                dataset_id=did,
                variables=vars_to_get,
                minimum_longitude=120.85,
                maximum_longitude=120.95,
                minimum_latitude=13.65,
                maximum_latitude=13.72,
                start_datetime=dt,
                end_datetime=dt,
                output_filename=f"probe_{did[:12]}_{dt[:10]}.nc",
                force_download=True,
            )
            print(f"-> SUCCESS (file downloaded)", flush=True)
        except Exception as e:
            err_msg = str(e).strip().replace("\n", " ")
            print(f"-> FAILED: {type(e).__name__}: {err_msg[:120]}", flush=True)
