<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\CheckoutNotification;
use App\Models\Payment;
use App\Models\PaymentException;
use App\Models\PaymentExceptionChoice;
use App\Models\PaymentExceptionEvent;
use App\Models\PaymentExceptionRefundReview;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Checkout\CheckoutNotifier;
use App\Services\Payment\ExceptionResolutionService;
use App\Services\Payment\GuestChoiceService;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use RuntimeException;

/**
 * Owner/admin queue of received money that could not simply be applied to a booking (Batch 3-II A), so it never
 * disappears from view: legacy bookings paid after their date filled up (paid_unallocated), payments for cancelled
 * bookings, different amounts or repeat payments, and legacy confirmations that could not be delivered.
 * Batch 3-II B1: each such receipt has a payment exception record (also paid checkouts that got no place, which
 * have no booking at all). While an exception is open its money is held, and the booking cannot be placed, moved or
 * restored by a status change.
 * Batch 3-II B2.2: staff record the guest's choice with text-only evidence, a different owner or admin verifies it,
 * and a verified choice is routed to owner refund review or allocated on its date. Owner and admin only (route
 * middleware, re-checked under lock by the services). Evidence travels only in POST bodies, is never flashed back,
 * logged or sent, and every view of it is audited. Nothing here moves money or messages the guest.
 */
class PaymentExceptionController extends Controller
{
    /** Free-text fields never flashed back to the session (a refused text may hold the very data it was refused for). */
    private const FREE_TEXT = ['evidence_summary', 'evidence_reference', 'verification_note', 'reason'];

    public function __construct(protected GuestChoiceService $choices, protected ExceptionResolutionService $resolutions)
    {
    }

    public function index(): View
    {
        return view('admin.payments.exceptions', [
            'exceptions' => PaymentException::with(['primaryPayment', 'booking', 'checkoutIntent', 'events'])
                ->orderByRaw("CASE WHEN status = 'open' THEN 0 ELSE 1 END")->latest('id')->get(),
            'unallocated' => Booking::where('status', 'paid_unallocated')->withCount('participants')->with('payments')->orderBy('start_date')->get(),
            'flagged' => Payment::awaitingReview()->with('booking')->latest('review_flagged_at')->get(),
            'failedConfirmations' => CheckoutNotification::whereNotNull('booking_id')->where('status', CheckoutNotification::FAILED)->with('booking')->latest('id')->get(),
        ]);
    }

    /** Choices waiting for independent verification or for action, and the unresolved money. No evidence shown here. */
    public function queue(): View
    {
        $open = PaymentException::whereIn('status', PaymentException::UNRESOLVED_STATUSES)->get(['id', 'kind', 'amount_centavos', 'opened_at']);

        return view('admin.payments.exception_queue', [
            'awaitingVerification' => PaymentExceptionChoice::where('status', PaymentExceptionChoice::RECORDED)->with(['paymentException', 'recorder'])->orderBy('recorded_at')->get(),
            'awaitingAction' => PaymentExceptionChoice::where('status', PaymentExceptionChoice::VERIFIED)->with(['paymentException', 'verifier'])->orderBy('verified_at')->get(),
            'reviews' => PaymentExceptionRefundReview::where('status', PaymentExceptionRefundReview::AWAITING_OWNER_REVIEW)->with('paymentException')->orderBy('routed_at')->get(),
            'unresolved' => $open->groupBy('kind')->map(fn ($rows) => [
                'count' => $rows->count(), 'centavos' => $rows->sum('amount_centavos'), 'oldest' => $rows->min('opened_at'),
            ]),
        ]);
    }

    /** Exception detail with the guest-choice evidence; each view is audited (who, which exception; never the content). */
    public function show(Request $request, PaymentException $paymentException): View
    {
        $history = $this->choices->history($paymentException);
        $staff = $request->user();
        PaymentExceptionEvent::create(['payment_exception_id' => $paymentException->id, 'type' => PaymentExceptionEvent::EVIDENCE_VIEWED, 'actor_id' => $staff->id,
            'details' => ['choice_ids' => $history['choices']->pluck('id')->all()]]);
        AuditLogger::log('PAYMENT_EXCEPTION_EVIDENCE_VIEWED', "Payment exception #{$paymentException->id}: guest-choice evidence viewed.", $staff, null, $request);

        return view('admin.payments.exception_show', [
            'exception' => $paymentException->load(['primaryPayment', 'booking', 'checkoutIntent', 'events', 'refundReview', 'allocationAttempts']),
            'choices' => $history['choices'],
            'names' => User::whereIn('id', $history['choices']->flatMap(fn ($c) => [$c->recorded_by, $c->verified_by, $c->closed_by, $c->acted_by])->filter()->unique())
                ->pluck('name', 'id'),
            'current' => $this->choices->current($paymentException),
            'staff' => $staff,
            'redacted' => (bool) config('checkout.guest_choice_evidence.redaction_enabled'),
        ]);
    }

    public function record(Request $request, PaymentException $paymentException): RedirectResponse
    {
        return $this->attempt($request, $paymentException, fn () => $this->choices->record($paymentException, $request->user(), $this->evidence($request)),
            'Guest choice recorded. A different owner or admin must verify it before anything is done with it.');
    }

