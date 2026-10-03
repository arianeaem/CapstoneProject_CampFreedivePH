"""
Shared target variable definitions and constants for marine physics forecasting models.
Decouples runtime serving from model training dependencies (e.g. optuna, xgboost, scikit-learn).
"""

WAVE_TARGETS = ["hs", "tp", "swell_height", "wind_wave_height"]
WIND_REGRESSOR_TARGETS = ["wind_speed", "wind_gust", "delta_p_3h"]
CURRENT_TARGETS = ["current_u", "current_v"]
