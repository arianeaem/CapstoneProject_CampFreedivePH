"""
Shared target variables and settings for the forecast models.
Kept separate so serving doesn't need the training libraries (optuna, xgboost, scikit-learn).
"""

WAVE_TARGETS = ["hs", "tp", "swell_height", "wind_wave_height"]
WIND_REGRESSOR_TARGETS = ["wind_speed", "wind_gust", "delta_p_3h"]
CURRENT_TARGETS = ["current_u", "current_v"]

# Clean training range (reanalysis and Final Run only, no provisional data)
TRAINING_WINDOW_START = "2022-11-01"
TRAINING_WINDOW_END = "2025-09-30"

# Daytime hours (Asia/Manila PHT)
OPERATIONAL_HOURS_START = 6   # 06:00 PHT
OPERATIONAL_HOURS_END = 18    # 18:00 PHT

# Gust limit for a squall / hard limit
SQUALL_GUST_THRESHOLD_KMH = 48.0
SQUALL_GUST_THRESHOLD_MS = 48.0 / 3.6  # 13.333 m/s

# Wet day = daily IMERG rain at least this much
WET_DAY_THRESHOLD_MM = 1.0  # mm/day

# Daily rain bands (TODO: check with Coach LC and the team)
# 0: <1 mm (dry), 1: <10 mm (light), 2: <25 mm (moderate), 3: <50 mm (heavy), 4: >=50 mm (very heavy)
RAIN_BANDS = [
    {"band": 0, "max_mm": 1.0,  "label": "Dry / None",      "description": "Negligible rain (< 1 mm/day)"},
    {"band": 1, "max_mm": 10.0, "label": "Light Rain",      "description": "Light showers (< 10 mm/day)"},
    {"band": 2, "max_mm": 25.0, "label": "Moderate Rain",   "description": "Moderate rainfall (< 25 mm/day)"},
    {"band": 3, "max_mm": 50.0, "label": "Heavy Rain",      "description": "Heavy rainfall (< 50 mm/day)"},
    {"band": 4, "max_mm": None, "label": "Torrential Rain", "description": "Extreme/Torrential rain (>= 50 mm/day)"},
]

# Which tail is the bad one
# P90: high values are bad
# P10: low values are bad
ADVERSE_TAILS = {
    "p90": [
        "hs",
        "swell_height",
        "wind_wave_height",
        "current_speed",
        "wind_speed",
        "wind_gust",
        "rain_daily_mm",
    ],
    "p10": [
        "tp",   # Low wave period = short choppy sea state
        "slp",  # Low barometric pressure = tropical cyclones / depressions
    ],
}
