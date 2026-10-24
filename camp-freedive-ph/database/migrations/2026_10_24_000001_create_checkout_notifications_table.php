<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Durable outbox for checkout messages (booking confirmation, payment received, staff alerts): one row per message
 * and recipient, recorded after the checkout outcome is committed and delivered by a queued job. Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checkout_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('checkout_intent_id')->constrained('checkout_intents')->cascadeOnDelete();
            $table->string('kind', 40);                 // customer_booking_confirmed, customer_payment_received, staff_new_booking, staff_paid_unallocated
            $table->text('recipient');                  // encrypted email address
            $table->char('recipient_hash', 64);         // sha256 of the lower-cased address, for once-per-recipient
            $table->string('status', 16)->default('pending')->index(); // pending, sending, sent, failed (retries exhausted)
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('available_at')->nullable(); // not dispatched again before this (backoff or dispatch lease)
            $table->uuid('claim_token')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->string('last_error', 120)->nullable(); // exception class only, never message content
            $table->timestamps();

            $table->unique(['checkout_intent_id', 'kind', 'recipient_hash']);
            $table->index(['status', 'available_at']);
        });

        Schema::table('checkout_intents', function (Blueprint $table) {
            // Set once the outcome's messages were written to the outbox (or there was nobody to write to)
            $table->timestamp('notifications_recorded_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('checkout_intents', function (Blueprint $table) {
            $table->dropColumn('notifications_recorded_at');
        });
        Schema::dropIfExists('checkout_notifications');
    }
};
