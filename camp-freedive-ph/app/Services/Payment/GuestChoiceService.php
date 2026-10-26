<?php

namespace App\Services\Payment;

use App\Models\Booking;
use App\Models\CheckoutIntent;
use App\Models\Payment;
use App\Models\PaymentException;
use App\Models\PaymentExceptionChoice;
use App\Models\PaymentExceptionEvent;
use App\Models\User;
use App\Services\AuditLogger;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;

/**
 * Staff-recorded guest choices for a payment exception, and their independent verification (Batch 3-II B2.1).
 *
 * The guest chooses explicitly (refund review, or an alternative date); staff record it with text evidence and a
 * different active owner or admin verifies it. Consent is never inferred from payment, silence or staff preference.
 * Nothing here allocates a place, opens a refund case, moves money or resolves the exception: it stays open, and a
 * verified choice is only consent on record for later stages. History is never deleted: rejected, withdrawn and
 * superseded choices keep their evidence, and every step is an exception event (ids only, no evidence text).
 *
 * Each operation runs in one transaction, locking in the established order: the acting staff row (shared, so a
 * concurrent deactivation is serialized with it) -> booking (legacy) -> checkout intent -> payment exception -> its
 * choices. The exception and choice are re-read and re-checked under those locks (eligibility, current status, the
 * lock_version the staff member saw), so stale, conflicting or duplicate decisions are refused. The database repeats
 * the essential rules (four-eyes, transitions, immutability, one actionable choice per exception).
 */
class GuestChoiceService
{
    private const NOTE_MIN = 10;
    private const NOTE_MAX = 500;

    /**
     * Data that never belongs in evidence or notes (P4, B2.2): card-like numbers, email addresses, Philippine mobile
     * and Metro Manila landline numbers, and credentials. Contact details stay on the booking; evidence refers to them
     * ("reply from the booking email") instead of copying them. Matches are refused, never logged or echoed.
     */
    private const SENSITIVE_PATTERNS = [
        'phone numbers' => '/(?<!\d)(?:\+?63|0)[\s.-]?9\d{2}[\s.-]?\d{3}[\s.-]?\d{4}(?!\d)|(?<!\d)(?:\+?63[\s.-]?|0)?\(?2\)?[\s.-]?[38]\d{3}[\s.-]?\d{4}(?!\d)/',
        'card or account numbers' => '/\d(?:[ -]?\d){11,18}/',
        'email addresses' => '/[^\s@<>()"\'\[\]]+@[^\s@<>()"\'\[\]]+\.[a-z]{2,}/i',
        'PINs, passwords or one-time codes' => '/\b(password|passcode|passwd|cvv|cvc|otp)\b|\bpin\b\s*(code)?\s*[:=#-]?\s*\d/i',
    ];

    /**
     * Record the guest's choice. Refused while another choice is actionable (supersede it instead).
     *
     * @param array{choice: string, requested_start_date?: ?string, requested_end_date?: ?string, evidence_channel: string,
     *     evidence_at?: ?string, evidence_reference?: ?string, evidence_summary: string, contact_match_method: string,
     *     contact_match_outcome: string} $input
     */
    public function record(PaymentException $exception, User $actor, array $input): PaymentExceptionChoice
    {
        $data = $this->validated($input);

        return $this->withLocks($exception, $actor, function (PaymentException $locked, User $staff) use ($data) {
            $this->assertNotActedOn($locked);
            $current = $this->actionableLocked($locked);
            if ($current !== null) {
                throw new RuntimeException("Guest choice #{$current->id} ({$current->status}) is already on record for this payment exception. "
                    . 'Record the guest\'s new choice as a replacement (supersede), or withdraw or reject the current one first; nothing was recorded.');
            }

            return $this->insert($locked, $staff, $data, null);
        });
    }

    /**
     * The guest changed their mind: close the current choice (recorded or verified) as superseded and record the new
     * one, which needs its own independent verification. The earlier record stays as evidence.
     */
    public function supersede(PaymentExceptionChoice $current, User $actor, array $input, string $reason, int $expectedVersion): PaymentExceptionChoice
    {
        $data = $this->validated($input);
        $reason = $this->note($reason, 'reason');

        return $this->withLocks($current->paymentException, $actor, function (PaymentException $locked, User $staff) use ($current, $data, $reason, $expectedVersion) {
            $old = $this->lockChoice($current, $locked, PaymentExceptionChoice::ACTIONABLE, $expectedVersion);
            $this->transition($old, PaymentExceptionChoice::SUPERSEDED, ['closed_by' => $staff->id, 'closed_at' => now(), 'closed_reason' => $reason]);
            $this->event($locked, PaymentExceptionEvent::CHOICE_SUPERSEDED, $staff, $old);

            return $this->insert($locked, $staff, $data, $old);
        });
    }

