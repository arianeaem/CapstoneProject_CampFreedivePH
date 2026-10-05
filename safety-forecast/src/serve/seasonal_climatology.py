"""
Seasonal Climatology Generator (Days 3-10 Operational Baseline & Prior).

Computes empirical percentiles (p10, p50, p90) and mean/std grouped by (month, hour)
on the canonical verified training partition (2022-11-01 to 2025-09-30, no provisional data).

Adverse Tail Quantiles:
  - Upper tail (P90): hs, swell_height, wind_wave_height, current_speed, wind_speed, wind_gust, rain_daily_mm
  - Lower tail (P10): tp (period/chop), slp (cyclone pressure)

Used by the 3-Way Model Execution Router for:
1. Long-range seasonal forecast envelope for Days 3-10 (where observation-based ML skill decays to prior).
2. Climatology fallback prior for quarantined or high-uncertainty models.
"""

import sys
import json
from pathlib import Path
from typing import Dict, Any, Optional, Tuple, Union
import numpy as np
import pandas as pd

PROJECT_ROOT = Path(__file__).resolve().parents[2]
SNAPSHOT_DIR = PROJECT_ROOT / "data" / "snapshots" / "2026-10-04_rev4"
INTERIM_DIR = PROJECT_ROOT / "data" / "interim"
OUTPUT_DIR = PROJECT_ROOT / "reports" / "baselines"

TRAIN_START_UTC = pd.Timestamp("2022-11-01 00:00:00", tz="UTC")
TRAIN_MAX_UTC = pd.Timestamp("2025-09-30 23:59:59", tz="UTC")

CORE_VARIABLES = {
    "waves": ["hs", "tp", "swell_height", "wind_wave_height"],
    "currents": ["current_speed", "eulerian_speed", "tide_speed", "stokes_speed"],
    "atmosphere": ["wind_speed", "wind_gust", "slp"],
}

# Adverse Tail Specifications per PRD & Expert Rules
ADVERSE_TAILS = {
    "p90": [
        "hs",
        "swell_height",
        "wind_wave_height",
        "current_speed",
        "eulerian_speed",
        "tide_speed",
        "stokes_speed",
        "wind_speed",
        "wind_gust",
        "rain_daily_mm",
    ],
    "p10": [
        "tp",   # Low wave period = short choppy sea state
        "slp",  # Low barometric pressure = tropical cyclones / depressions
    ],
}


