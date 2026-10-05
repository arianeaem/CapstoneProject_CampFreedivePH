"""
Shared constants and per-source window configurations for Camp FreedivePH.

Per-Source Windows (Decoupled & Measured):
1. Wind & Pressure (ECMWF ERA5 / ERA5T):
   - Archive: 2020-10-01 to 2026-09-30
   - Operational Lag: ~5 days (T-120h)
2. Precipitation (NASA GPM IMERG):
   - Final Run V07B: 2020-10-01 to 2025-09-30 (Day 273)
   - Late Run V07: 2023-01-01 to 2026-10-03 (~14h lag; 33 months overlap with Final for calibration)
3. Ocean Currents (CMEMS SMOC Hourly Instantaneous with Tides):
   - Analysis Archive: 2020-11-01 to 2026-10-03 (capped at analysis cutoff; excludes forecast)
   - Operational Lag: ~24 hours (T-24h)
4. Ocean Waves:
   - 1/12° Analysis (001_027): 2022-11-01 to 2026-10-03 (capped at analysis cutoff; excludes forecast)
   - 0.20° Reanalysis (WAVERYS): 2020-10-01 to 2026-05-31 (extends through 2026 to provide 3.5y overlap)
   - Operational Lag: ~12 hours (T-12h)

Model Training Strategy:
- Atmospheric & Current models: Train on 2020-11-01 to 2025-09-30 (full 5-year multi-source history).
- Wave models: Train on 2022-11-01 to 2025-09-30 on 1/12° analysis data (or WAVERYS-calibrated 5y).
"""

from pathlib import Path
import pandas as pd


SAFETY_FORECAST_DIR = Path(__file__).resolve().parents[2]
PROJECT_ROOT = SAFETY_FORECAST_DIR.parent
DATA_ROOT = SAFETY_FORECAST_DIR / "data"

# Balayan Bay / Verde Island Passage bounding box (Anilao/Mabini dive sites)
LON_MIN, LON_MAX = 120.7, 121.1
LAT_MIN, LAT_MAX = 13.5, 14.0
SITE_LAT, SITE_LON = 13.6874, 120.8931  # exact dive-site point (Bagalangit / Mainit Point, Mabini)
TARGET_TIMEZONE = "Asia/Manila"  # UTC+08:00 (PHT)

# =============================================================================
# CANONICAL INGESTION VARIABLE LISTS
# =============================================================================
VARIABLES = {
    "waves":    ["VHM0", "VTPK", "VHM0_SW1", "VHM0_SW2", "VHM0_WW"],
    "currents": ["utotal", "vtotal", "uo", "vo", "utide", "vtide", "vsdx", "vsdy"],
    "era5":     ["10m_u_component_of_wind", "10m_v_component_of_wind",
                 "instantaneous_10m_wind_gust", "mean_sea_level_pressure"],
    "imerg":    ["precipitation"],
}

# =============================================================================
# PER-SOURCE EMPIRICALLY MEASURED WINDOWS & OPERATIONAL LAGS
# =============================================================================

# 1. ECMWF ERA5 Atmosphere (u10, v10, max-corner gust, msl)
ERA5_START_DATE = "2020-10-01"
ERA5_END_DATE   = "2026-09-29 01:00"  # Realized ECMWF CDS cutoff (due to ~5-day ERA5T latency; 46h unreleased at end of Sep 2026)
ERA5_LAG_HOURS  = 120                 # ~5 days latency
ERA5T_REVISION_RISK_DAYS = 90         # ECMWF provisional revision risk window (~3 months)

# 2. NASA GPM IMERG Precipitation
IMERG_FINAL_START_DATE = "2020-10-01"
IMERG_FINAL_END_DATE   = "2025-09-30"  # Day 273 of 2025 (verified via GES DISC directory)
IMERG_LATE_START_DATE  = "2024-12-01"  # 10-month overlap (Amihan + dry + Habagat: 2024-12-01 to 2025-09-30) + holdout
IMERG_LATE_END_DATE    = "2026-10-03"  # Operational cutoff (Day 276 of 2026)
IMERG_LAG_HOURS        = 14            # ~14 hours latency

# 3. CMEMS Ocean Currents (1/12° Hourly Instantaneous SMOC - utotal, vtotal, uo, vo, utide, vtide, vsdx, vsdy)
# Capped at last realized analysis timestamp (excludes forecast horizon per PRD 7.5)
CURRENT_SMOC_START_DATE = "2020-11-01" # Verified: 2020-11-01 to present
CURRENT_SMOC_END_DATE   = "2026-10-03" # Analysis issue cutoff (excludes +10d forecast)
CURRENT_LAG_HOURS       = 24           # ~24 hours latency

