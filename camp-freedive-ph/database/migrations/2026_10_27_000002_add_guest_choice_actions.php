<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Batch 3-II B2.3/B2.4: acting on a verified guest choice.
 *
 *  - payment_exception_choices gains two terminal states:
 *      acted_on       verified -> acted_on only, in the same transaction as the action; acted_by/acted_at and the action
 *                     reference (resolution_reference: "refund_review:<id>" or "allocation:<booking id>") are written
 *                     once. Nothing can change it afterwards, and no new choice can be recorded for the exception
 *                     once one was acted on (P6/B2.3 carry-forward).
 *      action_failed  verified -> action_failed when the chosen date could not be allocated: the consent is spent;
 *                     a new date needs a new choice and a new independent verification (P6). Kept as history.
 *  - payment_exception_refund_reviews: the owner-review item for a verified refund choice (P5). Linked to the
 *    exception, its receipt and provider identity, the checkout intent or booking, the choice, and any booking refund
 *    case already open (never a second one). The exception stays open and its money held; B3 decides amounts.
 *    Immutable here; B3 adds its own transitions.
 *  - payment_exception_allocation_attempts: append-only record of every allocation outcome (allocated, failed, blocked).
 *  - payment_exceptions: the B1 guard is replaced by a narrow allowlist: open -> resolved, resolution "allocated",
 *    only when the exception's choice is acted_on with an allocation reference, by an active owner or admin. Every
 *    other change, and deleting, stays refused.
 *
 * SQLite note: acted_by is a plain column there (a foreign key would make Laravel rebuild the table, which drops its
 * triggers and partial index); the trigger requires it to be an active owner or admin. PostgreSQL has the foreign key.
 * Rollback is refused once any action, review, attempt or resolution exists.
 */
