<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A pricing rule (discount or surcharge).
 * Can be based on demand, season (Amihan / Habagat) or how early people book.
 * All rules together are kept between -30% and +30%.
 *
 * @property int $id
 * @property string $name
 * @property string $rule_type demand, seasonality, lead_time
 * @property string $condition_operator ==, !=, >, <, >=, <=
 * @property string $condition_value
 * @property string $applies_to all, discovery, fundive, refinement
 * @property string $adjustment_type increase, decrease
 * @property string $adjustment_method percentage, fixed
 * @property float $adjustment_value
 * @property int $priority order the rules run in
 * @property string $status active, inactive
 */
class PricingRule extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'description',
        'rule_type',
        'condition_operator',
        'condition_value',
        'max_fill_percent',
        'applies_to',
        'adjustment_type',
        'adjustment_method',
        'adjustment_value',
        'priority',
        'status',
        'created_by',
    ];

    protected $casts = [
        'adjustment_value' => 'decimal:2',
        'priority' => 'integer',
        'max_fill_percent' => 'integer',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(BookingPriceAdjustment::class, 'pricing_rule_id');
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Condition text, e.g. "Demand = High", "Season = Peak", "Lead time <= 3 days"
     */
    public function getConditionSummaryAttribute(): string
    {
        $fill = $this->max_fill_percent !== null ? " · batch < {$this->max_fill_percent}% full" : '';

        return match ($this->rule_type) {
            'demand' => 'Demand = ' . ucfirst($this->condition_value),
            'seasonality' => 'Season = ' . ucfirst(str_replace('_', '-', $this->condition_value)),
            'lead_time' => 'Lead time ' . match ($this->condition_operator) {
                '<=' => '≤ ',
                '>=' => '≥ ',
                '<' => '< ',
                '>' => '> ',
                default => '= ',
            } . $this->condition_value . ' days',
            default => $this->condition_value,
        } . $fill;
    }

    /**
     * Adjustment text, e.g. "+15%" or "-P500"
     */
    public function getFormattedAdjustmentAttribute(): string
    {
        $sign = $this->adjustment_type === 'increase' ? '+' : '−';
        if ($this->adjustment_method === 'percentage') {
            return $sign . rtrim(rtrim(number_format((float)$this->adjustment_value, 2), '0'), '.') . '%';
        }
        return $sign . '₱' . number_format((float)$this->adjustment_value, 0);
    }

    /**
     * "Applies To" text
     */
    public function getFormattedAppliesToAttribute(): string
    {
        return match ($this->applies_to) {
            'all' => 'All Classes',
            'discovery' => 'Discovery',
            'fundive' => 'Fundive',
            'refinement' => 'Refinement',
            default => ucfirst($this->applies_to),
        };
    }

    /**
     * Badge settings for the rule type
     */
    public function getTypeBadgeAttribute(): array
    {
        return match ($this->rule_type) {
            'demand' => [
                'label' => 'Demand Surge',
                'class' => 'bg-blue-50 text-blue-800',
            ],
            'seasonality' => [
                'label' => 'Seasonality',
                'class' => 'bg-amber-50 text-amber-800',
            ],
            'lead_time' => [
                'label' => 'Lead Time',
                'class' => 'bg-purple-50 text-purple-800',
            ],
            default => [
                'label' => ucfirst(str_replace('_', ' ', (string) $this->rule_type)),
                'class' => 'bg-gray-50 text-gray-800',
            ],
        };
    }
}
