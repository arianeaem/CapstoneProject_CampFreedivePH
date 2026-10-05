"""
Checks if the CMEMS waves and SMOC currents changed suddenly (e.g. after a system upgrade).

1. About the old t-test:
   - the t <= 2.4 we reported was on MONTHLY averages (N = 24 to 35 months),
     not on the hourly rows. On hourly data (N > 40,000) the t value is way too big
     because the values are strongly related hour to hour (r_1 > 0.95), so the p-values are wrong.
2. Test on monthly anomalies (season removed):
   - remove the normal Amihan / Habagat change using the monthly climatology:
     a(y, m) = mean(y, m) - clim_mean(m)
   - check for a jump at the upgrade dates:
     * SMOC currents: November 2022 upgrade (2022-11-01). 24 months before vs 35 months after.
     * CMEMS waves: November 2024 upgrade (2024-11-01). 24 months before vs 11 months after.
       (Wave data starts 2022-11-01, so we can't test the 2022 upgrade for waves.)
3. Notes:
   - not finding a change doesn't prove there is none.
   - known odd values: Tp < 2.0s (41 rows, 0.12%) and Hs max 2.69 m, not yet checked with a buoy/altimeter.
   - utide/vtide is model output at the cell 6.86 km offshore, not a real tide gauge.
"""

import json
from pathlib import Path
from typing import Dict, Any, Tuple
import numpy as np
import pandas as pd
from scipy import stats

from training_eligibility import load_snapshot_dataset
from config import DATA_ROOT, TARGET_TIMEZONE

OUT_REPORTS_DIR = DATA_ROOT.parent / "reports" / "data_audits"
OUT_REPORTS_DIR.mkdir(parents=True, exist_ok=True)


def compute_deseasonalized_monthly_anomalies(series: pd.Series) -> pd.DataFrame:
    """
    Monthly averages minus the long-term average for that month.
    Returns columns: ['month_str', 'year', 'month', 'raw_mean', 'clim_mean', 'anomaly', 'count'].
    """
    # Use UTC
    idx_utc = series.index.tz_convert("UTC") if series.index.tz is not None else series.index.tz_localize("UTC")
    df_s = pd.DataFrame({"val": series.values}, index=idx_utc)
    
    # Monthly average
    monthly = df_s.resample("ME").agg(["mean", "count"])
    monthly.columns = ["raw_mean", "count"]
    monthly = monthly[monthly["count"] >= 100].copy()  # drop partial start/end chunks
    
    monthly["year"] = monthly.index.year
    monthly["month"] = monthly.index.month
    monthly["month_str"] = monthly.index.strftime("%Y-%m")

    # Average for each calendar month
    month_clim = monthly.groupby("month")["raw_mean"].mean().to_dict()
    monthly["clim_mean"] = monthly["month"].map(month_clim)
    monthly["anomaly"] = monthly["raw_mean"] - monthly["clim_mean"]

    return monthly


def test_upgrade_step_on_anomalies(monthly_df: pd.DataFrame, upgrade_date_str: str, var_name: str) -> Dict[str, Any]:
    """
    Check if the monthly anomalies jump at an upgrade date.
    """
    up_dt = pd.Timestamp(upgrade_date_str, tz="UTC")
    
    before_mask = monthly_df.index < up_dt
    after_mask = monthly_df.index >= up_dt

    before_anom = monthly_df.loc[before_mask, "anomaly"].values
    after_anom = monthly_df.loc[after_mask, "anomaly"].values

    n_before = len(before_anom)
    n_after = len(after_anom)

    if n_before < 3 or n_after < 3:
        return {
            "variable": var_name,
            "upgrade_date": upgrade_date_str,
            "evaluable": False,
            "reason": f"Insufficient data: {n_before} months before, {n_after} months after."
        }

    # Welch's t-test on anomalies
    t_stat, p_val = stats.ttest_ind(before_anom, after_anom, equal_var=False)
    
    # Mann-Whitney U test
    u_stat, p_val_mw = stats.mannwhitneyu(before_anom, after_anom, alternative="two-sided")

    # Variance ratio
    var_before = float(np.var(before_anom, ddof=1)) if n_before > 1 else 0.0
    var_after = float(np.var(after_anom, ddof=1)) if n_after > 1 else 0.0
    var_ratio = var_after / var_before if var_before > 0 else 1.0

    mean_diff = float(np.mean(after_anom) - np.mean(before_anom))

    return {
        "variable": var_name,
        "upgrade_date": upgrade_date_str,
        "evaluable": True,
        "n_months_before": n_before,
        "n_months_after": n_after,
        "before_window": f"{monthly_df.loc[before_mask, 'month_str'].iloc[0]} to {monthly_df.loc[before_mask, 'month_str'].iloc[-1]}",
        "after_window": f"{monthly_df.loc[after_mask, 'month_str'].iloc[0]} to {monthly_df.loc[after_mask, 'month_str'].iloc[-1]}",
        "anomaly_mean_before": round(float(np.mean(before_anom)), 5),
        "anomaly_mean_after": round(float(np.mean(after_anom)), 5),
        "anomaly_step_diff": round(mean_diff, 5),
        "t_statistic": round(float(t_stat), 3),
        "p_value_welch": round(float(p_val), 4),
        "p_value_mannwhitney": round(float(p_val_mw), 4),
        "variance_ratio": round(float(var_ratio), 4),
        "is_significant_shift_p05": bool(p_val < 0.05),
        "methodological_caveat": "Fail-to-reject indicates no detectable jump above interannual anomaly variance; temporal autocorrelation means p-values are slightly anti-conservative."
    }


