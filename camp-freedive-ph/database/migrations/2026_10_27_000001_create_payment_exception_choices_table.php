<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Batch 3-II B2.1: staff-recorded guest choices for a payment exception, with independent verification.
 *
 * A guest whose verified payment got no place chooses, explicitly, either refund review or an alternative date. Staff
 * record the choice with text evidence (channel, timestamp and/or reference, a short summary, how the guest's contact
 * was matched); a different active owner or admin verifies it. Nothing here allocates a place, opens a refund case,
 * moves money or resolves the exception: the exception stays open, and the choice only records consent and its
 * verification for later stages.
 *
 * Integrity (database level, both drivers; PostgreSQL CHECK constraints and triggers, SQLite triggers):
 *  - valid choice, status, channel and contact-match values; evidence summary 10-500 characters; a timestamp or a
 *    reference; alternative date ranges valid (end after start), refund choices carry no dates;
 *  - the verifier is never the recorder, and both are active owners or admins when they act;
 *  - only exceptions that are open and of a supported kind (intent_unallocated, legacy_unallocated) get choices;
 *  - at most one actionable choice (recorded or verified) per exception: a partial unique index (both drivers);
 *  - transitions only recorded -> verified | rejected | withdrawn | superseded, and verified -> superseded; every
 *    change bumps lock_version by exactly one (stale writes fail); recorded facts, evidence and the price snapshot
 *    never change; verification and closing facts are written once; nothing is ever deleted;
 *  - resolution_reference stays empty in B2.1 (a later stage records what was done with a verified choice).
 * The price snapshot (quoted total, downpayment, received amount, source) is copied at recording and never changes;
 * nothing reprices.
 */
