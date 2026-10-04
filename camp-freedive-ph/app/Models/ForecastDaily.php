<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ForecastDaily extends Model
{
    use HasFactory;

    protected $table = 'forecast_daily';

    protected $guarded = ['id'];

    protected $casts = [
        'forecast_date' => 'date',
        'issued_at' => 'datetime',
        'rain_daily_mm_p50' => 'float',
        'rain_daily_mm_p90' => 'float',
        'rain_score' => 'integer',
        'p_wet' => 'float',
        'p_wet_ci_lo' => 'float',
        'p_wet_ci_hi' => 'float',
        'p_high_gust' => 'float',
        'p_high_gust_ci_lo' => 'float',
        'p_high_gust_ci_hi' => 'float',
        'prob_band_0_offshore_nne' => 'float',
        'prob_band_1_ese' => 'float',
        'prob_band_2_s_wnw' => 'float',
        'prob_band_3_onshore_habagat' => 'float',
    ];

    public function hourly(): HasMany
    {
        return $this->hasMany(ForecastHourly::class, 'forecast_daily_id');
    }
}
