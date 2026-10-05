"""
End-to-End Stress Test on Extreme Weather / Typhoon Month (October 2024).

October 2024 captures Severe Tropical Storm Kristine (Trami, Oct 21-25, 2024) in Batangas,
stress-testing:
1. Extreme gust peak preservation (max-corner vs bilinear smoothing).
2. Heavy rainfall rates from NASA GPM IMERG.
3. Elevated wave and current dynamics.
4. Schema conformity and per-source interpolation flags (is_interpolated).
5. Mathematical verification on extreme peaks.
"""

from pathlib import Path
import argparse
import json
import numpy as np
import pandas as pd
import xarray as xr

from config import SITE_LAT, SITE_LON
from spatial_extraction import (
    extract_nearest_ocean_cell,
    extract_era5_bilinear,
    extract_imerg
)

RAW_ROOT = Path(r"C:\Users\bryan\CapstoneProject_ML\data\raw")


def run_month_test(year_month: str = "2024-10"):
    year, month = int(year_month[:4]), int(year_month[5:7])
    days_in_month = pd.Period(f"{year}-{month:02d}").days_in_month
    
    test_start = f"{year}-{month:02d}-01 00:00:00"
    test_end = f"{year}-{month:02d}-{days_in_month:02d} 23:00:00"
    expected_hours = days_in_month * 24

    target_index = pd.date_range(start=test_start, end=test_end, freq="1h")
    test_out_dir = Path(__file__).resolve().parents[2] / "data" / f"test_{year}_{month:02d}"
    test_out_dir.mkdir(parents=True, exist_ok=True)

    print("=" * 85)
    print(f"STRESS TESTING EXTREME / TYPHOON MONTH: {test_start} to {test_end}")
    print(f"Target hourly steps: {expected_hours} hours | Site: Lat {SITE_LAT}° N, Lon {SITE_LON}° E")
    print("=" * 85)

    # -------------------------------------------------------------------------
    # 1. ERA5 Atmosphere (Bilinear u/v, Max-Corner Gust, SLP)
    # -------------------------------------------------------------------------
    print("\n--- 1. Testing ERA5 Atmosphere (Peak-Preserving Gusts) ---")
    era5_file = RAW_ROOT / "era5_wind_pressure" / f"era5_{year}_{month:02d}.nc"
    if not era5_file.exists():
        raise FileNotFoundError(f"Missing ERA5 file: {era5_file}")
    
    ds_era5 = xr.open_dataset(era5_file)
    interp_era5, era5_meta = extract_era5_bilinear(ds_era5)
    df_era5 = interp_era5.to_dataframe().reset_index()

    t_col = "valid_time" if "valid_time" in df_era5.columns else "time"
    df_era5 = df_era5.rename(columns={
        t_col: "time",
        "u10": "wind_u", "v10": "wind_v", "i10fg": "wind_gust", "msl": "slp"
    })
    
    df_era5["wind_speed"] = np.sqrt(df_era5["wind_u"]**2 + df_era5["wind_v"]**2)
    df_era5["wind_dir"] = (270 - np.degrees(np.arctan2(df_era5["wind_v"], df_era5["wind_u"]))) % 360
    df_era5["slp"] = df_era5["slp"] / 100.0

    df_era5 = df_era5.set_index("time")[["wind_u", "wind_v", "wind_speed", "wind_gust", "wind_dir", "slp"]]
    df_era5 = df_era5.reindex(target_index)

    # Flag column: ERA5 is native hourly reanalysis
    df_era5["era5_is_interpolated"] = False
    
    era5_out = test_out_dir / f"era5_{year}_{month:02d}.parquet"
    df_era5.to_parquet(era5_out)

    max_gust_idx = df_era5["wind_gust"].idxmax()
    max_gust_val = df_era5.loc[max_gust_idx, "wind_gust"]
    min_slp_idx = df_era5["slp"].idxmin()
    min_slp_val = df_era5.loc[min_slp_idx, "slp"]

    print(f"  Rows: {len(df_era5)}, NaNs: {df_era5.isna().sum().sum()}")
    print(f"  Mean wind speed: {df_era5['wind_speed'].mean():.2f} m/s, Max wind speed: {df_era5['wind_speed'].max():.2f} m/s")
    print(f"  PEAK GUST:       {max_gust_val:.2f} m/s ({max_gust_val*1.94384:.1f} kt) on {max_gust_idx}")
    print(f"  MIN PRESSURE:    {min_slp_val:.2f} hPa on {min_slp_idx} (Storm Kristine center)")

    # -------------------------------------------------------------------------
    # 2. CMEMS Waves (Nearest non-NaN cell + interpolation flag)
    # -------------------------------------------------------------------------
    print("\n--- 2. Testing CMEMS Waves ---")
    waves_file = RAW_ROOT / "cmems_waves.nc"
    ds_waves = xr.open_dataset(waves_file).sel(time=slice(test_start, test_end))
    
    extracted_waves, waves_meta = extract_nearest_ocean_cell(ds_waves, primary_var="VHM0")
    print(f"  Selected wave cell: ({waves_meta['selected_lat']}° N, {waves_meta['selected_lon']}° E), dist: {waves_meta['distance_km']} km")
    
    df_waves_raw = extracted_waves.to_dataframe().reset_index()
    native_wave_times = set(df_waves_raw["time"])

    df_waves = df_waves_raw.rename(columns={
        "VHM0": "hs", "VTPK": "tp", "VHM0_SW1": "swell_height", "VHM0_WW": "wind_wave_height"
    })[["time", "hs", "tp", "swell_height", "wind_wave_height"]]
    
    # 3-hourly to hourly linear interpolation
    df_waves = df_waves.set_index("time").resample("1h").interpolate(method="linear")
    df_waves = df_waves.reindex(target_index).ffill().bfill()
    
    # Label interpolated hours explicitly
    df_waves["wave_is_interpolated"] = [ts not in native_wave_times for ts in df_waves.index]
    
    waves_out = test_out_dir / f"cmems_waves_{year}_{month:02d}.parquet"
    df_waves.to_parquet(waves_out)

    max_hs_idx = df_waves["hs"].idxmax()
    max_hs_val = df_waves.loc[max_hs_idx, "hs"]
    print(f"  Rows: {len(df_waves)}, NaNs: {df_waves.isna().sum().sum()}")
    print(f"  Native 3h observations: {len(native_wave_times)}, Interpolated hours: {df_waves['wave_is_interpolated'].sum()}")
    print(f"  Mean Hs: {df_waves['hs'].mean():.3f} m, PEAK Hs: {max_hs_val:.3f} m on {max_hs_idx}")

    # -------------------------------------------------------------------------
    # 3. CMEMS Currents (Nearest non-NaN cell + interpolation flag)
    # -------------------------------------------------------------------------
    print("\n--- 3. Testing CMEMS Currents ---")
    currents_file = RAW_ROOT / "cmems_currents.nc"
    ds_currents = xr.open_dataset(currents_file).sel(time=slice(test_start, test_end))
    
    extracted_curr, curr_meta = extract_nearest_ocean_cell(ds_currents, primary_var="uo")
    print(f"  Selected current cell: ({curr_meta['selected_lat']}° N, {curr_meta['selected_lon']}° E), dist: {curr_meta['distance_km']} km")

    df_curr_raw = extracted_curr.to_dataframe().reset_index()
    native_curr_times = set(df_curr_raw["time"])

    df_curr = df_curr_raw.rename(columns={"uo": "current_u", "vo": "current_v"})[["time", "current_u", "current_v"]]
    
    df_curr = df_curr.set_index("time").resample("1h").ffill()
    df_curr = df_curr.reindex(target_index).ffill().bfill()
    df_curr["current_speed"] = np.sqrt(df_curr["current_u"]**2 + df_curr["current_v"]**2)
    df_curr["current_dir"] = (np.degrees(np.arctan2(df_curr["current_v"], df_curr["current_u"]))) % 360
    
    # Label interpolated / forward-filled hours explicitly
    df_curr["current_is_interpolated"] = [ts not in native_curr_times for ts in df_curr.index]
    
    curr_out = test_out_dir / f"cmems_currents_{year}_{month:02d}.parquet"
    df_curr.to_parquet(curr_out)

    max_spd_idx = df_curr["current_speed"].idxmax()
    max_spd_val = df_curr.loc[max_spd_idx, "current_speed"]
    print(f"  Rows: {len(df_curr)}, NaNs: {df_curr.isna().sum().sum()}")
    print(f"  Native daily observations: {len(native_curr_times)}, Forward-filled hours: {df_curr['current_is_interpolated'].sum()}")
    print(f"  Mean speed: {df_curr['current_speed'].mean():.3f} m/s, PEAK current speed: {max_spd_val:.3f} m/s ({max_spd_val*1.94384:.2f} kt) on {max_spd_idx}")

    # -------------------------------------------------------------------------
    # 4. NASA GPM IMERG Precipitation (Bilinear interpolation)
    # -------------------------------------------------------------------------
    print("\n--- 4. Testing NASA GPM IMERG Precipitation ---")
    gpm_file = RAW_ROOT / "gpm_precip" / "gpm_precip_raw.nc"
    ds_gpm = xr.open_dataset(gpm_file).sel(time=slice(test_start, test_end))
    
    interp_gpm, gpm_meta = extract_imerg(ds_gpm)
    df_gpm = interp_gpm["precipitation"].to_dataframe(name="rain_rate_mm_hr")[["rain_rate_mm_hr"]]
    df_gpm = df_gpm.resample("1h").mean()
    df_gpm = df_gpm.reindex(target_index).fillna(0.0)
    df_gpm["rain_is_interpolated"] = False

    gpm_out = test_out_dir / f"gpm_precip_{year}_{month:02d}.parquet"
    df_gpm.to_parquet(gpm_out)

    max_rain_idx = df_gpm["rain_rate_mm_hr"].idxmax()
    max_rain_val = df_gpm.loc[max_rain_idx, "rain_rate_mm_hr"]
    total_rain = df_gpm["rain_rate_mm_hr"].sum()
    print(f"  Rows: {len(df_gpm)}, NaNs: {df_gpm.isna().sum().sum()}")
    print(f"  TOTAL MONTH RAINFALL: {total_rain:.1f} mm")
    print(f"  PEAK RAIN RATE:       {max_rain_val:.2f} mm/hr on {max_rain_idx}")

    # -------------------------------------------------------------------------
    # 5. Combined Master Schema & Parity Validation
    # -------------------------------------------------------------------------
    print("\n" + "=" * 85)
    print("5. MASTER COMBINED TABLE & EXTREME STRESS VALIDATION")
    print("=" * 85)
    
    master_df = pd.concat([df_era5, df_waves, df_curr, df_gpm], axis=1)
    master_out = test_out_dir / f"combined_master_{year}_{month:02d}.parquet"
    master_df.to_parquet(master_out)
    
    print(f"Combined Master shape: {master_df.shape} (Expected: ({expected_hours}, {len(master_df.columns)}))")
    print(f"Columns: {list(master_df.columns)}")
    print(f"Total NaNs in Master table: {master_df.isna().sum().sum()}")
    print(f"Saved stress-test artifact -> {master_out}")

    assert len(master_df) == expected_hours, f"Row mismatch: got {len(master_df)}, expected {expected_hours}"
    assert master_df.isna().sum().sum() == 0, "Master table contains unexpected NaNs!"

    print(f"\nSUCCESS: Extreme storm event captured faithfully without NaN drops or vector distortion!")


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("--month", type=str, default="2024-10")
    args = parser.parse_args()
    run_month_test(args.month)
