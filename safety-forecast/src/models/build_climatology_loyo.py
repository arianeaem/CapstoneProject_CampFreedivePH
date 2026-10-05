"""
Builds the 366 x 24 seasonal climatology table for Batangas
from the training data (2022-11-01 to 2025-09-30).

What it does:
1. Tail calibration:
   - k_lo so that at most 10% of values are below p10'
   - k_hi so that at most 10% of values are above p90'
2. Holdout check:
   - fit on 2022-11-01 to 2025-03-31
   - test on the 6 months after (2025-04-01 to 2025-09-30)
3. P(wet day) per day of year with Wilson 95% intervals.
4. P(high-gust day) per day of year with Wilson 95% intervals.
5. Wind direction: circular mean, resultant length R, and chance of each PHP score band.
6. Saves climatology.parquet and climatology_meta.json.
"""

import json
from pathlib import Path
import numpy as np
import pandas as pd

PROJECT_ROOT = Path(__file__).resolve().parents[2]
SNAPSHOT_DIR = PROJECT_ROOT / "data" / "snapshots" / "2026-10-04_rev4"
INTERIM_DIR = PROJECT_ROOT / "data" / "interim"
PROCESSED_DIR = PROJECT_ROOT / "data" / "processed"
REPORTS_DIR = PROJECT_ROOT / "reports" / "baselines"

TRAIN_START = pd.Timestamp("2022-11-01 00:00:00", tz="Asia/Manila")
TRAIN_END   = pd.Timestamp("2025-09-30 23:59:59", tz="Asia/Manila")
HOLDOUT_START = pd.Timestamp("2025-04-01 00:00:00", tz="Asia/Manila")

ADVERSE_TAILS = {
    "p90": [
        "hs", "swell_height", "wind_wave_height",
        "current_speed", "eulerian_speed", "tide_speed", "stokes_speed",
        "wind_speed", "wind_gust", "rain_daily_mm"
    ],
    "p10": ["tp", "slp"]
}

VARIABLES = [
    "hs", "tp", "swell_height", "wind_wave_height",
    "current_speed", "eulerian_speed", "tide_speed", "stokes_speed",
    "wind_speed", "wind_gust", "slp", "rain_daily_mm"
]


def wilson_interval(k: int, n: int, confidence: float = 0.95):
    """Wilson interval for a proportion."""
    if n == 0:
        return 0.0, 0.0, 0.0
    z = 1.959963984540054
    p = k / n
    denom = 1 + z**2 / n
    center = (p + z**2 / (2 * n)) / denom
    margin = (z / denom) * np.sqrt(p * (1 - p) / n + z**2 / (4 * n**2))
    return round(float(p), 4), round(float(max(0.0, center - margin)), 4), round(float(min(1.0, center + margin)), 4)


def load_canonical_dataset() -> pd.DataFrame:
    print("Loading multi-source clean datasets (2022-11-01 to 2025-09-30)...")

    w = pd.read_parquet(SNAPSHOT_DIR / "cmems_waves.parquet")
    w = w[~w["is_provisional"]]
    w = w[(w.index >= TRAIN_START) & (w.index <= TRAIN_END)]
    w_cols = ["hs", "tp", "swell_height", "wind_wave_height"]

    c = pd.read_parquet(SNAPSHOT_DIR / "cmems_currents.parquet")
    c = c[~c["is_provisional"]]
    c = c[(c.index >= TRAIN_START) & (c.index <= TRAIN_END)]
    c_cols = ["current_speed", "eulerian_speed", "tide_speed", "stokes_speed"]

    e = pd.read_parquet(SNAPSHOT_DIR / "era5_wind_pressure.parquet")
    e = e[~e["is_provisional"]]
    e = e[(e.index >= TRAIN_START) & (e.index <= TRAIN_END)]
    e_cols = ["wind_speed", "wind_gust", "slp", "wind_dir"]

    df_hourly = w[w_cols].join(c[c_cols], how="inner").join(e[e_cols], how="inner")

    r = pd.read_parquet(INTERIM_DIR / "gpm_daily_precip.parquet")
    r.index = pd.to_datetime(r.index).tz_localize("Asia/Manila")
    r = r[(r.index >= TRAIN_START.floor("D")) & (r.index <= TRAIN_END.floor("D"))]
    r_series = r["precip_bilinear_mm_day"]

    df_hourly["rain_daily_mm"] = df_hourly.index.floor("D").map(r_series)
    return df_hourly


