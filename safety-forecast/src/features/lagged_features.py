"""
Builds the lag and rolling features.

Features:
- RAW_VARS at LAG_HOURS [0, 1, 3, 6, 12, 24, 48]
  (0 = value at time t, 1..48 = past values)
- 24h rolling mean, std, min and max, computed after .shift(1)
  so they cover t-1 back to t-24 and don't include t.
"""

import pandas as pd

RAW_VARS = [
    "hs", "tp", "swell_height", "wind_wave_height", "current_u", "current_v",
    "wind_u", "wind_v", "wind_speed", "wind_gust", "slp", "rain_rate_mm_hr",
]

LAG_HOURS = [0, 1, 3, 6, 12, 24, 48]  # 0 = the latest value
# lag 0 is on purpose. We already know it at time t, so it's not leakage when
# we predict t+H for H >= 1. Persistence is basically "use lag 0", so the
# model needs it too to have a fair chance of beating persistence.
ROLLING_WINDOW = 24


def build_lagged_features(df: pd.DataFrame) -> pd.DataFrame:
    df_in = df.copy()
    if "wind_u" not in df_in.columns and "wind_speed" in df_in.columns and "wind_dir" in df_in.columns:
        import numpy as np
        rad = np.radians(df_in["wind_dir"])
        df_in["wind_u"] = -df_in["wind_speed"] * np.sin(rad)
        df_in["wind_v"] = -df_in["wind_speed"] * np.cos(rad)

    cols: dict[str, pd.Series] = {}

    for var in RAW_VARS:
        for lag in LAG_HOURS:
            cols[f"{var}_lag{lag}h"] = df_in[var].shift(lag)

        # .shift(1) before .rolling() on purpose: without it the window would
        # include the current hour. With the shift it covers t-1 back to
        # t-ROLLING_WINDOW. (We already had a leakage bug once, don't bring it back.)
        shifted = df_in[var].shift(1)
        cols[f"{var}_roll_mean{ROLLING_WINDOW}h"] = shifted.rolling(ROLLING_WINDOW).mean()
        cols[f"{var}_roll_std{ROLLING_WINDOW}h"] = shifted.rolling(ROLLING_WINDOW).std()
        cols[f"{var}_roll_min{ROLLING_WINDOW}h"] = shifted.rolling(ROLLING_WINDOW).min()
        cols[f"{var}_roll_max{ROLLING_WINDOW}h"] = shifted.rolling(ROLLING_WINDOW).max()

    return pd.DataFrame(cols, index=df_in.index)
