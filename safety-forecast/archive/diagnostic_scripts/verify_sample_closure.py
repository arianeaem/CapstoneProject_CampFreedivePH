import copernicusmarine as cm
import xarray as xr
import numpy as np
from pathlib import Path

sample_file = Path("safety-forecast/data/cache/test_sample_1h.nc")
sample_file.parent.mkdir(parents=True, exist_ok=True)

vars_to_pull = ["utotal", "vtotal", "uo", "vo", "utide", "vtide", "vsdx", "vsdy"]
print("Requesting 1-hour subset with variables:", vars_to_pull)

cm.subset(
    dataset_id="cmems_mod_glo_phy_anfc_merged-uv_PT1H-i",
    variables=vars_to_pull,
    minimum_longitude=120.7, maximum_longitude=121.1,
    minimum_latitude=13.5, maximum_latitude=14.0,
    minimum_depth=0, maximum_depth=1,
    start_datetime="2024-01-01T00:00:00",
    end_datetime="2024-01-01T01:00:00",
    output_filename=str(sample_file),
    force_download=True
)

ds = xr.open_dataset(sample_file)
print("\n=== Dataset data_vars ===")
print("ds.data_vars keys:", list(ds.data_vars.keys()))
for v in ds.data_vars:
    long_name = ds[v].attrs.get("long_name", "")
    units = ds[v].attrs.get("units", "")
    print(f"  {v}: {long_name} [{units}] shape={ds[v].shape}")

# Closure test
u_tot = ds["utotal"].values
u_comp = ds["uo"].values + ds["utide"].values + ds["vsdx"].values
u_diff = np.abs(u_tot - u_comp)

v_tot = ds["vtotal"].values
v_comp = ds["vo"].values + ds["vtide"].values + ds["vsdy"].values
v_diff = np.abs(v_tot - v_comp)

print("\n=== Sample Closure Check ===")
print(f"Max  |utotal - (uo + utide + vsdx)|: {np.nanmax(u_diff):.6f} m/s")
print(f"Mean |utotal - (uo + utide + vsdx)|: {np.nanmean(u_diff):.6f} m/s")
print(f"Max  |vtotal - (vo + vtide + vsdy)|: {np.nanmax(v_diff):.6f} m/s")
print(f"Mean |vtotal - (vo + vtide + vsdy)|: {np.nanmean(v_diff):.6f} m/s")

# Clean up sample file
ds.close()
if sample_file.exists():
    sample_file.unlink()
