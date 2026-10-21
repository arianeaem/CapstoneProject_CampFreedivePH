<?php

namespace App\Http\Controllers\Payment;

use App\Http\Controllers\Controller;
use App\Models\CheckoutIntent;
use App\Services\Checkout\CheckoutPaymentProcessor;
use App\Services\Checkout\FinalizationResult;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Where PayMongo sends the customer back after a checkout-intent payment (signed, expiring, per-intent URLs).
 *
 * The return is never proof of payment: the page re-checks PayMongo and lets BookingFinalizer decide.
 * The cancel page changes nothing (a closed payment page is not a cancelled booking, and a verified payment
 * is never undone). Both work even if the checkout flag is turned off later, so started payments are not orphaned.
 * The booking PIN is never shown here; only data that does not unlock the booking is rendered.
 */
class CheckoutReturnController extends Controller
{
    public function __construct(protected CheckoutPaymentProcessor $processor)
    {
    }

    /** GET /checkout/{reference}/return (signed) */
    public function show(string $reference): Response
    {
        $intent = CheckoutIntent::where('reference', $reference)->firstOrFail();

        try {
            $outcome = $this->processor->process($intent, 'return')->outcome;
        } catch (Throwable $e) {
            Log::error('Checkout return could not be processed.', ['checkout_intent_id' => $intent->id, 'exception' => class_basename($e)]);
            $outcome = FinalizationResult::RETRYABLE;
        }

        return $this->statusPage($intent->fresh(), $outcome, false);
    }

    /** GET /checkout/{reference}/cancel (signed): read-only */
    public function cancel(string $reference): Response
    {
        $intent = CheckoutIntent::where('reference', $reference)->firstOrFail();

        $state = match ($intent->status) {
            CheckoutIntent::STATUS_FINALIZED => FinalizationResult::FINALIZED,
            CheckoutIntent::STATUS_PAID_UNALLOCATED => FinalizationResult::PAID_UNALLOCATED,
            CheckoutIntent::STATUS_PAYMENT_REVIEW => FinalizationResult::PAYMENT_REVIEW,
            CheckoutIntent::STATUS_PAID => FinalizationResult::RETRYABLE, // payment received, booking still being completed
            default => 'cancelled',
        };

        return $this->statusPage($intent, $state, true);
    }

    private function statusPage(CheckoutIntent $intent, string $state, bool $fromCancel): Response
    {
        $state = $state === FinalizationResult::ALREADY_FINALIZED ? FinalizationResult::FINALIZED : $state;
        $booking = $state === FinalizationResult::FINALIZED ? $intent->booking : null;

        $summary = $booking ? [
            'booking_number' => $booking->booking_number,
            'class' => $booking->formatted_class_type,
            'dates' => $booking->start_date->format('M d, Y') . ' – ' . $booking->end_date->format('M d, Y'),
            'pax' => $booking->participants()->count(),
            'paid' => (float) $booking->payments()->where('status', 'paid')->sum('amount'),
            'balance' => (float) $booking->balance_amount,
        ] : null;

        return response()
            ->view('checkout.status', [
                'state' => $state,
                'reference' => $intent->reference,
                'fromCancel' => $fromCancel,
                'summary' => $summary,
            ])
            // Signed links must not be cached or leaked through the Referer header
            ->header('Cache-Control', 'no-store, private')
            ->header('Referrer-Policy', 'no-referrer');
    }
}
