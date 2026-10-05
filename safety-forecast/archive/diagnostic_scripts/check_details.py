import pandas as pd
import numpy as np

# 1. Wave first 5 rows
df_w = pd.read_parquet('safety-forecast/data/interim/cmems_waves.parquet')
print('=' * 80)
print('1. WAVE FIRST 5 ROWS:')
print(df_w.head(5)[['hs', 'tp', 'swell_height', 'wind_wave_height', 'wave_is_interpolated']])

# 2. Wave Tp < 2.0s count
low_tp = df_w[df_w['tp'] < 2.0]
print('\n' + '=' * 80)
print(f'2. WAVE TP < 2.0s: {len(low_tp)} rows ({len(low_tp)/len(df_w)*100:.2f}%)')
if len(low_tp) > 0:
    print(low_tp[['hs', 'tp', 'wave_is_interpolated']].head(5))

# 3. Wave Hs Max (2.69m) Timestamp & Kristine comparison
max_hs_row = df_w.loc[df_w['hs'].idxmax()]
print('\n' + '=' * 80)
max_hs_val = max_hs_row['hs']
max_hs_idx = df_w['hs'].idxmax()
is_interp = max_hs_row['wave_is_interpolated']
ww_val = max_hs_row['wind_wave_height']
sw_val = max_hs_row['swell_height']
print(f'3. WAVE MAX HS: {max_hs_val} m at {max_hs_idx}')
print(f'   Interpolated flag: {is_interp}')
print(f'   Wind wave: {ww_val} m, Swell: {sw_val} m')

# Check Oct 2024 (Kristine peak)
kristine_slice = df_w.loc['2024-10-23':'2024-10-25']
k_max = kristine_slice['hs'].max()
k_idx = kristine_slice['hs'].idxmax()
print(f'   Kristine window (2024-10-23 to 2024-10-25) max Hs: {k_max} m at {k_idx}')

# 4. SMOC Last 24 Hours (Provisional Window check)
df_c = pd.read_parquet('safety-forecast/data/interim/cmems_currents.parquet')
print('\n' + '=' * 80)
print('4. SMOC LAST 10 ROWS (Provisional Window check):')
print(df_c.tail(10)[['current_speed', 'current_u', 'current_v', 'tide_speed']])
