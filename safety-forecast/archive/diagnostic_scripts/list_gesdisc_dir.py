import requests
import re
import pandas as pd

url = "https://gpm1.gesdisc.eosdis.nasa.gov/opendap/GPM_L3/GPM_3IMERGHH.07/contents.html"
print(f"Querying GES DISC root directory: {url} ...")
try:
    r = requests.get(url, timeout=15)
    print("Status:", r.status_code)
    years = re.findall(r'href="(\d{4})/', r.text)
    years = sorted(set(years))
    print("Available years in GES DISC directory:", years)
except Exception as e:
    print("Error accessing GES DISC root:", e)

# Check 2025 directory
url_2025 = "https://gpm1.gesdisc.eosdis.nasa.gov/opendap/GPM_L3/GPM_3IMERGHH.07/2025/contents.html"
print(f"\nQuerying GES DISC 2025 directory: {url_2025} ...")
try:
    r2 = requests.get(url_2025, timeout=15)
    print("2025 Status:", r2.status_code)
    days = re.findall(r'href="(\d{3})/', r2.text)
    days = sorted(set(int(d) for d in days))
    print(f"2025 total days listed: {len(days)}")
    if days:
        print(f"First day: {days[0]}, Last day: {days[-1]}")
        last_dt = pd.to_datetime("2025-01-01") + pd.to_timedelta(days[-1] - 1, unit="D")
        print(f"Last day {days[-1]} corresponds to calendar date: {last_dt.strftime('%Y-%m-%d')}")
except Exception as e:
    print("Error accessing 2025 directory:", e)

# Also check if 2026 directory exists
url_2026 = "https://gpm1.gesdisc.eosdis.nasa.gov/opendap/GPM_L3/GPM_3IMERGHH.07/2026/contents.html"
print(f"\nQuerying GES DISC 2026 directory: {url_2026} ...")
try:
    r3 = requests.get(url_2026, timeout=10)
    print("2026 Status:", r3.status_code)
except Exception as e:
    print("2026 check error:", e)
