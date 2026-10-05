<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\PaymentStatusLog;
use App\Models\RefundRequest;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use App\Http\Requests\Admin\Payments\StorePaymentRequest;
use App\Http\Requests\Admin\Payments\SettleBalanceRequest;

class PaymentController extends Controller
{
    /**
     * Display a listing of payment transactions.
     */
    public function index(Request $request): View
    {
        $query = Payment::with(['booking.participants', 'createdBy'])->latest('created_at');

        // Search filter (Booking #, Transaction ID, PayMongo ID, Contact Name)
        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('transaction_id', 'like', "%{$search}%")
                  ->orWhere('paymongo_payment_id', 'like', "%{$search}%")
                  ->orWhere('paymongo_refund_id', 'like', "%{$search}%")
                  ->orWhereHas('booking', function ($bq) use ($search) {
                      $bq->where('booking_number', 'like', "%{$search}%")
                         ->orWhere('contact_name', 'like', "%{$search}%")
                         ->orWhere('contact_phone', 'like', "%{$search}%");
                  });
            });
        }

        // Status filter
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        // Payment Stage filter
        if ($request->filled('stage')) {
            $query->where('payment_type', $request->input('stage'));
        }

        // Payment Method filter
        if ($request->filled('method')) {
            $query->where('payment_method', $request->input('method'));
        }

        // Date range filter
        if ($request->filled('date_from')) {
            $query->whereDate('paid_at', '>=', $request->input('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('paid_at', '<=', $request->input('date_to'));
        }

        $perPage = max(5, min(100, (int) $request->input('per_page', 10)));
        $payments = $query->paginate($perPage)->withQueryString();

        $stats = [
            'total_gross' => Payment::whereIn('status', ['paid', 'completed'])->sum('amount'),
            'total_net' => Payment::whereIn('status', ['paid', 'completed'])->sum('net_amount'),
            'total_refunded' => Payment::where('status', 'refunded')->sum('amount_refunded') ?: Payment::where('status', 'refunded')->sum('amount'),
            'total_forfeited' => Payment::where('is_forfeited', true)->orWhere('status', 'forfeited')->sum('amount'),
            'pending_refunds' => RefundRequest::where('status', 'pending')->count(),
        ];

        return view('admin.payments.index', compact('payments', 'stats'));
    }

    /**
     * Show manual payment creation form.
     */
    public function create(Request $request): View
    {
        $selectedBookingId = $request->query('booking_id');
        $selectedBooking = $selectedBookingId ? Booking::find($selectedBookingId) : null;

        $eligibleBookings = Booking::where('balance_amount', '>', 0)
            ->whereIn('status', ['confirmed', 'rescheduled'])
            ->latest()
            ->take(30)
            ->get();

        return view('admin.payments.create', compact('selectedBooking', 'eligibleBookings'));
    }

    /**
     * Store a manually entered payment record.
     */
    public function store(StorePaymentRequest $request): RedirectResponse
    {
        $currentUser = Auth::user();

        $validated = $request->validated();

        $booking = Booking::findOrFail($validated['booking_id']);
        $amount = (float) $validated['amount'];
        $txnId = $validated['transaction_id'] ?: ('OFFLINE-TXN-' . strtoupper(bin2hex(random_bytes(5))));

        $payment = DB::transaction(function () use ($booking, $validated, $amount, $txnId, $currentUser) {
            $payment = Payment::create([
                'booking_id' => $booking->id,
                'payment_method' => $validated['payment_method'],
                'transaction_id' => $txnId,
                'amount' => $amount,
                'fee_amount' => 0.00,
                'net_amount' => $amount,
                'payment_type' => $validated['payment_type'],
                'status' => 'completed',
                'paid_at' => now(),
                'created_by' => $currentUser->id,
            ]);

            // Recalculate balance on booking
            $totalPaid = $booking->payments()->whereIn('status', ['completed', 'paid'])->sum('amount');
            $newBalance = max(0, $booking->total_amount - $totalPaid);
            $booking->update(['balance_amount' => $newBalance]);

            // Status log
            PaymentStatusLog::create([
                'payment_id' => $payment->id,
                'old_status' => 'new',
                'new_status' => 'completed',
                'changed_by' => $currentUser->id,
                'note' => 'Manual payment received by ' . $currentUser->name . ($validated['notes'] ? ' - ' . $validated['notes'] : ''),
                'created_at' => now(),
            ]);

            return $payment;
        });

        AuditLogger::log(
            'PAYMENT_RECORDED_MANUALLY',
            "Payment of ₱" . number_format($amount, 2) . " recorded manually for Booking #{$booking->booking_number} ({$validated['payment_method']}) by {$currentUser->name}",
            $currentUser,
            $currentUser->name,
            $request
        );

        return redirect()->route('admin.payments.show', $payment)
            ->with('success', "Payment of ₱" . number_format($amount, 2) . " recorded successfully!");
    }

    /**
     * Display full payment detail view.
     */
    public function show(Payment $payment): View
    {
        $payment->load(['booking.participants', 'booking.payments', 'statusLogs.user', 'refundRequests.reviewer', 'createdBy']);

        return view('admin.payments.show', compact('payment'));
    }

    /**
     * Settle remaining balance for a booking.
     */
    public function settleBalance(SettleBalanceRequest $request, Payment $payment): RedirectResponse
    {
        $currentUser = Auth::user();
        $booking = $payment->booking;

        $validated = $request->validated();

        $amount = (float) $validated['amount'];
        $txnId = $validated['transaction_id'] ?: ('BAL-TXN-' . strtoupper(bin2hex(random_bytes(5))));

        DB::transaction(function () use ($booking, $validated, $amount, $txnId, $currentUser) {
            $newPayment = Payment::create([
                'booking_id' => $booking->id,
                'payment_method' => $validated['payment_method'],
                'transaction_id' => $txnId,
                'amount' => $amount,
                'fee_amount' => 0.00,
                'net_amount' => $amount,
                'payment_type' => 'balance_settlement',
                'status' => 'completed',
                'paid_at' => now(),
                'created_by' => $currentUser->id,
            ]);

            $totalPaid = $booking->payments()->whereIn('status', ['completed', 'paid'])->sum('amount');
            $newBalance = max(0, $booking->total_amount - $totalPaid);
            $booking->update(['balance_amount' => $newBalance]);

            PaymentStatusLog::create([
                'payment_id' => $newPayment->id,
                'old_status' => 'new',
                'new_status' => 'completed',
                'changed_by' => $currentUser->id,
                'note' => "Balance settlement of ₱" . number_format($amount, 2) . " received by {$currentUser->name}" . ($validated['notes'] ? " - {$validated['notes']}" : ''),
                'created_at' => now(),
            ]);
        });

        AuditLogger::log(
            'BALANCE_SETTLED',
            "Balance settlement of ₱" . number_format($amount, 2) . " processed for Booking #{$booking->booking_number} by {$currentUser->name}",
            $currentUser,
            $currentUser->name,
            $request
        );

        return back()->with('success', "Balance settlement of ₱" . number_format($amount, 2) . " successfully recorded!");
    }
}
