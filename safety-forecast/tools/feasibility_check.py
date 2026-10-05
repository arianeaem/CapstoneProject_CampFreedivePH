import os as _os
from pathlib import Path as _Path

# Run from the safety-forecast folder so the relative data/, reports/ and ../camp-freedive-ph/.env paths work
_os.chdir(_Path(__file__).resolve().parents[1])

#!/usr/bin/env python
"""
feasibility_check.py -- can a model beat climatology for one variable, and out to which horizon?

Run (from safety-forecast/):
    python tools/feasibility_check.py --data data/processed/collocated.parquet --target hs
    python tools/feasibility_check.py --data <file> --target slp
    python tools/feasibility_check.py --data <file> --target wind_speed
    python tools/feasibility_check.py --data <file> --target current_speed   # built from current_u/current_v

With data lags (recommended) and an optional date window:
    python tools/feasibility_check.py --data <file> --target hs \
        --lags "hs=12,tp=12,swell_height=12,wind_wave_height=12,current_speed=24,eulerian_speed=24,tide_speed=24,stokes_speed=24,wind_speed=120,wind_gust=120,slp=120" \
        --end 2025-09-30

What it does (hourly, continuous variables only):
  * Forecast origin o = the moment the forecast is issued ("now"). Horizons H are counted from o.
    Each variable's newest observation is older than o by its data lag (--lags), e.g. ERA5 ~120 h.
    Features only use data up to o - lag, so the skill shown is what you could really get when issuing.
    With no --lags the script assumes zero lag (optimistic).
  * Baselines: climatology (day-of-year smoothed +/-15 d, plus hour-of-day offset) and persistence.
    Climatology is fit ONLY on training hours of each fold.
  * Model: gradient boosting on [anomaly lags, rolling anomaly means, other variables at t0 and
    their 24 h change, climatology at target time, day-of-year sin/cos]. It predicts the anomaly
    (value minus climatology), so at long horizons it falls back toward climatology.
  * Validation: 5 contiguous time blocks; training origins within (horizon + 168 h) of the test
    block are purged on both sides, so no target or lookback window overlaps the test period.
  * Skill = 1 - MAE(model) / MAE(climatology). 95% CI from a block bootstrap (168 h blocks).
    "usable" = CI lower bound > 0.

NOT covered yet: wind/current direction (circular), gust events and wet/dry rain (classification),
p10/p90 calibration, and the one-time holdout. This only answers: is there skill, and how far out?
"""
import argparse
import sys
from pathlib import Path

import numpy as np
import pandas as pd
from sklearn.ensemble import HistGradientBoostingRegressor

HORIZONS = [24, 72, 120, 168, 240]   # hours after the last observation
LAGS = [0, 3, 6, 12, 24, 48, 72, 168]
ROLLS = [24, 72, 168]
MAX_LOOKBACK = 168
STRIDE = 3                           # one forecast origin every 3 h
K_FOLDS = 5
N_BOOT = 500
BLOCK_H = 168


def load(path: str) -> pd.DataFrame:
    p = Path(path)
    df = pd.read_csv(p) if p.suffix == ".csv" else pd.read_parquet(p)
    if not isinstance(df.index, pd.DatetimeIndex):
        for c in ("timestamp", "time", "datetime", "date"):
            if c in df.columns:
                df = df.set_index(pd.to_datetime(df[c])).drop(columns=[c])
                break
    if not isinstance(df.index, pd.DatetimeIndex):
        sys.exit("Need a DatetimeIndex or a timestamp/time column.")
    df = df.sort_index()
    df = df[~df.index.duplicated()]
    df = df.asfreq("h")
    df = df.interpolate(limit=6)     # fill short gaps only
    if "current_speed" not in df and {"current_u", "current_v"} <= set(df.columns):
        df["current_speed"] = np.hypot(df["current_u"], df["current_v"])
    if "wind_speed" not in df and {"wind_u", "wind_v"} <= set(df.columns):
        df["wind_speed"] = np.hypot(df["wind_u"], df["wind_v"])
    return df


PRIORITY = ["slp", "wind_speed", "wind_gust", "hs", "tp", "swell_height", "wind_wave_height",
            "current_speed", "eulerian_speed", "tide_speed", "stokes_speed"]


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