def evaluate_homogeneity():
    print("=" * 80)
    print("DESEASONALIZED MONTHLY ANOMALY HOMOGENEITY & SYSTEM UPGRADE AUDIT")
    print("=" * 80)

    # 1. Currents (Nov 2022 upgrade)
    df_curr = load_snapshot_dataset("currents")
    curr_monthly = compute_deseasonalized_monthly_anomalies(df_curr["current_speed"])
    curr_step_2022 = test_upgrade_step_on_anomalies(curr_monthly, "2022-11-01", "Current Speed (m/s)")
    
    print("\n1. SMOC CURRENTS (Nov 2022 Upgrade Step Test on Deseasonalized Anomalies):")
    print(f"   Before (n={curr_step_2022['n_months_before']} mos): {curr_step_2022['before_window']} | Mean Anomaly: {curr_step_2022['anomaly_mean_before']:.4f} m/s")
    print(f"   After  (n={curr_step_2022['n_months_after']} mos): {curr_step_2022['after_window']} | Mean Anomaly: {curr_step_2022['anomaly_mean_after']:.4f} m/s")
    print(f"   Anomaly Step Diff: {curr_step_2022['anomaly_step_diff']:+.4f} m/s | t={curr_step_2022['t_statistic']:.3f} | p={curr_step_2022['p_value_welch']:.4f}")
    print(f"   Significant at alpha=0.05: {curr_step_2022['is_significant_shift_p05']}")

    # Also test the Eulerian and tide parts
    curr_eul_monthly = compute_deseasonalized_monthly_anomalies(df_curr["eulerian_speed"])
    curr_eul_step = test_upgrade_step_on_anomalies(curr_eul_monthly, "2022-11-01", "Eulerian Speed (m/s)")
    print(f"   Eulerian Speed Anomaly Step: {curr_eul_step['anomaly_step_diff']:+.4f} m/s (p={curr_eul_step['p_value_welch']:.4f})")

    # 2. Waves (Nov 2024 upgrade)
    df_waves = load_snapshot_dataset("waves")
    waves_monthly = compute_deseasonalized_monthly_anomalies(df_waves["hs"])
    waves_step_2024 = test_upgrade_step_on_anomalies(waves_monthly, "2024-11-01", "Wave Hs (m)")
    
    print("\n2. CMEMS WAVES (Nov 2024 Upgrade Step Test on Deseasonalized Anomalies):")
    print(f"   Before (n={waves_step_2024['n_months_before']} mos): {waves_step_2024['before_window']} | Mean Anomaly: {waves_step_2024['anomaly_mean_before']:.4f} m")
    print(f"   After  (n={waves_step_2024['n_months_after']} mos): {waves_step_2024['after_window']} | Mean Anomaly: {waves_step_2024['anomaly_mean_after']:.4f} m")
    print(f"   Anomaly Step Diff: {waves_step_2024['anomaly_step_diff']:+.4f} m | t={waves_step_2024['t_statistic']:.3f} | p={waves_step_2024['p_value_welch']:.4f}")
    print(f"   Significant at alpha=0.05: {waves_step_2024['is_significant_shift_p05']}")
    print("   [NOTE]: CMEMS Waves dataset begins 2022-11-01; no pre-2022 data exists to test the 2022-11 upgrade.")

    # List the odd values
    tp_short = int((df_waves["tp"] < 2.0).sum())
    hs_max_val = float(df_waves["hs"].max())
    hs_max_time = str(df_waves["hs"].idxmax().tz_convert("UTC"))

    report = {
        "currents_smoc": {
            "upgrade_202211_step_test_total_speed": curr_step_2022,
            "upgrade_202211_step_test_eulerian_speed": curr_eul_step,
            "tidal_velocity_nature": "Numerical hydrodynamic model output (6.86 km offshore cell), not in-situ pier tide gauge."
        },
        "waves_cmems": {
            "upgrade_202411_step_test_hs": waves_step_2024,
            "upgrade_202211_status": "Not testable; wave analysis series starts 2022-11-01 03:00 UTC concurrently with 202211 version.",
            "tp_less_than_2s_rows": tp_short,
            "tp_less_than_2s_pct": round(tp_short / len(df_waves) * 100, 3),
            "hs_max_val_m": hs_max_val,
            "hs_max_time_utc": hs_max_time,
            "hs_max_caveat": "2.69m peak on 2024-10-24 06:00 UTC documented pending independent altimetry/track validation."
        },
        "statistical_framework": {
            "unit_of_analysis": "Deseasonalized monthly anomaly (monthly mean minus calendar-month climatology)",
            "autocorrelation_caveat": "Degrees of freedom are based on monthly anomaly counts; serial autocorrelation is mitigated by monthly aggregation but fails-to-reject cannot serve as mathematical proof of stationarity."
        }
    }

    out_file = OUT_REPORTS_DIR / "homogeneity_audit_report.json"
    with open(out_file, "w", encoding="utf-8") as f:
        json.dump(report, f, indent=2)
    print(f"\n[Saved Homogeneity Audit Report] -> {out_file}")
    print("=" * 80)
    return report


if __name__ == "__main__":
    evaluate_homogeneity()
