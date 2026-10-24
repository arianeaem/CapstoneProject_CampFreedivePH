<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Batch 3-I refund ledger. Additive only: no existing column is changed, no existing row is rewritten.
 *
 *  - booking_refunds:  one refund case per cancelled booking (why, who confirmed it, the guest's choice, what the
 *                      policy allows, what staff approved). Approval never moves money by itself.
 *  - refund_attempts:  each refund of one payment (PayMongo, offline or seen only in a PayMongo webhook), with its
 *                      own internal operation id and provider outcome. Amounts in centavos.
 *  - paymongo_webhook_events: durable record of legacy webhook events (replaces the 24-hour cache dedup).
 *  - bookings.refund_policy_version: NULL = made under the earlier published terms (review, never repriced).
 *
 * payments.paymongo_payment_id gets a plain index only: historical rows are not known to be unique (see the
 * preflight query in the Batch 3-I report); duplicates are prevented in the application.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained('bookings');
            $table->foreignId('cancellation_request_id')->nullable()->constrained('cancellation_requests')->nullOnDelete();
            $table->string('source', 32);                       // customer_cancellation, camp_cancellation, legacy_refund_request
            $table->string('reason_category', 32)->default('unknown'); // guest_voluntary, weather_pre_dive, weather_in_progress, camp_operational, unknown
            $table->foreignId('reason_confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reason_confirmed_at')->nullable();
            $table->string('activity_started', 8)->default('unknown'); // no, yes, unknown (staff-confirmed, never inferred from dates)
            $table->string('policy_version', 32)->nullable();   // NULL: booking made under the earlier published terms
            $table->string('guest_choice', 16)->nullable();     // refund, reschedule
            $table->foreignId('guest_choice_recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('guest_choice_at')->nullable();
            $table->string('status', 32)->index();              // awaiting_guest_choice, awaiting_staff_review, approved, closed_no_refund, completed
            $table->string('historical_decision', 32)->nullable(); // honor_previous_terms, apply_current_terms
            $table->foreignId('historical_decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('historical_decided_at')->nullable();
            $table->text('historical_rationale')->nullable();
            $table->bigInteger('quoted_centavos')->nullable();  // amount shown/promised when the request was made (preserved)
            $table->bigInteger('requested_centavos')->nullable(); // amount staff asked for when opening the case (not approved)
            $table->string('balance_review', 16)->default('none'); // none, unresolved (paid beyond the confirmed entitlement)
            $table->bigInteger('approved_centavos')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamps();

            $table->index('booking_id');
        });

        Schema::create('refund_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_refund_id')->nullable()->constrained('booking_refunds');
            $table->foreignId('payment_id')->constrained('payments');
            $table->uuid('operation_id')->unique();             // internal identity of this refund submission
            $table->string('method', 16);                       // paymongo, offline, external (seen only in a webhook)
            $table->bigInteger('amount_centavos');
            $table->char('currency', 3)->default('PHP');
            $table->string('status', 24)->index();              // prepared, submitting, provider_pending, unknown, succeeded, failed,
                                                                // offline_awaiting_payout, offline_confirmed
            $table->string('provider_refund_id')->nullable()->unique();
            $table->string('provider_status', 24)->nullable();
            $table->string('error_code', 64)->nullable();
            // Who settled the outcome: provider_response, provider_webhook, provider_verification (GET /refunds/{id}),
            // offline_staff (cash/bank payout documented by staff). Staff never settle a PayMongo outcome themselves.
            $table->string('resolution_source', 32)->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('evidence_note')->nullable();
            $table->timestamps();

            $table->index(['payment_id', 'status']);
        });

        Schema::create('paymongo_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_id', 191)->unique();
            $table->string('event_type', 64);
            $table->string('status', 16);                       // processing, processed, failed
            $table->unsignedInteger('attempts')->default(0);
            $table->string('last_error', 120)->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->string('refund_policy_version', 32)->nullable();
        });

        Schema::table('cancellation_requests', function (Blueprint $table) {
            $table->string('policy_version', 32)->nullable(); // NULL: requested under the earlier published terms
        });

        Schema::table('refund_requests', function (Blueprint $table) {
            $table->foreignId('booking_refund_id')->nullable()->constrained('booking_refunds')->nullOnDelete();
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->index('paymongo_payment_id');
            $table->index('paymongo_resource_id');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex(['paymongo_payment_id']);
            $table->dropIndex(['paymongo_resource_id']);
        });
        Schema::table('refund_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('booking_refund_id');
        });
        Schema::table('cancellation_requests', function (Blueprint $table) {
            $table->dropColumn('policy_version');
        });
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('refund_policy_version');
        });
        Schema::dropIfExists('paymongo_webhook_events');
        Schema::dropIfExists('refund_attempts');
        Schema::dropIfExists('booking_refunds');
    }
};
