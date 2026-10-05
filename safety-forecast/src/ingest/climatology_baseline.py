"""
Climatology and persistence baselines (PRD 3: the model must beat climatology).

Checks:
1. Climatology by day of year (+/- 15 days) and hour of day (PHT):
   - fit on the training split only (never the holdout)
   - scored on the same timestamps for every horizon
2. Persistence:
   - lead time:
     * horizon_from_issue: h in [1, 3, 6, 12, 24, 48, 72, 120, 168, 192, 240] hours
     * lead_from_last_obs: h + operational_lag (PRD 7.4)
   - formula: y_persist(t) = y(t - (h + lag))
3. Statistics:
   - block bootstrap (168h / 7 day blocks, 1,000 samples) on
     Delta_MAE = |err_candidate| - |err_clim|
   - 95% CI for Delta_MAE (significant if the upper end < 0)
   - skill = 1 - MAE_candidate / MAE_climatology
4. Note about tides:
   - for currents, persistence MAE drops at 1h (lookback 25h ~ 2 tide cycles), 12h (36h ~ 3 cycles)
     and 24h (48h ~ 4 cycles), but jumps at 6h (lookback 30h ~ 2.5 cycles, out of phase).
   - this up-and-down pattern comes from the tide, not real skill.
"""

from typing import Dict, List, Tuple, Any
import json
from pathlib import Path
import numpy as np
import pandas as pd

from config import DATA_ROOT, TARGET_TIMEZONE, OPERATIONAL_LAGS
from training_eligibility import load_snapshot_dataset
from splits import get_train_holdout_split

HORIZONS_H = [1, 3, 6, 12, 24, 48, 72, 120, 168, 192, 240]
OUT_BASELINES_DIR = DATA_ROOT / "processed" / "baselines"
OUT_REPORTS_DIR = DATA_ROOT.parent / "reports" / "baselines"
OUT_BASELINES_DIR.mkdir(parents=True, exist_ok=True)
OUT_REPORTS_DIR.mkdir(parents=True, exist_ok=True)


def circular_angular_difference(deg1: np.ndarray, deg2: np.ndarray) -> np.ndarray:
    """Absolute angle error in degrees [0, 180]."""
    diff = np.abs(deg1 - deg2) % 360.0
    return np.where(diff > 180.0, 360.0 - diff, diff)


def block_bootstrap_mae(errors: np.ndarray, block_size: int = 168, n_boot: int = 1000) -> Tuple[float, float, float]:
    """Block bootstrap 95% CI for one MAE."""
    n = len(errors)
    point_mae = float(np.mean(np.abs(errors)))
    if n < block_size * 2:
        return point_mae, point_mae, point_mae

    n_blocks = int(np.ceil(n / block_size))
    rng = np.random.default_rng(42)
    boot_maes = []
    
    max_start = n - block_size
    for _ in range(n_boot):
        starts = rng.integers(0, max_start + 1, size=n_blocks)
        sample_blocks = [errors[s:s + block_size] for s in starts]
        boot_sample = np.concatenate(sample_blocks)[:n]
        boot_maes.append(np.mean(np.abs(boot_sample)))

    ci_lower = float(np.percentile(boot_maes, 2.5))
    ci_upper = float(np.percentile(boot_maes, 97.5))
    return point_mae, ci_lower, ci_upper


