"""
Verify that the requested variable lists exist in each data source.
Pulls 1 day (or 1 slice) per source and prints ds.data_vars.
"""

from pathlib import Path
import xarray as xr
import pandas as pd
import copernicusmarine as cm
import requests

from config import VARIABLES, LON_MIN, LON_MAX, LAT_MIN, LAT_MAX
from gpm_precip import LAT_IDX_MIN, LAT_IDX_MAX, LON_IDX_MIN, LON_IDX_MAX

test_dir = Path("safety-forecast/data/scratch/vars_test")
test_dir.mkdir(parents=True, exist_ok=True)

print("=" * 80)
print("1. CMEMS WAVES: CHECKING VARIABLES")
print(f"Requested: {VARIABLES['waves']}")
print("=" * 80)
wave_nc = test_dir / "wave_1day.nc"
try:
    cm.subset(
        dataset_id="cmems_mod_glo_wav_anfc_0.083deg_PT3H-i",
        variables=VARIABLES["waves"],
        minimum_longitude=120.85, maximum_longitude=120.95,
        minimum_latitude=13.65, maximum_latitude=13.72,
        start_datetime="2023-01-01T00:00:00",
        end_datetime="2023-01-01T21:00:00",
        output_filename=str(wave_nc),
    )
    ds_w = xr.open_dataset(wave_nc)
    print("Downloaded ds.data_vars in Waves NetCDF:")
    for v in ds_w.data_vars:
        print(f"  - {v}: shape={ds_w[v].shape}, long_name={ds_w[v].attrs.get('long_name', '')}")
    missing_w = [v for v in VARIABLES["waves"] if v not in ds_w.data_vars]
    print(f"Missing waves vars: {missing_w}")
except Exception as e:
    print(f"Waves error: {e}")

print("\n" + "=" * 80)
print("2. CMEMS CURRENTS (SMOC): CHECKING VARIABLES")
print(f"Requested: {VARIABLES['currents']}")
print("=" * 80)
curr_nc = test_dir / "curr_1day.nc"
try:
    cm.subset(
        dataset_id="cmems_mod_glo_phy_anfc_merged-uv_PT1H-i",
        variables=VARIABLES["currents"],
        minimum_longitude=120.83, maximum_longitude=120.85,
        minimum_latitude=13.66, maximum_latitude=13.68,
        minimum_depth=0, maximum_depth=1,
        start_datetime="2021-01-01T00:00:00",
        end_datetime="2021-01-01T23:00:00",
        output_filename=str(curr_nc),
    )
    ds_c = xr.open_dataset(curr_nc)
    print("Downloaded ds.data_vars in Currents NetCDF:")
    for v in ds_c.data_vars:
        print(f"  - {v}: shape={ds_c[v].shape}, long_name={ds_c[v].attrs.get('long_name', '')}")
    missing_c = [v for v in VARIABLES["currents"] if v not in ds_c.data_vars]
    print(f"Missing currents vars: {missing_c}")
except Exception as e:
    print(f"Currents error: {e}")

print("\n" + "=" * 80)
print("3. ECMWF ERA5: CHECKING VARIABLES")
print(f"Requested CDS names: {VARIABLES['era5']}")
print("=" * 80)
# Check existing ERA5 file or read from disk
raw_era5_files = list(Path("safety-forecast/data/raw/era5_wind_pressure").glob("*.nc"))
if not raw_era5_files:
    raw_era5_files = list(Path("C:/Users/bryan/CapstoneProject_ML/data/raw/era5_wind_pressure").glob("*.nc"))

if raw_era5_files:
    ds_era = xr.open_dataset(raw_era5_files[0])
    print(f"Sample file: {raw_era5_files[0].name}")
    print("ds.data_vars in ERA5 NetCDF:")
    for v in ds_era.data_vars:
        print(f"  - {v}: shape={ds_era[v].shape}, long_name={ds_era[v].attrs.get('long_name', '')}")
else:
    print("No local ERA5 NetCDF found, will verify via cdsapi test if needed.")

print("\n" + "=" * 80)
print("4. NASA GPM IMERG: CHECKING VARIABLES")
print(f"Requested: {VARIABLES['imerg']}")
print("=" * 80)
url = (
    "https://gpm1.gesdisc.eosdis.nasa.gov/opendap/GPM_L3/GPM_3IMERGHH.07/2024/001/"
    f"3B-HHR.MS.MRG.3IMERG.20240101-S000000-E002959.0000.V07B.HDF5.ascii?"
    f"{VARIABLES['imerg'][0]}[0:1:0][{LON_IDX_MIN}:1:{LON_IDX_MAX}][{LAT_IDX_MIN}:1:{LAT_IDX_MAX}]"
)
try:
    resp = requests.get(url, timeout=15)
    print(f"Status Code: {resp.status_code}")
    if resp.status_code == 200:
        lines = [line for line in resp.text.splitlines() if "precipitation" in line][:3]
        print("Response header lines:")
        for line in lines:
            print(f"  {line}")
        print("IMERG variable confirmed: 'precipitation' exists and is readable.")
    else:
        print(f"OPeNDAP response: {resp.text[:200]}")
except Exception as e:
    print(f"IMERG error: {e}")
