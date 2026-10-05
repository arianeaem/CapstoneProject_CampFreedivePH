"""
Rigorous Multi-Seed Ablation Benchmark with 168h Block Bootstrap Confidence Intervals.

Evaluates out-of-fold CV MAE across walk-forward folds for:
1. Wave Significant Height (Hs) across 4 Wave Folds.
2. Eulerian Current Speed across 6 Current Folds.

Methodological Controls:
- Strict 240h purge margin between fold training and validation.
- All climatological mappings and feature scalers fitted strictly on training data per fold.
- Identical model architecture and hyperparameters (Ridge regression alpha=100.0, linear baseline).
- Multi-seed evaluation across 5 random seeds (42, 123, 456, 789, 2026).
- Paired 168h stationary block bootstrap (1,000 resamples) on error differences:
  * Delta(B - A) = |Error(Model B)| - |Error(Model A)| (Tests ERA5 120h value)
  * Delta(A - Clim) = |Error(Model A)| - |Error(Climatology)| (Tests if Model A has genuine skill over climatology)
"""

import sys
import json
from pathlib import Path
import numpy as np
import pandas as pd
from sklearn.linear_model import Ridge

SAFETY_DIR = Path(__file__).resolve().parents[1]
if str(SAFETY_DIR / "src" / "ingest") not in sys.path:
    sys.path.insert(0, str(SAFETY_DIR / "src" / "ingest"))

from splits import WAVES_WALK_FORWARD_FOLDS, CURRENTS_WALK_FORWARD_FOLDS

HORIZONS = [1, 3, 6, 12, 24, 48, 72, 120, 168, 240]
SEEDS = [42, 123, 456, 789, 2026]
BLOCK_SIZE = 168  # 7 days synoptic block length
N_BOOT = 1000


def paired_block_bootstrap_diff(err1: np.ndarray, err2: np.ndarray,
                                block_size: int = BLOCK_SIZE,
                                n_boot: int = N_BOOT,
                                alpha: float = 0.05):
    """Moving block bootstrap on paired error differences err1 - err2."""
    diff = err1 - err2
    diff = diff[~np.isnan(diff)]
    n = len(diff)
    if n < block_size * 2:
        m = float(np.mean(diff)) if n > 0 else 0.0
        return m, m, m

    max_start = n - block_size
    n_blocks = int(np.ceil(n / block_size))
    rng = np.random.default_rng(seed=42)

    boot_means = np.empty(n_boot)
    for b in range(n_boot):
        starts = rng.integers(0, max_start + 1, size=n_blocks)
        idx = np.concatenate([np.arange(s, s + block_size) for s in starts])[:n]
        boot_means[b] = np.mean(diff[idx])

    mean_val = float(np.mean(diff))
    ci_low = float(np.percentile(boot_means, 100 * (alpha / 2)))
    ci_high = float(np.percentile(boot_means, 100 * (1 - alpha / 2)))
    return mean_val, ci_low, ci_high


