import cdsapi
import pandas as pd

c = cdsapi.Client()
print("Probing ERA5 / ERA5T operational latency backwards from today (2026-10-04)...")

# Test daily steps backwards from 2026-10-03 to 2026-09-25
for days_ago in range(1, 15):
    target_dt = pd.to_datetime("2026-10-04") - pd.to_timedelta(days_ago, unit="D")
    y = target_dt.strftime("%Y")
    m = target_dt.strftime("%m")
    d = target_dt.strftime("%d")
    print(f"Testing ERA5 date: {y}-{m}-{d} ({days_ago} days ago)...", end=" ", flush=True)
    try:
        r = c.retrieve(
            "reanalysis-era5-single-levels",
            {
                "product_type": "reanalysis",
                "variable": ["10m_u_component_of_wind"],
                "year": [y],
                "month": [m],
                "day": [d],
                "time": ["00:00"],
                "area": [13.75, 120.75, 13.50, 121.00],
                "format": "netcdf",
            }
        )
        print(f"-> SUCCESS! ERA5 latest available date is {y}-{m}-{d} (Latency = {days_ago} days).")
        break
    except Exception as e:
        print(f"-> Not available ({type(e).__name__})")
