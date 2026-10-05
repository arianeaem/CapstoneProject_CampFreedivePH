import numpy as np
import pandas as pd
import xarray as xr
from pathlib import Path

# 1. FFT Spectral Power at M2 tidal frequency (12.42 h)
df = pd.read_parquet('safety-forecast/data/interim/cmems_currents.parquet')
x = df.loc['2024-01-01':'2024-03-31']

def m2(s):
    s = s - s.mean()
    f = np.fft.rfftfreq(len(s))
    p = np.abs(np.fft.rfft(s.values))**2
    idx = np.argmin(abs(f - 1/12.42))
    return p[idx] / p.sum()

print('=' * 80)
print('1. M2 TIDAL SPECTRUM RATIO (12.42h):')
for c in ['current_u', 'current_v', 'tide_u', 'tide_v', 'current_residual_u', 'current_residual_v']:
    if c in x.columns:
        print(f'{c:<25}: {m2(x[c]):.4f}')

# 2. Check full variable list of SMOC dataset via copernicusmarine describe or raw file
print('\n' + '=' * 80)
print('2. RAW SMOC NETCDF VARIABLES:')
raw_files = sorted(Path('safety-forecast/data/raw/cmems_currents').glob('currents_*.nc'))
if raw_files:
    ds = xr.open_dataset(raw_files[0])
    print('Variables in downloaded file:', list(ds.data_vars.keys()))
    for v in ds.data_vars:
        ln = ds[v].attrs.get('long_name', 'No long name')
        un = ds[v].attrs.get('units', 'No units')
        print(f'  {v:<15}: {ln} [{un}]')
else:
    print('No raw currents file found in safety-forecast/data/raw/cmems_currents!')

# Also query copernicusmarine dataset metadata for cmems_mod_glo_phy_anfc_merged-uv_PT1H-i
print('\n' + '=' * 80)
print('3. ALL AVAILABLE VARIABLES IN DATASET (cmems_mod_glo_phy_anfc_merged-uv_PT1H-i):')
try:
    import copernicusmarine as cm
    desc = cm.describe(dataset_id="cmems_mod_glo_phy_anfc_merged-uv_PT1H-i")
    # Extract variables
    vars_found = []
    if "variables" in desc:
        for var in desc["variables"]:
            vars_found.append((var.get("variable_id", var.get("name")), var.get("standard_name", ""), var.get("units", "")))
    elif "layers" in desc:
        for layer in desc["layers"]:
            vars_found.append((layer.get("name"), layer.get("standard_name", ""), layer.get("unit", "")))
    print(f"Total variables described: {len(vars_found)}")
    for vf in vars_found:
        print(f"  {vf[0]}: {vf[1]} ({vf[2]})")
except Exception as e:
    print(f"describe error or parsing: {e}")
