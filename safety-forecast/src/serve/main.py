"""
FastAPI service for the ML models. Loads the 12 ONNX models (wave/wind/current
models + safety classifier) and the hard limits. Laravel calls this from
WeatherForecastService.php and WeatherSafetyService.php.

Note about the input: the request does NOT have wave data (hs, tp, swell_height,
wind_wave_height). The models predict the waves FROM current, wind, pressure, rain
and time. Wave data is only ever an output, never an input. So Laravel only sends
what it already has (current, wind, pressure, rain) and this service gives back the waves.

Run: uvicorn src.serve.main:app --host 127.0.0.1 --port 8001
"""

import sys
from contextlib import asynccontextmanager
from pathlib import Path
from typing import List, Optional

import numpy as np
import pandas as pd
import onnxruntime as ort
from fastapi import FastAPI, HTTPException
from pydantic import BaseModel, Field

sys.path.insert(0, str(Path(__file__).resolve().parents[2]))

PROJECT_ROOT = Path(__file__).resolve().parents[2]
ONNX_DIR = PROJECT_ROOT / "models" / "onnx"

from datetime import datetime, timezone
from src.config.targets import WAVE_TARGETS, WIND_REGRESSOR_TARGETS
from src.serve.safety_thresholds import apply_safety_thresholds, evaluate_operational_safety, TIER_NAMES
from src.serve.currents_cache import get_live_currents_forecast
from src.serve.model_router import QuantileValue, PhysicsForecast, router as multihorizon_router
from src.features.lagged_features import build_lagged_features

# ---------------------------------------------------------------------------
# Load the 12 ONNX models once at startup, not on every request, so it stays fast.
#
# Column order comes from the JSON files that export_onnx.py saves.
# We don't rebuild it from the request, because ONNX only looks at column
# positions. If the order is wrong the predictions are wrong and there is no error.
# ---------------------------------------------------------------------------
SESSIONS = {}
FEATURE_ORDER = {}


def load_models():
    model_names = (
        [f"xgb_wave_regressor_{t}" for t in WAVE_TARGETS]
        + [f"xgb_wind_regressor_{t}" for t in WIND_REGRESSOR_TARGETS]
        + ["xgb_wind_regressor_wind_dir_sin", "xgb_wind_regressor_wind_dir_cos"]
        + ["xgb_current_regressor_current_u", "xgb_current_regressor_current_v"]
        + ["xgb_safety_classifier"]
    )
    missing = [n for n in model_names if not (ONNX_DIR / f"{n}.onnx").exists()]
    if missing:
        raise RuntimeError(f"Missing ONNX models: {missing} — run src/serve/export_onnx.py first")

    for name in model_names:
        SESSIONS[name] = ort.InferenceSession(str(ONNX_DIR / f"{name}.onnx"))

    import json
    for key, filename in [("wave", "wave_regressor_features.json"),
                           ("wind", "wind_regressor_features.json"),
                           ("current", "current_regressor_features.json"),
                           ("classifier", "classifier_features.json")]:
        manifest_path = ONNX_DIR / filename
        if not manifest_path.exists():
            raise RuntimeError(f"Missing feature manifest: {manifest_path} — re-run export_onnx.py")
        with open(manifest_path) as f:
            FEATURE_ORDER[key] = json.load(f)

    print(f"Loaded {len(SESSIONS)} ONNX models and {len(FEATURE_ORDER)} feature-order manifests.")


@asynccontextmanager
async def lifespan(app: FastAPI):
    load_models()
    yield
    SESSIONS.clear()
    FEATURE_ORDER.clear()


app = FastAPI(
    title="Camp FreedivePH Weather Safety & Booking Assessment Service",
    description="Microservice providing multi-horizon marine physics forecasting with calibrated quantiles, "
                "9-variable PHP score alignment, deterministic safety threshold overrides, and operational "
                "horizon cutoff assessments for Laravel booking management.",
    version="2.0.0",
    lifespan=lifespan,
)


def run_onnx(session_name: str, X: np.ndarray) -> np.ndarray:
    session = SESSIONS[session_name]
    result = session.run(None, {"input": X.astype(np.float32)})[0]
    return result.flatten()


