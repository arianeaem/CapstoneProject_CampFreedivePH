"""
Observed Store Ingestion & Refresh Module for Camp FreedivePH.

Ingests and refreshes the operational Observed Store using the exact dataset IDs,
coordinates, and processing logic verified during model training:
1. CMEMS Waves:
   - Dataset ID: cmems_mod_glo_wav_anfc_0.083deg_PT3H-i
   - Cell: (13.6667, 120.9167) [3.43 km South-Southeast of site]
   - Processing: 3-hourly native series resampled to hourly with limit=6 linear interpolation.
2. CMEMS Currents:
   - Dataset ID: cmems_mod_glo_phy_anfc_merged-uv_PT1H-i
   - Cell: (13.6667, 120.8333) [6.86 km South-Southwest of site]
   - Processing: Decomposed into Eulerian, Tide, and Stokes components, computing total current speed.
3. ECMWF ERA5 / Atmospheric:
   - Cell: 2D bilinear at site (13.6874, 120.8931) with peak-preserving 4-corner gust.
   - Processing: Calculates wind_speed = hypot(u, v), slp in hPa, wind_dir in degrees.

Writes:
- data/store/observed_store.parquet
- data/store/store_meta.json (recording exact last_observation_at per variable)
"""

import json
from pathlib import Path
from typing import Optional
import numpy as np
import pandas as pd

PROJECT_ROOT = Path(__file__).resolve().parents[2]
STORE_DIR = PROJECT_ROOT / "data" / "store"
SNAPSHOT_DIR = PROJECT_ROOT / "data" / "snapshots" / "2026-10-04_rev4"
PROCESSED_DIR = PROJECT_ROOT / "data" / "processed"
INTERIM_DIR = PROJECT_ROOT / "data" / "interim"

SITE_LAT = 13.6874
SITE_LON = 120.8931
TARGET_TIMEZONE = "Asia/Manila"


def _default_source(interim_name: str, snapshot_name: str) -> Path:
    """Prefer the latest ingestion output; use the sealed snapshot only as fallback."""
    live_path = INTERIM_DIR / interim_name
    if live_path.exists():
        return live_path
    snapshot_path = SNAPSHOT_DIR / snapshot_name
    print(
        f"WARNING: live interim source is missing ({live_path}); "
        f"using sealed snapshot {snapshot_path}"
    )
    return snapshot_path

DATASET_IDS = {
    "cmems_waves": {
        "dataset_id": "cmems_mod_glo_wav_anfc_0.083deg_PT3H-i",
        "cell_lat": 13.6667,
        "cell_lon": 120.9167,
        "native_frequency": "PT3H",
        "interpolation": "linear_limit_6h",
        "variables": ["hs", "tp", "swell_height", "wind_wave_height"]
    },
    "cmems_currents": {
        "dataset_id": "cmems_mod_glo_phy_anfc_merged-uv_PT1H-i",
        "cell_lat": 13.6667,
        "cell_lon": 120.8333,
        "native_frequency": "PT1H",
        "variables": [
            "current_u", "current_v", "current_speed", "current_dir",
            "eulerian_u", "eulerian_v", "eulerian_speed", "eulerian_dir",
            "tide_u", "tide_v", "tide_speed", "tide_dir",
            "stokes_u", "stokes_v", "stokes_speed", "stokes_dir"
        ]
    },
    "era5_atmosphere": {
        "product": "ECMWF ERA5 Reanalysis",
        "interpolation": "2d_bilinear_site_with_peak_preserving_gust",
        "target_lat": SITE_LAT,
        "target_lon": SITE_LON,
        "variables": ["wind_u", "wind_v", "wind_speed", "wind_gust", "wind_dir", "slp"]
    }
}


def load_and_process_waves(source_file: Optional[Path] = None) -> pd.DataFrame:
    """Loads 3-hourly waves and interpolates to hourly grid identically to training."""
    path = source_file or _default_source("cmems_waves.parquet", "cmems_waves.parquet")
    df = pd.read_parquet(path)
    if not isinstance(df.index, pd.DatetimeIndex):
        for c in ("timestamp", "time", "datetime", "date"):
            if c in df.columns:
                df = df.set_index(pd.to_datetime(df[c])).drop(columns=[c])
                break
    df = df.sort_index()
    df = df[~df.index.duplicated()]
    # Interpolate 3-hourly to 1-hourly
    df = df.asfreq("h").interpolate(method="linear", limit=6)
    cols = ["hs", "tp", "swell_height", "wind_wave_height"]
    return df[cols]