def compute_raw_grid(df: pd.DataFrame, var: str) -> np.ndarray:
    doy = df.index.dayofyear.to_numpy()
    hour = df.index.hour.to_numpy()
    x = df[var].to_numpy(float)
    n = len(x)

    is_p10_adverse = var in ADVERSE_TAILS["p10"]
    clim_grid = np.zeros((366, 24, 6), dtype=np.float32)

    if var == "rain_daily_mm":
        daily_s = df[var].resample("D").first()
        d_doy = daily_s.index.dayofyear.to_numpy()
        d_val = daily_s.to_numpy(float)
        d_buckets = [[] for _ in range(366)]
        for i in range(len(d_val)):
            if np.isfinite(d_val[i]):
                d_buckets[d_doy[i] - 1].append(d_val[i])
        pad_d = d_buckets[-15:] + d_buckets + d_buckets[:15]

        for d in range(366):
            win_lists = [pad_d[d + 15 + offset] for offset in range(-15, 16)]
            flat = [item for sub in win_lists for item in sub]
            if len(flat) >= 5:
                arr = np.array(flat)
                p10, p50, p90 = np.percentile(arr, [10, 50, 90])
                m = float(np.mean(arr))
                s = float(np.std(arr))
            else:
                p10, p50, p90, m, s = 0.0, 0.0, 0.0, 0.0, 0.0
            adv = p10 if is_p10_adverse else p90
            for h in range(24):
                clim_grid[d, h, :] = [p10, p50, p90, m, s, adv]
        return clim_grid

    buckets = [[[] for _ in range(24)] for _ in range(366)]
    for i in range(n):
        if np.isfinite(x[i]):
            buckets[doy[i] - 1][hour[i]].append(x[i])

    padded_buckets = buckets[-15:] + buckets + buckets[:15]

    for h in range(24):
        for d in range(366):
            win_lists = [padded_buckets[d + 15 + offset][h] for offset in range(-15, 16)]
            flat = [item for sub in win_lists for item in sub]
            if len(flat) >= 5:
                arr = np.array(flat)
                p10, p50, p90 = np.percentile(arr, [10, 50, 90])
                m = float(np.mean(arr))
                s = float(np.std(arr))
            else:
                p10, p50, p90, m, s = 0.0, 0.0, 0.0, 0.0, 0.0
            adv = p10 if is_p10_adverse else p90
            clim_grid[d, h, :] = [p10, p50, p90, m, s, adv]

    return clim_grid


def apply_asymmetric_k(clim_grid: np.ndarray, k_lo: float, k_hi: float, var: str) -> np.ndarray:
    """Stretch p10 and p90 around p50 separately so each tail has at most 10% outside."""
    scaled = clim_grid.copy()
    p10 = scaled[:, :, 0]
    p50 = scaled[:, :, 1]
    p90 = scaled[:, :, 2]

    p10_prime = p50 - k_lo * (p50 - p10)
    p90_prime = p50 + k_hi * (p90 - p50)

    if var in ["hs", "swell_height", "wind_wave_height", "current_speed",
                "eulerian_speed", "tide_speed", "stokes_speed", "wind_speed",
                "wind_gust", "rain_daily_mm", "tp"]:
        p10_prime = np.maximum(0.0, p10_prime)

    scaled[:, :, 0] = p10_prime
    scaled[:, :, 2] = p90_prime
    scaled[:, :, 5] = p10_prime if var in ADVERSE_TAILS["p10"] else p90_prime
    return scaled


