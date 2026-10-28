<?php

namespace App\Http\Controllers\Payment;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\PaymongoWebhookEvent;
use App\Models\RefundAttempt;
use App\Services\AuditLogger;
use App\Services\Booking\CapacityCoordinator;
use App\Services\Booking\CapacityException;
use App\Services\Booking\LegacyPaymentConfirmer;
use App\Services\Refunds\RefundExecutor;
use App\Services\Checkout\CheckoutWebhookHandler;
use App\Services\Gateways\PayMongoGateway;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * PayMongo checkout and webhooks.
 *
 * - sends the participant to the PayMongo checkout page (GCash, Maya, cards, GrabPay), only for a booking still
 *   waiting for its downpayment and only while its date has a place
 * - the signed return page and the signed webhook both apply the payment through LegacyPaymentConfirmer: verified
 *   payment, capacity re-checked under the date lock, confirmed only if a place is free (else kept for staff review);
 *   the confirmation email goes through the durable outbox, once, only after a real confirmation
 */
class PayMongoController extends Controller
{
    protected PayMongoGateway $gateway;
    protected AuditLogger $auditLogger;

    public function __construct(PayMongoGateway $gateway, AuditLogger $auditLogger)
    {
        $this->gateway = $gateway;
        $this->auditLogger = $auditLogger;
    }

    /**
     * Start the PayMongo checkout for the downpayment.
     *
     * POST /booking/{booking}/paymongo/checkout
     */
    public function checkout(Request $request, Booking $booking): JsonResponse|RedirectResponse
    {
        // Only the participant who opened this booking with its number + PIN can pay for it
        if (session('auth_booking_id') !== $booking->id) {
            return redirect()->route('manage.index')->with('info', 'Please enter your booking number and PIN first.');
        }

        // Only a booking still waiting for its downpayment can be paid (never a cancelled, confirmed or under-review one)
        if (! in_array($booking->status, ['pending_downpayment', 'pending'], true)) {
            return back()->with('error', 'This booking is not waiting for a payment, so no new payment was started.');
        }
        // Its place is not held any more after 15 minutes: only start a payment while the date still has room
        // (the payment is checked again under the capacity lock when it arrives)
        $pax = $booking->participants()->count();
        if ($pax > app(CapacityCoordinator::class)->remaining($booking->start_date->format('Y-m-d'), $booking)) {
            return back()->with('error', 'This date is now full, so no new payment was started. Please contact us to choose another date.');
        }

        // 1. Get the downpayment amount
        $amount = (float) ($booking->downpayment_amount ?? $booking->total_price ?? 3000);

        if ($amount <= 0) {
            return back()->with('error', 'Invalid payment amount.');
        }

        try {
            // 2. Create the checkout session
            $result = $this->gateway->createCheckoutSession($booking, $amount);

            if (!$result['success']) {
                $errorMsg = is_array($result['error'] ?? null) 
                    ? ($result['error']['errors'][0]['detail'] ?? 'PayMongo session creation failed.')
                    : ($result['error'] ?? 'Unable to connect to PayMongo.');
                
                Log::error("PayMongo Checkout Error for Booking #{$booking->booking_number}: {$errorMsg}");
                return back()->with('error', "Payment gateway error: {$errorMsg}");
            }

            $checkoutUrl = $result['checkout_url'];
            $checkoutId = $result['checkout_id'];

            // 3. Save or update the pending payment
            DB::transaction(function () use ($booking, $amount, $checkoutId, $result) {
                Payment::updateOrCreate(
                    [
                        'booking_id' => $booking->id,
                        'payment_type' => 'downpayment',
                        'status' => 'pending',
                    ],
                    [
                        'payment_method' => 'paymongo',
                        'transaction_id' => 'TXN-' . strtoupper(bin2hex(random_bytes(4))),
                        'paymongo_resource_id' => $checkoutId,
                        'amount' => $amount,
                        'fee_amount' => 0.00,
                        'net_amount' => $amount,
                        'expires_at' => now()->addHours(24),
                    ]
                );

                AuditLogger::log(
                    'PAYMONGO_CHECKOUT_INITIATED',
                    "Initiated PayMongo checkout for Booking #{$booking->booking_number} (Amount: ₱" . number_format($amount, 2) . ")",
                    null,
                    "Contact: {$booking->contact_name}"
                );
            });

            // 4. Return the result
            if ($request->wantsJson() || $request->ajax()) {
                return response()->json([
                    'success' => true,
                    'checkout_url' => $checkoutUrl,
                    'checkout_id' => $checkoutId,
                ]);
            }

            return redirect()->away($checkoutUrl);

        } catch (Exception $e) {
            Log::error("PayMongo Checkout Exception for Booking #{$booking->booking_number}: " . $e->getMessage());
            return back()->with('error', 'An error occurred while launching the payment gateway. Please try again.');
        }
    }

