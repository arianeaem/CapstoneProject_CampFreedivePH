"""
Conformal p10/p90 ranges for the short-range forecasts (days 1-3).

The p10 and p90 (80% coverage) come from the out-of-fold CV errors:
  e_i = y_i - y_hat_i
  p10 = max(0, y_hat + q10)
  p90 = y_hat + q90

This doesn't assume the errors are normal, it just uses the real errors.
"""

import sys
import json
from pathlib import Path
from typing import Dict, Any, Optional, Tuple

PROJECT_ROOT = Path(__file__).resolve().parents[2]
CALIBRATION_FILE = PROJECT_ROOT / "reports" / "baselines" / "conformal_calibration_p10_p90.json"


class ConformalIntervalCalibrator:
    _instance: Optional["ConformalIntervalCalibrator"] = None
    _table: Dict[str, Dict[str, Dict[str, float]]] = {}

    def __init__(self, table_path: Optional[Path] = None):
        if not self._table:
            path = table_path or CALIBRATION_FILE
            if path.exists():
                with open(path, "r", encoding="utf-8") as f:
                    ConformalIntervalCalibrator._table = json.load(f)

    @classmethod
    def get_instance(cls) -> "ConformalIntervalCalibrator":
        if cls._instance is None:
            cls._instance = cls()
        return cls._instance

    def get_quantiles(self, variable: str, horizon: int, point_forecast: float) -> Tuple[float, float, float]:
        """
        Returns (p10, p50, p90) using the conformal CV errors.
        If the exact horizon isn't in the table, uses a default.
        """
        # Variable name -> calibration key
        key_map = {
            "hs": "waves_hs",
            "eulerian_speed": "currents_eulerian",
            "current_speed": "currents_eulerian"
        }
        calib_key = key_map.get(variable)
        var_table = self._table.get(calib_key, {})

        # Use the closest horizon in [1, 3, 6, 12, 24, 48, 72]
        available_h = [1, 3, 6, 12, 24, 48, 72]
        closest_h = min(available_h, key=lambda x: abs(x - horizon))
        h_key = f"{closest_h}h"

        if h_key in var_table:
            entry = var_table[h_key]
            q10 = entry.get("q10_residual", -0.15)
            q90 = entry.get("q90_residual", +0.15)
            p10 = max(0.0, float(point_forecast + q10))
            p50 = float(point_forecast)
            p90 = max(p10, float(point_forecast + q90))
            return p10, p50, p90

        # Default scaling if there is no calibration table
        scale = (1.0 + horizon / 72.0)
        default_half_width = 0.12 * scale if "wave" in variable or variable == "hs" else 0.08 * scale
        p10 = max(0.0, float(point_forecast - default_half_width))
        p50 = float(point_forecast)
        p90 = max(p10, float(point_forecast + default_half_width))
        return p10, p50, p90