def paired_block_bootstrap_mae(
    err_cand: np.ndarray,
    err_base: np.ndarray,
    block_size: int = 168,
    n_boot: int = 1000
) -> Tuple[float, float, float, float, float]:
    """
    Paired block bootstrap 95% CI on Delta_MAE = |err_cand| - |err_base|.
    Returns:
    (mae_cand, mae_base, delta_mae, ci_lower_delta, ci_upper_delta)
    If ci_upper_delta < 0, cand is significantly better than base (p < 0.05).
    """
    assert len(err_cand) == len(err_base)
    n = len(err_cand)
    cand_abs = np.abs(err_cand)
    base_abs = np.abs(err_base)
    diff = cand_abs - base_abs

    mae_cand = float(np.mean(cand_abs))
    mae_base = float(np.mean(base_abs))
    delta_mae = float(np.mean(diff))

    if n < block_size * 2:
        return mae_cand, mae_base, delta_mae, delta_mae, delta_mae

    n_blocks = int(np.ceil(n / block_size))
    rng = np.random.default_rng(42)
    boot_diffs = []
    max_start = n - block_size

    for _ in range(n_boot):
        starts = rng.integers(0, max_start + 1, size=n_blocks)
        sample_blocks = [diff[s:s + block_size] for s in starts]
        boot_sample = np.concatenate(sample_blocks)[:n]
        boot_diffs.append(np.mean(boot_sample))

    ci_lower = float(np.percentile(boot_diffs, 2.5))
    ci_upper = float(np.percentile(boot_diffs, 97.5))
    return mae_cand, mae_base, delta_mae, ci_lower, ci_upper


class SmoothClimatologyModel:
    """
    Fit the day-of-year (+/- 15 days) and hour-of-day climatology on the training set.
    """
    def __init__(self, window_days: int = 15):
        self.window_days = window_days
        self.climatology_table_: Dict[Tuple[int, int], Dict[str, float]] = {}
        self.variables_: List[str] = []

    def fit(self, train_df: pd.DataFrame, variables: List[str]):
        self.variables_ = variables
        df = train_df.copy()
        
        idx_pht = df.index.tz_convert(TARGET_TIMEZONE)
        df["doy"] = idx_pht.dayofyear
        df["hod"] = idx_pht.hour

        print(f"Fitting Smooth DOY Climatology (+/- {self.window_days}d window) for {variables}...")
        for doy in range(1, 367):
            min_doy = (doy - self.window_days - 1) % 365 + 1
            max_doy = (doy + self.window_days - 1) % 365 + 1

            if min_doy <= max_doy:
                doy_mask = (df["doy"] >= min_doy) & (df["doy"] <= max_doy)
            else:
                doy_mask = (df["doy"] >= min_doy) | (df["doy"] <= max_doy)

            for hod in range(24):
                cell_mask = doy_mask & (df["hod"] == hod)
                sub = df.loc[cell_mask]
                
                means = {}
                for v in variables:
                    val_series = sub[v].dropna() if v in sub else pd.Series(dtype=float)
                    if len(val_series) >= 5:
                        means[v] = float(val_series.mean())
                    else:
                        window_vals = df.loc[doy_mask, v].dropna()
                        means[v] = float(window_vals.mean()) if len(window_vals) > 0 else 0.0

                self.climatology_table_[(doy, hod)] = means

        return self

    def predict(self, eval_df: pd.DataFrame) -> pd.DataFrame:
        idx_pht = eval_df.index.tz_convert(TARGET_TIMEZONE)
        doys = idx_pht.dayofyear.values
        hods = idx_pht.hour.values

        preds = {v: np.zeros(len(eval_df), dtype=float) for v in self.variables_}
        for i in range(len(eval_df)):
            key = (doys[i], hods[i])
            row_dict = self.climatology_table_.get(key, {})
            for v in self.variables_:
                preds[v][i] = row_dict.get(v, 0.0)

        pred_df = pd.DataFrame(preds, index=eval_df.index)
        return pred_df


