<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\CheckoutNotification;
use App\Models\Payment;
use App\Services\AuditLogger;
use App\Services\Checkout\CheckoutNotifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Owner/admin queue of received money that could not simply be applied to a booking (Batch 3-II A), so it never
 * disappears from view: legacy bookings paid after their date filled up (paid_unallocated), payments for cancelled
 * bookings, different amounts or repeat payments, and legacy confirmations that could not be delivered.
 * Placing a paid_unallocated booking is a status change (Bookings, capacity checked); refunds stay in Refund Cases.
 * Nothing here moves money.
 */
class PaymentExceptionController extends Controller
{
    public function index(): View
    {
        return view('admin.payments.exceptions', [
            'unallocated' => Booking::where('status', 'paid_unallocated')->withCount('participants')->with('payments')->orderBy('start_date')->get(),
            'flagged' => Payment::awaitingReview()->with('booking')->latest('review_flagged_at')->get(),
            'failedConfirmations' => CheckoutNotification::whereNotNull('booking_id')->where('status', CheckoutNotification::FAILED)->with('booking')->latest('id')->get(),
        ]);
    }

    /** Queue a legacy booking's failed confirmation again (still only delivered while the booking holds a place). */
    public function resend(Request $request, Booking $booking, CheckoutNotifier $notifier): RedirectResponse
    {
        $count = $notifier->retryFailedForBooking($booking);
        AuditLogger::log('BOOKING_NOTIFICATION_RETRY', "Booking {$booking->booking_number}: {$count} failed confirmation(s) queued again by staff.", $request->user(), null, $request);

        return back()->with('success', "{$count} message(s) queued again. They are sent only while the booking is confirmed.");
    }
}