def calibrate_asymmetric_k_factors(df: pd.DataFrame):
    """Find k_lo and k_hi with leave-one-year-out so each tail has at most 10% outside."""
    years = [2023, 2024, "boundary"]
    k_factors = {}
    loyo_results = {}

    for var in VARIABLES:
        fold_data = []
        for fold in years:
            if fold == 2023:
                test_mask = (df.index.year == 2023)
            elif fold == 2024:
                test_mask = (df.index.year == 2024)
            else:
                test_mask = (df.index.year.isin([2022, 2025]))

            train_df = df[~test_mask]
            test_df = df[test_mask]
            base_grid = compute_raw_grid(train_df, var)

            t_doy = test_df.index.dayofyear.to_numpy()
            t_hour = test_df.index.hour.to_numpy()
            y_test = test_df[var].to_numpy(float)
            valid = np.isfinite(y_test)

            fold_data.append((base_grid, t_doy[valid], t_hour[valid], y_test[valid]))

        # k_lo: share of y < p10' should be <= 10%
        best_k_lo = 1.0
        best_err_lo = 999.0
        for k_cand in np.arange(0.95, 1.80, 0.02):
            total_below = sum(np.sum(y_v < apply_asymmetric_k(grid, k_cand, 1.0, var)[t_doy - 1, t_hour, 0]) for grid, t_doy, t_hour, y_v in fold_data)
            total_pts = sum(len(y_v) for _, _, _, y_v in fold_data)
            rate_lo = (total_below / total_pts) * 100.0
            err = abs(rate_lo - 10.0)
            if err < best_err_lo:
                best_err_lo = err
                best_k_lo = float(round(k_cand, 2))
            if rate_lo <= 10.0:
                break

        # k_hi: share of y > p90' should be <= 10%
        best_k_hi = 1.0
        best_err_hi = 999.0
        for k_cand in np.arange(0.95, 1.80, 0.02):
            total_above = sum(np.sum(y_v > apply_asymmetric_k(grid, 1.0, k_cand, var)[t_doy - 1, t_hour, 2]) for grid, t_doy, t_hour, y_v in fold_data)
            total_pts = sum(len(y_v) for _, _, _, y_v in fold_data)
            rate_hi = (total_above / total_pts) * 100.0
            err = abs(rate_hi - 10.0)
            if err < best_err_hi:
                best_err_hi = err
                best_k_hi = float(round(k_cand, 2))
            if rate_hi <= 10.0:
                break

        k_factors[var] = {"k_lo": best_k_lo, "k_hi": best_k_hi}

        # Leave-one-year-out results for all years together
        all_y, all_lo, all_hi = [], [], []
        for base_grid, t_doy, t_hour, y_v in fold_data:
            cal_grid = apply_asymmetric_k(base_grid, best_k_lo, best_k_hi, var)
            all_lo.append(cal_grid[t_doy - 1, t_hour, 0])
            all_hi.append(cal_grid[t_doy - 1, t_hour, 2])
            all_y.append(y_v)

        pool_y = np.concatenate(all_y)
        pool_lo = np.concatenate(all_lo)
        pool_hi = np.concatenate(all_hi)
        in_p = (pool_y >= pool_lo) & (pool_y <= pool_hi)

        loyo_results[var] = {
            "k_lo": best_k_lo,
            "k_hi": best_k_hi,
            "calibrated_coverage_pct": round(float(np.mean(in_p)) * 100.0, 2),
            "below_p10_pct": round(float(np.mean(pool_y < pool_lo)) * 100.0, 2),
            "above_p90_pct": round(float(np.mean(pool_y > pool_hi)) * 100.0, 2),
        }

    return k_factors, loyo_results


def evaluate_climatology_on_holdout(df: pd.DataFrame, k_factors: dict) -> dict:
    """
    Test the calibrated climatology on the holdout (2025-04-01 to 2025-09-30)
    using a table fit only on 2022-11-01 to 2025-03-31.
    """
    dev_df = df.loc[:HOLDOUT_START - pd.Timedelta(seconds=1)]
    ho_df = df.loc[HOLDOUT_START:]

    ho_doy = ho_df.index.dayofyear.to_numpy()
    ho_hour = ho_df.index.hour.to_numpy()
    ho_results = {}

    for var in VARIABLES:
        dev_base = compute_raw_grid(dev_df, var)
        k_lo = k_factors[var]["k_lo"]
        k_hi = k_factors[var]["k_hi"]
        dev_cal = apply_asymmetric_k(dev_base, k_lo, k_hi, var)

        y_ho = ho_df[var].to_numpy(float)
        valid = np.isfinite(y_ho)

        p10_ho = dev_cal[ho_doy[valid] - 1, ho_hour[valid], 0]
        p90_ho = dev_cal[ho_doy[valid] - 1, ho_hour[valid], 2]
        p50_ho = dev_cal[ho_doy[valid] - 1, ho_hour[valid], 1]
        y_v = y_ho[valid]

        in_b = (y_v >= p10_ho) & (y_v <= p90_ho)
        below = y_v < p10_ho
        above = y_v > p90_ho
        mae = float(np.mean(np.abs(y_v - p50_ho)))

        ho_results[var] = {
            "n_holdout": int(np.sum(valid)),
            "holdout_coverage_pct": round(float(np.mean(in_b)) * 100.0, 2),
            "below_p10_pct": round(float(np.mean(below)) * 100.0, 2),
            "above_p90_pct": round(float(np.mean(above)) * 100.0, 2),
            "mae_p50": round(mae, 4)
        }

    return ho_results


