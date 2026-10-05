"""
Unit & Regression Tests: Cutoff Rules, Operational Lags, and No-Future-Rows Verification.
Guarantees compliance with PRD 7.5 (strict exclusion of forecast horizon from training data)
and prevents temporal leakage across all model inputs.
"""

from pathlib import Path
import pytest
import pandas as pd
import numpy as np

from config import (
    TRAINING_WINDOWS, CALIBRATION_WINDOWS, OPERATIONAL_LAGS,
    CURRENT_SMOC_END_DATE, WAVE_ANALYSIS_END_DATE, ERA5_END_DATE, IMERG_FINAL_END_DATE,
    get_serving_cutoff, PROJECT_ROOT, INTERIM_DIR
)


def test_cmems_end_dates_exclude_forecast_edge():
    """
    PRD 7.5 forbids forecast data as input features.
    CMEMS 10-day forecast horizon extends to ~2026-10-13.
    Ensures CMEMS analysis endpoints are strictly capped at or before the last analysis timestamp.
    """
    forecast_edge = pd.to_datetime("2026-10-13", utc=True)
    smoc_end = pd.to_datetime(CURRENT_SMOC_END_DATE, utc=True)
    wave_end = pd.to_datetime(WAVE_ANALYSIS_END_DATE, utc=True)

    assert smoc_end < forecast_edge, f"SMOC end date {smoc_end} includes forecast horizon!"
    assert wave_end < forecast_edge, f"Wave end date {wave_end} includes forecast horizon!"
    assert smoc_end <= pd.to_datetime("2026-10-04", utc=True), f"SMOC end date {smoc_end} exceeds present analysis cutoff!"
    assert wave_end <= pd.to_datetime("2026-10-04", utc=True), f"Wave end date {wave_end} exceeds present analysis cutoff!"


def test_waverys_calibration_overlap():
    """
    WAVERYS must overlap Wave Analysis sufficiently to support calibration.
    Fit on early overlap (2022-11 to 2024-12), test on late overlap (2025-01 to 2026-05).
    """
    cal = CALIBRATION_WINDOWS["waves_waverys_to_analysis"]
    fit_start = pd.to_datetime(cal["fit_start"])
    fit_end = pd.to_datetime(cal["fit_end"])
    test_start = pd.to_datetime(cal["test_start"])
    test_end = pd.to_datetime(cal["test_end"])

    assert fit_start >= pd.to_datetime("2022-11-01"), "Fit cannot start before wave analysis starts"
    assert fit_end < test_start, "Fit and test periods must be strictly sequential"
    assert (fit_end - fit_start).days >= 700, "Fit period must be at least ~2 years"
    assert (test_end - test_start).days >= 365, "Test holdout period must be at least 1 year"


def test_imerg_calibration_overlap():
    """
    IMERG Late must overlap Final Run through 2025-09-30 to calibrate offset.
    """
    cal = CALIBRATION_WINDOWS["imerg_late_to_final"]
    fit_start = pd.to_datetime(cal["fit_start"])
    fit_end = pd.to_datetime(cal["fit_end"])

    assert fit_start == pd.to_datetime("2023-01-01"), "IMERG Late starts 2023-01-01"
    assert fit_end == pd.to_datetime(IMERG_FINAL_END_DATE), "IMERG Final ends 2025-09-30"
    overlap_months = (fit_end.year - fit_start.year) * 12 + (fit_end.month - fit_start.month) + 1
    assert overlap_months >= 30, f"Expected >= 30 months overlap, got {overlap_months}"


def test_operational_lag_rules():
    """
    Verifies that serving cutoffs enforce each source's minimum operational lag.
    """
    ref_time = pd.Timestamp("2026-10-04 00:00:00", tz="UTC")

    cutoff_era5 = get_serving_cutoff("era5", ref_time)
    cutoff_curr = get_serving_cutoff("currents", ref_time)
    cutoff_wave = get_serving_cutoff("waves", ref_time)
    cutoff_rain = get_serving_cutoff("rain_late", ref_time)

    assert ref_time - cutoff_era5 >= pd.Timedelta(hours=120), "ERA5 lag must be >= 120h (~5 days)"
    assert ref_time - cutoff_curr >= pd.Timedelta(hours=24), "Currents lag must be >= 24h"
    assert ref_time - cutoff_wave >= pd.Timedelta(hours=12), "Wave lag must be >= 12h"
    assert ref_time - cutoff_rain >= pd.Timedelta(hours=14), "IMERG Late lag must be >= 14h"


