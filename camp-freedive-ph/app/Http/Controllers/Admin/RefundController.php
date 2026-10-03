<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\BookingStatusLog;
use App\Models\Payment;
use App\Models\PaymentStatusLog;
use App\Models\RefundRequest;
use App\Services\AuditLogger;
use App\Services\BookingPolicyEngine;
use App\Services\PayMongoService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class RefundController extends Controller
{
    public function __construct(
        protected PayMongoService $payMongoService,
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
    public function approve(Request $request, RefundRequest $refundRequest): RedirectResponse
    {
        $currentUser = Auth::user();
        $payment = $refundRequest->payment;
        $booking = $refundRequest->booking;

        $validated = $request->validate([
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $refundAmount = (float) ($refundRequest->refund_amount ?: $payment->amount);
        $paymongoPaymentId = $payment->paymongo_payment_id;

        // Auto-resolve real PayMongo payment_id from Checkout Session if needed
        if ((empty($paymongoPaymentId) || !str_starts_with($paymongoPaymentId, 'pay_')) && !empty($payment->paymongo_resource_id)) {
            $session = $this->payMongoService->getCheckoutSession($payment->paymongo_resource_id);
            $sessPayments = $session['data']['attributes']['payments'] ?? [];
            if (!empty($sessPayments[0]['id'])) {
                $paymongoPaymentId = $sessPayments[0]['id'];
                $payment->update(['paymongo_payment_id' => $paymongoPaymentId]);
            }
        }

        if (empty($paymongoPaymentId)) {
            $paymongoPaymentId = $payment->transaction_id ?: 'offline';
        }

        // Call PayMongo Refund API
        $refundResult = $this->payMongoService->refund(
            $paymongoPaymentId,
            $refundAmount,
            'requested_by_customer',
            $validated['notes'] ?? 'Camp FreedivePH Approved Cancellation Refund'
        );

        if (!$refundResult['success']) {
            $errMsg = $refundResult['error'] ?? 'PayMongo refund execution failed. Please retry.';
            return back()->with('error', "PayMongo Refund Error: {$errMsg}");
        }

        $paymongoRefundId = $refundResult['refund_id'] ?? ('ref_' . bin2hex(random_bytes(8)));

        DB::transaction(function () use ($refundRequest, $payment, $booking, $paymongoRefundId, $refundAmount, $validated, $currentUser) {
            // Update Payment Record
            $payment->update([
                'status' => 'refunded',
                'paymongo_refund_id' => $paymongoRefundId,
                'amount_refunded' => $refundAmount,
                'refund_reason' => $validated['notes'] ?? 'Admin approved customer cancellation refund',
            ]);

            // Update Refund Request
            $refundRequest->update([
                'status' => 'approved',
                'paymongo_refund_id' => $paymongoRefundId,
                'notes' => $validated['notes'] ?? 'Refund processed via PayMongo API',
                'reviewed_by' => $currentUser->id,
                'reviewed_at' => now(),
            ]);

            // Update Booking Status to Cancelled
            $booking->update([
                'status' => 'cancelled_by_guest',
            ]);

            // Logs
            PaymentStatusLog::create([
                'payment_id' => $payment->id,
                'old_status' => 'refund_requested',
                'new_status' => 'refunded',
                'changed_by' => $currentUser->id,
                'note' => "Refund of ₱" . number_format($refundAmount, 2) . " executed via PayMongo (Refund ID: {$paymongoRefundId})",
                'created_at' => now(),
            ]);

            BookingStatusLog::create([
                'booking_id' => $booking->id,
                'old_status' => 'cancellation_requested',
                'new_status' => 'cancelled_by_guest',
                'changed_by' => $currentUser->id,
                'note' => "Booking cancelled. 100% refund of ₱" . number_format($refundAmount, 2) . " credited to guest via PayMongo.",
                'created_at' => now(),
            ]);
        });

        AuditLogger::log(
            'REFUND_APPROVED_AND_EXECUTED',
            "Refund of ₱" . number_format($refundAmount, 2) . " approved & executed via PayMongo for Booking #{$booking->booking_number} by {$currentUser->name} (Refund ID: {$paymongoRefundId})",
            $currentUser,
            $currentUser->name,
            $request
        );

        return back()->with('success', "Refund of ₱" . number_format($refundAmount, 2) . " successfully executed via PayMongo! Reference: {$paymongoRefundId}");
    }

    /**
     * Reject a refund request.
     */
    public function reject(Request $request, RefundRequest $refundRequest): RedirectResponse
    {
        $currentUser = Auth::user();
        $payment = $refundRequest->payment;
        $booking = $refundRequest->booking;

        $validated = $request->validate([
            'notes' => ['required', 'string', 'max:500'],
        ], [
            'notes.required' => 'Please provide a clear reason for rejecting this refund request.',
        ]);

        DB::transaction(function () use ($refundRequest, $payment, $booking, $validated, $currentUser) {
            $payment->update(['status' => 'completed']);
            $booking->update(['status' => 'confirmed']);

            $refundRequest->update([
                'status' => 'rejected',
                'notes' => $validated['notes'],
                'reviewed_by' => $currentUser->id,
                'reviewed_at' => now(),
            ]);

            PaymentStatusLog::create([
                'payment_id' => $payment->id,
                'old_status' => 'refund_requested',
                'new_status' => 'completed',
                'changed_by' => $currentUser->id,
                'note' => "Refund request rejected by {$currentUser->name} - Reason: {$validated['notes']}",
                'created_at' => now(),
            ]);

            BookingStatusLog::create([
                'booking_id' => $booking->id,
                'old_status' => 'cancellation_requested',
                'new_status' => 'confirmed',
                'changed_by' => $currentUser->id,
                'note' => "Cancellation & refund rejected by {$currentUser->name} - Reason: {$validated['notes']}",
                'created_at' => now(),
            ]);
        });

        AuditLogger::log(
            'REFUND_REJECTED',
            "Refund request rejected for Booking #{$booking->booking_number} by {$currentUser->name}. Reason: {$validated['notes']}",
            $currentUser,
            $currentUser->name,
            $request
        );

        return back()->with('info', "Refund request for Booking #{$booking->booking_number} was rejected.");
    }

    /**
     * Forfeit payment/downpayment per policy.
     */
    public function forfeit(Request $request, RefundRequest $refundRequest): RedirectResponse
    {
        $currentUser = Auth::user();
        $payment = $refundRequest->payment;
        $booking = $refundRequest->booking;

        $validated = $request->validate([
            'forfeit_reason' => ['required', 'in:cancellation_outside_policy_window,customer_no_show,unapproved_late_withdrawal,custom_administrative_decision'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $forfeitedAmount = $payment->amount;

        DB::transaction(function () use ($refundRequest, $payment, $booking, $validated, $forfeitedAmount, $currentUser) {
            $payment->update([
                'status' => 'forfeited',
                'is_forfeited' => true,
                'forfeited_amount' => $forfeitedAmount,
                'forfeit_reason' => $validated['forfeit_reason'],
            ]);

            $refundRequest->update([
                'status' => 'forfeited',
                'forfeit_reason' => $validated['forfeit_reason'],
                'notes' => $validated['notes'] ?? 'Deposit forfeited per camp cancellation policy rules.',
                'reviewed_by' => $currentUser->id,
                'reviewed_at' => now(),
            ]);

            $bookingStatus = ($validated['forfeit_reason'] === 'customer_no_show') ? 'no_show' : 'cancelled_by_guest';
            $booking->update(['status' => $bookingStatus]);

            PaymentStatusLog::create([
                'payment_id' => $payment->id,
                'old_status' => 'refund_requested',
                'new_status' => 'forfeited',
                'changed_by' => $currentUser->id,
                'note' => "Payment of ₱" . number_format($forfeitedAmount, 2) . " forfeited. Reason: {$validated['forfeit_reason']}" . ($validated['notes'] ? " - {$validated['notes']}" : ''),
                'created_at' => now(),
            ]);

            BookingStatusLog::create([
                'booking_id' => $booking->id,
                'old_status' => 'cancellation_requested',
                'new_status' => $bookingStatus,
                'changed_by' => $currentUser->id,
                'note' => "Booking cancelled. Downpayment forfeited per camp policy ({$validated['forfeit_reason']}).",
                'created_at' => now(),
            ]);
        });

        AuditLogger::log(
            'PAYMENT_FORFEITED',
            "Payment of ₱" . number_format($forfeitedAmount, 2) . " forfeited for Booking #{$booking->booking_number} by {$currentUser->name} (Reason: {$validated['forfeit_reason']})",
            $currentUser,
            $currentUser->name,
            $request
        );

        return back()->with('success', "Payment of ₱" . number_format($forfeitedAmount, 2) . " marked as Forfeited per camp policy.");
    }
}
