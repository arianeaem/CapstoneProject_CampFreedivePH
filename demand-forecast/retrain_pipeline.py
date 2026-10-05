"""
retrain_pipeline.py

Retrains the demand models and makes the 90-day demand forecast for Camp Freedive PH.

Steps:
1. Load data: the real past batches made from the 553 Google Form registrations
   (data/demand_ml_batch_training_history.csv). No fake data is made.
   Completed Laravel batches are only added with --include-laravel.
2. Features: date features, season, booking lead time, lag and rolling features.
3. Train: XGBoost models for participant count and class income
   (grid search with PredefinedSplit).
4. Check: compare the test results (MAE, RMSE, WAPE, R2) against the limits.
5. Save: overwrite the model files (.joblib) in outputs/models/.
6. Forecast: predict 13 weekly batches for the next 90 days (outputs/forecast.csv).
   These are only forecasts, they are never saved as real data.
7. Send: compute the 7, 30, 60 and 90 day summaries and send the forecast to Laravel.
"""

import json
import os
import sys
from datetime import datetime, timedelta
import joblib
import numpy as np
import pandas as pd
import requests
from sklearn.model_selection import GridSearchCV, PredefinedSplit
from xgboost import XGBRegressor

# ==============================================================================
# Settings
# ==============================================================================
def _load_env_file():
    """Load the .env file if there is one."""
    try:
        from dotenv import load_dotenv
        dotenv_path = os.path.join(os.path.dirname(os.path.abspath(__file__)), ".env")
        if os.path.exists(dotenv_path):
            load_dotenv(dotenv_path=dotenv_path)
        else:
            load_dotenv()
    except ImportError:
        pass

    for candidate in [
        os.path.join(os.path.dirname(os.path.abspath(__file__)), ".env"),
        os.path.join(os.getcwd(), ".env"),
    ]:
        if os.path.isfile(candidate):
            try:
                with open(candidate, "r", encoding="utf-8") as f:
                    for line in f:
                        line = line.strip()
                        if not line or line.startswith("#") or "=" not in line:
                            continue
                        k, v = line.split("=", 1)
                        k = k.strip()
                        v = v.strip().strip("'\"")
                        if k and k not in os.environ:
                            os.environ[k] = v
            except Exception:
                pass

_load_env_file()

LARAVEL_API_URL = os.getenv("LARAVEL_API_URL", "http://127.0.0.1:8000/api/v1/ml").rstrip("/")
ML_TOKEN = os.getenv("ML_TOKEN")

if not ML_TOKEN:
    raise ValueError(
        "Missing required environment variable 'ML_TOKEN'. "
        "Please configure ML_TOKEN in demand-forecast/.env or export it in your environment."
    )

BASE_DIR = os.path.dirname(os.path.abspath(__file__))
OUTPUT_DIR = os.path.join(BASE_DIR, "outputs")
MODEL_DIR = os.path.join(OUTPUT_DIR, "models")
DATA_DIR = os.path.join(BASE_DIR, "data")

os.makedirs(OUTPUT_DIR, exist_ok=True)
os.makedirs(MODEL_DIR, exist_ok=True)
os.makedirs(DATA_DIR, exist_ok=True)

HEADERS = {
    "Authorization": f"Bearer {ML_TOKEN}",
    "Accept": "application/json",
    "Content-Type": "application/json"
}

FEATURE_COLS = [
    "day_of_week",
    "week_of_year",
    "month",
    "day_of_year",
    "is_weekend",
    "avg_lead_time",
    "median_lead_time",
    "participant_count_lag_1",
    "participant_count_lag_2",
    "participant_count_lag_4",
    "participant_count_rolling_mean_4",
    "booking_count_lag_1",
    "booking_count_lag_2",
    "booking_count_lag_4",
    "booking_count_rolling_mean_4",
    "class_revenue_lag_1",
    "class_revenue_lag_2",
    "class_revenue_lag_4",
    "class_revenue_rolling_mean_4",
    "season_is_dry",
]

TARGETS = [
    "participant_count",
    "booking_count",
    "class_revenue",
]
BATCH_CADENCE_DAYS = 7
HORIZONS = [7, 30, 60, 90]


def get_season(month: int) -> str:
    """Season for a month: dry (Nov-Apr) or wet (May-Oct)."""
    return "dry" if month in (11, 12, 1, 2, 3, 4) else "wet"


# ------------------------------------------------------------------------------
# demand_thresholds.json
# This script writes it from the real 553-record history and Laravel reads it
# (config/demand.php), so High/Medium/Low and Peak/Shoulder/Off-Peak are only defined here.
# ------------------------------------------------------------------------------
DEMAND_CONFIG_PATH = os.path.join(BASE_DIR, "demand_thresholds.json")
ACTUAL_HISTORY_PATH = os.path.join(DATA_DIR, "demand_ml_batch_training_history.csv")
REQUIRED_HISTORY_COLS = ["batch_id", "batch_date", "participant_count", "booking_count", "class_revenue"]
PEAK_INDEX_CUTOFF = 1.15      # month avg >= 115% of the overall average -> Peak
OFFPEAK_INDEX_CUTOFF = 0.90   # month avg <= 90% of the overall average -> Off-Peak
INCLUDE_LARAVEL = os.getenv("INCLUDE_LARAVEL_BATCHES", "0") == "1" or "--include-laravel" in sys.argv


