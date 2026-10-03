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
        Schema::create('coaches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('full_name');
            $table->string('email')->unique();
            $table->string('phone');
            $table->string('certification_level'); // e.g. AIDA 4 Master, PADI Freediver Instructor
            $table->string('certification_number');
            $table->date('certification_expiry');
            $table->text('specialties_notes')->nullable();
            $table->string('status', 32)->default('active'); // active, inactive, on_leave, pending_deactivation
            $table->string('photo_path')->nullable();
            $table->date('joined_at')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('batches', function (Blueprint $table) {
            $table->id();
            $table->string('batch_code')->unique();
            $table->date('start_date');
            $table->date('end_date');
            $table->unsignedInteger('max_capacity')->default(0);
            $table->string('status', 32)->default('open'); // open, full, completed, cancelled
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('coach_availability', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coach_id')->constrained('coaches')->onDelete('cascade');
            $table->tinyInteger('day_of_week'); // 0=Sunday, 1=Monday, ..., 6=Saturday
            $table->boolean('is_available')->default(true);
            $table->timestamps();
        });

        Schema::create('coach_blackout_dates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coach_id')->constrained('coaches')->onDelete('cascade');
            $table->date('blackout_date');
            $table->string('reason')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('coach_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coach_id')->constrained('coaches')->onDelete('cascade');
            $table->foreignId('batch_id')->constrained('batches')->onDelete('cascade');
            $table->foreignId('assigned_by')->constrained('users')->onDelete('cascade');
            $table->timestamp('assigned_at')->useCurrent();
            $table->timestamp('unassigned_at')->nullable();
        });

        Schema::create('deactivation_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coach_id')->constrained('coaches')->onDelete('cascade');
            $table->foreignId('requested_by')->constrained('users')->onDelete('cascade');
            $table->string('status', 32)->default('pending'); // pending, confirmed, dismissed
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->text('reason')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('deactivation_requests');
        Schema::dropIfExists('coach_assignments');
        Schema::dropIfExists('coach_blackout_dates');
        Schema::dropIfExists('coach_availability');
        Schema::dropIfExists('batches');
        Schema::dropIfExists('coaches');
    }
};
