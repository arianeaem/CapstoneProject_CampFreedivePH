"""
Tests for the demand-forecast pipeline.

Run from the demand-forecast folder:
    python -m pytest tests -q
Needs ML_TOKEN in the environment (any value works for tests):
    $env:ML_TOKEN="test"; python -m pytest tests -q
"""
import json
import os
import sys
import unittest

import pandas as pd

BASE_DIR = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
sys.path.insert(0, BASE_DIR)
os.environ.setdefault("ML_TOKEN", "test-token")

import retrain_pipeline as rp  # noqa: E402


class ActualDataOnlyTests(unittest.TestCase):
    def test_no_synthetic_generator_exists(self):
        self.assertFalse(hasattr(rp, "generate_baseline_history"))

    def test_history_is_the_553_record_batches(self):
        df = rp.load_actual_history()
        self.assertEqual(len(df), 85)
        self.assertTrue(df["primary_source"].astype(str).str.contains("553 Google Form").all())
        self.assertGreater(df["participant_count"].sum(), 0)

    def test_history_rejects_synthetic_rows(self):
        import tempfile
        df = rp.load_actual_history()
        df.loc[0, "primary_source"] = "Synthetic baseline"
        with tempfile.TemporaryDirectory() as d:
            path = os.path.join(d, "h.csv")
            df.to_csv(path, index=False)
            with self.assertRaises(ValueError):
                rp.load_actual_history(path)

    def test_history_missing_file_does_not_fall_back_to_fake_data(self):
        with self.assertRaises(FileNotFoundError):
            rp.load_actual_history(os.path.join(BASE_DIR, "data", "does_not_exist.csv"))


class DemandRulesTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.cfg = rp.derive_demand_config(rp.load_actual_history())

    def test_demand_level_boundaries(self):
        low, med = self.cfg["demand_level"]["low_max"], self.cfg["demand_level"]["medium_max"]
        self.assertEqual(rp.classify_demand_level(low, self.cfg), "Low")
        self.assertEqual(rp.classify_demand_level(low + 0.1, self.cfg), "Medium")
        self.assertEqual(rp.classify_demand_level(med, self.cfg), "Medium")
        self.assertEqual(rp.classify_demand_level(med + 0.1, self.cfg), "High")

    def test_all_three_seasons_are_identified_from_actual_data(self):
        seasons = set(self.cfg["season"]["by_month"].values())
        self.assertEqual(seasons, {"Peak", "Shoulder", "Off-Peak"})
        self.assertEqual(len(self.cfg["season"]["by_month"]), 12)

    def test_season_matches_seasonality_csv(self):
        sea = pd.read_csv(os.path.join(BASE_DIR, "data", "seasonality_12_months.csv"))
        for _, r in sea.iterrows():
            self.assertEqual(rp.season_for_month(int(r["month"]), self.cfg), r["season_period"],
                             f"month {int(r['month'])}")

    def test_shipped_json_matches_what_pipeline_derives(self):
        with open(os.path.join(BASE_DIR, "demand_thresholds.json"), encoding="utf-8") as f:
            shipped = json.load(f)
        self.assertEqual(shipped["demand_level"]["low_max"], self.cfg["demand_level"]["low_max"])
        self.assertEqual(shipped["demand_level"]["medium_max"], self.cfg["demand_level"]["medium_max"])
        self.assertEqual(shipped["season"]["by_month"], self.cfg["season"]["by_month"])