    /** Independent verification by a different active owner or admin. Not an allocation, refund approval or payout. */
    public function verify(PaymentExceptionChoice $choice, User $verifier, string $note, int $expectedVersion): PaymentExceptionChoice
    {
        $note = $this->note($note, 'verification note');

        return $this->withLocks($choice->paymentException, $verifier, function (PaymentException $locked, User $staff) use ($choice, $note, $expectedVersion) {
            $c = $this->lockChoice($choice, $locked, [PaymentExceptionChoice::RECORDED], $expectedVersion);
            $this->assertNotRecorder($c, $staff, 'verify');
            if ($c->contact_match_outcome !== PaymentExceptionChoice::MATCHED) {
                throw new RuntimeException('The guest\'s contact was not matched for this record, so it cannot be verified; nothing was changed.');
            }
            $this->transition($c, PaymentExceptionChoice::VERIFIED, ['verified_by' => $staff->id, 'verified_at' => now(), 'verification_note' => $note]);
            $this->event($locked, PaymentExceptionEvent::CHOICE_VERIFIED, $staff, $c);

            return $c;
        });
    }

    /** The independent reviewer does not accept the evidence. Kept on record; a new choice can then be recorded. */
    public function reject(PaymentExceptionChoice $choice, User $actor, string $reason, int $expectedVersion): PaymentExceptionChoice
    {
        $reason = $this->note($reason, 'reason');

        return $this->withLocks($choice->paymentException, $actor, function (PaymentException $locked, User $staff) use ($choice, $reason, $expectedVersion) {
            $c = $this->lockChoice($choice, $locked, [PaymentExceptionChoice::RECORDED], $expectedVersion);
            $this->assertNotRecorder($c, $staff, 'reject');
            $this->transition($c, PaymentExceptionChoice::REJECTED, ['closed_by' => $staff->id, 'closed_at' => now(), 'closed_reason' => $reason]);
            $this->event($locked, PaymentExceptionEvent::CHOICE_REJECTED, $staff, $c);

            return $c;
        });
    }

    /** Withdraw an unverified record (e.g. recorded on the wrong exception or by mistake). Kept on record. */
    public function withdraw(PaymentExceptionChoice $choice, User $actor, string $reason, int $expectedVersion): PaymentExceptionChoice
    {
        $reason = $this->note($reason, 'reason');

        return $this->withLocks($choice->paymentException, $actor, function (PaymentException $locked, User $staff) use ($choice, $reason, $expectedVersion) {
            $c = $this->lockChoice($choice, $locked, [PaymentExceptionChoice::RECORDED], $expectedVersion);
            $this->transition($c, PaymentExceptionChoice::WITHDRAWN, ['closed_by' => $staff->id, 'closed_at' => now(), 'closed_reason' => $reason]);
            $this->event($locked, PaymentExceptionEvent::CHOICE_WITHDRAWN, $staff, $c);

            return $c;
        });
    }

    /** The current actionable choice (recorded or verified), if any. Read-only. */
    public function current(PaymentException $exception): ?PaymentExceptionChoice
    {
        return PaymentExceptionChoice::where('payment_exception_id', $exception->id)->actionable()->first();
    }

    /** Every choice (oldest first) and every choice event of the exception. Read-only. */
    public function history(PaymentException $exception): array
    {
        return [
            'choices' => PaymentExceptionChoice::where('payment_exception_id', $exception->id)->orderBy('id')->get(),
            'events' => PaymentExceptionEvent::where('payment_exception_id', $exception->id)->where('type', 'like', 'guest_choice_%')->orderBy('id')->get(),
        ];
    }

    /** Exceptions whose withLocks callback is running in this process => the checked acting staff id. */
    private array $held = [];