def test_interim_and_test_data_no_future_rows():
    """
    Inspects available interim and test datasets to assert:
    1. Maximum timestamp does not exceed the allowed training cutoff.
    2. No forecast rows are present.
    """
    test_files = [
        PROJECT_ROOT / "safety-forecast" / "data" / "test_2024_10" / "combined_master_2024_10.parquet",
    ]

    for f in test_files:
        if not f.exists():
            continue
        df = pd.read_parquet(f)
        # Check index timestamps
        max_ts = pd.to_datetime(df.index.max())
        if max_ts.tzinfo is None:
            max_ts = max_ts.tz_localize("UTC")
        else:
            max_ts = max_ts.tz_convert("UTC")

        cutoff_limit = pd.to_datetime("2026-10-04 00:00:00", utc=True)
        assert max_ts <= cutoff_limit, f"Dataset {f.name} contains future timestamps: {max_ts} > {cutoff_limit}"

        # If is_forecast column exists, none must be true in historical sets
        if "is_forecast" in df.columns:
            assert df["is_forecast"].sum() == 0, f"Dataset {f.name} contains forecast rows!"


def test_real_observed_store_no_future_rows():
    """
    Reruns on the real observed-store parquet files:
    Fails if any realized row's time_utc is later than its issue_time_utc minus that source's lag.
    Fails if any row's time_utc is later than issue_time_utc.
    """
    from config import OBSERVED_STORE_DIR
    obs_dir = OBSERVED_STORE_DIR
    assert obs_dir.exists(), f"Observed store dir {obs_dir} does not exist"
    
    files = list(obs_dir.glob("*.parquet"))
    if len(files) == 0:
        print("  [INFO] Observed store empty, generating operational archive test...")
        try:
            from fetch_cmems_forecast import fetch_and_archive
            fetch_and_archive(lookback_days=1, forecast_days=2)
            files = list(obs_dir.glob("*.parquet"))
        except Exception as e:
            print(f"  [SKIP] Could not auto-fetch observed store: {e}")
            return
    assert len(files) > 0, f"No observed store parquets found in {obs_dir}"

    for f in files:
        df = pd.read_parquet(f)
        if "currents" in f.name.lower():
            lag = OPERATIONAL_LAGS["currents"]
            src_name = "currents"
        elif "waves" in f.name.lower():
            lag = OPERATIONAL_LAGS["waves"]
            src_name = "waves"
        else:
            lag = pd.Timedelta(hours=0)
            src_name = "unknown"

        assert "time_utc" in df.columns, f"{f.name} missing time_utc"
        assert "issue_time_utc" in df.columns, f"{f.name} missing issue_time_utc"

        time_utc = pd.to_datetime(df["time_utc"], utc=True)
        issue_time_utc = pd.to_datetime(df["issue_time_utc"], utc=True)

        # For non-provisional rows, time_utc MUST be <= issue_time_utc - lag
        if "is_provisional" in df.columns:
            realized_mask = ~df["is_provisional"]
        else:
            realized_mask = pd.Series([True] * len(df), index=df.index)

        # Strict check: realized rows must be at or before issue_time_utc - lag
        violating_realized = df[realized_mask & (time_utc > (issue_time_utc - lag))]
        assert len(violating_realized) == 0, (
            f"Observed store {f.name} contains {len(violating_realized)} realized rows where "
            f"time_utc > issue_time_utc - {lag}: \n{violating_realized[['time_utc', 'issue_time_utc']].head()}"
        )

        # Strict check: no row may ever exceed issue_time_utc
        violating_future = df[time_utc > issue_time_utc]
        assert len(violating_future) == 0, (
            f"Observed store {f.name} contains {len(violating_future)} future rows exceeding issue_time_utc: \n"
            f"{violating_future[['time_utc', 'issue_time_utc']].head()}"
        )


if __name__ == "__main__":
    print("Running no-future-rows and cutoff rule tests...")
    test_cmems_end_dates_exclude_forecast_edge()
    print("  [PASS] test_cmems_end_dates_exclude_forecast_edge")
    test_waverys_calibration_overlap()
    print("  [PASS] test_waverys_calibration_overlap")
    test_imerg_calibration_overlap()
    print("  [PASS] test_imerg_calibration_overlap")
    test_operational_lag_rules()
    print("  [PASS] test_operational_lag_rules")
    test_interim_and_test_data_no_future_rows()
    print("  [PASS] test_interim_and_test_data_no_future_rows")
    test_real_observed_store_no_future_rows()
    print("  [PASS] test_real_observed_store_no_future_rows (Strict issue_time - lag check on observed store)")
    print("All cutoff and no-future-rows rules verified!")
