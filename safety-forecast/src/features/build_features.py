"""
Builds the training features from the interim data.
Reads: data/interim/collocated.parquet
Writes: data/processed/training_features.parquet

Usage: python src/features/build_features.py
"""

from pathlib import Path
import pandas as pd

try:
    from physics import compute_marine_physics_features
except ImportError:
    from src.features.physics import compute_marine_physics_features


def _find_project_root() -> Path:
    current = Path(__file__).resolve().parent
    for _ in range(6):
        if (current / ".venv").exists() or (current / "data").exists():
            return current
        current = current.parent
    return Path(__file__).resolve().parent.parent.parent


def build_training_features() -> pd.DataFrame:
    """
    Build the feature table from the raw weather and sea data.

    1. Time of day (hour_sin/cos) and time of year (doy_sin/cos).
    2. Wave steepness (Hs / L) and swell share of the total.
    3. 3-hour pressure change (delta_p_3h).
    4. Current and wind as u and v parts.

    Returns:
        pd.DataFrame: feature table, saved to data/processed/training_features.parquet
    """
    root = _find_project_root()
    interim_path = root / "data" / "interim" / "collocated.parquet"
    processed_dir = root / "data" / "processed"
    processed_dir.mkdir(parents=True, exist_ok=True)
    out_path = processed_dir / "training_features.parquet"

    print(f"Loading {interim_path}...")
    df_raw = pd.read_parquet(interim_path)
    print(f"  Raw collocated shape: {df_raw.shape}")

    # Only use checked rows for training
    if "is_verified" in df_raw.columns:
        n_before = len(df_raw)
        df_raw = df_raw[df_raw["is_verified"] == True].copy()
        print(f"  Filtered to {len(df_raw)} verified rows (excluded {n_before - len(df_raw)} provisional/forecast rows)")
    elif "is_provisional" in df_raw.columns or "is_forecast" in df_raw.columns:
        mask = pd.Series(True, index=df_raw.index)
        if "is_provisional" in df_raw.columns:
            mask = mask & (~df_raw["is_provisional"])
        if "is_forecast" in df_raw.columns:
            mask = mask & (~df_raw["is_forecast"])
        n_before = len(df_raw)
        df_raw = df_raw[mask].copy()
        print(f"  Filtered to {len(df_raw)} verified rows (excluded {n_before - len(df_raw)} provisional/forecast rows)")

    # Physics and time features
    df_feat = compute_marine_physics_features(df_raw)

    # Remove the first rows that are NaN because of .shift(3) in delta_p_3h
    df_clean = df_feat.dropna()
    leading_nans = len(df_feat) - len(df_clean)
    print(f"  Dropped {leading_nans} leading NaN rows from shift(3)")

    assert len(df_clean.dropna()) == len(df_clean), "Unexpected NaNs remaining in training features!"
    assert len(df_clean) == len(df_raw) - 3, f"Expected {len(df_raw) - 3} rows, got {len(df_clean)}"

    df_clean.to_parquet(out_path)
    print(f"wrote {len(df_clean)} rows, {df_clean.shape[1]} columns -> {out_path}")
    print(f"Columns ({df_clean.shape[1]}): {list(df_clean.columns)}")
    return df_clean




if __name__ == "__main__":
    build_training_features()
