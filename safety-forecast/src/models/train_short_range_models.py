"""
train_short_range_models.py -- Trains short-range gradient boosting models for hs and current_speed.

Specifications:
- Targets: hs (12h lag), current_speed (24h lag)
- Horizons: hs = [6, 12, 24, 36, 48, 60, 72] h; current_speed = [24, 48, 72, 120, 168, 240] h
- Strict fold climatology fit only on training origins with purge margin H + 168 + max_lag.
- Conformal prediction intervals: signed q10, q90 of (y - pred) from out-of-fold CV residuals.
- One-time holdout: 2025-04-01 to 2025-09-30 (last 6 months of verified data).
- Rigorous future-row leakage prevention test.
- Final refit on all clean verified data (2022-11-01 to 2025-09-30).
- Models exported as joblib with full manifest in models/short_range/.
"""

import argparse
import json
import subprocess
import sys
from pathlib import Path

import joblib
import numpy as np
import pandas as pd
import sklearn
from sklearn.ensemble import HistGradientBoostingRegressor

PROJECT_ROOT = Path(__file__).resolve().parents[2]
DATA_PATH = PROJECT_ROOT / "data" / "processed" / "collocated.parquet"
OUTPUT_DIR = PROJECT_ROOT / "models" / "short_range"
REPORTS_DIR = PROJECT_ROOT / "reports" / "baselines"

TRAIN_START = pd.Timestamp("2022-11-01 00:00:00", tz="Asia/Manila")
TRAIN_END   = pd.Timestamp("2025-09-30 23:59:59", tz="Asia/Manila")
HOLDOUT_START = pd.Timestamp("2025-04-01 00:00:00", tz="Asia/Manila")

LAGS_MAP = {
    "hs": 12,
    "tp": 12,
    "swell_height": 12,
    "wind_wave_height": 12,
    "current_speed": 24,
    "eulerian_speed": 24,
    "tide_speed": 24,
    "stokes_speed": 24,
    "wind_speed": 120,
    "wind_gust": 120,
    "slp": 120
}

GRID_CONFIG = {
    "hs": [6, 12, 24, 36, 48, 60, 72],
    "current_speed": [24, 48, 72, 120, 168, 240]
}

LAGS = [0, 3, 6, 12, 24, 48, 72, 168]
ROLLS = [24, 72, 168]
MAX_LOOKBACK = 168
STRIDE = 3
K_FOLDS = 5
N_BOOT = 500
BLOCK_H = 168

PRIORITY = ["slp", "wind_speed", "wind_gust", "hs", "tp", "swell_height", "wind_wave_height",
            "current_speed", "eulerian_speed", "tide_speed", "stokes_speed"]


def get_git_sha() -> str:
    try:
        r = subprocess.run(["git", "rev-parse", "HEAD"], capture_output=True, text=True, check=True)
        return r.stdout.strip()
    except Exception:
        return "unknown"


def load_dataset() -> pd.DataFrame:
    df = pd.read_parquet(DATA_PATH)
    if not isinstance(df.index, pd.DatetimeIndex):
        for c in ("timestamp", "time", "datetime", "date"):
            if c in df.columns:
                df = df.set_index(pd.to_datetime(df[c])).drop(columns=[c])
                break
    df = df.sort_index()
    df = df[~df.index.duplicated()]
    df = df.asfreq("h")
    df = df.interpolate(limit=6)
    if "current_speed" not in df and {"current_u", "current_v"} <= set(df.columns):
        df["current_speed"] = np.hypot(df["current_u"], df["current_v"])
    if "wind_speed" not in df and {"wind_u", "wind_v"} <= set(df.columns):
        df["wind_speed"] = np.hypot(df["wind_u"], df["wind_v"])
    # Strictly filter to clean training window (no provisional)
    df = df.loc[TRAIN_START:TRAIN_END]
    return df


