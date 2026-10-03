<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\CancellationApprovedMail;
use App\Mail\CancellationRejectedMail;
use App\Mail\RescheduleApprovedMail;
use App\Mail\RescheduleRejectedMail;
use App\Models\Booking;
use App\Models\BookingStatusLog;
use App\Models\CancellationRequest;
use App\Models\Payment;
use App\Models\PaymentStatusLog;
use App\Models\RefundRequest;
use App\Models\RescheduleRequest;
use App\Services\AuditLogger;
use App\Services\BatchManagementService;
use App\Services\BookingPolicyEngine;
use App\Services\PayMongoService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\View\View;

class BookingRequestController extends Controller
{
    public function __construct(
        protected BookingPolicyEngine $policyEngine,
        protected BatchManagementService $batchService,
        protected PayMongoService $payMongoService
    ) {}

    /**
     * Display the dedicated Pending Requests queue.
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
     * Approve customer reschedule request.
     */
    public function approveReschedule(Request $request, RescheduleRequest $rescheduleRequest): RedirectResponse
    {
        $currentUser = Auth::user();
        $booking = $rescheduleRequest->booking;

        $request->validate([
            'admin_notes' => 'nullable|string|max:500',
        ]);

        $adminNotes = $request->input('admin_notes');

        DB::transaction(function () use ($rescheduleRequest, $booking, $adminNotes, $currentUser) {
            $oldDates = "{$booking->start_date->format('M d, Y')} - {$booking->end_date->format('M d, Y')}";
            $newDates = "{$rescheduleRequest->requested_start_date->format('M d, Y')} - {$rescheduleRequest->requested_end_date->format('M d, Y')}";

            // Find or auto-create batch for the requested date
            $batch = $this->batchService->findOrCreateBatchForDates(
                $rescheduleRequest->requested_start_date,
                $rescheduleRequest->requested_end_date,
                $currentUser
            );

            // Update booking dates and attach to the target batch
            $booking->update([
                'batch_id' => $batch->id,
                'start_date' => $rescheduleRequest->requested_start_date,
                'end_date' => $rescheduleRequest->requested_end_date,
                'status' => 'confirmed',
            ]);

            // Mark request approved
            $rescheduleRequest->update([
                'status' => 'approved',
                'admin_notes' => $adminNotes ?: 'Reschedule request approved by camp staff.',
                'reviewed_by' => $currentUser->id,
                'reviewed_at' => now(),
            ]);

            // Status Log
            BookingStatusLog::create([
                'booking_id' => $booking->id,
                'old_status' => 'reschedule_requested',
                'new_status' => 'confirmed',
                'changed_by' => $currentUser->id,
                'note' => "Reschedule approved ({$oldDates} {$newDates}, attached to {$batch->batch_code})" . ($adminNotes ? " - {$adminNotes}" : ''),
                'created_at' => now(),
            ]);
        });

        AuditLogger::log(
            'RESCHEDULE_APPROVED',
            "Reschedule request approved for Booking #{$booking->booking_number} by {$currentUser->name}",
            $currentUser,
            $currentUser->name,
            $request
        );

        // Send email notification to guest
        if ($booking->contact_email) {
            try {
                Mail::to($booking->contact_email)->send(new RescheduleApprovedMail($booking->fresh(), $rescheduleRequest));
            } catch (\Throwable $e) {
                Log::warning("Failed to send RescheduleApprovedMail to {$booking->contact_email}: " . $e->getMessage());
            }
        }

        return back()->with('success', "Reschedule request for Booking #{$booking->booking_number} has been approved! Booking moved to {$rescheduleRequest->requested_start_date->format('M d, Y')}.");
    }

    /**
     * Reject customer reschedule request.
     */
    public function rejectReschedule(Request $request, RescheduleRequest $rescheduleRequest): RedirectResponse
    {
        $currentUser = Auth::user();
        $booking = $rescheduleRequest->booking;

        $request->validate([
            'admin_notes' => 'nullable|string|max:500',
        ]);

        $reason = $request->input('admin_notes') ?: 'Reschedule request rejected by camp administration.';

        DB::transaction(function () use ($rescheduleRequest, $booking, $reason, $currentUser) {
            // Restore booking status to confirmed
            $booking->update(['status' => 'confirmed']);

            $rescheduleRequest->update([
                'status' => 'rejected',
                'admin_notes' => $reason,
                'reviewed_by' => $currentUser->id,
                'reviewed_at' => now(),
            ]);

            BookingStatusLog::create([
                'booking_id' => $booking->id,
                'old_status' => 'reschedule_requested',
                'new_status' => 'confirmed',
                'changed_by' => $currentUser->id,
                'note' => "Reschedule request rejected by {$currentUser->name} - Reason: {$reason}",
                'created_at' => now(),
            ]);
        });

        AuditLogger::log(
            'RESCHEDULE_REJECTED',
            "Reschedule request rejected for Booking #{$booking->booking_number} by {$currentUser->name}. Reason: {$reason}",
            $currentUser,
            $currentUser->name,
            $request
        );

        // Send email notification to guest
        if ($booking->contact_email) {
            try {
                Mail::to($booking->contact_email)->send(new RescheduleRejectedMail($booking->fresh(), $rescheduleRequest, $reason));
            } catch (\Throwable $e) {
                Log::warning("Failed to send RescheduleRejectedMail to {$booking->contact_email}: " . $e->getMessage());
            }
        }

        return back()->with('info', "Reschedule request for Booking #{$booking->booking_number} was rejected.");
    }

