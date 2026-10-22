<?php

namespace App\Services\Checkout;

use App\Models\CheckoutIntent;
use App\Models\Payment;
use App\Services\PayMongoService;
use Carbon\CarbonImmutable;

/**
 * Decides whether a checkout intent has an authentic, successful PayMongo payment that belongs to it.
 *
 * Read-only: it never creates bookings, changes the intent, or calls refunds. The finalizer
 * (Batch 2B) acts on the result. The decision always uses a fresh PayMongo read, and only fields
 * documented on PayMongo's Checkout Session and Payment resources:
 *   session: id, attributes.livemode, attributes.metadata, attributes.payments[],
 *            attributes.payment_intent.attributes.status (only when nothing is paid or pending)
 *   payment: id (pay_), attributes.status (pending|failed|paid), amount (centavos), currency (PHP),
 *            livemode, paid_at, payment_intent_id, fee, source.type
 * Payment-level metadata is not documented, so ownership is checked on the session metadata only.
 */
class PaymentVerifier
{
    private const PAYMENT_STATUSES = ['pending', 'failed', 'paid'];

    public function __construct(protected PayMongoService $paymongo)
    {
    }

    /**
     * @param string|null $claimedSessionId session id named by the caller (e.g. a webhook). Used to recover
     *                                      a session that PayMongo created but we never saved; must match
     *                                      the saved id when one exists.
     */
    public function verify(CheckoutIntent $intent, ?string $claimedSessionId = null): PaymentVerificationResult
    {
        $savedSessionId = $intent->paymongo_checkout_session_id;

        if ($savedSessionId && $claimedSessionId && $claimedSessionId !== $savedSessionId) {
            return PaymentVerificationResult::needsReview('session_mismatch', ['expected_session_id' => $savedSessionId, 'claimed_session_id' => $claimedSessionId]);
        }

        $sessionId = $savedSessionId ?: $claimedSessionId;
        if (! $sessionId) {
            return PaymentVerificationResult::needsReview('no_checkout_session');
        }
        $recovering = ! $savedSessionId;

        $expectedLivemode = $this->paymongo->configuredLivemode();
        if ($expectedLivemode === null) {
            // No recognisable secret key: cannot tell test from live, so never accept
            return PaymentVerificationResult::retryable('provider_mode_unknown');
        }

        $fetched = $this->paymongo->fetchCheckoutSessionForVerification($sessionId);
        $details = ['checkout_session_id' => $sessionId];

        switch ($fetched['outcome']) {
            case 'ok':
                break;
            case 'not_found':
                return PaymentVerificationResult::needsReview('session_not_found', $details);
            case 'not_configured':
                return PaymentVerificationResult::retryable('provider_not_configured', $details);
            case 'rejected':
                return PaymentVerificationResult::retryable('provider_rejected_request', $details + ['http_status' => $fetched['http_status'] ?? null]);
            case 'malformed':
                return PaymentVerificationResult::retryable('provider_response_malformed', $details);
            default:
                return PaymentVerificationResult::retryable('provider_unavailable', $details + ['http_status' => $fetched['http_status'] ?? null]);
        }

        $session = $fetched['session'];
        $attributes = $session['attributes'];

        // 1. The session PayMongo returned is the one we asked about
        if ($session['id'] !== $sessionId) {
            return PaymentVerificationResult::needsReview('session_mismatch', $details + ['returned_session_id' => $session['id']]);
        }

        // 2. It was created for this intent (both id and reference; required, especially when recovering)
        $metadata = is_array($attributes['metadata'] ?? null) ? $attributes['metadata'] : [];
        if ((string) ($metadata['checkout_intent_id'] ?? '') !== (string) $intent->id) {
            return PaymentVerificationResult::needsReview('intent_mismatch', $details);
        }
        if ((string) ($metadata['checkout_reference'] ?? '') !== (string) $intent->reference) {
            return PaymentVerificationResult::needsReview('reference_mismatch', $details);
        }

        // 3. Same environment as our key (a test-mode payment must never confirm a live booking)
        if (! is_bool($attributes['livemode'] ?? null)) {
            return PaymentVerificationResult::retryable('provider_response_incomplete', $details + ['missing' => 'livemode']);
        }
        if ($attributes['livemode'] !== $expectedLivemode) {
            return PaymentVerificationResult::needsReview('mode_mismatch', $details);
        }

        // 4. Payments on the session
        if (! array_key_exists('payments', $attributes) || ! is_array($attributes['payments'])) {
            return PaymentVerificationResult::retryable('provider_response_incomplete', $details + ['missing' => 'payments']);
        }

        $paid = [];
        $pending = false;
        foreach ($attributes['payments'] as $payment) {
            $status = $payment['attributes']['status'] ?? null;
            if (! in_array($status, self::PAYMENT_STATUSES, true)) {
                return PaymentVerificationResult::needsReview('unexpected_payment_status', $details + ['status' => is_string($status) ? $status : null]);
            }
            if ($status === 'paid') {
                $paid[] = $payment;
            } elseif ($status === 'pending') {
                $pending = true;
            }
        }

        if (count($paid) > 1) {
            return PaymentVerificationResult::needsReview('multiple_paid_payments', $details + ['paid_count' => count($paid)]);
        }
        if ($paid === []) {
            return PaymentVerificationResult::notPaid($pending ? 'payment_pending' : $this->unpaidReason($attributes), $details);
        }

        $payment = $paid[0];
        $pay = $payment['attributes'] ?? [];
        $paymentId = $payment['id'] ?? null;

        if (! is_string($paymentId) || ! str_starts_with($paymentId, 'pay_')) {
            return PaymentVerificationResult::needsReview('payment_id_missing', $details);
        }
        $details['payment_id'] = $paymentId;

        if (! is_bool($pay['livemode'] ?? null)) {
            return PaymentVerificationResult::retryable('provider_response_incomplete', $details + ['missing' => 'payment.livemode']);
        }
        if ($pay['livemode'] !== $expectedLivemode) {
            return PaymentVerificationResult::needsReview('mode_mismatch', $details);
        }

        // 5. Exact amount and currency of this intent's server-calculated downpayment
        if (! is_int($pay['amount'] ?? null) || ! is_string($pay['currency'] ?? null)) {
            return PaymentVerificationResult::retryable('provider_response_incomplete', $details + ['missing' => 'payment.amount_or_currency']);
        }
        $expectedCentavos = (int) round((float) $intent->downpayment_amount * 100);
        if ($pay['amount'] !== $expectedCentavos) {
            return PaymentVerificationResult::needsReview('amount_mismatch', $details + ['expected_centavos' => $expectedCentavos, 'actual_centavos' => $pay['amount']]);
        }
        if ($pay['currency'] !== 'PHP' || $pay['currency'] !== $intent->currency) {
            return PaymentVerificationResult::needsReview('currency_mismatch', $details + ['expected_currency' => $intent->currency, 'actual_currency' => $pay['currency']]);
        }

        // 6. The payment is not already claimed by another intent or recorded on another booking
        if (CheckoutIntent::where('paymongo_payment_id', $paymentId)->whereKeyNot($intent->id)->exists()) {
            return PaymentVerificationResult::needsReview('payment_id_used_by_other_intent', $details);
        }
        $paymentRows = Payment::where('paymongo_payment_id', $paymentId);
        if ($intent->booking_id) {
            $paymentRows->where('booking_id', '!=', $intent->booking_id); // this intent's own booking may already hold it
        }
        if ($paymentRows->exists()) {
            return PaymentVerificationResult::needsReview('payment_id_used_by_payment_record', $details);
        }

        return PaymentVerificationResult::verified(new VerifiedPayment(
            checkoutIntentId: $intent->id,
            checkoutSessionId: $sessionId,
            paymentId: $paymentId,
            amountCentavos: $pay['amount'],
            currency: $pay['currency'],
            status: 'paid',
            livemode: $pay['livemode'],
            verifiedAt: CarbonImmutable::now(),
            sessionRecovered: $recovering,
            // paid_at is a Unix timestamp in PayMongo's examples; its type is not formally documented, so only trust an integer
            paidAt: is_int($pay['paid_at'] ?? null) ? CarbonImmutable::createFromTimestamp($pay['paid_at']) : null,
            paymentMethod: is_string($pay['source']['type'] ?? null) ? $pay['source']['type'] : null,
            paymentIntentId: is_string($pay['payment_intent_id'] ?? null) ? $pay['payment_intent_id'] : null,
            feeCentavos: is_int($pay['fee'] ?? null) ? $pay['fee'] : null,
        ));
    }

    /**
     * No paid or pending payment on the session: what its payment intent says. An open GCash authorization shows only
     * here (payments[] stays empty, status awaiting_next_action; observed in test mode, Batch 2C.2.7A), and PayMongo
     * accepts closing the session in that state (2C.2.7B), so only an explicit "nothing started" is reported as unpaid.
     */
    private function unpaidReason(array $attributes): string
    {
        $status = $attributes['payment_intent']['attributes']['status'] ?? null;

        return match ($status) {
            'awaiting_payment_method' => 'no_successful_payment',     // nothing started: the session may be closed
            'cancelled' => 'payment_intent_cancelled',                // already closed (e.g. an earlier expiry)
            'awaiting_next_action', 'processing' => 'payment_authorizing',
            default => 'payment_state_unconfirmed',                   // missing, malformed, unknown or succeeded without a payment
        };
    }
}