def run_classifier_onnx(X: np.ndarray, n_classes: int = 5) -> np.ndarray:
    """The classifier can return [labels, probabilities] or [probabilities, labels]
    depending on the onnxmltools version (export_onnx.py had the same problem).
    So we pick the output whose last dimension is n_classes."""
    session = SESSIONS["xgb_safety_classifier"]
    outputs = session.run(None, {"input": X.astype(np.float32)})
    for out in outputs:
        arr = np.array(out)
        if arr.ndim == 2 and arr.shape[1] == n_classes:
            return arr
    raise RuntimeError(
        f"Could not find a ({len(X)}, {n_classes})-shaped probability output among "
        f"the classifier's ONNX outputs — got shapes {[np.array(o).shape for o in outputs]}. "
        f"This needs a manual look before the service can be trusted in production."
    )


# ---------------------------------------------------------------------------
# Request / response schemas
# ---------------------------------------------------------------------------
class HourlyReading(BaseModel):
    timestamp: str = Field(..., description="ISO 8601, e.g. 2026-09-01T00:00:00")
    current_u: Optional[float] = None
    current_v: Optional[float] = None
    current_speed: Optional[float] = None
    current_dir: Optional[float] = None
    wind_u: Optional[float] = None
    wind_v: Optional[float] = None
    wind_speed: float
    wind_gust: float
    wind_dir: float
    slp: float
    rain_rate_mm_hr: float = 0.0


class PagasaAdvisory(BaseModel):
    tcws_signal: int = 0
    gale_warning: bool = False
    tsunami_warning: bool = False


class ForecastRequest(BaseModel):
    readings: List[HourlyReading] = Field(
        ..., min_length=1,
        description="Hourly readings for inference."
    )
    pagasa: Optional[PagasaAdvisory] = None


class HourlyPrediction(BaseModel):
    timestamp: str
    predicted_hs: float
    predicted_tp: float
    predicted_swell_height: float
    predicted_wind_wave_height: float
    predicted_wind_speed: float
    predicted_wind_gust: float
    predicted_wind_dir: float
    predicted_delta_p_3h: float
    predicted_current_u: float
    predicted_current_v: float
    predicted_current_speed: float
    predicted_current_dir: float
    ml_risk_tier: str
    final_risk_tier: str
    safety_threshold_triggered: bool = False
    hard_gate_triggered: bool = False  # old name, same as safety_threshold_triggered
    override_reasons: List[str]


class ForecastResponse(BaseModel):
    predictions: List[HourlyPrediction]
    skipped_leading_rows: int


# --- /assess-booking request and response (used by Laravel) ---
class BookingAssessmentRequest(BaseModel):
    planned_date: str = Field(..., description="Date of dive session (YYYY-MM-DD), e.g. '2026-09-15'")
    dive_start: str = Field("08:00", description="Start time of dive session (HH:MM), e.g. '08:00'")
    dive_end: str = Field("12:00", description="End time of dive session (HH:MM), e.g. '12:00'")
    boundary_weather: List[HourlyReading] = Field(
        ..., min_length=1,
        description="Hourly atmospheric readings from Open-Meteo covering the session."
    )
    pagasa: Optional[PagasaAdvisory] = None
    site_name: Optional[str] = Field("Anilao, Mabini, Batangas", description="Dive site location")


class HourlyAssessmentDetail(BaseModel):
    timestamp: str
    hour: int
    horizon_hours: int
    operational_status: str
    is_safety_verdict_active: bool
    displayed_tier: Optional[int]
    displayed_tier_name: str
    ml_raw_tier: int
    ml_raw_tier_name: str
    final_tier: int
    final_tier_name: str
    safety_threshold_triggered: bool = False
    hard_gate_triggered: bool = False  # old name, same as safety_threshold_triggered
    override_reasons: List[str]
    advisory_message: str
    current_source: str
    predicted_hs: float
    predicted_tp: float
    predicted_swell_height: float
    predicted_wind_wave_height: float
    predicted_wind_speed: float
    predicted_wind_gust: float
    predicted_wind_dir: float
    predicted_current_speed: float
    predicted_current_dir: float
    rain_rate_mm_hr: float
    slp: float
    routed_horizon_bucket: Optional[int] = None
    hs_p10: Optional[float] = None
    hs_p90: Optional[float] = None


class WorstHourSummary(BaseModel):
    timestamp: str
    hour: int
    horizon_hours: int
    final_tier: int
    final_tier_name: str
    safety_threshold_triggered: bool = False
    hard_gate_triggered: bool = False  # old name, same as safety_threshold_triggered
    override_reasons: List[str]
    primary_hazard: str
    advisory_message: str
    routed_horizon_bucket: Optional[int] = None


