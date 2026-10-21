<?php

namespace App\Services\Checkout;

use App\Models\CheckoutIntent;

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
    ) {
    }

    /**
     * @param string $source return, webhook, ... (stored with the payment evidence)
     * @param string|null $claimedSessionId the session id an authenticated webhook names, if any
     */
    public function process(CheckoutIntent $intent, string $source, ?string $claimedSessionId = null): FinalizationResult
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
}
