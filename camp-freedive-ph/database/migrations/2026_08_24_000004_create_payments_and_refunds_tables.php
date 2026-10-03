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
        Schema::table('payments', function (Blueprint $table) {
            $table->string('paymongo_payment_id')->nullable()->after('transaction_id');
            $table->string('paymongo_resource_id')->nullable()->after('paymongo_payment_id');
            $table->decimal('fee_amount', 10, 2)->default(0)->after('amount');
            $table->decimal('net_amount', 10, 2)->default(0)->after('fee_amount');
            $table->string('paymongo_refund_id')->nullable()->after('status');
            $table->decimal('amount_refunded', 10, 2)->default(0)->after('paymongo_refund_id');
            $table->text('refund_reason')->nullable()->after('amount_refunded');
            $table->boolean('is_forfeited')->default(false)->after('refund_reason');
            $table->decimal('forfeited_amount', 10, 2)->default(0)->after('is_forfeited');
            $table->string('forfeit_reason')->nullable()->after('forfeited_amount');
            $table->timestamp('expires_at')->nullable()->after('paid_at');
            $table->foreignId('created_by')->nullable()->after('expires_at')->constrained('users')->nullOnDelete();
        });

        Schema::create('refund_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained('payments')->onDelete('cascade');
            $table->foreignId('booking_id')->constrained('bookings')->onDelete('cascade');
            $table->string('requested_by')->default('customer'); // customer, admin
            $table->timestamp('requested_at')->useCurrent();
            $table->json('eligibility_calculated')->nullable();
            $table->string('status', 32)->default('pending'); // pending, approved, rejected, forfeited
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('paymongo_refund_id')->nullable();
            $table->string('forfeit_reason')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('payment_status_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained('payments')->onDelete('cascade');
            $table->string('old_status', 32);
            $table->string('new_status', 32);
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_status_logs');
        Schema::dropIfExists('refund_requests');

        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign(['created_by']);
            $table->dropColumn([
                'paymongo_payment_id',
                'paymongo_resource_id',
                'fee_amount',
                'net_amount',
                'paymongo_refund_id',
                'amount_refunded',
                'refund_reason',
                'is_forfeited',
                'forfeited_amount',
                'forfeit_reason',
                'expires_at',
                'created_by',
            ]);
        });
    }
};
