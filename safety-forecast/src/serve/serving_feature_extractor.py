"""
Serving Feature Extractor for Camp FreedivePH Short-Range Production Models.

Extracts the exact 34-feature vectors for 'hs' and 'current_speed' from the Observed Store,
matching the training pipeline (train_short_range_models.py) down to float precision.
Strictly respects operational data lags:
- Waves (hs, tp, swell, wind_wave): 12h lag
- Currents (current_speed, eulerian, tide, stokes): 24h lag
- Atmospheric (slp, wind_speed, wind_gust): 120h lag
"""

import json
from pathlib import Path
from typing import Dict, List, Optional, Tuple, Union
import numpy as np
import pandas as pd

PROJECT_ROOT = Path(__file__).resolve().parents[2]
MODELS_DIR = PROJECT_ROOT / "models" / "short_range"
STORE_PATH = PROJECT_ROOT / "data" / "store" / "observed_store.parquet"
CLIM_PARAMS_PATH = MODELS_DIR / "clim_params.json"
MANIFEST_PATH = MODELS_DIR / "short_range_manifest.json"

LAGS = [0, 3, 6, 12, 24, 48, 72, 168]
ROLLS = [24, 72, 168]

LAGS_MAP = {
    "hs": 12, "tp": 12, "swell_height": 12, "wind_wave_height": 12,
    "current_speed": 24, "eulerian_speed": 24, "tide_speed": 24, "stokes_speed": 24,
    "wind_speed": 120, "wind_gust": 120, "slp": 120
}

AUX_COLS = {
    "hs": [
        "slp", "wind_speed", "wind_gust", "tp", "swell_height",
        "wind_wave_height", "current_speed", "eulerian_speed", "tide_speed", "stokes_speed"
    ],
    "current_speed": [
        "slp", "wind_speed", "wind_gust", "hs", "tp",
        "swell_height", "wind_wave_height", "eulerian_speed", "tide_speed", "stokes_speed"
    ]
}


class ServingFeatureExtractor:
    def __init__(self, store_df: Optional[pd.DataFrame] = None):
        if store_df is not None:
            self.df = store_df
        else:
            if not STORE_PATH.exists():
                raise FileNotFoundError(f"Observed store not found at {STORE_PATH}. Run refresh_store.py first.")
            self.df = pd.read_parquet(STORE_PATH)

        if not isinstance(self.df.index, pd.DatetimeIndex):
            for c in ("timestamp", "time", "datetime", "date"):
                if c in self.df.columns:
                    self.df = self.df.set_index(pd.to_datetime(self.df[c])).drop(columns=[c])
                    break
        self.df = self.df.sort_index()

        with open(CLIM_PARAMS_PATH, "r", encoding="utf-8") as f:
            self.clim_params = json.load(f)

        with open(MANIFEST_PATH, "r", encoding="utf-8") as f:
            self.manifest = json.load(f)

        # Precompute anomalies and rolls for fast slicing
        self.precomputed = {}
        for target in ["hs", "current_speed"]:
            x = self.df[target].to_numpy(float)
            idx = self.df.index
            doy = np.minimum(idx.dayofyear.to_numpy(), 366)
            hour = idx.hour.to_numpy()

            sm = np.array(self.clim_params[target]["sm"])
            hod = np.array(self.clim_params[target]["hod"])
            c = sm[doy - 1] + hod[hour]
            a = x - c

            rolls = {}
            for w in ROLLS:
                rolls[w] = pd.Series(a, index=idx).rolling(w, min_periods=int(w * 0.7)).mean()

            self.precomputed[target] = {
                "sm": sm,
                "hod": hod,
                "c_series": pd.Series(c, index=idx),
                "a_series": pd.Series(a, index=idx),
                "rolls": rolls
            }

    def extract_features(
        self,
        target: str,
        origin_time: Union[pd.Timestamp, str],
        horizon_h: int
    ) -> np.ndarray:
        """
        Extracts the 34-element feature vector for (target, origin_time, horizon_h).
        Guarantees exact numerical alignment with train_short_range_models.py.
        """
        t0 = pd.to_datetime(origin_time)
        if t0.tzinfo is None and self.df.index.tz is not None:
            t0 = t0.tz_localize(self.df.index.tz)
        elif t0.tzinfo is not None and self.df.index.tz is not None:
            t0 = t0.tz_convert(self.df.index.tz)

        t_target = t0 + pd.Timedelta(hours=horizon_h)
        lag_t = LAGS_MAP[target]
        t_anchor = t0 - pd.Timedelta(hours=lag_t)

        pre = self.precomputed[target]
        a_series = pre["a_series"]
        rolls = pre["rolls"]

        cols = []

        # 1. Target anomaly lags
        for l in LAGS:
            t_lag = t_anchor - pd.Timedelta(hours=l)
            if t_lag in a_series.index:
                cols.append(float(a_series.loc[t_lag]))
            else:
                # If slightly before index, use nearest
                prior = a_series.loc[:t_lag]
                cols.append(float(prior.iloc[-1]) if len(prior) > 0 else 0.0)

        # 2. Rolling anomaly means
        for w in ROLLS:
            r_s = rolls[w]
            if t_anchor in r_s.index:
                val = r_s.loc[t_anchor]
            else:
                prior = r_s.loc[:t_anchor]
                val = prior.iloc[-1] if len(prior) > 0 else 0.0
            cols.append(float(val if np.isfinite(val) else 0.0))

        # 3. Climatology at target time & calendar DOY harmonics
        doy_target = min(t_target.dayofyear, 366)
        hour_target = t_target.hour
        clim_target = pre["sm"][doy_target - 1] + pre["hod"][hour_target]
        cols.append(float(clim_target))

        cols.append(float(np.sin(2 * np.pi * doy_target / 366.0)))
        cols.append(float(np.cos(2 * np.pi * doy_target / 366.0)))

        # 4. Aux variables (lagged value & 24h change)
        aux_list = AUX_COLS[target]
        for a_col in aux_list:
            lag_a = LAGS_MAP[a_col]
            t_oa = t0 - pd.Timedelta(hours=lag_a)
            t_oa_24 = t_oa - pd.Timedelta(hours=24)

            s_aux = self.df[a_col]
            v_curr = float(s_aux.loc[t_oa]) if t_oa in s_aux.index else (float(s_aux.loc[:t_oa].iloc[-1]) if len(s_aux.loc[:t_oa]) > 0 else 0.0)
            v_prev = float(s_aux.loc[t_oa_24]) if t_oa_24 in s_aux.index else (float(s_aux.loc[:t_oa_24].iloc[-1]) if len(s_aux.loc[:t_oa_24]) > 0 else v_curr)

            cols.append(v_curr)
            cols.append(v_curr - v_prev)

        return np.array(cols, dtype=np.float32)


def get_feature_extractor() -> ServingFeatureExtractor:
    return ServingFeatureExtractor()
