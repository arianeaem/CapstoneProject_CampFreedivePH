<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Batch 3-II B1: one row per provider payment (provider + provider payment id, database-unique), holding the facts the
 * provider verified when it was recorded: amount in integer centavos, currency, live/test mode, the owner (checkout
 * intent and/or booking) and the receipt it became. A replay is accepted only when all of them match (ReceiptRecorder).
 *
 * payments.paymongo_payment_id stays a plain index: historical rows may repeat an id and are never merged, deleted or
 * given to an arbitrary winner. Rows recorded before this table existed have no identity until a separately approved
 * backfill; a NULL currency or livemode means "not verified", never "PHP" or "live".
 *
 * provider_identity_conflicts keeps every refused match (different facts, different owner, historical duplicate or
 * unverified id) for staff, once per distinct attempt.
 *
 * Verified facts are permanent (trigger on both drivers): no delete; provider, id, amount and status never change;
 * currency, livemode, booking and receipt may only be filled in once (empty -> value); the checkout intent never changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_payment_identities', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 16);                      // paymongo
            $table->string('provider_payment_id', 191);
            $table->bigInteger('amount_centavos');
            $table->char('currency', 3)->nullable();             // NULL = not verified
            $table->boolean('livemode')->nullable();             // NULL = not verified
            $table->foreignId('checkout_intent_id')->nullable()->constrained('checkout_intents')->restrictOnDelete();
            $table->foreignId('booking_id')->nullable()->constrained('bookings')->restrictOnDelete();
            $table->foreignId('payment_id')->nullable()->unique()->constrained('payments')->restrictOnDelete();
            $table->string('status', 16);                        // claimed
            $table->string('verification', 24);                  // provider_verified | historical_unverified
            $table->string('source', 48);
            $table->timestamps();
            $table->unique(['provider', 'provider_payment_id']);
        });

        Schema::create('provider_identity_conflicts', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 16);
            $table->string('provider_payment_id', 191);
            $table->foreignId('identity_id')->nullable()->constrained('provider_payment_identities')->restrictOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained('payments')->restrictOnDelete(); // the existing receipt, if any
            $table->string('reason', 48);                        // provider_mismatch, owner_mismatch, historical_duplicate, historical_unverified, receipt_missing
            $table->string('source', 48);
            $table->json('attempted');                           // what the refused event claimed: owner, centavos, currency, mode (no personal data)
            $table->char('fingerprint', 64)->unique();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['provider', 'provider_payment_id']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION cfp_guard_provider_payment_identity() RETURNS trigger LANGUAGE plpgsql AS $$
                BEGIN
                    IF TG_OP = 'DELETE' THEN
                        RAISE EXCEPTION 'Provider payment identities are permanent.';
                    END IF;
                    IF NEW.provider IS DISTINCT FROM OLD.provider OR NEW.provider_payment_id IS DISTINCT FROM OLD.provider_payment_id
                        OR NEW.amount_centavos IS DISTINCT FROM OLD.amount_centavos OR NEW.status IS DISTINCT FROM OLD.status
                        OR NEW.checkout_intent_id IS DISTINCT FROM OLD.checkout_intent_id
                        OR (OLD.currency IS NOT NULL AND NEW.currency IS DISTINCT FROM OLD.currency)
                        OR (OLD.livemode IS NOT NULL AND NEW.livemode IS DISTINCT FROM OLD.livemode)
                        OR (OLD.booking_id IS NOT NULL AND NEW.booking_id IS DISTINCT FROM OLD.booking_id)
                        OR (OLD.payment_id IS NOT NULL AND NEW.payment_id IS DISTINCT FROM OLD.payment_id)
                        OR (OLD.verification = 'provider_verified' AND NEW.verification IS DISTINCT FROM OLD.verification) THEN
                        RAISE EXCEPTION 'Verified provider payment facts cannot be changed.';
                    END IF;
                    RETURN NEW;
                END $$;
                CREATE TRIGGER provider_payment_identities_guard BEFORE UPDATE OR DELETE ON provider_payment_identities
                    FOR EACH ROW EXECUTE FUNCTION cfp_guard_provider_payment_identity();
                SQL);
        } else {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER provider_payment_identities_no_delete BEFORE DELETE ON provider_payment_identities
                BEGIN SELECT RAISE(ABORT, 'Provider payment identities are permanent.'); END;
                CREATE TRIGGER provider_payment_identities_guard BEFORE UPDATE ON provider_payment_identities
                WHEN NEW.provider IS NOT OLD.provider OR NEW.provider_payment_id IS NOT OLD.provider_payment_id
                    OR NEW.amount_centavos IS NOT OLD.amount_centavos OR NEW.status IS NOT OLD.status
                    OR NEW.checkout_intent_id IS NOT OLD.checkout_intent_id
                    OR (OLD.currency IS NOT NULL AND NEW.currency IS NOT OLD.currency)
                    OR (OLD.livemode IS NOT NULL AND NEW.livemode IS NOT OLD.livemode)
                    OR (OLD.booking_id IS NOT NULL AND NEW.booking_id IS NOT OLD.booking_id)
                    OR (OLD.payment_id IS NOT NULL AND NEW.payment_id IS NOT OLD.payment_id)
                    OR (OLD.verification = 'provider_verified' AND NEW.verification IS NOT OLD.verification)
                BEGIN SELECT RAISE(ABORT, 'Verified provider payment facts cannot be changed.'); END;
                SQL);
        }
    }

    /** Refused once any identity exists: verified provider facts are never discarded. */
    public function down(): void
    {
        if (DB::table('provider_payment_identities')->exists() || DB::table('provider_identity_conflicts')->exists()) {
            throw new RuntimeException('Provider payment identities or conflicts are recorded. Rolling back would discard verified payment facts; nothing was changed.');
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS provider_payment_identities_guard ON provider_payment_identities; DROP FUNCTION IF EXISTS cfp_guard_provider_payment_identity();');
        }
        Schema::drop('provider_identity_conflicts');
        Schema::drop('provider_payment_identities');
    }
};