return new class extends Migration
{
    private const CHOICES = "'refund_review', 'alternative_date'";
    private const CHANNELS = "'email', 'phone', 'message', 'in_person'";
    private const MATCH_METHODS = "'email_address', 'phone_number', 'booking_reference', 'in_person'";
    private const SUPPORTED_KINDS = "'intent_unallocated', 'legacy_unallocated'";

    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            Schema::table('payment_exception_choices', function (Blueprint $table) {
                $table->foreignId('acted_by')->nullable()->constrained('users')->restrictOnDelete();
                $table->timestamp('acted_at')->nullable();
            });
        } else {
            DB::statement('ALTER TABLE payment_exception_choices ADD COLUMN acted_by INTEGER NULL');
            DB::statement('ALTER TABLE payment_exception_choices ADD COLUMN acted_at DATETIME NULL');
        }

        Schema::create('payment_exception_refund_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_exception_id')->unique()->constrained('payment_exceptions')->restrictOnDelete();
            $table->foreignId('choice_id')->unique()->constrained('payment_exception_choices')->restrictOnDelete();
            $table->foreignId('primary_payment_id')->constrained('payments')->restrictOnDelete();
            $table->foreignId('provider_payment_identity_id')->nullable()->constrained('provider_payment_identities')->restrictOnDelete();
            $table->foreignId('checkout_intent_id')->nullable()->constrained('checkout_intents')->restrictOnDelete();
            $table->foreignId('booking_id')->nullable()->constrained('bookings')->restrictOnDelete();
            $table->foreignId('existing_booking_refund_id')->nullable()->constrained('booking_refunds')->restrictOnDelete();
            $table->bigInteger('received_centavos');
            $table->string('status', 32)->default('awaiting_owner_review'); // B3 adds its own states
            $table->foreignId('routed_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('routed_at');
            $table->timestamps();
            $table->index('status');
        });

        Schema::create('payment_exception_allocation_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_exception_id')->constrained('payment_exceptions')->restrictOnDelete();
            $table->foreignId('choice_id')->constrained('payment_exception_choices')->restrictOnDelete();
            $table->string('outcome', 16);                       // allocated | failed | blocked
            $table->string('reason', 48)->nullable();            // capacity_full, instructor_unverified, price_unverified, ...
            $table->foreignId('booking_id')->nullable()->constrained('bookings')->restrictOnDelete();
            $table->date('requested_start_date');
            $table->date('requested_end_date');
            $table->json('details')->nullable();                 // ids and counts only, never evidence text
            $table->foreignId('attempted_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('attempted_at');
            $table->index(['payment_exception_id', 'id']);
        });

        $this->installChoiceRules(true);
        $this->installExceptionGuard(true);
        $this->installAppendOnly();
    }

    public function down(): void
    {
        $inUse = DB::table('payment_exception_choices')->whereIn('status', ['acted_on', 'action_failed'])->exists()
            || DB::table('payment_exception_refund_reviews')->exists() || DB::table('payment_exception_allocation_attempts')->exists()
            || DB::table('payment_exceptions')->where('status', '!=', 'open')->exists();
        if ($inUse) {
            throw new RuntimeException('Guest-choice actions, refund reviews, allocation attempts or resolved exceptions are recorded. Rolling back would discard them; nothing was changed.');
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS payment_exception_refund_reviews_immutable ON payment_exception_refund_reviews;'
                . ' DROP TRIGGER IF EXISTS payment_exception_allocation_attempts_immutable ON payment_exception_allocation_attempts;');
        }
        Schema::drop('payment_exception_allocation_attempts');
        Schema::drop('payment_exception_refund_reviews');
        $this->installChoiceRules(false);
        $this->installExceptionGuard(false);
        if (DB::getDriverName() === 'pgsql') {
            Schema::table('payment_exception_choices', fn (Blueprint $table) => $table->dropConstrainedForeignId('acted_by'));
            Schema::table('payment_exception_choices', fn (Blueprint $table) => $table->dropColumn('acted_at'));
        } else {
            DB::statement('ALTER TABLE payment_exception_choices DROP COLUMN acted_by');
            DB::statement('ALTER TABLE payment_exception_choices DROP COLUMN acted_at');
        }
    }

    /** The B2.1 rules ($actions false) or the B2.3/B2.4 rules ($actions true), on both drivers. */
    private function installChoiceRules(bool $actions): void
    {
        $statuses = "'recorded', 'verified', 'rejected', 'withdrawn', 'superseded'" . ($actions ? ", 'acted_on', 'action_failed'" : '');
        $staff = fn (string $column) => "EXISTS (SELECT 1 FROM users u WHERE u.id = NEW.{$column} AND u.status = 'active' AND u.role IN ('owner', 'admin'))";
        $verifiedFacts = '{c}verified_by IS NOT NULL AND {c}verified_at IS NOT NULL AND {c}contact_match_outcome = \'matched\'';
        $closedFacts = "{c}closed_by IS NOT NULL AND {c}closed_at IS NOT NULL AND length(trim(coalesce({c}closed_reason, ''))) > 0";
        $noAction = $actions ? ' AND {c}acted_by IS NULL AND {c}acted_at IS NULL' : '';

        $row = "{c}choice IN (" . self::CHOICES . ") AND {c}status IN ({$statuses})
            AND {c}evidence_channel IN (" . self::CHANNELS . ") AND {c}contact_match_method IN (" . self::MATCH_METHODS . ")
            AND {c}contact_match_outcome IN ('matched', 'not_matched')
            AND length(trim(coalesce({c}evidence_summary, ''))) BETWEEN 10 AND 500
            AND ({c}evidence_at IS NOT NULL OR length(trim(coalesce({c}evidence_reference, ''))) > 0)
            AND (({c}choice = 'alternative_date' AND {c}requested_start_date IS NOT NULL AND {c}requested_end_date IS NOT NULL
                    AND {c}requested_end_date > {c}requested_start_date)
                OR ({c}choice = 'refund_review' AND {c}requested_start_date IS NULL AND {c}requested_end_date IS NULL))
            AND {c}received_centavos >= 0
            AND ({c}verified_by IS NULL OR {c}verified_by <> {c}recorded_by)
            AND ({c}status <> 'recorded' OR ({c}verified_by IS NULL AND {c}verified_at IS NULL AND {c}closed_by IS NULL AND {c}closed_at IS NULL{$noAction}))
            AND ({c}status <> 'verified' OR ({$verifiedFacts} AND {c}closed_by IS NULL{$noAction}))
            AND ({c}status NOT IN ('rejected', 'withdrawn', 'superseded') OR ({$closedFacts}{$noAction}))
            AND ({c}status NOT IN ('rejected', 'withdrawn') OR {c}verified_by IS NULL)";
        $row .= $actions
            ? " AND ({c}status <> 'acted_on' OR ({$verifiedFacts} AND {c}closed_by IS NULL AND {c}acted_by IS NOT NULL AND {c}acted_at IS NOT NULL
                    AND length(trim(coalesce({c}resolution_reference, ''))) > 0))
                AND ({c}status <> 'action_failed' OR ({$verifiedFacts} AND {$closedFacts}{$noAction}))
                AND ({c}status = 'acted_on' OR {c}resolution_reference IS NULL)"
            : ' AND {c}resolution_reference IS NULL';

        $insert = "NEW.status = 'recorded' AND NEW.lock_version = 0 AND NEW.verified_by IS NULL AND NEW.closed_by IS NULL
            AND EXISTS (SELECT 1 FROM payment_exceptions e WHERE e.id = NEW.payment_exception_id AND e.status = 'open' AND e.kind IN (" . self::SUPPORTED_KINDS . "))
            AND {$staff('recorded_by')}
            AND (NEW.supersedes_choice_id IS NULL OR EXISTS (SELECT 1 FROM payment_exception_choices p WHERE p.id = NEW.supersedes_choice_id
                AND p.payment_exception_id = NEW.payment_exception_id AND p.status = 'superseded'))"
            . ($actions ? " AND NOT EXISTS (SELECT 1 FROM payment_exception_choices a WHERE a.payment_exception_id = NEW.payment_exception_id AND a.status = 'acted_on')" : '');

        $immutable = ['payment_exception_id', 'choice', 'requested_start_date', 'requested_end_date', 'original_total_centavos',
            'original_downpayment_centavos', 'received_centavos', 'price_snapshot_source', 'price_snapshot_source_id', 'evidence_channel',
            'evidence_at', 'evidence_reference', 'evidence_summary', 'contact_match_method', 'contact_match_outcome', 'recorded_by',
            'recorded_at', 'supersedes_choice_id', 'created_at'];
        $writeOnce = ['verified_by', 'verified_at', 'verification_note', 'closed_by', 'closed_at', 'closed_reason']
            + ($actions ? [6 => 'acted_by', 7 => 'acted_at', 8 => 'resolution_reference'] : []);
        $openException = "EXISTS (SELECT 1 FROM payment_exceptions e WHERE e.id = {n}payment_exception_id AND e.status = 'open')";
        $update = implode(' AND ', array_map(fn ($c) => "{n}{$c} IS NOT DISTINCT FROM {o}{$c}", $immutable))
            . ' AND ' . implode(' AND ', array_map(fn ($c) => "({o}{$c} IS NULL OR {n}{$c} IS NOT DISTINCT FROM {o}{$c})", $writeOnce))
            . " AND {n}lock_version = {o}lock_version + 1
            AND (({o}status = 'recorded' AND {n}status IN ('verified', 'rejected', 'withdrawn', 'superseded'))
                OR ({o}status = 'verified' AND {n}status IN ('superseded'" . ($actions ? ", 'acted_on', 'action_failed'" : '') . ")))
            AND ({n}status <> 'verified' OR ({$staff('verified_by')} AND {$openException}))"
            . ($actions ? " AND ({n}status <> 'acted_on' OR ({$staff('acted_by')} AND {$openException}))" : '');

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE payment_exception_choices DROP CONSTRAINT IF EXISTS payment_exception_choices_valid');
            DB::statement('ALTER TABLE payment_exception_choices ADD CONSTRAINT payment_exception_choices_valid CHECK (' . strtr($row, ['{c}' => '']) . ')');
            $ins = strtr($insert, ['{n}' => 'NEW.', '{o}' => 'OLD.']);
            $upd = strtr($update, ['{n}' => 'NEW.', '{o}' => 'OLD.']);
            DB::unprepared(<<<SQL
                CREATE OR REPLACE FUNCTION cfp_guard_payment_exception_choice() RETURNS trigger LANGUAGE plpgsql AS \$\$
                BEGIN
                    IF TG_OP = 'DELETE' THEN
                        RAISE EXCEPTION 'Guest choices and their evidence are permanent.';
                    END IF;
                    IF TG_OP = 'INSERT' THEN
                        IF NOT ({$ins}) THEN
                            RAISE EXCEPTION 'A guest choice can only be recorded, by an active owner or admin, for an open supported payment exception that has not been acted on.';
                        END IF;
                        RETURN NEW;
                    END IF;
                    IF NOT ({$upd}) THEN
                        RAISE EXCEPTION 'This guest choice change is not allowed (immutable evidence, invalid transition, stale version or self-verification).';
                    END IF;
                    RETURN NEW;
                END \$\$;
                SQL);
        } else {
            $sqlite = fn (string $sql) => strtr(str_replace('IS NOT DISTINCT FROM', 'IS', $sql), ['{c}' => 'NEW.', '{n}' => 'NEW.', '{o}' => 'OLD.']);
            $rowNew = $sqlite($row);
            DB::unprepared('DROP TRIGGER IF EXISTS payment_exception_choices_valid_insert; DROP TRIGGER IF EXISTS payment_exception_choices_guard_update;');
            DB::unprepared(<<<SQL
                CREATE TRIGGER payment_exception_choices_valid_insert BEFORE INSERT ON payment_exception_choices
                WHEN NOT ({$rowNew}) OR NOT ({$sqlite($insert)})
                BEGIN SELECT RAISE(ABORT, 'A guest choice can only be recorded, with complete evidence, by an active owner or admin, for an open supported payment exception that has not been acted on.'); END;
                CREATE TRIGGER payment_exception_choices_guard_update BEFORE UPDATE ON payment_exception_choices
                WHEN NOT ({$rowNew}) OR NOT ({$sqlite($update)})
                BEGIN SELECT RAISE(ABORT, 'This guest choice change is not allowed (immutable evidence, invalid transition, stale version or self-verification).'); END;
                SQL);
        }
    }

    /**
     * B1 guard ($allowAllocation false: nothing about an exception may change) or the allocation allowlist: open ->
     * resolved with resolution "allocated" by an active owner or admin, once its choice is acted_on with the reference
     * "allocation:{booking}" of an allocated attempt whose booking is the exception's own (legacy) or was created from
     * its checkout intent; lock_version may only go up by one. Everything else is unchanged and deleting stays refused.
     * (The checkout receipt is attached after resolution, in the same transaction: ReceiptRecorder::attach refuses a
     * receipt while its exception is open, and the service rolls everything back if the attach is refused.)
     */
    private function installExceptionGuard(bool $allowAllocation): void
    {
        $facts = ['kind', 'primary_payment_id', 'checkout_intent_id', 'booking_id', 'amount_centavos', 'currency', 'provider_payment_id', 'opened_at'];
        $frozen = implode(' OR ', array_map(fn ($c) => "{n}{$c} IS DISTINCT FROM {o}{$c}", $facts));
        $resolution = "{n}status IS DISTINCT FROM {o}status OR {n}resolved_at IS DISTINCT FROM {o}resolved_at OR {n}resolution IS DISTINCT FROM {o}resolution OR {n}resolved_by IS DISTINCT FROM {o}resolved_by";
        $allowed = "{o}status = 'open' AND {n}status = 'resolved' AND {n}resolution = 'allocated' AND {n}resolved_at IS NOT NULL
            AND {n}lock_version = {o}lock_version + 1
            AND EXISTS (SELECT 1 FROM users u WHERE u.id = {n}resolved_by AND u.status = 'active' AND u.role IN ('owner', 'admin'))
            AND EXISTS (SELECT 1 FROM payment_exception_choices c JOIN payment_exception_allocation_attempts a
                    ON a.choice_id = c.id AND a.payment_exception_id = c.payment_exception_id AND a.outcome = 'allocated'
                WHERE c.payment_exception_id = {n}id AND c.status = 'acted_on' AND c.resolution_reference = 'allocation:' || a.booking_id
                AND (a.booking_id = {n}booking_id OR ({n}booking_id IS NULL
                    AND EXISTS (SELECT 1 FROM bookings b WHERE b.id = a.booking_id AND b.checkout_intent_id = {n}checkout_intent_id))))";
        // Refused when a fact changes, or the resolution changes other than by the allowed transition
        $refuse = $allowAllocation ? "({$frozen}) OR (({$resolution}) AND NOT ({$allowed}))" : "({$frozen}) OR ({$resolution})";

        if (DB::getDriverName() === 'pgsql') {
            $pg = strtr($refuse, ['{n}' => 'NEW.', '{o}' => 'OLD.']);
            DB::unprepared(<<<SQL
                CREATE OR REPLACE FUNCTION cfp_guard_payment_exception() RETURNS trigger LANGUAGE plpgsql AS \$\$
                BEGIN
                    IF TG_OP = 'DELETE' THEN
                        RAISE EXCEPTION 'Payment exceptions are permanent.';
                    END IF;
                    IF {$pg} THEN
                        RAISE EXCEPTION 'A payment exception can only be resolved through the approved workflow.';
                    END IF;
                    RETURN NEW;
                END \$\$;
                SQL);
        } else {
            $lite = strtr(str_replace(['IS DISTINCT FROM'], ['IS NOT'], $refuse), ['{n}' => 'NEW.', '{o}' => 'OLD.']);
            DB::unprepared(<<<SQL
                DROP TRIGGER IF EXISTS payment_exceptions_guard;
                CREATE TRIGGER payment_exceptions_guard BEFORE UPDATE ON payment_exceptions
                WHEN {$lite}
                BEGIN SELECT RAISE(ABORT, 'A payment exception can only be resolved through the approved workflow.'); END;
                SQL);
        }
    }

    /** Refund reviews and allocation attempts: no update, no delete (B3 changes the review rules). */
    private function installAppendOnly(): void
    {
        $reviewInsert = "EXISTS (SELECT 1 FROM payment_exceptions e JOIN payment_exception_choices c ON c.payment_exception_id = e.id
                WHERE e.id = NEW.payment_exception_id AND e.status = 'open' AND c.id = NEW.choice_id AND c.choice = 'refund_review' AND c.status = 'verified'
                AND e.primary_payment_id = NEW.primary_payment_id
                AND (e.checkout_intent_id IS NOT DISTINCT FROM NEW.checkout_intent_id) AND (e.booking_id IS NOT DISTINCT FROM NEW.booking_id))
            AND NEW.status = 'awaiting_owner_review'
            AND EXISTS (SELECT 1 FROM users u WHERE u.id = NEW.routed_by AND u.status = 'active' AND u.role IN ('owner', 'admin'))";
        $attemptInsert = "NEW.outcome IN ('allocated', 'failed', 'blocked') AND (NEW.outcome <> 'allocated' OR NEW.booking_id IS NOT NULL)
            AND (NEW.outcome = 'allocated' OR length(trim(coalesce(NEW.reason, ''))) > 0)
            AND EXISTS (SELECT 1 FROM payment_exception_choices c JOIN payment_exceptions e ON e.id = c.payment_exception_id
                WHERE c.id = NEW.choice_id AND c.payment_exception_id = NEW.payment_exception_id AND c.choice = 'alternative_date' AND e.status = 'open'
                AND ((NEW.outcome <> 'allocated' AND c.status = 'verified')
                    OR (NEW.outcome = 'allocated' AND c.status = 'acted_on' AND c.resolution_reference = 'allocation:' || NEW.booking_id
                        AND (NEW.booking_id = e.booking_id OR (e.booking_id IS NULL
                            AND EXISTS (SELECT 1 FROM bookings b WHERE b.id = NEW.booking_id AND b.checkout_intent_id = e.checkout_intent_id))))))";

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<SQL
                CREATE OR REPLACE FUNCTION cfp_guard_refund_review() RETURNS trigger LANGUAGE plpgsql AS \$\$
                BEGIN
                    IF TG_OP <> 'INSERT' THEN
                        RAISE EXCEPTION 'Refund review items are kept as routed (B3 decides them).';
                    END IF;
                    IF NOT ({$reviewInsert}) THEN
                        RAISE EXCEPTION 'A refund review can only be opened for an open exception from its verified refund choice, by an active owner or admin.';
                    END IF;
                    RETURN NEW;
                END \$\$;
                CREATE TRIGGER payment_exception_refund_reviews_immutable BEFORE INSERT OR UPDATE OR DELETE ON payment_exception_refund_reviews
                    FOR EACH ROW EXECUTE FUNCTION cfp_guard_refund_review();
                CREATE OR REPLACE FUNCTION cfp_guard_allocation_attempt() RETURNS trigger LANGUAGE plpgsql AS \$\$
                BEGIN
                    IF TG_OP <> 'INSERT' THEN
                        RAISE EXCEPTION 'Allocation attempts are append-only.';
                    END IF;
                    IF NOT ({$attemptInsert}) THEN
                        RAISE EXCEPTION 'Invalid allocation attempt record.';
                    END IF;
                    RETURN NEW;
                END \$\$;
                CREATE TRIGGER payment_exception_allocation_attempts_immutable BEFORE INSERT OR UPDATE OR DELETE ON payment_exception_allocation_attempts
                    FOR EACH ROW EXECUTE FUNCTION cfp_guard_allocation_attempt();
                SQL);
        } else {
            $review = str_replace('IS NOT DISTINCT FROM', 'IS', $reviewInsert);
            DB::unprepared(<<<SQL
                CREATE TRIGGER payment_exception_refund_reviews_valid_insert BEFORE INSERT ON payment_exception_refund_reviews
                WHEN NOT ({$review})
                BEGIN SELECT RAISE(ABORT, 'A refund review can only be opened for an open exception from its verified refund choice, by an active owner or admin.'); END;
                CREATE TRIGGER payment_exception_refund_reviews_no_update BEFORE UPDATE ON payment_exception_refund_reviews
                BEGIN SELECT RAISE(ABORT, 'Refund review items are kept as routed (B3 decides them).'); END;
                CREATE TRIGGER payment_exception_refund_reviews_no_delete BEFORE DELETE ON payment_exception_refund_reviews
                BEGIN SELECT RAISE(ABORT, 'Refund review items are kept as routed (B3 decides them).'); END;
                CREATE TRIGGER payment_exception_allocation_attempts_valid_insert BEFORE INSERT ON payment_exception_allocation_attempts
                WHEN NOT ({$attemptInsert})
                BEGIN SELECT RAISE(ABORT, 'Invalid allocation attempt record.'); END;
                CREATE TRIGGER payment_exception_allocation_attempts_no_update BEFORE UPDATE ON payment_exception_allocation_attempts
                BEGIN SELECT RAISE(ABORT, 'Allocation attempts are append-only.'); END;
                CREATE TRIGGER payment_exception_allocation_attempts_no_delete BEFORE DELETE ON payment_exception_allocation_attempts
                BEGIN SELECT RAISE(ABORT, 'Allocation attempts are append-only.'); END;
                SQL);
        }
    }
};
