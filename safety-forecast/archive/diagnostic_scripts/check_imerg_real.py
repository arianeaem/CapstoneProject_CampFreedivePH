import sys
sys.path.append(r"safety-forecast/src/ingest")
from gpm_precip import fetch_slice_grid, create_session
import pandas as pd
import numpy as np

session = create_session()

# Test different dates backwards from 2026 down to 2024
test_dates = [
    pd.Timestamp("2026-06-01 00:00:00"),
    pd.Timestamp("2025-12-01 00:00:00"),
    pd.Timestamp("2025-06-01 00:00:00"),
    pd.Timestamp("2024-12-31 23:30:00"),
    pd.Timestamp("2024-10-01 00:00:00"),
    pd.Timestamp("2024-06-01 00:00:00"),
]

for dt in test_dates:
    grid = fetch_slice_grid(dt, session, max_retries=1)
    is_valid = not np.isnan(grid).all()
    dt_str = dt.strftime("%Y-%m-%d %H:%M")
    val = float(np.nanmean(grid)) if is_valid else float("nan")
    print(f"IMERG {dt_str} -> Valid data: {is_valid} (mean: {val})")
