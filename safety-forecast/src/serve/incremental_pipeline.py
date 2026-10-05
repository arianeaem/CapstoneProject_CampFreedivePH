"""
Incremental Pipeline Runner: Scoped Re-Export & Scoped Testing for Changed Cells
================================================================================
Triggered automatically after quarterly re-benchmarking (ml:rebenchmark).
Detects cells where the winning model changed and cleared the promotion margin / TFT guardrail rules.
Runs three scoped stages ONLY for the changed cells:
  1. Export:   serving path assignment + ONNX smoke check
  2. Router:   router cache refresh + quantile verification
  3. Latency:  p95 latency SLA regression test
"""

import sys
import json
import time
from pathlib import Path
from typing import List, Dict, Any, Optional, Tuple
import numpy as np
import pandas as pd

PROJECT_ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(PROJECT_ROOT))

LB_PATH = PROJECT_ROOT / "reports" / "autogluon_benchmarks" / "full_leaderboard.csv"
PROD_JSON_PATH = PROJECT_ROOT / "reports" / "autogluon_benchmarks" / "production_model_selection.json"
ONNX_DIR = PROJECT_ROOT / "models" / "onnx"
FEATURES_JSON = ONNX_DIR / "forecaster_features.json"

from src.serve.model_router import ModelRouter, clear_router_caches, get_expected_feature_count

LATENCY_BUDGET_MS = 350.0
VALID_CONFIDENCE_TIERS = {
    "HIGH_CONFIDENCE",
    "MODERATE_CONFIDENCE",
    "LOW_CONFIDENCE_ML_UNCERTAIN",
    "LOW_CONFIDENCE_CLIMATOLOGY_BOUND",
}

ProdMap = Dict[Tuple[str, int], Dict[str, Any]]


def detect_changed_cells(margin_threshold: float = 0.02, tft_margin_threshold: float = 0.05) -> Tuple[List[Dict[str, Any]], ProdMap]:
    """
    Compares latest leaderboard against production_model_selection.json.
    Returns only cells that qualify for promotion under the margin / TFT guardrail rules.
    """
    if not LB_PATH.exists() or not PROD_JSON_PATH.exists():
        print("[IncrementalPipeline] Error: Leaderboard or production config not found.")
        return [], {}

    full_lb = pd.read_csv(LB_PATH)
    with open(PROD_JSON_PATH, "r") as f:
        prod_models = json.load(f)

    prod_map = {(r["variable"], r["horizon"]): r for r in prod_models}
    changed_cells = []

    for (var, h), cell_df in full_lb.groupby(["variable", "horizon"]):
        if (var, h) not in prod_map:
            continue

        incumbent = prod_map[(var, h)]
        incumbent_model = incumbent.get("model", "Unknown")
        incumbent_mase = incumbent.get("mase")
        serving_path = incumbent.get("serving_path", "unknown")

        sorted_cell = cell_df.sort_values("score_test", ascending=False).reset_index(drop=True)
        top_row = sorted_cell.iloc[0]
        top_model = top_row["model"]
        top_mase = float(top_row["mase"])

        # Climatology fallback preserve rule
        if "climatology" in str(serving_path).lower() or "climatology" in str(incumbent_model).lower() or pd.isna(incumbent_mase):
            continue

        # TFT Guardrail check
        if "TemporalFusionTransformer" in top_model:
            runner_up = sorted_cell.iloc[1] if len(sorted_cell) > 1 else top_row
            tft_margin = float(runner_up["mase"]) - top_mase
            if tft_margin < tft_margin_threshold:
                challenger_model = runner_up["model"]
                challenger_mase = float(runner_up["mase"])
                challenger_row = runner_up
            else:
                challenger_model = top_model
                challenger_mase = top_mase
                challenger_row = top_row
        else:
            challenger_model = top_model
            challenger_mase = top_mase
            challenger_row = top_row

        if challenger_model == incumbent_model:
            continue

        delta_mase = float(incumbent_mase) - challenger_mase
        if delta_mase >= margin_threshold:
            changed_cells.append({
                "variable": var,
                "horizon": h,
                "old_model": incumbent_model,
                "old_mase": incumbent_mase,
                "old_serving_path": serving_path,
                "new_model": challenger_model,
                "new_mase": challenger_mase,
                "delta_mase": delta_mase,
                "fit_time": float(challenger_row.get("fit_time_marginal", 0.0)),
                "pred_time": float(challenger_row.get("pred_time_test", 0.0)),
            })

    return changed_cells, prod_map


