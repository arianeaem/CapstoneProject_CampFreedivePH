<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Non-weather hazards for overrides (e.g. oil spill, red tide, no-sail order).
     */
    public function up(): void
    {
        Schema::table('manual_overrides', function (Blueprint $table) {
            $table->string('other_hazard')->nullable()->after('tsunami_warning');
        });
    }

    public function down(): void
    {
        Schema::table('manual_overrides', function (Blueprint $table) {
            $table->dropColumn('other_hazard');
        });
    }
};
