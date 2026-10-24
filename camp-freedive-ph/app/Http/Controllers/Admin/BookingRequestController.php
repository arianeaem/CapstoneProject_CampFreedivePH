<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\BookingRequests\ApproveCancellationRequest;
use App\Http\Requests\Admin\BookingRequests\ApproveRescheduleRequest;
use App\Http\Requests\Admin\BookingRequests\RejectCancellationRequest;
use App\Http\Requests\Admin\BookingRequests\RejectRescheduleRequest;
use App\Models\CancellationRequest;
use App\Models\RescheduleRequest;
use App\Services\AuditLogger;
use App\Services\Booking\BookingRequestService;
use App\Services\BookingPolicyEngine;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class BookingRequestController extends Controller
{
    public function __construct(
        protected BookingPolicyEngine $policyEngine,
        protected BookingRequestService $requests
    ) {}

    /**
     * Pending requests page.
     */
    public function index(Request $request): View
    {
        $pendingReschedules = RescheduleRequest::with(['booking.participants', 'booking.payments'])
            ->where('status', 'pending')
            ->latest()
            ->get();

        $pendingCancellations = CancellationRequest::with(['booking.participants', 'booking.payments'])
            ->where('status', 'pending')
            ->latest()
            ->get();

        $cancellationPolicies = [];
        foreach ($pendingCancellations as $req) {
            $cancellationPolicies[$req->id] = $this->policyEngine->evaluate($req->booking, $req->requested_at ?? $req->created_at);
        }

        $reschedulePolicies = [];
        foreach ($pendingReschedules as $req) {
            $reschedulePolicies[$req->id] = $this->policyEngine->evaluate($req->booking, $req->requested_at ?? $req->created_at);
        }

        $perPage = max(5, min(100, (int) $request->input('per_page', 10)));

        $processedReschedules = RescheduleRequest::with(['booking', 'reviewer'])
            ->whereIn('status', ['approved', 'rejected'])
            ->latest('reviewed_at')
            ->paginate($perPage, ['*'], 'reschedule_page')
            ->withQueryString();

        $processedCancellations = CancellationRequest::with(['booking', 'reviewer'])
            ->whereIn('status', ['approved', 'rejected'])
            ->latest('reviewed_at')
            ->paginate($perPage, ['*'], 'cancellation_page')
            ->withQueryString();

        return view('admin.bookings.requests', compact(
            'pendingReschedules',
            'pendingCancellations',
            'cancellationPolicies',
            'reschedulePolicies',
            'processedReschedules',
            'processedCancellations'
        ));
    }

    /**
     * Approve a reschedule request.
     */
    public function approveReschedule(ApproveRescheduleRequest $request, RescheduleRequest $rescheduleRequest): RedirectResponse
    {
        $operator = Auth::user();
        try {
            $booking = $this->requests->approveReschedule($rescheduleRequest, $operator, $request->input('admin_notes'));
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        AuditLogger::log('RESCHEDULE_APPROVED', "Reschedule request approved for Booking #{$booking->booking_number} by {$operator->name}", $operator, $operator->name, $request);

        return back()->with('success', "Reschedule request for Booking #{$booking->booking_number} has been approved! Booking moved to {$rescheduleRequest->requested_start_date->format('M d, Y')}.");
    }

    /**
     * Reject a reschedule request.
     */
    public function rejectReschedule(RejectRescheduleRequest $request, RescheduleRequest $rescheduleRequest): RedirectResponse
    {
        $operator = Auth::user();
        $booking = $rescheduleRequest->booking;
        try {
            $reason = $this->requests->rejectReschedule($rescheduleRequest, $operator, $request->input('admin_notes'));
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        AuditLogger::log('RESCHEDULE_REJECTED', "Reschedule request rejected for Booking #{$booking->booking_number} by {$operator->name}. Reason: {$reason}", $operator, $operator->name, $request);

        return back()->with('info', "Reschedule request for Booking #{$booking->booking_number} was rejected.");
    }

    /**
     * Approve a cancellation request (staff picks the refund option).
     */
    public function approveCancellation(ApproveCancellationRequest $request, CancellationRequest $cancellationRequest): RedirectResponse
    {
        $operator = Auth::user();
        $booking = $cancellationRequest->booking;
        $validated = $request->validated();

        try {
            $result = $this->requests->approveCancellation(
                $cancellationRequest,
                $operator,
                $validated['action_type'] ?? 'policy_refund',
                isset($validated['refund_amount']) ? (float) $validated['refund_amount'] : null,
                $request->input('admin_notes')
            );
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        $case = $result['case'];
        AuditLogger::log('CANCELLATION_APPROVED', "Cancellation request approved for Booking #{$booking->booking_number} by {$operator->name}. " . ($result['forfeited'] ? 'No refund (forfeited).' : "No money moved; refund case #{$case?->id} ({$case?->status})."), $operator, $operator->name, $request);

        if ($result['forfeited']) {
            return back()->with('success', "Cancellation for Booking #{$booking->booking_number} has been approved (no refund).");
        }

        return back()->with('success', "Cancellation for Booking #{$booking->booking_number} approved. No money was sent: review refund case #{$case?->id} in Refund Cases (" . str_replace('_', ' ', (string) $case?->status) . ').');
    }

    /**
     * Reject a cancellation request.
     */
    public function rejectCancellation(RejectCancellationRequest $request, CancellationRequest $cancellationRequest): RedirectResponse
    {
        $operator = Auth::user();
        $booking = $cancellationRequest->booking;
        try {
            $reason = $this->requests->rejectCancellation($cancellationRequest, $operator, $request->input('admin_notes'));
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        AuditLogger::log('CANCELLATION_REJECTED', "Cancellation request rejected for Booking #{$booking->booking_number} by {$operator->name}. Reason: {$reason}", $operator, $operator->name, $request);

        return back()->with('info', "Cancellation request for Booking #{$booking->booking_number} was rejected.");
    }
}
