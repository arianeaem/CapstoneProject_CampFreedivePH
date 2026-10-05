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
        Schema::create('forecast_daily', function (Blueprint $table) {
            $table->id();
            $table->decimal('site_lat', 8, 4);
            $table->decimal('site_lon', 8, 4);
            $table->date('forecast_date')->index();
            $table->timestamp('issued_at')->index();
            $table->decimal('rain_daily_mm_p50', 6, 2)->default(0);
            $table->decimal('rain_daily_mm_p90', 6, 2)->default(0);
            $table->unsignedTinyInteger('rain_score')->default(0);
            $table->string('rain_label', 64)->default('Dry / None');
            $table->decimal('p_wet', 5, 4)->default(0);
            $table->decimal('p_wet_ci_lo', 5, 4)->default(0);
            $table->decimal('p_wet_ci_hi', 5, 4)->default(0);
            $table->decimal('p_high_gust', 5, 4)->default(0);
            $table->decimal('p_high_gust_ci_lo', 5, 4)->default(0);
            $table->decimal('p_high_gust_ci_hi', 5, 4)->default(0);
            $table->decimal('prob_band_0_offshore_nne', 5, 4)->default(0);
            $table->decimal('prob_band_1_ese', 5, 4)->default(0);
            $table->decimal('prob_band_2_s_wnw', 5, 4)->default(0);
            $table->decimal('prob_band_3_onshore_habagat', 5, 4)->default(0);
            $table->string('daily_tier', 32)->default('Safe');
            $table->string('daily_label', 255)->default('');
            $table->timestamps();

            $table->unique(['site_lat', 'site_lon', 'forecast_date'], 'uq_forecast_daily_site_date');
        });

        Schema::create('forecast_hourly', function (Blueprint $table) {
            $table->id();
            $table->foreignId('forecast_daily_id')->nullable()->constrained('forecast_daily')->nullOnDelete();
            $table->decimal('site_lat', 8, 4);
            $table->decimal('site_lon', 8, 4);
            $table->timestamp('forecast_time')->index();
            $table->timestamp('issued_at')->index();
            $table->integer('lead_hours')->index();
            $table->string('hs_source', 16)->default('model');
            $table->decimal('hs_p10', 5, 3)->default(0);
            $table->decimal('hs_p50', 5, 3)->default(0);
            $table->decimal('hs_p90', 5, 3)->default(0);
            $table->string('current_speed_source', 16)->default('model');
            $table->decimal('current_speed_p10', 5, 3)->default(0);
            $table->decimal('current_speed_p50', 5, 3)->default(0);
            $table->decimal('current_speed_p90', 5, 3)->default(0);
            $table->decimal('wind_speed_p50', 5, 2)->nullable();
            $table->decimal('wind_gust_p50', 5, 2)->nullable();
            $table->decimal('slp_p50', 6, 2)->nullable();
            $table->decimal('tp_p50', 5, 2)->nullable();
            $table->decimal('swell_height_p50', 5, 3)->nullable();
            $table->decimal('wind_wave_height_p50', 5, 3)->nullable();
            $table->decimal('wind_dir_circ_mean_deg', 5, 1)->nullable();
            $table->string('tier', 32)->default('Safe');
            $table->string('label', 255)->default('');
            $table->boolean('adverse_tail_triggered')->default(false);
            $table->timestamps();

            $table->unique(['site_lat', 'site_lon', 'forecast_time'], 'uq_forecast_hourly_site_time');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('forecast_hourly');
        Schema::dropIfExists('forecast_daily');
    }
};
