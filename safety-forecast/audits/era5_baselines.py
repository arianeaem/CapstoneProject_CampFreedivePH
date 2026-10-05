"""
Rigorous Out-of-Fold CV Selection & Holdout Benchmark for ERA5 Atmospheric Baselines.

Variables Evaluated:
1. wind_speed (m/s)
2. wind_gust (m/s)
3. slp (hPa)
4. wind_dir (degrees, circular MAE/RMSE evaluated on wind_speed > 1.5 m/s)

Methodology:
- 6 Walk-Forward Expanding Folds across 2020-10 to 2025-09 (240h purge margin).
- Climatologies fit strictly on training partitions (No data leakage).
- Winning baseline selected per horizon based strictly on mean CV validation MAE.
- Evaluated on untouched Holdout (2025-10-01 to 2026-09-29, N=8,616 non-provisional hours).
- 168-hour stationary moving block bootstrap (1,000 resamples) for paired Delta MAE 95% CI.
"""

import sys
import json
from typing import Tuple, Dict, List
from pathlib import Path
import numpy as np
import pandas as pd

# Add repo paths
SAFETY_DIR = Path(__file__).resolve().parents[1]
if str(SAFETY_DIR / "src" / "ingest") not in sys.path:
    sys.path.insert(0, str(SAFETY_DIR / "src" / "ingest"))

from splits import CURRENTS_WALK_FORWARD_FOLDS

HORIZONS = [1, 3, 6, 12, 24, 48, 72, 120, 168, 240]
OPERATIONAL_LAG = 120  # hours (ECMWF ~5d latency)
BLOCK_SIZE_HOURS = 168  # 7 days (synoptic block bootstrap)
N_BOOT = 1000
WIND_SPEED_CALM_THRESHOLD = 1.5  # m/s for circular direction evaluation


def circular_diff(deg1: np.ndarray, deg2: np.ndarray) -> np.ndarray:
    """Computes shortest signed angular difference between two angles in degrees [-180, 180]."""
    return (deg1 - deg2 + 180.0) % 360.0 - 180.0


def circular_mae(pred: np.ndarray, obs: np.ndarray) -> float:
    diff = circular_diff(pred, obs)
    return float(np.nanmean(np.abs(diff)))


def circular_rmse(pred: np.ndarray, obs: np.ndarray) -> float:
    diff = circular_diff(pred, obs)
    return float(np.sqrt(np.nanmean(diff ** 2)))


def scalar_mae(pred: np.ndarray, obs: np.ndarray) -> float:
    return float(np.nanmean(np.abs(pred - obs)))


def paired_block_bootstrap(err_model: np.ndarray, err_ref: np.ndarray,
                           block_size: int = BLOCK_SIZE_HOURS,
                           n_boot: int = N_BOOT,
                           alpha: float = 0.05) -> Tuple[float, float, float]:
    """Moving block bootstrap on paired error differences with stationary block size."""
    diff = err_model - err_ref
    diff = diff[~np.isnan(diff)]
    n = len(diff)
    if n < block_size * 2:
        mean_diff = float(np.mean(diff)) if n > 0 else 0.0
        return mean_diff, mean_diff, mean_diff

    n_blocks = int(np.ceil(n / block_size))
    # Number of possible starting points
    max_start = n - block_size
    rng = np.random.default_rng(seed=42)

    boot_means = np.empty(n_boot)
    for b in range(n_boot):
        start_indices = rng.integers(0, max_start + 1, size=n_blocks)
        indices = np.concatenate([np.arange(s, s + block_size) for s in start_indices])[:n]
        boot_means[b] = np.mean(diff[indices])

    mean_diff = float(np.mean(diff))
    ci_lower = float(np.percentile(boot_means, 100 * (alpha / 2)))
    ci_upper = float(np.percentile(boot_means, 100 * (1 - alpha / 2)))
    return mean_diff, ci_lower, ci_upper


def fit_era5_climatology(train_df: pd.DataFrame) -> dict:
    """Fits hourly-by-month climatology on training partition."""
    # Group by (month, hour)
    m_h = train_df.groupby([train_df.index.month, train_df.index.hour])
    speed_clim = m_h["wind_speed"].mean().to_dict()
    gust_clim = m_h["wind_gust"].mean().to_dict()
    slp_clim = m_h["slp"].mean().to_dict()

    # Circular direction climatology: mean of u and v vectors
    u_clim = m_h["wind_u"].mean().to_dict()
    v_clim = m_h["wind_v"].mean().to_dict()
    dir_clim = {}
    for k in u_clim.keys():
        u_val = u_clim[k]
        v_val = v_clim[k]
        deg = (270.0 - np.degrees(np.arctan2(v_val, u_val))) % 360.0
        dir_clim[k] = deg

    return {
        "speed": speed_clim,
        "gust": gust_clim,
        "slp": slp_clim,
        "dir": dir_clim
    }