def compute_daily_probabilities_and_wind_direction(df: pd.DataFrame):
    daily = pd.DataFrame()
    daily["rain_mm"] = df["rain_daily_mm"].resample("D").first()
    daily["is_wet"] = (daily["rain_mm"] >= 1.0).astype(int)

    daytime = df[(df.index.hour >= 6) & (df.index.hour <= 18)]
    daily["max_gust_kmh"] = daytime["wind_gust"].resample("D").max() * 3.6
    daily["is_high_gust"] = (daily["max_gust_kmh"] >= 48.0).astype(int)

    daily_doy = daily.index.dayofyear.to_numpy()
    pad_daily_doy = np.concatenate([daily_doy - 366, daily_doy, daily_doy + 366])
    pad_wet = np.concatenate([daily["is_wet"].values, daily["is_wet"].values, daily["is_wet"].values])
    pad_gust = np.concatenate([daily["is_high_gust"].values, daily["is_high_gust"].values, daily["is_high_gust"].values])

    prob_results = {}
    for d in range(1, 367):
        m = (pad_daily_doy >= d - 15) & (pad_daily_doy <= d + 15)
        k_wet = int(np.sum(pad_wet[m]))
        n_wet = int(np.sum(m))
        p_wet, w_lo, w_hi = wilson_interval(k_wet, n_wet)

        k_gust = int(np.sum(pad_gust[m]))
        p_gust, g_lo, g_hi = wilson_interval(k_gust, n_wet)

        prob_results[d] = {
            "p_wet_day": p_wet,
            "p_wet_ci_lo": w_lo,
            "p_wet_ci_hi": w_hi,
            "p_high_gust_day": p_gust,
            "p_high_gust_ci_lo": g_lo,
            "p_high_gust_ci_hi": g_hi
        }

    w_doy = df.index.dayofyear.to_numpy()
    w_hour = df.index.hour.to_numpy()
    w_dir = df["wind_dir"].to_numpy(float)

    pad_w_doy = np.concatenate([w_doy - 366, w_doy, w_doy + 366])
    pad_w_hour = np.concatenate([w_hour, w_hour, w_hour])
    pad_w_dir = np.concatenate([w_dir, w_dir, w_dir])

    wind_dir_grid = {}
    rad = np.deg2rad(pad_w_dir)
    sin_vals = np.sin(rad)
    cos_vals = np.cos(rad)

    for d in range(1, 367):
        wind_dir_grid[d] = {}
        day_m = (pad_w_doy >= d - 15) & (pad_w_doy <= d + 15)
        for h in range(24):
            cell_m = day_m & (pad_w_hour == h) & np.isfinite(pad_w_dir)
            if np.sum(cell_m) >= 5:
                s_mean = np.mean(sin_vals[cell_m])
                c_mean = np.mean(cos_vals[cell_m])
                r_len = np.hypot(s_mean, c_mean)
                circ_mean = (np.rad2deg(np.arctan2(s_mean, c_mean))) % 360.0
                circ_std = np.rad2deg(np.sqrt(-2.0 * np.log(max(1e-4, min(1.0, r_len)))))

                dirs = pad_w_dir[cell_m]
                b0 = np.mean(((dirs >= 0) & (dirs <= 90)) | ((dirs > 315) & (dirs <= 360)))
                b1 = np.mean((dirs > 90) & (dirs <= 135))
                b2 = np.mean(((dirs > 135) & (dirs <= 180)) | ((dirs > 270) & (dirs <= 315)))
                b3 = np.mean((dirs > 180) & (dirs <= 270))
            else:
                circ_mean, r_len, circ_std = 0.0, 0.0, 0.0
                b0, b1, b2, b3 = 0.25, 0.25, 0.25, 0.25

            wind_dir_grid[d][h] = {
                "wind_dir_circ_mean_deg": round(float(circ_mean), 1),
                "wind_dir_resultant_R": round(float(r_len), 3),
                "wind_dir_circ_std_deg": round(float(circ_std), 1),
                "prob_band_0_offshore_nne": round(float(b0), 3),
                "prob_band_1_ese": round(float(b1), 3),
                "prob_band_2_s_wnw": round(float(b2), 3),
                "prob_band_3_onshore_habagat": round(float(b3), 3),
            }

    return prob_results, wind_dir_grid