return new class extends Migration
{
    private const CHOICES = "'refund_review', 'alternative_date'";
    private const STATUSES = "'recorded', 'verified', 'rejected', 'withdrawn', 'superseded'";
    private const CHANNELS = "'email', 'phone', 'message', 'in_person'";
    private const MATCH_METHODS = "'email_address', 'phone_number', 'booking_reference', 'in_person'";
    private const SUPPORTED_KINDS = "'intent_unallocated', 'legacy_unallocated'";

    public function up(): void
    {
        Schema::create('payment_exception_choices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_exception_id')->constrained('payment_exceptions')->restrictOnDelete();
            $table->string('choice', 24);                         // refund_review | alternative_date
            $table->string('status', 16)->default('recorded');    // recorded | verified | rejected | withdrawn | superseded
            $table->date('requested_start_date')->nullable();     // alternative_date only
            $table->date('requested_end_date')->nullable();
            // Price snapshot at recording (P1: the original quote and terms are kept, never repriced)
            $table->bigInteger('original_total_centavos')->nullable();
            $table->bigInteger('original_downpayment_centavos')->nullable();
            $table->bigInteger('received_centavos');
            $table->string('price_snapshot_source', 24)->nullable();   // booking | checkout_intent
            $table->unsignedBigInteger('price_snapshot_source_id')->nullable();
            // Evidence (text only, no attachments: P4)
            $table->string('evidence_channel', 16);               // email | phone | message | in_person
            $table->timestamp('evidence_at')->nullable();         // when the guest communicated the choice
            $table->string('evidence_reference', 191)->nullable(); // message id, call log reference, ...
            $table->string('evidence_summary', 500);
            $table->string('contact_match_method', 24);           // email_address | phone_number | booking_reference | in_person
            $table->string('contact_match_outcome', 16);          // matched | not_matched
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('recorded_at');
            $table->foreignId('verified_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->string('verification_note', 500)->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->restrictOnDelete(); // rejected / withdrawn / superseded
            $table->timestamp('closed_at')->nullable();
            $table->string('closed_reason', 500)->nullable();
            $table->foreignId('supersedes_choice_id')->nullable()->unique()->constrained('payment_exception_choices')->restrictOnDelete();
            $table->string('resolution_reference', 191)->nullable(); // reserved for a later stage; must stay empty in B2.1
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps();
            $table->index(['payment_exception_id', 'id']);
        });

        // At most one actionable choice per exception (partial unique index: PostgreSQL and SQLite alike)
        DB::statement("CREATE UNIQUE INDEX payment_exception_choices_one_actionable ON payment_exception_choices (payment_exception_id) WHERE status IN ('recorded', 'verified')");

        $row = $this->rowRules();
        $activeStaff = fn (string $column) => "EXISTS (SELECT 1 FROM users u WHERE u.id = NEW.{$column} AND u.status = 'active' AND u.role IN ('owner', 'admin'))";
        $insertRules = "NEW.status = 'recorded' AND NEW.lock_version = 0 AND NEW.verified_by IS NULL AND NEW.closed_by IS NULL
            AND EXISTS (SELECT 1 FROM payment_exceptions e WHERE e.id = NEW.payment_exception_id AND e.status = 'open' AND e.kind IN (" . self::SUPPORTED_KINDS . "))
            AND {$activeStaff('recorded_by')}
            AND (NEW.supersedes_choice_id IS NULL OR EXISTS (SELECT 1 FROM payment_exception_choices p WHERE p.id = NEW.supersedes_choice_id
                AND p.payment_exception_id = NEW.payment_exception_id AND p.status = 'superseded'))";

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE payment_exception_choices ADD CONSTRAINT payment_exception_choices_valid CHECK ({$this->pg($row, '')})");
            $insert = $this->pg($insertRules, 'NEW.');
            $update = $this->pg($this->updateRules($activeStaff('verified_by')), 'NEW.');
            DB::unprepared(<<<SQL
                CREATE OR REPLACE FUNCTION cfp_guard_payment_exception_choice() RETURNS trigger LANGUAGE plpgsql AS \$\$
                BEGIN
                    IF TG_OP = 'DELETE' THEN
                        RAISE EXCEPTION 'Guest choices and their evidence are permanent.';
                    END IF;
                    IF TG_OP = 'INSERT' THEN
                        IF NOT ({$insert}) THEN
                            RAISE EXCEPTION 'A guest choice can only be recorded, by an active owner or admin, for an open supported payment exception.';
                        END IF;
                        RETURN NEW;
                    END IF;
                    IF NOT ({$update}) THEN
                        RAISE EXCEPTION 'This guest choice change is not allowed (immutable evidence, invalid transition, stale version or self-verification).';
                    END IF;
                    RETURN NEW;
                END \$\$;
                CREATE TRIGGER payment_exception_choices_guard BEFORE INSERT OR UPDATE OR DELETE ON payment_exception_choices
                    FOR EACH ROW EXECUTE FUNCTION cfp_guard_payment_exception_choice();
                SQL);
        } else {
            $rowNew = $this->sqlite($row);
            $update = $this->sqlite($this->updateRules($activeStaff('verified_by')));
            $insert = $this->sqlite($insertRules);
            DB::unprepared(<<<SQL
                CREATE TRIGGER payment_exception_choices_valid_insert BEFORE INSERT ON payment_exception_choices
                WHEN NOT ({$rowNew}) OR NOT ({$insert})
                BEGIN SELECT RAISE(ABORT, 'A guest choice can only be recorded, with complete evidence, by an active owner or admin, for an open supported payment exception.'); END;
                CREATE TRIGGER payment_exception_choices_guard_update BEFORE UPDATE ON payment_exception_choices
                WHEN NOT ({$rowNew}) OR NOT ({$update})
                BEGIN SELECT RAISE(ABORT, 'This guest choice change is not allowed (immutable evidence, invalid transition, stale version or self-verification).'); END;
                CREATE TRIGGER payment_exception_choices_no_delete BEFORE DELETE ON payment_exception_choices
                BEGIN SELECT RAISE(ABORT, 'Guest choices and their evidence are permanent.'); END;
                SQL);
        }
    }

    /** Refused once any choice exists: recorded consent and evidence are never discarded. */
    public function down(): void
    {
        if (DB::table('payment_exception_choices')->exists()) {
            throw new RuntimeException('Guest choices are recorded. Rolling back would discard recorded consent and evidence; nothing was changed.');
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS payment_exception_choices_guard ON payment_exception_choices; DROP FUNCTION IF EXISTS cfp_guard_payment_exception_choice();');
        }
        Schema::drop('payment_exception_choices');
    }

    /** Rules every row must satisfy (columns written as {c}name, prefixed per driver). Null-safe: no rule passes on NULL. */
    private function rowRules(): string
    {
        return "{c}choice IN (" . self::CHOICES . ") AND {c}status IN (" . self::STATUSES . ")
            AND {c}evidence_channel IN (" . self::CHANNELS . ") AND {c}contact_match_method IN (" . self::MATCH_METHODS . ")
            AND {c}contact_match_outcome IN ('matched', 'not_matched')
            AND length(trim(coalesce({c}evidence_summary, ''))) BETWEEN 10 AND 500
            AND ({c}evidence_at IS NOT NULL OR length(trim(coalesce({c}evidence_reference, ''))) > 0)
            AND (({c}choice = 'alternative_date' AND {c}requested_start_date IS NOT NULL AND {c}requested_end_date IS NOT NULL
                    AND {c}requested_end_date > {c}requested_start_date)
                OR ({c}choice = 'refund_review' AND {c}requested_start_date IS NULL AND {c}requested_end_date IS NULL))
            AND {c}received_centavos >= 0
            AND ({c}verified_by IS NULL OR {c}verified_by <> {c}recorded_by)
            AND ({c}status <> 'recorded' OR ({c}verified_by IS NULL AND {c}verified_at IS NULL AND {c}closed_by IS NULL AND {c}closed_at IS NULL))
            AND ({c}status <> 'verified' OR ({c}verified_by IS NOT NULL AND {c}verified_at IS NOT NULL AND {c}contact_match_outcome = 'matched'
                AND {c}closed_by IS NULL))
            AND ({c}status NOT IN ('rejected', 'withdrawn', 'superseded') OR ({c}closed_by IS NOT NULL AND {c}closed_at IS NOT NULL
                AND length(trim(coalesce({c}closed_reason, ''))) > 0))
            AND ({c}status NOT IN ('rejected', 'withdrawn') OR {c}verified_by IS NULL)
            AND {c}resolution_reference IS NULL";
    }

    /** Update rules ({o} = old row, {n} = new row): allowed transitions, immutable facts, version bump. */
    private function updateRules(string $verifierActive): string
    {
        $immutable = ['payment_exception_id', 'choice', 'requested_start_date', 'requested_end_date', 'original_total_centavos',
            'original_downpayment_centavos', 'received_centavos', 'price_snapshot_source', 'price_snapshot_source_id', 'evidence_channel',
            'evidence_at', 'evidence_reference', 'evidence_summary', 'contact_match_method', 'contact_match_outcome', 'recorded_by',
            'recorded_at', 'supersedes_choice_id', 'created_at'];
        $unchanged = implode(' AND ', array_map(fn ($c) => "{n}{$c} IS NOT DISTINCT FROM {o}{$c}", $immutable));
        $writeOnce = implode(' AND ', array_map(fn ($c) => "({o}{$c} IS NULL OR {n}{$c} IS NOT DISTINCT FROM {o}{$c})",
            ['verified_by', 'verified_at', 'verification_note', 'closed_by', 'closed_at', 'closed_reason']));

        return "{$unchanged} AND {$writeOnce} AND {n}lock_version = {o}lock_version + 1
            AND (({o}status = 'recorded' AND {n}status IN ('verified', 'rejected', 'withdrawn', 'superseded'))
                OR ({o}status = 'verified' AND {n}status = 'superseded'))
            AND ({n}status <> 'verified' OR ({$verifierActive}
                AND EXISTS (SELECT 1 FROM payment_exceptions e WHERE e.id = {n}payment_exception_id AND e.status = 'open')))";
    }

    private function pg(string $sql, string $new): string
    {
        return strtr($sql, ['{c}' => $new, '{n}' => 'NEW.', '{o}' => 'OLD.']);
    }

    /** SQLite: IS / IS NOT are its null-safe comparisons. */
    private function sqlite(string $sql): string
    {
        return strtr(str_replace(['IS NOT DISTINCT FROM'], ['IS'], $sql), ['{c}' => 'NEW.', '{n}' => 'NEW.', '{o}' => 'OLD.']);
    }
};
