import pandas as pd
import numpy as np

# 1. WAVE AUDIT
print('=' * 80)
print('AUDIT 1: CMEMS 1/12° WAVE ANALYSIS (cmems_waves.parquet)')
print('=' * 80)
df_wave = pd.read_parquet('safety-forecast/data/interim/cmems_waves.parquet')
print('Total rows:', len(df_wave))
print('First timestamp:', df_wave.index[0])
print('Last timestamp: ', df_wave.index[-1])

# Time continuity
diffs_w = pd.Series(df_wave.index).diff().dropna()
unexpected_w = diffs_w[diffs_w != pd.Timedelta(hours=1)]
print('Unexpected step sizes (not 1h):', len(unexpected_w))
print('NaN counts per column:')
print(df_wave.isna().sum())

# Interpolation flag
interp_ratio = df_wave['wave_is_interpolated'].mean() * 100
print(f'wave_is_interpolated: {interp_ratio:.2f}% (Expected: ~66.7%)')

# Physical ranges
print('\nWave Physical Ranges:')
print(df_wave[['hs', 'tp', 'swell_height', 'wind_wave_height']].describe().round(4))

# Quadrature consistency: Hs vs sqrt(swell^2 + wind_wave^2)
quad = np.sqrt(df_wave['swell_height']**2 + df_wave['wind_wave_height']**2)
diff_quad = (df_wave['hs'] - quad).abs()
corr_val = df_wave['hs'].corr(quad)
print('\nQuadrature agreement (hs vs sqrt(swell^2 + wind_wave^2)):')
print(f'Mean Abs Diff: {diff_quad.mean():.4f} m, Max Diff: {diff_quad.max():.4f} m')
print(f'Correlation:   {corr_val:.4f}')

# Yearly homogeneity
df_wave['year'] = df_wave.index.year
print('\nYearly Mean & Std (Hs):')
print(df_wave.groupby('year')['hs'].agg(['count', 'mean', 'std', 'max']).round(4))

# 2. CURRENT AUDIT
print('\n' + '=' * 80)
print('AUDIT 2: CMEMS SMOC HOURLY CURRENTS (cmems_currents.parquet)')
print('=' * 80)
df_curr = pd.read_parquet('safety-forecast/data/interim/cmems_currents.parquet')
print('Total rows:', len(df_curr))
print('First timestamp:', df_curr.index[0])
print('Last timestamp: ', df_curr.index[-1])

diffs_c = pd.Series(df_curr.index).diff().dropna()
unexpected_c = diffs_c[diffs_c != pd.Timedelta(hours=1)]
print('Unexpected step sizes (not 1h):', len(unexpected_c))
print('NaN counts per column:')
print(df_curr.isna().sum())

curr_interp = df_curr['current_is_interpolated'].mean() * 100
print(f'current_is_interpolated: {curr_interp:.2f}% (Expected: 0.0%)')

print('\nCurrent Physical Ranges:')
cols_to_show = [c for c in ['current_speed', 'current_u', 'current_v', 'tide_speed', 'current_residual_speed'] if c in df_curr.columns]
print(df_curr[cols_to_show].describe().round(4))

# Tidal oscillation check: semi-diurnal periodicity
sample_tide = df_curr.loc['2024-01-01':'2024-01-07', 'tide_u']
zc = ((sample_tide[:-1].values * sample_tide[1:].values) < 0).sum()
print(f'\nTidal oscillation in 7-day sample (2024-01-01 to 2024-01-07):')
print(f'Zero-crossings in tide_u: {zc} crossings across 168h (~{168 / (zc/2):.1f}h period, semi-diurnal expected ~12.4h)')

# Yearly homogeneity
df_curr['year'] = df_curr.index.year
print('\nYearly Mean & Std (Current Speed):')
print(df_curr.groupby('year')['current_speed'].agg(['count', 'mean', 'std', 'max']).round(4))
