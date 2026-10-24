<?php

namespace App\Http\Controllers\Payment;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\PaymongoWebhookEvent;
use App\Models\RefundAttempt;
use App\Services\AuditLogger;
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
 * - sends the participant to the PayMongo checkout page (GCash, Maya, cards, GrabPay)
 * - when the participant comes back on the success page we check the payment right away
 * - the webhook also confirms the payment (signature is checked, and the same event
 *   is ignored if it comes again within 24 hours)
 * - when paid: booking goes from pending_downpayment to confirmed and the email is sent
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
            ->latest()
            ->first();

        $paymongo = app(\App\Services\PayMongoService::class);
        $paidPayment = null;

        if ($payment && $payment->status === 'paid') {
            $verified = true; // the webhook got here first
        } elseif ($payment && $payment->paymongo_resource_id && $paymongo->isSimulated()) {
            $verified = !app()->isProduction(); // simulated checkout without API keys (local/testing)
        } elseif ($payment && $payment->paymongo_resource_id) {
            $paidPayment = $paymongo->paidPaymentForCheckout($payment->paymongo_resource_id);
            $verified = $paidPayment !== null;
        } else {
            $verified = false;
        }

        if (!$verified) {
            Log::info("PayMongo success return without a confirmed payment for Booking #{$booking->booking_number}; waiting for the webhook.");

            return redirect()->route('manage.show', ['booking_number' => $booking->booking_number])
                ->with('info', "We're still waiting for PayMongo to confirm your payment. Your booking will be confirmed automatically and we'll e-mail you once it arrives.");
        }

        try {
            DB::transaction(function () use ($booking, $payment, $paidPayment) {
                $paymongoPaymentId = $paidPayment['id'] ?? null;
                $feeAmount = ($paidPayment['attributes']['fee'] ?? 0) / 100;
                $paymentMethodType = $paidPayment['attributes']['source']['type'] ?? $paidPayment['attributes']['payment_method_type'] ?? 'paymongo';

                if ($payment && $payment->status !== 'paid') {
                    $payment->update([
                        'status' => 'paid',
                        'paid_at' => now(),
                        'payment_method' => $paymentMethodType,
                        'paymongo_payment_id' => $paymongoPaymentId ?: ($payment->paymongo_payment_id ?: ('pay_' . bin2hex(random_bytes(8)))),
                        'fee_amount' => $feeAmount,
                    ]);

                    \App\Models\PaymentStatusLog::create([
                        'payment_id' => $payment->id,
                        'old_status' => 'pending',
                        'new_status' => 'paid',
                        'changed_by' => null,
                        'note' => "Downpayment paid successfully via PayMongo Hosted Checkout ({$paymentMethodType}).",
                        'created_at' => now(),
                    ]);
                }

                // Confirm the booking
                if ($booking->status === 'pending_downpayment' || $booking->status === 'pending') {
                    $booking->update([
                        'status' => 'confirmed',
                    ]);

                    app(\App\Services\SlotReservationService::class)->releaseHold($booking->start_date, $booking->booking_number);

                    \App\Models\BookingStatusLog::create([
                        'booking_id' => $booking->id,
                        'old_status' => 'pending_downpayment',
                        'new_status' => 'confirmed',
                        'changed_by' => null,
                        'note' => "Booking confirmed automatically upon successful PayMongo downpayment receipt.",
                        'created_at' => now(),
                    ]);
                }

                AuditLogger::log(
                    'PAYMONGO_PAYMENT_SUCCESS',
                    "Payment verified and confirmed for Booking #{$booking->booking_number} via PayMongo",
                    null,
                    "Contact: {$booking->contact_name}"
                );
            });

            // Send the confirmation email
            try {
                \Illuminate\Support\Facades\Mail::to($booking->contact_email)->send(
                    new \App\Mail\BookingConfirmedMail($booking->fresh()->load('participants', 'payments'))
                );
            } catch (Exception $e) {
                Log::warning("Email send failed for booking #{$booking->booking_number}: " . $e->getMessage());
            }

            return redirect()->route('booking.create', ['confirmed' => $booking->booking_number])
                ->with('success', 'Your downpayment has been received via PayMongo! Your 2D1N freediving camp slot is now confirmed.');

        } catch (Exception $e) {
            Log::error("PayMongo Success Handler Exception for Booking #{$booking->booking_number}: " . $e->getMessage());

            return redirect()->route('booking.create', ['confirmed' => $booking->booking_number])
                ->with('success', 'Payment received. Your booking is confirmed.');
        }
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
     * Handle 'payment.paid' and 'checkout_session.payment.paid'.
     */
    /**
     * Legacy booking paid (signed event). The payment evidence is checked and kept; the booking is confirmed only when
     * everything matches what it expected:
     *  - payment id ("pay_"), PHP, a positive integer amount, and the same mode (test/live) as our key;
     *  - a payment id already on record is never applied again or moved to another booking;
     *  - an existing paid payment row is never overwritten: a second real payment becomes its own row;
     *  - amount different from what the booking expects: recorded as received, booking not confirmed, flagged;
     *  - late payment on a cancelled booking: recorded as received, booking stays cancelled, flagged (no refund here).
     * Capacity is not re-checked here (unchanged legacy behaviour, Batch 3-II B-01).
     */
    protected function handlePaymentPaidEvent(array $eventData): void
    {
        $attributes = $eventData['attributes'] ?? [];
        $metadata = $attributes['metadata'] ?? $attributes['payments'][0]['attributes']['metadata'] ?? [];
        $bookingId = $metadata['booking_id'] ?? null;
        $bookingNumber = $metadata['booking_number'] ?? null;

        $booking = null;
        if ($bookingId) {
            $booking = Booking::find($bookingId);
        } elseif ($bookingNumber) {
            $booking = Booking::where('booking_number', $bookingNumber)->first();
        }

        if (!$booking) {
            Log::warning('PayMongo Webhook: Booking not found for paid event.');
            return;
        }

        // checkout_session.payment.paid carries the payment in payments[0]; payment.paid is the payment itself
        $paymentObject = $attributes['payments'][0] ?? null;
        $paymongoPaymentId = $paymentObject['id'] ?? ($eventData['id'] ?? null);
        $paymentAttributes = $paymentObject['attributes'] ?? $attributes;
        $sessionId = $paymentObject ? ($eventData['id'] ?? null) : null;
        $amountCentavos = $paymentAttributes['amount'] ?? null;
        $livemode = $paymentAttributes['livemode'] ?? ($attributes['livemode'] ?? null);
        $expectedMode = app(\App\Services\PayMongoService::class)->configuredLivemode();

        $problem = match (true) {
            ! is_string($paymongoPaymentId) || ! str_starts_with($paymongoPaymentId, 'pay_') => 'payment id missing',
            ! is_int($amountCentavos) || $amountCentavos <= 0 => 'amount missing',
            ($paymentAttributes['currency'] ?? 'PHP') !== 'PHP' => 'currency is not PHP',
            ! is_bool($livemode) || $expectedMode === null || $livemode !== $expectedMode => 'test/live mode does not match',
            isset($paymentAttributes['status']) && $paymentAttributes['status'] !== 'paid' => 'payment status is not paid',
            default => null,
        };
        if ($problem !== null) {
            AuditLogger::log('PAYMONGO_WEBHOOK_PAYMENT_REJECTED', "Paid event for Booking #{$booking->booking_number} not applied: {$problem}. Needs staff review.", null, 'PayMongo Webhook');
            return;
        }

        DB::transaction(function () use ($booking, $paymongoPaymentId, $sessionId, $amountCentavos, $paymentAttributes, $attributes) {
            $known = Payment::where('paymongo_payment_id', $paymongoPaymentId)->lockForUpdate()->first();
            if ($known) {
                if ($known->booking_id !== $booking->id) {
                    AuditLogger::log('PAYMONGO_WEBHOOK_PAYMENT_CONFLICT', "Payment {$paymongoPaymentId} is already recorded on another booking; event for Booking #{$booking->booking_number} not applied.", null, 'PayMongo Webhook');
                }
                return; // already recorded: nothing to do
            }

            // The pending downpayment row for this checkout (or any pending one); never a row that is already paid
            $payment = Payment::where('booking_id', $booking->id)->where('status', 'pending')
                ->orderByRaw('CASE WHEN paymongo_resource_id = ? THEN 0 ELSE 1 END', [(string) $sessionId])
                ->latest('id')->lockForUpdate()->first();
            $expectedCentavos = (int) round((float) ($payment?->amount ?? $booking->downpayment_amount) * 100);
            $amountPesos = round($amountCentavos / 100, 2);
            $feePesos = is_int($paymentAttributes['fee'] ?? null) ? round($paymentAttributes['fee'] / 100, 2) : 0.0;

            $payment ??= new Payment([
                'booking_id' => $booking->id,
                'payment_type' => 'downpayment',
                'transaction_id' => 'TXN-' . strtoupper(bin2hex(random_bytes(4))),
            ]);
            $payment->fill([
                'status' => 'paid',
                'paid_at' => now(),
                'payment_method' => (string) ($paymentAttributes['source']['type'] ?? $paymentAttributes['payment_method_type'] ?? $attributes['payment_method_type'] ?? 'paymongo'),
                'paymongo_payment_id' => $paymongoPaymentId,
                'paymongo_resource_id' => $payment->paymongo_resource_id ?? $sessionId,
                'amount' => $amountPesos,
                'fee_amount' => $feePesos,
                'net_amount' => round($amountPesos - $feePesos, 2),
            ])->save();

            if (str_starts_with($booking->status, 'cancelled')) {
                AuditLogger::log('PAYMONGO_WEBHOOK_LATE_PAYMENT', "Payment of ₱" . number_format($amountPesos, 2) . " received for cancelled Booking #{$booking->booking_number}; kept as received, booking not reinstated. Needs staff review.", null, 'PayMongo Webhook');
                return;
            }
            if ($amountCentavos !== $expectedCentavos) {
                AuditLogger::log('PAYMONGO_WEBHOOK_AMOUNT_MISMATCH', "Payment of ₱" . number_format($amountPesos, 2) . " received for Booking #{$booking->booking_number}, expected ₱" . number_format($expectedCentavos / 100, 2) . ". Recorded; booking not confirmed automatically. Needs staff review.", null, 'PayMongo Webhook');
                return;
            }

            if (in_array($booking->status, ['pending_downpayment', 'pending'], true)) {
                $booking->update(['status' => 'confirmed']);
                app(\App\Services\SlotReservationService::class)->releaseHold($booking->start_date, $booking->booking_number);
            }

            AuditLogger::log(
                'PAYMONGO_WEBHOOK_PAID',
                "Webhook marked Booking #{$booking->booking_number} as PAID (₱" . number_format($amountPesos, 2) . ")",
                null,
                'PayMongo Webhook'
            );
        });
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
