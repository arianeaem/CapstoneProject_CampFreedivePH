<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HourlyAssessment extends Model
{
    use HasFactory;

    protected $fillable = [
        'risk_assessment_id',
        'window_type',
        'open_water_window',
        'forecast_time',
        'wave_height',
        'wave_period',
        'swell_height',
        'wind_wave_height',
        'ocean_current',
        'rain',
        'sea_level_pressure',
        'wind_speed',
        'wind_direction',
        'tide_height',
        'tide_score',
        'weighted_score_pct',
        'classification',
        'recommended_action',
        'is_worst_hour_in_window',
    ];

    protected $casts = [
        'forecast_time' => 'datetime',
        'wave_height' => 'decimal:2',
        'wave_period' => 'decimal:2',
        'swell_height' => 'decimal:2',
        'wind_wave_height' => 'decimal:2',
        'ocean_current' => 'decimal:2',
        'rain' => 'decimal:2',
        'sea_level_pressure' => 'decimal:2',
        'wind_speed' => 'decimal:2',
        'wind_direction' => 'decimal:2',
        'tide_height' => 'decimal:2',
        'tide_score' => 'integer',
        'weighted_score_pct' => 'decimal:2',
        'is_worst_hour_in_window' => 'boolean',
    ];

    protected function serializeDate(\DateTimeInterface $date): string
    {
        return $date->format('Y-m-d\TH:i:sP');
    }

    public function riskAssessment(): BelongsTo
    {
        return $this->belongsTo(BatchRiskAssessment::class, 'risk_assessment_id');
    }

    public function getClassificationBadgeAttribute(): array
    {
        return match ($this->classification) {
            'Very Safe' => [
                'label' => 'Very Safe',
                'class' => 'bg-emerald-50 text-emerald-700',
            ],
            'Safe' => [
                'label' => 'Safe',
                'class' => 'bg-emerald-50 text-emerald-700',
            ],
            'Moderate' => [
                'label' => 'Moderate',
                'class' => 'bg-amber-50 text-amber-800',
            ],
            'High Risk' => [
                'label' => 'High Risk',
                'class' => 'bg-rose-50 text-rose-700',
            ],
            'Critical Risk' => [
                'label' => 'Critical Risk',
                'class' => 'bg-red-100 text-red-800',
            ],
            default => [
                'label' => $this->classification ?: 'Normal',
                'class' => 'bg-gray-100 text-gray-700',
            ],
        };
    }
}
