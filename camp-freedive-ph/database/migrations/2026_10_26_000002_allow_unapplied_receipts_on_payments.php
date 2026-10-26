<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Batch 3-II B1: a verified checkout payment that could not be given a place is kept as a receipt of its checkout
 * intent (no booking yet). Existing rows are never rewritten.
 *
 *  - payments.checkout_intent_id: the checkout intent a receipt came from (also set on receipts of finalized intents).
 *  - payments.booking_id becomes optional, but every payment keeps an owner: a booking or a checkout intent.
 *  - Deleting a booking that still has payments is refused (RESTRICT instead of CASCADE): receipts are never deleted
 *    with their booking. The only booking cleanup (a checkout that PayMongo refused to start) removes its pending
 *    payment rows first.
 *  - A receipt is never moved: booking_id may only go from empty to a booking (an authorized placement), and
 *    checkout_intent_id never changes. Enforced by a trigger on both drivers.
 *
 * PostgreSQL: one transaction (transactional DDL). SQLite: Laravel rebuilds the table with foreign keys off (SQLite
 * migrations do not run in a transaction), so referential integrity is checked before and after, and row counts and
 * money totals must be identical afterwards or the migration stops. Note for later SQLite migrations: rebuilding
 * payments drops its triggers, which must then be created again.
 */
