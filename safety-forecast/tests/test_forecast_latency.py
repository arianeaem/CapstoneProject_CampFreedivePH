"""
Latency tests for the forecaster.
Checks that a forecast for all 9 horizons takes less than 350ms (p95),
for both the ONNX models and the Python / climatology ones.

Run with:
    pytest tests/test_forecast_latency.py -v
    pytest tests/test_forecast_latency.py --benchmark-only (if pytest-benchmark is installed)
"""

import sys
from pathlib import Path
sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

import time
import logging
import pytest
import numpy as np
from fastapi.testclient import TestClient

logging.getLogger("httpx").setLevel(logging.WARNING)
logging.getLogger("autogluon").setLevel(logging.WARNING)

from src.serve.main import app, get_multi_horizon_physics, MultiHorizonForecastRequest
from src.serve.model_router import router

client = TestClient(app)
ALL_HORIZONS = [1, 6, 12, 24, 48, 72, 96, 144, 168]


@pytest.fixture(scope="module")
def sample_features():
    from src.serve.model_router import get_expected_feature_count
    feat_dim = get_expected_feature_count()
    np.random.seed(42)
    return np.random.uniform(low=0.1, high=5.0, size=(feat_dim,)).astype(np.float32)


@pytest.mark.parametrize("horizon", ALL_HORIZONS)
def test_forecast_all_horizons_response_schema_and_quantiles(horizon, sample_features):
    """
    Check that /forecast returns p10/p50/p90 forecasts for all 9 horizons.
    """
    payload = {
        "horizon_hours": horizon,
        "feature_vector": sample_features.tolist(),
    }
    response = client.post("/forecast", json=payload)
    assert response.status_code == 200, f"Failed for horizon {horizon}h: {response.text}"
    
    data = response.json()
    assert data["horizon_hours"] == horizon
    assert "physics_forecast" in data
    assert "metadata" in data
    
    pf = data["physics_forecast"]
    # Every variable must have p10, p50 and p90
    quantile_keys = [
        "significant_wave_height_m", "peak_period_s", "swell_height_m", "wind_wave_height_m",
        "wind_speed_kmh", "wind_gust_kmh", "wind_direction_deg", "sea_level_pressure_hpa",
        "current_u_ms", "current_v_ms", "current_speed_ms", "current_direction_deg"
    ]
    for key in quantile_keys:
        assert key in pf, f"Missing {key} in physics_forecast"
        q = pf[key]
        assert "p10" in q and "p50" in q and "p90" in q, f"Malformed quantile object for {key}: {q}"
        if key not in ["wind_direction_deg", "current_direction_deg", "current_u_ms", "current_v_ms"]:
            assert q["p10"] <= q["p50"] <= q["p90"] or q["p10"] <= q["p90"], f"Quantile order violation in {key}: {q}"
            
    # Values computed from the waves
    assert "wave_steepness" in pf
    assert "swell_ratio" in pf
    assert 0.0 <= pf["swell_ratio"] <= 1.0


@pytest.mark.parametrize("horizon", ALL_HORIZONS)
def test_forecast_latency_budget(horizon, sample_features):
    """
    Run 50 times per horizon and measure p50, p95 and max latency.
    p95 must be under 350ms.
    """
    latencies = []
    req = MultiHorizonForecastRequest(
        horizon_hours=horizon,
        feature_vector=sample_features.tolist(),
    )

    # Warmup
    for _ in range(5):
        get_multi_horizon_physics(req)

    # 50 timed runs
    for _ in range(50):
        t0 = time.perf_counter()
        resp = get_multi_horizon_physics(req)
        t1 = time.perf_counter()
        assert resp.horizon_hours == horizon
        latencies.append((t1 - t0) * 1000.0)  # ms

    p50 = np.percentile(latencies, 50)
    p95 = np.percentile(latencies, 95)
    p99 = np.percentile(latencies, 99)
    max_l = np.max(latencies)

    print(f"\n[HORIZON {horizon:3d}h LATENCY] p50: {p50:6.2f}ms | p95: {p95:6.2f}ms | p99: {p99:6.2f}ms | max: {max_l:6.2f}ms")
    assert p95 < 350.0, f"SLA Violation at H={horizon}h: p95 latency {p95:.2f}ms exceeds 350ms budget!"


def test_native_and_climatology_branches_specifically(sample_features):
    """
    Test the non-ONNX models (Python WeightedEnsemble and climatology)
    to make sure they are also well under 350ms.
    """
    native_latencies = []
    # H=6 (WeightedEnsemble) and H=96/144/168 (climatology)
    test_horizons = [6, 72, 96, 144, 168]

    for h in test_horizons:
        req = MultiHorizonForecastRequest(
            horizon_hours=h,
            feature_vector=sample_features.tolist(),
        )
        # Warmup
        for _ in range(3):
            get_multi_horizon_physics(req)

        for _ in range(30):
            t0 = time.perf_counter()
            resp = get_multi_horizon_physics(req)
            t1 = time.perf_counter()
            assert resp.horizon_hours == h
            native_latencies.append((t1 - t0) * 1000.0)

    p95_native = np.percentile(native_latencies, 95)
    p50_native = np.percentile(native_latencies, 50)
    max_native = np.max(native_latencies)

    print(f"\n[NATIVE/FALLBACK BRANCHES] p50: {p50_native:6.2f}ms | p95: {p95_native:6.2f}ms | max: {max_native:6.2f}ms")
    assert p95_native < 350.0, f"Native/Fallback p95 latency {p95_native:.2f}ms exceeds 350ms budget!"


# Optional pytest-benchmark test
def test_benchmark_forecast_h24(benchmark, sample_features):
    req = MultiHorizonForecastRequest(
        horizon_hours=24,
        feature_vector=sample_features.tolist(),
    )
    result = benchmark(get_multi_horizon_physics, req)
    assert result.horizon_hours == 24
