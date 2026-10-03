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
        Schema::create('demand_forecasts', function (Blueprint $table) {
            $table->id();
            $table->date('forecast_date')->index();
            $table->integer('days_ahead')->default(0);
            $table->decimal('predicted_participants', 8, 2)->default(0);
            $table->decimal('predicted_revenue_php', 12, 2)->default(0);
            $table->string('demand_level', 30)->default('Medium');
            $table->string('season_period', 30)->default('Off-Peak');
            $table->integer('instructors_needed')->default(0);
            $table->json('horizon_summary')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('synced_at')->useCurrent();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('demand_forecasts');
    }
};
