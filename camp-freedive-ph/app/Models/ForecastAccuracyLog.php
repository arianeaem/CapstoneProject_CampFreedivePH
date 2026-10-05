<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ForecastAccuracyLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'target_date',
        'lead_time_days',
        'lead_time_label',
        'predicted_classification',
        'actual_classification',
        'classification_matched',
        'predicted_wave_height',
        'actual_wave_height',
        'wave_height_error',
        'predicted_wind_speed',
        'actual_wind_speed',
        'wind_speed_error',
        'predicted_ocean_current',
        'actual_ocean_current',
        'current_error',
        'predicted_rain',
        'actual_rain',
        'rain_error',
        'accuracy_score_pct',
        'ml_predicted_classification',
        'ml_classification_matched',
        'verified_at',
        'notes',
    ];

    protected $casts = [
        'target_date' => 'date',
        'lead_time_days' => 'integer',
        'classification_matched' => 'boolean',
        'predicted_wave_height' => 'decimal:2',
        'actual_wave_height' => 'decimal:2',
        'wave_height_error' => 'decimal:2',
        'predicted_wind_speed' => 'decimal:2',
        'actual_wind_speed' => 'decimal:2',
        'wind_speed_error' => 'decimal:2',
        'predicted_ocean_current' => 'decimal:2',
        'actual_ocean_current' => 'decimal:2',
        'current_error' => 'decimal:2',
        'predicted_rain' => 'decimal:2',
        'actual_rain' => 'decimal:2',
        'rain_error' => 'decimal:2',
        'accuracy_score_pct' => 'decimal:2',
        'ml_classification_matched' => 'boolean',
        'verified_at' => 'datetime',
    ];

    /**
     * Overall accuracy score from the errors.
     *
     * Weights:
     * - same safety level: 40%
     * - waves: 20% (error <= 0.15 m = 100%, >= 0.80 m = 0%)
     * - wind: 20% (error <= 3 km/h = 100%, >= 20 km/h = 0%)
     * - current: 10% (error <= 0.10 m/s = 100%, >= 0.50 m/s = 0%)
     * - rain: 10% (error <= 0.5 mm = 100%, >= 10 mm = 0%)
     */
    public static function calculateAccuracyScore(
        bool $classificationMatched,
        float $waveError,
        float $windError,
        float $currentError,
        float $rainError
    ): float {
        $classScore = $classificationMatched ? 100.0 : 0.0;
        
        // Wave score (0 to 100)
        $waveScore = max(0.0, min(100.0, 100.0 - ($waveError / 0.80) * 100.0));
        
        // Wind score (0 to 100)
        $windScore = max(0.0, min(100.0, 100.0 - ($windError / 20.0) * 100.0));
        
        // Current score (0 to 100)
        $currentScore = max(0.0, min(100.0, 100.0 - ($currentError / 0.50) * 100.0));
        
        // Rain score (0 to 100)
        $rainScore = max(0.0, min(100.0, 100.0 - ($rainError / 10.0) * 100.0));

        $composite = ($classScore * 0.40) + ($waveScore * 0.20) + ($windScore * 0.20) + ($currentScore * 0.10) + ($rainScore * 0.10);
        return round($composite, 2);
    }
}
