"""
Hard safety limits and the forecast horizon rules.
This runs after xgb_safety_classifier. If any limit is broken or there is a PAGASA
storm signal, the result becomes Critical Risk no matter what the classifier said.

Horizon rules (from our test results):
- H = 1h (just before leaving):
    * ML safety level is shown: "TACTICAL_CLEARANCE"
    * Missed Critical = 4.5%, precision = 96.8%
    * This is the one used for the final go/no-go at the dock.
- 1h < H <= 24h (6h, 12h, 24h):
    * ML safety level is hidden: "PROVISIONAL_TREND_OUTLOOK"
    * Missed Critical = 40.9% - 54.5% (about a coin flip).
    * Showing "Safe" here would be misleading, so we hide the level and show
      the miss rate, the raw values, the P90 values and the hard limits instead.
      Planning only, the final check is at T-1h.
- H > 24h (48h, 72h, 96h, 144h):
    * ML safety level is hidden: "EXTENDED_TREND_OUTLOOK"
    * Missed Critical = 82% - 100% (it just goes back to the average).
    * Shows the raw values, the P90 values and the hard limits.

The limits come from build_safety_labels.py, they are not typed again here.

Run from the project root: python src/serve/safety_thresholds.py
"""

import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[2]))

from src.labels.build_safety_labels import SAFETY_THRESHOLDS, HARD_GATE, KMH_TO_MS  # noqa: E402

TIER_NAMES = ["Very Safe", "Safe", "Moderate", "High Risk", "Critical Risk"]
TIER_CRITICAL = 4
TIER_NO_CONSTRAINT = 0  # value used in max() when no limit is broken

TACTICAL_GO_NO_GO_HORIZON_HOURS = 1
PROVISIONAL_CUTOFF_HORIZON_HOURS = 24


def check_physical_breach(telemetry: dict) -> tuple[bool, list[str]]:
    """
    Check each reading against the hard limits.

    1. If wind, gusts, waves, swell, current, rain or low pressure is over the limit,
       it's unsafe no matter what the model says.
    2. A fast pressure drop (>= 2.5 hPa in 3h) can mean a storm. But here the pressure
       normally drops 1.5 - 2.0 hPa every afternoon, so a fast drop only counts
       if there are also strong gusts (>= 38 km/h) or heavy rain (>= 15 mm/hr).

    Parameters:
        telemetry (dict):
            - wind_speed (float): wind in m/s
            - wind_gust (float): gust in m/s
            - hs (float): wave height in m
            - swell_height (float): swell height in m
            - current_speed (float): current in m/s
            - rain_rate_mm_hr (float): rain in mm/hr
            - slp (float): pressure in hPa
            - delta_p_3h (float, optional): pressure change over 3 hours in hPa

    Returns:
        tuple[bool, list[str]]: (broken, list of reasons)
    """
    reasons = []

    limits = SAFETY_THRESHOLDS if "SAFETY_THRESHOLDS" in globals() else HARD_GATE

    if telemetry.get("wind_speed", 0.0) >= limits["wind_speed_ms"]:
        reasons.append(f"sustained wind {telemetry['wind_speed']:.1f} m/s >= "
                        f"{limits['wind_speed_ms']:.2f} m/s limit")
    if telemetry.get("wind_gust", 0.0) >= limits["wind_gust_ms"]:
        reasons.append(f"wind gust {telemetry['wind_gust']:.1f} m/s >= "
                        f"{limits['wind_gust_ms']:.2f} m/s limit")
    if telemetry.get("hs", 0.0) >= limits["wave_height_m"]:
        reasons.append(f"wave height {telemetry['hs']:.2f} m >= {limits['wave_height_m']} m limit")
    if telemetry.get("swell_height", 0.0) >= limits["swell_height_m"]:
        reasons.append(f"swell height {telemetry['swell_height']:.2f} m >= "
                        f"{limits['swell_height_m']} m limit")
    if telemetry.get("current_speed", 0.0) >= limits["current_ms"]:
        reasons.append(f"current {telemetry['current_speed']:.2f} m/s >= {limits['current_ms']} m/s limit")
    if telemetry.get("rain_rate_mm_hr", 0.0) >= limits["rain_mm_hr"]:
        reasons.append(f"rain rate {telemetry['rain_rate_mm_hr']:.1f} mm/hr >= "
                        f"{limits['rain_mm_hr']} mm/hr limit")
    if telemetry.get("slp", 1013.25) <= limits["pressure_hpa"]:
        reasons.append(f"pressure {telemetry['slp']:.1f} hPa <= {limits['pressure_hpa']} hPa limit")

    delta_p = telemetry.get("delta_p_3h", 0.0)
    pressure_drop = abs(delta_p) if delta_p < 0 else delta_p
    wind_gust_ms = telemetry.get("wind_gust", 0.0)
    rain_rate = telemetry.get("rain_rate_mm_hr", 0.0)
    squall_gust_threshold_ms = 38.0 * KMH_TO_MS  # 10.56 m/s (38 km/h)
    storm_rain_threshold_mm = 15.0  # mm/hr

    if pressure_drop >= 2.5 and (wind_gust_ms >= squall_gust_threshold_ms or rain_rate >= storm_rain_threshold_mm):
        reasons.append(
            f"rapid barometric drop ({pressure_drop:.1f} hPa/3h) with accompanying squalls/rain "
            f"({wind_gust_ms * 3.6:.1f} km/h gusts, {rain_rate:.1f} mm/hr rain)"
        )

    return len(reasons) > 0, reasons


