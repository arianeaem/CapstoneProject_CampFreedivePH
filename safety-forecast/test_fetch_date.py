import requests

creds = {}
with open('../camp-freedive-ph/.env', encoding='utf-8') as f:
    for line in f:
        line = line.strip()
        if '=' in line and not line.startswith('#'):
            k, v = line.split('=', 1)
            creds[k.strip()] = v.strip().strip('"').strip("'")

user = creds.get('EARTHDATA_USERNAME')
pwd = creds.get('EARTHDATA_PASSWORD')

dates = [
    '2023-01-01', '2023-03-09', '2023-04-10', '2023-04-11',
    '2023-06-04', '2024-01-04', '2024-03-26', '2024-04-04',
    '2024-04-09', '2024-06-05', '2025-01-19', '2025-01-20',
    '2025-06-14'
]

session = requests.Session()
session.auth = (user, pwd)

print("Checking the 13 missing dates against NASA GES DISC OPeNDAP:")
for d in dates:
    y, m, day = d.split('-')
    url_b = f"https://gpm1.gesdisc.eosdis.nasa.gov/opendap/GPM_L3/GPM_3IMERGDF.07/{y}/{m}/3B-DAY.MS.MRG.3IMERG.{y}{m}{day}-S000000-E235959.V07B.nc4.das"
    r_b = session.get(url_b, timeout=15)
    if r_b.status_code == 200:
        print(f"  {d}: V07B EXISTS (200 OK) -> was transient timeout during multi-threaded run")
    elif r_b.status_code == 404:
        # Check if non-B exists
        url_non_b = f"https://gpm1.gesdisc.eosdis.nasa.gov/opendap/GPM_L3/GPM_3IMERGDF.07/{y}/{m}/3B-DAY.MS.MRG.3IMERG.{y}{m}{day}-S000000-E235959.V07.nc4.das"
        r_non_b = session.get(url_non_b, timeout=15)
        if r_non_b.status_code == 200:
            print(f"  {d}: V07B 404, but V07 EXISTS (200 OK)")
        else:
            print(f"  {d}: TRUE MISSING IN NASA ARCHIVE (404 on both V07B and V07)")
    else:
        print(f"  {d}: HTTP {r_b.status_code}")
