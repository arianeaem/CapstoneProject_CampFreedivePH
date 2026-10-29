<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * B6: extend checkout_notifications into a general notification outbox.
 *
 * Adds batch_id and payment_exception_id so weather, coach, admin, and guest-request
 * notifications can use the same durable outbox with claim-based delivery, bounded
 * retries, deduplication and operational visibility.
 *
 * The existing checkout rows (keyed by checkout_intent_id or booking_id) are untouched.
 * New rows may reference a batch, a payment exception, or just a booking.
 *
 * The general unique constraint prevents duplicate messages: for rows without a checkout
 * intent the combination of (booking_id, batch_id, payment_exception_id, kind,
 * recipient_hash) must be unique. The original (checkout_intent_id, kind, recipient_hash)
 * and (booking_id, kind, recipient_hash) constraints still apply.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('checkout_notifications', function (Blueprint $table) {
            $table->foreignId('batch_id')->nullable()->after('booking_id')
                ->constrained('batches')->nullOnDelete();
            $table->foreignId('payment_exception_id')->nullable()->after('batch_id')
                ->constrained('payment_exceptions')->nullOnDelete();
            $table->string('event_key', 80)->nullable()->after('kind');
            $table->json('context')->nullable()->after('event_key');
        });
    }

    public function down(): void
    {
        Schema::table('checkout_notifications', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payment_exception_id');
            $table->dropConstrainedForeignId('batch_id');
            $table->dropColumn(['event_key', 'context']);
        });
    }
};
