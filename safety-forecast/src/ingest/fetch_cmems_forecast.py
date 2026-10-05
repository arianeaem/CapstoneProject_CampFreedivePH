"""
Daily download of the CMEMS forecasts and analysis.
Gets:
1. hourly current analysis and 10-day forecast from SMOC (cmems_mod_glo_phy_anfc_merged-uv_PT1H-i)
2. 3-hourly wave analysis and 10-day forecast (cmems_mod_glo_wav_anfc_0.083deg_PT3H-i)

Uses extract_nearest_ocean_cell and saves the observed values and forecasts
in the local store.
"""

import os
import sys
import logging
from pathlib import Path
from datetime import datetime, timedelta, timezone
import numpy as np
import pandas as pd
import xarray as xr

# Add the project root to sys.path
from config import PROJECT_ROOT, DATA_ROOT, CACHE_DIR, OBSERVED_STORE_DIR, SITE_LAT, SITE_LON, TARGET_TIMEZONE
from spatial_extraction import extract_nearest_ocean_cell

logger = logging.getLogger("fetch_cmems_forecast")
logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s [%(levelname)s] %(message)s",
    handlers=[logging.StreamHandler(sys.stdout)],
)

# Datasets
DATASET_SMOC = "cmems_mod_glo_phy_anfc_merged-uv_PT1H-i"
DATASET_CURR_6H = "cmems_mod_glo_phy-cur_anfc_0.083deg_PT6H-i"
DATASET_WAVE = "cmems_mod_glo_wav_anfc_0.083deg_PT3H-i"


