<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * ML forecast for ONE real scheduled batch. Never an actual/historical record.
 */
class BatchDemandForecast extends Model
{
    protected $table = 'batch_demand_forecasts';

    protected $fillable = [
        'batch_id', 'batch_code', 'batch_date', 'days_to_start', 'booked_so_far', 'capacity',
        'predicted_participants', 'predicted_bookings', 'predicted_revenue_php',
        'lower_bound', 'upper_bound', 'predicted_fill_rate',
        'demand_level', 'season_period', 'adjusted_for_booked',
        'data_basis', 'model_version', 'generated_at', 'synced_at',
    ];

    protected $casts = [
        'batch_date' => 'date',
        'days_to_start' => 'integer',
        'booked_so_far' => 'integer',
        'capacity' => 'integer',
        'predicted_participants' => 'float',
        'predicted_bookings' => 'float',
        'predicted_revenue_php' => 'float',
        'lower_bound' => 'float',
        'upper_bound' => 'float',
        'predicted_fill_rate' => 'float',
        'adjusted_for_booked' => 'boolean',
        'generated_at' => 'datetime',
        'synced_at' => 'datetime',
    ];
}