def check_pagasa_override(pagasa: dict | None = None) -> tuple[bool, list[str]]:
    """
    Check PAGASA storm signals, gale warnings and tsunami alerts.

    Official warnings win over the models. Signal #3 or higher, gale warnings
    and tsunami warnings mean no boats and no diving.

    Parameters:
        pagasa (dict | None):
            - tcws_signal (int): storm signal (0-5)
            - gale_warning (bool): Coast Guard gale warning
            - tsunami_warning (bool): PHIVOLCS tsunami warning

    Returns:
        tuple[bool, list[str]]: (broken, list of warnings)
    """
    if pagasa is None:
        return False, []

    reasons = []
    if pagasa.get("tcws_signal", 0) >= 3:
        reasons.append(f"PAGASA TCWS Signal #{pagasa['tcws_signal']} active")
    if pagasa.get("gale_warning", False):
        reasons.append("PAGASA Gale Warning in effect")
    if pagasa.get("tsunami_warning", False):
        reasons.append("Active tsunami warning")
    return len(reasons) > 0, reasons


def apply_safety_thresholds(ml_prediction: int, telemetry: dict, pagasa: dict | None = None) -> dict:
    """
    Apply the hard limits on top of the ML result.

    final tier = max(ml_prediction, limit tier, pagasa tier)
    So the limits can only make the risk higher, never lower.

    Parameters:
        ml_prediction (int): class from xgb_safety_classifier (0 = Very Safe .. 4 = Critical Risk)
        telemetry (dict): weather and sea readings
        pagasa (dict | None): PAGASA warnings

    Returns:
        dict:
            - final_tier (int): tier after the limits (0-4)
            - final_tier_name (str): e.g. 'Critical Risk'
            - ml_prediction (int): classifier result before the limits
            - ml_prediction_name (str): its label
            - safety_threshold_triggered (bool): True if any limit or warning was hit
            - hard_gate_triggered (bool): same as above (old name)
            - override_reasons (list[str]): why
    """
    physical_breach, physical_reasons = check_physical_breach(telemetry)
    pagasa_breach, pagasa_reasons = check_pagasa_override(pagasa)

    threshold_tier = TIER_CRITICAL if (physical_breach or pagasa_breach) else TIER_NO_CONSTRAINT
    final_tier = max(ml_prediction, threshold_tier)

    all_reasons = physical_reasons + pagasa_reasons
    triggered = physical_breach or pagasa_breach
    return {
        "final_tier": final_tier,
        "final_tier_name": TIER_NAMES[final_tier],
        "ml_prediction": ml_prediction,
        "ml_prediction_name": TIER_NAMES[ml_prediction],
        "safety_threshold_triggered": triggered,
        "hard_gate_triggered": triggered,  # Backward-compatible alias
        "override_reasons": all_reasons if all_reasons else ["within all physical/advisory limits"],
    }


