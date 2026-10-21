<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A checkout attempt before payment. Not an official booking: the booking (with its number and PIN)
 * is only created from an intent after the payment is verified. Contact, participant and health
 * details live in the encrypted `payload`; the plain columns are what capacity, PayMongo matching
 * and staff review need.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checkout_intents', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 20)->unique(); // CHK-XXXXXXXXXXXX, shown to the customer before payment
            $table->string('status', 32)->default('pending')->index(); // pending, failed, expired, paid, finalized, paid_unallocated
            $table->string('source', 16)->default('checkout'); // checkout, legacy_booking

            // What is being bought (plain, for capacity and staff review)
            $table->date('start_date');
            $table->date('end_date');
            $table->unsignedSmallInteger('pax_count');
            $table->string('class_type', 32);
            $table->string('pickup_option', 32);
            $table->char('currency', 3)->default('PHP');
            $table->decimal('total_amount', 10, 2);
            $table->decimal('downpayment_amount', 10, 2);

            // Contact, participants (incl. health), guardian consent and the price quote: encrypted:array
            $table->text('payload');
            $table->char('contact_email_hash', 64)->nullable()->index(); // sha256 of the lower-cased email, for lookup without decrypting

            // 15-minute capacity hold; only a pending intent with a future hold counts
            $table->timestamp('hold_expires_at')->nullable();
            $table->timestamp('checkout_expires_at')->nullable();

            // PayMongo
            $table->string('paymongo_checkout_session_id')->nullable()->unique();
            $table->string('paymongo_payment_id')->nullable()->unique();
            $table->decimal('paid_amount', 10, 2)->nullable();
            $table->char('paid_currency', 3)->nullable();
            $table->decimal('fee_amount', 10, 2)->nullable();
            $table->string('payment_method', 32)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->json('verification')->nullable(); // summary of how the payment was verified (no card data)
            $table->string('last_event_id')->nullable();

            // Official booking created from this intent (one at most)
            $table->foreignId('booking_id')->nullable()->unique()->constrained('bookings')->nullOnDelete();
            $table->foreignId('batch_id')->nullable()->constrained('batches')->nullOnDelete();

            $table->timestamp('finalized_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('failure_reason', 64)->nullable();
            $table->timestamp('expired_at')->nullable();

            // Paid but no room (or amount mismatch): staff resolution
            $table->string('unallocated_reason', 64)->nullable();
            $table->string('resolution_status', 32)->nullable()->index();
            $table->text('resolution_note')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->string('refund_reference')->nullable()->unique();
            $table->string('refund_status', 16)->nullable(); // submitted, succeeded, failed
            $table->decimal('refunded_amount', 10, 2)->nullable();
            $table->timestamp('refunded_at')->nullable(); // only after a confirmed successful payout

            // Booking-confirmation notifications are sent once
            $table->timestamp('staff_notified_at')->nullable();
            $table->timestamp('customer_notified_at')->nullable();

            // Provisional 30-day retention review for expired data (no automatic deletion)
            $table->timestamp('retention_review_at')->nullable();

            $table->timestamps();

            $table->index(['status', 'hold_expires_at']);
            $table->index(['start_date', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checkout_intents');
    }
};