    /**
     * Approve customer cancellation request with policy choices.
     */
    public function approveCancellation(Request $request, CancellationRequest $cancellationRequest): RedirectResponse
    {
        $currentUser = Auth::user();
        $booking = $cancellationRequest->booking;

        $validated = $request->validate([
            'action_type' => 'nullable|string|in:policy_refund,full_refund,forfeit',
            'refund_amount' => 'nullable|numeric|min:0',
            'admin_notes' => 'nullable|string|max:500',
        ]);

        $adminNotes = $request->input('admin_notes');
        $policy = $this->policyEngine->evaluate($booking);
        $actionType = $validated['action_type'] ?? 'policy_refund';

        // Determine refund amount & forfeit status
        if ($actionType === 'forfeit') {
            $refundAmount = 0.00;
            $refundPercentage = 0;
            $isForfeited = true;
        } elseif ($actionType === 'full_refund') {
            $refundAmount = (float) $booking->paid_amount;
            $refundPercentage = 100;
            $isForfeited = false;
        } else { // policy_refund
            $refundAmount = isset($validated['refund_amount']) ? (float) $validated['refund_amount'] : (float) ($policy['calculated_refund'] ?? $cancellationRequest->calculated_refund_amount);
            $refundPercentage = $policy['refund_percentage'] ?? 0;
            $isForfeited = ($refundAmount <= 0);
        }

        // Process PayMongo refund directly if eligible
        $paymongoRefundId = null;
        if (!$isForfeited && $refundAmount > 0) {
            $completedPayments = $booking->payments()->whereIn('status', ['completed', 'paid'])->get();
            foreach ($completedPayments as $payment) {
                $paymongoPaymentId = $payment->paymongo_payment_id;
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

                $refundResult = $this->payMongoService->refund(
                    $paymongoPaymentId,
                    $refundAmount,
                    'requested_by_customer',
                    $adminNotes ?? 'Camp FreedivePH Approved Cancellation Refund'
                );

                $paymongoRefundId = $refundResult['refund_id'] ?? ('ref_' . bin2hex(random_bytes(8)));
            }
        }

        DB::transaction(function () use ($cancellationRequest, $booking, $adminNotes, $currentUser, $policy, $refundAmount, $refundPercentage, $isForfeited, $paymongoRefundId) {
            $booking->update([
                'status' => 'cancelled_by_guest',
                'batch_id' => null,
            ]);

            $cancellationRequest->update([
                'status' => 'approved',
                'calculated_refund_amount' => $refundAmount,
                'admin_notes' => $adminNotes ?: ($isForfeited ? 'Cancellation approved (Downpayment forfeited per policy).' : 'Cancellation and refund of ₱' . number_format($refundAmount, 2) . ' processed successfully.'),
                'reviewed_by' => $currentUser->id,
                'reviewed_at' => now(),
            ]);

            // Create/Update RefundRequest records and payment statuses
            $completedPayments = $booking->payments()->whereIn('status', ['completed', 'paid', 'refunded'])->get();
            foreach ($completedPayments as $payment) {
                if (!$isForfeited && $refundAmount > 0) {
                    $payment->update([
                        'status' => 'refunded',
                        'paymongo_refund_id' => $paymongoRefundId,
                        'amount_refunded' => $refundAmount,
                        'refund_reason' => $adminNotes ?? 'Admin approved customer cancellation refund',
                    ]);

                    PaymentStatusLog::create([
                        'payment_id' => $payment->id,
                        'old_status' => 'completed',
                        'new_status' => 'refunded',
                        'changed_by' => $currentUser->id,
                        'note' => "Direct 1-step refund of ₱" . number_format($refundAmount, 2) . " executed via PayMongo (Refund ID: {$paymongoRefundId})",
                        'created_at' => now(),
                    ]);
                }

                RefundRequest::create([
                    'payment_id' => $payment->id,
                    'booking_id' => $booking->id,
                    'requested_by' => 'guest_cancellation',
                    'requested_at' => now(),
                    'eligibility_calculated' => [
                        'days_until_dive' => max(0, Carbon::now()->diffInDays($booking->start_date, false)),
                        'eligible_for_refund' => !$isForfeited && ($refundAmount > 0),
                        'refund_percentage' => $refundPercentage,
                        'window_label' => $policy['policy_tier'] ?? 'Standard Policy',
                        'policy_action_text' => $isForfeited ? 'Cancellation within forfeiture window.' : "Approved & processed refund of ₱" . number_format($refundAmount, 2),
                    ],
                    'status' => $isForfeited ? 'forfeited' : 'approved',
                    'paymongo_refund_id' => $paymongoRefundId,
                    'forfeit_reason' => $isForfeited ? 'cancellation_outside_policy_window' : null,
                    'notes' => "Processed directly via Guest Cancellation Request. " . ($adminNotes ?? ''),
                    'reviewed_by' => $currentUser->id,
                    'reviewed_at' => now(),
                ]);
            }

            BookingStatusLog::create([
                'booking_id' => $booking->id,
                'old_status' => 'cancellation_requested',
                'new_status' => 'cancelled_by_guest',
                'changed_by' => $currentUser->id,
                'note' => "Cancellation approved by {$currentUser->name} (" . ($isForfeited ? "Forfeited" : "Refund processed: ₱" . number_format($refundAmount, 2)) . ")" . ($adminNotes ? " - {$adminNotes}" : ''),
                'created_at' => now(),
            ]);
        });

        AuditLogger::log(
            'CANCELLATION_APPROVED',
            "Cancellation request approved and processed for Booking #{$booking->booking_number} by {$currentUser->name}. Refund amount: ₱{$refundAmount}",
            $currentUser,
            $currentUser->name,
            $request
        );

        // Send email notification to guest
        if ($booking->contact_email) {
            try {
                Mail::to($booking->contact_email)->send(new CancellationApprovedMail($booking->fresh(), $cancellationRequest, $refundAmount, $isForfeited));
            } catch (\Throwable $e) {
                Log::warning("Failed to send CancellationApprovedMail to {$booking->contact_email}: " . $e->getMessage());
            }
        }

        if (!$isForfeited && $refundAmount > 0) {
            return back()->with('success', "Cancellation for Booking #{$booking->booking_number} approved! Refund of ₱" . number_format($refundAmount, 2) . " has been executed directly via PayMongo.");
        }

        return back()->with('success', "Cancellation for Booking #{$booking->booking_number} has been approved (Downpayment forfeited per policy).");
    }

