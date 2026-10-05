"""
Makes the future targets for each horizon and stacks them into one table.

- Horizons: 1h (go/no-go at the dock), 6h, 12h, 24h, 48h (weekend), 72h, 96h, 144h.
- All 8 horizons go into one long table with `horizon` as a feature,
  so one model per variable works for all horizons.
- wind_dir is split into target_wind_dir_sin and target_wind_dir_cos.
- Sorted by time so the time splits stay in order for all horizons.
"""

import numpy as np
import pandas as pd

HORIZONS = [1, 6, 12, 24, 48, 72, 96, 144]  # hours: 1h, 6h, 12h, 24h, 48h, 72h, 96h, 144h
TARGET_VARS = [
    "hs", "tp", "swell_height", "wind_wave_height",
    "wind_speed", "wind_gust", "wind_dir", "slp",
    "current_u", "current_v",
]


def build_stacked_dataset(df: pd.DataFrame, lagged_features: pd.DataFrame) -> pd.DataFrame:
    stacked = []
    for h in HORIZONS:
        block = lagged_features.copy()
        block["horizon"] = h
        for var in TARGET_VARS:
            block[f"target_{var}"] = df[var].shift(-h)  # value h hours AFTER this row

        # sin/cos of the wind direction at t+H
        if "wind_dir" in df.columns:
            rad = np.radians(df["wind_dir"].shift(-h))
            block["target_wind_dir_sin"] = np.sin(rad)
            block["target_wind_dir_cos"] = np.cos(rad)

        stacked.append(block)

    result = pd.concat(stacked, axis=0)
    # Remove rows that are NaN because of the lags or the horizon shift
    # (at the start there isn't enough history, at the end not enough future).
    # .sort_index() keeps all horizons for time t in order.
    result = result.dropna().sort_index()
    return result