    /**
     * Participant comes back after paying.
     *
     * GET /booking/{booking}/paymongo/success (signed URL, see PayMongoGateway::signedReturnUrl)
     *
     * The signed link proves the visitor came back from this booking's checkout, but not
     * that they paid. The booking is only confirmed when PayMongo reports a paid payment
     * (or the webhook already recorded it); otherwise the webhook confirms it later.
     */
    public function success(Request $request, Booking $booking): RedirectResponse
    {
        // Log the participant in to the Manage Booking page (no PIN in the URL)
        session([
            'auth_booking_id' => $booking->id,
            'auth_booking_pin' => $booking->pin,
        ]);

        $payment = Payment::where('booking_id', $booking->id)
            ->where('payment_type', 'downpayment')
            ->latest('id')
            ->first();

        // Ask PayMongo only while our payment row is still waiting; a missing key never counts as paid (any environment)
        if ($payment && $payment->status === 'pending' && $payment->paymongo_resource_id) {
            $paid = app(\App\Services\PayMongoService::class)->paidPaymentForCheckout($payment->paymongo_resource_id);
            if ($paid !== null) {
                try {
                    app(LegacyPaymentConfirmer::class)->apply($booking, $paid['id'] ?? null, $paid['attributes'] ?? [], $payment->paymongo_resource_id, 'return');
                } catch (CapacityException) {
                    // Busy right now: the webhook (or a reload) applies it; nothing was written
                } catch (Exception $e) {
                    Log::error("PayMongo return could not apply the payment for Booking #{$booking->booking_number}.", ['exception' => class_basename($e)]);
                }
            }
        }

        $booking->refresh();
        $manage = fn (string $message) => redirect()->route('manage.show', ['booking_number' => $booking->booking_number])->with('info', $message);

        return match (true) {
            CapacityCoordinator::takesPlace($booking->status) => redirect()->route('booking.create', ['confirmed' => $booking->booking_number])
                ->with('success', 'Your downpayment has been received via PayMongo and your booking is confirmed. A confirmation email is on its way.'),
            $booking->status === LegacyPaymentConfirmer::PAID_UNALLOCATED => $manage("We've received your payment, but we couldn't confirm your reservation for the selected dates. Our team will review your booking and contact you about the available next steps. Please keep your payment reference for your records. You do not need to pay again."),
            in_array($booking->status, ['pending_downpayment', 'pending'], true) => $manage("We're still waiting for PayMongo to confirm your payment. We'll e-mail you once your booking is confirmed."),
            default => $manage('This booking is not active. If you paid, our team has your payment on record and will contact you.'),
        };
    }

    /**
     * Participant cancelled the checkout.
     *
     * GET /booking/{booking}/paymongo/cancel (signed URL, see PayMongoGateway::signedReturnUrl)
     */
    public function cancel(Request $request, Booking $booking): RedirectResponse
    {
        session([
            'auth_booking_id' => $booking->id,
            'auth_booking_pin' => $booking->pin,
        ]);

        return redirect()->route('manage.show', ['booking_number' => $booking->booking_number])->with('error','Payment checkout was cancelled. You can retry paying your reservation downpayment anytime.');
    }

