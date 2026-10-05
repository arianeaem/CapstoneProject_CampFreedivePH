import copernicusmarine as cm
import pandas as pd
import json

datasets_to_check = [
    "cmems_mod_glo_wav_anfc_0.083deg_PT3H-i",
    "cmems_mod_glo_phy-cur_anfc_0.083deg_PT6H-i",
    "cmems_mod_glo_phy_anfc_merged-uv_PT1H-i",
]

print("=== Checking Copernicus Marine Datasets Metadata ===")

for did in datasets_to_check:
    print(f"\n--- Checking: {did} ---")
    try:
        desc = cm.describe(dataset_id=did)
        # Parse returned structure
        if hasattr(desc, "to_dict"):
            data = desc.to_dict()
        elif isinstance(desc, dict):
            data = desc
        else:
            data = json.loads(str(desc))
        
        # Extract variables and coordinates
        vars_list = []
        if "variables" in data:
            for v in data["variables"]:
                v_name = v.get("standard_name") or v.get("short_name") or v.get("name")
                vars_list.append(v_name)
        
        # Extract time coverage
        time_cov = "Unknown"
        if "temporal_extent" in data:
            time_cov = data["temporal_extent"]
        elif "time_coverage" in data:
            time_cov = data["time_coverage"]
        elif "coordinates" in data and "time" in data["coordinates"]:
            t_coord = data["coordinates"]["time"]
            t_min = t_coord.get("minimum_value")
            t_max = t_coord.get("maximum_value")
            try:
                t_min_dt = pd.to_datetime(t_min, unit="ms") if isinstance(t_min, (int, float)) and t_min > 1e10 else t_min
                t_max_dt = pd.to_datetime(t_max, unit="ms") if isinstance(t_max, (int, float)) and t_max > 1e10 else t_max
                time_cov = f"From: {t_min_dt}  To: {t_max_dt}"
            except Exception:
                time_cov = f"From: {t_min}  To: {t_max}"

        print(f"Dataset ID: {did}")
        print(f"Time Coverage: {time_cov}")
        print(f"Variables: {vars_list}")
    except Exception as e:
        print(f"Error checking {did}: {e}")

print("\n=== Searching for other hourly current datasets containing 'merged' or 'cur' ===")
try:
    search_res = cm.describe(contains=["merged"])
    print("Found 'merged' datasets:")
    if hasattr(search_res, "to_dict"):
        s_data = search_res.to_dict()
    elif isinstance(search_res, dict):
        s_data = search_res
    else:
        s_data = json.loads(str(search_res))
    
    # List datasets
    products = s_data.get("products", [])
    for p in products:
        p_id = p.get("product_id") or p.get("id")
        for d in p.get("datasets", []):
            d_id = d.get("dataset_id") or d.get("id")
            print(f"  Product: {p_id} -> Dataset: {d_id}")
except Exception as e:
    print(f"Error searching contains=['merged']: {e}")
