"""
Evaluates seasonal ML skill breakdown (Amihan vs Habagat vs Transition)
on the Development Cross-Validation partition (2022-11-01 to 2025-03-31).

Seasons:
- Amihan (Northeast Monsoon): Dec, Jan, Feb
- Habagat (Southwest Monsoon): Jun, Jul, Aug, Sep
- Summer / Transition: Mar, Apr, May, Oct, Nov
"""

import json
from pathlib import Path
import numpy as np
import pandas as pd
from sklearn.ensemble import HistGradientBoostingRegressor

PROJECT_ROOT = Path(__file__).resolve().parents[2]
DATA_PATH = PROJECT_ROOT / "data" / "processed" / "collocated.parquet"
REPORTS_DIR = PROJECT_ROOT / "reports" / "baselines"

TRAIN_START = pd.Timestamp("2022-11-01 00:00:00", tz="Asia/Manila")
TRAIN_END   = pd.Timestamp("2025-09-30 23:59:59", tz="Asia/Manila")
HOLDOUT_START = pd.Timestamp("2025-04-01 00:00:00", tz="Asia/Manila")

LAGS_MAP = {
    "hs": 12, "tp": 12, "swell_height": 12, "wind_wave_height": 12,
    "current_speed": 24, "eulerian_speed": 24, "tide_speed": 24, "stokes_speed": 24,
    "wind_speed": 120, "wind_gust": 120, "slp": 120
}

TARGETS_CONFIG = {
    "hs": [6, 12, 24, 36, 48],
    "current_speed": [24, 48, 72]
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


def get_season(month: int) -> str:
    if month in [12, 1, 2]:
        return "Amihan (NE Monsoon)"
    elif month in [6, 7, 8, 9]:
        return "Habagat (SW Monsoon)"
    else:
        return "Summer / Transition"


def skill_ci(mdl, clim, t0_idx, rng):
    e_m, e_c = np.abs(mdl), np.abs(clim)
    blocks = t0_idx // BLOCK_H
    uniq, inv = np.unique(blocks, return_inverse=True)
    sm = np.bincount(inv, e_m, len(uniq))
    sc = np.bincount(inv, e_c, len(uniq))
    sum_sc = sc.sum()
    if sum_sc == 0:
        return 0.0, 0.0, 0.0
    est = 1 - sm.sum() / sum_sc
    boots = []
    for _ in range(N_BOOT):
        s = rng.integers(0, len(uniq), len(uniq))
        sc_s = sc[s].sum()
        if sc_s > 0:
            boots.append(1 - sm[s].sum() / sc_s)
    if len(boots) > 0:
        lo, hi = np.percentile(boots, [2.5, 97.5])
    else:
        lo, hi = est, est
    return float(est), float(lo), float(hi)


def main():
    df = load_dataset()
    idx = df.index
    n = len(idx)
    doy = np.minimum(idx.dayofyear.to_numpy(), 366)
    hour = idx.hour.to_numpy()

    holdout_idx = idx.get_loc(idx[idx >= HOLDOUT_START][0])
    print(f"Dataset: {n} hours total. Holdout starts at index {holdout_idx} ({idx[holdout_idx]}).")

    all_origins = np.arange(MAX_LOOKBACK + 120, n, STRIDE)
    dev_origins = all_origins[all_origins + 72 < holdout_idx]

    rng = np.random.default_rng(42)
    results = []

    for target, horizons in TARGETS_CONFIG.items():
        print(f"\n=======================================================")
        print(f"Evaluating Seasonal Skill for {target.upper()}")
        print(f"=======================================================")
        lag_t = LAGS_MAP.get(target, 0)
        aux_cols = pick_aux(df, target)
        lag_a = {c: LAGS_MAP.get(c, 0) for c in aux_cols}
        max_lag = max([lag_t] + list(lag_a.values()))

        x = df[target].to_numpy(float)
        aux = {c: df[c].to_numpy(float) for c in aux_cols}

        for H in horizons:
            margin = H + MAX_LOOKBACK + max_lag
            valid_dev = dev_origins[dev_origins + H < holdout_idx]
            fold_dev = np.minimum((np.arange(len(valid_dev)) * K_FOLDS) // len(valid_dev), K_FOLDS - 1)

            cv_preds, cv_truth, cv_clim, cv_t0, cv_target_time = [], [], [], [], []

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
                cv_preds.append(pred_te[ok])
                cv_target_time.append(idx[test_o[ok] + H])

            all_t0 = np.concatenate(cv_t0)
            all_truth = np.concatenate(cv_truth)
            all_clim = np.concatenate(cv_clim)
            all_preds = np.concatenate(cv_preds)
            all_target_ts = np.concatenate(cv_target_time)

            df_eval = pd.DataFrame({
                "t0": all_t0,
                "truth": all_truth,
                "clim": all_clim,
                "pred": all_preds,
                "target_time": all_target_ts
            })
            df_eval["month"] = pd.to_datetime(df_eval["target_time"]).dt.month
            df_eval["season"] = df_eval["month"].map(get_season)

            # Overall
            err_m = np.abs(df_eval["pred"] - df_eval["truth"])
            err_c = np.abs(df_eval["clim"] - df_eval["truth"])
            mae_m = float(err_m.mean())
            mae_c = float(err_c.mean())
            skill, s_lo, s_hi = skill_ci(err_m.values, err_c.values, df_eval["t0"].values, rng)

            results.append({
                "target": target,
                "horizon_h": H,
                "season": "Overall (Dev CV)",
                "n_samples": len(df_eval),
                "mae_model": round(mae_m, 4),
                "mae_clim": round(mae_c, 4),
                "skill_score": round(skill, 4),
                "skill_ci_lo": round(s_lo, 4),
                "skill_ci_hi": round(s_hi, 4)
            })

            # By Season
            for sname in ["Amihan (NE Monsoon)", "Habagat (SW Monsoon)", "Summer / Transition"]:
                sub = df_eval[df_eval["season"] == sname]
                if len(sub) == 0:
                    continue
                s_em = np.abs(sub["pred"] - sub["truth"])
                s_ec = np.abs(sub["clim"] - sub["truth"])
                s_mm = float(s_em.mean())
                s_mc = float(s_ec.mean())
                s_sk, s_l, s_h = skill_ci(s_em.values, s_ec.values, sub["t0"].values, rng)

                results.append({
                    "target": target,
                    "horizon_h": H,
                    "season": sname,
                    "n_samples": len(sub),
                    "mae_model": round(s_mm, 4),
                    "mae_clim": round(s_mc, 4),
                    "skill_score": round(s_sk, 4),
                    "skill_ci_lo": round(s_l, 4),
                    "skill_ci_hi": round(s_h, 4)
                })

    res_df = pd.DataFrame(results)
    print("\n" + "=" * 80)
    print("SEASONAL SKILL BREAKDOWN TABLE (DEV CV):")
    print("=" * 80)
    print(res_df.to_string(index=False))

    REPORTS_DIR.mkdir(parents=True, exist_ok=True)
    res_df.to_csv(REPORTS_DIR / "seasonal_skill_breakdown.csv", index=False)
    with open(REPORTS_DIR / "seasonal_skill_breakdown.json", "w", encoding="utf-8") as f:
        json.dump(results, f, indent=2)
    print(f"\nSaved seasonal breakdown to: {REPORTS_DIR / 'seasonal_skill_breakdown.json'}")


if __name__ == "__main__":
    main()
