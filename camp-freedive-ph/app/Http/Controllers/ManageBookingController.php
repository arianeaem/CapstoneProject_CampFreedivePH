<?php

namespace App\Http\Controllers;

use App\Http\Requests\ManageBooking\RescheduleBookingRequest;
use App\Mail\CancellationRequestedMail;
use App\Mail\RescheduleRequestedMail;
use App\Models\Booking;
use App\Models\CancellationRequest;
use App\Models\RescheduleRequest;
use App\Services\BookingPolicyEngine;
use App\Services\WeatherSafetyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\View\View;
use App\Http\Requests\ManageBooking\FindBookingRequest;
use App\Http\Requests\ManageBooking\CancelBookingRequest;

/**
 * Manage Booking page for guests.
 *
 * - guests log in with their booking number (CFP-YYYY-XXXX) and 4-digit PIN, no account needed
 * - they can request a reschedule (until 72 hours before) or a cancellation
 * - weather cancellations (storm warning / Critical Risk) get a full refund
 * - normal cancellations get a refund based on how early they cancel
 * - when rescheduling, we check the weather on the new date too
 */
class ManageBookingController extends Controller
{
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
     * Check the booking number and PIN and log the guest in.
     *
     * @param Request $request booking_number and pin
     * @return RedirectResponse
     */
    public function search(FindBookingRequest $request): RedirectResponse
    {
        $booking = Booking::where('booking_number', strtoupper(trim($request->booking_number)))
            ->where('pin', trim($request->pin))
            ->first();

        if (!$booking) {
            return back()
                ->withInput()
                ->with('error', 'Booking not found - please check your details.');
        }

        session([
            'auth_booking_id' => $booking->id,
            'auth_booking_pin' => $booking->pin,
        ]);

        return redirect()->route('manage.show', [
            'booking_number' => $booking->booking_number,
            'pin' => $booking->pin,
        ]);
    }

    /**
     * Booking details page for the guest.
     *
     * @param Request $request
     * @param string $booking_number e.g. CFP-2026-1234
     * @return View|RedirectResponse
     */
    public function show(Request $request, string $booking_number): View|RedirectResponse
    {
        $booking = Booking::where('booking_number', strtoupper(trim($booking_number)))
            ->with(['participants', 'payments', 'rescheduleRequests' => fn($q) => $q->latest(), 'cancellationRequests' => fn($q) => $q->latest()])
            ->first();

        if (!$booking) {
            return redirect()->route('manage.index')
                ->with('error', 'Booking not found - please check your details.');
        }

        $pin = $request->query('pin', session('auth_booking_pin'));

        $isAuthenticated = (session('auth_booking_id') === $booking->id)
            || ($pin !== null && $pin !== '' && (string) $booking->pin === (string) $pin);

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

        // What the guest is allowed to do right now (reschedule/cancel)
        $policy = $this->policyEngine->evaluate($booking);

        // Weather for the booking date
        $currentForecast = $this->weatherService->getForecast($booking->start_date, $booking->end_date);

        return view('manage.detail', compact('booking', 'policy', 'currentForecast'));
    }

    /**
     * Guest asks to move the dive date.
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

        $policy = $this->policyEngine->evaluate($booking);
        if (!$policy['reschedule_allowed']) {
            return back()->with('error', 'Rescheduling is not allowed: ' . $policy['reschedule_message']);
        }

        // Check the weather on the new date so the guest doesn't move into a storm
        $forecast = $this->weatherService->getForecast($validated['requested_start_date'], $validated['requested_end_date']);
        if (!$forecast['is_bookable']) {
            return back()->with('error', 'The requested new date has a Critical Storm Warning. Please pick an alternative safe date.');
        }

        $rescheduleRequest = RescheduleRequest::create([
            'booking_id' => $booking->id,
            'current_start_date' => $booking->start_date,
            'current_end_date' => $booking->end_date,
            'requested_start_date' => $validated['requested_start_date'],
            'requested_end_date' => $validated['requested_end_date'],
            'reason' => $validated['reason'] ?? 'Customer requested reschedule',
            'status' => 'pending',
        ]);

        $booking->update([
            'status' => 'reschedule_requested',
        ]);

        try {
            Mail::to($booking->contact_email)->send(new RescheduleRequestedMail($booking, $rescheduleRequest));
        } catch (\Exception $e) {
            Log::warning('Reschedule email failed: ' . $e->getMessage());
        }

        app(\App\Services\AdminNotificationService::class)->customerRequest('reschedule', $booking, $rescheduleRequest->reason);

        return redirect()->route('manage.show', ['booking_number' => $booking->booking_number, 'pin' => $booking->pin])
            ->with('success', "Your reschedule request has been sent to the camp for approval. You'll be notified once it's confirmed.");
    }

    /**
     * Guest asks to cancel the booking.
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

        $policy = $this->policyEngine->evaluate($booking);
        if ($booking->status === 'cancelled' || $booking->status === 'cancellation_requested') {
            return back()->with('error', 'A cancellation is already processed or pending review.');
        }
        if (!$policy['cancel_allowed']) {
            return back()->with('error', 'Cancellation is not allowed: ' . $policy['cancel_message']);
        }

        $cancellationRequest = CancellationRequest::create([
            'booking_id' => $booking->id,
            'calculated_refund_amount' => $policy['calculated_refund'],
            'reason' => $validated['reason'] ?? 'Customer requested cancellation',
            'force_majeure_flag' => $policy['is_force_majeure'],
            'status' => 'pending',
        ]);

        $booking->update([
            'status' => 'cancellation_requested',
        ]);

        try {
            Mail::to($booking->contact_email)->send(new CancellationRequestedMail($booking, $cancellationRequest));
        } catch (\Exception $e) {
            Log::warning('Cancellation email failed: ' . $e->getMessage());
        }

        app(\App\Services\AdminNotificationService::class)->customerRequest('cancellation', $booking, $cancellationRequest->reason);

        return redirect()->route('manage.show', ['booking_number' => $booking->booking_number, 'pin' => $booking->pin])
            ->with('success', "Your cancellation request has been submitted for camp review. You'll be notified once processed.");
    }
}