def generate_seasonal_climatology() -> Dict[str, Any]:
    print("=" * 80)
    print("GENERATING SEASONAL CLIMATOLOGY LOOKUP (p10, p50, p90) BY (MONTH, HOUR)")
    print(f"Data Sources: {SNAPSHOT_DIR} & {INTERIM_DIR}")
    print(f"Canonical Window: {TRAIN_START_UTC} to {TRAIN_MAX_UTC} (Verified Reanalysis/Final Only)")
    print("=" * 80)

    # 1. Load Waves
    waves_path = SNAPSHOT_DIR / "cmems_waves.parquet"
    df_waves = pd.read_parquet(waves_path)
    df_waves = df_waves[~df_waves["is_provisional"]]
    idx_w = df_waves.index.tz_convert("UTC")
    df_waves = df_waves[(idx_w >= TRAIN_START_UTC) & (idx_w <= TRAIN_MAX_UTC)]

    # 2. Load Currents
    curr_path = SNAPSHOT_DIR / "cmems_currents.parquet"
    df_curr = pd.read_parquet(curr_path)
    df_curr = df_curr[~df_curr["is_provisional"]]
    idx_c = df_curr.index.tz_convert("UTC")
    df_curr = df_curr[(idx_c >= TRAIN_START_UTC) & (idx_c <= TRAIN_MAX_UTC)]

    # 3. Load Wind & Pressure
    era5_path = SNAPSHOT_DIR / "era5_wind_pressure.parquet"
    df_era5 = pd.read_parquet(era5_path)
    df_era5 = df_era5[~df_era5["is_provisional"]]
    idx_e = df_era5.index.tz_convert("UTC")
    df_era5 = df_era5[(idx_e >= TRAIN_START_UTC) & (idx_e <= TRAIN_MAX_UTC)]

    datasets = {
        "waves": df_waves,
        "currents": df_curr,
        "atmosphere": df_era5,
    }

    lookup_table: Dict[str, Any] = {
        "metadata": {
            "window_start": str(TRAIN_START_UTC),
            "window_end": str(TRAIN_MAX_UTC),
            "adverse_tails": ADVERSE_TAILS,
        },
        "variables": {}
    }
    flattened_rows = []

    for category, vars_list in CORE_VARIABLES.items():
        df_cat = datasets[category]

        for var in vars_list:
            if var not in df_cat.columns:
                print(f"Warning: {var} not found in {category} dataset, skipping.", flush=True)
                continue

            lookup_table["variables"][var] = {}
            series = df_cat[var].dropna()
            idx_s = series.index.tz_convert("UTC")

            grouped = series.groupby([idx_s.month, idx_s.hour])
            p10_map = grouped.quantile(0.10).to_dict()
            p50_map = grouped.quantile(0.50).to_dict()
            p90_map = grouped.quantile(0.90).to_dict()
            mean_map = grouped.mean().to_dict()
            std_map = grouped.std().to_dict()

            # Ensure all 12x24 = 288 pairs exist
            for m in range(1, 13):
                for h in range(24):
                    pair_key = f"{m:02d}_{h:02d}"
                    p10 = float(p10_map.get((m, h), np.nan))
                    p50 = float(p50_map.get((m, h), np.nan))
                    p90 = float(p90_map.get((m, h), np.nan))
                    mean_val = float(mean_map.get((m, h), np.nan))
                    std_val = float(std_map.get((m, h), np.nan))

                    stats = {
                        "p10": round(p10, 4),
                        "p50": round(p50, 4),
                        "p90": round(p90, 4),
                        "mean": round(mean_val, 4),
                        "std": round(std_val, 4),
                        "adverse": round(p10 if var in ADVERSE_TAILS["p10"] else p90, 4),
                    }
                    lookup_table["variables"][var][pair_key] = stats

                    flattened_rows.append({
                        "variable": var,
                        "category": category,
                        "month": m,
                        "hour_utc": h,
                        "p10": stats["p10"],
                        "p50": stats["p50"],
                        "p90": stats["p90"],
                        "mean": stats["mean"],
                        "std": stats["std"],
                        "adverse": stats["adverse"],
                    })

    # 4. Daily Precipitation Climatology (IMERG Final Run V07B)
    gpm_path = INTERIM_DIR / "gpm_daily_precip.parquet"
    if gpm_path.exists():
        df_gpm = pd.read_parquet(gpm_path)
        df_gpm.index = pd.to_datetime(df_gpm.index)
        if df_gpm.index.tz is None:
            df_gpm.index = df_gpm.index.tz_localize("UTC")
        else:
            df_gpm.index = df_gpm.index.tz_convert("UTC")
        df_gpm_window = df_gpm[(df_gpm.index >= TRAIN_START_UTC) & (df_gpm.index <= TRAIN_MAX_UTC)]
        r_series = df_gpm_window["precip_bilinear_mm_day"].dropna()

        lookup_table["variables"]["rain_daily_mm"] = {}
        grouped_r = r_series.groupby(r_series.index.month)
        p10_r = grouped_r.quantile(0.10).to_dict()
        p50_r = grouped_r.quantile(0.50).to_dict()
        p90_r = grouped_r.quantile(0.90).to_dict()
        mean_r = grouped_r.mean().to_dict()
        std_r = grouped_r.std().to_dict()

        for m in range(1, 13):
            # For daily rain, all hours of month m map to the same daily envelope
            stats_r = {
                "p10": round(float(p10_r.get(m, 0.0)), 4),
                "p50": round(float(p50_r.get(m, 0.0)), 4),
                "p90": round(float(p90_r.get(m, 0.0)), 4),
                "mean": round(float(mean_r.get(m, 0.0)), 4),
                "std": round(float(std_r.get(m, 0.0)), 4),
                "adverse": round(float(p90_r.get(m, 0.0)), 4),
            }
            for h in range(24):
                pair_key = f"{m:02d}_{h:02d}"
                lookup_table["variables"]["rain_daily_mm"][pair_key] = stats_r
                flattened_rows.append({
                    "variable": "rain_daily_mm",
                    "category": "precipitation",
                    "month": m,
                    "hour_utc": h,
                    "p10": stats_r["p10"],
                    "p50": stats_r["p50"],
                    "p90": stats_r["p90"],
                    "mean": stats_r["mean"],
                    "std": stats_r["std"],
                    "adverse": stats_r["adverse"],
                })

    # Save to JSON
    OUTPUT_DIR.mkdir(parents=True, exist_ok=True)
    json_path = OUTPUT_DIR / "seasonal_climatology_p10_p50_p90.json"
    with open(json_path, "w", encoding="utf-8") as f:
        json.dump(lookup_table, f, indent=2)
    print(f"Exported JSON lookup to: {json_path}")

    # Also save tabular DataFrame
    flat_df = pd.DataFrame(flattened_rows)
    flat_df.to_parquet(OUTPUT_DIR / "seasonal_climatology_p10_p50_p90.parquet", index=False)
    print(f"Exported Parquet table to: {OUTPUT_DIR / 'seasonal_climatology_p10_p50_p90.parquet'}")

    print("\n--- SAMPLE CLIMATOLOGY ENVELOPE: AUGUST (Habagat) vs JANUARY (Amihan) ---")
    sample_df = flat_df[(flat_df["month"].isin([1, 8])) & (flat_df["hour_utc"] == 6) & (flat_df["variable"].isin(["hs", "current_speed", "wind_speed", "rain_daily_mm"]))]
    print(sample_df[["month", "hour_utc", "variable", "p10", "p50", "p90", "mean", "adverse"]].to_string(index=False))

    return lookup_table