    /**
     * PayMongo webhook.
     *
     * POST /api/webhooks/paymongo
     */
    public function webhook(Request $request): JsonResponse
    {
        $payload = $request->getContent();
        $signatureHeader = $request->header('Paymongo-Signature') 
            ?? $request->header('paymongo-signature') 
            ?? '';

        $processed = $this->gateway->processWebhook($payload, $signatureHeader);

        if (!$processed['verified']) {
            return response()->json(['error' => 'Invalid signature.'], 400);
        }

        $eventType = $processed['event_type'];
        $eventData = $processed['data'];

        // Checkout-intent events go to their own handler before the de-duplication below: their processing is
        // idempotent, and a "retry later" answer must reach PayMongo's next delivery instead of being dropped as
        // a duplicate. Events with booking_id never match, so legacy bookings are handled exactly as before.
        if (is_array($eventData) && ($intentLink = CheckoutWebhookHandler::intentMetadata($eventData)) !== null) {
            [$status, $body] = app(CheckoutWebhookHandler::class)->handle((string) $eventType, $eventData, $intentLink, $processed['raw']['data']['id'] ?? null);

            return response()->json($body, $status);
        }

        // PayMongo can send the same event more than once, and retries an event we could not process. Each event is
        // recorded durably and claimed before processing; it is marked processed only after it succeeded, so a
        // failure is retried on the next delivery and a processed event (or a replay of it) is never applied twice.
        $eventId = $processed['raw']['data']['id'] ?? ($eventData['id'] ?? null);
        $eventKey = is_string($eventId) && $eventId !== '' ? $eventId : 'payload:' . hash('sha256', $payload);
        $event = $this->claimWebhookEvent($eventKey, (string) $eventType);

        if ($event === 'processed') {
            Log::info('PayMongo Webhook: duplicate event delivery ignored.', ['event' => $eventType]);
            return response()->json(['received' => true, 'event' => $eventType, 'status' => 'duplicate_ignored'], 200);
        }
        if ($event === 'busy') {
            return response()->json(['error' => 'Event is being processed; retry later.'], 503);
        }

        Log::info("PayMongo Webhook Event Received: {$eventType}", ['event' => $eventType, 'event_id' => $eventId]);

        try {
            switch ($eventType) {
                case 'checkout_session.payment.paid':
                case 'payment.paid':
                    $this->handlePaymentPaidEvent($eventData);
                    break;

                case 'payment.failed':
                    $this->handlePaymentFailedEvent($eventData);
                    break;

                case 'refund.created':
                case 'refund.updated':
                    $this->handleRefundEvent($eventData);
                    break;

                default:
                    Log::info("Unhandled PayMongo Webhook Event Type: {$eventType}");
                    break;
            }

            PaymongoWebhookEvent::where('event_id', $eventKey)->update(['status' => 'processed', 'processed_at' => now(), 'last_error' => null]);

            return response()->json(['received' => true, 'event' => $eventType], 200);

        } catch (\Throwable $e) {
            PaymongoWebhookEvent::where('event_id', $eventKey)->update(['status' => 'failed', 'last_error' => substr(class_basename($e), 0, 120)]);
            Log::error('PayMongo Webhook Processing Exception; PayMongo will retry it.', ['event' => $eventType, 'exception' => class_basename($e)]);
            return response()->json(['error' => 'Webhook processing error.'], 500);
        }
    }

    /**
     * Record the event (first delivery) and claim it for processing.
     * Returns "claimed", "processed" (already done: a duplicate or a replay) or "busy" (another delivery is on it).
     * A claim older than 5 minutes is a crashed request and can be taken over.
     */
    private function claimWebhookEvent(string $eventKey, string $eventType): string
    {
        try {
            PaymongoWebhookEvent::firstOrCreate(['event_id' => $eventKey], ['event_type' => substr($eventType, 0, 64), 'status' => 'received']);
        } catch (\Illuminate\Database\QueryException) {
            // Concurrent first delivery created it: fall through to the claim
        }

        $claimed = PaymongoWebhookEvent::where('event_id', $eventKey)
            ->where(fn ($q) => $q->whereIn('status', ['received', 'failed'])
                ->orWhere(fn ($s) => $s->where('status', 'processing')->where('updated_at', '<', now()->subMinutes(5))))
            ->update(['status' => 'processing', 'attempts' => DB::raw('attempts + 1'), 'updated_at' => now()]);
        if ($claimed === 1) {
            return 'claimed';
        }

        return PaymongoWebhookEvent::where('event_id', $eventKey)->value('status') === 'processed' ? 'processed' : 'busy';
    }

