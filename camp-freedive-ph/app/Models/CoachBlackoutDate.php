<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CoachBlackoutDate extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'coach_id',
        'blackout_date',
        'reason',
        'created_at',
    ];

    protected $casts = [
        'blackout_date' => 'date',
        'created_at' => 'datetime',
    ];

    public function coach(): BelongsTo
    {
        return $this->belongsTo(Coach::class);
    }
}