class SeasonalClimatologyLookup:
    """Fast in-memory lookup for seasonal estimates (Days 3-10)."""
    _instance: Optional["SeasonalClimatologyLookup"] = None
    _data: Dict[str, Any] = {}

    def __init__(self, json_path: Optional[Path] = None):
        if not self._data:
            path = json_path or (OUTPUT_DIR / "seasonal_climatology_p10_p50_p90.json")
            if not path.exists():
                generate_seasonal_climatology()
            with open(path, "r", encoding="utf-8") as f:
                raw = json.load(f)
                # Support both direct variable keys and nested metadata/variables structure
                SeasonalClimatologyLookup._data = raw.get("variables", raw)

    def get_percentiles(self, variable: str, timestamp: Union[pd.Timestamp, str]) -> Dict[str, float]:
        """Returns dict with p10, p50, p90, mean, std, adverse for given variable and timestamp."""
        ts = pd.Timestamp(timestamp)
        if ts.tz is None:
            ts = ts.tz_localize("UTC")
        else:
            ts = ts.tz_convert("UTC")

        pair_key = f"{ts.month:02d}_{ts.hour:02d}"
        var_data = self._data.get(variable, {})

        if pair_key in var_data:
            return var_data[pair_key]

        # Graceful fallback: month-only average across hours
        month_entries = [v for k, v in var_data.items() if k.startswith(f"{ts.month:02d}_")]
        if month_entries:
            return {
                "p10": float(np.mean([e["p10"] for e in month_entries])),
                "p50": float(np.mean([e["p50"] for e in month_entries])),
                "p90": float(np.mean([e["p90"] for e in month_entries])),
                "mean": float(np.mean([e["mean"] for e in month_entries])),
                "std": float(np.mean([e["std"] for e in month_entries])),
                "adverse": float(np.mean([e.get("adverse", e["p90"]) for e in month_entries])),
            }

        return {"p10": 0.0, "p50": 0.0, "p90": 0.0, "mean": 0.0, "std": 0.0, "adverse": 0.0}

    def get_adverse_bound(self, variable: str, timestamp: Union[pd.Timestamp, str]) -> float:
        """Returns p10 for lower-tail hazards (tp, slp) and p90 for upper-tail hazards."""
        stats = self.get_percentiles(variable, timestamp)
        if variable in ADVERSE_TAILS["p10"]:
            return stats["p10"]
        return stats["p90"]


if __name__ == "__main__":
    generate_seasonal_climatology()
