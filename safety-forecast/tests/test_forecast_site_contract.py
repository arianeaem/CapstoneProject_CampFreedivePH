"""
Tests for POST /forecast/site.
Checks:
1. p10 <= p50 <= p90 for all variables and horizons.
2. Exactly 240 hours with no gaps for a 10-day forecast.
3. Right source per horizon:
   - hs: model for H <= 48, climatology after
   - current_speed: model for H <= 72, climatology after
   - other variables: always climatology
4. Daily values: rain_daily_mm, p_wet with Wilson CI, p_high_gust with Wilson CI (not called 'squall').
5. Old store: returns degraded: true and everything is climatology.
6. Outside our area: returns HTTP 422 with error: 'out_of_area'.
"""

import sys
import json
from pathlib import Path
import pandas as pd
import pytest
from fastapi.testclient import TestClient

PROJECT_ROOT = Path(__file__).resolve().parents[1]
if str(PROJECT_ROOT) not in sys.path:
    sys.path.insert(0, str(PROJECT_ROOT))

from src.serve.main import app
from src.serve.site_forecaster import MAX_LAG_HOURS, SiteForecaster

client = TestClient(app)

CAMP_LAT = 13.6874
CAMP_LON = 120.8931
TEST_ISSUED_AT = "2025-06-15 12:00:00+08:00"


def test_forecast_site_contract_and_monotonicity():
    """Check the full response, p10 <= p50 <= p90, and no gaps in the hours."""
    payload = {
        "latitude": CAMP_LAT,
        "longitude": CAMP_LON,
        "issued_at": TEST_ISSUED_AT,
        "days": 10
    }
    response = client.post("/forecast/site", json=payload)
    assert response.status_code == 200, f"Expected 200, got {response.status_code}: {response.text}"

    data = response.json()
    assert "site" in data
    assert "forecast_hourly" in data
    assert "forecast_daily" in data
    assert data["degraded"] is False

    hourly = data["forecast_hourly"]
    assert len(hourly) == 240, f"Expected 240 hourly steps, got {len(hourly)}"

    variables_to_check = [
        "hs", "current_speed", "wind_speed", "wind_gust",
        "slp", "tp", "swell_height", "wind_wave_height",
        "eulerian_speed", "tide_speed", "stokes_speed"
    ]

    for h_idx, step in enumerate(hourly):
        assert step["lead_hours"] == h_idx + 1

        for var in variables_to_check:
            var_data = step[var]
            p10 = var_data["p10"]
            p50 = var_data["p50"]
            p90 = var_data["p90"]

            # p10 <= p50 <= p90
            assert p10 <= p50, f"Monotonicity breach at hour {h_idx+1} for {var}: p10 ({p10}) > p50 ({p50})"
            assert p50 <= p90, f"Monotonicity breach at hour {h_idx+1} for {var}: p50 ({p50}) > p90 ({p90})"

            # Values can't be negative
            if var != "slp":
                assert p10 >= 0.0, f"Negative lower bound at hour {h_idx+1} for {var}: {p10}"


def test_source_routing_and_operational_cutoffs():
    """Check the cutoffs: hs model up to 48h, current_speed model up to 72h."""
    payload = {
        "latitude": CAMP_LAT,
        "longitude": CAMP_LON,
        "issued_at": TEST_ISSUED_AT,
        "days": 10
    }
    response = client.post("/forecast/site", json=payload)
    assert response.status_code == 200
    hourly = response.json()["forecast_hourly"]

    for step in hourly:
        H = step["lead_hours"]

        # Waves cutoff: 48h
        if H <= 48:
            assert step["hs"]["source"] == "model", f"Expected model for hs at H={H}"
        else:
            assert step["hs"]["source"] == "climatology", f"Expected climatology for hs at H={H}"

        # Current cutoff: 72h
        if H <= 72:
            assert step["current_speed"]["source"] == "model", f"Expected model for current_speed at H={H}"
        else:
            assert step["current_speed"]["source"] == "climatology", f"Expected climatology for current_speed at H={H}"

        # Everything else must be climatology
        for var in ["wind_speed", "wind_gust", "slp", "tp", "swell_height"]:
            assert step[var]["source"] == "climatology", f"Expected climatology for {var} at H={H}"


