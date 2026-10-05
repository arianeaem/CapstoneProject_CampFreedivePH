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
        // 1. Batch risk results (Day 1 and Day 2, one row each per run)
        Schema::create('batch_risk_assessments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('batches')->onDelete('cascade');
            $table->unsignedTinyInteger('day_number'); // 1 or 2
            $table->date('dive_date');
            $table->decimal('lead_time_hours', 8, 2)->nullable();
            $table->string('overall_classification', 32); // Very Safe, Safe, Moderate, High Risk, Critical Risk
            $table->decimal('weighted_score_pct', 5, 2)->nullable(); // Null when manual override is triggered
            $table->text('recommended_action');
            $table->string('worst_window', 64)->nullable(); // e.g. 15:30-17:30
            $table->timestamp('worst_hour')->nullable();
            $table->boolean('override_triggered')->default(false);
            $table->json('override_details')->nullable();
            $table->foreignId('assessed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assessed_at')->useCurrent();
            $table->timestamps();
        });

        // 2. Hourly results for the AM and PM windows
        Schema::create('hourly_assessments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('risk_assessment_id')->constrained('batch_risk_assessments')->onDelete('cascade');
            $table->string('window_type', 16); // am or pm
            $table->string('open_water_window', 32); // 09:30-12:00 or 15:30-17:30
            $table->timestamp('forecast_time');
            $table->decimal('wave_height', 5, 2)->nullable();
            $table->decimal('wave_period', 5, 2)->nullable();
            $table->decimal('swell_height', 5, 2)->nullable();
            $table->decimal('wind_wave_height', 5, 2)->nullable();
            $table->decimal('ocean_current', 5, 2)->nullable();
            $table->decimal('rain', 5, 2)->nullable();
            $table->decimal('sea_level_pressure', 6, 2)->nullable();
            $table->decimal('wind_speed', 5, 2)->nullable();
            $table->decimal('wind_direction', 5, 2)->nullable();
            $table->decimal('tide_height', 5, 2)->nullable();
            $table->unsignedTinyInteger('tide_score')->default(0);
            $table->decimal('weighted_score_pct', 5, 2)->nullable();
            $table->string('classification', 32);
            $table->text('recommended_action')->nullable();
            $table->boolean('is_worst_hour_in_window')->default(false);
            $table->timestamps();
        });

        // 3. Admin overrides (like PAGASA advisories)
        Schema::create('manual_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('batches')->onDelete('cascade');
            $table->unsignedTinyInteger('tcws_signal')->default(0); // 0 to 5
            $table->boolean('gale_warning')->default(false);
            $table->boolean('thunderstorm_advisory')->default(false);
            $table->boolean('typhoon_within_distance')->default(false);
            $table->boolean('tsunami_warning')->default(false);
            $table->text('reason')->nullable();
            $table->boolean('cancelled_batch')->default(false);
            $table->foreignId('applied_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });

        // 4. Sent cancellation and weather emails
        Schema::create('notification_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->nullable()->constrained('batches')->nullOnDelete();
            $table->foreignId('booking_id')->nullable()->constrained('bookings')->nullOnDelete();
            $table->string('recipient_email');
            $table->string('recipient_name');
            $table->string('subject');
            $table->text('message_body');
            $table->string('channel', 32)->default('email');
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at')->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notification_logs');
        Schema::dropIfExists('manual_overrides');
        Schema::dropIfExists('hourly_assessments');
        Schema::dropIfExists('batch_risk_assessments');
    }
};