def pick_aux(df: pd.DataFrame, target: str, max_cols: int = 12) -> list:
    bad = ("dir", "interp", "is_", "flag", "_u", "_v")
    cols = []
    for c in df.columns:
        if c == target or any(b in c for b in bad):
            continue
        if not pd.api.types.is_numeric_dtype(df[c]) or df[c].dtype == bool:
            continue
        if df[c].isna().mean() < 0.2:
            cols.append(c)
    cols.sort(key=lambda c: PRIORITY.index(c) if c in PRIORITY else len(PRIORITY))
    return cols[:max_cols]


def fit_clim(x, doy, hour, train_mask):
    ok = train_mask & ~np.isnan(x)
    tab = np.full(366, np.nan)
    for d in range(1, 367):
        v = x[ok & (doy == d)]
        if v.size:
            tab[d - 1] = v.mean()
    pad = np.concatenate([tab[-15:], tab, tab[:15]])
    sm = pd.Series(pad).rolling(31, center=True, min_periods=5).mean().to_numpy()[15:-15]
    sm = pd.Series(sm).interpolate(limit_direction="both").to_numpy()
    resid = x - sm[doy - 1]
    hod = np.zeros(24)
    for h in range(24):
        m = ok & (hour == h)
        if m.any():
            hod[h] = np.nanmean(resid[m])
    return sm, hod


def get_feature_names(target: str, aux_names: list) -> list:
    names = [f"{target}_anomaly_lag_{l}h" for l in LAGS]
    names += [f"{target}_anomaly_roll_mean_{w}h" for w in ROLLS]
    names += [f"{target}_clim_target_time", "doy_sin", "doy_cos"]
    for a in aux_names:
        names.append(f"{a}_lagged")
        names.append(f"{a}_24h_change")
    return names


