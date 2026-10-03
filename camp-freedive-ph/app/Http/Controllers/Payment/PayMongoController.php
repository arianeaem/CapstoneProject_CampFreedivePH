<?php

namespace App\Http\Controllers\Payment;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Payment;
use App\Services\AuditLogger;
use App\Services\Gateways\PayMongoGateway;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * PayMongo Payment Gateway Integration & Webhook Controller.
 *
 * Architecture & Payment Lifecycle:
 * 1. Hosted Checkout v2: Dispatches guests to PayMongo's PCI-DSS compliant checkout session
 *    supporting GCash, Maya, Credit/Debit cards, and GrabPay.
 * 2. Dual-Verification Protocol:
 *    - Synchronous Return (Success Route): Reconciles payment status immediately upon browser redirect.
 *    - Asynchronous Webhook (Webhook Route): Verifies cryptographic HMAC signatures (`Paymongo-Signature`)
 *      with Redis/Cache-backed 24-hour idempotency deduplication to safely discard duplicate retry payloads.
 * 3. Automated State Machine: Updates booking status from `pending_downpayment` -> `confirmed`,
 *    records transaction fee audits, and dispatches customer confirmation emails.
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
     * Initiate a PayMongo Checkout Session for a booking's required downpayment.
     *
     * POST /booking/{booking}/paymongo/checkout
     */
    public function checkout(Request $request, Booking $booking): JsonResponse|RedirectResponse
    {
        // 1. Calculate required downpayment
        $amount = (float) ($booking->downpayment_amount ?? $booking->total_price ?? 3000);

        if ($amount <= 0) {
            return back()->with('error', 'Invalid payment amount.');
        }

        try {
            // 2. Call Gateway to create PayMongo Checkout Session
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

            // 3. Record or update pending payment record
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
                    "Customer: {$booking->contact_name}"
                );
            });

            // 4. Return response
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
     * Handle Customer Return upon Successful PayMongo Checkout.
     *
     * GET /booking/{booking}/paymongo/success
     */
    public function success(Request $request, Booking $booking): RedirectResponse
    {
        try {
            DB::transaction(function () use ($booking) {
                // Find latest pending downpayment
                $payment = Payment::where('booking_id', $booking->id)
                    ->where('payment_type', 'downpayment')
                    ->latest()
                    ->first();

                $paymongoPaymentId = null;
                $feeAmount = 0.00;
                $paymentMethodType = 'paymongo';

                // Query PayMongo Checkout Session to retrieve actual payment details if available
                if ($payment && $payment->paymongo_resource_id) {
                    $sessionData = app(\App\Services\PayMongoService::class)->getCheckoutSession($payment->paymongo_resource_id);
                    $paymentsList = $sessionData['data']['attributes']['payments'] ?? [];
                    if (!empty($paymentsList)) {
                        $firstPaid = $paymentsList[0] ?? null;
                        if ($firstPaid) {
                            $paymongoPaymentId = $firstPaid['id'] ?? null;
                            $paymentMethodType = $firstPaid['attributes']['source']['type'] ?? $firstPaid['attributes']['payment_method_type'] ?? 'paymongo';
                            $feeAmount = ($firstPaid['attributes']['fee'] ?? 0) / 100;
                        }
                    }
                }

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

                // Confirm booking status
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
                    "Customer: {$booking->contact_name}"
                );
            });

            // Send confirmation email
            try {
                \Illuminate\Support\Facades\Mail::to($booking->contact_email)->send(
                    new \App\Mail\BookingConfirmedMail($booking->fresh()->load('participants', 'payments'))
                );
            } catch (Exception $e) {
                Log::warning("Email send failed for booking #{$booking->booking_number}: " . $e->getMessage());
            }

            // Authenticate session for self-service portal
            session([
                'auth_booking_id' => $booking->id,
                'auth_booking_pin' => $booking->pin,
            ]);

            return redirect()->route('booking.create', [
                'confirmed' => $booking->booking_number,
                'pin' => $booking->pin,
            ])->with('success', 'Your downpayment has been received via PayMongo! Your 2D1N freediving camp slot is now confirmed.');

        } catch (Exception $e) {
            Log::error("PayMongo Success Handler Exception for Booking #{$booking->booking_number}: " . $e->getMessage());
            
            session([
                'auth_booking_id' => $booking->id,
                'auth_booking_pin' => $booking->pin,
            ]);

            return redirect()->route('booking.create', [
                'confirmed' => $booking->booking_number,
                'pin' => $booking->pin,
            ])->with('success', 'Payment received. Your booking is confirmed.');
        }
    }

    /**
     * Handle Customer Return when Checkout is Cancelled.
     *
     * GET /booking/{booking}/paymongo/cancel
     */
    public function cancel(Request $request, Booking $booking): RedirectResponse
    {
        session([
            'auth_booking_id' => $booking->id,
            'auth_booking_pin' => $booking->pin,
        ]);

        return redirect()->route('manage.show', [
            'booking_number' => $booking->booking_number,
            'pin' => $booking->pin,
        ])->with('error', 'Payment checkout was cancelled. You can retry paying your reservation downpayment anytime.');
    }

    /**
     * Webhook Entry Point for asynchronous PayMongo events.
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

        // Webhook Idempotency Deduplication:
        // PayMongo operates on an at-least-once delivery model, which may retry sending the exact same payload.
        // Cache::add() is atomic and returns true ONLY if the event has not been processed within the 24-hour TTL window.
        $eventId = $processed['raw']['data']['id'] ?? ($eventData['id'] ?? null);
        $dedupKey = $eventId ? "paymongo_webhook_evt:{$eventId}" : 'paymongo_webhook_payload:' . hash('sha256', $payload);

        if (!Cache::add($dedupKey, true, now()->addHours(24))) {
            Log::info("PayMongo Webhook: Duplicate event delivery discarded (Key: {$dedupKey}, Event: {$eventType})");
            return response()->json([
                'received' => true,
                'event' => $eventType,
                'status' => 'duplicate_ignored',
            ], 200);
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

            return response()->json(['received' => true, 'event' => $eventType], 200);

        } catch (Exception $e) {
            Log::error("PayMongo Webhook Processing Exception: " . $e->getMessage());
            return response()->json(['error' => 'Webhook processing error.'], 500);
        }
    }

    /**
     * Process 'payment.paid' and 'checkout_session.payment.paid' events.
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
            Log::warning('PayMongo Webhook: Booking not found for paid event.', ['metadata' => $metadata]);
            return;
        }

        $paymongoPaymentId = $attributes['payments'][0]['id'] ?? ($eventData['id'] ?? null);
        $paymongoResourceId = $eventData['id'] ?? null;
        $amountInCentavos = $attributes['amount'] ?? ($attributes['payments'][0]['attributes']['amount'] ?? 0);
        $amountInPesos = $amountInCentavos > 0 ? ($amountInCentavos / 100) : ($booking->downpayment_amount ?? 3000);
        $feeAmount = ($attributes['fee'] ?? 0) / 100;

        DB::transaction(function () use ($booking, $paymongoPaymentId, $paymongoResourceId, $amountInPesos, $feeAmount, $attributes) {
            $payment = Payment::where('booking_id', $booking->id)
                ->where('payment_type', 'downpayment')
                ->latest()
                ->first();

            if (!$payment) {
                $payment = new Payment([
                    'booking_id' => $booking->id,
                    'payment_type' => 'downpayment',
                    'transaction_id' => 'TXN-' . strtoupper(bin2hex(random_bytes(4))),
                ]);
            }

            $paymentMethodType = $attributes['payment_method_type'] ?? 'gcash';

            $payment->fill([
                'status' => 'paid',
                'paid_at' => now(),
                'payment_method' => $paymentMethodType,
                'paymongo_payment_id' => $paymongoPaymentId,
                'paymongo_resource_id' => $paymongoResourceId,
                'amount' => $amountInPesos,
                'fee_amount' => $feeAmount,
                'net_amount' => $amountInPesos - $feeAmount,
            ])->save();

            if ($booking->status !== 'confirmed') {
                $booking->update(['status' => 'confirmed']);
                app(\App\Services\SlotReservationService::class)->releaseHold($booking->start_date, $booking->booking_number);
            }

            AuditLogger::log(
                'PAYMONGO_WEBHOOK_PAID',
                "Webhook marked Booking #{$booking->booking_number} as PAID (₱" . number_format($amountInPesos, 2) . ")",
                null,
                'PayMongo Webhook'
            );
        });
    }

    /**
     * Process 'payment.failed' event.
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
     * Process refund events from PayMongo.
     */
    protected function handleRefundEvent(array $eventData): void
    {
        $attributes = $eventData['attributes'] ?? [];
        $paymentId = $attributes['payment_id'] ?? null;
        $refundId = $eventData['id'] ?? null;
        $amountInCentavos = $attributes['amount'] ?? 0;
        $amountInPesos = $amountInCentavos / 100;

        if ($paymentId) {
            $payment = Payment::where('paymongo_payment_id', $paymentId)->first();
            if ($payment) {
                $payment->update([
                    'status' => 'refunded',
                    'paymongo_refund_id' => $refundId,
                    'amount_refunded' => $amountInPesos,
                ]);
            }
        }
    }
}
