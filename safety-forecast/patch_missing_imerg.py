import requests
import json
import calendar
import numpy as np
import pandas as pd
from pathlib import Path

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

LON_INDICES = (3007, 3011)
LAT_INDICES = (1035, 1040)
LON_COORDS = [120.75, 120.85, 120.95, 121.05, 121.15]
LAT_COORDS = [13.55, 13.65, 13.75, 13.85, 13.95, 14.05]

def parse_dap2_ascii(text: str) -> np.ndarray:
    grid = []
    for line in text.splitlines():
        if 'precipitation.precipitation[' in line:
            parts = [p.strip() for p in line.split(',')]
            row = [float(x) for x in parts[1:]]
            grid.append(row)
    arr = np.array(grid, dtype=np.float32)
    arr[arr < -9000.0] = np.nan
    return arr

fetched = {}
for d in dates:
    y, m, day = d.split('-')
    url = f"https://gpm1.gesdisc.eosdis.nasa.gov/opendap/GPM_L3/GPM_3IMERGDF.07/{y}/{m}/3B-DAY.MS.MRG.3IMERG.{y}{m}{day}-S000000-E235959.V07B.nc4.ascii?precipitation[0:1:0][{LON_INDICES[0]}:1:{LON_INDICES[-1]}][{LAT_INDICES[0]}:1:{LAT_INDICES[-1]}]"
    r = session.get(url, timeout=20)
    if r.status_code == 200:
        grid = parse_dap2_ascii(r.text)
        fetched[d] = grid
        print(f"Fetched {d}: shape {grid.shape}, site bilinear {grid.mean():.2f} mm/day")
    else:
        print(f"Failed {d}: {r.status_code}")

# Patch raw monthly files
raw_dir = Path("data/raw/gpm_daily")
for d, grid in fetched.items():
    ym = d[:7].replace('-', '_')
    f = raw_dir / f"gpm_daily_{ym}.parquet"
    if f.exists():
        df_m = pd.read_parquet(f)
        df_m = df_m[df_m['date'] != d]
        new_rows = []
        for i, lon in enumerate(LON_COORDS):
            for j, lat in enumerate(LAT_COORDS):
                new_rows.append({
                    'date': d, 'lon': lon, 'lat': lat,
                    'precipitation_mm_day': float(grid[i, j])
                })
        df_m = pd.concat([df_m, pd.DataFrame(new_rows)], ignore_index=True)
        df_m = df_m.sort_values(['date', 'lon', 'lat'])
        df_m.to_parquet(f, index=False)
        print(f"Patched {d} into {f.name}")

# Rebuild full master 30-cell archive and site table
monthly_files = sorted([p for p in raw_dir.glob("gpm_daily_20*.parquet") if "grid_30cells" not in p.name])
master_df = pd.concat([pd.read_parquet(p) for p in monthly_files], ignore_index=True)
master_df = master_df.drop_duplicates(subset=['date', 'lon', 'lat']).sort_values(['date', 'lon', 'lat'])
master_df.to_parquet(raw_dir / "gpm_daily_grid_30cells.parquet", index=False)

SITE_LAT, SITE_LON = 13.6874, 120.8931
dx = (SITE_LON - 120.85) / 0.1
dy = (SITE_LAT - 13.65) / 0.1
w_sw = float((1.0 - dx) * (1.0 - dy))
w_se = float(dx * (1.0 - dy))
w_nw = float((1.0 - dx) * dy)
w_ne = float(dx * dy)

dates_uniq = sorted(master_df['date'].unique())
records = []
for d in dates_uniq:
    sub = master_df[master_df['date'] == d]
    p_sw = sub[(sub['lon'] == 120.85) & (sub['lat'] == 13.65)]['precipitation_mm_day'].values
    p_se = sub[(sub['lon'] == 120.95) & (sub['lat'] == 13.65)]['precipitation_mm_day'].values
    p_nw = sub[(sub['lon'] == 120.85) & (sub['lat'] == 13.75)]['precipitation_mm_day'].values
    p_ne = sub[(sub['lon'] == 120.95) & (sub['lat'] == 13.75)]['precipitation_mm_day'].values
    if len(p_sw) and len(p_se) and len(p_nw) and len(p_ne):
        p_interp = w_sw * p_sw[0] + w_se * p_se[0] + w_nw * p_nw[0] + w_ne * p_ne[0]
        p_near = p_sw[0]
        p_box = sub['precipitation_mm_day'].mean()
        records.append({
            'date': d,
            'precip_bilinear_mm_day': float(p_interp),
            'precip_nearest_mm_day': float(p_near),
            'precip_box_mean_mm_day': float(p_box),
            'rain_rate_site_mm_hr': float(p_interp / 24.0)
        })

site_df = pd.DataFrame(records).set_index('date').sort_index()
interim_path = Path("data/interim/gpm_daily_precip.parquet")
site_df.to_parquet(interim_path)
print(f"\nALL 13 DATES PATCHED! Wrote {len(site_df)} complete daily rows to {interim_path}")
