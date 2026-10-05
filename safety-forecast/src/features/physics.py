"""
Physics features.
Used in both training and live predictions.
"""

from pathlib import Path
import numpy as np
import pandas as pd


def compute_marine_physics_features(df: pd.DataFrame) -> pd.DataFrame:
    """
    Computes:
    - wave steepness (Hs / wavelength)
    - swell ratio (how much of the wave energy is swell)
    - 3-hour pressure change (delta_p_3h)
    - angle between wind and current, and between wind and waves
    - time of day and time of year as sin/cos (hour_sin/cos, doy_sin/cos)

    Tide features (tidal_rate, slack_tide_proxy) are left out on purpose (out of scope).
    """
    df = df.copy()
    g = 9.80665

    # 1. Wave steepness: H / L ~ (2 * pi * Hs) / (g * Tp^2)
    df["wave_steepness"] = (2 * np.pi * df["hs"]) / (g * (df["tp"] ** 2) + 1e-6)

    # 2. Swell ratio: share of the wave energy from long-period swell
    df["swell_ratio"] = df["swell_height"] / (df["hs"] + 1e-5)

    # 3. Pressure change: 3-hour SLP change in hPa
    df["delta_p_3h"] = df["slp"] - df["slp"].shift(3)

    # 4. Angle difference on [0, 180] deg
    if "wave_dir" in df.columns:
        angle_diff_wave = np.abs(df["wind_dir"] - df["wave_dir"])
        df["wind_wave_alignment"] = np.minimum(angle_diff_wave, 360 - angle_diff_wave)

    if "current_dir" in df.columns:
        angle_diff_curr = np.abs(df["wind_dir"] - df["current_dir"])
        df["wind_current_alignment"] = np.minimum(angle_diff_curr, 360 - angle_diff_curr)

    # 5. Time sin/cos from the DatetimeIndex
    if hasattr(df.index, "hour"):
        hours = df.index.hour
        doy = df.index.dayofyear
    elif "hour" in df.columns and "day_of_year" in df.columns:
        hours = df["hour"]
        doy = df["day_of_year"]
    else:
        time_col = pd.to_datetime(df.index)
        hours = time_col.hour
        doy = time_col.dayofyear

    df["hour_sin"] = np.sin(2 * np.pi * hours / 24.0)
    df["hour_cos"] = np.cos(2 * np.pi * hours / 24.0)
    df["doy_sin"] = np.sin(2 * np.pi * doy / 365.25)
    df["doy_cos"] = np.cos(2 * np.pi * doy / 365.25)

    return df
