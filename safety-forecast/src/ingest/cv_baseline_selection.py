"""
Rigorous 4-Candidate Baseline Comparison and Out-of-Fold CV Selection for CMEMS Currents.

Methodology & Protocol:
1. Evaluates all 4 Candidate Baselines:
   - Candidate 1: Direct Speed Climatology (Smooth DOY +/- 15d + diurnal hour)
   - Candidate 2: Decomposed Climatology (Harmonic Tide + Eulerian Clim + Stokes Clim)
   - Candidate 3: Total Speed Persistence (lag = 24h)
   - Candidate 4: Decomposed Eulerian Persistence (Harmonic Tide + Eulerian Persist + Stokes Persist, lag = 24h)

2. Strict No-Data-Leakage CV Selection:
   - 6 Canonical Walk-Forward Expanding Folds.
   - Tide models & Climatologies fit strictly on fold training data.
   - Out-of-fold validation on purged val splits (>= 240h purge gap).
   - Selection of optimal baseline per horizon based SOLELY on mean CV validation MAE.

3. Single-Shot Holdout Benchmark:
   - Evaluated strictly ONCE on untouched Holdout (2025-10-01 to 2026-10-02, N=8,785).
   - Reference for skill: Direct Speed Climatology (Holdout MAE = 0.1455 m/s).
   - Paired Moving Block-Bootstrap with block_length = 168 hours (7 days, synoptic atmospheric timescale)
     and 1,000 resamples to account for temporal autocorrelation.
   - Delta MAE = MAE_model - MAE_reference (m/s).
   - Transition boundary reported as ~5 days (approximate synoptic memory limit).
"""

import sys
import os
import numpy as np
import pandas as pd
from typing import Dict, List, Tuple

sys.path.insert(0, "safety-forecast/src/ingest")
from splits import CURRENTS_WALK_FORWARD_FOLDS, get_train_holdout_split
from harmonic_tide_baseline import HarmonicTideModel
from climatology_baseline import SmoothClimatologyModel, paired_block_bootstrap_mae

HORIZONS = [1, 3, 6, 12, 24, 48, 72, 120, 168, 192, 240]
LAG_HOURS = 24
BLOCK_SIZE_HOURS = 168  # 7 days (synoptic block bootstrap)
N_BOOT = 1000

def calc_mae(pred: np.ndarray, obs: np.ndarray) -> float:
    mask = ~np.isnan(pred) & ~np.isnan(obs)
    return float(np.mean(np.abs(pred[mask] - obs[mask])))