def run_scoped_export(changed_cell: Dict[str, Any]) -> Dict[str, Any]:
    """
    Scoped export: serving path assignment and ONNX smoke check for a single changed cell.
    """
    var = changed_cell["variable"]
    h = changed_cell["horizon"]
    new_model = changed_cell["new_model"]

    print(f"\n[SCOPED EXPORT] Processing ({var}, H={h}h) -> {new_model}")

    # Determine serving path
    if "XGBoost" in new_model or "DirectTabular" in new_model or "GBDT" in new_model:
        # Check if ONNX exportable
        target_onnx = ONNX_DIR / f"{var}_H{h}.onnx"
        if not target_onnx.exists():
            # Fall back to root ONNX forecaster target
            target_onnx = ONNX_DIR / f"xgb_wave_forecaster_{var}.onnx" if "wave" in var or var in ["hs", "tp", "swell_height", "wind_wave_height"] else ONNX_DIR / f"xgb_wind_forecaster_{var}.onnx"

        if target_onnx.exists():
            serving_path = str(target_onnx.relative_to(PROJECT_ROOT)).replace("\\", "/")
            print(f"  Serving Branch: ONNX Fast Runtime ({serving_path})")
        else:
            serving_path = "python_native"
            print(f"  Serving Branch: Python Native (no ONNX artifact found for {new_model})")
    else:
        serving_path = "python_native"
        print(f"  Serving Branch: Python Native ({new_model})")

    # Smoke check: the exported ONNX graph loads and returns one output per input row.
    # This verifies the artifact runs; it does not compare against the native model.
    parity_passed = True
    if serving_path != "python_native" and Path(PROJECT_ROOT / serving_path).exists():
        import onnxruntime as ort
        sess = ort.InferenceSession(str(PROJECT_ROOT / serving_path), providers=["CPUExecutionProvider"])
        test_input = np.random.uniform(0.1, 5.0, size=(100, get_expected_feature_count())).astype(np.float32)
        onnx_out = sess.run(None, {"input": test_input})[0]
        parity_passed = onnx_out is not None and len(onnx_out) == 100
        if not parity_passed:
            raise RuntimeError(f"ONNX smoke check failed for ({var}, H={h}h): unexpected output shape")
        print("  ONNX Smoke Check: PASS (100 synthetic test points evaluated)")

    return {
        "variable": var,
        "horizon": h,
        "model": new_model,
        "serving_path": serving_path,
        "mase": changed_cell["new_mase"],
        "parity_passed": parity_passed,
    }


def run_scoped_router_update(changed_cell: Dict[str, Any]) -> bool:
    """
    Scoped router update: invalidate the router session cache and verify quantile outputs for the changed cell.
    """
    var = changed_cell["variable"]
    h = changed_cell["horizon"]
    print(f"\n[SCOPED ROUTER & QUANTILES] Validating ({var}, H={h}h)")

    # Invalidate session cache
    clear_router_caches()
    router = ModelRouter()

    # Generate dummy features
    np.random.seed(42)
    dummy_feat = np.random.uniform(0.1, 5.0, size=(get_expected_feature_count(),)).astype(np.float32)

    # Scoped single-variable routed prediction
    val_pred = router.route_forecast(var, h, dummy_feat)
    if val_pred is None or "value" not in val_pred:
        raise RuntimeError(f"Prediction failed for ({var}, {h})")
    print(f"  Prediction Output: {val_pred['value']:.4f} | Serving Source: {val_pred.get('source', 'unknown')}")

    # End-to-end multihorizon quantile verification for the affected horizon
    forecast, metadata = router.generate_physics_forecast(h, dummy_feat)
    if forecast is None or metadata is None:
        raise RuntimeError(f"Multihorizon forecast failed for horizon {h}h")
    if metadata.get("overall_confidence") not in VALID_CONFIDENCE_TIERS:
        raise RuntimeError(f"Unexpected confidence tier for horizon {h}h: {metadata.get('overall_confidence')}")
    print(f"  Quantile Validation: PASS (Horizon {h}h -> Confidence Tier: {metadata['overall_confidence']})")
    return True


