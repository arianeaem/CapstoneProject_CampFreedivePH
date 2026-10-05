import requests
import re
import pandas as pd

for coll in ["GPM_3IMERGHHL.07", "GPM_3IMERGHHE.07"]:
    url = f"https://gpm1.gesdisc.eosdis.nasa.gov/opendap/GPM_L3/{coll}/contents.html"
    print(f"\nChecking collection: {coll} at {url} ...")
    try:
        r = requests.get(url, timeout=10)
        print(f"Status: {r.status_code}")
        if r.status_code == 200:
            years = sorted(set(re.findall(r'href="(\d{4})/', r.text)))
            print(f"Years available: {years[-4:]}")
            last_year = years[-1]
            url_y = f"https://gpm1.gesdisc.eosdis.nasa.gov/opendap/GPM_L3/{coll}/{last_year}/contents.html"
            ry = requests.get(url_y, timeout=10)
            days = sorted(set(int(d) for d in re.findall(r'href="(\d{3})/', ry.text)))
            if days:
                last_day = days[-1]
                cal_date = pd.to_datetime(f"{last_year}-01-01") + pd.to_timedelta(last_day - 1, unit="D")
                print(f"Latest year {last_year}: {len(days)} days. Last day {last_day} -> Date: {cal_date.strftime('%Y-%m-%d')}")
    except Exception as e:
        print(f"Error checking {coll}: {e}")
