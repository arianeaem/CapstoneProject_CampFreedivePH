<?php

namespace App\Http\Controllers;

use App\Http\Requests\ManageBooking\RescheduleBookingRequest;
use App\Models\Booking;
use App\Models\CancellationRequest;
use App\Models\RescheduleRequest;
use App\Services\BookingPolicyEngine;
use App\Services\Payment\PaymentExceptionService;
use App\Services\WeatherSafetyService;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Services\NotificationOutbox;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;
use App\Http\Requests\ManageBooking\FindBookingRequest;
use App\Http\Requests\ManageBooking\CancelBookingRequest;

/**
 * Manage Booking page for participants.
 *
 * - participants log in with their booking number (CFP-YYYY-XXXX) and 4-digit PIN, no account needed
 * - they can request a reschedule (until 72 hours before) or a cancellation
 * - weather cancellations (storm warning / Critical Risk) get a full refund
 * - normal cancellations get a refund based on how early they cancel
 * - when rescheduling, we check the weather on the new date too
 * - a request only changes a booking that already holds its place, and never one whose payment is under review
 *   (Batch 3-II B1): checked again under the booking row lock, so a request can neither place a paid booking that got
 *   no place nor get around a payment exception. Those guests are told the team will contact them about their options.
 */
class ManageBookingController extends Controller
{
    /** Shown instead of request forms while a payment of the booking is held (no remedy is chosen here). */
    public const PAYMENT_UNDER_REVIEW_MESSAGE = 'We are reviewing a payment on this booking, so it cannot be changed or cancelled online right now. '
        . 'Our team will contact you about your options, including another date or a refund. Nothing has been changed. '
        . 'If you would like to talk to us sooner, please contact the camp directly.';

    /** Statuses from which a guest may send a request (both already hold their place). */
    public const REQUESTABLE_STATUSES = ['confirmed', 'rescheduled'];

    private const CHANGED_MEANWHILE_MESSAGE = 'Your booking changed while you were sending this request, so nothing was changed. Please review your booking and try again, or contact the camp.';

    public function __construct(
        protected BookingPolicyEngine $policyEngine,
        protected WeatherSafetyService $weatherService
    ) {}

    // TODO: add SMS OTP for cancellations

    /**
     * Booking lookup page.
     *
     * @param Request $request can have the booking number and PIN to pre-fill
     * @return View
     */
    public function index(Request $request): View
    {
        $prefilledNumber = $request->query('number', '');
        $prefilledPin = $request->query('pin', '');

        return view('manage.lookup', compact('prefilledNumber', 'prefilledPin'));
    }

    /**
     * Check the booking number and PIN and log the participant in.
     *
     * @param Request $request booking_number and pin
     * @return RedirectResponse
     */
    public function search(FindBookingRequest $request): RedirectResponse
    {
        $bookingNumber = strtoupper(trim($request->booking_number));
        $this->ensureNotGuessingPins($request, $bookingNumber);

        $booking = Booking::where('booking_number', $bookingNumber)
            ->where('pin', trim($request->pin))
            ->first();

        if (!$booking) {
            $this->recordFailedPin($request, $bookingNumber);

            return back()
                ->withInput()
                ->with('error', 'Booking not found - please check your details.');
        }

        session([
            'auth_booking_id' => $booking->id,
            'auth_booking_pin' => $booking->pin,
        ]);

        return redirect()->route('manage.show', ['booking_number' => $booking->booking_number]);
    }

    /**
     * Wrong-PIN limits: per IP, and per booking number so one booking can't be guessed
     * from many IPs. Only failed attempts count, so returning participants are never blocked.
     */
    private function pinLimiterKeys(Request $request, string $bookingNumber): array
    {
        $max = (int) config('rate_limits.manage_lookup.max_attempts', 10);

        return [
            'manage-pin:ip:' . $request->ip() => $max,
            'manage-pin:booking:' . $bookingNumber => $max * 2,
        ];
    }

