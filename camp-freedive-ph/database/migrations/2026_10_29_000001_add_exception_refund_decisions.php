<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * B3: owner/admin decisions on exception refund reviews. Additive: new nullable columns, and the B2 "no update" rule on
 * payment_exception_refund_reviews narrowed to an allowlist of exactly two transitions, each checked by the database:
 *
 *  - awaiting_owner_review -> approved_awaiting_payout: an active owner or admin (role recorded), an amount above 0 and
 *    at most what was received, in the exception's own (verified) currency, while the exception is still open. Approval
 *    moves no money and resolves nothing; payout and a "refunded" state come with provider execution (not here).
 *  - awaiting_owner_review -> rejected_invalid: an active owner or admin with a written reason (10+ characters), for an
 *    invalid or duplicate request only; the exception and its receipt stay held (no forfeiture).
 *
 * Every routed fact (exception, choice, receipt, identity, checkout, booking, linked case, amount received, who routed)
 * stays frozen; lock_version goes up by exactly one per decision; deleting stays refused. Rollback is refused once any
 * decision exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            Schema::table('payment_exception_refund_reviews', function (Blueprint $table) {
                $table->bigInteger('approved_centavos')->nullable();
                $table->char('approved_currency', 3)->nullable();
                $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
                $table->string('approver_role', 16)->nullable();
                $table->timestamp('approved_at')->nullable();
                $table->text('approval_note')->nullable();
                $table->foreignId('rejected_by')->nullable()->constrained('users')->restrictOnDelete();
                $table->timestamp('rejected_at')->nullable();
                $table->text('rejection_reason')->nullable();
                $table->unsignedInteger('lock_version')->default(0);
            });
        } else {
            // Plain ALTER TABLE: a Laravel table rebuild on SQLite would drop the table's triggers
            foreach (['approved_centavos INTEGER NULL', 'approved_currency VARCHAR(3) NULL', 'approved_by INTEGER NULL', 'approver_role VARCHAR(16) NULL',
                'approved_at DATETIME NULL', 'approval_note TEXT NULL', 'rejected_by INTEGER NULL', 'rejected_at DATETIME NULL', 'rejection_reason TEXT NULL',
                'lock_version INTEGER NOT NULL DEFAULT 0'] as $column) {
                DB::statement("ALTER TABLE payment_exception_refund_reviews ADD COLUMN {$column}");
            }
        }
        $this->installReviewRules(true);
        $this->installPaymentOwnerGuard(true);
    }

    public function down(): void
    {
        if (DB::table('payment_exception_refund_reviews')->where(fn ($q) => $q->where('status', '!=', 'awaiting_owner_review')->orWhere('lock_version', '>', 0))->exists()) {
            throw new RuntimeException('Refund review decisions are recorded. Rolling back would discard them; nothing was changed.');
        }
        $this->installPaymentOwnerGuard(false);
        $this->installReviewRules(false);
        if (DB::getDriverName() === 'pgsql') {
            Schema::table('payment_exception_refund_reviews', function (Blueprint $table) {
                $table->dropConstrainedForeignId('approved_by');
                $table->dropConstrainedForeignId('rejected_by');
                $table->dropColumn(['approved_centavos', 'approved_currency', 'approver_role', 'approved_at', 'approval_note', 'rejected_at', 'rejection_reason', 'lock_version']);
            });
        } else {
            foreach (['approved_centavos', 'approved_currency', 'approved_by', 'approver_role', 'approved_at', 'approval_note', 'rejected_by', 'rejected_at', 'rejection_reason', 'lock_version'] as $column) {
                DB::statement("ALTER TABLE payment_exception_refund_reviews DROP COLUMN {$column}");
            }
        }
    }

    /**
     * The B1 payment-owner guard ($applying false: a receipt never moves between bookings or checkouts) or the B3 one
     * ($applying true), which also states in the database what ReceiptRecorder::attach already requires: an unapplied
     * receipt is applied only to the booking created from its own checkout, and never while an open payment exception
     * holds it (e.g. a refund approved and awaiting payout).
     */
    private function installPaymentOwnerGuard(bool $applying): void
    {
        $apply = "OLD.booking_id IS NULL AND NEW.booking_id IS NOT NULL AND (
                NOT EXISTS (SELECT 1 FROM bookings b WHERE b.id = NEW.booking_id AND b.checkout_intent_id = NEW.checkout_intent_id)
                OR EXISTS (SELECT 1 FROM payment_exceptions e WHERE e.status = 'open' AND (e.primary_payment_id = NEW.id
                    OR EXISTS (SELECT 1 FROM payment_exception_receipts r WHERE r.payment_exception_id = e.id AND r.payment_id = NEW.id))))";
        if (DB::getDriverName() === 'pgsql') {
            $check = $applying ? "IF {$apply} THEN
                        RAISE EXCEPTION 'An unapplied receipt is applied only to the booking of its own checkout, and never while a payment exception holds it.';
                    END IF;" : '';
            DB::unprepared(<<<SQL
                CREATE OR REPLACE FUNCTION cfp_guard_payment_owner() RETURNS trigger LANGUAGE plpgsql AS \$\$
                BEGIN
                    IF NEW.checkout_intent_id IS DISTINCT FROM OLD.checkout_intent_id THEN
                        RAISE EXCEPTION 'A payment''s checkout intent cannot be changed.';
                    END IF;
                    IF OLD.booking_id IS NOT NULL AND NEW.booking_id IS DISTINCT FROM OLD.booking_id THEN
                        RAISE EXCEPTION 'A payment is never moved to another booking.';
                    END IF;
                    {$check}
                    RETURN NEW;
                END \$\$;
                SQL);
        } else {
            $extra = $applying ? 'OR (' . $apply . ')' : '';
            DB::unprepared(<<<SQL
                DROP TRIGGER IF EXISTS payments_guard_owner;
                CREATE TRIGGER payments_guard_owner BEFORE UPDATE OF booking_id, checkout_intent_id ON payments
                WHEN (NEW.booking_id IS NULL AND NEW.checkout_intent_id IS NULL)
                    OR NEW.checkout_intent_id IS NOT OLD.checkout_intent_id
                    OR (OLD.booking_id IS NOT NULL AND NEW.booking_id IS NOT OLD.booking_id)
                    {$extra}
                BEGIN SELECT RAISE(ABORT, 'A payment is never moved to another booking or checkout intent.'); END;
                SQL);
        }
    }

    /** B2 rules ($decisions false: insert only) or the B3 allowlist ($decisions true), on both drivers. */
    private function installReviewRules(bool $decisions): void
    {
        $staff = fn (string $column, string $extra = '') => "EXISTS (SELECT 1 FROM users u WHERE u.id = {n}{$column} AND u.status = 'active' AND u.role IN ('owner', 'admin'){$extra})";
        $insert = "EXISTS (SELECT 1 FROM payment_exceptions e JOIN payment_exception_choices c ON c.payment_exception_id = e.id
                WHERE e.id = {n}payment_exception_id AND e.status = 'open' AND c.id = {n}choice_id AND c.choice = 'refund_review' AND c.status = 'verified'
                AND e.primary_payment_id = {n}primary_payment_id
                AND (e.checkout_intent_id IS NOT DISTINCT FROM {n}checkout_intent_id) AND (e.booking_id IS NOT DISTINCT FROM {n}booking_id))
            AND {n}status = 'awaiting_owner_review' AND " . $staff('routed_by')
            . ($decisions ? " AND {n}lock_version = 0 AND {n}approved_by IS NULL AND {n}approved_centavos IS NULL AND {n}rejected_by IS NULL" : '');
        $facts = ['payment_exception_id', 'choice_id', 'primary_payment_id', 'provider_payment_identity_id', 'checkout_intent_id', 'booking_id',
            'existing_booking_refund_id', 'received_centavos', 'routed_by', 'routed_at', 'created_at'];
        $frozen = implode(' OR ', array_map(fn ($c) => "{n}{$c} IS DISTINCT FROM {o}{$c}", $facts));
        $openException = "EXISTS (SELECT 1 FROM payment_exceptions e WHERE e.id = {n}payment_exception_id AND e.status = 'open'";
        $approve = "{o}status = 'awaiting_owner_review' AND {n}status = 'approved_awaiting_payout' AND {n}lock_version = {o}lock_version + 1
            AND {n}approved_centavos > 0 AND {n}approved_centavos <= {n}received_centavos AND {n}approved_at IS NOT NULL
            AND {$openException} AND e.currency IS NOT NULL AND e.currency = {n}approved_currency)
            AND " . $staff('approved_by', " AND u.role = {n}approver_role") . "
            AND {n}rejected_by IS NULL AND {n}rejected_at IS NULL AND {n}rejection_reason IS NULL";
        $reject = "{o}status = 'awaiting_owner_review' AND {n}status = 'rejected_invalid' AND {n}lock_version = {o}lock_version + 1
            AND {n}rejected_at IS NOT NULL AND length(trim(coalesce({n}rejection_reason, ''))) >= 10 AND {$openException})
            AND " . $staff('rejected_by') . "
            AND {n}approved_by IS NULL AND {n}approved_centavos IS NULL AND {n}approved_currency IS NULL AND {n}approved_at IS NULL";
        $refuseUpdate = "({$frozen}) OR NOT (({$approve}) OR ({$reject}))";
        $insertMessage = 'A refund review can only be opened for an open exception from its verified refund choice, by an active owner or admin.';
        $updateMessage = $decisions
            ? 'A refund review can only be approved (full entitlement, by an active owner or admin, exception still open) or rejected with a reason, once.'
            : 'Refund review items are kept as routed (B3 decides them).';

        if (DB::getDriverName() === 'pgsql') {
            $pgInsert = strtr($insert, ['{n}' => 'NEW.']);
            $pgUpdate = $decisions ? strtr($refuseUpdate, ['{n}' => 'NEW.', '{o}' => 'OLD.']) : 'TRUE';
            DB::unprepared(<<<SQL
                CREATE OR REPLACE FUNCTION cfp_guard_refund_review() RETURNS trigger LANGUAGE plpgsql AS \$\$
                BEGIN
                    IF TG_OP = 'DELETE' THEN
                        RAISE EXCEPTION 'Refund review items are kept as routed (B3 decides them).';
                    END IF;
                    IF TG_OP = 'UPDATE' THEN
                        IF {$pgUpdate} THEN
                            RAISE EXCEPTION '{$updateMessage}';
                        END IF;
                        RETURN NEW;
                    END IF;
                    IF NOT ({$pgInsert}) THEN
                        RAISE EXCEPTION '{$insertMessage}';
                    END IF;
                    RETURN NEW;
                END \$\$;
                SQL);
        } else {
            $sqlite = fn (string $sql) => strtr(str_replace(['IS NOT DISTINCT FROM', 'IS DISTINCT FROM'], ['IS', 'IS NOT'], $sql), ['{n}' => 'NEW.', '{o}' => 'OLD.']);
            $updateWhen = $decisions ? 'WHEN ' . $sqlite($refuseUpdate) : '';
            $insertWhen = $sqlite($insert);
            DB::unprepared(<<<SQL
                DROP TRIGGER IF EXISTS payment_exception_refund_reviews_valid_insert;
                DROP TRIGGER IF EXISTS payment_exception_refund_reviews_no_update;
                CREATE TRIGGER payment_exception_refund_reviews_valid_insert BEFORE INSERT ON payment_exception_refund_reviews
                WHEN NOT ({$insertWhen})
                BEGIN SELECT RAISE(ABORT, '{$insertMessage}'); END;
                CREATE TRIGGER payment_exception_refund_reviews_no_update BEFORE UPDATE ON payment_exception_refund_reviews
                {$updateWhen}
                BEGIN SELECT RAISE(ABORT, '{$updateMessage}'); END;
                SQL);
        }
    }
};
