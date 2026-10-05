"""
Site Forecaster Module for Camp FreedivePH.
Provides POST /forecast/site microservice backend logic.

Capabilities:
1. Spatial verification: Validates lat/lon against Camp FreedivePH Batangas operational area.
2. Store Freshness Check: Flags stale store and degrades gracefully to Climatology (degraded: true).
3. Horizon Routing & Cutoffs:
   - hs: Model up to 48h (H < 6 clamped to h6; interpolated between 6, 12, 24, 36, 48). Climatology beyond 48h.
   - current_speed: Model up to 72h (H < 24 clamped to h24; interpolated between 24, 48, 72). Climatology beyond 72h.
   - Separate interpolation of predicted anomaly and conformal quantiles [q10, q90].
   - All other variables (wind, gust, slp, tp, swell, wind_wave, tide, stokes, eulerian): Climatology.
4. Daily aggregates: rain_mm, p_wet (Wilson CI), p_high_gust (Wilson CI), wind direction bands.
5. Strict contract guarantees: p10 <= p50 <= p90 everywhere, continuous hourly timestamps.
"""

import json
from pathlib import Path
from typing import Dict, List, Optional, Tuple, Any
import joblib
import numpy as np
import pandas as pd

from src.serve.serving_feature_extractor import ServingFeatureExtractor

PROJECT_ROOT = Path(__file__).resolve().parents[2]
MODELS_DIR = PROJECT_ROOT / "models" / "short_range"
PROCESSED_DIR = PROJECT_ROOT / "data" / "processed"
STORE_DIR = PROJECT_ROOT / "data" / "store"

SITE_LAT = 13.6874
SITE_LON = 120.8931
TARGET_TIMEZONE = "Asia/Manila"
MAX_DISTANCE_KM = 25.0

HS_HORIZONS = [6, 12, 24, 36, 48]
CURRENT_HORIZONS = [24, 48, 72]

HS_CUTOFF_H = 48
CURRENT_CUTOFF_H = 72


MAX_LAG_HOURS = {
    "hs": 24.0,           # CMEMS Waves analysis operational cutoff lag
    "current_speed": 48.0 # CMEMS SMOC Currents operational cutoff lag
}


def haversine_distance_km(lat1: float, lon1: float, lat2: float, lon2: float) -> float:
    """Calculates great-circle distance between two points in km."""
    r = 6371.0
    phi1, phi2 = np.radians(lat1), np.radians(lat2)
    dphi = np.radians(lat2 - lat1)
    dlambda = np.radians(lon2 - lon1)
    a = np.sin(dphi / 2.0)**2 + np.cos(phi1) * np.cos(phi2) * np.sin(dlambda / 2.0)**2
    return float(2.0 * r * np.arctan2(np.sqrt(a), np.sqrt(1.0 - a)))


class OutOfAreaError(ValueError):
    pass


