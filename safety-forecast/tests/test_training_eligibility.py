"""
Tests for training eligibility, the manifest and the data delays.

Checks:
1. Manifest: coordinates, distances and read-only files in snapshot 2026-10-04_rev2.
2. In the parquet, is_provisional marks exactly the last L hours
   (12h waves, 24h currents).
3. Live cutoff (issue_time_utc - operational_lag) marks ~2 rows for currents
   and 0 rows for waves when issue_time is Oct 3 22:00 UTC.
4. No interpolated wave rows in training.
5. All times have a timezone.
"""

import json
from pathlib import Path
import pytest
import pandas as pd

import sys
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "src" / "ingest"))

from training_eligibility import (
    load_snapshot_dataset,
    filter_training_eligible,
    evaluate_provisional_status,
    get_source_cutoff,
    DEFAULT_SNAPSHOT_DIR
)
from config import OPERATIONAL_LAGS, TARGET_TIMEZONE


def test_manifest_and_snapshot_rev2_integrity():
    manifest_path = DEFAULT_SNAPSHOT_DIR / "manifest.json"
    assert manifest_path.exists(), "Snapshot manifest.json missing!"

    with open(manifest_path, "r", encoding="utf-8") as f:
        manifest = json.load(f)

    assert manifest["snapshot_id"] == "2026-10-04_rev2"
    
    # Check the cell coordinates and distances
    waves_meta = manifest["files"]["cmems_waves.parquet"]
    assert waves_meta["cell_center"]["lat"] == 13.6667
    assert waves_meta["cell_center"]["lon"] == 120.9167
    assert waves_meta["distance_from_site_km"] == 3.43

    curr_meta = manifest["files"]["cmems_currents.parquet"]
    assert curr_meta["cell_center"]["lat"] == 13.6667
    assert curr_meta["cell_center"]["lon"] == 120.8333
    assert curr_meta["distance_from_site_km"] == 6.86

    # Check the files are read-only
    assert manifest["governance_rules"]["read_only"] is True


def test_static_archive_quarantine_counts():
    """
    Check that the parquet marks the last L rows as provisional.
    Waves: last 12 rows (lag = 12h)
    Currents: last 24 rows (lag = 24h)
    """
    df_waves = load_snapshot_dataset("waves", training_only=False)
    assert df_waves["is_provisional"].sum() == 12
    assert (df_waves["is_provisional"].iloc[-12:] == True).all()
    assert (df_waves["is_provisional"].iloc[:-12] == False).all()

    df_curr = load_snapshot_dataset("currents", training_only=False)
    assert df_curr["is_provisional"].sum() == 24
    assert (df_curr["is_provisional"].iloc[-24:] == True).all()
    assert (df_curr["is_provisional"].iloc[:-24] == False).all()


def test_dynamic_operational_serving_cutoff():
    """
    Test the live cutoff: cutoff = issue_time_utc - lag.
    Downloaded at ~22:00 UTC on Oct 3, 2026, data ends at 00:00 UTC on Oct 3, 2026:
    - currents (lag 24h): cutoff is 2026-10-02 22:00 UTC -> 2 rows (23:00 and 00:00) are provisional
    - waves (lag 12h): cutoff is 2026-10-03 10:00 UTC -> 0 rows after the cutoff
    """
    issue_time = pd.Timestamp("2026-10-03 22:00:00", tz="UTC")

    # Currents
    df_curr = load_snapshot_dataset("currents", training_only=False)
    dynamic_curr_prov = evaluate_provisional_status(df_curr, "currents", issue_time_utc=issue_time)
    assert dynamic_curr_prov.sum() == 2, f"Expected 2 dynamic provisional rows for currents, got {dynamic_curr_prov.sum()}"
    assert dynamic_curr_prov.index[-1].tz_convert("UTC") > (issue_time - OPERATIONAL_LAGS["currents"])
    assert dynamic_curr_prov.index[-2].tz_convert("UTC") > (issue_time - OPERATIONAL_LAGS["currents"])
    assert dynamic_curr_prov.index[-3].tz_convert("UTC") <= (issue_time - OPERATIONAL_LAGS["currents"])

    # Waves
    df_waves = load_snapshot_dataset("waves", training_only=False)
    dynamic_waves_prov = evaluate_provisional_status(df_waves, "waves", issue_time_utc=issue_time)
    assert dynamic_waves_prov.sum() == 0, f"Expected 0 dynamic provisional rows for waves, got {dynamic_waves_prov.sum()}"


def test_training_eligible_filtering_purity():
    """
    Check that filter_training_eligible removes:
    - interpolated wave rows
    - provisional rows (static and live)
    """
    df_waves_train = load_snapshot_dataset("waves", training_only=True)
    assert (df_waves_train["wave_is_interpolated"] == False).all()
    assert (df_waves_train["is_provisional"] == False).all()
    assert df_waves_train.index.tz is not None

    df_curr_train = load_snapshot_dataset("currents", training_only=True)
    assert (df_curr_train["is_provisional"] == False).all()
    assert df_curr_train.index.tz is not None


def test_synthetic_future_injection():
    issue_time = pd.Timestamp("2026-10-04 00:00:00", tz="UTC")
    cutoff = get_source_cutoff("waves", issue_time)

    idx = pd.date_range("2026-10-03 10:00:00", "2026-10-03 18:00:00", freq="1h", tz="UTC")
    dummy = pd.DataFrame({
        "hs": [1.0] * len(idx),
        "wave_is_interpolated": [False] * len(idx),
    }, index=idx)

    filtered = filter_training_eligible(dummy, "waves", issue_time_utc=issue_time)
    assert (filtered.index <= cutoff).all()
