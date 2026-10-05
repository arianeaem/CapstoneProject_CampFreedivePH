<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ForecastHourly extends Model
{
    use HasFactory;

    protected $table = 'forecast_hourly';

    protected $guarded = ['id'];

    protected $casts = [
        'forecast_time' => 'datetime',
        'issued_at' => 'datetime',
        'lead_hours' => 'integer',
        'hs_p10' => 'float',
        'hs_p50' => 'float',
        'hs_p90' => 'float',
        'current_speed_p10' => 'float',
        'current_speed_p50' => 'float',
        'current_speed_p90' => 'float',
        'wind_speed_p50' => 'float',
        'wind_gust_p50' => 'float',
        'slp_p50' => 'float',
        'tp_p50' => 'float',
        'swell_height_p50' => 'float',
        'wind_wave_height_p50' => 'float',
        'wind_dir_circ_mean_deg' => 'float',
        'adverse_tail_triggered' => 'boolean',
    ];

    public function daily(): BelongsTo
    {
        return $this->belongsTo(ForecastDaily::class, 'forecast_daily_id');
    }
}
