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
     * Display the dedicated Pending Refund Requests queue.
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


        // Calculate live policy snapshot for each pending request based on when it was submitted
        $policies = [];
        foreach ($pendingRefunds as $req) {
            $policies[$req->id] = $this->policyEngine->evaluate($req->booking, $req->requested_at ?? $req->created_at);
        }

        return view('admin.payments.refunds', compact('pendingRefunds', 'processedRefunds', 'policies'));
    }

    /**
     * Approve and execute refund via PayMongo API.
     */
    public function approve(ApproveRefundRequest $request, RefundRequest $refundRequest): RedirectResponse
    {
        $operator = Auth::user();
        $booking = $refundRequest->booking;
        $result = $this->refunds->approve($refundRequest, $operator, $request->validated()['notes'] ?? null);

        if (!$result['success']) {
            return back()->with('error', "PayMongo Refund Error: {$result['error']}");
        }

        $amount = number_format($result['amount'], 2);
        AuditLogger::log('REFUND_APPROVED_AND_EXECUTED', "Refund of ₱{$amount} approved & executed via PayMongo for Booking #{$booking->booking_number} by {$operator->name} (Refund ID: {$result['refund_id']})", $operator, $operator->name, $request);

        return back()->with('success', "Refund of ₱{$amount} successfully executed via PayMongo! Reference: {$result['refund_id']}");
    }

    /**
     * Reject a refund request.
     */
    public function reject(RejectRefundRequest $request, RefundRequest $refundRequest): RedirectResponse
    {
        $operator = Auth::user();
        $booking = $refundRequest->booking;
        $notes = $request->validated()['notes'];
        $this->refunds->reject($refundRequest, $operator, $notes);

        AuditLogger::log('REFUND_REJECTED', "Refund request rejected for Booking #{$booking->booking_number} by {$operator->name}. Reason: {$notes}", $operator, $operator->name, $request);

        return back()->with('info', "Refund request for Booking #{$booking->booking_number} was rejected.");
    }

    /**
     * Forfeit payment/downpayment per policy.
     */
    public function forfeit(ForfeitPaymentRequest $request, RefundRequest $refundRequest): RedirectResponse
    {
        $operator = Auth::user();
        $booking = $refundRequest->booking;
        $validated = $request->validated();
        $amount = number_format($this->refunds->forfeit($refundRequest, $operator, $validated['forfeit_reason'], $validated['notes'] ?? null), 2);

        AuditLogger::log('PAYMENT_FORFEITED', "Payment of ₱{$amount} forfeited for Booking #{$booking->booking_number} by {$operator->name} (Reason: {$validated['forfeit_reason']})", $operator, $operator->name, $request);

        return back()->with('success', "Payment of ₱{$amount} marked as Forfeited per camp policy.");
    }
}
