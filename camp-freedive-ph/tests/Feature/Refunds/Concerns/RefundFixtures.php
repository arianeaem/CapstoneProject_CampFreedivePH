<?php

namespace Tests\Feature\Refunds\Concerns;

use App\Models\Booking;
use App\Models\BookingRefund;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Synthetic bookings, receipts and PayMongo refund answers for the Batch 3-I refund tests.
 * Amounts follow the client's numbers: ₱4,250 bookings, ₱2,000 own-transport / ₱3,000 van downpayments.
 */
trait RefundFixtures
{
    private int $fixtureSeq = 0;

    /** @param ?string $policyVersion null = made under the earlier published terms */
    protected function booking(array $overrides = [], ?string $policyVersion = 'current'): Booking
    {
        $n = ++$this->fixtureSeq;
        $booking = Booking::create(array_merge([
            'booking_number' => 'CFP-2026-RF' . str_pad((string) $n, 3, '0', STR_PAD_LEFT),
            'pin' => '4821',
            'class_type' => 'discovery',
            'start_date' => now()->addDays(30)->toDateString(),
            'end_date' => now()->addDays(31)->toDateString(),
            'pickup_option' => 'own',
            'total_amount' => 4250,
            'downpayment_amount' => 2000,
            'balance_amount' => 2250,
            'contact_name' => 'Synthetic Guest',
            'contact_email' => "guest{$n}@example.test",
            'contact_phone' => '09170000001',
            'status' => 'confirmed',
        ], $overrides));
        // The model stamps the current version on creation; earlier-terms bookings have none
        $booking->forceFill(['refund_policy_version' => $policyVersion === 'current' ? config('refunds.policy_version') : $policyVersion])->saveQuietly();

        return $booking->fresh();
    }

    protected function receipt(Booking $booking, float $amount, string $status = 'paid', ?string $payId = 'auto', string $type = 'downpayment', array $extra = []): Payment
    {
        $n = ++$this->fixtureSeq;

        return Payment::create(array_merge([
            'booking_id' => $booking->id,
            'payment_method' => $payId === null ? 'cash' : 'gcash',
            'transaction_id' => "TXN-RF-{$n}",
            'paymongo_payment_id' => $payId === 'auto' ? "pay_test_rf_{$n}" : $payId,
            'amount' => $amount,
            'payment_type' => $type,
            'status' => $status,
            'paid_at' => now(),
        ], $extra));
    }

    protected function staff(string $role = 'admin'): User
    {
        return User::factory()->create(['role' => $role, 'status' => 'active', 'must_change_password' => false, 'email' => "{$role}." . uniqid() . '@example.test']);
    }

    /** A camp-cancellation case, classified and with the guest's choice already recorded (as staff would). */
    protected function campCase(Booking $booking, string $reason, string $started = 'no', ?string $choice = 'refund'): BookingRefund
    {
        $staff = $this->staff();
        $service = app(\App\Services\Refunds\RefundCaseService::class);
        $case = $service->openForCampCancellation($booking, $reason, $started, $staff, 'Synthetic camp cancellation');
        if ($choice !== null) {
            $case = $service->recordGuestChoice($case, $choice, $staff, 'Guest replied by email (synthetic)');
        }

        return $case->fresh();
    }

    /**
     * PayMongo answers GET /v1/refunds/{id} ("Retrieve a Refund"): $lookups[id] is the refund's attributes, a
     * [status, body] pair or a Throwable; any other id gets a 404. Every looked-up id is recorded in $gets.
     */
    protected function fakeRefundLookups(array $lookups, array &$gets): void
    {
        Http::fake(function (Request $request) use ($lookups, &$gets) {
            if ($request->method() !== 'GET' || ! preg_match('#/v1/refunds/([^/]+)$#', (string) parse_url($request->url(), PHP_URL_PATH), $m)) {
                return null;
            }
            $gets[] = $m[1];
            $answer = $lookups[$m[1]] ?? [404, ['errors' => [['code' => 'resource_not_found']]]];
            if ($answer instanceof \Throwable) {
                throw $answer;
            }
            if (array_is_list($answer)) {
                return Http::response($answer[1], $answer[0]);
            }

            return Http::response(['data' => ['id' => $m[1], 'type' => 'refund', 'attributes' => $answer]]);
        });
    }

    /** The attributes PayMongo would return for $attempt's own refund (documented fields), with $overrides. */
    protected function providerRefund(\App\Models\RefundAttempt $attempt, array $overrides = []): array
    {
        return array_merge([
            'amount' => $attempt->amount_centavos,
            'currency' => 'PHP',
            'payment_id' => $attempt->payment->paymongo_payment_id,
            'status' => 'succeeded',
            'notes' => "Camp FreedivePH refund op:{$attempt->operation_id}",
            'livemode' => false,
            'reason' => 'requested_by_customer',
            'metadata' => null,
        ], $overrides);
    }

    /**
     * PayMongo answers POST /v1/refunds with $answers in order (last repeats). Each answer: 'succeeded', 'pending',
     * 'failed', or a Throwable / [status, body] pair. Every POST body is recorded in $posts.
     */
    protected function fakeRefunds(array $answers, array &$posts): void
    {
        Http::fake(function (Request $request) use (&$answers, &$posts) {
            if (! str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/v1/refunds')) {
                return null;
            }
            $attributes = $request->data()['data']['attributes'];
            $posts[] = $attributes;
            $answer = count($answers) > 1 ? array_shift($answers) : $answers[0];
            if ($answer instanceof \Throwable) {
                throw $answer;
            }
            if (is_array($answer)) {
                return Http::response($answer[1], $answer[0]);
            }

            return Http::response(['data' => ['id' => 'ref_test_' . count($posts), 'type' => 'refund', 'attributes' => [
                'amount' => $attributes['amount'], 'currency' => 'PHP', 'payment_id' => $attributes['payment_id'],
                'status' => $answer, 'livemode' => false, 'notes' => $attributes['notes'],
            ]]]);
        });
    }
}
