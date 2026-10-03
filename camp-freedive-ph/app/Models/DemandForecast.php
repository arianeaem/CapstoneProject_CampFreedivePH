<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DemandForecast extends Model
{
    use HasFactory;

    protected $table = 'demand_forecasts';

    protected $fillable = [
        'forecast_date',
        'days_ahead',
        'predicted_participants',
        'predicted_bookings',
        'predicted_revenue_php',
        'demand_level',
        'season_period',
        'instructors_needed',
        'horizon_summary',
        'metadata',
        'synced_at',
    ];

    protected $casts = [
        'forecast_date' => 'date',
        'days_ahead' => 'integer',
        'predicted_participants' => 'float',
        'predicted_bookings' => 'float',
        'predicted_revenue_php' => 'float',
        'instructors_needed' => 'integer',
        'horizon_summary' => 'array',
        'metadata' => 'array',
        'synced_at' => 'datetime',
    ];

    /**
     * Scope for future forecast records.
     */
    public function scopeUpcoming($query)
    {
        return $query->where('forecast_date', '>=', Carbon::today())
            ->orderBy('forecast_date', 'asc');
    }

    /**
     * Scope to get the latest synced batch run.
     */
    public function scopeLatestSync($query)
    {
        $latestSyncedAt = static::max('synced_at');
        if (!$latestSyncedAt) {
            return $query;
        }

        return $query->where('synced_at', $latestSyncedAt)->orderBy('forecast_date', 'asc');
    }
}