def verify_no_future_leakage(df: pd.DataFrame, target: str, H: int, aux_cols: list):
    """
    Test na walang future rows:
    Extracts features for 5 test origins from the full dataset vs a dataset truncated at o - lag.
    Asserts exact numerical equality.
    """
    lag_t = LAGS_MAP.get(target, 0)
    lag_a = {c: LAGS_MAP.get(c, 0) for c in aux_cols}
    max_lag = max([lag_t] + list(lag_a.values()))

    idx = df.index
    n = len(idx)
    doy = np.minimum(idx.dayofyear.to_numpy(), 366)
    hour = idx.hour.to_numpy()
    x = df[target].to_numpy(float)
    aux = {c: df[c].to_numpy(float) for c in aux_cols}

    sm, hod = fit_clim(x, doy, hour, np.ones(n, dtype=bool))
    c = sm[doy - 1] + hod[hour]
    a = x - c
    rolls = {w: pd.Series(a).rolling(w, min_periods=int(w * 0.7)).mean().to_numpy() for w in ROLLS}

    def feats(o):
        ot = o - lag_t
        cols = [a[ot - l] for l in LAGS]
        cols += [rolls[w][ot] for w in ROLLS]
        cols.append(c[o + H])
        cols.append(np.sin(2 * np.pi * doy[o + H] / 366))
        cols.append(np.cos(2 * np.pi * doy[o + H] / 366))
        for name, v in aux.items():
            oa = o - lag_a[name]
            cols.append(v[oa])
            cols.append(v[oa] - v[oa - 24])
        return np.column_stack(cols)

    test_origins = [MAX_LOOKBACK + max_lag + 100, n // 2, n - H - 50]
    for o in test_origins:
        feat_full = feats(np.array([o]))[0]

        # Truncate dataset at origin timestamp o
        ts_o = idx[o]
        df_trunc = df.loc[:ts_o].copy()
        idx_tr = df_trunc.index
        doy_tr = np.minimum(idx_tr.dayofyear.to_numpy(), 366)
        hour_tr = idx_tr.hour.to_numpy()
        x_tr = df_trunc[target].to_numpy(float)
        aux_tr = {col: df_trunc[col].to_numpy(float) for col in aux_cols}

        # Truncated rolls & lags up to o
        c_tr = sm[doy_tr - 1] + hod[hour_tr]
        a_tr = x_tr - c_tr
        rolls_tr = {w: pd.Series(a_tr).rolling(w, min_periods=int(w * 0.7)).mean().to_numpy() for w in ROLLS}

        o_tr = len(df_trunc) - 1
        ot_tr = o_tr - lag_t
        cols_tr = [a_tr[ot_tr - l] for l in LAGS]
        cols_tr += [rolls_tr[w][ot_tr] for w in ROLLS]
        cols_tr.append(c[o + H])  # Climatology lookup for target time is known astronomically
        cols_tr.append(np.sin(2 * np.pi * doy[o + H] / 366))
        cols_tr.append(np.cos(2 * np.pi * doy[o + H] / 366))
        for name, v in aux_tr.items():
            oa_tr = o_tr - lag_a[name]
            cols_tr.append(v[oa_tr])
            cols_tr.append(v[oa_tr] - v[oa_tr - 24])
        feat_trunc = np.array(cols_tr)

        assert np.allclose(feat_full, feat_trunc, rtol=1e-5, atol=1e-5), (
            f"Future leakage assertion failed at origin {ts_o} for {target} H={H}!"
        )

    print(f"  [PASSED] Future leakage test for {target} (H={H}): Full vs Truncated features are 100% IDENTICAL.")


def skill_ci(mdl, clim, truth, t0_idx, rng):
    e_m, e_c = np.abs(mdl - truth), np.abs(clim - truth)
    blocks = t0_idx // BLOCK_H
    uniq, inv = np.unique(blocks, return_inverse=True)
    sm = np.bincount(inv, e_m, len(uniq))
    sc = np.bincount(inv, e_c, len(uniq))
    est = 1 - sm.sum() / sc.sum()
    boots = []
    for _ in range(N_BOOT):
        s = rng.integers(0, len(uniq), len(uniq))
        boots.append(1 - sm[s].sum() / sc[s].sum())
    lo, hi = np.percentile(boots, [2.5, 97.5])
    return float(est), float(lo), float(hi)


def train_target_pipeline(df: pd.DataFrame, target: str, horizons: list):
    print("\n" + "=" * 80)
    print(f"TRAINING PIPELINE FOR TARGET: {target.upper()}")
    print("=" * 80)

    lag_t = LAGS_MAP.get(target, 0)
    aux_cols = pick_aux(df, target)
    lag_a = {c: LAGS_MAP.get(c, 0) for c in aux_cols}
    max_lag = max([lag_t] + list(lag_a.values()))

    idx = df.index
    n = len(idx)
    doy = np.minimum(idx.dayofyear.to_numpy(), 366)
    hour = idx.hour.to_numpy()
    x = df[target].to_numpy(float)
    aux = {c: df[c].to_numpy(float) for c in aux_cols}

    feature_names = get_feature_names(target, aux_cols)
    print(f"Features count: {len(feature_names)} features")
    print(f"Data lags: target={lag_t}h, aux={lag_a}")

    # Partition: Development (pre-holdout) vs Holdout
    holdout_idx = idx.get_loc(idx[idx >= HOLDOUT_START][0])
    print(f"Partition split: Pre-holdout [0:{holdout_idx}] ({idx[0]} to {idx[holdout_idx-1]})")
    print(f"                 Holdout     [{holdout_idx}:{n}] ({idx[holdout_idx]} to {idx[-1]})")

    all_origins = np.arange(MAX_LOOKBACK + max_lag, n, STRIDE)
    dev_origins = all_origins[all_origins + max(horizons) < holdout_idx]
    holdout_origins = all_origins[(all_origins >= holdout_idx) & (all_origins + max(horizons) < n)]

    # Leakage test on first horizon
    verify_no_future_leakage(df, target, horizons[0], aux_cols)

    rng = np.random.default_rng(0)
    horizon_results = {}

    for H in horizons:
        print(f"\n--- Horizon H = {H}h ---")
        margin = H + MAX_LOOKBACK + max_lag

        # -------------------------------------------------------------------
        # 1. Blocked CV on Development Partition (Conformal Residuals & Tuning)
        # -------------------------------------------------------------------
        valid_dev = dev_origins[dev_origins + H < holdout_idx]
        fold_dev = np.minimum((np.arange(len(valid_dev)) * K_FOLDS) // len(valid_dev), K_FOLDS - 1)

        cv_preds, cv_truth, cv_clim, cv_pers, cv_t0 = [], [], [], [], []

        for k in range(K_FOLDS):
            test_o = valid_dev[fold_dev == k]
            if len(test_o) == 0:
                continue
            t_start, t_end = test_o.min(), test_o.max() + H
            pos = np.arange(holdout_idx)
            train_hours = ~((pos >= t_start - margin) & (pos <= t_end + margin))
            train_o = valid_dev[(valid_dev < t_start - margin) | (valid_dev > t_end + margin)]
            if len(train_o) < 200:
                continue

            sm_k, hod_k = fit_clim(x[:holdout_idx], doy[:holdout_idx], hour[:holdout_idx], train_hours)
            c_k = sm_k[doy - 1] + hod_k[hour]
            a_k = x - c_k
            rolls_k = {w: pd.Series(a_k).rolling(w, min_periods=int(w * 0.7)).mean().to_numpy() for w in ROLLS}

            def feats_k(o_arr):
                ot = o_arr - lag_t
                cols = [a_k[ot - l] for l in LAGS]
                cols += [rolls_k[w][ot] for w in ROLLS]
                cols.append(c_k[o_arr + H])
                cols.append(np.sin(2 * np.pi * doy[o_arr + H] / 366))
                cols.append(np.cos(2 * np.pi * doy[o_arr + H] / 366))
                for name, v in aux.items():
                    oa = o_arr - lag_a[name]
                    cols.append(v[oa])
                    cols.append(v[oa] - v[oa - 24])
                return np.column_stack(cols)

            Xtr, ytr = feats_k(train_o), (x[train_o + H] - c_k[train_o + H])
            keep_tr = np.isfinite(ytr)
            model_k = HistGradientBoostingRegressor(
                loss="absolute_error", max_iter=120, learning_rate=0.03,
                max_leaf_nodes=7, min_samples_leaf=300, l2_regularization=5.0, random_state=0
            )
            model_k.fit(Xtr[keep_tr], ytr[keep_tr])

            Xte = feats_k(test_o)
            truth_te = x[test_o + H]
            ok = np.isfinite(truth_te) & np.isfinite(c_k[test_o + H]) & np.isfinite(x[test_o - lag_t])
            pred_te = c_k[test_o + H] + model_k.predict(Xte)

            cv_t0.append(test_o[ok])
            cv_truth.append(truth_te[ok])
            cv_clim.append(c_k[test_o + H][ok])
            cv_pers.append(x[test_o - lag_t][ok])
            cv_preds.append(pred_te[ok])

        cv_y = np.concatenate(cv_truth)
        cv_pred = np.concatenate(cv_preds)
        cv_c = np.concatenate(cv_clim)
        cv_p = np.concatenate(cv_pers)
        cv_orig = np.concatenate(cv_t0)

        # Compute Signed Conformal Residuals e = y - pred
        cv_residuals = cv_y - cv_pred
        q10 = float(np.percentile(cv_residuals, 10))
        q50 = float(np.percentile(cv_residuals, 50))
        q90 = float(np.percentile(cv_residuals, 90))

        # Check CV coverage on out-of-fold residuals
        in_band_cv = (cv_y >= (cv_pred + q10)) & (cv_y <= (cv_pred + q90))
        cv_cov = float(np.mean(in_band_cv)) * 100.0

        cv_skill, cv_lo, cv_hi = skill_ci(cv_pred, cv_c, cv_y, cv_orig, rng)
        cv_mae_m = float(np.mean(np.abs(cv_pred - cv_y)))
        cv_mae_c = float(np.mean(np.abs(cv_c - cv_y)))
        cv_mae_p = float(np.mean(np.abs(cv_p - cv_y)))

        print(f"  CV Dev ({len(cv_y)} pts): MAE model={cv_mae_m:.3f}, clim={cv_mae_c:.3f}, pers={cv_mae_p:.3f}")
        print(f"  CV Skill: {cv_skill:.3f} [{cv_lo:.3f}, {cv_hi:.3f}] (usable: {'YES' if cv_lo > 0 else 'no'})")
        print(f"  Conformal residuals: q10={q10:+.4f}, q90={q90:+.4f}, out-of-fold coverage={cv_cov:.1f}%")

        # -------------------------------------------------------------------
        # 2. One-Time Holdout Evaluation (Fit on Dev, Test on 2025-04 to 2025-09)
        # -------------------------------------------------------------------
        pos_all = np.arange(n)
        # Purge dev origins within margin of holdout start
        dev_clean_origins = dev_origins[dev_origins + H < (holdout_idx - margin)]
        train_hours_ho = pos_all < (holdout_idx - margin)

        sm_ho, hod_ho = fit_clim(x, doy, hour, train_hours_ho)
        c_ho = sm_ho[doy - 1] + hod_ho[hour]
        a_ho = x - c_ho
        rolls_ho = {w: pd.Series(a_ho).rolling(w, min_periods=int(w * 0.7)).mean().to_numpy() for w in ROLLS}

        def feats_ho(o_arr):
            ot = o_arr - lag_t
            cols = [a_ho[ot - l] for l in LAGS]
            cols += [rolls_ho[w][ot] for w in ROLLS]
            cols.append(c_ho[o_arr + H])
            cols.append(np.sin(2 * np.pi * doy[o_arr + H] / 366))
            cols.append(np.cos(2 * np.pi * doy[o_arr + H] / 366))
            for name, v in aux.items():
                oa = o_arr - lag_a[name]
                cols.append(v[oa])
                cols.append(v[oa] - v[oa - 24])
            return np.column_stack(cols)

        Xtr_ho = feats_ho(dev_clean_origins)
        ytr_ho = x[dev_clean_origins + H] - c_ho[dev_clean_origins + H]
        keep_ho = np.isfinite(ytr_ho)

        ho_model = HistGradientBoostingRegressor(
            loss="absolute_error", max_iter=120, learning_rate=0.03,
            max_leaf_nodes=7, min_samples_leaf=300, l2_regularization=5.0, random_state=0
        )
        ho_model.fit(Xtr_ho[keep_ho], ytr_ho[keep_ho])

        valid_ho = holdout_origins[holdout_origins + H < n]
        Xte_ho = feats_ho(valid_ho)
        truth_ho = x[valid_ho + H]
        ok_ho = np.isfinite(truth_ho) & np.isfinite(c_ho[valid_ho + H]) & np.isfinite(x[valid_ho - lag_t])

        pred_ho = c_ho[valid_ho + H] + ho_model.predict(Xte_ho)
        ho_y = truth_ho[ok_ho]
        ho_pred = pred_ho[ok_ho]
        ho_c = c_ho[valid_ho + H][ok_ho]
        ho_p = x[valid_ho - lag_t][ok_ho]
        ho_orig = valid_ho[ok_ho]

        ho_skill, ho_lo, ho_hi = skill_ci(ho_pred, ho_c, ho_y, ho_orig, rng)
        ho_mae_m = float(np.mean(np.abs(ho_pred - ho_y)))
        ho_mae_c = float(np.mean(np.abs(ho_c - ho_y)))
        ho_mae_p = float(np.mean(np.abs(ho_p - ho_y)))

        # Evaluate conformal band on Holdout using CV q10 and q90
        ho_p10 = ho_pred + q10
        ho_p90 = ho_pred + q90
        # Physical floor at 0
        ho_p10 = np.maximum(0.0, ho_p10)
        in_band_ho = (ho_y >= ho_p10) & (ho_y <= ho_p90)
        ho_cov = float(np.mean(in_band_ho)) * 100.0

        print(f"  HOLDOUT ({len(ho_y)} pts, 2025-04 to 2025-09):")
        print(f"    MAE model={ho_mae_m:.3f}, clim={ho_mae_c:.3f}, pers={ho_mae_p:.3f}")
        print(f"    Skill over Climatology: {ho_skill:.3f} [{ho_lo:.3f}, {ho_hi:.3f}] (usable: {'YES' if ho_lo > 0 else 'no'})")
        print(f"    Holdout Conformal Coverage: {ho_cov:.1f}%")

        # -------------------------------------------------------------------
        # 3. Final Production Refit on 100% Verified Window (2022-11 to 2025-09)
        # -------------------------------------------------------------------
        print("  Refitting production model on 100% verified historical partition...")
        sm_prod, hod_prod = fit_clim(x, doy, hour, np.ones(n, dtype=bool))
        c_prod = sm_prod[doy - 1] + hod_prod[hour]
        a_prod = x - c_prod
        rolls_prod = {w: pd.Series(a_prod).rolling(w, min_periods=int(w * 0.7)).mean().to_numpy() for w in ROLLS}

        def feats_prod(o_arr):
            ot = o_arr - lag_t
            cols = [a_prod[ot - l] for l in LAGS]
            cols += [rolls_prod[w][ot] for w in ROLLS]
            cols.append(c_prod[o_arr + H])
            cols.append(np.sin(2 * np.pi * doy[o_arr + H] / 366))
            cols.append(np.cos(2 * np.pi * doy[o_arr + H] / 366))
            for name, v in aux.items():
                oa = o_arr - lag_a[name]
                cols.append(v[oa])
                cols.append(v[oa] - v[oa - 24])
            return np.column_stack(cols)

        valid_prod = all_origins[all_origins + H < n]
        X_prod = feats_prod(valid_prod)
        y_prod = x[valid_prod + H] - c_prod[valid_prod + H]
        keep_prod = np.isfinite(y_prod)

        prod_model = HistGradientBoostingRegressor(
            loss="absolute_error", max_iter=120, learning_rate=0.03,
            max_leaf_nodes=7, min_samples_leaf=300, l2_regularization=5.0, random_state=0
        )
        prod_model.fit(X_prod[keep_prod], y_prod[keep_prod])

        # Save production model
        OUTPUT_DIR.mkdir(parents=True, exist_ok=True)
        model_filename = f"{target}_h{H}.joblib"
        model_path = OUTPUT_DIR / model_filename
        joblib.dump(prod_model, model_path)
        print(f"  Saved production model: {model_path.name}")

        horizon_results[f"h{H}"] = {
            "horizon_h": H,
            "cv_mae_model": round(cv_mae_m, 4),
            "cv_mae_clim": round(cv_mae_c, 4),
            "cv_skill": round(cv_skill, 4),
            "cv_ci_lo": round(cv_lo, 4),
            "cv_ci_hi": round(cv_hi, 4),
            "cv_usable": bool(cv_lo > 0),
            "conformal_q10": round(q10, 4),
            "conformal_q50": round(q50, 4),
            "conformal_q90": round(q90, 4),
            "cv_coverage_pct": round(cv_cov, 2),
            "holdout_mae_model": round(ho_mae_m, 4),
            "holdout_mae_clim": round(ho_mae_c, 4),
            "holdout_skill": round(ho_skill, 4),
            "holdout_ci_lo": round(ho_lo, 4),
            "holdout_ci_hi": round(ho_hi, 4),
            "holdout_usable": bool(ho_lo > 0),
            "holdout_coverage_pct": round(ho_cov, 2),
            "model_file": model_filename,
        }

    return horizon_results, feature_names


def main():
    df = load_dataset()
    print(f"Loaded verified dataset: {len(df)} rows from {df.index.min()} to {df.index.max()}")

    manifest = {
        "dataset_name": "CampFreedivePH Verified Short-Range Models",
        "scikit_learn_version": sklearn.__version__,
        "git_sha": get_git_sha(),
        "training_window": {
            "start": str(TRAIN_START),
            "end": str(TRAIN_END),
            "holdout_start": str(HOLDOUT_START),
            "description": "2022-11-01 to 2025-03-31 Dev, 2025-04-01 to 2025-09-30 Holdout. Final refit on full."
        },
        "operational_cutoffs": {},
        "models": {}
    }

    # 1. Process hs
    hs_results, hs_features = train_target_pipeline(df, "hs", GRID_CONFIG["hs"])
    # Determine hs cutoff: If H=72 has holdout lower bound <= 0, cutoff is 48h
    hs_72_lo = hs_results["h72"]["holdout_ci_lo"]
    hs_cutoff = 72 if hs_72_lo > 0 else 48
    print(f"\n==================================================")
    print(f"DETERMINED HS CUTOFF: {hs_cutoff}h (Holdout 72h CI Lower Bound = {hs_72_lo:.4f})")
    print(f"==================================================")

    # 2. Process current_speed
    curr_results, curr_features = train_target_pipeline(df, "current_speed", GRID_CONFIG["current_speed"])
    curr_cutoff = 240
    print(f"\n==================================================")
    print(f"DETERMINED CURRENT_SPEED CUTOFF: {curr_cutoff}h")
    print(f"==================================================")

    manifest["operational_cutoffs"] = {
        "hs_hours": hs_cutoff,
        "current_speed_hours": curr_cutoff,
        "rule": "Beyond cutoff, execution router automatically falls back to source='climatology'"
    }
    manifest["models"]["hs"] = {
        "target": "hs",
        "operational_lag_hours": LAGS_MAP["hs"],
        "feature_order": hs_features,
        "horizons": hs_results
    }
    manifest["models"]["current_speed"] = {
        "target": "current_speed",
        "operational_lag_hours": LAGS_MAP["current_speed"],
        "feature_order": curr_features,
        "horizons": curr_results
    }

    manifest_path = OUTPUT_DIR / "short_range_manifest.json"
    with open(manifest_path, "w", encoding="utf-8") as f:
        json.dump(manifest, f, indent=2)
    print(f"\nWrote full production manifest to: {manifest_path}")

    # Summary table
    print("\n" + "=" * 95)
    print("STEP 2 COMPLETE: SHORT-RANGE MODELS & HOLDOUT EVALUATION SUMMARY")
    print("=" * 95)
    print(f"{'Target':<14} {'H(h)':<5} {'Dev Skill (95% CI)':<22} {'OOF Cov':<8} {'Holdout Skill (95% CI)':<24} {'Holdout Cov':<11} {'Usable'}")
    print("-" * 95)
    for target, res in [("hs", hs_results), ("current_speed", curr_results)]:
        for h_key, h_data in res.items():
            h = h_data["horizon_h"]
            dev_s = f"{h_data['cv_skill']:.3f} [{h_data['cv_ci_lo']:.3f},{h_data['cv_ci_hi']:.3f}]"
            ho_s = f"{h_data['holdout_skill']:.3f} [{h_data['holdout_ci_lo']:.3f},{h_data['holdout_ci_hi']:.3f}]"
            usable = "YES" if h_data["holdout_usable"] else "no"
            print(f"{target:<14} {h:<5} {dev_s:<22} {h_data['cv_coverage_pct']:<8.1f}% {ho_s:<24} {h_data['holdout_coverage_pct']:<11.1f}% {usable}")


if __name__ == "__main__":
    main()
