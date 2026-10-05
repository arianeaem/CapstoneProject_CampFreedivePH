import os as _os
from pathlib import Path as _Path

# Run from the safety-forecast folder so the relative data/, reports/ and ../camp-freedive-ph/.env paths work
_os.chdir(_Path(__file__).resolve().parents[1])

import subprocess
import sys
from pathlib import Path

LAGS = "hs=12,tp=12,swell_height=12,wind_wave_height=12,current_speed=24,eulerian_speed=24,tide_speed=24,stokes_speed=24,wind_speed=120,wind_gust=120,slp=120"
TARGETS = ["hs", "slp", "wind_speed", "current_speed"]
DATA = "data/processed/collocated.parquet"

print("================================================================================")
print("PART 1: FULL DATASET WITH OPERATIONAL DATA LAGS (INCLUDES PROVISIONAL UP TO 2026-09)")
print("================================================================================\n")

for target in TARGETS:
    print(f"\n>>> RUNNING TARGET: {target} (Full dataset with lags) <<<\n")
    cmd = [
        sys.executable, "tools/feasibility_check.py",
        "--data", DATA,
        "--target", target,
        "--lags", LAGS,
        "--out", "reports/with_lags_full"
    ]
    subprocess.run(cmd, check=True)

print("\n================================================================================")
print("PART 2: VERIFIED REANALYSIS ONLY (--end 2025-09-30, NO PROVISIONAL DATA)")
print("================================================================================\n")

for target in TARGETS:
    print(f"\n>>> RUNNING TARGET: {target} (--end 2025-09-30 with lags) <<<\n")
    cmd = [
        sys.executable, "tools/feasibility_check.py",
        "--data", DATA,
        "--target", target,
        "--lags", LAGS,
        "--end", "2025-09-30",
        "--out", "reports/with_lags_non_provisional"
    ]
    subprocess.run(cmd, check=True)

print("\nALL FEASIBILITY RUNS COMPLETED SUCCESSFULLY!")