    public function supersede(Request $request, PaymentException $paymentException, PaymentExceptionChoice $choice): RedirectResponse
    {
        $this->owned($paymentException, $choice);
        return $this->attempt($request, $paymentException, fn () => $this->choices->supersede($choice, $request->user(),
            $this->evidence($request), (string) $request->input('reason'), $this->version($request)),
            'The guest\'s new choice was recorded and the earlier one kept as superseded. The new choice needs its own verification.');
    }

    public function verify(Request $request, PaymentException $paymentException, PaymentExceptionChoice $choice): RedirectResponse
    {
        $this->owned($paymentException, $choice);
        return $this->attempt($request, $paymentException, fn () => $this->choices->verify($choice, $request->user(),
            (string) $request->input('verification_note'), $this->version($request)), 'Guest choice verified. Nothing was allocated or refunded.');
    }

    public function reject(Request $request, PaymentException $paymentException, PaymentExceptionChoice $choice): RedirectResponse
    {
        $this->owned($paymentException, $choice);
        return $this->attempt($request, $paymentException, fn () => $this->choices->reject($choice, $request->user(),
            (string) $request->input('reason'), $this->version($request)), 'Guest choice rejected and kept on record.');
    }

    public function withdraw(Request $request, PaymentException $paymentException, PaymentExceptionChoice $choice): RedirectResponse
    {
        $this->owned($paymentException, $choice);
        return $this->attempt($request, $paymentException, fn () => $this->choices->withdraw($choice, $request->user(),
            (string) $request->input('reason'), $this->version($request)), 'Guest choice withdrawn and kept on record.');
    }

    public function routeRefundReview(Request $request, PaymentException $paymentException, PaymentExceptionChoice $choice): RedirectResponse
    {
        $this->owned($paymentException, $choice);
        return $this->attempt($request, $paymentException, fn () => $this->resolutions->routeToRefundReview($choice, $request->user(), $this->version($request)),
            'Sent to owner refund review. The money stays held; no amount was set and nothing was refunded.');
    }

    public function allocate(Request $request, PaymentException $paymentException, PaymentExceptionChoice $choice): RedirectResponse
    {
        $this->owned($paymentException, $choice);
        try {
            $attempt = $this->resolutions->allocateAlternativeDate($choice, $request->user(), $this->version($request));
        } catch (RuntimeException|QueryException $e) {
            return $this->refusal($paymentException, $e);
        }

        return redirect()->to(portal_route('payments.exceptions.show', $paymentException))->with($attempt->outcome === 'allocated' ? 'success' : 'error', match ($attempt->outcome) {
            'allocated' => 'Placed on the guest\'s verified date at the original price and terms; the exception is resolved. No message was sent.',
            'failed' => "Not allocated ({$attempt->reason}): the verified date cannot be given. A new date needs the guest's new choice and an independent verification.",
            default => "Not allocated ({$attempt->reason}): kept for staff review. The guest's verified choice stays on record.",
        });
    }

    public function resend(Request $request, Booking $booking, CheckoutNotifier $notifier): RedirectResponse
    {
        $count = $notifier->retryFailedForBooking($booking);
        AuditLogger::log('BOOKING_NOTIFICATION_RETRY', "Booking {$booking->booking_number}: {$count} failed confirmation(s) queued again by staff.", $request->user(), null, $request);

        return back()->with('success', "{$count} message(s) queued again. They are sent only while the booking is confirmed.");
    }

    private function attempt(Request $request, PaymentException $exception, callable $action, string $success): RedirectResponse
    {
        try {
            $action();
        } catch (RuntimeException|QueryException $e) {
            return $this->refusal($exception, $e)->withInput($request->except(self::FREE_TEXT));
        }

        return redirect()->to(portal_route('payments.exceptions.show', $exception))->with('success', $success);
    }

    /** Service refusals never repeat the evidence; database refusals are reduced to their code (bindings carry it). */
    private function refusal(PaymentException $exception, RuntimeException|QueryException $e): RedirectResponse
    {
        $message = $e->getMessage();
        if ($e instanceof QueryException) {
            Log::warning('Payment exception action refused by the database.', ['payment_exception_id' => $exception->id, 'sqlstate' => $e->errorInfo[0] ?? null]);
            $message = 'The database refused this change (it was changed meanwhile or is not allowed). Reload the page; nothing was changed.';
        }

        return redirect()->to(portal_route('payments.exceptions.show', $exception))->with('error', $message);
    }

    /** Outside the actions' try blocks: a 404 is a RuntimeException too. */
    private function owned(PaymentException $exception, PaymentExceptionChoice $choice): void
    {
        abort_unless((int) $choice->payment_exception_id === (int) $exception->id, 404);
    }

    /** The lock_version the form was rendered with; anything else is stale. */
    private function version(Request $request): int
    {
        $value = $request->input('lock_version');
        if (! is_string($value) || ! ctype_digit($value)) {
            throw new RuntimeException('The form is missing its version. Reload the page and try again; nothing was changed.');
        }

        return (int) $value;
    }

    private function evidence(Request $request): array
    {
        return $request->only(['choice', 'requested_start_date', 'requested_end_date', 'evidence_channel', 'evidence_at', 'evidence_reference',
            'evidence_summary', 'contact_match_method', 'contact_match_outcome']);
    }
}
