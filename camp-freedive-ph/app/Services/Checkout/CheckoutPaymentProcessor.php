<?php

namespace App\Services\Checkout;

use App\Models\CheckoutIntent;
use App\Services\PayMongoService;
use Carbon\CarbonImmutable;

/**
 * One entry point for the customer return page and the PayMongo webhook: verify the payment with PayMongo
 * (network, outside any lock or transaction), then let BookingFinalizer decide under its locks.
 *
 * A browser return or a webhook is never proof of payment on its own; only a fresh PaymentVerifier read is.
 * Durable end states (finalized, paid_unallocated) are answered from the database without calling PayMongo
 * again, so repeated returns and duplicate webhooks cost nothing and change nothing.
 */
class CheckoutPaymentProcessor
{
    public function __construct(
        protected PaymentVerifier $verifier,
        protected BookingFinalizer $finalizer,
        protected PayMongoService $paymongo,
        protected CheckoutNotifier $notifier,
    ) {
    }

    /**
     * @param string $source return, webhook, ... (stored with the payment evidence)
     * @param string|null $claimedSessionId the session id an authenticated webhook names, if any
     */
    public function process(CheckoutIntent $intent, string $source, ?string $claimedSessionId = null): FinalizationResult
    {
        return $this->notified($intent, $this->decide($intent, $source, $claimedSessionId));
    }

    /**
     * Post-outcome messages: written to the CheckoutNotifier outbox (once per checkout) and queued, never sent inside
     * this request. Also runs for outcomes answered from the database, so a lost recording or dispatch is redone.
     */
    private function notified(CheckoutIntent $intent, FinalizationResult $result): FinalizationResult
    {
        if (in_array($result->outcome, [FinalizationResult::FINALIZED, FinalizationResult::ALREADY_FINALIZED, FinalizationResult::PAID_UNALLOCATED], true)) {
            $this->notifier->afterOutcome($intent);
        }

        return $result;
    }

    private function decide(CheckoutIntent $intent, string $source, ?string $claimedSessionId): FinalizationResult
    {
        $sameSession = $claimedSessionId === null || $claimedSessionId === $intent->paymongo_checkout_session_id;

        if ($sameSession && $intent->status === CheckoutIntent::STATUS_FINALIZED) {
            return FinalizationResult::alreadyFinalized($intent->booking);
        }
        if ($sameSession && $intent->status === CheckoutIntent::STATUS_PAID_UNALLOCATED) {
            return FinalizationResult::paidUnallocated($intent->unallocated_reason ?? 'capacity_unavailable');
        }

        return $this->finalizer->finalize($intent, $this->verifier->verify($intent, $claimedSessionId), $source);
    }

    /**
     * Finish a `paid` intent (payment verified and saved, booking not created yet) from the evidence saved when
     * it was verified, without asking PayMongo again. Falls back to a fresh verification when the saved evidence
     * is incomplete, does not match the intent, or was verified in a different PayMongo mode (test vs live) than
     * the one configured now; the fresh verification then sends a mode mismatch to review instead of finalizing.
     * The finalizer re-checks payment id, amount, currency and capacity.
     */
    public function resumeFromEvidence(CheckoutIntent $intent, string $source): FinalizationResult
    {
        $e = $intent->verification ?? [];
        $usable = $intent->status === CheckoutIntent::STATUS_PAID
            && is_string($e['payment_id'] ?? null) && $e['payment_id'] === $intent->paymongo_payment_id
            && is_string($e['checkout_session_id'] ?? null) && $e['checkout_session_id'] === $intent->paymongo_checkout_session_id
            && is_int($e['amount_centavos'] ?? null) && is_string($e['currency'] ?? null)
            && is_bool($e['livemode'] ?? null) && $e['livemode'] === $this->paymongo->configuredLivemode()
            && is_string($e['verified_at'] ?? null);

        if (! $usable) {
            return $this->process($intent, $source);
        }

        $payment = new VerifiedPayment(
            checkoutIntentId: $intent->id,
            checkoutSessionId: $e['checkout_session_id'],
            paymentId: $e['payment_id'],
            amountCentavos: $e['amount_centavos'],
            currency: $e['currency'],
            status: 'paid',
            livemode: $e['livemode'],
            verifiedAt: CarbonImmutable::parse($e['verified_at']),
            sessionRecovered: (bool) ($e['session_recovered'] ?? false),
            paidAt: is_string($e['paid_at'] ?? null) ? CarbonImmutable::parse($e['paid_at']) : null,
            paymentMethod: is_string($e['payment_method'] ?? null) ? $e['payment_method'] : null,
            paymentIntentId: is_string($e['payment_intent_id'] ?? null) ? $e['payment_intent_id'] : null,
            feeCentavos: is_int($e['fee_centavos'] ?? null) ? $e['fee_centavos'] : null,
        );

        return $this->notified($intent, $this->finalizer->finalize($intent, PaymentVerificationResult::verified($payment), $source));
    }
}
