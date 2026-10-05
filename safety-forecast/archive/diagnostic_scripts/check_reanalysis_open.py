import copernicusmarine as cm
import sys

reanalysis_datasets = [
    "cmems_mod_glo_wav_my_0.2deg_PT3H-i",
    "cmems_mod_glo_phy_my_0.083deg_P1D-m",
]

for did in reanalysis_datasets:
    print(f"\n====================================", flush=True)
    print(f"Opening: {did}", flush=True)
    print(f"====================================", flush=True)
    try:
        ds = cm.open_dataset(dataset_id=did)
        t_var = "time" if "time" in ds else list(ds.coords.keys())[0]
        times = ds[t_var].values
        print(f"Success! Time coord: {t_var}", flush=True)
        print(f"Start time: {times[0]}", flush=True)
        print(f"End time:   {times[-1]}", flush=True)
        print(f"Variables:  {list(ds.data_vars.keys())}", flush=True)
    except Exception as e:
        print(f"Error opening {did}: {type(e).__name__}: {e}", flush=True)
