<?php

namespace Tests\Feature\Payments\Concerns;

use App\Models\Booking;
use App\Models\BookingParticipant;
use App\Models\CheckoutIntent;
use App\Models\Payment;
use App\Services\Checkout\BookingFinalizer;
use App\Services\Checkout\FinalizationResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Batch 3-II B1 fixtures: legacy bookings paid through the signed webhook, checkout intents finalized with a verified
 * (synthetic) payment, and helpers to read what was recorded. Used with FakesPayMongo, RefundFixtures and
 * BuildsCheckoutIntents; PayMongo is never called.
 */
trait ReceiptFixtures
{
    /** A legacy booking on $date with $pax participants. */
    protected function paxBooking(int $pax, string $status = 'confirmed', ?string $date = null, ?int $batchId = null): Booking
    {
        $date ??= now()->addDays(30)->toDateString();
        $booking = $this->booking(['status' => $status, 'start_date' => $date, 'end_date' => \Carbon\Carbon::parse($date)->addDay()->toDateString(), 'batch_id' => $batchId]);
        for ($i = 0; $i < $pax; $i++) {
            BookingParticipant::create(['booking_id' => $booking->id, 'name' => 'Guest ' . chr(65 + $i % 26), 'age' => 30, 'price_per_person' => 4250]);
        }

        return $booking->fresh();
    }

    /** A legacy reservation waiting for its ₱2,000 downpayment, with its pending PayMongo row. */
    protected function unpaidBooking(int $pax = 2, ?string $date = null, ?int $batchId = null): Booking
    {
        $booking = $this->paxBooking($pax, 'pending_downpayment', $date, $batchId);
        Payment::create(['booking_id' => $booking->id, 'payment_method' => 'paymongo', 'transaction_id' => 'PAY-' . Str::upper(Str::random(8)),
            'amount' => 2000, 'payment_type' => 'downpayment', 'status' => 'pending', 'paymongo_resource_id' => "cs_{$booking->id}"]);

        return $booking;
    }

    protected function paidAttributes(int $centavos = 200000, array $overrides = []): array
    {
        return array_merge(['status' => 'paid', 'amount' => $centavos, 'currency' => 'PHP', 'livemode' => false, 'fee' => 5000], $overrides);
    }

    /** Deliver a signed checkout_session.payment.paid webhook for a legacy booking. */
    protected function payByWebhook(Booking $booking, string $payId, array $attributes = [], ?string $eventId = null)
    {
        $attributes = $attributes ?: $this->paidAttributes();

        return $this->postSignedPayMongoWebhook(['data' => ['id' => $eventId ?? "evt_{$payId}_" . Str::random(4), 'type' => 'event', 'attributes' => [
            'type' => 'checkout_session.payment.paid', 'data' => [
                'id' => "cs_{$booking->id}", 'type' => 'checkout_session', 'attributes' => ['amount' => $attributes['amount'] ?? 0, 'payment_method_type' => 'gcash',
                    'metadata' => ['booking_id' => $booking->id, 'booking_number' => $booking->booking_number],
                    'payments' => [['id' => $payId, 'attributes' => $attributes]]]]]]]);
    }

    protected function finalizeIntent(CheckoutIntent $intent, array $overrides = []): FinalizationResult
    {
        return app(BookingFinalizer::class)->finalize($intent->fresh(), $this->verifiedResultFor($intent, $overrides), 'test');
    }

    /** Row counts of every table B1 writes to. */
    protected function receiptCounts(): array
    {
        return collect(['payments', 'provider_payment_identities', 'provider_identity_conflicts', 'payment_exceptions', 'payment_exception_receipts', 'payment_exception_events'])
            ->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()])->all();
    }

    /** Run $write in a savepoint and return the database error message it raised (the test's transaction stays usable). */
    protected function databaseRefusal(callable $write): ?string
    {
        try {
            DB::transaction($write);
        } catch (\Illuminate\Database\QueryException $e) {
            return $e->getMessage();
        }

        return null;
    }
}
