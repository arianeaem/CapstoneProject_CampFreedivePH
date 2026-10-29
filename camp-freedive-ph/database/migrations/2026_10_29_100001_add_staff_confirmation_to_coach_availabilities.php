<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * S1: distinguish self-declared coach availability from staff-confirmed availability.
 *
 * Existing rows remain unconfirmed (staff_confirmed_by NULL) — they are not silently
 * promoted to confirmed. Staff confirmation is a deliberate Owner/Admin action.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coach_availabilities', function (Blueprint $table) {
            $table->foreignId('staff_confirmed_by')->nullable()->after('notes')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('staff_confirmed_at')->nullable()->after('staff_confirmed_by');
        });
    }

    public function down(): void
    {
        Schema::table('coach_availabilities', function (Blueprint $table) {
            $table->dropConstrainedForeignId('staff_confirmed_by');
            $table->dropColumn('staff_confirmed_at');
        });
    }
};
