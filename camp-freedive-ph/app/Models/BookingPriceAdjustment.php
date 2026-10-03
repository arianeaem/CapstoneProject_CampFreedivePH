<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookingPriceAdjustment extends Model
{
    use HasFactory;

    protected $fillable = [
        'booking_id',
        'pricing_rule_id',
        'rule_name',
        'rule_type',
        'condition_summary',
        'base_price',
        'adjustment_amount',
        'adjusted_price',
    ];

    protected $casts = [
        'base_price' => 'decimal:2',
        'adjustment_amount' => 'decimal:2',
        'adjusted_price' => 'decimal:2',
    ];

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function pricingRule(): BelongsTo
    {
        return $this->belongsTo(PricingRule::class, 'pricing_rule_id')->withTrashed();
    }
}