    /**
     * One transaction, locks in the established order (acting staff, booking, checkout intent, exception), staff and
     * exception eligibility re-checked under them. Also used by ExceptionResolutionService for the actions on a
     * verified choice, so every guest-choice write takes the same locks in the same order. lockChoice, actOn and
     * failAction refuse to run outside this callback (for the same exception and acting staff).
     */
    public function withLocks(PaymentException $exception, User $actor, callable $work): mixed
    {
        return DB::transaction(function () use ($exception, $actor, $work) {
            $staff = User::whereKey($actor->id)->sharedLock()->first();
            if ($staff === null || $staff->status !== 'active' || ! in_array($staff->role, ['owner', 'admin'], true)) {
                throw new RuntimeException('Only an active owner or admin can record or verify guest choices; nothing was changed.');
            }

            $owner = PaymentException::whereKey($exception->id)->firstOrFail(['id', 'booking_id', 'checkout_intent_id']);
            if ($owner->booking_id !== null) {
                Booking::whereKey($owner->booking_id)->lockForUpdate()->firstOrFail();
            }
            if ($owner->checkout_intent_id !== null) {
                CheckoutIntent::whereKey($owner->checkout_intent_id)->lockForUpdate()->firstOrFail();
            }
            $locked = PaymentException::whereKey($exception->id)->lockForUpdate()->firstOrFail();
            $this->assertEligible($locked);

            $outer = $this->held[$locked->id] ?? null;
            $this->held[$locked->id] = $staff->id;
            try {
                return $work($locked, $staff);
            } finally {
                if ($outer === null) {
                    unset($this->held[$locked->id]);
                } else {
                    $this->held[$locked->id] = $outer;
                }
            }
        });
    }

    /** Open, of a supported kind, and its owner still in the state the exception describes (no place, no booking yet). */
    private function assertEligible(PaymentException $exception): void
    {
        $refuse = fn (string $why) => throw new RuntimeException("Guest choices cannot be recorded or decided for payment exception #{$exception->id}: {$why}. Nothing was changed.");
        if (! in_array($exception->status, PaymentException::UNRESOLVED_STATUSES, true)) {
            $refuse('it is no longer open');
        }
        if (! in_array($exception->kind, PaymentExceptionChoice::SUPPORTED_KINDS, true)) {
            $refuse('this kind of exception (' . str_replace('_', ' ', $exception->kind) . ') needs an owner decision, not a guest choice');
        }
        $receipt = Payment::find($exception->primary_payment_id);
        $owned = $receipt !== null && in_array($receipt->status, Payment::RECEIPT_STATUSES, true) && ($exception->kind === PaymentException::KIND_INTENT_UNALLOCATED
            ? $exception->booking_id === null && $receipt->booking_id === null && (int) $receipt->checkout_intent_id === (int) $exception->checkout_intent_id
                && CheckoutIntent::whereKey($exception->checkout_intent_id)->where('status', CheckoutIntent::STATUS_PAID_UNALLOCATED)->whereNull('booking_id')->exists()
            : $exception->booking_id !== null && (int) $receipt->booking_id === (int) $exception->booking_id
                && Booking::whereKey($exception->booking_id)->where('status', 'paid_unallocated')->exists());
        if (! $owned) {
            $refuse('its receipt, booking or checkout no longer matches a paid booking without a place');
        }
    }

    private function actionableLocked(PaymentException $exception): ?PaymentExceptionChoice
    {
        return PaymentExceptionChoice::where('payment_exception_id', $exception->id)->actionable()->lockForUpdate()->first();
    }

    /** Lock the choice and require that it belongs to the exception, is in an expected status and unchanged since seen. */
    public function lockChoice(PaymentExceptionChoice $choice, PaymentException $exception, array $statuses, int $expectedVersion): PaymentExceptionChoice
    {
        $this->assertHeld($exception);
        $c = PaymentExceptionChoice::whereKey($choice->id)->lockForUpdate()->firstOrFail();
        if ((int) $c->payment_exception_id !== (int) $exception->id) {
            throw new RuntimeException('This guest choice belongs to another payment exception; nothing was changed.');
        }
        if ($c->lock_version !== $expectedVersion || ! in_array($c->status, $statuses, true)) {
            throw new RuntimeException("Guest choice #{$c->id} changed meanwhile (now {$c->status}). Reload and review it; nothing was changed.");
        }

        return $c;
    }

    /**
     * The verified choice was acted on (inside the action's transaction, under withLocks): terminal, with the action
     * reference written once. The database refuses any later change and any new choice for the exception.
     */
    public function actOn(PaymentExceptionChoice $choice, PaymentException $exception, User $staff, string $reference): void
    {
        $this->assertHeld($exception, $staff, $choice);
        $this->transition($choice, PaymentExceptionChoice::ACTED_ON, ['acted_by' => $staff->id, 'acted_at' => now(), 'resolution_reference' => $reference]);
        $this->event($exception, PaymentExceptionEvent::CHOICE_ACTED_ON, $staff, $choice);
    }