# Old name, kept so older code still works
apply_hard_gate = apply_safety_thresholds


def evaluate_operational_safety(
    horizon_hours: int,
    ml_prediction: int,
    telemetry: dict,
    pagasa: dict | None = None
) -> dict:
    """
    Apply the 3 horizon bands.

    - Band 1 (H = 1h): "TACTICAL_CLEARANCE"
      Shows the ML safety level + hard limits.
      Missed Critical = 4.5%, precision = 96.8%.

    - Band 2 (1h < H <= 24h, so 6h, 12h, 24h): "PROVISIONAL_TREND_OUTLOOK"
      Hides the ML level (displayed_tier = null).
      Missed Critical = 40.9% - 54.5%. Shows raw values, P90 and the hard limits for planning.

    - Band 3 (H > 24h, so 48h, 72h, 96h, 144h): "EXTENDED_TREND_OUTLOOK"
      Hides the ML level (displayed_tier = null).
      Missed Critical = 82% - 100%. Shows raw values, P90 and the hard limits.
    """
    threshold_result = apply_safety_thresholds(ml_prediction, telemetry, pagasa)

    if horizon_hours <= TACTICAL_GO_NO_GO_HORIZON_HOURS:
        operational_status = "TACTICAL_CLEARANCE"
        is_safety_verdict_active = True
        displayed_tier = threshold_result["final_tier"]
        displayed_tier_name = threshold_result["final_tier_name"]
        advisory_message = (
            "Real-time tactical clearance (1h). High model fidelity (Critical FNR: 4.5%, "
            "Precision: 96.8%). Directly authorizes boat departure / dive dispatch."
        )
    elif horizon_hours <= PROVISIONAL_CUTOFF_HORIZON_HOURS:
        operational_status = "PROVISIONAL_TREND_OUTLOOK"
        is_safety_verdict_active = False
        displayed_tier = None  # level is hidden
        displayed_tier_name = "SUPPRESSED_PROVISIONAL_TREND"
        advisory_message = (
            f"Provisional planning outlook ({horizon_hours}h ahead). Discrete safety tier is SUPPRESSED "
            "because models at this range historically miss ~45% of dangerous conditions (FNR: 40.9%-54.5%) "
            "due to early MSE variance smoothing. Displaying raw physics, P90 tail bounds, and safety limit alerts "
            "for tentative planning; formal safety clearance is strictly evaluated at T-1h."
        )
    else:
        operational_status = "EXTENDED_TREND_OUTLOOK"
        is_safety_verdict_active = False
        displayed_tier = None  # level is hidden
        displayed_tier_name = "SUPPRESSED_FOR_EXTENDED_HORIZON"
        advisory_message = (
            f"Extended macro outlook ({horizon_hours}h ahead). Discrete safety tier is SUPPRESSED "
            "due to climatological mean-regression (Critical FNR: 82%-100%). Displaying physical trajectory, "
            "P90 tail risk, and safety limit alerts for advance trip scheduling; re-evaluate as conditions "
            "approach T-24h and T-1h."
        )

    return {
        "horizon_hours": horizon_hours,
        "operational_status": operational_status,
        "is_safety_verdict_active": is_safety_verdict_active,
        "displayed_tier": displayed_tier,
        "displayed_tier_name": displayed_tier_name,
        "ml_raw_prediction": ml_prediction,
        "ml_raw_prediction_name": TIER_NAMES[ml_prediction],
        "safety_threshold_triggered": threshold_result["safety_threshold_triggered"],
        "hard_gate_triggered": threshold_result["hard_gate_triggered"],  # Backward-compatible alias
        "override_reasons": threshold_result["override_reasons"],
        "advisory_message": advisory_message,
        "telemetry": telemetry,
    }


