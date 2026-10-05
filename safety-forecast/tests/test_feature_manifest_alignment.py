"""
Automated unit test validating 100% alignment between:
1. feature_manifest.json feature names and ordering.
2. Real snapshot-extracted features via feature_extractor.py.
3. Model router execution on real physical snapshot features.
4. Quarantine verification: zero legacy 133-feature models in active serving paths.
"""

from pathlib import Path
import json
import pytest
import numpy as np
import pandas as pd

import sys
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "src" / "ingest"))
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "src" / "serve"))

from training_eligibility import load_snapshot_dataset
from feature_extractor import extract_features_at_origin, load_feature_manifest
from model_router import ModelRouter, FEATURE_MANIFEST_PATH, get_expected_feature_count


def test_feature_manifest_exists_and_declares_52_features():
    manifest = load_feature_manifest()
    assert manifest["manifest_version"] == "1.1"
    assert len(manifest["features"]) == 52, f"Expected 52 features, got {len(manifest['features'])}"


def test_snapshot_feature_extraction_order_and_types():
    df_waves = load_snapshot_dataset("waves", training_only=False)
    df_currents = load_snapshot_dataset("currents", training_only=False)

    # Origin timestamp inside valid holdout
    origin_time = pd.Timestamp("2026-05-15 12:00:00", tz="UTC")
    horizon_h = 24

    features_series = extract_features_at_origin(df_waves, df_currents, origin_time, horizon_h)
    manifest = load_feature_manifest()
    expected_names = [f["name"] for f in manifest["features"]]

    # 1. Exact length match
    assert len(features_series) == len(expected_names)
    
    # 2. Exact name and order match
    assert list(features_series.index) == expected_names

    # 3. No NaNs in real feature vector
    nan_cols = features_series[features_series.isna()].index.tolist()
    assert len(nan_cols) == 0, f"Found NaNs in extracted features: {nan_cols}"

    # 4. Physical values sanity
    assert features_series["hs_lag_12h"] > 0.0
    assert features_series["current_speed_lag_24h"] >= 0.0
    assert -1.0 <= features_series["doy_sin"] <= 1.0


def test_model_router_with_real_snapshot_features():
    """Feeds the real extracted features into ModelRouter rather than a synthetic dummy vector."""
    df_waves = load_snapshot_dataset("waves", training_only=False)
    df_currents = load_snapshot_dataset("currents", training_only=False)

    origin_time = pd.Timestamp("2026-05-15 12:00:00", tz="UTC")
    horizon_h = 24
    features_series = extract_features_at_origin(df_waves, df_currents, origin_time, horizon_h)
    
    real_feature_array = features_series.values.astype(np.float32)

    router = ModelRouter()
    # Route tactical forecast using real features
    res = router.route_forecast("hs", horizon_h, real_feature_array, origin_time)
    assert res["value"] is not None
    assert res["confidence_tier"] in ["HIGH_CONFIDENCE", "MODERATE_CONFIDENCE", "LOW_CONFIDENCE", "LOW_CONFIDENCE_CLIMATOLOGY_BOUND"]


def test_legacy_133_feature_models_quarantined():
    """Verifies that legacy 133-feature models have been removed from active models/ and models/onnx/."""
    models_dir = Path(__file__).resolve().parents[1] / "models"
    onnx_dir = models_dir / "onnx"

    legacy_active_onnx = list(onnx_dir.glob("*forecaster*.onnx"))
    assert len(legacy_active_onnx) == 0, f"Found unarchived legacy ONNX forecasters in active dir: {legacy_active_onnx}"

    legacy_features_json = onnx_dir / "forecaster_features.json"
    assert not legacy_features_json.exists(), "Legacy forecaster_features.json must be archived!"

    # Verify they exist in archive
    archive_dir = Path(__file__).resolve().parents[1] / "archive" / "legacy_133_models"
    assert archive_dir.exists(), "Archive directory missing!"
    archived_files = list(archive_dir.glob("*"))
    assert len(archived_files) > 0, "No models found in archive!"


def test_no_era5_feature_shorter_than_120h_lag():
    """Validates that all ERA5 features in feature_manifest.json strictly observe >= 120h operational lag."""
    manifest = load_feature_manifest()
    era5_features = [f for f in manifest["features"] if f.get("source") == "era5"]
    assert len(era5_features) > 0, "No ERA5 features declared in manifest!"

    for feat in era5_features:
        lag = feat.get("lag_hours", feat.get("hours", 0))
        assert lag >= 120, f"Leakage violation: Feature {feat['name']} has lag {lag}h < 120h!"

