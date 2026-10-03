<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CoachOpening extends Model
{
    use HasFactory;

    protected $table = 'coach_openings';

    protected $fillable = [
        'batch_id',
        'dive_date',
        'needed_students_count',
        'status',
        'posted_by',
        'notes',
    ];

    protected $casts = [
        'dive_date' => 'date',
        'needed_students_count' => 'integer',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    public function postedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function requests(): HasMany
    {
        return $this->hasMany(CoachRequest::class, 'opening_id');
    }
}