class SiteForecaster:
    def __init__(self):
        self.clim_df = pd.read_parquet(PROCESSED_DIR / "climatology.parquet")
        # MultiIndex lookup by (day_of_year, hour_pht)
        self.clim_lookup = self.clim_df.set_index(["day_of_year", "hour_pht"])

        with open(MODELS_DIR / "short_range_manifest.json", "r", encoding="utf-8") as f:
            self.manifest = json.load(f)

        # Load models
        self.hs_models = {}
        for h in HS_HORIZONS:
            p = MODELS_DIR / f"hs_h{h}.joblib"
            if p.exists():
                self.hs_models[h] = joblib.load(p)

        self.current_models = {}
        for h in CURRENT_HORIZONS:
            p = MODELS_DIR / f"current_speed_h{h}.joblib"
            if p.exists():
                self.current_models[h] = joblib.load(p)

        # Conformal intervals
        self.hs_quantiles = {}
        for h in HS_HORIZONS:
            info = self.manifest["models"]["hs"]["horizons"][f"h{h}"]
            self.hs_quantiles[h] = (info["conformal_q10"], info["conformal_q90"])

        self.current_quantiles = {}
        for h in CURRENT_HORIZONS:
            info = self.manifest["models"]["current_speed"]["horizons"][f"h{h}"]
            self.current_quantiles[h] = (info["conformal_q10"], info["conformal_q90"])

        # Feature extractor
        self.feature_extractor = None
        self._init_feature_extractor()

    def _init_feature_extractor(self):
        store_path = STORE_DIR / "observed_store.parquet"
        if store_path.exists():
            try:
                self.feature_extractor = ServingFeatureExtractor()
            except Exception as e:
                print(f"Warning: Could not initialize ServingFeatureExtractor: {e}")
                self.feature_extractor = None

    def check_store_status(self, issued_at_ts: pd.Timestamp) -> Tuple[bool, Optional[str], Dict[str, float]]:
        """
        Checks if observed store is missing or stale on a per-variable basis.
        Returns: (is_stale, reason, var_lags)
        """
        meta_path = STORE_DIR / "store_meta.json"
        if not meta_path.exists() or self.feature_extractor is None:
            return True, "Observed store missing or unreadable", {}

        try:
            with open(meta_path, "r", encoding="utf-8") as f:
                meta = json.load(f)
            last_obs = meta.get("last_observation_at", {})
            if not last_obs:
                return True, "No last_observation_at metadata found", {}

            var_lags = {}
            stale_notes = []

            for var, max_lag in MAX_LAG_HOURS.items():
                last_time_str = last_obs.get(var)
                if not last_time_str:
                    var_lags[var] = 9999.0
                    stale_notes.append(f"{var} has no observation timestamp")
                    continue

                last_ts = pd.Timestamp(last_time_str)
                if last_ts.tzinfo is None:
                    last_ts = last_ts.tz_localize(TARGET_TIMEZONE)
                else:
                    last_ts = last_ts.tz_convert(TARGET_TIMEZONE)

                lag = (issued_at_ts - last_ts).total_seconds() / 3600.0
                var_lags[var] = lag
                if lag > max_lag:
                    stale_notes.append(f"{var} lag is {lag:.1f}h (limit: {max_lag}h)")

            is_stale = len(stale_notes) > 0
            reason = f"Store observations exceed operational lag limits: {'; '.join(stale_notes)}" if is_stale else None
            return is_stale, reason, var_lags
        except Exception as ex:
            return True, f"Error validating store metadata: {ex}", {}

    def predict_hs(
        self,
        t0: pd.Timestamp,
        H: int,
        target_ts: pd.Timestamp,
        degraded: bool,
        hs_lag: float = 0.0
    ) -> Dict[str, Any]:
        doy = min(target_ts.dayofyear, 366)
        hour = target_ts.hour
        clim_row = self.clim_lookup.loc[(doy, hour)]
        clim_p50 = float(clim_row["hs_p50"])
        clim_p10 = float(clim_row["hs_p10"])
        clim_p90 = float(clim_row["hs_p90"])

        if degraded or H > HS_CUTOFF_H or hs_lag > MAX_LAG_HOURS["hs"] or self.feature_extractor is None:
            return {
                "source": "climatology",
                "p10": clim_p10,
                "p50": clim_p50,
                "p90": clim_p90,
                "horizon_h": H
            }

        # Effective horizon clamped to min 6
        H_eff = max(6, H)

        # Find bounding horizons
        if H_eff in HS_HORIZONS:
            model = self.hs_models[H_eff]
            feats = self.feature_extractor.extract_features("hs", t0, H_eff)
            anomaly = float(model.predict(feats.reshape(1, -1))[0])
            q10, q90 = self.hs_quantiles[H_eff]
        else:
            # Interpolate between adjacent horizons
            h_below = max([h for h in HS_HORIZONS if h <= H_eff])
            h_above = min([h for h in HS_HORIZONS if h >= H_eff])
            alpha = (H_eff - h_below) / float(h_above - h_below)

            m_b = self.hs_models[h_below]
            m_a = self.hs_models[h_above]

            f_b = self.feature_extractor.extract_features("hs", t0, h_below)
            f_a = self.feature_extractor.extract_features("hs", t0, h_above)

            anom_b = float(m_b.predict(f_b.reshape(1, -1))[0])
            anom_a = float(m_a.predict(f_a.reshape(1, -1))[0])
            anomaly = (1.0 - alpha) * anom_b + alpha * anom_a

            q10_b, q90_b = self.hs_quantiles[h_below]
            q10_a, q90_a = self.hs_quantiles[h_above]
            q10 = (1.0 - alpha) * q10_b + alpha * q10_a
            q90 = (1.0 - alpha) * q90_b + alpha * q90_a

        p50 = max(0.0, clim_p50 + anomaly)
        p10 = max(0.0, p50 + q10)
        p90 = max(p50, p50 + q90)
        # Monotonicity
        p10 = min(p10, p50)
        p90 = max(p90, p50)

        return {
            "source": "model",
            "p10": round(p10, 4),
            "p50": round(p50, 4),
            "p90": round(p90, 4),
            "anomaly": round(anomaly, 4),
            "horizon_h": H
        }

    def predict_current_speed(
        self,
        t0: pd.Timestamp,
        H: int,
        target_ts: pd.Timestamp,
        degraded: bool,
        current_lag: float = 0.0
    ) -> Dict[str, Any]:
        doy = min(target_ts.dayofyear, 366)
        hour = target_ts.hour
        clim_row = self.clim_lookup.loc[(doy, hour)]
        clim_p50 = float(clim_row["current_speed_p50"])
        clim_p10 = float(clim_row["current_speed_p10"])
        clim_p90 = float(clim_row["current_speed_p90"])

        if degraded or H > CURRENT_CUTOFF_H or current_lag > MAX_LAG_HOURS["current_speed"] or self.feature_extractor is None:
            return {
                "source": "climatology",
                "p10": clim_p10,
                "p50": clim_p50,
                "p90": clim_p90,
                "horizon_h": H
            }

        # Effective horizon clamped to min 24
        H_eff = max(24, H)

        if H_eff in CURRENT_HORIZONS:
            model = self.current_models[H_eff]
            feats = self.feature_extractor.extract_features("current_speed", t0, H_eff)
            anomaly = float(model.predict(feats.reshape(1, -1))[0])
            q10, q90 = self.current_quantiles[H_eff]
        else:
            h_below = max([h for h in CURRENT_HORIZONS if h <= H_eff])
            h_above = min([h for h in CURRENT_HORIZONS if h >= H_eff])
            alpha = (H_eff - h_below) / float(h_above - h_below)

            m_b = self.current_models[h_below]
            m_a = self.current_models[h_above]

            f_b = self.feature_extractor.extract_features("current_speed", t0, h_below)
            f_a = self.feature_extractor.extract_features("current_speed", t0, h_above)

            anom_b = float(m_b.predict(f_b.reshape(1, -1))[0])
            anom_a = float(m_a.predict(f_a.reshape(1, -1))[0])
            anomaly = (1.0 - alpha) * anom_b + alpha * anom_a

            q10_b, q90_b = self.current_quantiles[h_below]
            q10_a, q90_a = self.current_quantiles[h_above]
            q10 = (1.0 - alpha) * q10_b + alpha * q10_a
            q90 = (1.0 - alpha) * q90_b + alpha * q90_a

        p50 = max(0.0, clim_p50 + anomaly)
        p10 = max(0.0, p50 + q10)
        p90 = max(p50, p50 + q90)
        p10 = min(p10, p50)
        p90 = max(p90, p50)

        return {
            "source": "model",
            "p10": round(p10, 4),
            "p50": round(p50, 4),
            "p90": round(p90, 4),
            "anomaly": round(anomaly, 4),
            "horizon_h": H
        }

    def forecast_site(
        self,
        lat: float,
        lon: float,
        issued_at: Optional[str] = None,
        days: int = 10,
        force_stale: bool = False
    ) -> Dict[str, Any]:
        dist = haversine_distance_km(lat, lon, SITE_LAT, SITE_LON)
        if dist > MAX_DISTANCE_KM:
            raise OutOfAreaError(
                f"Coordinates ({lat:.4f}, {lon:.4f}) are {dist:.1f} km from site, exceeding the {MAX_DISTANCE_KM} km operational limit."
            )

        if issued_at is not None:
            t0 = pd.Timestamp(issued_at)
            if t0.tzinfo is None:
                t0 = t0.tz_localize(TARGET_TIMEZONE)
            else:
                t0 = t0.tz_convert(TARGET_TIMEZONE)
        else:
            t0 = pd.Timestamp.now(tz=TARGET_TIMEZONE)

        is_stale, stale_reason, var_lags = self.check_store_status(t0)
        degraded = bool(force_stale or is_stale)
        if force_stale and not stale_reason:
            stale_reason = "Simulated stale store condition (force_stale=True)"

        total_hours = min(max(1, days * 24), 384)
        target_timestamps = [t0 + pd.Timedelta(hours=h) for h in range(1, total_hours + 1)]

        hourly_results = []
        days_seen = set()
        daily_results = []

        other_vars = [
            "wind_speed", "wind_gust", "slp", "tp", "swell_height",
            "wind_wave_height", "eulerian_speed", "tide_speed", "stokes_speed"
        ]

        hs_lag = var_lags.get("hs", 0.0)
        curr_lag = var_lags.get("current_speed", 0.0)

        for h, target_ts in enumerate(target_timestamps, start=1):
            doy = min(target_ts.dayofyear, 366)
            hour = target_ts.hour
            clim_row = self.clim_lookup.loc[(doy, hour)]

            # Freshness is enforced per model input. A stale waves feed must not
            # disable a fresh currents model (and vice versa).
            hs_res = self.predict_hs(
                t0, h, target_ts, force_stale, hs_lag
            )
            curr_res = self.predict_current_speed(
                t0, h, target_ts, force_stale, curr_lag
            )

            row = {
                "forecast_time": str(target_ts),
                "lead_hours": h,
                "hs": hs_res,
                "current_speed": curr_res,
            }

            for v in other_vars:
                p10 = float(clim_row[f"{v}_p10"])
                p50 = float(clim_row[f"{v}_p50"])
                p90 = float(clim_row[f"{v}_p90"])
                v_unit = "m/s" if ("speed" in v or "gust" in v) else ("hPa" if v == "slp" else ("s" if v == "tp" else "m"))
                v_res = {
                    "source": "climatology",
                    "p10": round(min(p10, p50), 4),
                    "p50": round(p50, 4),
                    "p90": round(max(p90, p50), 4),
                    "unit": v_unit,
                }
                # Explicit SI raw (m/s) and converted km/h for wind and gust
                if v in ["wind_speed", "wind_gust"]:
                    v_res["raw_si_unit"] = "m/s"
                    v_res["raw_p50_ms"] = round(p50, 3)
                    v_res["p10_kmh"] = round(min(p10, p50) * 3.6, 2)
                    v_res["p50_kmh"] = round(p50 * 3.6, 2)
                    v_res["p90_kmh"] = round(max(p90, p50) * 3.6, 2)
                row[v] = v_res

            # Wind direction
            row["wind_dir_circ_mean_deg"] = float(clim_row["wind_dir_circ_mean_deg"])
            row["wind_dir_resultant_R"] = float(clim_row["wind_dir_resultant_R"])

            hourly_results.append(row)

            date_str = str(target_ts.date())
            if date_str not in days_seen:
                days_seen.add(date_str)
                daily_results.append({
                    "date": date_str,
                    "day_of_year": doy,
                    "rain_daily_mm_p50": round(float(clim_row["rain_daily_mm_p50"]), 2),
                    "rain_daily_mm_p90": round(float(clim_row["rain_daily_mm_p90"]), 2),
                    "p_wet": float(clim_row["p_wet_day"]),
                    "p_wet_ci": [float(clim_row["p_wet_ci_lo"]), float(clim_row["p_wet_ci_hi"])],
                    "p_high_gust": float(clim_row["p_high_gust_day"]),
                    "p_high_gust_ci": [float(clim_row["p_high_gust_ci_lo"]), float(clim_row["p_high_gust_ci_hi"])],
                    "prob_band_0_offshore_nne": float(clim_row["prob_band_0_offshore_nne"]),
                    "prob_band_1_ese": float(clim_row["prob_band_1_ese"]),
                    "prob_band_2_s_wnw": float(clim_row["prob_band_2_s_wnw"]),
                    "prob_band_3_onshore_habagat": float(clim_row["prob_band_3_onshore_habagat"]),
                })

        return {
            "site": {
                "name": "Camp FreedivePH (Bagalangit / Mainit Point, Batangas)",
                "latitude": lat,
                "longitude": lon,
                "distance_km": round(dist, 2),
                "timezone": TARGET_TIMEZONE
            },
            "issued_at": str(t0),
            "degraded": degraded,
            "degraded_reason": stale_reason if degraded else None,
            "operational_cutoffs": {
                "hs_hours": HS_CUTOFF_H,
                "current_speed_hours": CURRENT_CUTOFF_H,
                "max_lag_hours": MAX_LAG_HOURS,
                "rule": "Beyond cutoff, variable source transitions to climatology."
            },
            "forecast_hourly": hourly_results,
            "forecast_daily": daily_results
        }


_FORECASTER: Optional[SiteForecaster] = None

def get_site_forecaster() -> SiteForecaster:
    global _FORECASTER
    if _FORECASTER is None:
        _FORECASTER = SiteForecaster()
    return _FORECASTER
