<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Batch 3-II B1: payment exceptions. Verified money that could not simply be applied (paid checkout with no place, a
 * legacy booking paid after its date filled up, paid after cancellation, a different amount, paid again) gets one
 * exception per receipt, so it stays visible until a staff workflow resolves it.
 *
 *  - payment_exceptions: one per primary receipt; the owner (booking, or checkout intent when no booking exists) and
 *    the provider evidence are snapshotted at opening.
 *  - payment_exception_receipts: the receipts an exception covers. A receipt belongs to at most one exception (unique),
 *    and only to an exception of its own owner (trigger: same booking, or same checkout intent with no booking).
 *  - payment_exception_events: append-only history.
 *
 * B1 opens exceptions but has no way to resolve them: status and resolution columns cannot change, nothing can be
 * deleted, and links and events are immutable (triggers on both drivers). The B2 guest-choice workflow will replace
 * the exception trigger with its own allowed transitions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_exceptions', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 40);                          // intent_unallocated, legacy_unallocated, paid_after_cancellation, amount_mismatch, paid_again
            $table->string('status', 24)->default('open');       // open (B1 never resolves)
            $table->foreignId('primary_payment_id')->unique()->constrained('payments')->restrictOnDelete();
            $table->foreignId('checkout_intent_id')->nullable()->constrained('checkout_intents')->restrictOnDelete();
            $table->foreignId('booking_id')->nullable()->constrained('bookings')->restrictOnDelete();
            $table->bigInteger('amount_centavos');
            $table->char('currency', 3)->nullable();
            $table->string('provider_payment_id', 191)->nullable();
            $table->json('evidence')->nullable();                // ids, reason, amounts, source (no personal data)
            $table->timestamp('opened_at');
            $table->timestamp('resolved_at')->nullable();
            $table->string('resolution', 48)->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps();
            $table->index('status');
            $table->index('booking_id');
            $table->index('checkout_intent_id');
        });

        Schema::create('payment_exception_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_exception_id')->constrained('payment_exceptions')->restrictOnDelete();
            $table->foreignId('payment_id')->unique()->constrained('payments')->restrictOnDelete();
            $table->string('owner_type', 16);                    // booking | checkout_intent
            $table->unsignedBigInteger('owner_id');
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['payment_exception_id', 'payment_id']);
        });

        Schema::create('payment_exception_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_exception_id')->constrained('payment_exceptions')->restrictOnDelete();
            $table->string('type', 48);                          // opened, batch_cancelled_by_camp, booking_cancelled_by_camp, ...
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->json('details')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['payment_exception_id', 'id']);
        });

        $ownerMismatch = <<<'SQL'
            NOT EXISTS (
                SELECT 1 FROM payments p JOIN payment_exceptions e ON e.id = NEW.payment_exception_id
                WHERE p.id = NEW.payment_id AND (
                    (e.booking_id IS NOT NULL AND p.booking_id = e.booking_id AND NEW.owner_type = 'booking' AND NEW.owner_id = e.booking_id)
                    OR (e.booking_id IS NULL AND e.checkout_intent_id IS NOT NULL AND p.booking_id IS NULL AND p.checkout_intent_id = e.checkout_intent_id
                        AND NEW.owner_type = 'checkout_intent' AND NEW.owner_id = e.checkout_intent_id)))
            SQL;

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<SQL
                CREATE OR REPLACE FUNCTION cfp_guard_payment_exception() RETURNS trigger LANGUAGE plpgsql AS \$\$
                BEGIN
                    IF TG_OP = 'DELETE' THEN
                        RAISE EXCEPTION 'Payment exceptions are permanent.';
                    END IF;
                    IF NEW.kind IS DISTINCT FROM OLD.kind OR NEW.primary_payment_id IS DISTINCT FROM OLD.primary_payment_id
                        OR NEW.checkout_intent_id IS DISTINCT FROM OLD.checkout_intent_id OR NEW.booking_id IS DISTINCT FROM OLD.booking_id
                        OR NEW.amount_centavos IS DISTINCT FROM OLD.amount_centavos OR NEW.currency IS DISTINCT FROM OLD.currency
                        OR NEW.provider_payment_id IS DISTINCT FROM OLD.provider_payment_id OR NEW.opened_at IS DISTINCT FROM OLD.opened_at
                        OR NEW.status IS DISTINCT FROM OLD.status OR NEW.resolved_at IS DISTINCT FROM OLD.resolved_at
                        OR NEW.resolution IS DISTINCT FROM OLD.resolution OR NEW.resolved_by IS DISTINCT FROM OLD.resolved_by THEN
                        RAISE EXCEPTION 'A payment exception can only be resolved through the approved workflow.';
                    END IF;
                    RETURN NEW;
                END \$\$;
                CREATE TRIGGER payment_exceptions_guard BEFORE UPDATE OR DELETE ON payment_exceptions
                    FOR EACH ROW EXECUTE FUNCTION cfp_guard_payment_exception();

                CREATE OR REPLACE FUNCTION cfp_immutable_row() RETURNS trigger LANGUAGE plpgsql AS \$\$
                BEGIN
                    RAISE EXCEPTION '% rows are append-only.', TG_TABLE_NAME;
                END \$\$;
                CREATE TRIGGER payment_exception_receipts_immutable BEFORE UPDATE OR DELETE ON payment_exception_receipts
                    FOR EACH ROW EXECUTE FUNCTION cfp_immutable_row();
                CREATE TRIGGER payment_exception_events_immutable BEFORE UPDATE OR DELETE ON payment_exception_events
                    FOR EACH ROW EXECUTE FUNCTION cfp_immutable_row();

                CREATE OR REPLACE FUNCTION cfp_guard_payment_exception_receipt() RETURNS trigger LANGUAGE plpgsql AS \$\$
                BEGIN
                    IF {$ownerMismatch} THEN
                        RAISE EXCEPTION 'A receipt can only join a payment exception of its own booking or checkout intent.';
                    END IF;
                    RETURN NEW;
                END \$\$;
                CREATE TRIGGER payment_exception_receipts_owner BEFORE INSERT ON payment_exception_receipts
                    FOR EACH ROW EXECUTE FUNCTION cfp_guard_payment_exception_receipt();
                SQL);
        } else {
            DB::unprepared(<<<SQL
                CREATE TRIGGER payment_exceptions_no_delete BEFORE DELETE ON payment_exceptions
                BEGIN SELECT RAISE(ABORT, 'Payment exceptions are permanent.'); END;
                CREATE TRIGGER payment_exceptions_guard BEFORE UPDATE ON payment_exceptions
                WHEN NEW.kind IS NOT OLD.kind OR NEW.primary_payment_id IS NOT OLD.primary_payment_id
                    OR NEW.checkout_intent_id IS NOT OLD.checkout_intent_id OR NEW.booking_id IS NOT OLD.booking_id
                    OR NEW.amount_centavos IS NOT OLD.amount_centavos OR NEW.currency IS NOT OLD.currency
                    OR NEW.provider_payment_id IS NOT OLD.provider_payment_id OR NEW.opened_at IS NOT OLD.opened_at
                    OR NEW.status IS NOT OLD.status OR NEW.resolved_at IS NOT OLD.resolved_at
                    OR NEW.resolution IS NOT OLD.resolution OR NEW.resolved_by IS NOT OLD.resolved_by
                BEGIN SELECT RAISE(ABORT, 'A payment exception can only be resolved through the approved workflow.'); END;
                CREATE TRIGGER payment_exception_receipts_no_update BEFORE UPDATE ON payment_exception_receipts
                BEGIN SELECT RAISE(ABORT, 'payment_exception_receipts rows are append-only.'); END;
                CREATE TRIGGER payment_exception_receipts_no_delete BEFORE DELETE ON payment_exception_receipts
                BEGIN SELECT RAISE(ABORT, 'payment_exception_receipts rows are append-only.'); END;
                CREATE TRIGGER payment_exception_events_no_update BEFORE UPDATE ON payment_exception_events
                BEGIN SELECT RAISE(ABORT, 'payment_exception_events rows are append-only.'); END;
                CREATE TRIGGER payment_exception_events_no_delete BEFORE DELETE ON payment_exception_events
                BEGIN SELECT RAISE(ABORT, 'payment_exception_events rows are append-only.'); END;
                CREATE TRIGGER payment_exception_receipts_owner BEFORE INSERT ON payment_exception_receipts
                WHEN {$ownerMismatch}
                BEGIN SELECT RAISE(ABORT, 'A receipt can only join a payment exception of its own booking or checkout intent.'); END;
                SQL);
        }
    }

    /** Refused once any exception exists: open exceptions are verified money that must stay visible. */
    public function down(): void
    {
        if (DB::table('payment_exceptions')->exists()) {
            throw new RuntimeException('Payment exceptions are recorded. Rolling back would hide verified money that still needs a decision; nothing was changed.');
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS payment_exceptions_guard ON payment_exceptions; DROP FUNCTION IF EXISTS cfp_guard_payment_exception();'
                . ' DROP TRIGGER IF EXISTS payment_exception_receipts_immutable ON payment_exception_receipts;'
                . ' DROP TRIGGER IF EXISTS payment_exception_events_immutable ON payment_exception_events; DROP FUNCTION IF EXISTS cfp_immutable_row();'
                . ' DROP TRIGGER IF EXISTS payment_exception_receipts_owner ON payment_exception_receipts; DROP FUNCTION IF EXISTS cfp_guard_payment_exception_receipt();');
        }
        Schema::drop('payment_exception_events');
        Schema::drop('payment_exception_receipts');
        Schema::drop('payment_exceptions');
    }
};
