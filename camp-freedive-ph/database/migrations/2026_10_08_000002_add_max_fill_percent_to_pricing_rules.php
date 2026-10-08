<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pricing clean-up (discounts were outweighing premiums ~13:1):
 * - new optional condition: a rule only applies while the batch is less than X% full
 * - Last-Minute Fill only while the batch is under 50% full
 * - Off-Peak Discount off (same signal as Low Demand, which is per date and follows real bookings)
 * - Shoulder Season Offer off (shoulder months are average demand)
 * Rules are turned off, not deleted, so they can be switched back on from the Pricing page.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pricing_rules', function (Blueprint $table) {
            $table->unsignedTinyInteger('max_fill_percent')->nullable()->after('condition_value');
        });

        DB::table('pricing_rules')->where('name', 'Last-Minute Fill')->update(['max_fill_percent' => 50]);
        DB::table('pricing_rules')->whereIn('name', ['Off-Peak Discount', 'Shoulder Season Offer'])->update(['status' => 'inactive']);
    }

    public function down(): void
    {
        DB::table('pricing_rules')->whereIn('name', ['Off-Peak Discount', 'Shoulder Season Offer'])->update(['status' => 'active']);

        Schema::table('pricing_rules', function (Blueprint $table) {
            $table->dropColumn('max_fill_percent');
        });
    }
};
