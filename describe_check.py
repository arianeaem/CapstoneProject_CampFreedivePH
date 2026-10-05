import os
import sys
import pandas as pd
import copernicusmarine as cm

# Disable tqdm monitor and progress bars to prevent race condition crashes
os.environ["TQDM_DISABLE"] = "1"

DATASETS_TO_CHECK = [
    ("Wave 1/12° Analysis", "cmems_mod_glo_wav_anfc_0.083deg_PT3H-i"),
    ("Current Hourly Merged-UV (SMOC)", "cmems_mod_glo_phy_anfc_merged-uv_PT1H-i"),
    ("Current 1/12° Hourly Mean", "cmems_mod_glo_phy_anfc_0.083deg_PT1H-m"),
    ("Current 1/12° 6-Hourly Instantaneous", "cmems_mod_glo_phy-cur_anfc_0.083deg_PT6H-i"),
    ("Current 1/12° Daily Mean", "cmems_mod_glo_phy_anfc_0.083deg_P1D-m"),
    ("Current 1/12° Daily Mean (cur)", "cmems_mod_glo_phy-cur_anfc_0.083deg_P1D-m"),
]

def format_time_val(val):
    if val is None or val == "unknown":
        return "Unknown"
    try:
        if isinstance(val, (int, float)):
            # If timestamp in milliseconds since 1970
            if val > 1e11:
                return str(pd.to_datetime(val, unit="ms", utc=True))
            elif val > 1e8:
                return str(pd.to_datetime(val, unit="s", utc=True))
        return str(pd.to_datetime(val, utc=True))
    except Exception:
        return str(val)

print("=" * 85)
print("COPERNICUS MARINE DATASET TEMPORAL EXTENTS & METADATA AUDIT")
print("=" * 85)

for label, did in DATASETS_TO_CHECK:
    print(f"\n--- Checking: {label} ({did}) ---")
    try:
        desc = cm.describe(dataset_id=did, disable_progress_bar=True)
        if not desc or not desc.products:
            print(f"  [ERROR] No metadata returned for {did}")
            continue
        
        p = desc.products[0]
        d = p.datasets[0]
        v = d.versions[0]
        part = v.parts[0]
        
        t_min_raw, t_max_raw = None, None
        vars_found = []
        depth_levels = None
        
        for s in part.services:
            for coord in (getattr(s, "coordinates", None) or []):
                if coord.coordinate_id == "time":
                    t_min_raw = coord.minimum_value
                    t_max_raw = coord.maximum_value
                if coord.coordinate_id == "depth":
                    depth_levels = getattr(coord, "values", None) or getattr(coord, "maximum_value", None)
            
            for var in (getattr(s, "variables", None) or []):
                units = getattr(var, "units", "")
                vars_found.append(f"{var.short_name} ({units})" if units else var.short_name)
            
            if t_min_raw is not None:
                break
        
        t_start = format_time_val(t_min_raw)
        t_end = format_time_val(t_max_raw)
        
        print(f"  Product ID:  {p.product_id}")
        print(f"  Dataset ID:  {d.dataset_id}")
        print(f"  Start Date:  {t_start}")
        print(f"  End Date:    {t_end}")
        print(f"  Variables:   {', '.join(vars_found[:8])}")
        if depth_levels is not None:
            print(f"  Depth:       {depth_levels}")
            
    except Exception as e:
        print(f"  [ERROR] Could not fetch metadata for {did}: {e}")

print("\n" + "=" * 85)