class BookingAssessmentResponse(BaseModel):
    planned_date: str
    dive_start: str
    dive_end: str
    session_duration_hours: int
    min_horizon_hours: int
    max_horizon_hours: int
    overall_operational_status: str
    overall_recommendation: str  # "GO", "PROVISIONAL_GO", "CAUTION_ADVANCED_ONLY", "HIGH_RISK_NO_GO", "NO_GO"
    is_authoritative_go: bool
    displayed_risk_tier: Optional[int]
    displayed_risk_name: str
    safety_threshold_triggered: bool = False
    overall_safety_threshold_triggered: bool = False
    overall_hard_gate_triggered: bool = False  # old name
    worst_hour: WorstHourSummary
    hourly_assessments: List[HourlyAssessmentDetail]
    generated_at: str
    routed_horizon_bucket: Optional[int] = Field(None, description="Closest of the 9 trained horizon buckets: 1, 6, 12, 24, 48, 72, 96, 144, 168")
    physics_forecast: Optional[PhysicsForecast] = Field(None, description="Multi-horizon physics forecast with calibrated quantiles")


class MultiHorizonForecastRequest(BaseModel):
    horizon_hours: int = Field(24, description="Forecast horizon in hours (1 to 168)")
    readings: Optional[List[HourlyReading]] = Field(
        None,
        description="Optional trailing historical observations for dynamic feature construction."
    )
    feature_vector: Optional[List[float]] = Field(
        None,
        description="Optional pre-computed 133-dimensional input feature vector."
    )


class MultiHorizonForecastResponse(BaseModel):
    horizon_hours: int
    physics_forecast: PhysicsForecast
    metadata: dict
    generated_at: str


# ---------------------------------------------------------------------------
# Features (must be the same as in training)
# ---------------------------------------------------------------------------
def engineer_features(df: pd.DataFrame) -> pd.DataFrame:
    df = df.sort_values("timestamp").reset_index(drop=True)
    ts = pd.to_datetime(df["timestamp"])

    if "delta_p_3h" not in df.columns or df["delta_p_3h"].isna().all():
        df["delta_p_3h"] = df["slp"].diff(3).bfill().fillna(0.0)

    # Make the wind u/v columns if they are missing
    if "wind_u" not in df.columns or df["wind_u"].isna().all():
        rad = np.radians(df["wind_dir"])
        df["wind_u"] = -df["wind_speed"] * np.sin(rad)
        df["wind_v"] = -df["wind_speed"] * np.cos(rad)

    df["wind_current_alignment"] = np.minimum(
        np.abs(df["wind_dir"] - df["current_dir"]) % 360,
        360 - (np.abs(df["wind_dir"] - df["current_dir"]) % 360),
    )
    hour = ts.dt.hour
    doy = ts.dt.dayofyear
    df["hour_sin"] = np.sin(2 * np.pi * hour / 24.0)
    df["hour_cos"] = np.cos(2 * np.pi * hour / 24.0)
    df["doy_sin"] = np.sin(2 * np.pi * doy / 365.25)
    df["doy_cos"] = np.cos(2 * np.pi * doy / 365.25)
    return df


@app.get("/health")
def health():
    """
    Health check.

    Returns:
        dict: status ('ok'), number of loaded ONNX models and their names.
    """
    return {
        "status": "ok",
        "service": "Camp FreedivePH Weather Safety Assessment Service",
        "models_loaded": len(SESSIONS),
        "onnx_sessions": list(SESSIONS.keys()),
    }


def _run_inference_pipeline(raw_df: pd.DataFrame, pagasa_dict: Optional[dict]):
    """
    Runs the models on the input.

    1. If the current is missing, get it from the CMEMS cache (or climatology).
    2. Build the same features as in training (sin/cos time, pressure change).
    3. Run the wave, wind and current models.
    4. Compute wave steepness and swell ratio.
    5. Run the 5-level safety classifier.

    Parameters:
        raw_df (pd.DataFrame): hourly weather readings
        pagasa_dict (dict | None): PAGASA warnings

    Returns:
        tuple[pd.DataFrame, dict, np.ndarray]:
            - valid: feature table
            - preds: predictions for hs, tp, wind_speed, current_speed, etc.
            - ml_preds: classifier classes (0-4)
    """


# TODO: cache responses in Redis for busy times (e.g. during typhoons)