return new class extends Migration
{
    public function up(): void
    {
        $before = $this->snapshot();

        if (DB::getDriverName() === 'pgsql') {
            $fk = $this->bookingForeignKey();
            DB::statement('ALTER TABLE payments ADD COLUMN checkout_intent_id BIGINT NULL');
            DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_checkout_intent_id_foreign FOREIGN KEY (checkout_intent_id) REFERENCES checkout_intents (id) ON DELETE RESTRICT');
            DB::statement('CREATE INDEX payments_checkout_intent_id_index ON payments (checkout_intent_id)');
            DB::statement('ALTER TABLE payments ALTER COLUMN booking_id DROP NOT NULL');
            DB::statement("ALTER TABLE payments DROP CONSTRAINT \"{$fk}\"");
            DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_booking_id_foreign FOREIGN KEY (booking_id) REFERENCES bookings (id) ON DELETE RESTRICT NOT VALID');
            DB::statement('ALTER TABLE payments VALIDATE CONSTRAINT payments_booking_id_foreign');
            DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_owner_present CHECK (booking_id IS NOT NULL OR checkout_intent_id IS NOT NULL) NOT VALID');
            DB::statement('ALTER TABLE payments VALIDATE CONSTRAINT payments_owner_present');
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION cfp_guard_payment_owner() RETURNS trigger LANGUAGE plpgsql AS $$
                BEGIN
                    IF NEW.checkout_intent_id IS DISTINCT FROM OLD.checkout_intent_id THEN
                        RAISE EXCEPTION 'A payment''s checkout intent cannot be changed.';
                    END IF;
                    IF OLD.booking_id IS NOT NULL AND NEW.booking_id IS DISTINCT FROM OLD.booking_id THEN
                        RAISE EXCEPTION 'A payment is never moved to another booking.';
                    END IF;
                    RETURN NEW;
                END $$;
                CREATE TRIGGER payments_guard_owner BEFORE UPDATE OF booking_id, checkout_intent_id ON payments
                    FOR EACH ROW EXECUTE FUNCTION cfp_guard_payment_owner();
                SQL);
        } else {
            $this->assertSqliteIntegrity('before');
            Schema::table('payments', function (Blueprint $table) {
                $table->foreignId('checkout_intent_id')->nullable()->constrained('checkout_intents')->restrictOnDelete();
                $table->unsignedBigInteger('booking_id')->nullable()->change();
                $table->dropForeign(['booking_id']);
                $table->foreign('booking_id')->references('id')->on('bookings')->restrictOnDelete();
            });
            $this->assertSqliteIntegrity('after');
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER payments_owner_present BEFORE INSERT ON payments
                WHEN NEW.booking_id IS NULL AND NEW.checkout_intent_id IS NULL
                BEGIN SELECT RAISE(ABORT, 'A payment needs a booking or a checkout intent.'); END;
                CREATE TRIGGER payments_guard_owner BEFORE UPDATE OF booking_id, checkout_intent_id ON payments
                WHEN (NEW.booking_id IS NULL AND NEW.checkout_intent_id IS NULL)
                    OR NEW.checkout_intent_id IS NOT OLD.checkout_intent_id
                    OR (OLD.booking_id IS NOT NULL AND NEW.booking_id IS NOT OLD.booking_id)
                BEGIN SELECT RAISE(ABORT, 'A payment is never moved to another booking or checkout intent.'); END;
                SQL);
        }

        $this->assertUnchanged($before, $this->snapshot());
    }

    /** Refused while any receipt has no booking: the old schema cannot hold it (fix forward instead). */
    public function down(): void
    {
        if (DB::table('payments')->whereNull('booking_id')->exists()) {
            throw new RuntimeException('Some payments have no booking (unapplied checkout receipts). Rolling back would lose them; nothing was changed.');
        }
        $before = $this->snapshot();

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS payments_guard_owner ON payments; DROP FUNCTION IF EXISTS cfp_guard_payment_owner();');
            DB::statement('ALTER TABLE payments DROP CONSTRAINT payments_owner_present');
            DB::statement('ALTER TABLE payments DROP CONSTRAINT payments_booking_id_foreign');
            DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_booking_id_foreign FOREIGN KEY (booking_id) REFERENCES bookings (id) ON DELETE CASCADE');
            DB::statement('ALTER TABLE payments ALTER COLUMN booking_id SET NOT NULL');
            DB::statement('ALTER TABLE payments DROP COLUMN checkout_intent_id');
        } else {
            DB::unprepared('DROP TRIGGER IF EXISTS payments_owner_present; DROP TRIGGER IF EXISTS payments_guard_owner;');
            $this->assertSqliteIntegrity('before rollback');
            Schema::table('payments', function (Blueprint $table) {
                $table->dropForeign(['checkout_intent_id']);
                $table->dropForeign(['booking_id']);
                $table->foreign('booking_id')->references('id')->on('bookings')->cascadeOnDelete();
                $table->unsignedBigInteger('booking_id')->nullable(false)->change();
            });
            Schema::table('payments', fn (Blueprint $table) => $table->dropColumn('checkout_intent_id'));
            $this->assertSqliteIntegrity('after rollback');
        }

        $this->assertUnchanged($before, $this->snapshot());
    }

    private function bookingForeignKey(): string
    {
        $name = DB::selectOne("SELECT conname FROM pg_constraint WHERE conrelid = 'payments'::regclass AND contype = 'f' AND confrelid = 'bookings'::regclass")?->conname;
        if (! is_string($name)) {
            throw new RuntimeException('payments.booking_id has no foreign key to bookings; refusing to change it.');
        }

        return $name;
    }

    /** Row counts and money totals of payments and everything that points at them. */
    private function snapshot(): array
    {
        $payments = DB::selectOne('SELECT COUNT(*) AS n, COALESCE(SUM(amount), 0) AS amount, COALESCE(SUM(booking_id), 0) AS bookings, COUNT(booking_id) AS with_booking FROM payments');
        $attempts = DB::selectOne('SELECT COUNT(*) AS n, COALESCE(SUM(amount_centavos), 0) AS centavos FROM refund_attempts');

        return [
            'payments' => [(int) $payments->n, (string) $payments->amount, (string) $payments->bookings, (int) $payments->with_booking],
            'refund_attempts' => [(int) $attempts->n, (string) $attempts->centavos],
            'payment_status_logs' => DB::table('payment_status_logs')->count(),
            'refund_requests' => DB::table('refund_requests')->count(),
            'booking_refunds' => DB::table('booking_refunds')->count(),
        ];
    }

    private function assertUnchanged(array $before, array $after): void
    {
        if ($before !== $after) {
            throw new RuntimeException('Payment records changed during the migration (before ' . json_encode($before) . ', after ' . json_encode($after) . '); stopping.');
        }
    }

    private function assertSqliteIntegrity(string $when): void
    {
        $violations = DB::select('PRAGMA foreign_key_check');
        if ($violations !== []) {
            throw new RuntimeException("SQLite reports " . count($violations) . " broken references {$when} the payments change; nothing more was changed.");
        }
    }
};
