<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Participant Directory: one record per real person, linked to every booking they join.
 * booking_participants stays as the per-booking snapshot, so editing a person never
 * changes a past booking.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('participants', function (Blueprint $table) {
            $table->id();
            $table->string('full_name');
            $table->string('name_key')->index(); // lowercase, trimmed, single spaces
            $table->date('birthdate')->nullable();
            $table->string('gender', 32)->nullable();
            $table->string('latest_swimmer_status', 32)->nullable();
            $table->text('latest_health_condition')->nullable();
            $table->timestamp('health_updated_at')->nullable();
            $table->string('last_email')->nullable();
            $table->string('last_phone', 32)->nullable();
            $table->date('last_dive_date')->nullable()->index();
            $table->foreignId('merged_into_id')->nullable()->constrained('participants')->nullOnDelete();
            $table->timestamp('anonymized_at')->nullable();
            $table->timestamps();

            $table->index(['name_key', 'birthdate']);
        });

        Schema::table('booking_participants', function (Blueprint $table) {
            $table->foreignId('participant_id')->nullable()->after('booking_id')->constrained('participants')->nullOnDelete();
            // Who the row was first linked to, so a merge can be undone
            $table->foreignId('original_participant_id')->nullable()->after('participant_id')->constrained('participants')->nullOnDelete();
        });

        Schema::create('participant_match_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('participant_a_id')->constrained('participants')->cascadeOnDelete();
            $table->foreignId('participant_b_id')->constrained('participants')->cascadeOnDelete();
            $table->string('reason');
            $table->string('status', 16)->default('open'); // open, merged, not_same
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->unique(['participant_a_id', 'participant_b_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('participant_match_reviews');
        Schema::table('booking_participants', function (Blueprint $table) {
            $table->dropConstrainedForeignId('original_participant_id');
            $table->dropConstrainedForeignId('participant_id');
        });
        Schema::dropIfExists('participants');
    }
};