def predict_era5_climatology(clim: dict, target_idx: pd.DatetimeIndex, var: str) -> np.ndarray:
    c_dict = clim[var]
    res = np.empty(len(target_idx))
    for i, t in enumerate(target_idx):
        res[i] = c_dict.get((t.month, t.hour), np.nan)
    return res


def run_era5_baselines():
    data_path = SAFETY_DIR / "data" / "snapshots" / "2026-10-04_rev4" / "era5_wind_pressure.parquet"
    if not data_path.exists():
        raise FileNotFoundError(f"Missing {data_path}")

    df = pd.read_parquet(data_path)
    # Filter out provisional rows
    df_clean = df[~df["is_provisional"]].copy()

    # Holdout partition: 2025-10-01 to end of non-provisional
    holdout_mask = df_clean.index.tz_convert("UTC") >= pd.Timestamp("2025-10-01 00:00:00+00:00")
    holdout_df = df_clean[holdout_mask].copy()
    train_pool_df = df_clean[~holdout_mask].copy()

    print("=" * 95)
    print("ERA5 ATMOSPHERIC BASELINES BENCHMARK (CV SELECTION + HOLDOUT EVALUATION)")
    print(f"Dataset: {data_path.name} | Total Non-provisional Rows: {len(df_clean)}")
    print(f"Training Pool: {len(train_pool_df)} rows | Holdout: {len(holdout_df)} rows")
    print(f"Lag: {OPERATIONAL_LAG}h | Block Bootstrap: {BLOCK_SIZE_HOURS}h | Resamples: {N_BOOT}")
    print("=" * 95)

    variables = ["wind_speed", "wind_gust", "slp", "wind_dir"]
    results = {}

    for var in variables:
        is_circ = (var == "wind_dir")
        v_key = "dir" if is_circ else ("speed" if var == "wind_speed" else ("gust" if var == "wind_gust" else "slp"))
        print(f"\n>>> Running CV and Holdout for Variable: {var.upper()} (Circular={is_circ}) <<<")

        # Track fold MAEs: cv_scores[model][horizon] = [mae_f1, ..., mae_f6]
        models = ["persistence", "climatology"]
        cv_scores = {m: {h: [] for h in HORIZONS} for m in models}

        for f_info in CURRENTS_WALK_FORWARD_FOLDS:
            fold_id = f_info["fold"]
            t_start = pd.Timestamp(f_info["train_start"], tz="UTC")
            t_end = pd.Timestamp(f_info["train_end"], tz="UTC")
            v_start = pd.Timestamp(f_info["val_start"], tz="UTC")
            v_end = pd.Timestamp(f_info["val_end"], tz="UTC")

            f_train = train_pool_df[(train_pool_df.index.tz_convert("UTC") >= t_start) & (train_pool_df.index.tz_convert("UTC") <= t_end)]
            f_val = train_pool_df[(train_pool_df.index.tz_convert("UTC") >= v_start) & (train_pool_df.index.tz_convert("UTC") <= v_end)]

            # Fit climatology strictly on fold train
            f_clim = fit_era5_climatology(f_train)

            # Evaluate each horizon
            for h in HORIZONS:
                # Target at t + h
                # Persistence uses observation at t - OPERATIONAL_LAG
                # Hence lookback is h + OPERATIONAL_LAG
                lead = h + OPERATIONAL_LAG
                val_obs = f_val[var].values
                val_persist = f_train[var].reindex(f_val.index).shift(lead).values if lead > len(f_val) else f_val[var].shift(lead).values

                # Climatology prediction at validation timestamps
                val_clim_pred = predict_era5_climatology(f_clim, f_val.index, v_key)

                # Mask for evaluation (for wind_dir, only evaluate when wind_speed > WIND_SPEED_CALM_THRESHOLD)
                val_ws = f_val["wind_speed"].values
                eval_mask = ~np.isnan(val_obs)
                if is_circ:
                    eval_mask = eval_mask & (val_ws > WIND_SPEED_CALM_THRESHOLD)

                # Persistence MAE
                p_mask = eval_mask & ~np.isnan(val_persist)
                if is_circ:
                    p_mae = circular_mae(val_persist[p_mask], val_obs[p_mask])
                    c_mae = circular_mae(val_clim_pred[eval_mask], val_obs[eval_mask])
                else:
                    p_mae = scalar_mae(val_persist[p_mask], val_obs[p_mask])
                    c_mae = scalar_mae(val_clim_pred[eval_mask], val_obs[eval_mask])

                cv_scores["persistence"][h].append(p_mae)
                cv_scores["climatology"][h].append(c_mae)

        # Compute Mean CV MAE per horizon and select winning baseline
        selected_model_per_h = {}
        cv_summary = {}
        for h in HORIZONS:
            mean_p = float(np.mean(cv_scores["persistence"][h]))
            mean_c = float(np.mean(cv_scores["climatology"][h]))
            winner = "persistence" if mean_p < mean_c else "climatology"
            selected_model_per_h[h] = winner
            cv_summary[h] = {
                "cv_persistence_mae": mean_p,
                "cv_climatology_mae": mean_c,
                "selected_winner": winner
            }

        # ---------------------------------------------------------------------
        # HOLDOUT EVALUATION (Fit Climatology on full train_pool_df)
        # ---------------------------------------------------------------------
        full_clim = fit_era5_climatology(train_pool_df)
        holdout_clim_pred = predict_era5_climatology(full_clim, holdout_df.index, v_key)
        holdout_obs = holdout_df[var].values
        holdout_ws = holdout_df["wind_speed"].values

        holdout_eval_mask = ~np.isnan(holdout_obs)
        if is_circ:
            holdout_eval_mask = holdout_eval_mask & (holdout_ws > WIND_SPEED_CALM_THRESHOLD)

        holdout_results = []
        for h in HORIZONS:
            lead = h + OPERATIONAL_LAG
            # Persistence: shifted from full historical series
            holdout_persist = df_clean[var].reindex(holdout_df.index).shift(lead).values

            # Error arrays for paired bootstrap
            if is_circ:
                err_clim = np.abs(circular_diff(holdout_clim_pred, holdout_obs))
                err_pers = np.abs(circular_diff(holdout_persist, holdout_obs))
            else:
                err_clim = np.abs(holdout_clim_pred - holdout_obs)
                err_pers = np.abs(holdout_persist - holdout_obs)

            # Apply evaluation mask
            h_mask = holdout_eval_mask & ~np.isnan(holdout_persist)
            mae_clim = float(np.mean(err_clim[h_mask]))
            mae_pers = float(np.mean(err_pers[h_mask]))

            winner = selected_model_per_h[h]
            mae_selected = mae_pers if winner == "persistence" else mae_clim
            err_selected = err_pers if winner == "persistence" else err_clim

            # Paired block bootstrap: Delta = MAE(selected) - MAE(climatology)
            d_mae, ci_low, ci_high = paired_block_bootstrap(err_selected[h_mask], err_clim[h_mask])
            skill_pct = ((mae_clim - mae_selected) / mae_clim) * 100.0 if mae_clim > 0 else 0.0

            holdout_results.append({
                "horizon_h": h,
                "lead_from_last_obs_h": lead,
                "selected_model": winner,
                "holdout_mae_selected": mae_selected,
                "holdout_mae_clim_ref": mae_clim,
                "holdout_mae_persistence": mae_pers,
                "delta_mae": d_mae,
                "delta_ci_95_lower": ci_low,
                "delta_ci_95_upper": ci_high,
                "skill_pct_vs_clim": skill_pct
            })

        results[var] = {
            "cv_summary": cv_summary,
            "holdout_benchmark": holdout_results
        }

        # Print table
        unit = "deg" if is_circ else ("hPa" if var == "slp" else "m/s")
        print(f"\n--- {var.upper()} HOLDOUT BENCHMARK TABLE ({unit}) ---")
        h_df = pd.DataFrame(holdout_results)
        print(h_df[["horizon_h", "lead_from_last_obs_h", "selected_model", "holdout_mae_selected", "holdout_mae_clim_ref", "delta_mae", "delta_ci_95_lower", "delta_ci_95_upper", "skill_pct_vs_clim"]].to_string(index=False, float_format="%.4f"))

    # Save to report JSON
    out_dir = SAFETY_DIR / "reports" / "baselines"
    out_dir.mkdir(parents=True, exist_ok=True)
    out_json = out_dir / "era5_baseline_report.json"
    with open(out_json, "w") as f:
        json.dump(results, f, indent=2)
    print(f"\n[REPORT] Saved full ERA5 baseline benchmark to {out_json}")


if __name__ == "__main__":
    run_era5_baselines()