    /** The verified date could not be given: the consent is spent and kept as history; a new date needs a new choice (P6). */
    public function failAction(PaymentExceptionChoice $choice, PaymentException $exception, User $staff, string $reason): void
    {
        $this->assertHeld($exception, $staff, $choice);
        $this->transition($choice, PaymentExceptionChoice::ACTION_FAILED, ['closed_by' => $staff->id, 'closed_at' => now(), 'closed_reason' => $reason]);
        $this->event($exception, PaymentExceptionEvent::CHOICE_ACTION_FAILED, $staff, $choice);
    }

    /**
     * Inside withLocks for this exception (and, for actions, by its checked staff on a choice locked verified there).
     * A programming error otherwise: nothing may lock or act on a choice outside the established locks and checks.
     */
    private function assertHeld(PaymentException $exception, ?User $staff = null, ?PaymentExceptionChoice $choice = null): void
    {
        $held = $this->held[$exception->id] ?? null;
        if ($held === null || DB::transactionLevel() === 0 || ($staff !== null && (int) $staff->id !== (int) $held)
            || ($choice !== null && ((int) $choice->payment_exception_id !== (int) $exception->id || $choice->status !== PaymentExceptionChoice::VERIFIED))) {
            throw new LogicException('Guest choices can only be locked or acted on inside GuestChoiceService::withLocks, by its checked staff, on a choice locked there as verified.');
        }
    }

    private function assertNotActedOn(PaymentException $exception): void
    {
        $acted = PaymentExceptionChoice::where('payment_exception_id', $exception->id)->where('status', PaymentExceptionChoice::ACTED_ON)->first();
        if ($acted !== null) {
            throw new RuntimeException("Guest choice #{$acted->id} was already acted on for this payment exception; a new choice cannot be recorded. Nothing was changed.");
        }
    }

    private function assertNotRecorder(PaymentExceptionChoice $choice, User $staff, string $action): void
    {
        if ((int) $choice->recorded_by === (int) $staff->id) {
            throw new RuntimeException("You recorded this guest choice, so you cannot {$action} it: a different owner or admin must; nothing was changed.");
        }
    }

    private function transition(PaymentExceptionChoice $choice, string $status, array $fields): void
    {
        $choice->fill($fields + ['status' => $status, 'lock_version' => $choice->lock_version + 1])->save();
    }

    private function insert(PaymentException $exception, User $staff, array $data, ?PaymentExceptionChoice $supersedes): PaymentExceptionChoice
    {
        $snapshot = $exception->booking_id !== null
            ? Booking::find($exception->booking_id, ['id', 'total_amount', 'downpayment_amount'])
            : CheckoutIntent::find($exception->checkout_intent_id, ['id', 'total_amount', 'downpayment_amount']);
        $cents = fn ($pesos) => $pesos === null ? null : (int) round((float) $pesos * 100);

        $choice = PaymentExceptionChoice::create($data + [
            'payment_exception_id' => $exception->id,
            'status' => PaymentExceptionChoice::RECORDED,
            'original_total_centavos' => $cents($snapshot?->total_amount),
            'original_downpayment_centavos' => $cents($snapshot?->downpayment_amount),
            'received_centavos' => $exception->amount_centavos,
            'price_snapshot_source' => $snapshot === null ? null : ($exception->booking_id !== null ? 'booking' : 'checkout_intent'),
            'price_snapshot_source_id' => $snapshot?->id,
            'recorded_by' => $staff->id,
            'recorded_at' => now(),
            'supersedes_choice_id' => $supersedes?->id,
            'lock_version' => 0,
        ]);
        $this->event($exception, PaymentExceptionEvent::CHOICE_RECORDED, $staff, $choice);

        return $choice;
    }

    /** Event and audit line with ids and the choice only (no evidence text or contact details). */
    private function event(PaymentException $exception, string $type, User $staff, PaymentExceptionChoice $choice): void
    {
        PaymentExceptionEvent::create(['payment_exception_id' => $exception->id, 'type' => $type, 'actor_id' => $staff->id, 'details' => [
            'choice_id' => $choice->id, 'choice' => $choice->choice, 'status' => $choice->status, 'supersedes_choice_id' => $choice->supersedes_choice_id,
        ]]);
        AuditLogger::log(strtoupper($type), "Payment exception #{$exception->id}: guest choice #{$choice->id} ({$choice->choice}) is now {$choice->status}. "
            . 'No place, refund or money changed.', $staff, $staff->name);
    }

