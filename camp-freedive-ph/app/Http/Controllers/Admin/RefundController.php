<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Payments\ApproveRefundRequest;
use App\Http\Requests\Admin\Payments\ForfeitPaymentRequest;
use App\Http\Requests\Admin\Payments\RejectRefundRequest;
use App\Models\RefundRequest;
use App\Services\AuditLogger;
use App\Services\BookingPolicyEngine;
use App\Services\Payment\RefundService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class RefundController extends Controller
{
    public function __construct(
        protected RefundService $refunds,
        protected BookingPolicyEngine $policyEngine
    ) {}

    /**
     * Pending refunds page.
     */
    public function index(Request $request): View
    {
        $pendingRefunds = RefundRequest::with(['payment', 'booking.participants'])
            ->where('status', 'pending')
            ->latest()
            ->get();

        $perPage = max(5, min(100, (int) $request->input('per_page', 10)));
        $processedRefunds = RefundRequest::with(['payment', 'booking', 'reviewer'])
            ->whereIn('status', ['approved', 'rejected', 'forfeited'])
            ->latest('reviewed_at')
            ->paginate($perPage)
            ->withQueryString();


        // Get the policy result for each request based on when it was sent
        $policies = [];
        foreach ($pendingRefunds as $req) {
            $policies[$req->id] = $this->policyEngine->evaluate($req->booking, $req->requested_at ?? $req->created_at);
        }

        return view('admin.payments.refunds', compact('pendingRefunds', 'processedRefunds', 'policies'));
    }

    /**
     * Move a queued refund request into the booking's refund case (reason, guest choice, amount, then payout there).
     * Nothing is sent to PayMongo here.
     */
    public function approve(ApproveRefundRequest $request, RefundRequest $refundRequest): RedirectResponse
    {
        try {
            $case = $this->refunds->approve($refundRequest, Auth::user());
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->to(portal_route('refund_cases.show', $case))
            ->with('success', "Refund request moved to refund case #{$case->id}. Confirm the reason and the guest's choice before approving an amount; no money has been sent.");
    }

    /**
     * Reject a refund request.
     */
    public function reject(RejectRefundRequest $request, RefundRequest $refundRequest): RedirectResponse
    {
        $operator = Auth::user();
        $booking = $refundRequest->booking;
        $notes = $request->validated()['notes'];
        try {
            $this->refunds->reject($refundRequest, $operator, $notes);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        AuditLogger::log('REFUND_REJECTED', "Refund request rejected for Booking #{$booking->booking_number} by {$operator->name}. Reason: {$notes}", $operator, $operator->name, $request);

        return back()->with('info', "Refund request for Booking #{$booking->booking_number} was rejected.");
    }

    /**
     * Keep the payment / downpayment (per policy).
     */
    public function forfeit(ForfeitPaymentRequest $request, RefundRequest $refundRequest): RedirectResponse
    {
        $operator = Auth::user();
        $booking = $refundRequest->booking;
        $validated = $request->validated();
        try {
            $amount = number_format($this->refunds->forfeit($refundRequest, $operator, $validated['forfeit_reason'], $validated['notes'] ?? null), 2);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        AuditLogger::log('PAYMENT_FORFEITED', "Payment of ₱{$amount} forfeited for Booking #{$booking->booking_number} by {$operator->name} (Reason: {$validated['forfeit_reason']})", $operator, $operator->name, $request);

        return back()->with('success', "Payment of ₱{$amount} marked as Forfeited per camp policy.");
    }
}