def load_actual_history(path: str = ACTUAL_HISTORY_PATH) -> pd.DataFrame:
    """Load the real batch history (from the 553 Google Form records)."""
    if not os.path.exists(path):
        raise FileNotFoundError(
            f"Actual history not found: {path}. The pipeline will NOT generate synthetic history."
        )
    df = pd.read_csv(path, parse_dates=["batch_date"])
    missing = [c for c in REQUIRED_HISTORY_COLS if c not in df.columns]
    if missing:
        raise ValueError(f"Actual history is missing required columns: {missing}")
    if df.empty:
        raise ValueError("Actual history is empty.")
    if "primary_source" in df.columns and df["primary_source"].astype(str).str.contains("synthetic|baseline|simulated|forecast", case=False).any():
        raise ValueError("Actual history contains rows tagged synthetic/forecast. Refusing to train.")
    return df.sort_values("batch_date").reset_index(drop=True)


def derive_demand_config(df: pd.DataFrame) -> dict:
    """Make the High/Medium/Low and Peak/Shoulder/Off-Peak rules from the real history only."""
    pax = df["participant_count"].astype(float)
    low_max = round(float(pax.quantile(0.33)), 1)
    medium_max = round(float(pax.quantile(0.67)), 1)

    month_avg = df.groupby(df["batch_date"].dt.month)["participant_count"].mean()
    month_avg = month_avg.reindex(range(1, 13))
    overall = float(month_avg.mean())
    sd = float(month_avg.std(ddof=1))

    season_by_month = {}
    index_by_month = {}
    for m in range(1, 13):
        avg = month_avg.get(m)
        if pd.isna(avg):
            season_by_month[str(m)] = "Shoulder"   # no actual data for that month
            index_by_month[str(m)] = None
            continue
        idx = float(avg) / overall
        index_by_month[str(m)] = round(idx, 4)
        if idx >= PEAK_INDEX_CUTOFF:
            season_by_month[str(m)] = "Peak"
        elif idx <= OFFPEAK_INDEX_CUTOFF:
            season_by_month[str(m)] = "Off-Peak"
        else:
            season_by_month[str(m)] = "Shoulder"

    return {
        "version": 1,
        "generated_at": datetime.now().strftime("%Y-%m-%d %H:%M:%S"),
        "source": "553 actual Google Form registration records",
        "batches_used": int(len(df)),
        "date_range": [str(df["batch_date"].min().date()), str(df["batch_date"].max().date())],
        "demand_level": {
            "rule": "participants per batch: <= low_max = Low; <= medium_max = Medium; otherwise High (33rd/67th percentile of actual batches)",
            "low_max": low_max,
            "medium_max": medium_max,
        },
        "season": {
            "rule": "month average participants / all-month average: >= peak_index = Peak; <= offpeak_index = Off-Peak; otherwise Shoulder",
            "peak_index": PEAK_INDEX_CUTOFF,
            "offpeak_index": OFFPEAK_INDEX_CUTOFF,
            "overall_monthly_mean": round(overall, 2),
            "monthly_sd": round(sd, 2),
            "seasonal_index_by_month": index_by_month,
            "by_month": season_by_month,
        },
    }


def save_demand_config(cfg: dict, path: str = DEMAND_CONFIG_PATH) -> None:
    with open(path, "w", encoding="utf-8") as f:
        json.dump(cfg, f, indent=2)


def classify_demand_level(pax: float, cfg: dict) -> str:
    if pax <= cfg["demand_level"]["low_max"]:
        return "Low"
    if pax <= cfg["demand_level"]["medium_max"]:
        return "Medium"
    return "High"


def season_for_month(month: int, cfg: dict) -> str:
    return cfg["season"]["by_month"][str(int(month))]


def compute_metrics(y_true, y_pred) -> dict:
    """MAE, RMSE, MAPE, WAPE and R2."""
    y_true = np.asarray(y_true, dtype=float)
    y_pred = np.asarray(y_pred, dtype=float)
    errors = y_pred - y_true
    mae = float(np.mean(np.abs(errors)))
    rmse = float(np.sqrt(np.mean(errors ** 2)))

    # MAPE (skip zero actuals so we don't divide by zero)
    nonzero = y_true != 0
    mape = float(np.mean(np.abs(errors[nonzero] / y_true[nonzero])) * 100) if nonzero.any() else float("nan")
    wape = float(np.sum(np.abs(errors)) / np.sum(y_true) * 100) if np.sum(y_true) != 0 else float("nan")

    # R-squared
    ss_res = np.sum(errors ** 2)
    ss_tot = np.sum((y_true - np.mean(y_true)) ** 2)
    r2 = float(1 - (ss_res / ss_tot)) if ss_tot > 0 else 0.0

    return {
        "MAE": mae,
        "RMSE": rmse,
        "MAPE": mape,
        "WAPE": wape,
        "R2": r2,
        "MBE": float(np.mean(errors))
    }


# ------------------------------------------------------------------------------
# Batch forecast helpers (one forecast for each scheduled batch)
# ------------------------------------------------------------------------------
INTERVAL_Z_80 = 1.2816                 # 80% prediction interval
DEFAULT_BATCH_CAPACITY = 45
COACH_RATIO = 4                        # one coach per four participants
REAL_HISTORY_MIN_BATCHES = 104         # about two years of weekly batches
REAL_HISTORY_MAX_STALE_DAYS = 90       # newest real batch must be at most this old
# TODO: confirm these two numbers with the camp


