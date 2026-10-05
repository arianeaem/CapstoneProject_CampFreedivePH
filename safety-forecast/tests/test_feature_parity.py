"""
Checks that ServingFeatureExtractor gives the same features as training
(train_short_range_models.py) on past test origins.

- difference between serving and training features must be < 1e-4
  (much smaller than the model MAE of ~0.1 m)
- tests 'hs' and 'current_speed' for several horizons and seasons
"""

import sys
from pathlib import Path
import numpy as np
import pandas as pd
import pytest

PROJECT_ROOT = Path(__file__).resolve().parents[1]
if str(PROJECT_ROOT) not in sys.path:
    sys.path.insert(0, str(PROJECT_ROOT))

from src.serve.serving_feature_extractor import ServingFeatureExtractor, LAGS, ROLLS, LAGS_MAP, AUX_COLS
DATA_PATH = PROJECT_ROOT / "data" / "processed" / "collocated.parquet"
CLIM_PARAMS_PATH = PROJECT_ROOT / "models" / "short_range" / "clim_params.json"


def load_training_dataset():
    df = pd.read_parquet(DATA_PATH)
    if not isinstance(df.index, pd.DatetimeIndex):
        for c in ("timestamp", "time", "datetime", "date"):
            if c in df.columns:
                df = df.set_index(pd.to_datetime(df[c])).drop(columns=[c])
                break
    df = df.sort_index()
    df = df[~df.index.duplicated()]
    df = df.asfreq("h").interpolate(limit=6)
    if "current_speed" not in df and {"current_u", "current_v"} <= set(df.columns):
        df["current_speed"] = np.hypot(df["current_u"], df["current_v"])
    if "wind_speed" not in df and {"wind_u", "wind_v"} <= set(df.columns):
        df["wind_speed"] = np.hypot(df["wind_u"], df["wind_v"])
    return df


@pytest.fixture(scope="module")
def extractor():
    return ServingFeatureExtractor()


@pytest.fixture(scope="module")
def train_df():
    return load_training_dataset()


def compute_training_features_at_origin(df, target, o_idx, H):
    """Same feature code as train_short_range_models.py lines 422-433."""
    import json
    with open(CLIM_PARAMS_PATH, "r") as f:
        params = json.load(f)

    lag_t = LAGS_MAP[target]
    aux_cols = AUX_COLS[target]
    lag_a = {c: LAGS_MAP[c] for c in aux_cols}

    idx = df.index
    doy = np.minimum(idx.dayofyear.to_numpy(), 366)
    hour = idx.hour.to_numpy()
    x = df[target].to_numpy(float)
    aux = {c: df[c].to_numpy(float) for c in aux_cols}

    sm = np.array(params[target]["sm"])
    hod = np.array(params[target]["hod"])
    c_prod = sm[doy - 1] + hod[hour]
    a_prod = x - c_prod
    rolls_prod = {w: pd.Series(a_prod).rolling(w, min_periods=int(w * 0.7)).mean().to_numpy() for w in ROLLS}

    ot = o_idx - lag_t
    cols = [a_prod[ot - l] for l in LAGS]
    cols += [rolls_prod[w][ot] for w in ROLLS]
    cols.append(c_prod[o_idx + H])
    cols.append(np.sin(2 * np.pi * doy[o_idx + H] / 366))
    cols.append(np.cos(2 * np.pi * doy[o_idx + H] / 366))
    for name in aux_cols:
        oa = o_idx - lag_a[name]
        cols.append(aux[name][oa])
        cols.append(aux[name][oa] - aux[name][oa - 24])

    return np.array(cols, dtype=np.float32)


def test_feature_parity_hs(extractor, train_df):
    """Test 'hs' for several origins and horizons."""
    test_origins = [
        "2024-01-15 12:00:00+08:00",  # Amihan
        "2024-07-20 08:00:00+08:00",  # Habagat
        "2025-02-10 14:00:00+08:00",  # Pre-holdout Amihan
        "2025-05-18 10:00:00+08:00",  # Holdout Summer
    ]
    horizons = [6, 12, 24, 36, 48]

    for origin_str in test_origins:
        ts = pd.Timestamp(origin_str)
        o_idx = train_df.index.get_loc(ts)

        for H in horizons:
            feat_serving = extractor.extract_features("hs", ts, H)
            feat_training = compute_training_features_at_origin(train_df, "hs", o_idx, H)

            assert len(feat_serving) == 34, f"Serving features length is {len(feat_serving)}, expected 34"
            assert len(feat_training) == 34, f"Training features length is {len(feat_training)}, expected 34"

            diff = np.abs(feat_serving - feat_training)
            max_diff = float(np.max(diff))
            mean_diff = float(np.mean(diff))

            # Must be much smaller than the model MAE of ~0.1 m
            assert max_diff < 1e-4, (
                f"Feature parity failed for hs at {origin_str} H={H}! "
                f"Max diff={max_diff:.6e}, Mean diff={mean_diff:.6e}"
            )


def test_feature_parity_current_speed(extractor, train_df):
    """Test 'current_speed' for several origins and horizons."""
    test_origins = [
        "2024-02-15 12:00:00+08:00",
        "2024-08-20 08:00:00+08:00",
        "2025-03-10 14:00:00+08:00",
        "2025-06-18 10:00:00+08:00",
    ]
    horizons = [24, 48, 72]

    for origin_str in test_origins:
        ts = pd.Timestamp(origin_str)
        o_idx = train_df.index.get_loc(ts)

        for H in horizons:
            feat_serving = extractor.extract_features("current_speed", ts, H)
            feat_training = compute_training_features_at_origin(train_df, "current_speed", o_idx, H)

            assert len(feat_serving) == 34
            assert len(feat_training) == 34

            diff = np.abs(feat_serving - feat_training)
            max_diff = float(np.max(diff))
            mean_diff = float(np.mean(diff))

            assert max_diff < 1e-4, (
                f"Feature parity failed for current_speed at {origin_str} H={H}! "
                f"Max diff={max_diff:.6e}, Mean diff={mean_diff:.6e}"
            )


if __name__ == "__main__":
    pytest.main(["-v", __file__])