def fetch_and_archive(lookback_days: int = 3, forecast_days: int = 10) -> bool:
    try:
        import copernicusmarine as cm
    except ImportError:
        logger.error("copernicusmarine is not installed.")
        return False

    now_utc = datetime.now(timezone.utc)
    start_dt = (now_utc - timedelta(days=lookback_days)).strftime("%Y-%m-%dT00:00:00")
    end_dt = (now_utc + timedelta(days=forecast_days)).strftime("%Y-%m-%dT23:59:59")

    logger.info("=" * 80)
    logger.info("ARCHIVING OPERATIONAL CMEMS FEEDS (CURRENTS & WAVES)")
    logger.info(f"Target Site: Lat {SITE_LAT}° N, Lon {SITE_LON}° E (Camp FreedivePH)")
    logger.info(f"Window:      {start_dt} to {end_dt} (UTC)")
    logger.info("=" * 80)

    # -------------------------------------------------------------------------
    # 1. Currents (SMOC hourly, or 6H if hourly is not available)
    # -------------------------------------------------------------------------
    curr_nc = CACHE_DIR / "live_currents_raw.nc"
    curr_success = False
    for did in [DATASET_SMOC, DATASET_CURR_6H]:
        try:
            logger.info(f"Subsetting currents from: {did}...")
            cm.subset(
                dataset_id=did,
                variables=["uo", "vo"],
                minimum_longitude=120.80, maximum_longitude=120.90,
                minimum_latitude=13.65, maximum_latitude=13.72,
                minimum_depth=0, maximum_depth=1,
                start_datetime=start_dt, end_datetime=end_dt,
                output_filename=str(curr_nc),
            )
            curr_success = True
            logger.info(f"Downloaded current slice from: {did}")
            break
        except Exception as e:
            logger.warning(f"Failed pulling from {did}: {e}")

    # When we downloaded it (not the same as when CMEMS ran the model)
    issue_time_str = now_utc.isoformat()

    # Delays from config
    from config import get_serving_cutoff, OPERATIONAL_LAGS

    if curr_success and curr_nc.exists():
        ds_c = xr.open_dataset(curr_nc)
        extracted_c, meta_c = extract_nearest_ocean_cell(ds_c, primary_var="uo")
        df_c = extracted_c.to_dataframe().reset_index()
        
        # Add the tide parts if they exist
        cols = {"uo": "current_u", "vo": "current_v"}
        if "utide" in df_c.columns:
            cols["utide"] = "tide_u"
        if "vtide" in df_c.columns:
            cols["vtide"] = "tide_v"
        df_c = df_c.rename(columns=cols)

        df_c["time_utc"] = pd.to_datetime(df_c["time"]).dt.tz_localize("UTC")
        df_c["time_pht"] = df_c["time_utc"].dt.tz_convert("Asia/Manila")
        df_c["current_speed"] = np.sqrt(df_c["current_u"]**2 + df_c["current_v"]**2)
        df_c["current_dir"] = (np.degrees(np.arctan2(df_c["current_v"], df_c["current_u"]))) % 360
        df_c["issue_time_utc"] = issue_time_str
        
        # Delay cutoff (T - 24h for currents)
        curr_cutoff = get_serving_cutoff("currents", now_utc)
        df_c["is_verified"] = df_c["time_utc"] <= curr_cutoff
        df_c["is_provisional"] = (df_c["time_utc"] > curr_cutoff) & (df_c["time_utc"] <= now_utc)
        df_c["is_forecast"] = df_c["time_utc"] > now_utc
        
        # Save the forecast cache
        curr_cache_file = CACHE_DIR / "cmems_currents_forecast_cache.parquet"
        df_c.to_parquet(curr_cache_file)
        logger.info(f"Saved {len(df_c)} current rows (cutoff: {curr_cutoff}, verified={df_c['is_verified'].sum()}, provisional={df_c['is_provisional'].sum()}, forecast={df_c['is_forecast'].sum()}) to {curr_cache_file}")

        # Save to observed_store: only observed values <= now_utc.
        # Provisional rows are marked so the feature builder can skip them and the next run replaces them.
        obs_c = df_c[df_c["time_utc"] <= now_utc].copy()
        if len(obs_c) > 0:
            obs_file = OBSERVED_STORE_DIR / "cmems_currents_observed_archive.parquet"
            if obs_file.exists():
                existing_obs = pd.read_parquet(obs_file)
                # Replace the provisional rows with the new download
                combined_obs = pd.concat([existing_obs, obs_c]).drop_duplicates(subset=["time_utc"], keep="last")
            else:
                combined_obs = obs_c
            combined_obs.sort_values("time_utc").to_parquet(obs_file)
            logger.info(f"Appended current observations to {obs_file} (Total: {len(combined_obs)}, Verified: {combined_obs['is_verified'].sum()}, Provisional: {combined_obs['is_provisional'].sum()})")

    # -------------------------------------------------------------------------
    # 2. Waves (1/12 deg analysis/forecast)
    # -------------------------------------------------------------------------
    wave_nc = CACHE_DIR / "live_waves_raw.nc"
    try:
        logger.info(f"Subsetting waves from: {DATASET_WAVE}...")
        cm.subset(
            dataset_id=DATASET_WAVE,
            variables=["VHM0", "VTPK", "VHM0_SW1", "VHM0_SW2", "VHM0_WW"],
            minimum_longitude=120.85, maximum_longitude=120.95,
            minimum_latitude=13.65, maximum_latitude=13.72,
            start_datetime=start_dt, end_datetime=end_dt,
            output_filename=str(wave_nc),
        )
        ds_w = xr.open_dataset(wave_nc)
        extracted_w, meta_w = extract_nearest_ocean_cell(ds_w, primary_var="VHM0")
        df_w = extracted_w.to_dataframe().reset_index()

        sw1 = df_w["VHM0_SW1"].fillna(0) if "VHM0_SW1" in df_w else 0
        sw2 = df_w["VHM0_SW2"].fillna(0) if "VHM0_SW2" in df_w else 0
        total_swell = np.sqrt(sw1**2 + sw2**2)

        df_w["hs"] = df_w["VHM0"]
        df_w["tp"] = df_w["VTPK"]
        df_w["swell_height"] = total_swell
        df_w["wind_wave_height"] = df_w.get("VHM0_WW", np.nan)
        df_w["time_utc"] = pd.to_datetime(df_w["time"]).dt.tz_localize("UTC")
        df_w["time_pht"] = df_w["time_utc"].dt.tz_convert("Asia/Manila")
        df_w["issue_time_utc"] = issue_time_str

        # Delay cutoff (T - 12h for waves)
        wave_cutoff = get_serving_cutoff("waves", now_utc)
        df_w["is_verified"] = df_w["time_utc"] <= wave_cutoff
        df_w["is_provisional"] = (df_w["time_utc"] > wave_cutoff) & (df_w["time_utc"] <= now_utc)
        df_w["is_forecast"] = df_w["time_utc"] > now_utc

        wave_cache_file = CACHE_DIR / "cmems_waves_forecast_cache.parquet"
        df_w.to_parquet(wave_cache_file)
        logger.info(f"Saved {len(df_w)} wave rows (cutoff: {wave_cutoff}, verified={df_w['is_verified'].sum()}, provisional={df_w['is_provisional'].sum()}, forecast={df_w['is_forecast'].sum()}) to {wave_cache_file}")

        # Save to observed_store: only observed values <= now_utc
        obs_w = df_w[df_w["time_utc"] <= now_utc].copy()
        if len(obs_w) > 0:
            obs_file_w = OBSERVED_STORE_DIR / "cmems_waves_observed_archive.parquet"
            if obs_file_w.exists():
                existing_obs_w = pd.read_parquet(obs_file_w)
                combined_obs_w = pd.concat([existing_obs_w, obs_w]).drop_duplicates(subset=["time_utc"], keep="last")
            else:
                combined_obs_w = obs_w
            combined_obs_w.sort_values("time_utc").to_parquet(obs_file_w)
            logger.info(f"Appended wave observations to {obs_file_w} (Total: {len(combined_obs_w)}, Verified: {combined_obs_w['is_verified'].sum()}, Provisional: {combined_obs_w['is_provisional'].sum()})")

    except Exception as e:
        logger.warning(f"Failed pulling wave forecast: {e}")

    logger.info("Archival run finished.")
    return True


if __name__ == "__main__":
    fetch_and_archive()
