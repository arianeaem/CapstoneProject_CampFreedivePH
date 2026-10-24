<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A legacy PayMongo webhook event, kept so it is processed once and retried until it succeeds. */
class PaymongoWebhookEvent extends Model
{
    protected $fillable = ['event_id', 'event_type', 'status', 'attempts', 'last_error', 'processed_at'];

    protected function casts(): array
    {
        return ['processed_at' => 'datetime', 'attempts' => 'integer'];
    }
}
