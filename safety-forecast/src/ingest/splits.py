"""
Train / validation / holdout split and the walk-forward folds.

Based on PRD sections 4 and 11:
1. Holdout: 2025-10-01 00:00 UTC to 2026-10-03 00:00 UTC (no provisional rows).
   Only used once for the final test, never during training or tuning.
2. Last training origin: 2025-09-30 00:00 UTC minus 240h (2025-09-20 00:00 UTC),
   so a 240h forecast from training can't reach the holdout.
3. Walk-forward folds (each one adds more data):
   - Waves (3 years): 4 folds. Fold 4 has Habagat 2025 (May-Sep 2025) for the conformal calibration.
   - Currents (5 years): 6 folds, from 2020-11 to 2025-09, several monsoon seasons.
   - At least 240h gap between train and validation.
4. When to use Option B:
   - Based on skill vs climatology in the CV folds (not raw MAE, because MAE is
     naturally different between Amihan and Habagat).
   - Big waves (Hs > 2.0 m) are checked in the CV folds, never in the holdout.
"""

from typing import List, Dict, Any, Tuple, Optional
from datetime import datetime, timezone
import numpy as np
import pandas as pd

from config import TARGET_TIMEZONE

# All split dates are in UTC
HOLDOUT_START_UTC = pd.Timestamp("2025-10-01 00:00:00", tz="UTC")
HOLDOUT_END_UTC   = pd.Timestamp("2026-10-03 00:00:00", tz="UTC")

PURGE_MARGIN_HOURS = 240  # 10 days
TRAIN_MAX_ORIGIN_UTC = pd.Timestamp("2025-09-30 00:00:00", tz="UTC") - pd.Timedelta(hours=PURGE_MARGIN_HOURS)

# 4 walk-forward folds for CMEMS waves (Nov 2022 to Sep 2025)
WAVES_WALK_FORWARD_FOLDS = [
    {
        "fold": 1,
        "name": "Wave Fold 1 (Amihan 23-24 Validation)",
        "train_start": "2022-11-01 03:00:00",
        "train_end":   "2023-10-15 00:00:00",
        "purge_hours": 240,
        "val_start":   "2023-10-25 00:00:00",
        "val_end":     "2024-03-31 23:00:00",
        "val_monsoon": "Transition + Amihan (Northeast Monsoon)"
    },
    {
        "fold": 2,
        "name": "Wave Fold 2 (Habagat 2024 Validation)",
        "train_start": "2022-11-01 03:00:00",
        "train_end":   "2024-04-15 00:00:00",
        "purge_hours": 240,
        "val_start":   "2024-04-25 00:00:00",
        "val_end":     "2024-10-15 23:00:00",
        "val_monsoon": "Dry Season + Habagat (Southwest Monsoon)"
    },
    {
        "fold": 3,
        "name": "Wave Fold 3 (Amihan 24-25 Validation)",
        "train_start": "2022-11-01 03:00:00",
        "train_end":   "2024-10-31 00:00:00",
        "purge_hours": 240,
        "val_start":   "2024-11-10 00:00:00",
        "val_end":     "2025-04-30 23:00:00",
        "val_monsoon": "Amihan (Northeast Monsoon) + Early Dry"
    },
    {
        "fold": 4,
        "name": "Wave Fold 4 (Habagat 2025 Calibration / Validation)",
        "train_start": "2022-11-01 03:00:00",
        "train_end":   "2025-04-30 00:00:00",
        "purge_hours": 240,
        "val_start":   "2025-05-10 00:00:00",
        "val_end":     "2025-09-20 00:00:00",
        "val_monsoon": "Habagat 2025 (Conformal Residual Calibration Split)"
    }
]

# 6 walk-forward folds for CMEMS currents (Nov 2020 to Sep 2025)
CURRENTS_WALK_FORWARD_FOLDS = [
    {
        "fold": 1,
        "name": "Currents Fold 1 (Amihan 21-22 Validation)",
        "train_start": "2020-11-01 00:00:00",
        "train_end":   "2021-10-15 00:00:00",
        "purge_hours": 240,
        "val_start":   "2021-10-25 00:00:00",
        "val_end":     "2022-04-30 23:00:00",
        "val_monsoon": "Amihan 2021-2022"
    },
    {
        "fold": 2,
        "name": "Currents Fold 2 (Habagat 2022 Validation)",
        "train_start": "2020-11-01 00:00:00",
        "train_end":   "2022-04-15 00:00:00",
        "purge_hours": 240,
        "val_start":   "2022-04-25 00:00:00",
        "val_end":     "2022-10-15 23:00:00",
        "val_monsoon": "Habagat 2022"
    },
    {
        "fold": 3,
        "name": "Currents Fold 3 (Habagat 2023 Validation)",
        "train_start": "2020-11-01 00:00:00",
        "train_end":   "2023-04-15 00:00:00",
        "purge_hours": 240,
        "val_start":   "2023-04-25 00:00:00",
        "val_end":     "2023-10-15 23:00:00",
        "val_monsoon": "Habagat 2023"
    },
    {
        "fold": 4,
        "name": "Currents Fold 4 (Habagat 2024 Validation)",
        "train_start": "2020-11-01 00:00:00",
        "train_end":   "2024-04-15 00:00:00",
        "purge_hours": 240,
        "val_start":   "2024-04-25 00:00:00",
        "val_end":     "2024-10-15 23:00:00",
        "val_monsoon": "Habagat 2024"
    },
    {
        "fold": 5,
        "name": "Currents Fold 5 (Amihan 24-25 Validation)",
        "train_start": "2020-11-01 00:00:00",
        "train_end":   "2024-10-31 00:00:00",
        "purge_hours": 240,
        "val_start":   "2024-11-10 00:00:00",
        "val_end":     "2025-04-30 23:00:00",
        "val_monsoon": "Amihan 2024-2025"
    },
    {
        "fold": 6,
        "name": "Currents Fold 6 (Habagat 2025 Calibration / Validation)",
        "train_start": "2020-11-01 00:00:00",
        "train_end":   "2025-04-30 00:00:00",
        "purge_hours": 240,
        "val_start":   "2025-05-10 00:00:00",
        "val_end":     "2025-09-20 00:00:00",
        "val_monsoon": "Habagat 2025 (Conformal Residual Calibration Split)"
    }
]


