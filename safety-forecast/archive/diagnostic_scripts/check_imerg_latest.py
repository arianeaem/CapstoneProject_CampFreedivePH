import requests
import pandas as pd
from datetime import timedelta

session = requests.Session()
# Check NASA GES DISC directory or specific dates
test_dates = [
    pd.Timestamp('2026-06-01 00:00:00'),
    pd.Timestamp('2025-12-01 00:00:00'),
    pd.Timestamp('2025-06-01 00:00:00'),
    pd.Timestamp('2025-01-01 00:00:00'),
    pd.Timestamp('2024-12-31 23:30:00'),
    pd.Timestamp('2024-10-01 00:00:00'),
    pd.Timestamp('2024-06-01 00:00:00'),
]

for dt in test_dates:
    year = dt.strftime('%Y')
    doy = dt.strftime('%j')
    d_str = dt.strftime('%Y%m%d')
    s_str = dt.strftime('%H%M%S')
    dt_end = dt + timedelta(minutes=29, seconds=59)
    e_str = dt_end.strftime('%H%M%S')
    minutes = dt.hour * 60 + dt.minute
    fn = f'3B-HHR.MS.MRG.3IMERG.{d_str}-S{s_str}-E{e_str}.{minutes:04d}.V07B.HDF5'
    url = f'https://gpm1.gesdisc.eosdis.nasa.gov/opendap/GPM_L3/GPM_3IMERGHH.07/{year}/{doy}/{fn}.html'
    try:
        r = session.head(url, timeout=10)
        print(f"{dt.strftime('%Y-%m-%d')} -> status {r.status_code}")
    except Exception as e:
        print(f"{dt.strftime('%Y-%m-%d')} -> error {e}")