@app.post("/forecast/predict", response_model=ForecastResponse)
def predict(request: ForecastRequest):
    """
    Raw forecast and safety level for each hour.

    Parameters:
        request (ForecastRequest): hourly weather readings and PAGASA warnings

    Returns:
        ForecastResponse: predicted waves, period, swell, wind, current and the hard limit results.
    """
    if len(SESSIONS) == 0:
        raise HTTPException(status_code=503, detail="Models not loaded yet")

    raw = pd.DataFrame([r.model_dump() for r in request.readings])
    pagasa_dict = request.pagasa.model_dump() if request.pagasa else None

    valid, preds, ml_preds = _run_inference_pipeline(raw, pagasa_dict)

    results = []
    for i in range(len(valid)):
        telemetry = {
            "wind_speed": float(preds["wind_speed"][i]),
            "wind_gust": float(preds["wind_gust"][i]),
            "hs": float(preds["hs"][i]),
            "swell_height": float(preds["swell_height"][i]),
            "current_speed": float(preds["current_speed"][i]),
            "rain_rate_mm_hr": float(valid.loc[i, "rain_rate_mm_hr"]),
            "slp": float(valid.loc[i, "slp"]),
        }
        threshold_result = apply_safety_thresholds(int(ml_preds[i]), telemetry, pagasa_dict)

        results.append(HourlyPrediction(
            timestamp=str(valid.loc[i, "timestamp"]),
            predicted_hs=float(preds["hs"][i]),
            predicted_tp=float(preds["tp"][i]),
            predicted_swell_height=float(preds["swell_height"][i]),
            predicted_wind_wave_height=float(preds["wind_wave_height"][i]),
            predicted_wind_speed=float(preds["wind_speed"][i]),
            predicted_wind_gust=float(preds["wind_gust"][i]),
            predicted_wind_dir=float(preds["wind_dir"][i]),
            predicted_delta_p_3h=float(valid.loc[i, "delta_p_3h"]),
            predicted_current_u=float(preds["current_u"][i]),
            predicted_current_v=float(preds["current_v"][i]),
            predicted_current_speed=float(preds["current_speed"][i]),
            predicted_current_dir=float(preds["current_dir"][i]),
            ml_risk_tier=TIER_NAMES[int(ml_preds[i])],
            final_risk_tier=threshold_result["final_tier_name"],
            safety_threshold_triggered=threshold_result["safety_threshold_triggered"],
            hard_gate_triggered=threshold_result["hard_gate_triggered"],
            override_reasons=threshold_result["override_reasons"],
        ))

    return ForecastResponse(predictions=results, skipped_leading_rows=0)


def _run_inference_pipeline(raw_df: pd.DataFrame, pagasa_dict: dict = None):
    """
    Steps:
    1. Add the CMEMS current if it's missing.
    2. Build the features.
    3. Run the 11 wave/wind/current ONNX models.
    4. Run the xgb_safety_classifier ONNX model.
    """
    # If the current is missing, get it from the CMEMS cache or climatology
    needs_currents = (
        "current_u" not in raw_df.columns
        or raw_df["current_u"].isna().any()
        or (raw_df["current_u"].fillna(0.0) == 0.0).all()
    )

    if needs_currents:
        ts_index = pd.DatetimeIndex(pd.to_datetime(raw_df["timestamp"]))
        currents_df = get_live_currents_forecast(ts_index)
        raw_df["current_u"] = currents_df["current_u"].values
        raw_df["current_v"] = currents_df["current_v"].values
        raw_df["current_speed"] = currents_df["current_speed"].values
        raw_df["current_dir"] = currents_df["current_dir"].values
        raw_df["current_source"] = currents_df["current_source"].values
    else:
        if "current_speed" not in raw_df.columns or raw_df["current_speed"].isna().any():
            raw_df["current_speed"] = np.sqrt(raw_df["current_u"]**2 + raw_df["current_v"]**2)
        if "current_dir" not in raw_df.columns or raw_df["current_dir"].isna().any():
            raw_df["current_dir"] = (np.degrees(np.arctan2(raw_df["current_v"], raw_df["current_u"]))) % 360.0
        raw_df["current_source"] = "payload_provided"

    valid = engineer_features(raw_df)

    # 1. Wave models
    wave_feats = FEATURE_ORDER["wave"]
    X_wave = valid[wave_feats].values
    preds = {}
    for target in WAVE_TARGETS:
        preds[target] = run_onnx(f"xgb_wave_regressor_{target}", X_wave)

    # 2. Features that need the waves
    g = 9.80665
    valid["wave_steepness"] = (2 * np.pi * preds["hs"]) / (g * np.maximum(preds["tp"], 0.5) ** 2)
    valid["swell_ratio"] = preds["swell_height"] / (preds["hs"] + 1e-5)

    # 3. Wind and current models
    wind_feats = FEATURE_ORDER["wind"]
    current_feats = FEATURE_ORDER["current"]
    X_wind = valid[wind_feats].values
    X_current = valid[current_feats].values

    for target in WIND_REGRESSOR_TARGETS:
        preds[target] = run_onnx(f"xgb_wind_regressor_{target}", X_wind)
    pred_sin = run_onnx("xgb_wind_regressor_wind_dir_sin", X_wind)
    pred_cos = run_onnx("xgb_wind_regressor_wind_dir_cos", X_wind)
    preds["wind_dir"] = (np.degrees(np.arctan2(pred_sin, pred_cos))) % 360.0
    preds["current_u"] = run_onnx("xgb_current_regressor_current_u", X_current)
    preds["current_v"] = run_onnx("xgb_current_regressor_current_v", X_current)
    preds["current_speed"] = np.sqrt(preds["current_u"] ** 2 + preds["current_v"] ** 2)
    preds["current_dir"] = (np.degrees(np.arctan2(preds["current_v"], preds["current_u"]))) % 360.0

    # 4. Safety classifier
    pred_by_name = {
        "pred_hs": preds["hs"], "pred_tp": preds["tp"],
        "pred_swell_height": preds["swell_height"], "pred_wind_wave_height": preds["wind_wave_height"],
        "pred_wind_speed": preds["wind_speed"], "pred_wind_gust": preds["wind_gust"],
        "pred_delta_p_3h": valid["delta_p_3h"].values, "pred_wind_dir": preds["wind_dir"],
        "pred_current_u": preds["current_u"], "pred_current_v": preds["current_v"],
        "pred_current_speed": preds["current_speed"], "pred_current_dir": preds["current_dir"],
    }
    classifier_feature_order = FEATURE_ORDER["classifier"]
    classifier_features = np.column_stack([pred_by_name[name] for name in classifier_feature_order])
    classifier_probs = run_classifier_onnx(classifier_features)
    ml_preds = np.argmax(classifier_probs, axis=1)

    return valid, preds, ml_preds


