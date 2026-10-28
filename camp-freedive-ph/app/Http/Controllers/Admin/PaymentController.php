<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\BookingRefund;
use App\Models\Payment;
use App\Services\Refunds\FinancialTotals;
use App\Models\RefundRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use App\Http\Requests\Admin\Payments\StorePaymentRequest;
use App\Http\Requests\Admin\Payments\SettleBalanceRequest;
use App\Services\Payment\ManualPaymentService;
use RuntimeException;

class PaymentController extends Controller
{
    /**
     * List of payments.
     */
    public function index(Request $request): View
    {
        $query = Payment::with(['booking.participants', 'createdBy'])->latest('created_at');

        // Search (booking #, transaction id, PayMongo id, name)
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

        // Filter by status
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        // Filter by payment stage
        if ($request->filled('stage')) {
            $query->where('payment_type', $request->input('stage'));
        }

        // Filter by payment method
        if ($request->filled('method')) {
            $query->where('payment_method', $request->input('method'));
        }

        // Filter by date range
        if ($request->filled('date_from')) {
            $query->whereDate('paid_at', '>=', $request->input('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('paid_at', '<=', $request->input('date_to'));
        }

        $perPage = max(5, min(100, (int) $request->input('per_page', 10)));
        $payments = $query->paginate($perPage)->withQueryString();

        // Paid = receipts before refunds; Refunded = only confirmed refunds (see FinancialTotals)
        $stats = [
            'total_gross' => FinancialTotals::paid(),
            'total_net' => Payment::whereIn('status', Payment::RECEIPT_STATUSES)->sum('net_amount'),
            'total_refunded' => FinancialTotals::refunded(),
            'total_forfeited' => Payment::where('is_forfeited', true)->orWhere('status', 'forfeited')->sum('amount'),
            'pending_refunds' => RefundRequest::where('status', 'pending')->count() + BookingRefund::whereIn('status', BookingRefund::OPEN_STATUSES)->count(),
        ];
        $stats['total_retained'] = round($stats['total_gross'] - $stats['total_refunded'], 2);
        $position = FinancialTotals::position();

        return view('admin.payments.index', compact('payments', 'stats', 'position'));
    }

    /**
     * Form to add a payment manually.
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
     * Save a manual payment (B4: ManualPaymentService - owner/admin, payable booking, never more than is due, unique
     * non-PayMongo reference, stale-form check). A manual payment never confirms a booking by itself.
     */
    public function store(StorePaymentRequest $request, ManualPaymentService $payments): RedirectResponse
    {
        $validated = $request->validated();
        try {
            $payment = $payments->record(Booking::findOrFail($validated['booking_id']), Auth::user(), $validated);
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.payments.show', $payment)
            ->with('success', 'Payment of ₱' . number_format((float) $payment->amount, 2) . ' recorded successfully!');
    }

    /**
     * Payment details page.
     */
    public function show(Payment $payment): View
    {
        $payment->load(['booking.participants', 'booking.payments', 'statusLogs.user', 'refundRequests.reviewer', 'createdBy', 'checkoutIntent', 'paymentExceptions', 'providerIdentity']);

        return view('admin.payments.show', compact('payment'));
    }

    /**
     * Pay the remaining balance of a booking (same safeguards as store()).
     */
    public function settleBalance(SettleBalanceRequest $request, Payment $payment, ManualPaymentService $payments): RedirectResponse
    {
        $booking = $payment->booking;
        if ($booking === null) {
            // An unapplied checkout receipt (no place was left): there is no booking balance to settle
            return back()->with('error', 'This payment is not applied to any booking yet (a paid checkout under a payment exception), so no balance can be recorded against it. Nothing was saved.');
        }
        try {
            $settled = $payments->record($booking, Auth::user(), $request->validated() + ['payment_type' => 'balance_settlement'], 'BAL-TXN');
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Balance settlement of ₱' . number_format((float) $settled->amount, 2) . ' successfully recorded!');
    }
}