def load_and_process_currents(source_file: Optional[Path] = None) -> pd.DataFrame:
    """Loads currents and computes speed / decomposition components identically to training."""
    path = source_file or _default_source("cmems_currents.parquet", "cmems_currents.parquet")
    df = pd.read_parquet(path)
    if not isinstance(df.index, pd.DatetimeIndex):
        for c in ("timestamp", "time", "datetime", "date"):
            if c in df.columns:
                df = df.set_index(pd.to_datetime(df[c])).drop(columns=[c])
                break
    df = df.sort_index()
    df = df[~df.index.duplicated()]
    df = df.asfreq("h").interpolate(method="linear", limit=6)

    # Compute speeds if missing
    if "current_speed" not in df and {"current_u", "current_v"} <= set(df.columns):
        df["current_speed"] = np.hypot(df["current_u"], df["current_v"])
    if "eulerian_speed" not in df and {"eulerian_u", "eulerian_v"} <= set(df.columns):
        df["eulerian_speed"] = np.hypot(df["eulerian_u"], df["eulerian_v"])
    if "tide_speed" not in df and {"tide_u", "tide_v"} <= set(df.columns):
        df["tide_speed"] = np.hypot(df["tide_u"], df["tide_v"])
    if "stokes_speed" not in df and {"stokes_u", "stokes_v"} <= set(df.columns):
        df["stokes_speed"] = np.hypot(df["stokes_u"], df["stokes_v"])

    cols = [
        "current_u", "current_v", "current_speed",
        "eulerian_u", "eulerian_v", "eulerian_speed",
        "tide_u", "tide_v", "tide_speed",
        "stokes_u", "stokes_v", "stokes_speed"
    ]
    present_cols = [c for c in cols if c in df.columns]
    return df[present_cols]


def load_and_process_atmosphere(source_file: Optional[Path] = None) -> pd.DataFrame:
    """Loads atmospheric reanalysis identically to training."""
    path = source_file or _default_source("era5_wind_pressure.parquet", "era5_wind_pressure.parquet")
    df = pd.read_parquet(path)
    if not isinstance(df.index, pd.DatetimeIndex):
        for c in ("timestamp", "time", "datetime", "date"):
            if c in df.columns:
                df = df.set_index(pd.to_datetime(df[c])).drop(columns=[c])
                break
    df = df.sort_index()
    df = df[~df.index.duplicated()]
    df = df.asfreq("h").interpolate(method="linear", limit=6)

    if "wind_speed" not in df and {"wind_u", "wind_v"} <= set(df.columns):
        df["wind_speed"] = np.hypot(df["wind_u"], df["wind_v"])

    cols = ["wind_speed", "wind_gust", "slp", "wind_dir"]
    if "wind_u" in df and "wind_v" in df:
        cols = ["wind_u", "wind_v"] + cols
    present_cols = [c for c in cols if c in df.columns]
    return df[present_cols]


def refresh_observed_store(
    waves_path: Optional[Path] = None,
    currents_path: Optional[Path] = None,
    atmosphere_path: Optional[Path] = None,
    store_dir: Optional[Path] = None
) -> pd.DataFrame:
    """
    Refreshes the observed store by combining waves, currents, and atmosphere datasets.
    Records metadata with exact last_observation_at timestamps per variable.
    """
    out_dir = store_dir or STORE_DIR
    out_dir.mkdir(parents=True, exist_ok=True)

    print("Refreshing Observed Store...")
    w_df = load_and_process_waves(waves_path)
    c_df = load_and_process_currents(currents_path)
    a_df = load_and_process_atmosphere(atmosphere_path)

    # Preserve each source's trailing observations. An inner join would
    # truncate CMEMS data to the older ERA5 tail and hide per-variable lags.
    store_df = w_df.join(c_df, how="outer").join(a_df, how="outer")
    store_df = store_df.sort_index()

    if {"wind_speed", "wind_gust"} <= set(store_df.columns):
        wind = store_df["wind_speed"].dropna().iloc[-1]
        gust = store_df["wind_gust"].dropna().iloc[-1]
        print(
            "Wind unit audit: "
            f"raw wind={wind:.3f} m/s -> {wind * 3.6:.2f} km/h; "
            f"raw gust={gust:.3f} m/s -> {gust * 3.6:.2f} km/h"
        )

    # Track last valid observation timestamp per variable
    last_obs_at = {}
    for col in store_df.columns:
        valid_series = store_df[col].dropna()
        if len(valid_series) > 0:
            last_obs_at[col] = str(valid_series.index[-1])
        else:
            last_obs_at[col] = None

    # Save to parquet
    out_parquet = out_dir / "observed_store.parquet"
    store_df.to_parquet(out_parquet)
    print(f"Saved Observed Store ({len(store_df)} rows, {store_df.shape[1]} cols) to {out_parquet}")

    # Build metadata manifest
    meta = {
        "store_name": "Camp FreedivePH Operational Observed Store",
        "refreshed_at": str(pd.Timestamp.now(tz=TARGET_TIMEZONE)),
        "time_range": {
            "start": str(store_df.index[0]),
            "end": str(store_df.index[-1]),
            "total_hours": len(store_df)
        },
        "dataset_ids": DATASET_IDS,
        "last_observation_at": last_obs_at,
        "operational_lags": {
            "waves_lag_hours": 12,
            "currents_lag_hours": 24,
            "atmosphere_lag_hours": 120
        },
        "source_files": {
            "waves": str(waves_path or _default_source("cmems_waves.parquet", "cmems_waves.parquet")),
            "currents": str(currents_path or _default_source("cmems_currents.parquet", "cmems_currents.parquet")),
            "atmosphere": str(atmosphere_path or _default_source("era5_wind_pressure.parquet", "era5_wind_pressure.parquet")),
        },
        "columns": store_df.columns.tolist()
    }

    out_meta = out_dir / "store_meta.json"
    with open(out_meta, "w", encoding="utf-8") as f:
        json.dump(meta, f, indent=2)
    print(f"Saved Observed Store metadata to {out_meta}")

    return store_df


if __name__ == "__main__":
    refresh_observed_store()