def make_feature_row(current_date, history, avg_lead, median_lead) -> dict:
    """Features for one batch date, from the rolling history of the batches before it."""
    recent_pax = history["participant_count"].tolist()
    recent_bkg = history["booking_count"].tolist()
    recent_rev = history["class_revenue"].tolist()
    return {
        "day_of_week": current_date.dayofweek,
        "week_of_year": int(current_date.isocalendar().week),
        "month": current_date.month,
        "day_of_year": current_date.dayofyear,
        "is_weekend": int(current_date.dayofweek in (5, 6)),
        "avg_lead_time": avg_lead,
        "median_lead_time": median_lead,
        "participant_count_lag_1": recent_pax[-1],
        "participant_count_lag_2": recent_pax[-2] if len(recent_pax) >= 2 else np.nan,
        "participant_count_lag_4": recent_pax[-4] if len(recent_pax) >= 4 else np.nan,
        "participant_count_rolling_mean_4": float(np.mean(recent_pax[-4:])),
        "booking_count_lag_1": recent_bkg[-1],
        "booking_count_lag_2": recent_bkg[-2] if len(recent_bkg) >= 2 else np.nan,
        "booking_count_lag_4": recent_bkg[-4] if len(recent_bkg) >= 4 else np.nan,
        "booking_count_rolling_mean_4": float(np.mean(recent_bkg[-4:])),
        "class_revenue_lag_1": recent_rev[-1],
        "class_revenue_lag_2": recent_rev[-2] if len(recent_rev) >= 2 else np.nan,
        "class_revenue_lag_4": recent_rev[-4] if len(recent_rev) >= 4 else np.nan,
        "class_revenue_rolling_mean_4": float(np.mean(recent_rev[-4:])),
        "season_is_dry": int(get_season(current_date.month) == "dry"),
    }


def fetch_scheduled_batches() -> list:
    """Upcoming batches from Laravel (GET /scheduled-batches). We don't make up batches."""
    try:
        resp = requests.get(f"{LARAVEL_API_URL}/scheduled-batches", headers=HEADERS, timeout=15)
        resp.raise_for_status()
        return list(resp.json().get("data", []))
    except Exception as e:
        print(f"-> Could not fetch scheduled batches from Laravel ({e}). Per-batch forecast skipped.")
        return []


def compute_baselines(train_val_df: pd.DataFrame, test_df: pd.DataFrame, models: dict, feature_cols: list) -> dict:
    """Compare the ML model with two simple baselines on the latest (held-out) batches."""
    out = {}
    for target in ("participant_count", "booking_count"):
        y_true = test_df[target].astype(float).values
        model_pred = models[target].predict(test_df[feature_cols])
        naive_pred = test_df[f"{target}_lag_1"].astype(float).values            # "same as the previous batch"
        month_mean = train_val_df.groupby("month")[target].mean()
        overall = float(train_val_df[target].mean())
        seasonal_pred = np.array([float(month_mean.get(m, overall)) for m in test_df["month"]])  # "usual for that month"

        def pack(pred):
            m = compute_metrics(y_true, np.asarray(pred, dtype=float))
            return {"MAE": round(float(m["MAE"]), 2), "WAPE": round(float(m["WAPE"]), 1)}

        entry = {"model": pack(model_pred), "naive": pack(naive_pred), "seasonal_naive": pack(seasonal_pred)}
        entry["beats_naive"] = bool(entry["model"]["MAE"] < entry["naive"]["MAE"])
        entry["beats_seasonal_naive"] = bool(entry["model"]["MAE"] < entry["seasonal_naive"]["MAE"])
        out[target] = entry
    out["test_batches"] = int(len(test_df))
    out["model_validated"] = bool(
        out["participant_count"]["beats_naive"] and out["participant_count"]["beats_seasonal_naive"]
    )
    return out


def determine_data_basis(n_batches: int, last_actual_date, today) -> str:
    stale_days = int((today - last_actual_date).days)
    if n_batches >= REAL_HISTORY_MIN_BATCHES and stale_days <= REAL_HISTORY_MAX_STALE_DAYS:
        return "real_history"
    return "limited_history"


def build_batch_forecasts(batches, models, actual_df, avg_lead, median_lead, demand_cfg,
                          rmse_by_target, model_version, data_basis, generated_at, today) -> list:
    """
    One forecast row for each scheduled batch.

    Each batch is forecast on its own: it starts from the normal level of its month
    in the real history, not from another batch's forecast. So adding, moving or
    cancelling one batch doesn't change the others.
    """
    rows = []
    cols = ["participant_count", "booking_count", "class_revenue"]
    month_means = actual_df.groupby(pd.to_datetime(actual_df["batch_date"]).dt.month)[cols].mean()
    overall_means = actual_df[cols].mean()
    _h = actual_df.assign(m=pd.to_datetime(actual_df["batch_date"]).dt.month)
    _g = _h.groupby("m")[["class_revenue", "participant_count"]].sum()
    rev_per_pax_by_month = _g["class_revenue"] / _g["participant_count"]
    overall_rev_per_pax = actual_df["class_revenue"].sum() / actual_df["participant_count"].sum()

    for b in sorted(batches, key=lambda x: x["start_date"]):
        batch_date = pd.to_datetime(b["start_date"])
        base = month_means.loc[batch_date.month] if batch_date.month in month_means.index else overall_means
        history = pd.DataFrame([{
            "batch_date": batch_date - timedelta(days=BATCH_CADENCE_DAYS * (4 - i)),
            "participant_count": float(base["participant_count"]),
            "booking_count": float(base["booking_count"]),
            "class_revenue": float(base["class_revenue"]),
        } for i in range(4)])
        capacity = int(b.get("capacity") or DEFAULT_BATCH_CAPACITY)
        booked = int(b.get("booked_so_far") or 0)

        X = pd.DataFrame([make_feature_row(batch_date, history, avg_lead, median_lead)])[FEATURE_COLS]
        pax = max(0.0, float(models["participant_count"].predict(X)[0]))
        bkg = max(0.0, float(models["booking_count"].predict(X)[0]))

        # The forecast can't be lower than what is already booked (booked_so_far is real data)
        adjusted = booked > pax
        pax_final = max(pax, float(booked))
        rpp = float(rev_per_pax_by_month.get(batch_date.month, overall_rev_per_pax))
        rev = max(0.0, pax_final * rpp)

        rmse = float(rmse_by_target.get("participant_count", 0.0))
        lower = max(float(booked), pax_final - INTERVAL_Z_80 * rmse)
        upper = min(float(capacity), pax_final + INTERVAL_Z_80 * rmse)
        upper = max(upper, pax_final)

        rows.append({
            "batch_id": b.get("batch_id"),
            "batch_code": b.get("batch_code"),
            "batch_date": batch_date.strftime("%Y-%m-%d"),
            "days_to_start": int((batch_date - today).days),
            "booked_so_far": booked,
            "capacity": capacity,
            "predicted_participants": round(pax_final, 1),
            "predicted_bookings": round(bkg, 1),
            "predicted_revenue_php": round(rev, 2),
            "lower_bound": round(lower, 1),
            "upper_bound": round(upper, 1),
            "predicted_fill_rate": round(pax_final / capacity, 4) if capacity else None,
            "demand_level": classify_demand_level(pax_final, demand_cfg),
            "season_period": season_for_month(batch_date.month, demand_cfg),
            "adjusted_for_booked": bool(adjusted),
            "data_basis": data_basis,
            "model_version": model_version,
            "generated_at": generated_at,
        })

    return rows


