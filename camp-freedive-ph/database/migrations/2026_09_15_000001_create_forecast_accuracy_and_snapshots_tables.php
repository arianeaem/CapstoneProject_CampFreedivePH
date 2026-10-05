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
        // 1. Forecast snapshots (T-14, T-7, T-3, T-1)
        Schema::create('forecast_snapshots', function (Blueprint $table) {
            $table->id();
            $table->date('target_date'); // Target dive / marine date being forecasted
            $table->unsignedSmallInteger('lead_time_days'); // e.g. 14, 7, 3, 1, 0
            $table->string('lead_time_label', 32); // e.g. "24h (1 Day)", "72h (3 Days)", "7 Days", "14 Days"
            $table->string('predicted_classification', 32); // Very Safe, Safe, Moderate, High Risk, Critical Risk
            $table->decimal('predicted_score_pct', 5, 2)->nullable();
            $table->decimal('predicted_wave_height', 5, 2)->nullable();
            $table->decimal('predicted_wind_speed', 5, 2)->nullable();
            $table->decimal('predicted_ocean_current', 5, 2)->nullable();
            $table->decimal('predicted_rain', 5, 2)->nullable();
            $table->decimal('predicted_pressure', 6, 2)->nullable();
            $table->string('ml_predicted_classification', 32)->nullable();
            $table->json('hourly_data')->nullable();
            $table->timestamp('captured_at')->useCurrent();
            $table->timestamps();

            $table->unique(['target_date', 'lead_time_days'], 'uq_snapshot_target_lead_time');
            $table->index('target_date');
        });

        // 2. Accuracy logs (forecast vs actual weather)
        Schema::create('forecast_accuracy_logs', function (Blueprint $table) {
            $table->id();
            $table->date('target_date'); // The dive date being audited (T-0)
            $table->unsignedSmallInteger('lead_time_days'); // Horizon evaluated: 1, 3, 7, 14, etc.
            $table->string('lead_time_label', 32);
            $table->string('predicted_classification', 32);
            $table->string('actual_classification', 32);
            $table->boolean('classification_matched')->default(false);
            $table->decimal('predicted_wave_height', 5, 2)->nullable();
            $table->decimal('actual_wave_height', 5, 2)->nullable();
            $table->decimal('wave_height_error', 5, 2)->nullable(); // |predicted - actual|
            $table->decimal('predicted_wind_speed', 5, 2)->nullable();
            $table->decimal('actual_wind_speed', 5, 2)->nullable();
            $table->decimal('wind_speed_error', 5, 2)->nullable(); // |predicted - actual|
            $table->decimal('predicted_ocean_current', 5, 2)->nullable();
            $table->decimal('actual_ocean_current', 5, 2)->nullable();
            $table->decimal('current_error', 5, 2)->nullable(); // |predicted - actual|
            $table->decimal('predicted_rain', 5, 2)->nullable();
            $table->decimal('actual_rain', 5, 2)->nullable();
            $table->decimal('rain_error', 5, 2)->nullable(); // |predicted - actual|
            $table->decimal('accuracy_score_pct', 5, 2)->nullable(); // 0 - 100% composite score
            $table->string('ml_predicted_classification', 32)->nullable();
            $table->boolean('ml_classification_matched')->nullable();
            $table->timestamp('verified_at')->useCurrent();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['target_date', 'lead_time_days'], 'uq_accuracy_target_lead_time');
            $table->index('target_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('forecast_accuracy_logs');
        Schema::dropIfExists('forecast_snapshots');
    }
};