def evaluate_baselines_for_source(source: str, targets: List[str], dir_var: str = None):
    print("\n" + "=" * 80)
    print(f"EVALUATING BASELINES FOR {source.upper()} ON HOLDOUT SPLIT")
    print("=" * 80)

    # 1. Load the snapshot rev2 data
    df_raw = load_snapshot_dataset(source)
    train_df, holdout_df = get_train_holdout_split(df_raw)
    
    print(f"Dataset:  {source}")
    print(f"Training: {len(train_df):,} rows ({train_df.index.min().tz_convert('UTC')} to {train_df.index.max().tz_convert('UTC')})")
    print(f"Holdout:  {len(holdout_df):,} rows ({holdout_df.index.min().tz_convert('UTC')} to {holdout_df.index.max().tz_convert('UTC')})")

    # 2. Fit climatology on training only
    clim_model = SmoothClimatologyModel(window_days=15)
    clim_model.fit(train_df, targets)
    clim_preds_holdout = clim_model.predict(holdout_df)

    # 3. Persistence for each horizon
    lag = OPERATIONAL_LAGS[source.lower()]
    lag_hours = int(lag.total_seconds() / 3600)

    eval_horizons = HORIZONS_H
    if source.lower() == "waves":
        eval_horizons = [h for h in HORIZONS_H if h >= 3]

    persist_results = []
    predictions_record = {
        "time_utc": holdout_df.index.tz_convert("UTC"),
        "time_pht": holdout_df.index.tz_convert(TARGET_TIMEZONE),
    }
    for var in targets:
        predictions_record[f"{var}_actual"] = holdout_df[var].values
        predictions_record[f"{var}_clim"] = clim_preds_holdout[var].values

    for h in eval_horizons:
        lead_obs = h + lag_hours
        total_lookback = pd.Timedelta(hours=lead_obs)
        
        target_times = holdout_df.index
        lookback_times = target_times - total_lookback

        persist_vals = df_raw[targets].reindex(lookback_times)
        persist_vals.index = target_times

        for var in targets:
            predictions_record[f"{var}_persist_h{h}"] = persist_vals[var].values

            actual = holdout_df[var].values
            p_cand = persist_vals[var].values
            p_clim = clim_preds_holdout[var].values

            # Only the timestamps where actual, persistence and climatology all have values
            valid = ~np.isnan(actual) & ~np.isnan(p_cand) & ~np.isnan(p_clim)
            
            err_cand = actual[valid] - p_cand[valid]
            err_clim = actual[valid] - p_clim[valid]

            block_size_rows = 56 if source.lower() == "waves" else 168
            mae_cand, mae_clim, delta_mae, d_low, d_high = paired_block_bootstrap_mae(err_cand, err_clim, block_size=block_size_rows)
            _, c_low, c_high = block_bootstrap_mae(err_cand, block_size=block_size_rows)
            
            skill = 1.0 - (mae_cand / mae_clim) if mae_clim > 0 else 0.0
            stat_sig = bool(d_high < 0.0 or d_low > 0.0)

            persist_results.append({
                "source": source,
                "variable": var,
                "horizon_from_issue": h,
                "lead_from_last_obs": lead_obs,
                "operational_lag_h": lag_hours,
                "n_samples": int(valid.sum()),
                "mae_persistence": round(mae_cand, 4),
                "ci_lower_cand": round(c_low, 4),
                "ci_upper_cand": round(c_high, 4),
                "mae_climatology_exact_sample": round(mae_clim, 4),
                "delta_mae": round(delta_mae, 4),
                "paired_ci_lower": round(d_low, 4),
                "paired_ci_upper": round(d_high, 4),
                "skill_vs_climatology": round(skill, 4),
                "statistically_significant": stat_sig
            })

    # Summary table
    res_df = pd.DataFrame(persist_results)
    csv_out = OUT_REPORTS_DIR / f"{source}_baseline_metrics.csv"
    res_df.to_csv(csv_out, index=False)
    print(f"[Saved Metrics CSV]  -> {csv_out}")
    
    # Save the predictions (parquet)
    pred_df = pd.DataFrame(predictions_record).set_index("time_utc")
    parquet_out = OUT_BASELINES_DIR / f"{source}_baseline_predictions.parquet"
    pred_df.to_parquet(parquet_out)
    print(f"[Saved Predictions] -> {parquet_out}")

    return res_df


def run_all_baselines():
    # Waves Option A
    res_waves = evaluate_baselines_for_source("waves", ["hs", "tp", "swell_height", "wind_wave_height"])

    # Currents
    res_curr = evaluate_baselines_for_source("currents", ["current_speed"])

    print("\n" + "=" * 80)
    print("ALL CLIMATOLOGY & PERSISTENCE BASELINES EVALUATED")
    print("=" * 80)


if __name__ == "__main__":
    run_all_baselines()