def build_ablation_dataset():
    waves = pd.read_parquet(SAFETY_DIR / "data" / "snapshots" / "2026-10-04_rev4" / "cmems_waves.parquet")
    currents = pd.read_parquet(SAFETY_DIR / "data" / "snapshots" / "2026-10-04_rev4" / "cmems_currents.parquet")
    era5 = pd.read_parquet(SAFETY_DIR / "data" / "snapshots" / "2026-10-04_rev4" / "era5_wind_pressure.parquet")

    waves = waves[~waves["is_provisional"]].copy()
    currents = currents[~currents["is_provisional"]].copy()
    era5 = era5[~era5["is_provisional"]].copy()

    idx = currents.index.intersection(era5.index)
    df = pd.DataFrame(index=idx)

    # Targets
    df["hs"] = waves["hs"].reindex(idx).interpolate(method="time")
    df["eulerian_speed"] = currents["eulerian_speed"].reindex(idx)

    # Wave features (strictly >= 12h lag)
    df["hs_lag_12h"] = waves["hs"].reindex(idx).shift(12)
    df["hs_lag_24h"] = waves["hs"].reindex(idx).shift(24)
    df["hs_roll_mean_24h"] = waves["hs"].reindex(idx).shift(12).rolling(24).mean()

    # Current features (strictly >= 24h lag)
    df["eul_lag_24h"] = currents["eulerian_speed"].reindex(idx).shift(24)
    df["eul_lag_48h"] = currents["eulerian_speed"].reindex(idx).shift(48)
    df["eul_roll_mean_24h"] = currents["eulerian_speed"].reindex(idx).shift(24).rolling(24).mean()
    df["tide_speed_lag_24h"] = currents["tide_speed"].reindex(idx).shift(24)

    # Calendar features
    df["doy_sin"] = np.sin(2 * np.pi * idx.dayofyear / 365.25)
    df["doy_cos"] = np.cos(2 * np.pi * idx.dayofyear / 365.25)
    df["hod_sin"] = np.sin(2 * np.pi * idx.hour / 24.0)
    df["hod_cos"] = np.cos(2 * np.pi * idx.hour / 24.0)

    # ERA5 features (strictly >= 120h operational lag)
    df["wind_speed_lag_120h"] = era5["wind_speed"].reindex(idx).shift(120)
    df["wind_gust_lag_120h"] = era5["wind_gust"].reindex(idx).shift(120)
    df["slp_lag_120h"] = era5["slp"].reindex(idx).shift(120)
    df["wind_u_lag_120h"] = era5["wind_u"].reindex(idx).shift(120)
    df["wind_v_lag_120h"] = era5["wind_v"].reindex(idx).shift(120)
    df["tau_y_lag_120h"] = (era5["wind_v"] * era5["wind_speed"]).reindex(idx).shift(120)
    df["wind_roll_mean_24h"] = era5["wind_speed"].reindex(idx).shift(120).rolling(24).mean()

    return df