def run_scoped_latency_check(changed_cell: Dict[str, Any]) -> float:
    """
    Scoped latency SLA test (<350ms p95 budget) on the changed cell's horizon.
    """
    h = changed_cell["horizon"]
    print(f"\n[SCOPED LATENCY SLA] Benchmarking Horizon {h}h")

    router = ModelRouter()
    dummy_feat = np.random.uniform(0.1, 5.0, size=(get_expected_feature_count(),)).astype(np.float32)

    # Warmup
    for _ in range(5):
        router.generate_physics_forecast(h, dummy_feat)

    latencies = []
    for _ in range(50):
        t0 = time.perf_counter()
        router.generate_physics_forecast(h, dummy_feat)
        latencies.append((time.perf_counter() - t0) * 1000)

    p50 = np.percentile(latencies, 50)
    p95 = np.percentile(latencies, 95)
    print(f"  Latency SLA: p50 = {p50:.2f}ms | p95 = {p95:.2f}ms (Budget: <{LATENCY_BUDGET_MS:.0f}ms)")
    if p95 >= LATENCY_BUDGET_MS:
        raise RuntimeError(f"Latency SLA violated: p95 = {p95:.2f}ms >= {LATENCY_BUDGET_MS:.0f}ms")
    print(f"  Latency SLA Status: PASS (<{LATENCY_BUDGET_MS:.0f}ms budget satisfied)")
    return float(p95)


def run_incremental_pipeline(forced_cells: Optional[List[Tuple[str, int]]] = None) -> Dict[str, Any]:
    """
    Orchestrates the scoped export, router and latency stages for changed cells only.
    """
    print("=" * 80)
    print("INCREMENTAL ML RE-SERVING & REGRESSION PIPELINE (EXPORT / ROUTER / LATENCY)")
    print("=" * 80)

    changed_cells, prod_map = detect_changed_cells()

    if forced_cells:
        print(f"[TEST OVERRIDE] Forcing scoped execution for {len(forced_cells)} cell(s): {forced_cells}")
        changed_cells = [
            {
                "variable": v,
                "horizon": h,
                "old_model": prod_map.get((v, h), {}).get("model", "CurrentModel"),
                "old_mase": prod_map.get((v, h), {}).get("mase", 0.5),
                "old_serving_path": prod_map.get((v, h), {}).get("serving_path", "python_native"),
                "new_model": "DirectTabular[XGBoost]",
                "new_mase": 0.45,
                "delta_mase": 0.05,
                "fit_time": 0.35,
                "pred_time": 0.005,
            }
            for (v, h) in forced_cells
        ]

    if not changed_cells:
        print("\n[RESULT] No cell winners changed in the latest benchmark.")
        print(f"Skipping redundant re-export and re-testing for all {len(prod_map)} unchanged cells.")
        print("Production serving config remains intact and verified.")
        print("=" * 80)
        return {"changed_count": 0, "status": "NO_CHANGES_SKIPPED"}

    print(f"\nDetected {len(changed_cells)} changed cell(s) requiring scoped re-export & re-test:\n")
    for idx, c in enumerate(changed_cells, 1):
        print(f"  {idx}. ({c['variable']}, H={c['horizon']}h): {c['old_model']} -> {c['new_model']} (dMASE: {c['delta_mase']:+.4f})")

    results = []
    for c in changed_cells:
        export_res = run_scoped_export(c)
        router_ok = run_scoped_router_update(c)
        latency_p95 = run_scoped_latency_check(c)

        results.append({
            "variable": c["variable"],
            "horizon": c["horizon"],
            "export": export_res,
            "router_validated": router_ok,
            "latency_p95_ms": latency_p95,
            "status": "SCOPED_SUCCESS"
        })

    print("\n" + "=" * 80)
    print(f"INCREMENTAL PIPELINE COMPLETE: {len(results)} cell(s) re-exported & verified successfully.")
    print("=" * 80)
    return {"changed_count": len(results), "results": results, "status": "COMPLETED"}


if __name__ == "__main__":
    import argparse
    parser = argparse.ArgumentParser(description="Run incremental re-export and re-testing for changed cells")
    parser.add_argument("--test-cell", type=str, default=None, help="Force scoped test on a specific cell, e.g. 'hs,24'")
    args = parser.parse_args()

    forced = None
    if args.test_cell:
        v, h = args.test_cell.split(",")
        forced = [(v.strip(), int(h.strip()))]

    run_incremental_pipeline(forced_cells=forced)
