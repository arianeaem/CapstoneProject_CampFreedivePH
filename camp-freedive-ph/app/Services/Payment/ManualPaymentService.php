<?php

namespace App\Services\Payment;

use App\Models\Booking;
use App\Models\Payment;
use App\Models\PaymentStatusLog;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * B4: staff-recorded offline payments (cash, bank transfer, e-wallet sent outside PayMongo), the existing manual workflow,
 * made safe. A manual payment is the recording staff member's own attestation: it is kept as a "completed" receipt
 * with created_by (its provenance; PayMongo receipts carry a verified provider identity instead), never with a
 * PayMongo id, and it never confirms a booking by itself (staff confirm through the status menu, which checks the
 * downpayment, capacity and the operating date).
 *
 *  - Only an active owner or admin records one.
 *  - Only for a booking that is waiting for its downpayment, confirmed or rescheduled, and whose money is not held by a
 *    payment exception; never more than is still due (total minus receipts), never 0 or less.
 *  - The reference is the staff member's note, not proof: at most 64 characters, unique, and never shaped like a
 *    PayMongo id (pay_, cs_, ref_, evt_, pi_), so it cannot pass for a provider-verified payment.
 *  - The form carries the balance it showed (expected_balance): a replayed or stale submission (another payment was
 *    recorded meanwhile) is refused instead of recording the money twice.
 *  - Booking row locked; nothing else is touched (no capacity, batch, refund or exception change).
 */
class ManualPaymentService
{
    /** References that look like PayMongo identifiers are refused (they would impersonate a verified payment). */
    public const PROVIDER_ID_PATTERN = '/^\s*(pay|cs|ref|evt|pi|src)_/i';

    public const PAYABLE_BOOKING_STATUSES = ['pending_downpayment', 'confirmed', 'rescheduled'];

    public function __construct(protected PaymentExceptionService $exceptions)
    {
    }

    /** @param array{payment_method: string, payment_type: string, amount: mixed, transaction_id?: ?string, notes?: ?string, expected_balance?: mixed} $data */
    public function record(Booking $booking, User $actor, array $data, string $prefix = 'OFFLINE-TXN'): Payment
    {
        $staff = User::find($actor->id);
        if ($staff === null || $staff->status !== 'active' || ! in_array($staff->role, ['owner', 'admin'], true)) {
            throw new RuntimeException('Only an active owner or admin can record a payment; nothing was saved.');
        }
        $centavos = (int) round((float) $data['amount'] * 100);
        if ($centavos <= 0) {
            throw new RuntimeException('The amount must be more than ₱0; nothing was saved.');
        }
        if (! isset($data['expected_balance']) || ! is_numeric($data['expected_balance'])) {
            throw new RuntimeException('The form is missing the balance it showed. Reload the page and try again; nothing was saved.');
        }
        $reference = self::reference($data['transaction_id'] ?? null);

        $payment = DB::transaction(function () use ($booking, $staff, $data, $centavos, $reference, $prefix) {
            $locked = Booking::whereKey($booking->id)->lockForUpdate()->firstOrFail();
            if (! in_array($locked->status, self::PAYABLE_BOOKING_STATUSES, true)) {
                throw new RuntimeException('A payment can only be recorded for a booking that is waiting for its downpayment, confirmed or rescheduled (this one is '
                    . str_replace('_', ' ', $locked->status) . '); nothing was saved.');
            }
            $this->exceptions->assertNotHeld($locked, 'given a manual payment');
            if ((int) round((float) $locked->balance_amount * 100) !== (int) round((float) $data['expected_balance'] * 100)) {
                throw new RuntimeException('Another payment was recorded for this booking meanwhile (the balance changed). Reload the page and check before recording again; nothing was saved.');
            }
            if ($reference !== null && Payment::where('transaction_id', $reference)->exists()) {
                throw new RuntimeException("Reference {$reference} is already recorded for a payment; nothing was saved.");
            }
            $paid = self::receivedCentavos($locked);
            $due = (int) round((float) $locked->total_amount * 100) - $paid;
            if ($centavos > $due) {
                throw new RuntimeException('₱' . number_format($centavos / 100, 2) . ' is more than what is still due on this booking (₱'
                    . number_format(max(0, $due) / 100, 2) . '); nothing was saved.');
            }

            $payment = Payment::create([
                'booking_id' => $locked->id,
                'payment_method' => $data['payment_method'],
                'transaction_id' => $reference ?? ($prefix . '-' . strtoupper(bin2hex(random_bytes(5)))),
                'amount' => $centavos / 100,
                'fee_amount' => 0.00,
                'net_amount' => $centavos / 100,
                'payment_type' => $data['payment_type'],
                'status' => 'completed',
                'paid_at' => now(),
                'created_by' => $staff->id,
            ]);
            $locked->update(['balance_amount' => max(0, $due - $centavos) / 100]);
            PaymentStatusLog::create([
                'payment_id' => $payment->id,
                'old_status' => 'new',
                'new_status' => 'completed',
                'changed_by' => $staff->id,
                'note' => "Manual {$data['payment_method']} payment recorded by {$staff->name} (staff attestation; not provider-verified)"
                    . (! empty($data['notes']) ? ' - ' . $data['notes'] : ''),
                'created_at' => now(),
            ]);

            return $payment;
        });

        AuditLogger::log($data['payment_type'] === 'balance_settlement' ? 'BALANCE_SETTLED' : 'PAYMENT_RECORDED_MANUALLY',
            "Manual payment #{$payment->id} of ₱" . number_format($centavos / 100, 2) . " ({$data['payment_method']}, {$data['payment_type']}) recorded for Booking #{$booking->booking_number} by {$staff->name}. "
            . 'Staff attestation, not provider-verified; booking status unchanged.', $staff, $staff->name);

        return $payment;
    }

    /** Receipts that count toward the booking's paid total (same rule as the balance shown to staff). */
    public static function receivedCentavos(Booking $booking): int
    {
        return (int) round((float) $booking->payments()->whereIn('status', ['completed', 'paid'])->sum('amount') * 100);
    }

    /** The staff reference, checked: null when none was given. */
    public static function reference(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        if (mb_strlen($value) > 64) {
            throw new RuntimeException('The reference may be at most 64 characters; nothing was saved.');
        }
        if (preg_match(self::PROVIDER_ID_PATTERN, $value) === 1) {
            throw new RuntimeException('A manual reference cannot look like a PayMongo identifier (pay_, cs_, ref_, ...): PayMongo payments are recorded only from PayMongo itself. Nothing was saved.');
        }

        return $value;
    }
}
