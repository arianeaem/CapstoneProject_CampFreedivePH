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
        Schema::table('demand_forecasts', function (Blueprint $table) {
            if (!Schema::hasColumn('demand_forecasts', 'predicted_bookings')) {
                $table->decimal('predicted_bookings', 8, 2)->default(0)->after('predicted_participants');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('demand_forecasts', function (Blueprint $table) {
            if (Schema::hasColumn('demand_forecasts', 'predicted_bookings')) {
                $table->dropColumn('predicted_bookings');
            }
        });
    }
};
