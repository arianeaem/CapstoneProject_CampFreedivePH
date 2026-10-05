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
        // 1. Coach availability (set by the coach, can be changed by admin)
        Schema::create('coach_availabilities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coach_id')->constrained('users')->onDelete('cascade');
            $table->date('date');
            $table->string('status', 32)->default('available'); // available, unavailable, assigned
            $table->string('notes')->nullable();
            $table->timestamps();

            $table->unique(['coach_id', 'date']);
        });

        // 2. Which coach each participant has
        Schema::create('participant_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('participant_id')->constrained('booking_participants')->onDelete('cascade');
            $table->foreignId('booking_id')->constrained('bookings')->onDelete('cascade');
            $table->foreignId('coach_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('batch_id')->nullable()->constrained('batches')->nullOnDelete();
            $table->date('dive_date');
            $table->foreignId('assigned_by')->constrained('users')->onDelete('cascade');
            $table->timestamp('assigned_at')->useCurrent();
            $table->string('status', 32)->default('assigned'); // assigned, reassigned, completed
            $table->boolean('is_ratio_override')->default(false); // exception flag if > 4:1
            $table->timestamps();
        });

        // 3. Open coach slots (shown on the Coach Portal)
        Schema::create('coach_openings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('batches')->onDelete('cascade');
            $table->date('dive_date');
            $table->unsignedInteger('needed_students_count')->default(4);
            $table->string('status', 32)->default('open'); // open, filled, cancelled
            $table->foreignId('posted_by')->constrained('users')->onDelete('cascade');
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        // 4. Coach requests for open slots
        Schema::create('coach_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('opening_id')->nullable()->constrained('coach_openings')->nullOnDelete();
            $table->foreignId('batch_id')->constrained('batches')->onDelete('cascade');
            $table->foreignId('coach_id')->constrained('users')->onDelete('cascade');
            $table->string('status', 32)->default('pending'); // pending, approved, not_selected
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        // 5. History of student reassignments
        Schema::create('assignment_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('participant_id')->constrained('booking_participants')->onDelete('cascade');
            $table->foreignId('old_coach_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('new_coach_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('changed_by')->constrained('users')->onDelete('cascade');
            $table->text('reason')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('assignment_logs');
        Schema::dropIfExists('coach_requests');
        Schema::dropIfExists('coach_openings');
        Schema::dropIfExists('participant_assignments');
        Schema::dropIfExists('coach_availabilities');
    }
};