    /** Complete, minimal, text-only evidence (P4). Refuses card numbers, PINs and passwords. */
    private function validated(array $input): array
    {
        $refuse = fn (string $why) => throw new RuntimeException("The guest choice was not recorded: {$why}.");
        $text = fn (?string $v) => $v === null ? null : trim($v);

        $choice = $input['choice'] ?? null;
        if (! in_array($choice, PaymentExceptionChoice::CHOICES, true)) {
            $refuse('choose refund review or an alternative date, as the guest said');
        }
        $start = $input['requested_start_date'] ?? null;
        $end = $input['requested_end_date'] ?? null;
        if ($choice === PaymentExceptionChoice::ALTERNATIVE_DATE) {
            if (! $this->isDate($start) || ! $this->isDate($end) || $end <= $start) {
                $refuse('give the alternative start and end dates the guest asked for (end after start)');
            }
            if ($start <= Carbon::today('Asia/Manila')->toDateString()) {
                $refuse('the alternative date must be in the future');
            }
        } elseif ($start !== null || $end !== null) {
            $refuse('a refund-review choice has no dates');
        }

        $channel = $input['evidence_channel'] ?? null;
        if (! in_array($channel, PaymentExceptionChoice::CHANNELS, true)) {
            $refuse('say how the guest told us (email, phone, message or in person)');
        }
        $evidenceAt = $input['evidence_at'] ?? null;
        if ($evidenceAt !== null) {
            try {
                $evidenceAt = Carbon::parse($evidenceAt);
            } catch (\Throwable) {
                $refuse('the communication time is not a valid date and time');
            }
            if ($evidenceAt->isAfter(now()->addMinutes(5))) {
                $refuse('the communication time is in the future');
            }
        }
        $reference = $text($input['evidence_reference'] ?? null) ?: null;
        if ($evidenceAt === null && $reference === null) {
            $refuse('give the communication time or a reference (message id, call log entry)');
        }
        if ($reference !== null && mb_strlen($reference) > 191) {
            $refuse('the reference is too long (191 characters at most)');
        }
        $summary = $text($input['evidence_summary'] ?? '') ?? '';
        if (mb_strlen($summary) < self::NOTE_MIN || mb_strlen($summary) > self::NOTE_MAX) {
            $refuse('write a short summary of what the guest said (10 to 500 characters), not the whole conversation');
        }
        foreach ([$summary, (string) $reference] as $value) {
            $this->assertNoSensitiveData($value, 'The guest choice was not recorded');
        }
        $method = $input['contact_match_method'] ?? null;
        if (! in_array($method, PaymentExceptionChoice::MATCH_METHODS, true)) {
            $refuse('say how the guest\'s contact was matched to the booking');
        }
        if (($input['contact_match_outcome'] ?? null) !== PaymentExceptionChoice::MATCHED) {
            $refuse('the guest\'s contact must match the booking or checkout before a choice is recorded');
        }

        return [
            'choice' => $choice,
            'requested_start_date' => $choice === PaymentExceptionChoice::ALTERNATIVE_DATE ? $start : null,
            'requested_end_date' => $choice === PaymentExceptionChoice::ALTERNATIVE_DATE ? $end : null,
            'evidence_channel' => $channel,
            'evidence_at' => $evidenceAt,
            'evidence_reference' => $reference,
            'evidence_summary' => $summary,
            'contact_match_method' => $method,
            'contact_match_outcome' => PaymentExceptionChoice::MATCHED,
        ];
    }

    private function note(string $value, string $label): string
    {
        $value = trim($value);
        if (mb_strlen($value) < self::NOTE_MIN || mb_strlen($value) > self::NOTE_MAX) {
            throw new RuntimeException("Give a {$label} of 10 to 500 characters; nothing was changed.");
        }
        $this->assertNoSensitiveData($value, "The {$label} was not saved");

        return $value;
    }

    /** Refuses card numbers, emails, phone numbers and credentials; the message never repeats the text. */
    public static function sensitiveDataKind(string $value): ?string
    {
        foreach (self::SENSITIVE_PATTERNS as $kind => $pattern) {
            if (preg_match($pattern, $value) === 1) {
                return $kind;
            }
        }

        return null;
    }

    private function assertNoSensitiveData(string $value, string $what): void
    {
        $kind = self::sensitiveDataKind($value);
        if ($kind !== null) {
            throw new RuntimeException("{$what}: do not include {$kind}. Refer to the booking's contact details instead of copying them; nothing was changed.");
        }
    }

    private function isDate(mixed $value): bool
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 && Carbon::hasFormat($value, 'Y-m-d')
            && Carbon::createFromFormat('Y-m-d', $value)->format('Y-m-d') === $value;
    }
}