# 4. CMEMS Ocean Waves
# Option A: 1/12° Analysis (~4 years homogeneous channel data, 3.4 km from site)
WAVE_ANALYSIS_START_DATE = "2022-11-01 03:00:00" # Verified start from CMEMS coordinate bounds (03:00 UTC)
WAVE_ANALYSIS_END_DATE   = "2026-10-03" # Analysis issue cutoff (excludes +10d forecast)
WAVE_LAG_HOURS           = 12           # ~12 hours latency

# Option B: 0.2° WAVERYS Reanalysis (Extended to May 2026 to provide 3.5y overlap for calibration)
WAVE_REANALYSIS_START_DATE = "2020-10-01"
WAVE_REANALYSIS_END_DATE   = "2026-05-31"

# Backward compatibility defaults
START_DATE = "2022-01-01"
END_DATE = "2024-12-31"

RAW_DIR = DATA_ROOT / "raw"
INTERIM_DIR = DATA_ROOT / "interim"
PROCESSED_DIR = DATA_ROOT / "processed"
OBSERVED_STORE_DIR = DATA_ROOT / "observed_store"
CACHE_DIR = DATA_ROOT / "cache"

for _d in (DATA_ROOT, RAW_DIR, INTERIM_DIR, PROCESSED_DIR, OBSERVED_STORE_DIR, CACHE_DIR):
    _d.mkdir(parents=True, exist_ok=True)

# =============================================================================
# MODEL TRAINING WINDOWS & OVERLAP RULES
# =============================================================================
TRAINING_WINDOWS = {
    # Independent single-target models train on their maximum clean windows
    "atmosphere": {"start": "2020-11-01", "end": "2025-09-30"},
    "currents":   {"start": "2020-11-01", "end": "2025-09-30"},
    "rain":       {"start": "2020-11-01", "end": "2025-09-30"},
    "waves_opt_a":{"start": "2022-11-01", "end": "2025-09-30"}, # Pure 1/12° Analysis (~4 years)
    "waves_opt_b":{"start": "2020-11-01", "end": "2026-05-31"}, # WAVERYS Calibrated (5+ years)
}

CALIBRATION_WINDOWS = {
    # WAVERYS (0.2°) to 1/12° Analysis overlap: 3.5 years (2022-11-01 to 2026-05-31)
    "waves_waverys_to_analysis": {
        "fit_start": "2022-11-01",
        "fit_end": "2024-12-31",   # 26-month training fit
        "test_start": "2025-01-01",
        "test_end": "2026-05-31",  # 17-month holdout evaluation
    },
    # IMERG Late (0.1°) to Final Run overlap: 10 months (2024-12-01 to 2025-09-30)
    "imerg_late_to_final": {
        "fit_start": "2024-12-01",
        "fit_end": "2025-09-30",
    }
}

OPERATIONAL_LAGS = {
    "era5": pd.Timedelta(hours=120),       # ~5 days (T-120h)
    "currents": pd.Timedelta(hours=24),    # ~24 hours (T-24h)
    "rain_late": pd.Timedelta(hours=14),   # ~14 hours (T-14h)
    "waves": pd.Timedelta(hours=12),       # ~12 hours (T-12h)
}


def get_serving_cutoff(source: str, reference_time_utc: pd.Timestamp = None) -> pd.Timestamp:
    """
    Returns the maximum available observation timestamp for a source given operational lag.
    Guarantees serving/training cuts each variable at its true lag boundary.
    """
    ref = reference_time_utc or pd.Timestamp.now(tz="UTC")
    lag = OPERATIONAL_LAGS.get(source.lower(), pd.Timedelta(hours=0))
    return ref - lag


def target_hourly_index(start_date: str = None, end_date: str = None, tz: str = TARGET_TIMEZONE) -> pd.DatetimeIndex:
    """
    Generates the canonical hourly index localized to Asia/Manila (PHT, UTC+08:00).
    Guarantees no 8-hour diurnal phase slip in downstream operations.
    """
    s = start_date or f"{START_DATE} 00:00:00"
    e = end_date or f"{END_DATE} 23:00:00"
    
    idx_utc = pd.date_range(s, e, freq="1h", tz="UTC")
    if tz == "UTC":
        return idx_utc
    return idx_utc.tz_convert(tz)

