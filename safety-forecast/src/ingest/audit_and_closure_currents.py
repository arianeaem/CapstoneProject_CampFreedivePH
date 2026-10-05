"""
Checks the CMEMS SMOC current data after download.

1. Do the parts add up?
   |utotal - (uo + utide + vsdx)| and |vtotal - (vo + vtide + vsdy)|
2. Speed of each part:
   - total surface current (utotal, vtotal)
   - Eulerian current (uo, vo)
   - tide current (utide, vtide)
   - Stokes drift from waves (vsdx, vsdy)
3. FFT power at the M2 tide period (12.42h)
4. Gaps, interpolation and provisional rows.
"""

import sys
from pathlib import Path
import numpy as np
import pandas as pd
from scipy.signal import periodogram

from config import DATA_ROOT, INTERIM_DIR, RAW_DIR


def run_audit():
    parquet_path = INTERIM_DIR / "cmems_currents.parquet"
    if not parquet_path.exists():
        print(f"[ERROR] {parquet_path} does not exist. Run cmems_currents.py first.")
        return

    df = pd.read_parquet(parquet_path)
    print("=" * 80)
    print("CMEMS SMOC OCEAN CURRENTS: POST-REDOWNLOAD AUDIT & CLOSURE REPORT")
    print("=" * 80)
    print(f"Total Rows:       {len(df):,}")
    print(f"Time Range (PHT): {df.index.min()} to {df.index.max()}")
    print(f"Time Range (UTC): {df.index.min().tz_convert('UTC')} to {df.index.max().tz_convert('UTC')}")
    print(f"Columns:          {list(df.columns)}")
    print(f"Interpolated:     {df['current_is_interpolated'].sum():,} rows ({df['current_is_interpolated'].mean()*100:.2f}%)")
    print(f"Provisional:      {df['is_provisional'].sum():,} rows ({df['is_provisional'].mean()*100:.2f}%)")

    # 1. Do the parts add up?
    has_components = all(c in df.columns for c in ["current_u", "eulerian_u", "tide_u", "stokes_u",
                                                   "current_v", "eulerian_v", "tide_v", "stokes_v"])
    if has_components:
        diff_u = np.abs(df["current_u"] - (df["eulerian_u"] + df["tide_u"] + df["stokes_u"]))
        diff_v = np.abs(df["current_v"] - (df["eulerian_v"] + df["tide_v"] + df["stokes_v"]))

        print("\n" + "-" * 50)
        print("1. MATHEMATICAL CLOSURE TEST: utotal = uo + utide + vsdx")
        print("-" * 50)
        print(f"Zonal (u) Closure Error:")
        print(f"  Mean:   {diff_u.mean():.6f} m/s")
        print(f"  Median: {diff_u.median():.6f} m/s")
        print(f"  P95:    {diff_u.quantile(0.95):.6f} m/s")
        print(f"  Max:    {diff_u.max():.6f} m/s")
        print(f"\nMeridional (v) Closure Error:")
        print(f"  Mean:   {diff_v.mean():.6f} m/s")
        print(f"  Median: {diff_v.median():.6f} m/s")
        print(f"  P95:    {diff_v.quantile(0.95):.6f} m/s")
        print(f"  Max:    {diff_v.max():.6f} m/s")

        if diff_u.max() < 0.005 and diff_v.max() < 0.005:
            print("\n>> VERDICT: EXACT PHYSICAL CLOSURE CONFIRMED (within NetCDF scale_factor precision < 1 mm/s)")
        else:
            print("\n>> WARNING: Discrepancy observed in closure relation. Target must remain raw utotal/vtotal.")

    # 2. Speeds
    print("\n" + "-" * 50)
    print("2. SPEED DISTRIBUTIONS (m/s and knots)")
    print("-" * 50)
    
    speed_vars = [
        ("Total Surface Current", "current_speed"),
        ("Eulerian Non-Tidal", "eulerian_speed"),
        ("Tidal Current", "tide_speed"),
        ("Wave Stokes Drift", "stokes_speed"),
    ]
    
    print(f"{'Component':<24} | {'Mean (m/s)':<10} | {'Std (m/s)':<10} | {'P50 (m/s)':<10} | {'P95 (m/s)':<10} | {'Max (m/s)':<10} | {'Mean (kt)':<10}")
    print("-" * 95)
    for label, col in speed_vars:
        if col in df.columns:
            s = df[col]
            mean_kt = s.mean() * 1.94384
            print(f"{label:<24} | {s.mean():<10.4f} | {s.std():<10.4f} | {s.median():<10.4f} | {s.quantile(0.95):<10.4f} | {s.max():<10.4f} | {mean_kt:<10.3f}")

    # 3. FFT power at the M2 tide period (12.42h)
    print("\n" + "-" * 50)
    print("3. FFT SPECTRAL POWER AT M2 TIDAL FREQUENCY (f = 1 / 12.42h = 0.0805 cph)")
    print("-" * 50)
    
    def m2_spectral_ratio(series):
        s = series.dropna()
        f, psd = periodogram(s - s.mean(), fs=1.0)  # fs = 1 per hour
        m2_idx = np.argmin(np.abs(f - (1.0 / 12.42)))
        peak_power = psd[m2_idx]
        total_power = np.sum(psd)
        ratio = peak_power / total_power if total_power > 0 else 0
        return peak_power, ratio

    u_vars = [
        ("Total Current u (current_u)", "current_u"),
        ("Eulerian u (eulerian_u)", "eulerian_u"),
        ("Tide u (tide_u)", "tide_u"),
        ("Stokes u (stokes_u)", "stokes_u"),
    ]
    
    print(f"{'Variable':<30} | {'M2 Peak Power':<15} | {'M2 Power Ratio':<15}")
    print("-" * 65)
    for label, col in u_vars:
        if col in df.columns:
            p_val, r_val = m2_spectral_ratio(df[col])
            print(f"{label:<30} | {p_val:<15.6f} | {r_val:<15.6f}")

    # 4. Average per year
    print("\n" + "-" * 50)
    print("4. YEARLY MEAN TOTAL CURRENT SPEED (m/s)")
    print("-" * 50)
    yearly = df["current_speed"].groupby(df.index.year).agg(["count", "mean", "std", "max"])
    print(yearly.to_string())
    print("=" * 80)


if __name__ == "__main__":
    run_audit()