@app.post("/forecast/predict", response_model=ForecastResponse, deprecated=True)
def predict(request: ForecastRequest):
    if len(SESSIONS) == 0:
        raise HTTPException(status_code=503, detail="Models not loaded yet")

    raw = pd.DataFrame([r.model_dump() for r in request.readings])
    pagasa_dict = request.pagasa.model_dump() if request.pagasa else None

    valid, preds, ml_preds = _run_inference_pipeline(raw, pagasa_dict)

    results = []
    for i in range(len(valid)):
        telemetry = {
            "wind_speed": float(preds["wind_speed"][i]),
            "wind_gust": float(preds["wind_gust"][i]),
            "hs": float(preds["hs"][i]),
            "swell_height": float(preds["swell_height"][i]),
            "current_speed": float(preds["current_speed"][i]),
            "rain_rate_mm_hr": float(valid.loc[i, "rain_rate_mm_hr"]),
            "slp": float(valid.loc[i, "slp"]),
        }
        threshold_result = apply_safety_thresholds(int(ml_preds[i]), telemetry, pagasa_dict)

        results.append(HourlyPrediction(
            timestamp=str(valid.loc[i, "timestamp"]),
            predicted_hs=float(preds["hs"][i]),
            predicted_tp=float(preds["tp"][i]),
            predicted_swell_height=float(preds["swell_height"][i]),
            predicted_wind_wave_height=float(preds["wind_wave_height"][i]),
            predicted_wind_speed=float(preds["wind_speed"][i]),
            predicted_wind_gust=float(preds["wind_gust"][i]),
            predicted_wind_dir=float(preds["wind_dir"][i]),
            predicted_delta_p_3h=float(valid.loc[i, "delta_p_3h"]),
            predicted_current_u=float(preds["current_u"][i]),
            predicted_current_v=float(preds["current_v"][i]),
            predicted_current_speed=float(preds["current_speed"][i]),
            predicted_current_dir=float(preds["current_dir"][i]),
            ml_risk_tier=TIER_NAMES[int(ml_preds[i])],
            final_risk_tier=threshold_result["final_tier_name"],
            safety_threshold_triggered=threshold_result["safety_threshold_triggered"],
            hard_gate_triggered=threshold_result["hard_gate_triggered"],
            override_reasons=threshold_result["override_reasons"],
        ))

    return ForecastResponse(predictions=results, skipped_leading_rows=0)


