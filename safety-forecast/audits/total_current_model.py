"""
Tests the total current model over the 6 walk-forward CV folds.

Split into parts:
  V_total = Eulerian forecast + harmonic tide + Stokes climatology

As vectors:
  u_total = u_eul + u_tide + u_stokes
  v_total = v_eul + v_tide + v_stokes
  Speed = sqrt(u_total^2 + v_total^2)

Scored in m/s and knots (kt = m/s * 1.943844) over the 6 folds
with a 240h gap and no data leakage.
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

from splits import CURRENTS_WALK_FORWARD_FOLDS
from harmonic_tide_baseline import HarmonicTideModel

HORIZONS = [1, 3, 6, 12, 24, 48, 72, 120, 168, 240]
MS_TO_KT = 1.943844


def run_total_current_benchmark():
    print("=" * 105, flush=True)
    print("PHYSICAL TOTAL CURRENT MODEL: EULERIAN FORECAST + HARMONIC TIDE + STOKES CLIMATOLOGY", flush=True)
    print("Evaluated across 6 Walk-Forward CV Folds (240h purge margin, no leakage)", flush=True)
    print(f"Reporting metrics in BOTH m/s and knots (1 m/s = {MS_TO_KT:.4f} kt)", flush=True)
    print("=" * 105, flush=True)

    currents_path = SAFETY_DIR / "data" / "snapshots" / "2026-10-04_rev4" / "cmems_currents.parquet"
    df = pd.read_parquet(currents_path)
    df = df[~df["is_provisional"]].copy()

    # Features known at t (24h late)
    idx = df.index
    feat_df = pd.DataFrame(index=idx)

    # Eulerian velocity lags (lag >= 24h)
    feat_df["eul_u_lag_24h"] = df["eulerian_u"].shift(24)
    feat_df["eul_v_lag_24h"] = df["eulerian_v"].shift(24)
    feat_df["eul_u_lag_48h"] = df["eulerian_u"].shift(48)
    feat_df["eul_v_lag_48h"] = df["eulerian_v"].shift(48)
    feat_df["eul_u_roll_24h"] = df["eulerian_u"].shift(24).rolling(24).mean()
    feat_df["eul_v_roll_24h"] = df["eulerian_v"].shift(24).rolling(24).mean()

    # Total speed lag (24h)
    feat_df["curr_speed_lag_24h"] = df["current_speed"].shift(24)

    # Date sin/cos features
    feat_df["doy_sin"] = np.sin(2 * np.pi * idx.dayofyear / 365.25)
    feat_df["doy_cos"] = np.cos(2 * np.pi * idx.dayofyear / 365.25)
    feat_df["hod_sin"] = np.sin(2 * np.pi * idx.hour / 24.0)
    feat_df["hod_cos"] = np.cos(2 * np.pi * idx.hour / 24.0)

    eul_features = [
        "eul_u_lag_24h", "eul_v_lag_24h", "eul_u_lag_48h", "eul_v_lag_48h",
        "eul_u_roll_24h", "eul_v_roll_24h", "doy_sin", "doy_cos", "hod_sin", "hod_cos"
    ]

    # Fit the tide and climatology models once per fold
    print("Fitting tidal & climatological models across 6 folds...", flush=True)
    fold_models = {}
    for f_info in CURRENTS_WALK_FORWARD_FOLDS:
        fold_id = f_info["fold"]
        t_start = pd.Timestamp(f_info["train_start"], tz="UTC")
        t_end = pd.Timestamp(f_info["train_end"], tz="UTC")
        v_start = pd.Timestamp(f_info["val_start"], tz="UTC")
        v_end = pd.Timestamp(f_info["val_end"], tz="UTC")

        # Check the gap
        purge_h = (v_start - t_end).total_seconds() / 3600.0
        assert purge_h >= 240, f"Purge violation in fold {fold_id}: {purge_h} < 240"

        train_df = df[(df.index >= t_start) & (df.index <= t_end)].copy()
        val_df = df[(df.index >= v_start) & (df.index <= v_end)].copy()

        tide_model = HarmonicTideModel()
        tide_model.fit(train_df)

        # Climatology fit on the fold's training data only
        stk_u_clim = train_df.groupby([train_df.index.month, train_df.index.hour])["stokes_u"].mean().to_dict()
        stk_v_clim = train_df.groupby([train_df.index.month, train_df.index.hour])["stokes_v"].mean().to_dict()
        eul_u_clim = train_df.groupby([train_df.index.month, train_df.index.hour])["eulerian_u"].mean().to_dict()
        eul_v_clim = train_df.groupby([train_df.index.month, train_df.index.hour])["eulerian_v"].mean().to_dict()
        tot_speed_clim = train_df.groupby([train_df.index.month, train_df.index.hour])["current_speed"].mean().to_dict()

        fold_models[fold_id] = {
            "train_df": train_df,
            "val_df": val_df,
            "tide_model": tide_model,
            "stk_u_clim": stk_u_clim,
            "stk_v_clim": stk_v_clim,
            "eul_u_clim": eul_u_clim,
            "eul_v_clim": eul_v_clim,
            "tot_speed_clim": tot_speed_clim,
        }
        print(f"  Fold {fold_id} fitted (Train N={len(train_df)}, Val N={len(val_df)})", flush=True)

    results_table = []

    print("\nEvaluating horizons...", flush=True)
    for h in HORIZONS:
        all_err_decomp_ml_ms = []
        all_err_decomp_clim_ms = []
        all_err_decomp_pers_ms = []
        all_err_direct_clim_ms = []
        all_err_total_pers_ms = []

        for f_info in CURRENTS_WALK_FORWARD_FOLDS:
            fold_id = f_info["fold"]
            fm = fold_models[fold_id]
            train_df = fm["train_df"]
            val_df = fm["val_df"]

            # Train targets at t + h
            y_u_eul_train = train_df["eulerian_u"].shift(-h).dropna()
            y_v_eul_train = train_df["eulerian_v"].shift(-h).dropna()
            c_train_idx = y_u_eul_train.index.intersection(y_v_eul_train.index)

            X_train = feat_df.loc[c_train_idx, eul_features].fillna(0)
            y_u_train = y_u_eul_train.loc[c_train_idx]
            y_v_train = y_v_eul_train.loc[c_train_idx]

            # Validation targets at t + h
            y_u_eul_val = val_df["eulerian_u"].shift(-h).dropna()
            y_v_eul_val = val_df["eulerian_v"].shift(-h).dropna()
            c_val_idx = y_u_eul_val.index.intersection(y_v_eul_val.index)

            target_timestamps = c_val_idx + pd.Timedelta(hours=h)
            true_total_speed = df.loc[target_timestamps, "current_speed"].values

            # Tide prediction at the target times
            val_target_df = pd.DataFrame(index=target_timestamps)
            u_tide_pred, v_tide_pred = fm["tide_model"].predict(val_target_df)

            # Stokes climatology
            stk_u_map = fm["stk_u_clim"]
            stk_v_map = fm["stk_v_clim"]
            u_stk_pred = np.array([stk_u_map.get((ts.month, ts.hour), 0.0) for ts in target_timestamps])
            v_stk_pred = np.array([stk_v_map.get((ts.month, ts.hour), 0.0) for ts in target_timestamps])

            # Eulerian climatology
            eul_u_map = fm["eul_u_clim"]
            eul_v_map = fm["eul_v_clim"]
            u_eul_clim = np.array([eul_u_map.get((ts.month, ts.hour), 0.0) for ts in target_timestamps])
            v_eul_clim = np.array([eul_v_map.get((ts.month, ts.hour), 0.0) for ts in target_timestamps])

            # Eulerian ML model (Ridge, alpha=100.0)
            X_val = feat_df.loc[c_val_idx, eul_features].fillna(0)
            reg_u = Ridge(alpha=100.0, random_state=42)
            reg_v = Ridge(alpha=100.0, random_state=42)
            reg_u.fit(X_train, y_u_train)
            reg_v.fit(X_train, y_v_train)

            u_eul_ml = reg_u.predict(X_val)
            v_eul_ml = reg_v.predict(X_val)

            # Eulerian persistence (24h before the forecast time t)
            u_eul_pers = feat_df.loc[c_val_idx, "eul_u_lag_24h"].values
            v_eul_pers = feat_df.loc[c_val_idx, "eul_v_lag_24h"].values

            # Total current model = Eulerian ML + tide + Stokes clim
            u_tot_ml = u_eul_ml + u_tide_pred + u_stk_pred
            v_tot_ml = v_eul_ml + v_tide_pred + v_stk_pred
            speed_tot_ml = np.sqrt(u_tot_ml**2 + v_tot_ml**2)

            # Split climatology = Eulerian clim + tide + Stokes clim
            u_tot_clim = u_eul_clim + u_tide_pred + u_stk_pred
            v_tot_clim = v_eul_clim + v_tide_pred + v_stk_pred
            speed_tot_clim = np.sqrt(u_tot_clim**2 + v_tot_clim**2)

            # Split persistence = Eulerian persistence + tide + Stokes clim
            u_tot_pers = u_eul_pers + u_tide_pred + u_stk_pred
            v_tot_pers = v_eul_pers + v_tide_pred + v_stk_pred
            speed_tot_pers = np.sqrt(u_tot_pers**2 + v_tot_pers**2)

            # Simple baselines
            tot_speed_map = fm["tot_speed_clim"]
            direct_speed_clim = np.array([tot_speed_map.get((ts.month, ts.hour), np.nan) for ts in target_timestamps])
            direct_speed_pers = feat_df.loc[c_val_idx, "curr_speed_lag_24h"].values

            # Collect the errors
            all_err_decomp_ml_ms.append(np.abs(speed_tot_ml - true_total_speed))
            all_err_decomp_clim_ms.append(np.abs(speed_tot_clim - true_total_speed))
            all_err_decomp_pers_ms.append(np.abs(speed_tot_pers - true_total_speed))
            all_err_direct_clim_ms.append(np.abs(direct_speed_clim - true_total_speed))
            all_err_total_pers_ms.append(np.abs(direct_speed_pers - true_total_speed))

        # Join all 6 folds
        err_ml = np.concatenate(all_err_decomp_ml_ms)
        err_dclim = np.concatenate(all_err_decomp_clim_ms)
        err_dpers = np.concatenate(all_err_decomp_pers_ms)
        err_clim = np.concatenate(all_err_direct_clim_ms)
        err_pers = np.concatenate(all_err_total_pers_ms)

        mae_ml_ms = float(np.mean(err_ml))
        mae_dclim_ms = float(np.mean(err_dclim))
        mae_dpers_ms = float(np.mean(err_dpers))
        mae_clim_ms = float(np.mean(err_clim))
        mae_pers_ms = float(np.mean(err_pers))

        mae_ml_kt = mae_ml_ms * MS_TO_KT
        mae_dclim_kt = mae_dclim_ms * MS_TO_KT
        mae_dpers_kt = mae_dpers_ms * MS_TO_KT
        mae_clim_kt = mae_clim_ms * MS_TO_KT
        mae_pers_kt = mae_pers_ms * MS_TO_KT

        skill_pct = ((mae_dclim_ms - mae_ml_ms) / mae_dclim_ms) * 100.0

        results_table.append({
            "Horizon": f"{h}h",
            "Total_Model_ms": mae_ml_ms,
            "Total_Model_kt": mae_ml_kt,
            "Decomp_Clim_ms": mae_dclim_ms,
            "Decomp_Clim_kt": mae_dclim_kt,
            "Decomp_Pers_ms": mae_dpers_ms,
            "Decomp_Pers_kt": mae_dpers_kt,
            "Direct_Clim_ms": mae_clim_ms,
            "Direct_Clim_kt": mae_clim_kt,
            "Total_Pers_ms": mae_pers_ms,
            "Total_Pers_kt": mae_pers_kt,
            "Skill_vs_DecompClim_%": skill_pct
        })
        print(f"  Horizon {h:3d}h complete | Total Model: {mae_ml_ms:.4f} m/s ({mae_ml_kt:.4f} kt) | Decomp Clim: {mae_dclim_ms:.4f} m/s ({mae_dclim_kt:.4f} kt) | Skill: {skill_pct:+.2f}%", flush=True)

    res_df = pd.DataFrame(results_table)
    print("\n--- RESULTS: TOTAL CURRENT MODEL VS BASELINES ACROSS 6 CV FOLDS ---", flush=True)
    headers = [
        "Horizon",
        "Total_Model_ms", "Total_Model_kt",
        "Decomp_Clim_ms", "Decomp_Clim_kt",
        "Decomp_Pers_ms", "Decomp_Pers_kt",
        "Direct_Clim_ms", "Direct_Clim_kt",
        "Skill_vs_DecompClim_%"
    ]
    print(res_df[headers].to_string(index=False, float_format=lambda x: f"{x:.4f}"), flush=True)

    # Save to reports
    out_dir = SAFETY_DIR / "reports" / "baselines"
    out_dir.mkdir(parents=True, exist_ok=True)
    res_df.to_json(out_dir / "total_current_model_cv_results.json", orient="records", indent=2)
    print(f"\nSaved benchmark results to {out_dir / 'total_current_model_cv_results.json'}", flush=True)


if __name__ == "__main__":
    run_total_current_benchmark()
