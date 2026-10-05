import copernicusmarine as cm
import xarray as xr
from pathlib import Path

out_p = Path('safety-forecast/data/scratch/all_vars_smoc.nc')
out_p.parent.mkdir(parents=True, exist_ok=True)
cm.subset(
    dataset_id='cmems_mod_glo_phy_anfc_merged-uv_PT1H-i',
    minimum_longitude=120.83, maximum_longitude=120.85,
    minimum_latitude=13.66, maximum_latitude=13.68,
    minimum_depth=0, maximum_depth=1,
    start_datetime='2024-01-01T00:00:00',
    end_datetime='2024-01-01T01:00:00',
    output_filename=str(out_p)
)
ds = xr.open_dataset(out_p)
print('ALL VARIABLES IN DATASET:')
for v in ds.data_vars:
    ln = ds[v].attrs.get('long_name', '')
    un = ds[v].attrs.get('units', '')
    sn = ds[v].attrs.get('standard_name', '')
    print(f'  {v:<15}: {ln} | standard_name: {sn} | units: {un}')
