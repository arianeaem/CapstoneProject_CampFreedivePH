<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The day's rating is the whole day (06:00-18:00); the roughest single hour is kept
 * beside it when it is rougher ("Peak: Moderate at 12 PM · current 0.42 m/s").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('batch_risk_assessments', function (Blueprint $table) {
            $table->string('peak_classification', 32)->nullable()->after('override_details');
            $table->string('peak_time', 16)->nullable()->after('peak_classification');
            $table->string('peak_reason')->nullable()->after('peak_time');
        });
    }

    public function down(): void
    {
        Schema::table('batch_risk_assessments', function (Blueprint $table) {
            $table->dropColumn(['peak_classification', 'peak_time', 'peak_reason']);
        });
    }
};
