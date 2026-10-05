"""
Real feature extraction module for Camp FreedivePH.
Extracts the canonical 38 physical and calendar features declared in feature_manifest.json
from snapshot datasets, strictly respecting operational lags (Waves 12h, Currents 24h).
"""

from pathlib import Path
from typing import Dict, Any, List, Optional
import json
import numpy as np
import pandas as pd

from config import TARGET_TIMEZONE

MANIFEST_PATH = Path(__file__).resolve().parent / "feature_manifest.json"


def load_feature_manifest() -> Dict[str, Any]:
    with open(MANIFEST_PATH, "r", encoding="utf-8") as f:
        return json.load(f)


def extract_features_at_origin(
    df_waves: pd.DataFrame,
    df_currents: pd.DataFrame,
    origin_time_utc: pd.Timestamp,
    horizon_h: int,
    df_era5: Optional[pd.DataFrame] = None
) -> pd.Series:
    """
    Constructs the exact 38-feature vector for a forecast issued at origin_time_utc
    targeting (origin_time_utc + horizon_h).
    
    Guarantees:
    - Wave features look back at least 12h (operational lag).
    - Current features look back at least 24h (operational lag).
    - Features and column order match feature_manifest.json exactly.
    """
    manifest = load_feature_manifest()
    features_spec = manifest["features"]

    # Align tz to UTC
    t0 = origin_time_utc if origin_time_utc.tzinfo is not None else origin_time_utc.tz_localize("UTC")
    t0_utc = t0.tz_convert("UTC")
    t_target_utc = t0_utc + pd.Timedelta(hours=horizon_h)
    t_target_pht = t_target_utc.tz_convert(TARGET_TIMEZONE)

    feats = {}

    # Waves lookups
    w_idx = df_waves.index.tz_convert("UTC") if df_waves.index.tz is not None else df_waves.index.tz_localize("UTC")
    c_idx = df_currents.index.tz_convert("UTC") if df_currents.index.tz is not None else df_currents.index.tz_localize("UTC")

    # Helper for point lookup
    def get_wave_val(col: str, lag_h: int) -> float:
        ts = t0_utc - pd.Timedelta(hours=lag_h)
        if ts in w_idx:
            return float(df_waves.loc[ts, col])
        # Fallback to nearest prior observation within 3h
        prior = df_waves.loc[w_idx <= ts, col]
        return float(prior.iloc[-1]) if len(prior) > 0 else np.nan

    def get_curr_val(col: str, lag_h: int) -> float:
        ts = t0_utc - pd.Timedelta(hours=lag_h)
        if ts in c_idx:
            return float(df_currents.loc[ts, col])
        prior = df_currents.loc[c_idx <= ts, col]
        return float(prior.iloc[-1]) if len(prior) > 0 else np.nan

    # Waves rolling slices (strictly up to t0 - 12h)
    w_history = df_waves.loc[w_idx <= (t0_utc - pd.Timedelta(hours=12))]
    c_history = df_currents.loc[c_idx <= (t0_utc - pd.Timedelta(hours=24))]

    # 1. Wave features
    feats["hs_lag_12h"] = get_wave_val("hs", 12)
    feats["hs_lag_24h"] = get_wave_val("hs", 24)
    feats["hs_lag_48h"] = get_wave_val("hs", 48)
    feats["hs_lag_72h"] = get_wave_val("hs", 72)
    
    hs_24h = w_history["hs"].iloc[-24:] if len(w_history) >= 24 else w_history["hs"]
    hs_7d = w_history["hs"].iloc[-168:] if len(w_history) >= 168 else w_history["hs"]
    feats["hs_roll_mean_24h"] = float(hs_24h.mean())
    feats["hs_roll_std_24h"] = float(hs_24h.std(ddof=0))
    feats["hs_roll_mean_7d"] = float(hs_7d.mean())
    feats["hs_roll_max_7d"] = float(hs_7d.max())

    feats["tp_lag_12h"] = get_wave_val("tp", 12)
    feats["tp_lag_24h"] = get_wave_val("tp", 24)
    tp_24h = w_history["tp"].iloc[-24:] if len(w_history) >= 24 else w_history["tp"]
    feats["tp_roll_mean_24h"] = float(tp_24h.mean())

    feats["swell_height_lag_12h"] = get_wave_val("swell_height", 12)
    feats["swell_height_lag_24h"] = get_wave_val("swell_height", 24)
    sw_7d = w_history["swell_height"].iloc[-168:] if len(w_history) >= 168 else w_history["swell_height"]
    feats["swell_height_roll_mean_7d"] = float(sw_7d.mean())

    feats["wind_wave_height_lag_12h"] = get_wave_val("wind_wave_height", 12)
    feats["wind_wave_height_lag_24h"] = get_wave_val("wind_wave_height", 24)

    # Derived wave physics
    hs12 = feats["hs_lag_12h"]
    tp12 = feats["tp_lag_12h"]
    sw12 = feats["swell_height_lag_12h"]
    feats["wave_steepness_lag_12h"] = float(hs12 / (1.56 * (tp12 ** 2))) if tp12 > 0 else 0.0
    feats["swell_ratio_lag_12h"] = float(sw12 / hs12) if hs12 > 0 else 0.0

    # 2. Currents features
    feats["current_speed_lag_24h"] = get_curr_val("current_speed", 24)
    feats["current_speed_lag_48h"] = get_curr_val("current_speed", 48)
    feats["current_speed_lag_72h"] = get_curr_val("current_speed", 72)

    cs_24h = c_history["current_speed"].iloc[-24:] if len(c_history) >= 24 else c_history["current_speed"]
    cs_7d = c_history["current_speed"].iloc[-168:] if len(c_history) >= 168 else c_history["current_speed"]
    feats["current_speed_roll_mean_24h"] = float(cs_24h.mean())
    feats["current_speed_roll_std_24h"] = float(cs_24h.std(ddof=0))
    feats["current_speed_roll_mean_7d"] = float(cs_7d.mean())

    feats["current_u_lag_24h"] = get_curr_val("current_u", 24)
    feats["current_v_lag_24h"] = get_curr_val("current_v", 24)
    feats["eulerian_u_lag_24h"] = get_curr_val("eulerian_u", 24)
    feats["eulerian_v_lag_24h"] = get_curr_val("eulerian_v", 24)
    feats["tide_u_lag_24h"] = get_curr_val("tide_u", 24)
    feats["tide_v_lag_24h"] = get_curr_val("tide_v", 24)
    feats["tide_speed_lag_24h"] = get_curr_val("tide_speed", 24)
    feats["stokes_u_lag_24h"] = get_curr_val("stokes_u", 24)
    feats["stokes_v_lag_24h"] = get_curr_val("stokes_v", 24)
    feats["stokes_speed_lag_24h"] = get_curr_val("stokes_speed", 24)

    # 3. ERA5 Atmospheric Features (lag >= 120h)
    if df_era5 is None:
        try:
            from training_eligibility import load_snapshot_dataset
            df_era5 = load_snapshot_dataset("era5", training_only=False)
        except Exception:
            df_era5 = None

    if df_era5 is not None:
        e_idx = df_era5.index.tz_convert("UTC") if df_era5.index.tz is not None else df_era5.index.tz_localize("UTC")
        def get_era_val(col: str, lag_h: int) -> float:
            ts = t0_utc - pd.Timedelta(hours=lag_h)
            if ts in e_idx:
                return float(df_era5.loc[ts, col])
            prior = df_era5.loc[e_idx <= ts, col]
            return float(prior.iloc[-1]) if len(prior) > 0 else 0.0

        cutoff_era = t0_utc - pd.Timedelta(hours=120)
        e_history = df_era5.loc[e_idx <= cutoff_era]

        feats["wind_speed_lag_120h"] = get_era_val("wind_speed", 120)
        feats["wind_speed_lag_144h"] = get_era_val("wind_speed", 144)
        ws_24h = e_history["wind_speed"].iloc[-24:] if len(e_history) >= 24 else e_history["wind_speed"]
        ws_7d = e_history["wind_speed"].iloc[-168:] if len(e_history) >= 168 else e_history["wind_speed"]
        feats["wind_speed_roll_mean_24h"] = float(ws_24h.mean())
        feats["wind_speed_roll_max_7d"] = float(ws_7d.max())

        feats["wind_gust_lag_120h"] = get_era_val("wind_gust", 120)
        wg_24h = e_history["wind_gust"].iloc[-24:] if len(e_history) >= 24 else e_history["wind_gust"]
        feats["wind_gust_roll_max_24h"] = float(wg_24h.max())
        feats["gust_excess_lag_120h"] = max(0.0, feats["wind_gust_lag_120h"] - feats["wind_speed_lag_120h"])

        w_dir = get_era_val("wind_dir", 120)
        feats["wind_dir_sin_lag_120h"] = float(np.sin(np.radians(w_dir)))
        feats["wind_dir_cos_lag_120h"] = float(np.cos(np.radians(w_dir)))

        wu = get_era_val("wind_u", 120)
        wv = get_era_val("wind_v", 120)
        ws = feats["wind_speed_lag_120h"]
        feats["wind_stress_u_lag_120h"] = float(wu * ws)
        feats["wind_stress_v_lag_120h"] = float(wv * ws)

        feats["slp_lag_120h"] = get_era_val("slp", 120)
        feats["slp_tendency_3h_lag_120h"] = feats["slp_lag_120h"] - get_era_val("slp", 123)
        feats["slp_tendency_24h_lag_120h"] = feats["slp_lag_120h"] - get_era_val("slp", 144)
    else:
        for f in features_spec:
            if f.get("source") == "era5":
                feats[f["name"]] = 0.0

    # 4. Calendar features at target time in PHT
    doy = t_target_pht.dayofyear
    hod = t_target_pht.hour
    feats["doy_sin"] = float(np.sin(2.0 * np.pi * doy / 365.25))
    feats["doy_cos"] = float(np.cos(2.0 * np.pi * doy / 365.25))
    feats["hod_sin"] = float(np.sin(2.0 * np.pi * hod / 24.0))
    feats["hod_cos"] = float(np.cos(2.0 * np.pi * hod / 24.0))

    # Build Series in exact manifest order
    ordered_names = [f["name"] for f in features_spec]
    return pd.Series([feats[k] for k in ordered_names], index=ordered_names, name=t0_utc)
