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
 * Customer Self-Service Booking Management Controller.
 *
 * Business Workflow & Security Policies:
 * 1. Frictionless PIN Authentication: Allows guests to access vouchers, reschedule requests, and cancellation forms
 *    using their unique Booking Reference Code (CFP-YYYY-XXXXX) and 4-digit PIN without account registration.
 * 2. Automated Policy Enforcement:
 *    - Voluntary Reschedules: Permitted up to 72 hours prior to scheduled departure.
 *    - Force Majeure Weather Cancellations: Automatically grants 100% refund entitlement if coastal storm warnings
 *      or Critical Risk conditions are active on the dive date.
 *    - Voluntary Guest Cancellations: Calculates tiered refund deductions based on booking lead time.
 * 3. Proactive Safety Re-Verification: Validates marine weather safety for newly requested dates during reschedule attempts.
 */
class ManageBookingController extends Controller
{
    public function __construct(
        protected BookingPolicyEngine $policyEngine,
        protected WeatherSafetyService $weatherService
    ) {}

    // TODO: Implement SMS OTP two-factor authentication for sensitive booking cancellations.

    /**
     * Show the public booking lookup portal.
     *
     * @param Request $request Optional query params for pre-filling booking code and PIN.
     * @return View Renders the lookup portal.
     */
    public function index(Request $request): View
    {
        $prefilledNumber = $request->query('number', '');
        $prefilledPin = $request->query('pin', '');

        return view('manage.lookup', compact('prefilledNumber', 'prefilledPin'));
    }

    /**
     * Authenticates booking credentials and establishes guest session.
     *
     * @param Request $request Contains `booking_number` and `pin`.
     * @return RedirectResponse Redirects to self-service dashboard on success.
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
     * Show the booking details and self-service management dashboard.
     *
     * @param Request $request Guest request holding auth session credentials.
     * @param string $booking_number Unique booking code (CFP-YYYY-XXXXX).
     * @return View|RedirectResponse Renders voucher, participant details, and policy actions.
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

        // Live evaluation of the cancellation/reschedule policy engine
        $policy = $this->policyEngine->evaluate($booking);

        // Marine forecast for current booking date
        $currentForecast = $this->weatherService->getForecast($booking->start_date, $booking->end_date);

        return view('manage.detail', compact('booking', 'policy', 'currentForecast'));
    }

    /**
     * Submit a customer request to reschedule the dive date.
     *
     * Business Logic:
     * 1. Validates that downpayment was settled (unpaid bookings cannot hold replacement dates).
     * 2. Checks policy cutoff window (minimum 72h lead time required for voluntary changes).
     * 3. Proactively evaluates marine weather on the target replacement date to prevent moving into a storm.
     *
     * @param Request $request Holds `pin`, `requested_start_date`, `requested_end_date`, and `reason`.
     * @param string $booking_number Target booking identifier.
     * @return RedirectResponse Redirects back with status feedback.
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

        // Proactive Marine Safety Verification on Replacement Date:
        // Protects guests from inadvertently rescheduling into an approaching cyclone or gale warning.
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
     * Submit a customer request to cancel the reservation.
     *
     * Business Logic:
     * Computes refund eligibility via BookingPolicyEngine:
     * - Weather / Force Majeure: 100% full refund entitlement.
     * - Voluntary Notice (>7 days): Partial downpayment refund minus non-refundable processing costs.
     * - Last-minute Notice (<7 days): Non-refundable deposit retention.
     *
     * @param Request $request Holds `pin`, `confirm_cancel_ack`, and `reason`.
     * @param string $booking_number Target booking identifier.
     * @return RedirectResponse Redirects back with status feedback.
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