    /**
     * 'payment.paid' and 'checkout_session.payment.paid' for a legacy booking (signed). Applied by
     * LegacyPaymentConfirmer, exactly like the return page: verified payment, capacity re-checked under the date lock,
     * a full date or a cancelled booking keeps the payment for staff review, and nothing is ever applied twice.
     * A busy date throws, so the event is retried by PayMongo.
     */
    protected function handlePaymentPaidEvent(array $eventData): void
    {
        $attributes = $eventData['attributes'] ?? [];
        $metadata = $attributes['metadata'] ?? $attributes['payments'][0]['attributes']['metadata'] ?? [];
        $bookingId = $metadata['booking_id'] ?? null;
        $bookingNumber = $metadata['booking_number'] ?? null;

        $booking = $bookingId ? Booking::find($bookingId) : ($bookingNumber ? Booking::where('booking_number', $bookingNumber)->first() : null);
        if (! $booking) {
            Log::warning('PayMongo Webhook: Booking not found for paid event.');
            return;
        }

        // checkout_session.payment.paid carries the payment in payments[0]; payment.paid is the payment itself
        $paymentObject = $attributes['payments'][0] ?? null;
        $paymentId = $paymentObject['id'] ?? ($eventData['id'] ?? null);
        $paymentAttributes = $paymentObject['attributes'] ?? $attributes;
        $sessionId = $paymentObject ? ($eventData['id'] ?? null) : null;
        $eventLivemode = is_bool($attributes['livemode'] ?? null) ? $attributes['livemode'] : null;

        app(LegacyPaymentConfirmer::class)->apply($booking, is_string($paymentId) ? $paymentId : null, $paymentAttributes, $sessionId, 'webhook',
            $eventLivemode, isset($attributes['payment_method_type']) ? (string) $attributes['payment_method_type'] : null);
    }

    /**
     * Handle 'payment.failed'.
     */
    protected function handlePaymentFailedEvent(array $eventData): void
    {
        $attributes = $eventData['attributes'] ?? [];
        $metadata = $attributes['metadata'] ?? [];
        $bookingId = $metadata['booking_id'] ?? null;

        if ($bookingId) {
            $payment = Payment::where('booking_id', $bookingId)
                ->where('status', 'pending')
                ->latest()
                ->first();

            if ($payment) {
                $payment->update(['status' => 'failed']);
            }
        }
    }

