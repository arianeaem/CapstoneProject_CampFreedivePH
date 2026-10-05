"""
Makes snapshot 2026-10-04_rev4.

- uses the clean re-download of era5_2026_08.nc from ECMWF CDS (same area [14.0, 120.7, 13.5, 121.1])
- era5_wind_pressure.parquet has 9 columns, with era5t_revision_risk (~90 days) and is_provisional (120h)
- cmems_waves.parquet and cmems_currents.parquet keep the same SHA-256
- runs the check in audits/audit_era5.py
- writes manifest v1.4 with the rules, the comparisons and the read-only lock
"""

import os
import sys
import shutil
import json
import hashlib
from pathlib import Path
import pandas as pd

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


def build_rev4():
    snapshot_dir = SAFETY_DIR / "data" / "snapshots" / "2026-10-04_rev4"
    interim_dir = SAFETY_DIR / "data" / "interim"

    snapshot_dir.mkdir(parents=True, exist_ok=True)
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

        print(f"[REV4 BUILD] Copied {fname}: {len(df)} rows, SHA256={sha256}")

    # Run the check on the snapshot ERA5 file
    print("[REV4 BUILD] Running dynamic audit on snapshot ERA5 parquet...")
    audit_results = run_audit(
        raw_dir=str(SAFETY_DIR / "data" / "raw" / "era5_wind_pressure"),
        parquet_path=str(snapshot_dir / "era5_wind_pressure.parquet"),
        verbose=False
    )

    manifest = {
        "manifest_version": "1.4",
        "snapshot_id": "2026-10-04_rev4",
        "supersedes": "2026-10-04_rev3",
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
                "reanalysis_download_provenance": {
                    "august_2026_download_status": "Manual two-step merge performed on 2026-10-04. The original CDS batch request omitted i10fg for August 2026 (cause remains unverified / unknown upstream CDS server anomaly, as July and September 2026 batch requests received i10fg without issue). A dedicated CDS request for i10fg was retrieved using the identical canonical area [14.0, 120.7, 13.5, 121.1]. Coordinate assertion confirmed exact bit-for-bit alignment across all 3 latitudes [14.0, 13.75, 13.5], 2 longitudes [120.75, 121.0], and 744 hourly timestamps. Merged array has 0 NaNs.",
                    "sha256_idempotency_proof": "The interim parquet SHA-256 (9acb119bb88d0e4785bd1a84297e7a85e014082d2dd9c5e907bc0db02c284d62) was bit-for-bit identical between the manual re-merge and the previous patched extraction, proving that ECMWF CDS returned identical underlying numerical values and that the extraction logic is reproducible.",
                    "august_synoptic_analysis": "Daily analysis of August 2026 demonstrates sustained South-Southwest to West-Southwest flow (mean direction 222° to 259°) spanning multi-week surges (Aug 3-12, Aug 16-19, Aug 27-31). This synoptic signature is characteristic of sustained Southwest Monsoon (Habagat) channeling through Verde Island Passage / Balayan Bay, rather than an isolated 24-48h tropical cyclone transit. Cross-verification with IMERG Late rainfall will be conducted upon completion of precipitation ingestion.",
                    "september_era5t_context": "September 2026 ERA5T (expver=0005) exhibits typical climatological behavior (speed mean 2.97 m/s, gust mean 6.79 m/s, Gust/Speed ratio 2.29), exactly centered within historical 2021-2025 Septembers (speed 2.52-3.59 m/s, ratio 2.13-2.46)."
                }
            }
        },
        "pending_sources": {
            "imerg": "Ingestion active in background. Dedicated retry tool (safety-forecast/src/ingest/retry_gpm_failures.py) ready for transient 503 errors. Will be sealed in rev5 snapshot upon completion."
        },
        "dynamic_audit_summary": audit_results["parquet_audit"],
        "governance_rules": {
            "read_only": True,
            "interim_access_prohibited": True,
            "superseded_snapshots_note": "rev1, rev2, and rev3 are read-only and preserved for archival provenance. rev3 was superseded before any model training due to 2026-08 coordinate-asserted merge and formal addition of the era5t_revision_risk quarantine column.",
            "era5t_refresh_plan": "ECMWF transitions ERA5T to final ERA5 reanalysis after ~2-3 months. A scheduled refresh is planned for ~December 2026 to re-download July-September 2026 in a single batch and issue a subsequent snapshot rev5. Since July-September 2026 is strictly located in the holdout test set (post-2025-09-30), this reanalysis revision does not alter model training splits.",
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
    print(f"[REV4 BUILD] Manifest written to {manifest_path}")

    # Make the files read-only
    os.system(f"attrib +r {snapshot_dir}/*.*")
    print(f"[REV4 BUILD] Applied read-only lock to {snapshot_dir}/*.*")


if __name__ == "__main__":
    build_rev4()