def run_rigorous_ablation():
    print("=" * 100)
    print("RIGOROUS MULTI-SEED ABLATION BENCHMARK WITH 168H BLOCK BOOTSTRAP")
    print("Seeds:", SEEDS, "| Purge Margin: 240h | Block Bootstrap:", BLOCK_SIZE, "h (1,000 resamples)")
    print("=" * 100)

    df = build_ablation_dataset()

    base_wave_feats = ["hs_lag_12h", "hs_lag_24h", "hs_roll_mean_24h", "doy_sin", "doy_cos", "hod_sin", "hod_cos"]
    era5_feats = ["wind_speed_lag_120h", "wind_gust_lag_120h", "slp_lag_120h", "wind_u_lag_120h", "wind_v_lag_120h", "tau_y_lag_120h", "wind_roll_mean_24h"]
    base_curr_feats = ["eul_lag_24h", "eul_lag_48h", "eul_roll_mean_24h", "tide_speed_lag_24h", "doy_sin", "doy_cos", "hod_sin", "hod_cos"]

    # -------------------------------------------------------------------------
    # 1. WAVE HS EVALUATION
    # -------------------------------------------------------------------------
    print("\n>>> 1. WAVE SIGNIFICANT HEIGHT (Hs) ABLATION (4 FOLDS, 5 SEEDS) <<<")
    wave_summary = []
    for h in HORIZONS:
        all_err_a = []
        all_err_b = []
        all_err_clim = []
        all_err_pers = []

        for f_info in WAVES_WALK_FORWARD_FOLDS:
            t_start = pd.Timestamp(f_info["train_start"], tz="UTC")
            t_end = pd.Timestamp(f_info["train_end"], tz="UTC")
            v_start = pd.Timestamp(f_info["val_start"], tz="UTC")
            v_end = pd.Timestamp(f_info["val_end"], tz="UTC")

            # Verify 240h purge margin
            actual_purge = (v_start - t_end).total_seconds() / 3600.0
            assert actual_purge >= 240, f"Purge violation: {actual_purge}h < 240h"

            train = df[(df.index.tz_convert("UTC") >= t_start) & (df.index.tz_convert("UTC") <= t_end)].copy()
            val = df[(df.index.tz_convert("UTC") >= v_start) & (df.index.tz_convert("UTC") <= v_end)].copy()

            y_train = train["hs"].shift(-h).dropna()
            X_train = train.loc[y_train.index]

            y_val = val["hs"].shift(-h).dropna()
            X_val = val.loc[y_val.index]

            # Fit Climatology strictly on fold train
            clim_map = train.groupby([train.index.month, train.index.hour])["hs"].mean()
            y_clim_val = np.array([clim_map.get((t.month, t.hour), np.nan) for t in y_val.index])
            y_pers_val = X_val["hs_lag_12h"].values

            err_clim_fold = np.abs(y_clim_val - y_val.values)
            err_pers_fold = np.abs(y_pers_val - y_val.values)

            # Multi-seed models
            preds_a_seeds = []
            preds_b_seeds = []
            for seed in SEEDS:
                reg_a = Ridge(alpha=100.0, random_state=seed)
                reg_a.fit(X_train[base_wave_feats].fillna(0), y_train)
                preds_a_seeds.append(reg_a.predict(X_val[base_wave_feats].fillna(0)))

                reg_b = Ridge(alpha=100.0, random_state=seed)
                reg_b.fit(X_train[base_wave_feats + era5_feats].fillna(0), y_train)
                preds_b_seeds.append(reg_b.predict(X_val[base_wave_feats + era5_feats].fillna(0)))

            pred_a = np.mean(preds_a_seeds, axis=0)
            pred_b = np.mean(preds_b_seeds, axis=0)

            all_err_a.append(np.abs(pred_a - y_val.values))
            all_err_b.append(np.abs(pred_b - y_val.values))
            all_err_clim.append(err_clim_fold)
            all_err_pers.append(err_pers_fold)

        concat_a = np.concatenate(all_err_a)
        concat_b = np.concatenate(all_err_b)
        concat_c = np.concatenate(all_err_clim)
        concat_p = np.concatenate(all_err_pers)

        mae_a = float(np.mean(concat_a))
        mae_b = float(np.mean(concat_b))
        mae_c = float(np.mean(concat_c))
        mae_p = float(np.mean(concat_p))

        # Bootstrap: B vs A (Does 120h ERA5 help Model A?)
        diff_ba, ci_ba_l, ci_ba_u = paired_block_bootstrap_diff(concat_b, concat_a)
        # Bootstrap: A vs Clim (Does Model A beat Climatology?)
        diff_ac, ci_ac_l, ci_ac_u = paired_block_bootstrap_diff(concat_a, concat_c)

        wave_summary.append({
            "Horizon": f"{h}h",
            "CV_Clim_MAE": mae_c,
            "CV_Pers_MAE": mae_p,
            "Model_A_MAE": mae_a,
            "Model_B_MAE": mae_b,
            "Delta_BA": diff_ba,
            "Delta_BA_CI": f"[{ci_ba_l:+.4f}, {ci_ba_u:+.4f}]",
            "Delta_A_vs_Clim": diff_ac,
            "Delta_AC_CI": f"[{ci_ac_l:+.4f}, {ci_ac_u:+.4f}]",
            "A_Skill_vs_Clim_%": ((mae_c - mae_a) / mae_c) * 100.0
        })

    w_df = pd.DataFrame(wave_summary)
    print(w_df[["Horizon", "CV_Clim_MAE", "Model_A_MAE", "Model_B_MAE", "Delta_BA", "Delta_BA_CI", "Delta_A_vs_Clim", "Delta_AC_CI", "A_Skill_vs_Clim_%"]].to_string(index=False))

    # -------------------------------------------------------------------------
    # 2. EULERIAN CURRENT SPEED EVALUATION
    # -------------------------------------------------------------------------
    print("\n>>> 2. EULERIAN CURRENT SPEED ABLATION (6 FOLDS, 5 SEEDS) <<<")
    curr_summary = []
    for h in HORIZONS:
        all_err_a = []
        all_err_b = []
        all_err_clim = []
        all_err_pers = []

        for f_info in CURRENTS_WALK_FORWARD_FOLDS:
            t_start = pd.Timestamp(f_info["train_start"], tz="UTC")
            t_end = pd.Timestamp(f_info["train_end"], tz="UTC")
            v_start = pd.Timestamp(f_info["val_start"], tz="UTC")
            v_end = pd.Timestamp(f_info["val_end"], tz="UTC")

            actual_purge = (v_start - t_end).total_seconds() / 3600.0
            assert actual_purge >= 240, f"Purge violation: {actual_purge}h < 240h"

            train = df[(df.index.tz_convert("UTC") >= t_start) & (df.index.tz_convert("UTC") <= t_end)].copy()
            val = df[(df.index.tz_convert("UTC") >= v_start) & (df.index.tz_convert("UTC") <= v_end)].copy()

            y_train = train["eulerian_speed"].shift(-h).dropna()
            X_train = train.loc[y_train.index]

            y_val = val["eulerian_speed"].shift(-h).dropna()
            X_val = val.loc[y_val.index]

            clim_map = train.groupby([train.index.month, train.index.hour])["eulerian_speed"].mean()
            y_clim_val = np.array([clim_map.get((t.month, t.hour), np.nan) for t in y_val.index])
            y_pers_val = X_val["eul_lag_24h"].values

            err_clim_fold = np.abs(y_clim_val - y_val.values)
            err_pers_fold = np.abs(y_pers_val - y_val.values)

            preds_a_seeds = []
            preds_b_seeds = []
            for seed in SEEDS:
                reg_a = Ridge(alpha=100.0, random_state=seed)
                reg_a.fit(X_train[base_curr_feats].fillna(0), y_train)
                preds_a_seeds.append(reg_a.predict(X_val[base_curr_feats].fillna(0)))

                reg_b = Ridge(alpha=100.0, random_state=seed)
                reg_b.fit(X_train[base_curr_feats + era5_feats].fillna(0), y_train)
                preds_b_seeds.append(reg_b.predict(X_val[base_curr_feats + era5_feats].fillna(0)))

            pred_a = np.mean(preds_a_seeds, axis=0)
            pred_b = np.mean(preds_b_seeds, axis=0)

            all_err_a.append(np.abs(pred_a - y_val.values))
            all_err_b.append(np.abs(pred_b - y_val.values))
            all_err_clim.append(err_clim_fold)
            all_err_pers.append(err_pers_fold)

        concat_a = np.concatenate(all_err_a)
        concat_b = np.concatenate(all_err_b)
        concat_c = np.concatenate(all_err_clim)
        concat_p = np.concatenate(all_err_pers)

        mae_a = float(np.mean(concat_a))
        mae_b = float(np.mean(concat_b))
        mae_c = float(np.mean(concat_c))
        mae_p = float(np.mean(concat_p))

        diff_ba, ci_ba_l, ci_ba_u = paired_block_bootstrap_diff(concat_b, concat_a)
        diff_ac, ci_ac_l, ci_ac_u = paired_block_bootstrap_diff(concat_a, concat_c)

        curr_summary.append({
            "Horizon": f"{h}h",
            "CV_Clim_MAE": mae_c,
            "CV_Pers_MAE": mae_p,
            "Model_A_MAE": mae_a,
            "Model_B_MAE": mae_b,
            "Delta_BA": diff_ba,
            "Delta_BA_CI": f"[{ci_ba_l:+.4f}, {ci_ba_u:+.4f}]",
            "Delta_A_vs_Clim": diff_ac,
            "Delta_AC_CI": f"[{ci_ac_l:+.4f}, {ci_ac_u:+.4f}]",
            "A_Skill_vs_Clim_%": ((mae_c - mae_a) / mae_c) * 100.0
        })

    c_df = pd.DataFrame(curr_summary)
    print(c_df[["Horizon", "CV_Clim_MAE", "Model_A_MAE", "Model_B_MAE", "Delta_BA", "Delta_BA_CI", "Delta_A_vs_Clim", "Delta_AC_CI", "A_Skill_vs_Clim_%"]].to_string(index=False))


if __name__ == "__main__":
    run_rigorous_ablation()