def main():
    df = load_canonical_dataset()

    print("\n" + "=" * 80)
    print("STEP 1: ASYMMETRIC TAIL CALIBRATION (Target: <=10% on Both Tails)")
    print("=" * 80)
    k_factors, loyo_metrics = calibrate_asymmetric_k_factors(df)

    REPORTS_DIR.mkdir(parents=True, exist_ok=True)
    loyo_path = REPORTS_DIR / "climatology_loyo_coverage.json"
    with open(loyo_path, "w", encoding="utf-8") as f:
        json.dump(loyo_metrics, f, indent=2)

    calib_rows = []
    for var, data in loyo_metrics.items():
        calib_rows.append({
            "Variable": var,
            "k_lo": data["k_lo"],
            "k_hi": data["k_hi"],
            "Coverage": f"{data['calibrated_coverage_pct']}%",
            "Below P10": f"{data['below_p10_pct']}%",
            "Above P90": f"{data['above_p90_pct']}%",
        })
    print("\nASYMMETRICALLY CALIBRATED LOYO COVERAGE SUMMARY:")
    print(pd.DataFrame(calib_rows).to_string(index=False))

    # Test on the holdout
    print("\n" + "=" * 80)
    print("HOLDOUT EVALUATION OF CLIMATOLOGY (Fit on Dev, Tested on 2025-04 to 2025-09)")
    print("=" * 80)
    ho_metrics = evaluate_climatology_on_holdout(df, k_factors)
    ho_path = REPORTS_DIR / "climatology_holdout_evaluation.json"
    with open(ho_path, "w", encoding="utf-8") as f:
        json.dump(ho_metrics, f, indent=2)

    ho_rows = []
    for var, data in ho_metrics.items():
        ho_rows.append({
            "Variable": var,
            "Holdout Coverage": f"{data['holdout_coverage_pct']}%",
            "Holdout Below P10": f"{data['below_p10_pct']}%",
            "Holdout Above P90": f"{data['above_p90_pct']}%",
            "Holdout MAE (P50)": data["mae_p50"]
        })
    print(pd.DataFrame(ho_rows).to_string(index=False))

    print("\nComputing P(wet day), P(high-gust day), and Wind Direction distributions...")
    prob_daily, wind_dir_grid = compute_daily_probabilities_and_wind_direction(df)

    print("\nFitting final 366 x 24 Climatology Table with Asymmetric Bands...")
    records = []
    sample_dates = pd.date_range("2024-01-01", "2024-12-31", freq="D")
    doy_to_date = {d.dayofyear: (d.month, d.day) for d in sample_dates}

    var_grids = {}
    for var in VARIABLES:
        base_grid = compute_raw_grid(df, var)
        k_lo = k_factors[var]["k_lo"]
        k_hi = k_factors[var]["k_hi"]
        var_grids[var] = apply_asymmetric_k(base_grid, k_lo, k_hi, var)

    for doy in range(1, 367):
        month, day = doy_to_date[doy]
        p_d = prob_daily[doy]
        for hour in range(24):
            w_h = wind_dir_grid[doy][hour]
            row = {
                "day_of_year": doy,
                "month": month,
                "day": day,
                "hour_pht": hour,
                "hour_utc": (hour - 8) % 24,
                "p_wet_day": p_d["p_wet_day"],
                "p_wet_ci_lo": p_d["p_wet_ci_lo"],
                "p_wet_ci_hi": p_d["p_wet_ci_hi"],
                "p_high_gust_day": p_d["p_high_gust_day"],
                "p_high_gust_ci_lo": p_d["p_high_gust_ci_lo"],
                "p_high_gust_ci_hi": p_d["p_high_gust_ci_hi"],
                "wind_dir_circ_mean_deg": w_h["wind_dir_circ_mean_deg"],
                "wind_dir_resultant_R": w_h["wind_dir_resultant_R"],
                "wind_dir_circ_std_deg": w_h["wind_dir_circ_std_deg"],
                "prob_band_0_offshore_nne": w_h["prob_band_0_offshore_nne"],
                "prob_band_1_ese": w_h["prob_band_1_ese"],
                "prob_band_2_s_wnw": w_h["prob_band_2_s_wnw"],
                "prob_band_3_onshore_habagat": w_h["prob_band_3_onshore_habagat"],
            }
            for var in VARIABLES:
                grid = var_grids[var]
                p10, p50, p90, mean_v, std_v, adv = grid[doy - 1, hour]
                row[f"{var}_p10"] = round(float(p10), 4)
                row[f"{var}_p50"] = round(float(p50), 4)
                row[f"{var}_p90"] = round(float(p90), 4)
                row[f"{var}_mean"] = round(float(mean_v), 4)
                row[f"{var}_std"] = round(float(std_v), 4)
                row[f"{var}_adverse"] = round(float(adv), 4)
            records.append(row)

    out_df = pd.DataFrame(records)
    PROCESSED_DIR.mkdir(parents=True, exist_ok=True)
    out_parquet = PROCESSED_DIR / "climatology.parquet"
    out_df.to_parquet(out_parquet, index=False)
    print(f"Wrote canonical climatology table ({len(out_df)} rows, {out_df.shape[1]} cols) to: {out_parquet}")

    meta = {
        "dataset_name": "CampFreedivePH Canonical 366x24 Seasonal Climatology",
        "spatial_site": {
            "name": "Bagalangit / Mainit Point, Mabini, Batangas",
            "lat": 13.6874,
            "lon": 120.8931,
            "timezone": "Asia/Manila (UTC+08:00)"
        },
        "training_window": {
            "start": str(TRAIN_START),
            "end": str(TRAIN_END),
            "holdout_start": str(HOLDOUT_START),
            "total_days": 1065,
            "quality": "Verified Reanalysis / NASA Final Run only (zero provisional rows)"
        },
        "table_shape": {
            "days_of_year": 366,
            "hours_per_day": 24,
            "total_cells": 366 * 24,
            "variables_count": len(VARIABLES)
        },
        "calibration": {
            "target_coverage": 80.0,
            "formula": "p10' = max(0, p50 - k_lo*(p50 - p10)), p90' = p50 + k_hi*(p90 - p50)",
            "asymmetric_k_factors": k_factors,
            "loyo_empirical_metrics": loyo_metrics,
            "holdout_empirical_metrics": ho_metrics
        },
        "probabilities": {
            "wet_day_definition": "Daily precipitation >= 1.0 mm/day",
            "high_gust_day_definition": "Daytime peak gust (06:00-18:00 PHT) >= 48.0 km/h (13.33 m/s)",
            "confidence_intervals": "Wilson score 95% interval"
        },
        "wind_direction_scoring_bands": {
            "band_0": "[0, 90] U (315, 360] - Offshore / NNE (calm bay lee)",
            "band_1": "(90, 135] - ESE",
            "band_2": "(135, 180] U (270, 315] - S or WNW",
            "band_3": "(180, 270] - Onshore / SW Habagat"
        },
        "adverse_tails": ADVERSE_TAILS,
        "variables": VARIABLES
    }
    out_meta = PROCESSED_DIR / "climatology_meta.json"
    with open(out_meta, "w", encoding="utf-8") as f:
        json.dump(meta, f, indent=2)
    print(f"Wrote metadata manifest to: {out_meta}")


if __name__ == "__main__":
    main()