def monthly_rollup(batch_rows: list, demand_cfg: dict) -> list:
    """Monthly totals = sum of the batch forecasts in that month."""
    if not batch_rows:
        return []
    df = pd.DataFrame(batch_rows)
    df["_m"] = pd.to_datetime(df["batch_date"]).dt.to_period("M")
    out = []
    for period, g in df.groupby("_m", sort=True):
        avg = float(g["predicted_participants"].mean())
        out.append({
            "month": str(period),
            "batches": int(len(g)),
            "predicted_participants": round(float(g["predicted_participants"].sum()), 1),
            "predicted_bookings": round(float(g["predicted_bookings"].sum()), 1),
            "avg_participants_per_batch": round(avg, 1),
            "demand_level": classify_demand_level(avg, demand_cfg),
            "season_period": season_for_month(period.month, demand_cfg),
        })
    return out


def run_pipeline():
    # ==============================================================================
    # STEP 1: get the data (and Laravel batches if enabled)
    # ==============================================================================
    print("======================================================================")
    print("STEP 1: DATA INGESTION (LARAVEL API & HISTORICAL ACTUALS)")
    print("======================================================================")
    print(f"Loading ACTUAL history: {ACTUAL_HISTORY_PATH}")
    df_base = load_actual_history()
    print(f"-> {len(df_base)} actual batches loaded (source: {df_base['primary_source'].iloc[0] if 'primary_source' in df_base.columns else 'n/a'}).")

    df_fresh = pd.DataFrame()
    if INCLUDE_LARAVEL:
        print(f"--include-laravel set: fetching completed batches from {LARAVEL_API_URL}/training-data...")
        try:
            response = requests.get(f"{LARAVEL_API_URL}/training-data", headers=HEADERS, timeout=15)
            if response.status_code == 404:
                response = requests.get(f"{LARAVEL_API_URL}/export-training-data", headers=HEADERS, timeout=15)
            response.raise_for_status()
            records = response.json().get("data", [])
            if records:
                raw_fresh = pd.DataFrame(records)
                df_fresh = pd.DataFrame({
                    "batch_id": raw_fresh["batch_id"].apply(lambda x: f"LV-B{x}"),
                    "batch_date": pd.to_datetime(raw_fresh["start_date"]),
                    "primary_source": "Laravel Production DB",
                    "date_confidence": "confirmed",
                    "date_source": "Laravel Batch System",
                    "participant_count": pd.to_numeric(raw_fresh["total_participants"], errors="coerce").fillna(0),
                    "booking_count": pd.to_numeric(raw_fresh["active_bookings_count"], errors="coerce").fillna(0),
                    "class_revenue": pd.to_numeric(raw_fresh["total_revenue_php"], errors="coerce").fillna(0),
                    "package_revenue": 0.0,
                    "avg_lead_time": np.nan,
                    "median_lead_time": np.nan,
                })
                print(f"-> {len(df_fresh)} completed Laravel batches merged as ACTUAL data.")
        except Exception as e:
            print(f"-> Warning: Could not fetch from Laravel ({e}). Continuing with the 553-record history only.")
    else:
        print("-> Laravel batches NOT used (the 553 actual records are the only training source).")

    combined = pd.concat([df_base, df_fresh], ignore_index=True) if not df_fresh.empty else df_base.copy()
    combined = combined.drop_duplicates(subset=["batch_date"], keep="last")
    combined = combined.sort_values("batch_date").reset_index(drop=True)

    local_batch_path = os.path.join(OUTPUT_DIR, "batch_level_dataset.csv")
    combined.to_csv(local_batch_path, index=False)
    print(f"-> Unified ACTUAL dataset ready: {len(combined)} chronological batches.")
    print(f"   Date range: {combined['batch_date'].min().date()} to {combined['batch_date'].max().date()}")

    # High/Medium/Low and Peak/Shoulder/Off-Peak rules (from real data only)
    demand_cfg = derive_demand_config(combined)
    save_demand_config(demand_cfg)
    print(f"-> Saved demand rules -> {DEMAND_CONFIG_PATH}")
    print(f"   Demand: Low <= {demand_cfg['demand_level']['low_max']} < Medium <= {demand_cfg['demand_level']['medium_max']} < High")
    print("   Season by month: " + ", ".join(f"{m}:{v}" for m, v in demand_cfg['season']['by_month'].items()))


    # ==============================================================================
    # STEP 2: features
    # ==============================================================================
    print("\n======================================================================")
    print("STEP 2: FEATURE ENGINEERING & LAG COMPUTATION")
    print("======================================================================")

    batch = combined.copy()
    batch["batch_date"] = pd.to_datetime(batch["batch_date"])
    batch = batch.sort_values("batch_date").reset_index(drop=True)

    # 1. Date features
    batch["day_of_week"] = batch["batch_date"].dt.dayofweek
    batch["week_of_year"] = batch["batch_date"].dt.isocalendar().week.astype(int)
    batch["month"] = batch["batch_date"].dt.month
    batch["day_of_year"] = batch["batch_date"].dt.dayofyear
    batch["is_weekend"] = batch["day_of_week"].isin([5, 6]).astype(int)

    # 2. Season
    batch["season"] = batch["month"].apply(get_season)
    batch["season_is_dry"] = (batch["season"] == "dry").astype(int)

    # 3. Default lead time
    default_lead = 14.5
    batch["avg_lead_time"] = batch["avg_lead_time"].fillna(default_lead)
    batch["median_lead_time"] = batch["median_lead_time"].fillna(default_lead)

    # 4. Lag and rolling features
    for target in TARGETS:
        batch[f"{target}_lag_1"] = batch[target].shift(1)
        batch[f"{target}_lag_2"] = batch[target].shift(2)
        batch[f"{target}_lag_4"] = batch[target].shift(4)
        batch[f"{target}_rolling_mean_4"] = batch[target].shift(1).rolling(window=4, min_periods=1).mean()

    featured_path = os.path.join(OUTPUT_DIR, "featured_dataset.csv")
    batch.to_csv(featured_path, index=False)
    print(f"-> Feature engineering complete. Saved -> {featured_path}")


    # ==============================================================================
    # STEP 3: split by time and train
    # ==============================================================================
    print("\n======================================================================")
    print("STEP 3: TIME-SERIES SPLIT & MODEL RETRAINING (XGBOOST)")
    print("======================================================================")

    n = len(batch)
    n_train = int(round(n * 0.70))
    n_val = int(round(n * 0.15))
    n_test = n - n_train - n_val

    batch["split"] = "train"
    batch.loc[n_train:n_train + n_val - 1, "split"] = "validation"
    batch.loc[n_train + n_val:, "split"] = "test"

    split_path = os.path.join(OUTPUT_DIR, "split_dataset.csv")
    batch.to_csv(split_path, index=False)

    train_val_df = batch[batch["split"].isin(["train", "validation"])].copy()
    test_df = batch[batch["split"] == "test"].copy()

    print(f"Split Summary: Train={len(train_val_df[train_val_df['split']=='train'])}, "
          f"Validation={len(train_val_df[train_val_df['split']=='validation'])}, "
          f"Test={len(test_df)}")

    # PredefinedSplit for the validation fold
    test_fold = train_val_df["split"].map({"train": -1, "validation": 0}).values
    ps = PredefinedSplit(test_fold)

    X_train_val = train_val_df[FEATURE_COLS]
    X_test = test_df[FEATURE_COLS]

    PARAM_GRID = {
        "max_depth": [3, 4, 5],
        "n_estimators": [100, 200],
        "learning_rate": [0.05, 0.1],
    }

    retrained_models = {}
    # 3 models:
    # 1 -> participant_count (XGBoost)
    # 2 -> booking_count (XGBoost)
    # 3 -> class_revenue (XGBoost)
    for idx, target in enumerate(TARGETS, 1):
        print(f"Training Model {idx} [XGBoost Regressor] for '{target}'...")
        y_train_val = train_val_df[target]

        grid = GridSearchCV(
            XGBRegressor(objective="reg:squarederror", random_state=42),
            param_grid=PARAM_GRID,
            cv=ps,
            scoring="neg_mean_absolute_error",
            refit=True,
        )
        grid.fit(X_train_val, y_train_val)
        best_model = grid.best_estimator_
        retrained_models[target] = best_model
        print(f"  -> Model {idx} ({target}) Best params: {grid.best_params_}")
        print(f"  -> Model {idx} ({target}) Best validation MAE: {-grid.best_score_:,.2f}")


    # ==============================================================================
    # STEP 4: check the results
    # ==============================================================================
    print("\n======================================================================")
    print("STEP 4: ERROR-LIMIT GATE (absolute MAE limits only)")
    print("======================================================================")

    eval_results = {}
    validation_passed = True

    # Limits for the models
    MAX_ACCEPTABLE_PARTICIPANT_MAE = 20.0
    MAX_ACCEPTABLE_BOOKING_MAE = 10.0
    MAX_ACCEPTABLE_REVENUE_MAE = 80000.0

    target_descriptions = {
        "participant_count": "Participant Count (Individual Divers / Attendees)",
        "booking_count": "Booking Count (Distinct Booking / Group Transactions)",
        "class_revenue": "Class Revenue (Gross Class Revenue PHP)",
    }

    for idx, target in enumerate(TARGETS, 1):
        model = retrained_models[target]
        y_true = test_df[target].values
        y_pred = model.predict(X_test)
        metrics = compute_metrics(y_true, y_pred)
        eval_results[target] = metrics

        print(f"\n----------------------------------------------------------------------")
        print(f"MODEL {idx} EVALUATION: target='{target}'")
        print(f"Description: {target_descriptions.get(target, target)}")
        print(f"----------------------------------------------------------------------")
        print(f"  Target: {target}")
        print(f"  MAE:    {metrics['MAE']:,.2f}")
        print(f"  RMSE:   {metrics['RMSE']:,.2f}")
        print(f"  R²:     {metrics['R2']:.4f}")
        print(f"  WAPE:   {metrics['WAPE']:.1f}%")

        if target == "participant_count" and metrics["MAE"] > MAX_ACCEPTABLE_PARTICIPANT_MAE:
            print(f"  WARNING: Model {idx} Participant MAE ({metrics['MAE']:.2f}) exceeds threshold ({MAX_ACCEPTABLE_PARTICIPANT_MAE})")
            validation_passed = False
        elif target == "booking_count" and metrics["MAE"] > MAX_ACCEPTABLE_BOOKING_MAE:
            print(f"  WARNING: Model {idx} Booking MAE ({metrics['MAE']:.2f}) exceeds threshold ({MAX_ACCEPTABLE_BOOKING_MAE})")
            validation_passed = False
        elif target == "class_revenue" and metrics["MAE"] > MAX_ACCEPTABLE_REVENUE_MAE:
            print(f"  WARNING: Model {idx} Revenue MAE ({metrics['MAE']:.2f}) exceeds threshold ({MAX_ACCEPTABLE_REVENUE_MAE})")
            validation_passed = False

    if validation_passed:
        print("\n-> Error-limit gate PASSED: MAE is within the maximum acceptable limits.")
        print("   NOTE: this gate does NOT compare against simple baselines. See the baseline comparison below.")
        for target in TARGETS:
            model_path = os.path.join(MODEL_DIR, f"{target}_model.joblib")
            joblib.dump(retrained_models[target], model_path)
            print(f"  -> Exported model artifact: {model_path}")
    else:
        print("\n-> Error-limit gate FAILED: MAE is above the maximum acceptable limits. Retaining existing model artifacts.")
        # Try to load the old models if they exist
        for target in TARGETS:
            model_path = os.path.join(MODEL_DIR, f"{target}_model.joblib")
            if os.path.exists(model_path):
                retrained_models[target] = joblib.load(model_path)


    # ==============================================================================
    # STEP 5: 90-day forecast (step by step)
    # ==============================================================================
    print("\n======================================================================")
    print("STEP 5: 90-DAY RECURSIVE FORECAST GENERATION")
    print("======================================================================")

    last_known_date = pd.to_datetime(datetime.now().date())
    print(f"Forecasting from current anchor date: {last_known_date.date()} forward for 90 days...")

    # Demand level and season come from demand_cfg (made from real data)
    def classify_demand(pax: float) -> str:
        return classify_demand_level(pax, demand_cfg)


    participant_model = retrained_models["participant_count"]
    booking_model = retrained_models["booking_count"]
    revenue_model = retrained_models["class_revenue"]

    # Start the lag window with the last 4 batches
    history = batch[["batch_date", "participant_count", "booking_count", "class_revenue"]].tail(4).copy()
    gap_days = int((last_known_date - history["batch_date"].max()).days)
    if gap_days > 28:
        # The real history ends long before today. Using those old batches for the lags would
        # make every forecast depend on old months, so use the real average of the starting month.
        m_anchor = last_known_date.month
        by_month = batch.groupby("month")[["participant_count", "booking_count", "class_revenue"]].mean()
        seed_vals = by_month.loc[m_anchor] if m_anchor in by_month.index else batch[["participant_count", "booking_count", "class_revenue"]].mean()
        print(f"-> Actual history ends {gap_days} days before the anchor date; seeding lag features with the actual "
              f"month-{m_anchor} average ({seed_vals['participant_count']:.1f} pax) instead of stale batches.")
        history = pd.DataFrame([{
            "batch_date": last_known_date - timedelta(days=BATCH_CADENCE_DAYS * (4 - i)),
            "participant_count": float(seed_vals["participant_count"]),
            "booking_count": float(seed_vals["booking_count"]),
            "class_revenue": float(seed_vals["class_revenue"]),
        } for i in range(4)])
    avg_lead = float(batch["avg_lead_time"].mean())
    median_lead = float(batch["median_lead_time"].mean())

    history_seed = history.copy()          # copy for the batch forecast
    forecast_rows = []
    current_date = last_known_date
    n_steps = (max(HORIZONS) // BATCH_CADENCE_DAYS) + 1  # 13 steps

    for step in range(n_steps):
        current_date = current_date + timedelta(days=BATCH_CADENCE_DAYS)

        row = make_feature_row(current_date, history, avg_lead, median_lead)
        X_step = pd.DataFrame([row])[FEATURE_COLS]

        pred_participants = max(0.0, float(participant_model.predict(X_step)[0]))
        pred_bookings = max(0.0, float(booking_model.predict(X_step)[0]))
        pred_revenue = max(0.0, float(revenue_model.predict(X_step)[0]))

        forecast_rows.append({
            "forecast_date": current_date.strftime("%Y-%m-%d"),
            "days_ahead": int((current_date - last_known_date).days),
            "predicted_participants": round(pred_participants, 1),
            "predicted_bookings": round(pred_bookings, 1),
            "predicted_revenue_php": round(pred_revenue, 2),
            "demand_level": classify_demand(pred_participants),
            "season_period": None,  # Dynamically assigned by Statistical Demand Interpretation Layer below
            "instructors_needed": int(np.ceil(pred_participants / 4.0)),
        })

        # Add this forecast to the history for the next step
        history = pd.concat([
            history,
            pd.DataFrame([{
                "batch_date": current_date,
                "participant_count": pred_participants,
                "booking_count": pred_bookings,
                "class_revenue": pred_revenue
            }])
        ], ignore_index=True).tail(4)

    df_forecast = pd.DataFrame(forecast_rows)
    forecast_csv_path = os.path.join(OUTPUT_DIR, "forecast.csv")

    # ==============================================================================
    # Demand level and season (rules from the real 553-record history)
    # ==============================================================================
    df_forecast["_month"] = pd.to_datetime(df_forecast["forecast_date"]).dt.to_period("M")
    monthly_avg_participants = df_forecast.groupby("_month")["predicted_participants"].mean().round(1)

    overall_mean = float(demand_cfg["season"]["overall_monthly_mean"])
    sd = float(demand_cfg["season"]["monthly_sd"])
    upper_threshold = round(overall_mean * demand_cfg["season"]["peak_index"], 2)
    lower_threshold = round(overall_mean * demand_cfg["season"]["offpeak_index"], 2)

    print("\n--- Season Interpretation (from actual history) ---")
    print(f"All-month actual average = {overall_mean:.2f} pax | Peak >= {upper_threshold:.2f} | Off-Peak <= {lower_threshold:.2f}")

    monthly_season_classification = {}
    monthly_classifications = []
    for period, avg_val in monthly_avg_participants.items():
        season_class = season_for_month(period.month, demand_cfg)
        monthly_season_classification[period] = season_class
        month_name = period.to_timestamp().strftime("%B %Y")
        print(f"  {month_name:<16} forecast avg {avg_val:>4.1f} pax -> {season_class}")
        monthly_classifications.append({
            "month": month_name,
            "monthly_average": float(round(avg_val, 1)),
            "overall_mean": float(round(overall_mean, 1)),
            "standard_deviation": float(round(sd, 1)),
            "upper_threshold": float(round(upper_threshold, 1)),
            "lower_threshold": float(round(lower_threshold, 1)),
            "classification": season_class,
        })

    monthly_class_json_path = os.path.join(OUTPUT_DIR, "monthly_demand_classifications.json")
    with open(monthly_class_json_path, "w", encoding="utf-8") as f:
        json.dump(monthly_classifications, f, indent=2)
    print(f"-> Saved monthly classifications JSON -> {monthly_class_json_path}")

    df_forecast["season_period"] = df_forecast["_month"].map(monthly_season_classification)
    df_forecast.to_csv(forecast_csv_path, index=False)
    print(f"-> Generated {len(df_forecast)} ML forecast points. Saved -> {forecast_csv_path}")

    monthly_rows = []

    for period, group in df_forecast.groupby("_month", sort=True):
        monthly_rows.append({
            "month": str(period),
            "batches_in_month": len(group),
            "predicted_participants": round(
                group["predicted_participants"].sum(), 1
            ),
            "predicted_bookings": round(
                group["predicted_bookings"].sum(), 1
            ),
            "predicted_revenue_php": round(
                group["predicted_revenue_php"].sum(), 2
            ),
            "instructors_needed_total": int(
                group["instructors_needed"].sum()
            ),
            "season_period": monthly_season_classification.get(period, "Shoulder"),
        })

    df_forecast_monthly = pd.DataFrame(monthly_rows)

    # Remove the temporary group column so df_forecast
    # stays the same for the rest of the script.
    df_forecast = df_forecast.drop(columns=["_month"])

    forecast_monthly_path = os.path.join(
        OUTPUT_DIR,
        "forecast_monthly.csv"
    )

    df_forecast_monthly.to_csv(
        forecast_monthly_path,
        index=False
    )

    print(
        f"-> Rolled up into {len(df_forecast_monthly)} calendar months. "
        f"Saved -> {forecast_monthly_path}"
    )

    print("\n90-Day Recursive Point Forecast Preview:")
    print(f"  {'Date':<12} | {'Days':<5} | {'Pax (Participants)':<19} | {'Bookings (Distinct)':<20} | {'Revenue (PHP)':<15} | {'Demand'}")
    print("  " + "-" * 85)
    for _, r in df_forecast.head(6).iterrows():
        print(f"  {r['forecast_date']:<12} | {int(r['days_ahead']):<5} | {r['predicted_participants']:>14.1f} pax | {r['predicted_bookings']:>15.1f} bkg | PHP {r['predicted_revenue_php']:>10,.2f} | {r['demand_level']}")


    # ==============================================================================
    # STEP 6: summaries and send to Laravel
    # ==============================================================================
    print("\n======================================================================")
    print("STEP 6: COMPUTE HORIZONS & PUSH TO LARAVEL")
    print("======================================================================")


    def get_horizon_summary(df: pd.DataFrame, max_days: int) -> dict:
        subset = df[df["days_ahead"] <= max_days]
        if subset.empty:
            return {"batches": 0, "participants": 0, "bookings": 0, "revenue": 0.0, "peak_instructors": 0}
        return {
            "batches": int(len(subset)),
            "participants": int(round(subset["predicted_participants"].sum())),
            "bookings": int(round(subset["predicted_bookings"].sum())),
            "revenue": float(round(subset["predicted_revenue_php"].sum(), 2)),
            "peak_instructors": int(subset["instructors_needed"].max()) if not subset.empty else 0
        }


    horizon_summaries = {
        "7_day": get_horizon_summary(df_forecast, 7),
        "30_day": get_horizon_summary(df_forecast, 30),
        "60_day": get_horizon_summary(df_forecast, 60),
        "90_day": get_horizon_summary(df_forecast, 90),
    }

    for h_name, summary in horizon_summaries.items():
        print(f"  {h_name.upper():<7}: {summary['batches']:2d} batches | "
              f"{summary['participants']:3d} pax | "
              f"{summary['bookings']:3d} bkg | "
              f"PHP {summary['revenue']:10,.2f} | "
              f"Peak Coaches: {summary['peak_instructors']}")

    # ==============================================================================
    # Batch forecast (each scheduled batch) + monthly totals + baselines
    # ==============================================================================
    print("\n======================================================================")
    print("PER-BATCH FORECAST (REAL SCHEDULED BATCHES)")
    print("======================================================================")
    today_ts = pd.to_datetime(datetime.now().date())
    generated_at = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
    n_actual = int(len(batch))
    last_actual = pd.to_datetime(batch["batch_date"].max())
    data_basis = determine_data_basis(n_actual, last_actual, today_ts)
    model_version = f"xgb-demand-{n_actual}b-{datetime.now().strftime('%Y%m%d')}"

    baseline_cmp = compute_baselines(train_val_df, test_df, retrained_models, FEATURE_COLS)
    print("Baseline comparison on the held-out latest batches (lower MAE is better):")
    for tgt in ("participant_count", "booking_count"):
        e = baseline_cmp[tgt]
        print(f"  {tgt:<18} model MAE {e['model']['MAE']:>6} | naive {e['naive']['MAE']:>6} | seasonal-naive {e['seasonal_naive']['MAE']:>6}"
              f"  -> beats naive: {e['beats_naive']}, beats seasonal-naive: {e['beats_seasonal_naive']}")
    if not baseline_cmp["model_validated"]:
        print("  RESULT: NOT VALIDATED - the model does not beat the simple baselines yet (limited history).")
        print("          Forecasts are still produced, but the page marks them as a guide only.")
    else:
        print("  RESULT: VALIDATED - the model beats both simple baselines on the held-out batches.")

    scheduled = fetch_scheduled_batches()
    rmse_by_target = {t: eval_results[t]["RMSE"] for t in eval_results}
    batch_rows = build_batch_forecasts(
        scheduled, retrained_models, batch, avg_lead, median_lead, demand_cfg,
        rmse_by_target, model_version, data_basis, generated_at, today_ts,
    ) if scheduled else []
    batch_monthly = monthly_rollup(batch_rows, demand_cfg)

    pd.DataFrame(batch_rows).to_csv(os.path.join(OUTPUT_DIR, "per_batch_forecast.csv"), index=False)
    pd.DataFrame(batch_monthly).to_csv(os.path.join(OUTPUT_DIR, "per_batch_monthly.csv"), index=False)
    with open(os.path.join(OUTPUT_DIR, "baseline_comparison.json"), "w", encoding="utf-8") as f:
        json.dump(baseline_cmp, f, indent=2)
    print(f"-> data_basis = {data_basis} ({n_actual} actual batches, newest {last_actual.date()})")
    print(f"-> {len(batch_rows)} scheduled batch(es) forecast; {len(batch_monthly)} month rollup row(s).")
    for r in batch_rows:
        print(f"   {r['batch_date']} {str(r.get('batch_code') or ''):<14} booked {r['booked_so_far']:>2} | "
              f"predicted {r['predicted_participants']:>5} pax ({r['lower_bound']}-{r['upper_bound']}) | "
              f"{r['demand_level']:<6} | {r['season_period']}")

    forecast_payload = {
        "batch_forecasts": batch_rows,
        "batch_monthly": batch_monthly,
        "horizon_summaries": horizon_summaries,
        "monthly_classifications": monthly_classifications,
        "monthly_forecasts": df_forecast_monthly.to_dict(orient="records"),
        "forecasts": df_forecast.to_dict(orient="records"),
        "metadata": {
            "retrained_at": datetime.now().strftime("%Y-%m-%d %H:%M:%S"),
            "model_type": "XGBRegressor",
            "is_forecast": True,
            "model_version": model_version,
            "generated_at": generated_at,
            "data_basis": data_basis,
            "baseline_comparison": baseline_cmp,
            "data_source": "553 actual Google Form registration records",
            "training_batches": int(len(batch)),
            "demand_rules": demand_cfg,
            "monthly_forecasts": df_forecast_monthly.to_dict(orient="records"),
            "models": {
                "model_1": {"target": "participant_count", "artifact": "participant_count_model.joblib"},
                "model_2": {"target": "booking_count", "artifact": "booking_count_model.joblib"},
                "model_3": {"target": "class_revenue", "artifact": "class_revenue_model.joblib"}
            },
            "eval_metrics": eval_results,
            "statistical_interpretation": {
                "overall_mean": round(overall_mean, 2),
                "standard_deviation": round(sd, 2),
                "upper_threshold": round(upper_threshold, 2),
                "lower_threshold": round(lower_threshold, 2),
                "monthly_classifications": monthly_classifications
            }
        }
    }

    print(f"\nSyncing 90-day forecast to Laravel endpoint ({LARAVEL_API_URL}/sync-forecast)...")
    try:
        sync_response = requests.post(
            f"{LARAVEL_API_URL}/sync-forecast",
            json=forecast_payload,
            headers=HEADERS,
            timeout=15
        )
        sync_response.raise_for_status()
        resp_data = sync_response.json()
        print("-> Sync complete successfully:", resp_data.get("message", "OK"))
        print(f"-> Total records synced: {resp_data.get('records_synced', len(df_forecast))}")
    except Exception as e:
        print(f"-> Error syncing forecast to Laravel: {e}")

    print("\n======================================================================")
    print("RE-TRAINING PIPELINE EXECUTION FINISHED.")
    print("======================================================================")

if __name__ == "__main__":
    run_pipeline()