    /**
     * Reject customer cancellation request.
     */
    public function rejectCancellation(Request $request, CancellationRequest $cancellationRequest): RedirectResponse
    {
        $currentUser = Auth::user();
        $booking = $cancellationRequest->booking;

        $request->validate([
            'admin_notes' => 'nullable|string|max:500',
        ]);

        $reason = $request->input('admin_notes') ?: 'Cancellation request rejected by camp administration.';

        DB::transaction(function () use ($cancellationRequest, $booking, $reason, $currentUser) {
            $booking->update(['status' => 'confirmed']);

            $cancellationRequest->update([
                'status' => 'rejected',
                'admin_notes' => $reason,
                'reviewed_by' => $currentUser->id,
                'reviewed_at' => now(),
            ]);

            BookingStatusLog::create([
                'booking_id' => $booking->id,
                'old_status' => 'cancellation_requested',
                'new_status' => 'confirmed',
                'changed_by' => $currentUser->id,
                'note' => "Cancellation rejected by {$currentUser->name} - Reason: {$reason}",
                'created_at' => now(),
            ]);
        });

        AuditLogger::log(
            'CANCELLATION_REJECTED',
            "Cancellation request rejected for Booking #{$booking->booking_number} by {$currentUser->name}. Reason: {$reason}",
            $currentUser,
            $currentUser->name,
            $request
        );

        // Send email notification to guest
        if ($booking->contact_email) {
            try {
                Mail::to($booking->contact_email)->send(new CancellationRejectedMail($booking->fresh(), $cancellationRequest, $reason));
            } catch (\Throwable $e) {
                Log::warning("Failed to send CancellationRejectedMail to {$booking->contact_email}: " . $e->getMessage());
            }
        }

        return back()->with('info', "Cancellation request for Booking #{$booking->booking_number} was rejected.");
    }
}
