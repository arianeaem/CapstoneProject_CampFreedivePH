<?php

namespace App\Services\Checkout;

use App\Models\CheckoutIntent;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Authenticated PayMongo webhook events for checkout intents (the caller has already checked the signature).
 *
 * The event only says which intent to look at; the payment itself is re-read from PayMongo by PaymentVerifier.
 * Answers (PayMongo retries a webhook that does not get a 2xx answer):
 *  - 200: finalized, already finalized, paid_unallocated, payment_review (all saved durably), events that are
 *         not payment events, and events that name no matching checkout (a retry could not help).
 *  - 503: verification or finalization can be retried (PayMongo unreachable, lock busy, rolled back), or a paid
 *         event while PayMongo does not show the payment yet. Nothing final was written, so a retry is safe.
 *  - 500: unexpected error (nothing final written; idempotent on retry).
 * Logs carry ids and outcomes only: no contact details, metadata or provider bodies.
 */
class CheckoutWebhookHandler
{
    private const PAID_EVENTS = ['checkout_session.payment.paid', 'payment.paid'];

    public function __construct(protected CheckoutPaymentProcessor $processor)
    {
    }

    /**
     * The checkout-intent link of an event, or null when it is not a checkout-intent event.
     * Legacy booking events carry booking_id and always stay with the legacy handler.
     *
     * @return array{id: string, reference: string}|null
     */
    public static function intentMetadata(array $eventData): ?array
    {
        $metadata = $eventData['attributes']['metadata'] ?? null;
        if (! is_array($metadata) || array_key_exists('booking_id', $metadata) || ! isset($metadata['checkout_intent_id'])) {
            return null;
        }

        return ['id' => (string) $metadata['checkout_intent_id'], 'reference' => (string) ($metadata['checkout_reference'] ?? '')];
    }

    /**
     * @param array{id: string, reference: string} $link from intentMetadata()
     * @return array{0: int, 1: array} HTTP status and JSON body
     */
    public function handle(string $eventType, array $eventData, array $link, ?string $eventId): array
    {
        $intent = ctype_digit($link['id']) ? CheckoutIntent::find((int) $link['id']) : null;
        if ($intent === null || ! hash_equals($intent->reference, $link['reference'])) {
            Log::warning('PayMongo webhook names no matching checkout intent; nothing changed.', ['event_id' => $eventId, 'event' => $eventType]);
            AuditLogger::log('CHECKOUT_WEBHOOK_UNMATCHED', "PayMongo event {$eventId} ({$eventType}) named a checkout that does not match any checkout intent; nothing changed.", null, 'PayMongo Webhook');

            return [200, ['received' => true, 'event' => $eventType, 'status' => 'unmatched']];
        }

        if (! in_array($eventType, self::PAID_EVENTS, true)) {
            Log::info('PayMongo checkout webhook ignored (not a payment event).', ['checkout_intent_id' => $intent->id, 'event_id' => $eventId, 'event' => $eventType]);

            return [200, ['received' => true, 'event' => $eventType, 'status' => 'ignored']];
        }

        // A checkout_session event names its session: the verifier checks it against the saved one
        $claimedSessionId = ($eventData['type'] ?? null) === 'checkout_session' && is_string($eventData['id'] ?? null) ? $eventData['id'] : null;

        try {
            $result = $this->processor->process($intent, 'webhook', $claimedSessionId);
        } catch (Throwable $e) {
            Log::error('PayMongo checkout webhook could not be processed.', ['checkout_intent_id' => $intent->id, 'event_id' => $eventId, 'exception' => class_basename($e)]);

            return [500, ['error' => 'Webhook processing error.']];
        }

        Log::info('PayMongo checkout webhook processed.', ['checkout_intent_id' => $intent->id, 'event_id' => $eventId, 'outcome' => $result->outcome, 'reason' => $result->reason]);

        if (in_array($result->outcome, [FinalizationResult::RETRYABLE, FinalizationResult::NOT_PAID], true)) {
            return [503, ['error' => 'Payment could not be confirmed yet; retry later.', 'event' => $eventType]];
        }

        return [200, ['received' => true, 'event' => $eventType, 'status' => $result->outcome]];
    }
}
