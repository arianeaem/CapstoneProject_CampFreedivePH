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
        Schema::table('batches', function (Blueprint $table) {
            $table->string('name')->nullable()->after('id'); // e.g. Aug 30-31 Batch
            $table->string('lifecycle_status', 32)->default('confirmed')->after('end_date'); // confirmed, completed, rescheduled, cancelled_by_camp
            $table->string('risk_classification', 32)->default('very_safe')->after('lifecycle_status');
            $table->foreignId('created_by')->nullable()->after('notes')->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable()->after('created_by');
            $table->timestamp('cancelled_at')->nullable()->after('closed_at');
            $table->text('cancellation_reason')->nullable()->after('cancelled_at');
            $table->timestamp('completed_at')->nullable()->after('cancellation_reason');
            $table->timestamp('archived_at')->nullable()->after('completed_at');
            $table->string('capacity_note')->nullable()->after('max_capacity');
        });

        // Batch Status Logs (Audit trail for whole-batch status changes)
        Schema::create('batch_status_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('batches')->onDelete('cascade');
            $table->string('old_status', 32)->nullable();
            $table->string('new_status', 32);
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        // Link bookings to batches
        Schema::table('bookings', function (Blueprint $table) {
            $table->foreignId('batch_id')->nullable()->after('id')->constrained('batches')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropForeign(['batch_id']);
            $table->dropColumn('batch_id');
        });

        Schema::dropIfExists('batch_status_logs');

        Schema::table('batches', function (Blueprint $table) {
            $table->dropForeign(['created_by']);
            $table->dropColumn([
                'name',
                'lifecycle_status',
                'risk_classification',
                'created_by',
                'closed_at',
                'cancelled_at',
                'cancellation_reason',
                'completed_at',
                'archived_at',
                'capacity_note',
            ]);
        });
    }
};
