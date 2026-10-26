@extends('layouts.admin')

@section('title', 'Payment Exceptions | Camp FreedivePH')

@section('breadcrumb')
    <a href="{{ portal_route('payments.index') }}" class="text-[#6E6E73] hover:text-[#780000] font-medium transition-colors">Payments &amp; Refunds</a>
    <svg class="w-3.5 h-3.5 text-[#8E8E93] shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m9 18 6-6-6-6"/></svg>
    <span class="font-bold text-[#1D1D1F]">Payment Exceptions</span>
@endsection

@section('content')
@php
    $reasons = [
        'capacity_unavailable' => 'Paid after the date filled up',
        'paid_after_cancellation' => 'Paid after the booking was cancelled',
        'amount_mismatch' => 'Amount differs from the downpayment',
        'paid_again' => 'Paid again (booking already settled)',
    ];
@endphp
<div class="space-y-6 text-sm">
    <div>
        <h1 class="text-2xl sm:text-3xl font-extrabold text-[#1D1D1F] tracking-tight">Payment Exceptions</h1>
        <p class="text-[#6E6E73] mt-1">Money that was received but could not simply be applied. It is kept as received and counted as paid; nothing here moves money. A paid booking waiting for a place is confirmed from its booking page (Change status → Confirmed) once a place is free; refunds go through Refund Cases.</p>
    </div>

    <div class="bg-white rounded-xl border border-[#E5E5EA] p-4 shadow-2xs">
        <h2 class="font-bold text-base mb-3">Paid, waiting for a place ({{ $unallocated->count() }})</h2>
        <table class="w-full text-left">
            <thead class="text-[#6E6E73] text-xs uppercase tracking-wider"><tr><th class="py-1">Booking</th><th class="py-1">Date</th><th class="py-1">People</th><th class="py-1 text-right">Received</th></tr></thead>
            <tbody class="divide-y divide-[#E5E5EA]">
                @forelse($unallocated as $booking)
                    <tr>
                        <td class="py-1.5"><a class="font-semibold text-[#780000]" href="{{ portal_route('bookings.show', $booking) }}">{{ $booking->booking_number }}</a> · {{ $booking->contact_name }}</td>
                        <td class="py-1.5">{{ $booking->start_date?->format('M d, Y') }}</td>
                        <td class="py-1.5">{{ $booking->participants_count }}</td>
                        <td class="py-1.5 text-right">₱{{ number_format((float) $booking->payments->whereIn('status', \App\Models\Payment::RECEIPT_STATUSES)->sum('amount'), 2) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="py-2 text-[#6E6E73]">None.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="bg-white rounded-xl border border-[#E5E5EA] p-4 shadow-2xs">
        <h2 class="font-bold text-base mb-3">Payments needing review ({{ $flagged->count() }})</h2>
        <table class="w-full text-left">
            <thead class="text-[#6E6E73] text-xs uppercase tracking-wider"><tr><th class="py-1">Booking</th><th class="py-1">Reason</th><th class="py-1 text-right">Amount</th><th class="py-1">PayMongo payment</th><th class="py-1">Flagged</th></tr></thead>
            <tbody class="divide-y divide-[#E5E5EA]">
                @forelse($flagged as $payment)
                    <tr>
                        <td class="py-1.5">@if($payment->booking)<a class="font-semibold text-[#780000]" href="{{ portal_route('bookings.show', $payment->booking) }}">{{ $payment->booking->booking_number }}</a> ({{ str_replace('_', ' ', $payment->booking->status) }})@endif</td>
                        <td class="py-1.5">{{ $reasons[$payment->review_reason] ?? $payment->review_reason }}</td>
                        <td class="py-1.5 text-right">₱{{ number_format((float) $payment->amount, 2) }}</td>
                        <td class="py-1.5 font-mono text-xs">{{ $payment->paymongo_payment_id ?? '—' }}</td>
                        <td class="py-1.5">{{ $payment->review_flagged_at?->setTimezone('Asia/Manila')->format('M d, Y g:i A') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="py-2 text-[#6E6E73]">None.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="bg-white rounded-xl border border-[#E5E5EA] p-4 shadow-2xs">
        <h2 class="font-bold text-base mb-3">Booking confirmations not delivered ({{ $failedConfirmations->count() }})</h2>
        <table class="w-full text-left">
            <thead class="text-[#6E6E73] text-xs uppercase tracking-wider"><tr><th class="py-1">Booking</th><th class="py-1">Attempts</th><th class="py-1">Last error</th><th class="py-1">Action</th></tr></thead>
            <tbody class="divide-y divide-[#E5E5EA]">
                @forelse($failedConfirmations as $notification)
                    <tr>
                        <td class="py-1.5">{{ $notification->booking?->booking_number }} ({{ str_replace('_', ' ', (string) $notification->booking?->status) }})</td>
                        <td class="py-1.5">{{ $notification->attempts }}</td>
                        <td class="py-1.5">{{ $notification->last_error }}</td>
                        <td class="py-1.5">
                            @if($notification->booking)
                                <form method="POST" action="{{ portal_route('payments.exceptions.resend', $notification->booking) }}">
                                    @csrf
                                    <button class="btn-secondary px-2 py-1 text-xs font-semibold">Send again</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="py-2 text-[#6E6E73]">None.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
