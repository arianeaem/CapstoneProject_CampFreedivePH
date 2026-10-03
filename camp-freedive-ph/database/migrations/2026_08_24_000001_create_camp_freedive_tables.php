<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->string('booking_number', 32)->unique();
            $table->string('pin', 8);
            $table->string('class_type', 32); // discovery, fundive, refinement
            $table->boolean('is_certified_diver')->default(false);
            $table->date('start_date');
            $table->date('end_date');
            $table->string('pickup_option', 32)->default('none'); // none, own, carpool
            $table->string('pickup_location')->nullable();
            $table->decimal('carpool_fee', 10, 2)->default(0);
            $table->boolean('boat_dive')->default(false);
            $table->decimal('boat_dive_fee', 10, 2)->default(0);
            $table->decimal('lgu_fee', 10, 2)->default(0);
            $table->decimal('environmental_fee', 10, 2)->default(0);
            $table->decimal('subtotal', 10, 2)->default(0);
            $table->decimal('total_amount', 10, 2)->default(0);
            $table->decimal('downpayment_amount', 10, 2)->default(0);
            $table->decimal('balance_amount', 10, 2)->default(0);
            $table->string('contact_name');
            $table->string('contact_email');
            $table->string('contact_phone', 32);
            $table->string('contact_facebook')->nullable();
            $table->string('status', 32)->default('confirmed'); // pending_payment, confirmed, reschedule_requested, cancellation_requested, cancelled, completed
            $table->timestamps();
        });

        Schema::create('booking_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained('bookings')->onDelete('cascade');
            $table->string('name');
            $table->integer('age');
            $table->text('health_condition')->nullable();
            $table->string('swimmer_status', 32)->nullable(); // swimmer, non-swimmer
            $table->decimal('price_per_person', 10, 2);
            $table->timestamps();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained('bookings')->onDelete('cascade');
            $table->string('payment_method', 32); // gcash, bank_transfer
            $table->string('transaction_id', 64)->unique();
            $table->decimal('amount', 10, 2);
            $table->string('payment_type', 32)->default('downpayment'); // downpayment, full
            $table->string('status', 32)->default('completed'); // pending, completed, refunded
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });

        Schema::create('reschedule_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained('bookings')->onDelete('cascade');
            $table->date('current_start_date');
            $table->date('current_end_date');
            $table->date('requested_start_date');
            $table->date('requested_end_date');
            $table->text('reason')->nullable();
            $table->string('status', 32)->default('pending'); // pending, approved, rejected
            $table->timestamps();
        });

        Schema::create('cancellation_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained('bookings')->onDelete('cascade');
            $table->decimal('calculated_refund_amount', 10, 2)->default(0);
            $table->text('reason')->nullable();
            $table->boolean('force_majeure_flag')->default(false);
            $table->string('status', 32)->default('pending'); // pending, approved, rejected
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cancellation_requests');
        Schema::dropIfExists('reschedule_requests');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('booking_participants');
        Schema::dropIfExists('bookings');
    }
};
