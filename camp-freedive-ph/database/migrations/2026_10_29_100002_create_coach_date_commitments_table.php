<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * S1: authoritative per-date coach commitments.
 *
 * Each row means "this coach is committed to this batch on this date". The unique constraint
 * on (coach_id, date) is the single source of truth for conflict prevention — the database
 * rejects any attempt to commit the same coach to two batches on the same day.
 *
 * Two-day camps produce two commitment rows (one per day). Multi-day camps produce one per day.
 * Unassignment deletes the commitment row; audit history lives in assignment_logs.
 *
 * Existing coach_assignments (batch-level, no per-date conflict check) are left untouched —
 * the S1 commitments table is a parallel authoritative source that future S2–S5 work
 * will integrate into the booking capacity gate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coach_date_commitments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coach_id')->constrained('coaches')->cascadeOnDelete();
            $table->foreignId('batch_id')->constrained('batches')->cascadeOnDelete();
            $table->date('date');
            $table->foreignId('assigned_by')->constrained('users')->cascadeOnDelete();
            $table->timestamp('assigned_at')->useCurrent();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['coach_id', 'date']);
            $table->index(['batch_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coach_date_commitments');
    }
};
