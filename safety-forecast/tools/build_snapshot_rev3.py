"""
Reproducible Snapshot Rev3 Generation Tool for Camp FreedivePH.

Copies verified interim parquet files into snapshot directory 2026-10-04_rev3,
computes dynamic SHA-256 hashes, executes dynamic audits via audit_era5.py,
generates manifest.json, and locks files as read-only.
"""

import os
import sys
import shutil
import json
import hashlib
from pathlib import Path
import pandas as pd

# Add safety-forecast dir to sys.path to allow importing from audits
SAFETY_DIR = Path(__file__).resolve().parents[1]
REPO_ROOT = SAFETY_DIR.parent
if str(SAFETY_DIR) not in sys.path:
    sys.path.insert(0, str(SAFETY_DIR))

from audits.audit_era5 import run_audit


def get_file_hash_and_size(path: Path):
    h = hashlib.sha256()
    with open(path, "rb") as f:
        while chunk := f.read(65536):
            h.update(chunk)
    return h.hexdigest(), path.stat().st_size


def build_rev3():
    snapshot_dir = REPO_ROOT / "safety-forecast" / "data" / "snapshots" / "2026-10-04_rev3"
    interim_dir = REPO_ROOT / "safety-forecast" / "data" / "interim"

    snapshot_dir.mkdir(parents=True, exist_ok=True)

    # Unlock directory if already exists
    os.system(f"attrib -r {snapshot_dir}/*.* >nul 2>&1")

    targets = [
        "cmems_waves.parquet",
        "cmems_currents.parquet",
        "era5_wind_pressure.parquet",
    ]

    file_meta = {}
    for fname in targets:
        src = interim_dir / fname
        dst = snapshot_dir / fname
        if not src.exists():
            raise FileNotFoundError(f"Missing source file: {src}")

        shutil.copy2(src, dst)
        sha256, size_bytes = get_file_hash_and_size(dst)
        df = pd.read_parquet(dst)

        file_meta[fname] = {
            "sha256": sha256,
            "file_size_bytes": size_bytes,
            "row_count": len(df),
            "columns": list(df.columns),
            "start_time_utc": df.index.min().tz_convert("UTC").isoformat(),
            "end_time_utc": df.index.max().tz_convert("UTC").isoformat(),
            "provisional_rows_count": int(df["is_provisional"].sum()) if "is_provisional" in df else 0,
        }
        if "era5t_revision_risk" in df:
            file_meta[fname]["revision_risk_rows_count"] = int(df["era5t_revision_risk"].sum())

        print(f"[REV3 BUILD] Copied {fname}: {len(df)} rows, SHA256={sha256}")

    # Run dynamic audit on the snapshot ERA5 file
    print("[REV3 BUILD] Running dynamic audit on snapshot ERA5 parquet...")
    audit_results = run_audit(
        raw_dir=str(REPO_ROOT / "safety-forecast" / "data" / "raw" / "era5_wind_pressure"),
        parquet_path=str(snapshot_dir / "era5_wind_pressure.parquet"),
        verbose=False
    )

    manifest = {
        "manifest_version": "1.3",
        "snapshot_id": "2026-10-04_rev3",
        "supersedes": "2026-10-04_rev2",
        "created_at_utc": pd.Timestamp.now(tz="UTC").isoformat(),
        "download_date": "2026-10-04",
        "index_timezone": "Asia/Manila",
        "site": {
            "name": "Camp FreedivePH (Bagalangit / Mainit Point, Mabini, Batangas)",
            "latitude": 13.6874,
            "longitude": 120.8931
        },
        "files": {
            "cmems_waves.parquet": {
                **file_meta["cmems_waves.parquet"],
                "dataset_id": "cmems_mod_glo_wav_anfc_0.083deg_PT3H-i",
                "dataset_version": "202411",
                "cell_center": {"lat": 13.6667, "lon": 120.9167},
                "distance_from_site_km": 3.43,
                "location_description": "Maricaban Strait wave analysis cell (0.083° native)",
                "operational_lag_hours": 12,
                "native_time_step_hours": 3
            },
            "cmems_currents.parquet": {
                **file_meta["cmems_currents.parquet"],
                "dataset_id": "cmems_mod_glo_phy_anfc_merged-uv_PT1H-i",
                "dataset_version": "202211",
                "cell_center": {"lat": 13.6667, "lon": 120.8333},
                "distance_from_site_km": 6.86,
                "location_description": "Balayan Bay entrance / Verde Island Passage numerical model current cell (1/12° native)",
                "operational_lag_hours": 24,
                "native_time_step_hours": 1
            },
            "era5_wind_pressure.parquet": {
                **file_meta["era5_wind_pressure.parquet"],
                "dataset_id": "reanalysis-era5-single-levels",
                "dataset_version": "ERA5 / ERA5T (ECMWF CDS API)",
                "spatial_representation": "Bilinear spatial interpolation over 0.25° grid (~31 km resolution) across 4 land-sea mixed grid corners to site coordinates (13.6874°N, 120.8931°E) with corner maximum gust (i10fg)",
                "grid_corners": [
                    {"corner": "NW", "lat": 13.75, "lon": 120.75},
                    {"corner": "NE", "lat": 13.75, "lon": 121.00},
                    {"corner": "SW", "lat": 13.50, "lon": 120.75},
                    {"corner": "SE", "lat": 13.50, "lon": 121.00}
                ],
                "operational_lag_hours": 120,
                "native_time_step_hours": 1,
                "realized_end_utc": "2026-09-29T01:00:00+00:00",
                "nominal_month_end_utc": "2026-09-30T23:00:00+00:00",
                "unreleased_trailing_hours": 46,
                "unreleased_hours_rationale": "ECMWF ERA5T has an operational availability lag of ~5 days (120 hours); the final 46 hours of September 2026 remain unreleased by ECMWF CDS as of 2026-10-04.",
                "manual_patch_documentation": {
                    "file": "era5_2026_08.nc",
                    "patch_date": "2026-10-04",
                    "reason": "Initial CDS monthly request omitted i10fg variable. A dedicated CDS request for i10fg was retrieved, longitude grid-aligned to [120.75, 121.0], and merged into the NetCDF.",
                    "verification": "Post-patch gust stats: min=1.61, mean=10.69, max=20.94 m/s (0 NaNs). Consistent with strong Southwest Monsoon (Habagat) flow in August (mean speed 5.43 m/s, gust/speed ratio 2.09). Recommendation: Re-download unified era5_2026_08.nc in subsequent update once CDS synchronizes."
                }
            }
        },
        "pending_sources": {
            "imerg": "Ingestion active in background. Dedicated retry tool (safety-forecast/src/ingest/retry_gpm_failures.py) ready for transient 503 errors. Will be sealed in rev4 snapshot upon completion."
        },
        "dynamic_audit_summary": audit_results["parquet_audit"],
        "governance_rules": {
            "read_only": True,
            "interim_access_prohibited": True,
            "dual_quarantine_policy": {
                "availability_lag": "is_provisional flags the trailing 120 hours (5 days) ending at last observation for operational serving cutoff simulation.",
                "reanalysis_revision_risk": "era5t_revision_risk flags the trailing ~90 days (2026-07-01 to 2026-09-29) where ERA5T provisional reanalysis is subject to monthly revisions by ECMWF. This period is part of the holdout evaluation set."
            },
            "physical_consistency_caveat": "gust >= speed check is mathematically favored by taking the maximum corner gust against vector-averaged bilinear speed, plus np.maximum lower bound. It does not replace in-situ ground-truth station validation.",
            "squall_hazard_risk_warning": "ERA5 parameterized gust (0.25° grid) does not resolve localized microscale convective squalls. A max gust of 33.75 m/s vs sustained speed of 15.25 m/s (ratio > 2.2x) reflects subgrid parametrization. Rigorous thresholding of 'squall event' must be defined before modeling to meet the PRD squall recall target (>= 80%).",
            "storm_attribution_caveat": "Deepest SLP (991.68 hPa on 2022-10-29) and peak gust (33.75 m/s on 2020-10-26) temporally coincide with STS Paeng and Typhoon Quinta, but require formal PAGASA/JMA best-track distance cross-validation before claiming direct storm association.",
            "model_tide_caveat": "Tidal velocities (utide, vtide) represent 1/12 degree numerical model physics at the 6.86 km offshore cell (13.6667N, 120.8333E), not an in-situ pier tide gauge."
        }
    }

    manifest_path = snapshot_dir / "manifest.json"
    with open(manifest_path, "w", encoding="utf-8") as f:
        json.dump(manifest, f, indent=2)
    print(f"[REV3 BUILD] Manifest written to {manifest_path}")

    # Set read-only attributes
    os.system(f"attrib +r {snapshot_dir}/*.*")
    print(f"[REV3 BUILD] Applied read-only lock to {snapshot_dir}/*.*")


if __name__ == "__main__":
    build_rev3()