# ---------------------------------------------------------------------------
# Tests
# ---------------------------------------------------------------------------
def _run_tests():
    print("Running safety threshold and operational cutoff test suite...\n")
    failures = []
    total_checks = [0]

    def check(name, condition, message):
        total_checks[0] += 1
        status = "PASS" if condition else "FAIL"
        print(f"  [{status}] {name}")
        if not condition:
            failures.append(f"{name}: {message}")

    calm = {"wind_speed": 3.0, "wind_gust": 4.0, "hs": 0.3, "swell_height": 0.2,
            "current_speed": 0.1, "rain_rate_mm_hr": 0.0, "slp": 1012.0}

    # 1. Calm conditions -> no override
    r = apply_safety_thresholds(0, calm)
    check("calm -> final_tier is 0", r["final_tier"] == 0, f"got {r}")
    check("calm -> not triggered", not r["safety_threshold_triggered"], f"got {r}")

    # 2. Limits must never lower a higher ML result, even if the sea is calm
    r = apply_safety_thresholds(3, calm)
    check("ML=3 calm -> final_tier stays 3", r["final_tier"] == 3, f"got {r}")
    check("ML=3 calm -> not triggered", not r["safety_threshold_triggered"], f"got {r}")

    # 3. Sustained wind breach
    w_breach = dict(calm, wind_speed=11.0)  # > 10.56 m/s
    r = apply_safety_thresholds(0, w_breach)
    check("wind breach -> final_tier is 4", r["final_tier"] == 4, f"got {r}")
    check("wind breach -> triggered is True", r["safety_threshold_triggered"] is True, f"got {r}")

    # 4. Wind gust breach
    g_breach = dict(calm, wind_gust=14.0)  # > 13.33 m/s
    r = apply_safety_thresholds(1, g_breach)
    check("gust breach -> final_tier is 4", r["final_tier"] == 4, f"got {r}")

    # 5. Wave height breach
    hs_breach = dict(calm, hs=2.0)
    r = apply_safety_thresholds(0, hs_breach)
    check("hs breach -> final_tier is 4", r["final_tier"] == 4, f"got {r}")

    # 6. Swell height breach
    sw_breach = dict(calm, swell_height=1.9)
    r = apply_safety_thresholds(0, sw_breach)
    check("swell breach -> final_tier is 4", r["final_tier"] == 4, f"got {r}")

    # 7. Current speed breach
    c_breach = dict(calm, current_speed=0.9)
    r = apply_safety_thresholds(0, c_breach)
    check("current breach -> final_tier is 4", r["final_tier"] == 4, f"got {r}")

    # 8. Rain rate breach
    r_breach = dict(calm, rain_rate_mm_hr=26.0)
    r = apply_safety_thresholds(0, r_breach)
    check("rain breach -> final_tier is 4", r["final_tier"] == 4, f"got {r}")

    # 9. Pressure breach
    p_breach = dict(calm, slp=995.0)
    r = apply_safety_thresholds(0, p_breach)
    check("pressure breach -> final_tier is 4", r["final_tier"] == 4, f"got {r}")

    # 10. PAGASA signal 3
    r = apply_safety_thresholds(0, calm, {"tcws_signal": 3})
    check("PAGASA signal 3 -> final_tier is 4", r["final_tier"] == 4, f"got {r}")
    check("PAGASA signal 3 -> triggered is True", r["safety_threshold_triggered"] is True, f"got {r}")

    # 11. PAGASA gale warning
    r = apply_safety_thresholds(0, calm, {"gale_warning": True})
    check("PAGASA gale -> final_tier is 4", r["final_tier"] == 4, f"got {r}")

    # 12. PAGASA tsunami warning
    r = apply_safety_thresholds(0, calm, {"tsunami_warning": True})
    check("tsunami -> final_tier is 4", r["final_tier"] == 4, f"got {r}")

    # 13. PAGASA signal 1 or 2 alone is not Critical
    r = apply_safety_thresholds(0, calm, {"tcws_signal": 2})
    check("PAGASA signal 2 -> final_tier is 0 (no critical override)", r["final_tier"] == 0, f"got {r}")
    check("PAGASA signal 2 -> not triggered", not r["safety_threshold_triggered"], f"got {r}")

    # 14. 1h horizon -> level is shown
    op1 = evaluate_operational_safety(1, 0, calm)
    check("1h horizon -> TACTICAL_CLEARANCE", op1["operational_status"] == "TACTICAL_CLEARANCE", f"got {op1}")
    check("1h horizon -> is_safety_verdict_active is True", op1["is_safety_verdict_active"] is True, f"got {op1}")
    check("1h horizon -> displayed_tier is 0", op1["displayed_tier"] == 0, f"got {op1}")

    # 15. 6h horizon -> level is hidden
    op6 = evaluate_operational_safety(6, 0, calm)
    check("6h horizon -> PROVISIONAL_TREND_OUTLOOK", op6["operational_status"] == "PROVISIONAL_TREND_OUTLOOK", f"got {op6}")
    check("6h horizon -> is_safety_verdict_active is False", op6["is_safety_verdict_active"] is False, f"got {op6}")
    check("6h horizon -> displayed_tier is None", op6["displayed_tier"] is None, f"got {op6}")

    # 16. 72h with a broken limit -> still flagged
    op72_breach = evaluate_operational_safety(72, 0, w_breach)
    check("72h with breach -> triggered is True", op72_breach["safety_threshold_triggered"] is True, f"got {op72_breach}")
    check("72h horizon -> EXTENDED_TREND_OUTLOOK", op72_breach["operational_status"] == "EXTENDED_TREND_OUTLOOK", f"got {op72_breach}")
    check("72h horizon -> displayed_tier is None (suppressed)", op72_breach["displayed_tier"] is None, f"got {op72_breach}")

    # 17. Normal afternoon pressure drop with no gusts or rain -> not broken
    diurnal_drop = dict(calm, delta_p_3h=-2.6, wind_gust=5.0, rain_rate_mm_hr=0.0)
    r_diurnal = apply_safety_thresholds(0, diurnal_drop)
    check("diurnal drop (-2.6 hPa) with calm wind -> no breach",
          not r_diurnal["safety_threshold_triggered"], f"got {r_diurnal}")

    # 18. Fast pressure drop with strong gusts (>= 38 km/h / 10.56 m/s) -> broken
    storm_drop_gust = dict(calm, delta_p_3h=-2.6, wind_gust=11.0, rain_rate_mm_hr=0.0)
    r_storm_gust = apply_safety_thresholds(0, storm_drop_gust)
    check("storm precursor drop (-2.6 hPa) + squall gust (11 m/s) -> breach",
          r_storm_gust["safety_threshold_triggered"] is True and r_storm_gust["final_tier"] == 4, f"got {r_storm_gust}")

    # 19. Fast pressure drop with heavy rain (>= 15 mm/hr) -> broken
    storm_drop_rain = dict(calm, delta_p_3h=-2.6, wind_gust=5.0, rain_rate_mm_hr=16.0)
    r_storm_rain = apply_safety_thresholds(0, storm_drop_rain)
    check("storm precursor drop (-2.6 hPa) + storm rain (16 mm/hr) -> breach",
          r_storm_rain["safety_threshold_triggered"] is True and r_storm_rain["final_tier"] == 4, f"got {r_storm_rain}")

    print(f"\n{total_checks[0]} checks run.")
    if failures:
        print(f"FAILED {len(failures)} checks:")
        for f in failures:
            print(f"  - {f}")
        sys.exit(1)
    else:
        print("All safety threshold and operational cutoff tests passed.")


if __name__ == "__main__":
    _run_tests()
