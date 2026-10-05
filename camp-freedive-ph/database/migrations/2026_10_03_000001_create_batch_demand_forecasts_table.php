<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ML demand forecast per batch (one row per scheduled batch).
     * Separate table so a forecast is never mixed up with real data.
     */
    public function up(): void
    {
        Schema::create('batch_demand_forecasts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('batch_id')->nullable()->index();
            $table->string('batch_code', 60)->nullable();
            $table->date('batch_date')->index();
            $table->integer('days_to_start')->default(0);
            $table->integer('booked_so_far')->default(0);
            $table->unsignedSmallInteger('capacity')->default(45);
            $table->decimal('predicted_participants', 8, 2)->default(0);
            $table->decimal('predicted_bookings', 8, 2)->default(0);
            $table->decimal('predicted_revenue_php', 12, 2)->default(0);
            $table->decimal('lower_bound', 8, 2)->nullable();
            $table->decimal('upper_bound', 8, 2)->nullable();
            $table->decimal('predicted_fill_rate', 6, 4)->nullable();
            $table->string('demand_level', 30)->default('Medium');
            $table->string('season_period', 30)->default('Shoulder');
            $table->boolean('adjusted_for_booked')->default(false);
            $table->string('data_basis', 30)->default('limited_history');
            $table->string('model_version', 80)->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->timestamp('synced_at')->useCurrent();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('batch_demand_forecasts');
    }
};
