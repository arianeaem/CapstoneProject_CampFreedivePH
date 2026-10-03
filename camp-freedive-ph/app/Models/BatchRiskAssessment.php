<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Batch Risk Assessment Model representing an evaluation of marine weather conditions for a batch day.
 *
 * Domain & Safety Assessment Context:
 * - Stores aggregated multi-variable safety evaluations for Day 1 (Saturday) and Day 2 (Sunday).
 * - Records overall classification ('Very Safe', 'Safe', 'Moderate', 'High Risk', 'Critical Risk').
 * - Links to granular AM/PM hourly evaluations for tactical dive planning.
 *
 * @property int $id
 * @property int $batch_id
 * @property int $day_number 1 (Saturday) or 2 (Sunday)
 * @property Carbon $dive_date
 * @property float $lead_time_hours Forecasting horizon lead time
 * @property string $overall_classification Very Safe, Safe, Moderate, High Risk, Critical Risk
 * @property float $weighted_score_pct 0 to 100 risk score
 * @property string $recommended_action Operational safety advisory for staff
 * @property string $worst_window AM or PM
 * @property Carbon $worst_hour Peak risk hour
 * @property bool $override_triggered Whether manual staff override was logged
 */
class BatchRiskAssessment extends Model
{
    use HasFactory;

    protected $fillable = [
        'batch_id',
        'day_number',
        'dive_date',
        'lead_time_hours',
        'overall_classification',
        'weighted_score_pct',
        'recommended_action',
        'worst_window',
        'worst_hour',
        'override_triggered',
        'override_details',
        'assessed_by',
        'assessed_at',
    ];

    protected $casts = [
        'dive_date' => 'date',
        'lead_time_hours' => 'decimal:2',
        'weighted_score_pct' => 'decimal:2',
        'worst_hour' => 'datetime',
        'override_triggered' => 'boolean',
        'override_details' => 'array',
        'assessed_at' => 'datetime',
    ];

    protected function serializeDate(\DateTimeInterface $date): string
    {
        return $date->format('Y-m-d\TH:i:sP');
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    public function assessor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assessed_by');
    }

    public function hourlyAssessments(): HasMany
    {
        return $this->hasMany(HourlyAssessment::class, 'risk_assessment_id')->orderBy('forecast_time', 'asc');
    }

    public function amHourlyAssessments(): HasMany
    {
        return $this->hasMany(HourlyAssessment::class, 'risk_assessment_id')
            ->where('window_type', 'am')
            ->orderBy('forecast_time', 'asc');
    }

    public function pmHourlyAssessments(): HasMany
    {
        return $this->hasMany(HourlyAssessment::class, 'risk_assessment_id')
            ->where('window_type', 'pm')
            ->orderBy('forecast_time', 'asc');
    }

    public function getClassificationBadgeAttribute(): array
    {
        return match ($this->overall_classification) {
            'Very Safe' => [
                'label' => 'Very Safe',
                'class' => 'bg-emerald-50 text-emerald-700',
                'bg' => 'bg-emerald-500',
            ],
            'Safe' => [
                'label' => 'Safe',
                'class' => 'bg-emerald-50 text-emerald-700',
                'bg' => 'bg-emerald-500',
            ],
            'Moderate' => [
                'label' => 'Moderate',
                'class' => 'bg-amber-50 text-amber-800',
                'bg' => 'bg-amber-500',
            ],
            'High Risk' => [
                'label' => 'High Risk',
                'class' => 'bg-rose-50 text-rose-700',
                'bg' => 'bg-rose-500',
            ],
            'Critical Risk' => [
                'label' => 'Critical Risk',
                'class' => 'bg-red-100 text-red-800',
                'bg' => 'bg-red-600',
            ],
            default => [
                'label' => $this->overall_classification ?: 'Not Assessed',
                'class' => 'bg-gray-100 text-gray-700',
                'bg' => 'bg-gray-400',
            ],
        };
    }

    public function getReliabilityAttribute(): array
    {
        $daysOut = max(0, (float) ($this->lead_time_hours / 24.0));
        return \App\Services\WeatherForecastService::getReliabilityCategory($daysOut);
    }
}
