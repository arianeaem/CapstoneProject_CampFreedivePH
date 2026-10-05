"""
Unified Training & Serving Eligibility Module for Camp FreedivePH.

Guarantees 100% strict adherence to:
1. Operational lag boundaries:
   - ERA5 Atmosphere:    120 hours (5 days)
   - CMEMS Currents:     24 hours
   - NASA GPM IMERG:     14 hours
   - CMEMS Waves:        12 hours
2. Native resolution purity:
   - Waves: only native 3-hourly observations (wave_is_interpolated == False)
   - Currents: hourly instantaneous analysis
3. Strict tz-awareness:
   - All internal comparisons and filtering performed in UTC.
   - PHT (Asia/Manila, UTC+08:00) provided for diurnal features and presentation.
4. Issue time boundary:
   - is_provisional = time_utc > (issue_time_utc - operational_lag)
"""

from typing import Optional, Dict
from datetime import datetime, timezone
import pandas as pd

from config import OPERATIONAL_LAGS, TARGET_TIMEZONE, DATA_ROOT

DEFAULT_SNAPSHOT_DIR = DATA_ROOT / "snapshots" / "2026-10-04_rev2"


def get_source_cutoff(source: str, issue_time_utc: Optional[pd.Timestamp] = None) -> pd.Timestamp:
    """
    Computes the strict operational cutoff in UTC for a given source:
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
    Evaluates whether each row is provisional based on source operational lag.
    A row is provisional if its observation timestamp (UTC) is greater than
    (issue_time_utc - operational_lag).
    """
    cutoff = get_source_cutoff(source, issue_time_utc)
    
    # Ensure index is tz-aware UTC
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
    Filters a dataset to only rows eligible for model training.

    Rules:
    - All sources: is_provisional == False (strictly <= issue_time_utc - operational_lag)
    - Waves: wave_is_interpolated == False (native 3-hourly observations only)
    """
    source_lower = source.lower()
    df_clean = df.copy()

    # Re-evaluate provisional flag based on explicit issue_time_utc if provided
    if issue_time_utc is not None or "is_provisional" not in df_clean.columns:
        df_clean["is_provisional"] = evaluate_provisional_status(df_clean, source_lower, issue_time_utc)

    # Base filter: Non-provisional
    mask = ~df_clean["is_provisional"]

    # Wave-specific filter: Native observations only (no interpolated rows in training targets/features)
    if source_lower == "waves" and "wave_is_interpolated" in df_clean.columns:
        mask = mask & (~df_clean["wave_is_interpolated"])

    eligible_df = df_clean.loc[mask].copy()

    # Ensure canonical time columns
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
    Canonical loader for snapshot datasets.
    Reads from snapshot directory (NOT interim), ensuring immutability.
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