def test_daily_aggregates_and_high_gust_naming():
    """Check the daily values: rain_mm, p_wet with Wilson CI, and p_high_gust (not squall)."""
    payload = {
        "latitude": CAMP_LAT,
        "longitude": CAMP_LON,
        "issued_at": TEST_ISSUED_AT,
        "days": 10
    }
    response = client.post("/forecast/site", json=payload)
    assert response.status_code == 200
    daily = response.json()["forecast_daily"]

    assert len(daily) >= 10

    for day in daily:
        # Required fields
        assert "date" in day
        assert "rain_daily_mm_p50" in day
        assert "rain_daily_mm_p90" in day
        assert "p_wet" in day
        assert "p_wet_ci" in day
        assert "p_high_gust" in day
        assert "p_high_gust_ci" in day

        # The word 'squall' must not be used
        assert "p_squall" not in day
        assert "squall_probability" not in day

        # Wilson CI bounds
        pw, (w_lo, w_hi) = day["p_wet"], day["p_wet_ci"]
        assert 0.0 <= w_lo <= pw <= w_hi <= 1.0, f"Invalid Wilson CI for p_wet: {w_lo} <= {pw} <= {w_hi}"

        pg, (g_lo, g_hi) = day["p_high_gust"], day["p_high_gust_ci"]
        assert 0.0 <= g_lo <= pg <= g_hi <= 1.0, f"Invalid Wilson CI for p_high_gust: {g_lo} <= {pg} <= {g_hi}"


def test_stale_store_fallback():
    """An old store should make everything climatology with degraded=True."""
    payload = {
        "latitude": CAMP_LAT,
        "longitude": CAMP_LON,
        "issued_at": TEST_ISSUED_AT,
        "days": 3,
        "force_stale": True
    }
    response = client.post("/forecast/site", json=payload)
    assert response.status_code == 200
    data = response.json()

    assert data["degraded"] is True
    assert data["degraded_reason"] is not None

    for step in data["forecast_hourly"]:
        assert step["hs"]["source"] == "climatology"
        assert step["current_speed"]["source"] == "climatology"


def test_out_of_area_error():
    """Coordinates outside our area should return HTTP 422 with out_of_area."""
    # Manila (~100 km north of the site)
    payload = {
        "latitude": 14.5995,
        "longitude": 120.9842,
        "issued_at": TEST_ISSUED_AT,
        "days": 3
    }
    response = client.post("/forecast/site", json=payload)
    assert response.status_code == 422, f"Expected 422, got {response.status_code}"
    err_detail = response.json()["detail"]
    assert err_detail.get("error") == "out_of_area"


def test_stale_hs_does_not_mark_fresh_current_as_stale():
    """Freshness is per variable, so current is still ok when hs is too old."""
    from unittest.mock import patch
    import tempfile

    with tempfile.TemporaryDirectory() as tmp:
        meta_path = Path(tmp) / "store_meta.json"
        meta_path.write_text(json.dumps({
            "last_observation_at": {
                "hs": "2026-10-04T00:00:00+08:00",
                "current_speed": "2026-10-05T00:00:00+08:00",
            }
        }), encoding="utf-8")

        forecaster = object.__new__(SiteForecaster)
        forecaster.feature_extractor = object()
        with patch("src.serve.site_forecaster.STORE_DIR", Path(tmp)):
            stale, reason, lags = forecaster.check_store_status(
                pd.Timestamp("2026-10-05T12:00:00+08:00")
            )

        assert stale is True
        assert "hs lag" in reason
        assert lags["hs"] == 36.0
        assert lags["current_speed"] == 12.0
        assert MAX_LAG_HOURS["hs"] == 24.0
        assert MAX_LAG_HOURS["current_speed"] == 48.0


if __name__ == "__main__":
    pytest.main(["-v", __file__])