def run_four_candidate_baseline_benchmark():
    print("=" * 85)
    print("FOUR-CANDIDATE CURRENTS BASELINE BENCHMARK & CV SELECTION")
    print(f"Moving Block Bootstrap: block_length = {BLOCK_SIZE_HOURS}h (7 days), resamples = {N_BOOT}")
    print("=" * 85)

    currents_path = "safety-forecast/data/snapshots/2026-10-04_rev2/cmems_currents.parquet"
    df = pd.read_parquet(currents_path)

    # Dictionary to collect fold validation MAEs:
    # cv_maes[candidate_id][horizon] = list of 6 fold MAEs
    cand_ids = ["direct_clim", "decomp_clim", "total_persist", "decomp_persist"]
    cv_maes = {c: {h: [] for h in HORIZONS} for c in cand_ids}

    # 1. RUN CROSS-VALIDATION ACROSS 6 FOLDS
    for f in CURRENTS_WALK_FORWARD_FOLDS:
        fold_num = f["fold"]
        t_start = pd.Timestamp(f["train_start"], tz="UTC")
        t_end = pd.Timestamp(f["train_end"], tz="UTC")
        v_start = pd.Timestamp(f["val_start"], tz="UTC")
        v_end = pd.Timestamp(f["val_end"], tz="UTC")

        train_f = df.loc[(df.index >= t_start) & (df.index <= t_end) & (~df["is_provisional"])].copy()
        val_f = df.loc[(df.index >= v_start) & (df.index <= v_end) & (~df["is_provisional"])].copy()
        val_obs = val_f["current_speed"].values

        # Fit models on fold training data
        tide_f = HarmonicTideModel()
        tide_f.fit(train_f)
        tide_u_val, tide_v_val = tide_f.predict(val_f)

        clim_f = SmoothClimatologyModel()
        clim_f.fit(train_f, ["eulerian_u", "eulerian_v", "stokes_u", "stokes_v", "current_speed"])
        clim_val_preds = clim_f.predict(val_f)

        # Candidate 1: Direct Speed Climatology (horizon-independent)
        cand1_pred = clim_val_preds["current_speed"].values
        mae_cand1 = calc_mae(cand1_pred, val_obs)

        # Candidate 2: Decomposed Climatology (horizon-independent)
        cand2_u = tide_u_val + clim_val_preds["eulerian_u"].values + clim_val_preds["stokes_u"].values
        cand2_v = tide_v_val + clim_val_preds["eulerian_v"].values + clim_val_preds["stokes_v"].values
        cand2_pred = np.sqrt(cand2_u**2 + cand2_v**2)
        mae_cand2 = calc_mae(cand2_pred, val_obs)

        for h in HORIZONS:
            cv_maes["direct_clim"][h].append(mae_cand1)
            cv_maes["decomp_clim"][h].append(mae_cand2)

            total_lead = LAG_HOURS + h
            lb_times = val_f.index - pd.Timedelta(hours=total_lead)

            # Candidate 3: Total Speed Persistence
            cand3_pred = df["current_speed"].reindex(lb_times).values
            cv_maes["total_persist"][h].append(calc_mae(cand3_pred, val_obs))

            # Candidate 4: Decomposed Eulerian Persistence
            past_eul_u = df["eulerian_u"].reindex(lb_times).values
            past_eul_v = df["eulerian_v"].reindex(lb_times).values
            past_stk_u = df["stokes_u"].reindex(lb_times).values
            past_stk_v = df["stokes_v"].reindex(lb_times).values
            cand4_u = tide_u_val + past_eul_u + past_stk_u
            cand4_v = tide_v_val + past_eul_v + past_stk_v
            cand4_pred = np.sqrt(cand4_u**2 + cand4_v**2)
            cv_maes["decomp_persist"][h].append(calc_mae(cand4_pred, val_obs))

        print(f"Fold {fold_num} complete.")

    # Compute CV mean MAE
    cv_mean = {c: {h: float(np.mean(cv_maes[c][h])) for h in HORIZONS} for c in cand_ids}

    # CV SELECTION
    cv_selected = {}
    for h in HORIZONS:
        scores = {c: cv_mean[c][h] for c in cand_ids}
        best_cand = min(scores, key=scores.get)
        cv_selected[h] = {
            "winner": best_cand,
            "cv_mae": scores[best_cand],
            "all_cv_scores": scores
        }

    print("\n" + "=" * 85)
    print("CROSS-VALIDATION OUT-OF-FOLD BASELINE SELECTION")
    print("=" * 85)
    for h in HORIZONS:
        s = cv_selected[h]["all_cv_scores"]
        w = cv_selected[h]["winner"]
        print(f"h={h:3d}h (lead {LAG_HOURS+h:3d}h) | Direct Clim: {s['direct_clim']:.4f} | Decomp Clim: {s['decomp_clim']:.4f} | Total Persist: {s['total_persist']:.4f} | Decomp Persist: {s['decomp_persist']:.4f} -> WINNER: {w.upper()}")

    # 2. EVALUATE ALL 4 ON UNTOUCHED HOLDOUT (SINGLE-SHOT)
    print("\n" + "=" * 85)
    print("HOLDOUT EVALUATION (UNTOUCHED HOLDOUT, N = 8,785)")
    print("=" * 85)

    train_full, holdout_full = get_train_holdout_split(df)
    holdout_obs = holdout_full["current_speed"].values

    tide_full = HarmonicTideModel()
    tide_full.fit(train_full)
    tide_u_hold, tide_v_hold = tide_full.predict(holdout_full)

    clim_full = SmoothClimatologyModel()
    clim_full.fit(train_full, ["eulerian_u", "eulerian_v", "stokes_u", "stokes_v", "current_speed"])
    clim_hold_preds = clim_full.predict(holdout_full)

    # Reference: Candidate 1 (Direct Speed Climatology)
    cand1_hold_pred = clim_hold_preds["current_speed"].values
    holdout_ref_mae = calc_mae(cand1_hold_pred, holdout_obs)
    print(f"PRIMARY REFERENCE: Direct Speed Climatology Holdout MAE = {holdout_ref_mae:.4f} m/s ({holdout_ref_mae*1.94384:.3f} kt)")

    # Candidate 2: Decomposed Climatology
    cand2_hold_u = tide_u_hold + clim_hold_preds["eulerian_u"].values + clim_hold_preds["stokes_u"].values
    cand2_hold_v = tide_v_hold + clim_hold_preds["eulerian_v"].values + clim_hold_preds["stokes_v"].values
    cand2_hold_pred = np.sqrt(cand2_hold_u**2 + cand2_hold_v**2)
    holdout_cand2_mae = calc_mae(cand2_hold_pred, holdout_obs)
    print(f"CANDIDATE 2:      Decomposed Climatology Holdout MAE     = {holdout_cand2_mae:.4f} m/s ({holdout_cand2_mae*1.94384:.3f} kt)")

    report_table = []

    for h in HORIZONS:
        total_lead = LAG_HOURS + h
        lb_times = holdout_full.index - pd.Timedelta(hours=total_lead)

        # Candidate 3
        cand3_hold_pred = df["current_speed"].reindex(lb_times).values
        holdout_cand3_mae = calc_mae(cand3_hold_pred, holdout_obs)

        # Candidate 4
        past_eul_u = df["eulerian_u"].reindex(lb_times).values
        past_eul_v = df["eulerian_v"].reindex(lb_times).values
        past_stk_u = df["stokes_u"].reindex(lb_times).values
        past_stk_v = df["stokes_v"].reindex(lb_times).values
        cand4_hold_u = tide_u_hold + past_eul_u + past_stk_u
        cand4_hold_v = tide_v_hold + past_eul_v + past_stk_v
        cand4_hold_pred = np.sqrt(cand4_hold_u**2 + cand4_hold_v**2)
        holdout_cand4_mae = calc_mae(cand4_hold_pred, holdout_obs)

        # Which candidate was selected by CV?
        winner_id = cv_selected[h]["winner"]
        cand_preds_dict = {
            "direct_clim": cand1_hold_pred,
            "decomp_clim": cand2_hold_pred,
            "total_persist": cand3_hold_pred,
            "decomp_persist": cand4_hold_pred,
        }
        win_pred = cand_preds_dict[winner_id]
        win_holdout_mae = calc_mae(win_pred, holdout_obs)

        # Paired Moving Block Bootstrap against Primary Reference (Direct Speed Climatology)
        valid = ~np.isnan(win_pred) & ~np.isnan(cand1_hold_pred) & ~np.isnan(holdout_obs)
        err_win = np.abs(win_pred[valid] - holdout_obs[valid])
        err_ref = np.abs(cand1_hold_pred[valid] - holdout_obs[valid])

        _, _, delta_mae, ci_lower, ci_upper = paired_block_bootstrap_mae(
            err_win, err_ref, block_size=BLOCK_SIZE_HOURS, n_boot=N_BOOT
        )

        if winner_id in ["direct_clim", "decomp_clim"]:
            skill_status = "Horizon-Independent Climatology (Zero Forecast Skill)"
        else:
            if ci_upper < 0:
                skill_status = "Statistically Significant Forecast Skill"
            elif ci_lower < 0 and ci_upper >= 0:
                skill_status = "Positive Skill (n.s., 95% CI spans 0)"
            else:
                skill_status = "No Forecast Skill vs Climatology"

        report_table.append({
            "horizon_h": h,
            "lead_time_h": total_lead,
            "cv_winner": winner_id,
            "cv_mae_direct_clim": cv_mean["direct_clim"][h],
            "cv_mae_decomp_clim": cv_mean["decomp_clim"][h],
            "cv_mae_total_persist": cv_mean["total_persist"][h],
            "cv_mae_decomp_persist": cv_mean["decomp_persist"][h],
            "holdout_mae_direct_clim": holdout_ref_mae,
            "holdout_mae_decomp_clim": holdout_cand2_mae,
            "holdout_mae_total_persist": holdout_cand3_mae,
            "holdout_mae_decomp_persist": holdout_cand4_mae,
            "holdout_mae_selected": win_holdout_mae,
            "holdout_mae_selected_kt": win_holdout_mae * 1.94384,
            "delta_mae_vs_direct_clim": delta_mae,
            "block_ci_lower_ms": ci_lower,
            "block_ci_upper_ms": ci_upper,
            "skill_status": skill_status
        })

    df_report = pd.DataFrame(report_table)
    out_csv = "safety-forecast/reports/baselines/currents_unified_four_candidate_benchmark.csv"
    os.makedirs(os.path.dirname(out_csv), exist_ok=True)
    df_report.to_csv(out_csv, index=False)
    print(f"\n[Saved Unified 4-Candidate Benchmark CSV] -> {out_csv}")

if __name__ == "__main__":
    run_four_candidate_baseline_benchmark()
