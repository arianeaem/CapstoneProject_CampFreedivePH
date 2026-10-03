<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ForecastSnapshot extends Model
{
    use HasFactory;

    protected $fillable = [
        'target_date',
        'lead_time_days',
        'lead_time_label',
        'predicted_classification',
        'predicted_score_pct',
        'predicted_wave_height',
        'predicted_wind_speed',
        'predicted_ocean_current',
        'predicted_rain',
        'predicted_pressure',
        'ml_predicted_classification',
        'hourly_data',
        'captured_at',
    ];

    protected $casts = [
        'target_date' => 'date',
        'lead_time_days' => 'integer',
        'predicted_score_pct' => 'decimal:2',
        'predicted_wave_height' => 'decimal:2',
        'predicted_wind_speed' => 'decimal:2',
        'predicted_ocean_current' => 'decimal:2',
        'predicted_rain' => 'decimal:2',
        'predicted_pressure' => 'decimal:2',
        'hourly_data' => 'array',
        'captured_at' => 'datetime',
    ];

    public static function formatLeadTimeLabel(int $days): string
    {
        return match ($days) {
            0 => 'Real-Time (0 Days)',
            1 => '24h (1 Day)',
            2 => '48h (2 Days)',
            3 => '72h (3 Days)',
            7 => '7 Days (1 Week)',
            14 => '14 Days (2 Weeks)',
            default => "{$days} Days",
        };
    }
}
