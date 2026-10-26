<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Batch 3-II A: booking capacity and lifecycle integrity. Additive only; no existing row is rewritten.
 *
 *  - payments.review_*: a payment that arrived but could not simply be applied (date full, booking cancelled, amount
 *    different, paid again) is kept as received and flagged for staff; the flag is cleared only by a staff resolution.
 *  - checkout_notifications.booking_id: the durable outbox also carries confirmations of legacy bookings (confirmed by
 *    the PayMongo webhook without a checkout intent). checkout_intent_id becomes optional; one of the two is set.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('review_reason', 48)->nullable(); // capacity_unavailable, paid_after_cancellation, amount_mismatch, paid_again
            $table->timestamp('review_flagged_at')->nullable();
            $table->timestamp('review_resolved_at')->nullable();
            $table->foreignId('review_resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->index(['review_reason', 'review_resolved_at']);
        });

        Schema::table('checkout_notifications', function (Blueprint $table) {
            $table->unsignedBigInteger('checkout_intent_id')->nullable()->change();
            $table->foreignId('booking_id')->nullable()->constrained('bookings')->cascadeOnDelete();
            $table->unique(['booking_id', 'kind', 'recipient_hash']);
        });
    }

    public function down(): void
    {
        DB::table('checkout_notifications')->whereNull('checkout_intent_id')->delete(); // booking-only rows cannot survive the old schema
        Schema::table('checkout_notifications', function (Blueprint $table) {
            $table->dropUnique(['booking_id', 'kind', 'recipient_hash']);
            $table->dropConstrainedForeignId('booking_id');
            $table->unsignedBigInteger('checkout_intent_id')->nullable(false)->change();
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex(['review_reason', 'review_resolved_at']);
            $table->dropConstrainedForeignId('review_resolved_by');
            $table->dropColumn(['review_reason', 'review_flagged_at', 'review_resolved_at']);
        });
    }
};