    /**
     * refund.created / refund.updated (signed). Updates the refund ledger by the refund's own status; never marks a
     * payment "refunded" or overwrites its amounts (that hid receipts and turned partial refunds into full ones).
     *  - matched by PayMongo refund id, else by the operation id in its notes ("op:<uuid>", our submission whose
     *    answer was lost), on the same payment and for the same amount;
     *  - pending -> provider_pending, succeeded -> succeeded, failed -> failed; a finished attempt is never changed;
     *  - a refund we did not send (e.g. made in the PayMongo dashboard) is recorded as an "external" ledger entry so
     *    it counts (once: the PayMongo refund id is unique).
     */
    protected function handleRefundEvent(array $eventData): void
    {
        $attributes = $eventData['attributes'] ?? [];
        $paymentId = $attributes['payment_id'] ?? null;
        $refundId = $eventData['id'] ?? null;
        $amount = $attributes['amount'] ?? null;
        $mapped = match ($attributes['status'] ?? null) {
            'succeeded' => RefundAttempt::SUCCEEDED,
            'pending', 'processing' => RefundAttempt::PROVIDER_PENDING,
            'failed' => RefundAttempt::FAILED,
            default => null,
        };
        $expectedMode = app(\App\Services\PayMongoService::class)->configuredLivemode();

        if (! is_string($refundId) || ! str_starts_with($refundId, 'ref_') || ! is_string($paymentId) || ! is_int($amount) || $amount <= 0
            || $mapped === null || ($attributes['currency'] ?? 'PHP') !== 'PHP'
            || (isset($attributes['livemode']) && $attributes['livemode'] !== $expectedMode)) {
            AuditLogger::log('PAYMONGO_REFUND_EVENT_REJECTED', 'Refund event ' . (is_string($refundId) ? $refundId : '?') . ' not applied: incomplete, unexpected status, currency or mode.', null, 'PayMongo Webhook');
            return;
        }

        $payment = Payment::where('paymongo_payment_id', $paymentId)->first();
        if (! $payment) {
            AuditLogger::log('PAYMONGO_REFUND_EVENT_UNMATCHED', "Refund {$refundId} is for a payment not on record ({$paymentId}).", null, 'PayMongo Webhook');
            return;
        }

        $case = DB::transaction(function () use ($refundId, $payment, $amount, $mapped, $attributes) {
            $attempt = RefundAttempt::where('provider_refund_id', $refundId)->lockForUpdate()->first();
            if (! $attempt && preg_match('/op:([0-9a-f-]{36})/', (string) ($attributes['notes'] ?? ''), $m)) {
                $attempt = RefundAttempt::where('operation_id', $m[1])->lockForUpdate()->first();
            }

            if (! $attempt) {
                RefundAttempt::create([
                    'payment_id' => $payment->id,
                    'operation_id' => (string) \Illuminate\Support\Str::uuid(),
                    'method' => 'external',
                    'amount_centavos' => $amount,
                    'currency' => 'PHP',
                    'status' => $mapped,
                    'provider_refund_id' => $refundId,
                    'provider_status' => (string) $attributes['status'],
                    'resolution_source' => RefundAttempt::SOURCE_PROVIDER_WEBHOOK,
                    'resolved_at' => in_array($mapped, [RefundAttempt::SUCCEEDED, RefundAttempt::FAILED], true) ? now() : null,
                    'evidence_note' => 'Recorded from a PayMongo refund event; not sent from this system.',
                ]);
                AuditLogger::log('PAYMONGO_REFUND_EVENT_EXTERNAL', "Refund {$refundId} (₱" . number_format($amount / 100, 2) . ", {$attributes['status']}) on payment #{$payment->id} was not sent from this system; recorded in the refund ledger.", null, 'PayMongo Webhook');

                return null;
            }

            if ($attempt->payment_id !== $payment->id || $attempt->amount_centavos !== $amount) {
                AuditLogger::log('PAYMONGO_REFUND_EVENT_MISMATCH', "Refund {$refundId} does not match refund operation {$attempt->operation_id} (payment or amount differs); not applied.", null, 'PayMongo Webhook');
                return null;
            }
            if (in_array($attempt->status, [RefundAttempt::SUCCEEDED, RefundAttempt::FAILED, RefundAttempt::OFFLINE_CONFIRMED], true)) {
                if ($attempt->status !== $mapped) {
                    AuditLogger::log('PAYMONGO_REFUND_EVENT_CONFLICT', "Refund {$refundId} reported {$attributes['status']} but operation {$attempt->operation_id} is already {$attempt->status}; not changed.", null, 'PayMongo Webhook');
                }
                return null;
            }

            $attempt->update([
                'status' => $mapped,
                'provider_refund_id' => $refundId,
                'provider_status' => (string) $attributes['status'],
                'resolution_source' => RefundAttempt::SOURCE_PROVIDER_WEBHOOK,
                'resolved_at' => in_array($mapped, [RefundAttempt::SUCCEEDED, RefundAttempt::FAILED], true) ? now() : null,
            ]);

            return $attempt->bookingRefund;
        });

        if ($case) {
            app(RefundExecutor::class)->refreshCompletion($case);
        }
    }
}
