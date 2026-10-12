<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pricing changes for steadier revenue (owner review, Oct 2026):
 * - new optional condition: a rule only applies while the batch is at least X% full
 * - Off-peak discount: only while the batch is under 50% full
 * - Early-bird discount: -10% (was a fixed amount), only while the batch is under 40% full
 * - Last-minute surcharge: turned off
 * - minimum price per class (80% of the default base price), editable in Settings › Programs
 * Rules are matched by what they do, not by name, because live rule names can differ.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pricing_rules', function (Blueprint $table) {
            $table->unsignedTinyInteger('min_fill_percent')->nullable()->after('max_fill_percent');
        });

        DB::table('pricing_rules')
            ->where('rule_type', 'seasonality')->where('condition_value', 'off_peak')->where('adjustment_type', 'decrease')
            ->update(['max_fill_percent' => 50]);

        DB::table('pricing_rules')
            ->where('rule_type', 'lead_time')->whereIn('condition_operator', ['>=', '>'])->where('adjustment_type', 'decrease')
            ->update(['adjustment_method' => 'percentage', 'adjustment_value' => 10, 'max_fill_percent' => 40]);

        DB::table('pricing_rules')
            ->where('rule_type', 'lead_time')->whereIn('condition_operator', ['<=', '<'])->where('adjustment_type', 'increase')
            ->update(['status' => 'inactive']);

        foreach ([
            ['min_price_discovery', '3400.00', 'Discovery Freedive'],
            ['min_price_fundive_cert', '2000.00', 'Fun Dive (Certified)'],
            ['min_price_fundive_noncert', '2650.00', 'Fun Dive (Non-Certified)'],
            ['min_price_refinement', '3300.00', 'Skills Refinement'],
        ] as [$key, $value, $class]) {
            DB::table('system_settings')->insertOrIgnore([
                'group' => 'program_pricing',
                'key' => "program_pricing.{$key}",
                'value' => $value,
                'type' => 'float',
                'description' => "Lowest price per participant for {$class}, whatever the pricing rules say.",
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('system_settings')->where('key', 'like', 'program_pricing.min_price_%')->delete();

        Schema::table('pricing_rules', function (Blueprint $table) {
            $table->dropColumn('min_fill_percent');
        });
    }
};