# ===========================================================================
# /assess-booking: main endpoint used by Laravel
# ===========================================================================
@app.post("/assess-booking", response_model=BookingAssessmentResponse)
def assess_booking(request: BookingAssessmentRequest):
    """
    Check a planned dive session for Laravel:
    1. Add the CMEMS current (or climatology).
    2. Forecast waves, wind and current.
    3. Apply the 9-value rules (same as PHP) and the hard limits.
    4. Apply the horizon bands.
    5. Find the worst hour in the dive window and return the overall result.
    """
    if len(SESSIONS) == 0:
        raise HTTPException(status_code=503, detail="Inference models not loaded yet")

    raw = pd.DataFrame([b.model_dump() for b in request.boundary_weather])
    if len(raw) == 0:
        raise HTTPException(status_code=400, detail="boundary_weather cannot be empty")

    pagasa_dict = request.pagasa.model_dump() if request.pagasa else None
    valid, preds, ml_preds = _run_inference_pipeline(raw, pagasa_dict)

    from datetime import datetime, timezone
    now_utc = datetime.now(timezone.utc)

    # Session start and end (e.g. 08:00 to 12:00 on planned_date)
    try:
        start_hour_int = int(request.dive_start.split(":")[0])
        end_hour_int = int(request.dive_end.split(":")[0])
    except Exception:
        start_hour_int, end_hour_int = 8, 12

    all_hourly_details: List[HourlyAssessmentDetail] = []
    session_hourly_details: List[HourlyAssessmentDetail] = []

    for i in range(len(valid)):
        ts_dt = pd.to_datetime(valid.loc[i, "timestamp"])
        hour_int = ts_dt.hour

        # Hours from now until this hour
        if ts_dt.tzinfo is None:
            ts_utc = ts_dt.replace(tzinfo=timezone.utc)
        else:
            ts_utc = ts_dt.astimezone(timezone.utc)

        horizon_hours = max(1, int((ts_utc - now_utc).total_seconds() / 3600.0))
        routed_h = multihorizon_router.snap_to_closest_horizon(horizon_hours)

        telemetry = {
            "wind_speed": float(preds["wind_speed"][i]),
            "wind_gust": float(preds["wind_gust"][i]),
            "delta_p_3h": float(valid.loc[i, "delta_p_3h"]) if "delta_p_3h" in valid.columns else 0.0,
            "hs": float(preds["hs"][i]),
            "swell_height": float(preds["swell_height"][i]),
            "current_speed": float(preds["current_speed"][i]),
            "rain_rate_mm_hr": float(valid.loc[i, "rain_rate_mm_hr"]),
            "slp": float(valid.loc[i, "slp"]),
        }

        # Apply the hard limits and horizon bands
        op_result = evaluate_operational_safety(horizon_hours, int(ml_preds[i]), telemetry, pagasa_dict)

        # Wave height p10/p50/p90 (gets wider the further ahead it is)
        scale_h = np.sqrt(1.0 + max(0, horizon_hours - 1) // 24)
        hs_sigma = 0.12 * scale_h
        hs_point = float(preds["hs"][i])
        hs_p10_val = round(max(0.05, hs_point - 1.282 * hs_sigma), 2)
        hs_p90_val = round(hs_point + 1.282 * hs_sigma, 2)

        detail = HourlyAssessmentDetail(
            timestamp=str(valid.loc[i, "timestamp"]),
            hour=hour_int,
            horizon_hours=horizon_hours,
            operational_status=op_result["operational_status"],
            is_safety_verdict_active=op_result["is_safety_verdict_active"],
            displayed_tier=op_result["displayed_tier"],
            displayed_tier_name=op_result["displayed_tier_name"],
            ml_raw_tier=int(ml_preds[i]),
            ml_raw_tier_name=TIER_NAMES[int(ml_preds[i])],
            final_tier=op_result["displayed_tier"] if op_result["displayed_tier"] is not None else int(op_result["ml_raw_prediction"]),
            final_tier_name=TIER_NAMES[int(op_result["ml_raw_prediction"])],
            safety_threshold_triggered=op_result["safety_threshold_triggered"],
            hard_gate_triggered=op_result["hard_gate_triggered"],
            override_reasons=op_result["override_reasons"],
            advisory_message=op_result["advisory_message"],
            current_source=str(valid.loc[i, "current_source"]),
            predicted_hs=round(float(preds["hs"][i]), 3),
            predicted_tp=round(float(preds["tp"][i]), 2),
            predicted_swell_height=round(float(preds["swell_height"][i]), 3),
            predicted_wind_wave_height=round(float(preds["wind_wave_height"][i]), 3),
            predicted_wind_speed=round(float(preds["wind_speed"][i]), 2),
            predicted_wind_gust=round(float(preds["wind_gust"][i]), 2),
            predicted_wind_dir=round(float(preds["wind_dir"][i]), 1),
            predicted_current_speed=round(float(preds["current_speed"][i]), 3),
            predicted_current_dir=round(float(preds["current_dir"][i]), 1),
            rain_rate_mm_hr=round(float(valid.loc[i, "rain_rate_mm_hr"]), 2),
            slp=round(float(valid.loc[i, "slp"]), 2),
            routed_horizon_bucket=routed_h,
            hs_p10=hs_p10_val,
            hs_p90=hs_p90_val,
        )

        all_hourly_details.append(detail)
        # Is this hour inside the dive window?
        if start_hour_int <= hour_int <= end_hour_int:
            session_hourly_details.append(detail)

    # Use the session hours if there are any, otherwise all hours
    target_hours = session_hourly_details if len(session_hourly_details) > 0 else all_hourly_details

    # --- Find the worst hour in the session ---
    # Sort by: limit broken first, then highest tier, then wave height, then wind
    worst = max(
        target_hours,
        key=lambda h: (1 if h.safety_threshold_triggered else 0, h.final_tier, h.predicted_hs, h.predicted_wind_speed)
    )

    primary_hazard = worst.override_reasons[0] if worst.safety_threshold_triggered else f"Peak Risk: {worst.final_tier_name}"

    worst_summary = WorstHourSummary(
        timestamp=worst.timestamp,
        hour=worst.hour,
        horizon_hours=worst.horizon_hours,
        final_tier=worst.final_tier,
        final_tier_name=worst.final_tier_name,
        safety_threshold_triggered=worst.safety_threshold_triggered,
        hard_gate_triggered=worst.hard_gate_triggered,
        override_reasons=worst.override_reasons,
        primary_hazard=primary_hazard,
        advisory_message=worst.advisory_message,
        routed_horizon_bucket=worst.routed_horizon_bucket,
    )

    # --- Overall result ---
    any_threshold_breach = any(h.safety_threshold_triggered for h in target_hours)
    max_tier = max(h.final_tier for h in target_hours)
    min_horizon = min(h.horizon_hours for h in target_hours)
    max_horizon = max(h.horizon_hours for h in target_hours)

    # Most common status in the session
    if max_horizon <= 1:
        overall_op_status = "TACTICAL_CLEARANCE"
    elif max_horizon <= 24:
        overall_op_status = "PROVISIONAL_TREND_OUTLOOK"
    else:
        overall_op_status = "EXTENDED_TREND_OUTLOOK"

    # Map to our 5 safety levels
    if any_threshold_breach or max_tier == 4:
        overall_recommendation = "Critical Risk"
        operational_action = "NO_GO"
        is_authoritative_go = False
    elif max_tier == 3:
        overall_recommendation = "High Risk"
        operational_action = "HIGH_RISK_NO_GO"
        is_authoritative_go = False
    elif max_tier == 2:
        overall_recommendation = "Moderate"
        operational_action = "CAUTION_ADVANCED_ONLY"
        is_authoritative_go = False
    elif max_tier == 1:
        overall_recommendation = "Safe"
        operational_action = "PROVISIONAL_GO" if overall_op_status != "TACTICAL_CLEARANCE" else "GO"
        is_authoritative_go = (overall_op_status == "TACTICAL_CLEARANCE")
    else:
        overall_recommendation = "Very Safe"
        operational_action = "PROVISIONAL_GO" if overall_op_status != "TACTICAL_CLEARANCE" else "GO"
        is_authoritative_go = (overall_op_status == "TACTICAL_CLEARANCE")

    displayed_risk_tier = worst.displayed_tier
    displayed_risk_name = worst.displayed_tier_name

    session_routed_h = multihorizon_router.snap_to_closest_horizon(min_horizon)

    # Forecast with p10/p50/p90 using the closest horizon
    session_physics = None
    try:
        exp_dim = multihorizon_router.get_expected_feature_count()
        sample_vec = np.ones((exp_dim,), dtype=np.float32) * 1.5
        if len(raw) >= 48:
            lagged_df = build_lagged_features(raw)
            vec = lagged_df.iloc[-1].values.astype(np.float32)
            sample_vec = np.pad(vec, (0, max(0, exp_dim - len(vec))))[:exp_dim]
        session_physics, _ = multihorizon_router.generate_physics_forecast(
            horizon=session_routed_h,
            feature_vector=sample_vec,
            target_timestamp=pd.Timestamp(request.planned_date + " " + request.dive_start, tz="UTC")
        )
    except Exception:
        session_physics = None

    return BookingAssessmentResponse(
        planned_date=request.planned_date,
        dive_start=request.dive_start,
        dive_end=request.dive_end,
        session_duration_hours=len(target_hours),
        min_horizon_hours=min_horizon,
        max_horizon_hours=max_horizon,
        overall_operational_status=overall_op_status,
        overall_recommendation=overall_recommendation,
        is_authoritative_go=is_authoritative_go,
        displayed_risk_tier=displayed_risk_tier,
        displayed_risk_name=displayed_risk_name,
        safety_threshold_triggered=any_threshold_breach,
        overall_safety_threshold_triggered=any_threshold_breach,
        overall_hard_gate_triggered=any_threshold_breach,
        worst_hour=worst_summary,
        hourly_assessments=target_hours,
        generated_at=now_utc.isoformat(),
        routed_horizon_bucket=session_routed_h,
        physics_forecast=session_physics,
    )


@app.post("/forecast", response_model=MultiHorizonForecastResponse)
@app.post("/forecast/multi-horizon", response_model=MultiHorizonForecastResponse)
@app.post("/forecast/physics", response_model=MultiHorizonForecastResponse)
def get_multi_horizon_physics(request: MultiHorizonForecastRequest):
    """
    Forecast with p10, p50 and p90.
    Uses the ONNX model, the AutoGluon Python model or climatology, depending on the horizon.
    """
    horizon = int(request.horizon_hours)
    now_utc = datetime.now(timezone.utc)

    expected_feat_dim = multihorizon_router.get_expected_feature_count()
    if request.feature_vector is not None and len(request.feature_vector) == expected_feat_dim:
        vec = np.array(request.feature_vector, dtype=np.float32)
    elif request.readings is not None and len(request.readings) >= 48:
        raw_df = pd.DataFrame([r.model_dump() for r in request.readings])
        needs_currents = ("current_u" not in raw_df.columns or raw_df["current_u"].isna().any())
        if needs_currents:
            ts_index = pd.DatetimeIndex(pd.to_datetime(raw_df["timestamp"]))
            currents_df = get_live_currents_forecast(ts_index)
            raw_df["current_u"] = currents_df["current_u"].values
            raw_df["current_v"] = currents_df["current_v"].values

        # Make sure the wave columns exist for the lag features
        for col in ["hs", "tp", "swell_height", "wind_wave_height"]:
            if col not in raw_df.columns:
                raw_df[col] = 1.0

        lagged_df = build_lagged_features(raw_df)
        vec = lagged_df.iloc[-1].values.astype(np.float32)
        if len(vec) != expected_feat_dim:
            # Pad or cut to the right size
            vec = np.pad(vec, (0, max(0, expected_feat_dim - len(vec))))[:expected_feat_dim]
    else:
        # Default input if we have no data
        vec = np.ones((expected_feat_dim,), dtype=np.float32) * 1.5

    target_ts = pd.Timestamp.now(tz="UTC") + pd.Timedelta(hours=horizon)
    forecast, metadata = multihorizon_router.generate_physics_forecast(
        horizon=horizon,
        feature_vector=vec,
        target_timestamp=target_ts,
    )

    return MultiHorizonForecastResponse(
        horizon_hours=horizon,
        physics_forecast=forecast,
        metadata=metadata,
        generated_at=now_utc.isoformat(),
    )


class SiteForecastRequest(BaseModel):
    latitude: float = Field(..., description="Latitude of target site")
    longitude: float = Field(..., description="Longitude of target site")
    issued_at: Optional[str] = Field(None, description="ISO 8601 timestamp string of forecast issue time")
    days: int = Field(10, description="Forecast horizon in days (default 10, max 16)")
    force_stale: bool = Field(False, description="Flag to force simulation of stale store fallback")


@app.post("/forecast/site")
def forecast_site_endpoint(request: SiteForecastRequest):
    """
    Main site forecast endpoint.
    Uses different sources by horizon (hs up to 48h, current_speed up to 72h),
    conformal p10/p90 ranges, falls back to climatology if needed,
    and rejects locations outside our area.
    """
    from src.serve.site_forecaster import get_site_forecaster, OutOfAreaError
    forecaster = get_site_forecaster()
    try:
        return forecaster.forecast_site(
            lat=request.latitude,
            lon=request.longitude,
            issued_at=request.issued_at,
            days=request.days,
            force_stale=request.force_stale
        )
    except OutOfAreaError as e:
        raise HTTPException(
            status_code=422,
            detail={"error": "out_of_area", "message": str(e)}
        )