class PerBatchForecastTests(unittest.TestCase):
    """The forecast is made for REAL scheduled batches; totals are sums of those batches."""

    class _ConstModel:
        def __init__(self, value):
            self.value = value

        def predict(self, X):
            return [self.value] * len(X)

    class _LagModel:
        """Predicts from the lag feature, so a leak between batches would change the result."""

        def predict(self, X):
            return [float(v) + 1.0 for v in X["participant_count_lag_1"]]

    @classmethod
    def setUpClass(cls):
        cls.cfg = rp.derive_demand_config(rp.load_actual_history())
        cls.history = rp.load_actual_history()

    def _rows(self, batches, pax=14.0):
        models = {
            "participant_count": self._ConstModel(pax),
            "booking_count": self._ConstModel(6.0),
            "class_revenue": self._ConstModel(40000.0),
        }
        return rp.build_batch_forecasts(
            batches, models, self.history, 14.5, 14.5, self.cfg,
            {"participant_count": 5.0}, "test-v1", "limited_history", "2026-10-03 00:00:00",
            pd.Timestamp("2026-10-03"),
        )

    def test_one_row_per_scheduled_batch_in_date_order(self):
        rows = self._rows([
            {"batch_id": 2, "batch_code": "B", "start_date": "2026-10-17", "capacity": 45, "booked_so_far": 3},
            {"batch_id": 1, "batch_code": "A", "start_date": "2026-10-10", "capacity": 45, "booked_so_far": 1},
        ])
        self.assertEqual([r["batch_code"] for r in rows], ["A", "B"])
        self.assertEqual(rows[0]["days_to_start"], 7)

    def test_no_batches_means_no_forecast_rows(self):
        self.assertEqual(rp.monthly_rollup([], self.cfg), [])

    def test_forecast_is_never_below_what_is_already_booked(self):
        rows = self._rows([{"batch_id": 1, "batch_code": "A", "start_date": "2026-10-10", "capacity": 45, "booked_so_far": 30}], pax=14.0)
        self.assertEqual(rows[0]["predicted_participants"], 30.0)
        self.assertTrue(rows[0]["adjusted_for_booked"])
        self.assertGreaterEqual(rows[0]["lower_bound"], 30.0)

    def test_interval_and_fill_rate(self):
        rows = self._rows([{"batch_id": 1, "batch_code": "A", "start_date": "2026-10-10", "capacity": 40, "booked_so_far": 0}], pax=20.0)
        r = rows[0]
        self.assertLessEqual(r["lower_bound"], r["predicted_participants"])
        self.assertGreaterEqual(r["upper_bound"], r["predicted_participants"])
        self.assertLessEqual(r["upper_bound"], 40)
        self.assertAlmostEqual(r["predicted_fill_rate"], 0.5, places=3)

    def test_a_batch_forecast_does_not_change_when_other_batches_are_added(self):
        models = {
            "participant_count": self._LagModel(),
            "booking_count": self._ConstModel(6.0),
            "class_revenue": self._ConstModel(40000.0),
        }
        a = {"batch_id": 1, "batch_code": "A", "start_date": "2026-11-07", "capacity": 45, "booked_so_far": 0}
        extra = {"batch_id": 2, "batch_code": "X", "start_date": "2026-10-09", "capacity": 45, "booked_so_far": 0}

        def run(batches):
            return rp.build_batch_forecasts(
                batches, models, self.history, 14.5, 14.5, self.cfg, {"participant_count": 5.0},
                "test-v1", "limited_history", "2026-10-03 00:00:00", pd.Timestamp("2026-10-03"))

        alone = [r for r in run([a]) if r["batch_code"] == "A"][0]
        with_other = [r for r in run([extra, a]) if r["batch_code"] == "A"][0]
        self.assertEqual(alone["predicted_participants"], with_other["predicted_participants"])

    def test_labels_come_from_the_shared_rules(self):
        rows = self._rows([{"batch_id": 1, "batch_code": "A", "start_date": "2026-10-10", "capacity": 45, "booked_so_far": 0}], pax=25.0)
        self.assertEqual(rows[0]["demand_level"], rp.classify_demand_level(25.0, self.cfg))
        self.assertEqual(rows[0]["season_period"], rp.season_for_month(10, self.cfg))
        self.assertEqual(rows[0]["data_basis"], "limited_history")
        self.assertEqual(rows[0]["model_version"], "test-v1")

    def test_monthly_rollup_is_the_sum_of_batches(self):
        rows = self._rows([
            {"batch_id": 1, "batch_code": "A", "start_date": "2026-10-10", "capacity": 45, "booked_so_far": 0},
            {"batch_id": 2, "batch_code": "B", "start_date": "2026-10-17", "capacity": 45, "booked_so_far": 0},
            {"batch_id": 3, "batch_code": "C", "start_date": "2026-11-07", "capacity": 45, "booked_so_far": 0},
        ])
        monthly = rp.monthly_rollup(rows, self.cfg)
        self.assertEqual([m["month"] for m in monthly], ["2026-10", "2026-11"])
        self.assertEqual(monthly[0]["batches"], 2)
        oct_total = sum(r["predicted_participants"] for r in rows[:2])
        self.assertAlmostEqual(monthly[0]["predicted_participants"], oct_total, places=1)
        self.assertAlmostEqual(monthly[0]["avg_participants_per_batch"], oct_total / 2, places=1)

    def test_data_basis_flag(self):
        today = pd.Timestamp("2026-10-03")
        self.assertEqual(rp.determine_data_basis(85, pd.Timestamp("2025-11-29"), today), "limited_history")
        self.assertEqual(rp.determine_data_basis(200, pd.Timestamp("2026-09-20"), today), "real_history")
        self.assertEqual(rp.determine_data_basis(200, pd.Timestamp("2025-11-29"), today), "limited_history")


class ForecastOutputTests(unittest.TestCase):
    """Only meaningful AFTER `python retrain_pipeline.py` has been run."""

    def setUp(self):
        self.path = os.path.join(BASE_DIR, "outputs", "forecast.csv")
        if not os.path.exists(self.path):
            self.skipTest("outputs/forecast.csv not generated yet")

    def test_forecast_is_future_dated_and_labelled(self):
        df = pd.read_csv(self.path, parse_dates=["forecast_date"])
        self.assertTrue((df["forecast_date"] > pd.Timestamp.today().normalize() - pd.Timedelta(days=1)).all())
        self.assertTrue(df["demand_level"].isin(["Low", "Medium", "High"]).all())
        self.assertTrue(df["season_period"].isin(["Peak", "Shoulder", "Off-Peak"]).all())

    def test_forecast_never_overlaps_actual_history(self):
        fc = pd.read_csv(self.path, parse_dates=["forecast_date"])
        hist = rp.load_actual_history()
        self.assertTrue(fc["forecast_date"].min() > hist["batch_date"].max())
        self.assertFalse(set(fc["forecast_date"]).intersection(set(hist["batch_date"])))

    def test_forecast_demand_matches_rules(self):
        cfg = rp.derive_demand_config(rp.load_actual_history())
        df = pd.read_csv(self.path)
        for _, r in df.iterrows():
            self.assertEqual(r["demand_level"], rp.classify_demand_level(r["predicted_participants"], cfg))

    def test_model_artifacts_exist(self):
        for name in ("participant_count", "booking_count", "class_revenue"):
            self.assertTrue(os.path.exists(os.path.join(BASE_DIR, "outputs", "models", f"{name}_model.joblib")))


if __name__ == "__main__":
    unittest.main()