def run(df, target, horizons, lags, default_lag=0):
    idx = df.index
    n = len(idx)
    doy = np.minimum(idx.dayofyear.to_numpy(), 366)
    hour = idx.hour.to_numpy()
    x = df[target].to_numpy(float)
    aux = {c: df[c].to_numpy(float) for c in pick_aux(df, target)}
    lag_t = lags.get(target, default_lag)
    lag_a = {c: lags.get(c, default_lag) for c in aux}
    max_lag = max([lag_t] + list(lag_a.values()))
    all_origins = np.arange(MAX_LOOKBACK + max_lag, n, STRIDE)
    out = {}

    for H in horizons:
        valid = all_origins[all_origins + H < n]
        fold = np.minimum((np.arange(len(valid)) * K_FOLDS) // len(valid), K_FOLDS - 1)
        rec = {k: [] for k in ("t0", "y", "clim", "pers", "mdl")}
        margin = H + MAX_LOOKBACK + max_lag

        for k in range(K_FOLDS):
            test_o = valid[fold == k]
            if test_o.size == 0:
                continue
            t_start, t_end = test_o.min(), test_o.max() + H
            pos = np.arange(n)
            train_hours = ~((pos >= t_start - margin) & (pos <= t_end + margin))
            train_o = valid[(valid < t_start - margin) | (valid > t_end + margin)]
            if train_o.size < 200:
                continue

            sm, hod = fit_clim(x, doy, hour, train_hours)
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

            Xtr, ytr = feats(train_o), (x[train_o + H] - c[train_o + H])
            keep = np.isfinite(ytr)
            model = HistGradientBoostingRegressor(
                loss="absolute_error", max_iter=120, learning_rate=0.03,
                max_leaf_nodes=7, min_samples_leaf=300, l2_regularization=5.0, random_state=0)
            model.fit(Xtr[keep], ytr[keep])

            Xte = feats(test_o)
            truth = x[test_o + H]
            ok = np.isfinite(truth) & np.isfinite(c[test_o + H]) & np.isfinite(x[test_o - lag_t])
            pred = c[test_o + H] + model.predict(Xte)
            rec["t0"].append(test_o[ok]); rec["y"].append(truth[ok])
            rec["clim"].append(c[test_o + H][ok]); rec["pers"].append(x[test_o - lag_t][ok])
            rec["mdl"].append(pred[ok])

        out[H] = {k: np.concatenate(v) for k, v in rec.items()} if rec["y"] else None
    return out, list(aux)


def skill_ci(d, rng):
    e_m, e_c = np.abs(d["mdl"] - d["y"]), np.abs(d["clim"] - d["y"])
    blocks = d["t0"] // BLOCK_H
    uniq, inv = np.unique(blocks, return_inverse=True)
    sm = np.bincount(inv, e_m, len(uniq)); sc = np.bincount(inv, e_c, len(uniq))
    cnt = np.bincount(inv, minlength=len(uniq))
    est = 1 - sm.sum() / sc.sum()
    boots = []
    for _ in range(N_BOOT):
        s = rng.integers(0, len(uniq), len(uniq))
        boots.append(1 - sm[s].sum() / sc[s].sum())
    lo, hi = np.percentile(boots, [2.5, 97.5])
    return est, lo, hi, int(cnt.sum())


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--data", required=True)
    ap.add_argument("--target", default="hs")
    ap.add_argument("--out", default="reports")
    ap.add_argument("--lags", default="", help='data lag in hours per column, e.g. "slp=120,hs=12"')
    ap.add_argument("--default-lag", type=int, default=0)
    ap.add_argument("--start", default=None, help="first timestamp to use (e.g. 2022-11-01)")
    ap.add_argument("--end", default=None, help="last timestamp to use (e.g. 2025-09-30)")
    args = ap.parse_args()

    df = load(args.data)
    if args.start:
        df = df.loc[pd.Timestamp(args.start, tz=df.index.tz):]
    if args.end:
        df = df.loc[:pd.Timestamp(args.end, tz=df.index.tz) + pd.Timedelta(hours=23)]
    lags = {}
    for item in filter(None, args.lags.split(",")):
        k, v = item.split("=")
        lags[k.strip()] = int(v)
    if args.target not in df.columns:
        sys.exit(f"'{args.target}' not in columns: {list(df.columns)}")
    print(f"Data: {df.index[0]} -> {df.index[-1]}  ({len(df)} hourly rows, "
          f"{df[args.target].isna().mean()*100:.1f}% NaN in target)")

    res, aux = run(df, args.target, HORIZONS, lags, args.default_lag)
    print(f"Other variables used as features: {aux}")
    print(f"Data lags used (h): target={lags.get(args.target, args.default_lag)}, "
          f"others={ {c: lags.get(c, args.default_lag) for c in aux} }\n")
    rng = np.random.default_rng(0)
    rows = []
    print(f"{'H(h)':>5} {'n':>6} {'MAE clim':>9} {'MAE pers':>9} {'MAE model':>10} {'skill':>7} {'95% CI':>16}  usable")
    for H in HORIZONS:
        d = res[H]
        if d is None:
            print(f"{H:>5}  (not enough data)")
            continue
        mae = {k: float(np.mean(np.abs(d[k] - d["y"]))) for k in ("clim", "pers", "mdl")}
        est, lo, hi, n = skill_ci(d, rng)
        usable = lo > 0
        rows.append(dict(horizon_h=H, n=n, mae_clim=mae["clim"], mae_pers=mae["pers"],
                         mae_model=mae["mdl"], skill=est, ci_lo=lo, ci_hi=hi, usable=usable))
        print(f"{H:>5} {n:>6} {mae['clim']:>9.3f} {mae['pers']:>9.3f} {mae['mdl']:>10.3f} "
              f"{est:>7.3f} [{lo:>6.3f},{hi:>6.3f}]  {'YES' if usable else 'no'}")
    Path(args.out).mkdir(exist_ok=True)
    pd.DataFrame(rows).to_csv(Path(args.out) / f"feasibility_{args.target}{'_lag' if lags else ''}.csv", index=False)
    good = [r["horizon_h"] for r in rows if r["usable"]]
    print(f"\nLongest horizon with skill over climatology: {max(good) if good else 'none'} h "
          f"(counted from the moment of issue, lags applied)")


if __name__ == "__main__":
    main()
