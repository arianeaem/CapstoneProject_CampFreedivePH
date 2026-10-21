<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Links an official booking to the checkout intent it was created from.
 * Unique: one booking per intent. Nullable: existing (legacy) bookings have no intent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->foreignId('checkout_intent_id')->nullable()->unique()->constrained('checkout_intents')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropForeign(['checkout_intent_id']);
            $table->dropUnique(['checkout_intent_id']);
            $table->dropColumn('checkout_intent_id');
        });
    }
};
