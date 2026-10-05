<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ManualOverride extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'batch_id',
        'tcws_signal',
        'gale_warning',
        'thunderstorm_advisory',
        'typhoon_within_distance',
        'tsunami_warning',
        'other_hazard',
        'reason',
        'cancelled_batch',
        'applied_by',
        'created_at',
    ];

    protected $casts = [
        'tcws_signal' => 'integer',
        'gale_warning' => 'boolean',
        'thunderstorm_advisory' => 'boolean',
        'typhoon_within_distance' => 'boolean',
        'tsunami_warning' => 'boolean',
        'cancelled_batch' => 'boolean',
        'created_at' => 'datetime',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applied_by');
    }

    public function getActiveAdvisoriesAttribute(): array
    {
        $advisories = [];
        if ($this->tcws_signal >= 3) {
            $advisories[] = "TCWS Signal No. {$this->tcws_signal}";
        }
        if ($this->gale_warning) {
            $advisories[] = "PAGASA Marine Gale Warning";
        }
        if ($this->thunderstorm_advisory) {
            $advisories[] = "Severe Thunderstorm / Lightning Advisory";
        }
        if ($this->typhoon_within_distance) {
            $advisories[] = "Typhoon within Safety Distance (Batangas)";
        }
        if ($this->tsunami_warning) {
            $advisories[] = "Tsunami / Marine Hazard Warning";
        }
        if (!empty($this->other_hazard)) {
            $advisories[] = $this->other_hazard;
        }

        return $advisories;
    }
}