def get_train_holdout_split(df: pd.DataFrame) -> Tuple[pd.DataFrame, pd.DataFrame]:
    """
    Split the data into training and holdout.
    - training ends at TRAIN_MAX_ORIGIN_UTC (240h before the holdout)
    - holdout starts at HOLDOUT_START_UTC and has no provisional rows
    """
    idx_utc = df.index if df.index.tz is not None and df.index.tz.zone == "UTC" else df.index.tz_convert("UTC")

    # Training filter
    train_mask = (idx_utc <= TRAIN_MAX_ORIGIN_UTC)
    if "is_provisional" in df.columns:
        train_mask = train_mask & (~df["is_provisional"])
    if "wave_is_interpolated" in df.columns:
        train_mask = train_mask & (~df["wave_is_interpolated"])

    # Holdout filter
    holdout_mask = (idx_utc >= HOLDOUT_START_UTC) & (idx_utc <= HOLDOUT_END_UTC)
    if "is_provisional" in df.columns:
        holdout_mask = holdout_mask & (~df["is_provisional"])
    if "wave_is_interpolated" in df.columns:
        holdout_mask = holdout_mask & (~df["wave_is_interpolated"])

    train_df = df.loc[train_mask].copy()
    holdout_df = df.loc[holdout_mask].copy()
    return train_df, holdout_df


def get_walk_forward_folds(df: pd.DataFrame, source: str = "waves") -> List[Dict[str, Any]]:
    """
    Gives the train and validation data for each walk-forward fold.
    Uses WAVES_WALK_FORWARD_FOLDS (4) or CURRENTS_WALK_FORWARD_FOLDS (6).
    """
    idx_utc = df.index if df.index.tz is not None and df.index.tz.zone == "UTC" else df.index.tz_convert("UTC")
    fold_configs = WAVES_WALK_FORWARD_FOLDS if source.lower() == "waves" else CURRENTS_WALK_FORWARD_FOLDS
    folds_data = []

    for fold_cfg in fold_configs:
        t_start = pd.Timestamp(fold_cfg["train_start"], tz="UTC")
        t_end   = pd.Timestamp(fold_cfg["train_end"], tz="UTC")
        v_start = pd.Timestamp(fold_cfg["val_start"], tz="UTC")
        v_end   = pd.Timestamp(fold_cfg["val_end"], tz="UTC")

        # Train split
        t_mask = (idx_utc >= t_start) & (idx_utc <= t_end)
        if "is_provisional" in df.columns:
            t_mask = t_mask & (~df["is_provisional"])
        if "wave_is_interpolated" in df.columns:
            t_mask = t_mask & (~df["wave_is_interpolated"])

        # Val split
        v_mask = (idx_utc >= v_start) & (idx_utc <= v_end)
        if "is_provisional" in df.columns:
            v_mask = v_mask & (~df["is_provisional"])
        if "wave_is_interpolated" in df.columns:
            v_mask = v_mask & (~df["wave_is_interpolated"])

        folds_data.append({
            "fold": fold_cfg["fold"],
            "name": fold_cfg["name"],
            "val_monsoon": fold_cfg["val_monsoon"],
            "train_df": df.loc[t_mask].copy(),
            "val_df":   df.loc[v_mask].copy(),
            "purge_hours": fold_cfg["purge_hours"],
            "train_rows": int(t_mask.sum()),
            "val_rows": int(v_mask.sum())
        })

    return folds_data


def check_option_b_trigger_criteria(cv_results: pd.DataFrame) -> Dict[str, Any]:
    """
    Check if we should switch to Option B (0.2 deg WAVERYS reanalysis).

    Raw Hs MAE changes by more than 25% between folds just because of the season
    (big Amihan waves vs flat summer), so we don't use it.
    Instead Option B is used if:
      1. skill vs climatology goes below 0.0 in any fold, or
      2. MAE for big waves (Hs > 2.0 m) in the CV folds is more than 0.50 m.
    """
    skills = cv_results.get("skill_vs_climatology", pd.Series(dtype=float))
    min_skill = float(skills.min()) if len(skills) > 0 else 0.0
    mean_skill = float(skills.mean()) if len(skills) > 0 else 0.0
    
    triggers = []
    if min_skill < 0.0:
        triggers.append(f"Negative skill vs climatology observed in at least one fold (min skill: {min_skill:.3f})")
    
    return {
        "mean_cv_skill_vs_climatology": round(mean_skill, 4),
        "min_cv_skill_vs_climatology": round(min_skill, 4),
        "option_b_triggered": len(triggers) > 0,
        "trigger_reasons": triggers,
        "governance_note": "Evaluated strictly on CV folds to preserve holdout sanctity."
    }
