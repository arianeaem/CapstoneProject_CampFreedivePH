<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Minors: the primary contact must be 18+, and participants under 18 need a
 * parent/legal guardian's consent, recorded on the booking.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->date('contact_birthdate')->nullable()->after('contact_facebook');
            $table->string('guardian_name')->nullable()->after('contact_birthdate');
            $table->string('guardian_relationship', 32)->nullable()->after('guardian_name');
            $table->string('guardian_phone', 32)->nullable()->after('guardian_relationship');
            $table->timestamp('guardian_consent_at')->nullable()->after('guardian_phone');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn(['contact_birthdate', 'guardian_name', 'guardian_relationship', 'guardian_phone', 'guardian_consent_at']);
        });
    }
};
