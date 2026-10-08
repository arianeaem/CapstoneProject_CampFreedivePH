<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * batches.risk_classification used to default to 'very_safe', so new batches looked
 * "Very Safe" before any forecast existed. NULL now means "not yet assessed".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('batches', function (Blueprint $table) {
            $table->string('risk_classification', 32)->nullable()->default(null)->change();
        });

        // Clear the fake rating on batches that were never really assessed or overridden
        DB::table('batches')
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('batch_risk_assessments')
                ->whereColumn('batch_risk_assessments.batch_id', 'batches.id')
                ->where('overall_classification', '!=', 'Not Available'))
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('manual_overrides')
                ->whereColumn('manual_overrides.batch_id', 'batches.id'))
            ->update(['risk_classification' => null]);
    }

    public function down(): void
    {
        DB::table('batches')->whereNull('risk_classification')->update(['risk_classification' => 'very_safe']);

        Schema::table('batches', function (Blueprint $table) {
            $table->string('risk_classification', 32)->nullable(false)->default('very_safe')->change();
        });
    }
};
