"""
Decides which rows can be used for training and serving.

1. Delay of each data source:
   - ERA5 atmosphere:  120 hours (5 days)
   - CMEMS currents:   24 hours
   - NASA GPM IMERG:   14 hours
   - CMEMS waves:      12 hours
2. Only real data points:
   - Waves: only the real 3-hourly values (wave_is_interpolated == False)
   - Currents: hourly values
3. Time zones:
   - All checks are done in UTC.
   - PHT (Asia/Manila, UTC+8) is only used for time-of-day features and display.
4. Provisional rows:
   - is_provisional = time_utc > (issue_time_utc - operational_lag)
"""

from typing import Optional, Dict
from datetime import datetime, timezone
import pandas as pd

from config import OPERATIONAL_LAGS, TARGET_TIMEZONE, DATA_ROOT

DEFAULT_SNAPSHOT_DIR = DATA_ROOT / "snapshots" / "2026-10-04_rev2"


def get_source_cutoff(source: str, issue_time_utc: Optional[pd.Timestamp] = None) -> pd.Timestamp:
    """
    Cutoff time in UTC for a source:
    cutoff_utc = issue_time_utc - operational_lag
    """
    if issue_time_utc is None:
        issue_time_utc = pd.Timestamp.now(tz="UTC")
    elif issue_time_utc.tzinfo is None:
        issue_time_utc = issue_time_utc.tz_localize("UTC")
    else:
        issue_time_utc = issue_time_utc.tz_convert("UTC")

    lag = OPERATIONAL_LAGS.get(source.lower())
    if lag is None:
        raise ValueError(f"Unknown source '{source}'. Must be one of {list(OPERATIONAL_LAGS.keys())}")

    return issue_time_utc - lag


def evaluate_provisional_status(
    df: pd.DataFrame,
    source: str,
    issue_time_utc: Optional[pd.Timestamp] = None
) -> pd.Series:
    """
    Mark each row as provisional or not, based on the source delay.
    A row is provisional if its time (UTC) is after (issue_time_utc - operational_lag).
    """
    cutoff = get_source_cutoff(source, issue_time_utc)
    
    # Make sure the index is in UTC
    idx_utc = df.index
    if idx_utc.tzinfo is None:
        idx_utc = idx_utc.tz_localize("UTC")
    else:
        idx_utc = idx_utc.tz_convert("UTC")

    return pd.Series(idx_utc > cutoff, index=df.index, name="is_provisional")


def filter_training_eligible(
    df: pd.DataFrame,
    source: str,
    issue_time_utc: Optional[pd.Timestamp] = None
) -> pd.DataFrame:
    """
    Keep only the rows that can be used for training.

    Rules:
    - all sources: is_provisional == False (time <= issue_time_utc - operational_lag)
    - waves: wave_is_interpolated == False (real 3-hourly values only)
    """
    source_lower = source.lower()
    df_clean = df.copy()

    # Recompute the provisional flag if issue_time_utc is given
    if issue_time_utc is not None or "is_provisional" not in df_clean.columns:
        df_clean["is_provisional"] = evaluate_provisional_status(df_clean, source_lower, issue_time_utc)

    # Remove provisional rows
    mask = ~df_clean["is_provisional"]

    # Waves: remove interpolated rows
    if source_lower == "waves" and "wave_is_interpolated" in df_clean.columns:
        mask = mask & (~df_clean["wave_is_interpolated"])

    eligible_df = df_clean.loc[mask].copy()

    # Make sure the time columns exist
    if "time_utc" not in eligible_df.columns:
        eligible_df["time_utc"] = eligible_df.index.tz_convert("UTC")
    if "time_pht" not in eligible_df.columns:
        eligible_df["time_pht"] = eligible_df.index.tz_convert(TARGET_TIMEZONE)

    return eligible_df


def load_snapshot_dataset(
    source: str,
    snapshot_dir: Optional[pd.Timestamp] = None,
    training_only: bool = False,
    issue_time_utc: Optional[pd.Timestamp] = None
) -> pd.DataFrame:
    """
    Load a snapshot dataset.
    Reads from the snapshot folder (not interim) so the data doesn't change.
    """
    s_dir = Path(snapshot_dir) if snapshot_dir else DEFAULT_SNAPSHOT_DIR
    filename_map = {
        "waves": "cmems_waves.parquet",
        "currents": "cmems_currents.parquet",
    }
    fname = filename_map.get(source.lower())
    if not fname:
        raise ValueError(f"Source '{source}' is not in snapshot {s_dir.name}. Available: {list(filename_map.keys())}")

    filepath = s_dir / fname
    if not filepath.exists():
        raise FileNotFoundError(f"Snapshot file not found: {filepath}")

    df = pd.read_parquet(filepath)
    if training_only:
        return filter_training_eligible(df, source, issue_time_utc)
    return df
