import cdsapi
import sys

print("Checking latest available ERA5 data via cdsapi...", flush=True)
c = cdsapi.Client()

# Test 2025/2026 or 2024
test_dates = [
    ("2026", "09", "01"),
    ("2025", "12", "01"),
    ("2025", "06", "01"),
    ("2025", "01", "01"),
    ("2024", "12", "31"),
]

for year, month, day in test_dates:
    print(f"Requesting ERA5 test for {year}-{month}-{day}...", end=" ", flush=True)
    try:
        r = c.retrieve(
            "reanalysis-era5-single-levels",
            {
                "product_type": "reanalysis",
                "variable": ["10m_u_component_of_wind"],
                "year": [year],
                "month": [month],
                "day": [day],
                "time": ["00:00"],
                "area": [14.0, 120.5, 13.5, 121.5],
                "format": "netcdf",
            }
        )
        print(f"-> AVAILABLE! Location: {r.location}", flush=True)
        break
    except Exception as e:
        print(f"-> NOT AVAILABLE / ERROR: {type(e).__name__}: {str(e)[:100]}", flush=True)