    private function ensureNotGuessingPins(Request $request, string $bookingNumber): void
    {
        foreach ($this->pinLimiterKeys($request, $bookingNumber) as $key => $max) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                throw new ThrottleRequestsException('Too many PIN attempts.', null, ['Retry-After' => RateLimiter::availableIn($key)]);
            }
        }
    }

    private function recordFailedPin(Request $request, string $bookingNumber): void
    {
        $decaySeconds = 60 * (int) config('rate_limits.manage_lookup.decay_minutes', 15);
        foreach (array_keys($this->pinLimiterKeys($request, $bookingNumber)) as $key) {
            RateLimiter::hit($key, $decaySeconds);
        }
    }

    /**
     * Booking details page for the participant.
     *
     * @param Request $request
     * @param string $booking_number e.g. CFP-2026-1234
     * @return View|RedirectResponse
     */
    /**
     * "Book again": start a new booking with the same people, from the participant's own
     * booking (booking number + PIN session only, so nobody can pull other people's details).
     */
    public function bookAgain(string $booking_number): RedirectResponse
    {
        $booking = Booking::where('booking_number', strtoupper(trim($booking_number)))->with('participants')->first();
        if (!$booking || session('auth_booking_id') !== $booking->id) {
            return redirect()->route('manage.index')->with('info', 'Please enter your booking number and PIN first.');
        }

        $split = function (string $name): array {
            $parts = preg_split('/\s+/u', trim($name));
            $last = count($parts) > 1 ? array_pop($parts) : '';

            return [implode(' ', $parts), $last];
        };

        $participants = $booking->participants->map(function ($p) use ($split) {
            [$first, $last] = $split($p->name);

            return [
                'first_name' => $first, 'middle_name' => '', 'no_middle_name' => true, 'last_name' => $last, 'suffix' => '',
                'name' => $p->name, 'birthdate' => $p->birthdate?->format('Y-m-d') ?? '', 'gender' => $p->gender ?? '',
                'age' => '', 'health_condition' => $p->health_condition ?? '', 'swimmer_status' => $p->swimmer_status ?? 'non_swimmer',
            ];
        })->values();

        $leadIndex = $booking->participants->search(fn ($p) => mb_strtolower(trim($p->name)) === mb_strtolower(trim($booking->contact_name)));
        [$contactFirst, $contactLast] = $split($booking->contact_name);

        session(['book_again' => [
            'from' => $booking->booking_number,
            'class_type' => $booking->class_type,
            'participants' => $participants,
            'selected_lead_participant' => $leadIndex === false ? 'custom' : $leadIndex,
            'contact_first_name' => $contactFirst,
            'contact_last_name' => $contactLast,
            'contact_no_middle_name' => true,
            'contact_birthdate' => $booking->contact_birthdate?->format('Y-m-d') ?? '',
            'contact_email' => $booking->contact_email,
            'contact_phone' => $booking->contact_phone,
            'contact_facebook' => $booking->contact_facebook ?? '',
        ]]);

        return redirect()->route('booking.create', ['class' => $booking->class_type]);
    }

    public function show(Request $request, string $booking_number): View|RedirectResponse
    {
        $booking = Booking::where('booking_number', strtoupper(trim($booking_number)))
            ->with(['participants', 'payments', 'rescheduleRequests' => fn($q) => $q->latest(), 'cancellationRequests' => fn($q) => $q->latest()])
            ->first();

        if (!$booking) {
            return redirect()->route('manage.index')
                ->with('error', 'Booking not found - please check your details.');
        }

        $isAuthenticated = session('auth_booking_id') === $booking->id;

        // Older e-mails link here with ?pin=, so that still works but counts towards the wrong-PIN limit
        $pin = (string) $request->query('pin', '');
        if (!$isAuthenticated && $pin !== '') {
            $this->ensureNotGuessingPins($request, $booking->booking_number);
            $isAuthenticated = hash_equals((string) $booking->pin, $pin);
            if (!$isAuthenticated) {
                $this->recordFailedPin($request, $booking->booking_number);
            }
        }

        if (!$isAuthenticated) {
            $msg = $request->has('pin')
                ? 'Invalid PIN. Please enter your 4-digit PIN.'
                : 'Please enter your 4-digit PIN to access your booking.';

            return redirect()->route('manage.index', ['number' => $booking->booking_number])
                ->with('info', $msg);
        }

        session([
            'auth_booking_id' => $booking->id,
            'auth_booking_pin' => $booking->pin,
        ]);

        // What the participant is allowed to do right now (reschedule/cancel)
        $policy = $this->policyEngine->evaluate($booking);
        $paymentUnderReview = $this->paymentUnderReview($booking);

        // Weather for the booking date
        $currentForecast = $this->weatherService->getForecast($booking->start_date, $booking->end_date);

        return view('manage.detail', compact('booking', 'policy', 'currentForecast', 'paymentUnderReview'));
    }

    /** A paid booking that got no place, or one with a held payment (unresolved payment exception or review). */
    private function paymentUnderReview(Booking $booking): bool
    {
        return $booking->status === 'paid_unallocated' || app(PaymentExceptionService::class)->blockingIssue($booking) !== null;
    }

    /**
     * Run $create with the booking row locked, only while the booking still has the status the guest saw, already takes
     * its place (so the request status changes no capacity) and has no held payment. Returns the created request, or
     * the error to show (nothing created or changed).
     *
     * @template T of RescheduleRequest|CancellationRequest
     * @param callable(Booking): T $create
     * @return T|string
     */
    private function lockedGuestRequest(Booking $booking, callable $create): RescheduleRequest|CancellationRequest|string
    {
        return DB::transaction(function () use ($booking, $create) {
            $locked = Booking::whereKey($booking->id)->lockForUpdate()->firstOrFail();
            if ($this->paymentUnderReview($locked)) {
                return self::PAYMENT_UNDER_REVIEW_MESSAGE;
            }
            // B5: one request at a time, only on an active booking that is not waiting on a request (a pending reschedule
            // and cancellation never coexist) and has not taken place yet
            if ($locked->status !== $booking->status || ! in_array($locked->status, self::REQUESTABLE_STATUSES, true)) {
                return self::CHANGED_MEANWHILE_MESSAGE;
            }

            return $create($locked);
        });
    }

    /**
     * Participant asks to move the dive date.
     *
     * 1. Downpayment must be paid first.
     * 2. Must be at least 72 hours before the dive.
     * 3. The new date must not have bad weather.
     *
     * @param Request $request pin, requested_start_date, requested_end_date, reason
     * @param string $booking_number
     * @return RedirectResponse
     */
    public function reschedule(RescheduleBookingRequest $request, string $booking_number): RedirectResponse
    {
        $validated = $request->validated();

        $booking = Booking::where('booking_number', strtoupper(trim($booking_number)))
            ->where('pin', trim($validated['pin']))
            ->firstOrFail();

        if ($booking->status === 'pending_downpayment') {
            return back()->with('error', 'Your booking cannot be rescheduled because the required downpayment has not been paid.');
        }
        if ($this->paymentUnderReview($booking)) {
            return back()->with('error', self::PAYMENT_UNDER_REVIEW_MESSAGE);
        }

        $policy = $this->policyEngine->evaluate($booking);
        if (!$policy['reschedule_allowed']) {
            return back()->with('error', 'Rescheduling is not allowed: ' . $policy['reschedule_message']);
        }

        // Check the weather on the new date so the participant doesn't move into a storm
        $forecast = $this->weatherService->getForecast($validated['requested_start_date'], $validated['requested_end_date']);
        if (!$forecast['is_bookable']) {
            return back()->with('error', 'The requested new date has a Critical Storm Warning. Please pick an alternative safe date.');
        }

        $rescheduleRequest = $this->lockedGuestRequest($booking, function (Booking $locked) use ($validated) {
            $created = RescheduleRequest::create([
                'booking_id' => $locked->id,
                'current_start_date' => $locked->start_date,
                'current_end_date' => $locked->end_date,
                'requested_start_date' => $validated['requested_start_date'],
                'requested_end_date' => $validated['requested_end_date'],
                'reason' => $validated['reason'] ?? 'Participant requested reschedule',
                'status' => 'pending',
            ]);
            $locked->update(['status' => 'reschedule_requested']);

            return $created;
        });
        if (is_string($rescheduleRequest)) {
            return back()->with('error', $rescheduleRequest);
        }
        $booking->refresh();

        app(NotificationOutbox::class)->rescheduleRequested($booking, $rescheduleRequest);

        app(\App\Services\AdminNotificationService::class)->customerRequest('reschedule', $booking, $rescheduleRequest->reason);

        return redirect()->route('manage.show', ['booking_number' => $booking->booking_number])
            ->with('success', "Your reschedule request has been sent to the camp for approval. You'll be notified once it's confirmed.");
    }

    /**
     * Participant asks to cancel the booking.
     *
     * Refund is computed by BookingPolicyEngine:
     * - weather cancellation: full refund
     * - more than 7 days before: partial refund
     * - less than 7 days before: no refund
     *
     * @param Request $request pin, confirm_cancel_ack, reason
     * @param string $booking_number
     * @return RedirectResponse
     */
    public function cancel(CancelBookingRequest $request, string $booking_number): RedirectResponse
    {
        $validated = $request->validated();

        $booking = Booking::where('booking_number', strtoupper(trim($booking_number)))
            ->where('pin', trim($validated['pin']))
            ->firstOrFail();

        if ($booking->status === 'pending_downpayment') {
            return back()->with('error', 'Your booking cannot be cancelled because the required downpayment has not been paid.');
        }
        if ($this->paymentUnderReview($booking)) {
            return back()->with('error', self::PAYMENT_UNDER_REVIEW_MESSAGE);
        }

        $policy = $this->policyEngine->evaluate($booking);
        if ($booking->status === 'cancelled' || $booking->status === 'cancellation_requested') {
            return back()->with('error', 'A cancellation is already processed or pending review.');
        }
        if (!$policy['cancel_allowed']) {
            return back()->with('error', 'Cancellation is not allowed: ' . $policy['cancel_message']);
        }

        // calculated_refund_amount is the amount the applicable terms quote now (kept as shown); the policy version
        // says which terms (NULL = the earlier published terms). No refund is decided or paid here.
        $cancellationRequest = $this->lockedGuestRequest($booking, function (Booking $locked) use ($validated, $policy) {
            $created = CancellationRequest::create([
                'booking_id' => $locked->id,
                'calculated_refund_amount' => $policy['calculated_refund'],
                'reason' => $validated['reason'] ?? 'Participant requested cancellation',
                'force_majeure_flag' => $policy['is_force_majeure'],
                'status' => 'pending',
                'policy_version' => $locked->refund_policy_version,
            ]);
            $locked->update(['status' => 'cancellation_requested']);

            return $created;
        });
        if (is_string($cancellationRequest)) {
            return back()->with('error', $cancellationRequest);
        }
        $booking->refresh();

        app(NotificationOutbox::class)->cancellationRequested($booking, $cancellationRequest);

        app(\App\Services\AdminNotificationService::class)->customerRequest('cancellation', $booking, $cancellationRequest->reason);

        return redirect()->route('manage.show', ['booking_number' => $booking->booking_number])
            ->with('success', "Your cancellation request has been submitted for camp review. You'll be notified once processed.");
    }
}
