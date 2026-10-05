@extends('layouts.admin')

@section('title', 'Pending Refund Requests | Camp FreedivePH')

@section('breadcrumb')
    <a href="{{ portal_route('payments.index') }}" class="text-[#6E6E73] hover:text-[#780000] font-medium transition-colors">Payments &amp; Refunds</a>
    <svg class="w-3.5 h-3.5 text-[#8E8E93] shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m9 18 6-6-6-6"/></svg>
    <span class="font-bold text-[#1D1D1F]">Refund Requests</span>
@endsection

@section('content')
<div class="space-y-6 text-sm" x-data="{
    rejectModalOpen: false,
    rejectFormAction: '',
    rejectBookingNumber: '',
    rejectAmount: '',
    rejectNotes: '',
    openRejectModal(url, bookingNumber, amount) {
        this.rejectFormAction = url;
        this.rejectBookingNumber = bookingNumber;
        this.rejectAmount = amount;
        this.rejectNotes = '';
        this.rejectModalOpen = true;
    }
}">
    
    <!-- Top Header -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl sm:text-3xl font-extrabold text-[#1D1D1F] tracking-tight">Pending Refund Requests</h1>
        </div>

        <div class="flex items-center gap-2.5 flex-wrap">
            <a href="{{ route('admin.bookings.requests') }}" class="btn-secondary px-3.5 py-2 text-sm sm:text-sm font-semibold">
                Reschedule/Cancel Requests
            </a>
            <a href="{{ route('admin.payments.index') }}" class="btn-secondary px-3.5 py-2 text-sm sm:text-sm font-semibold">
                Payments Ledger
            </a>
        </div>
    </div>

    <!-- Pending Refund Queue -->
    <div class="space-y-3.5">
        <div class="flex items-center justify-between">
            <h2 class="font-bold text-base text-[#1D1D1F] flex items-center gap-2">
                <span>Pending Refund Approvals</span>
                @if(count($pendingRefunds) > 0)
                    <span class="px-2.5 py-0.5 rounded-full text-xs font-bold bg-[#780000] text-white">
                        {{ count($pendingRefunds) }} Action Required
                    </span>
                @endif
            </h2>
        </div>

        <!-- Pending Refund Requests -->
        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4 sm:gap-5">
            @forelse($pendingRefunds as $req)
            @php
                $policy = $policies[$req->id] ?? null;
                $payment = $req->payment;
                $booking = $req->booking;
                $isEligible = ($policy && ($policy['refund_percentage'] ?? 0) > 0);
                $claimAmount = $payment->amount ?? 0;
            @endphp
            <div class="bg-white rounded-xl border border-[#E5E5EA] p-4 sm:p-5 shadow-2xs flex flex-col justify-between space-y-4">
                
                <!-- Request Header -->
                <div>
                    <div class="border-b border-[#F2F2F7] pb-3 space-y-2.5">
                        <!-- Top Row: Class Badge & Pending Status beside each other -->
                        <div class="flex items-center gap-2 flex-wrap">
                            <span class="text-xs font-bold px-2.5 py-0.5 rounded-full bg-[#F2F2F7] text-[#3A3A3C]">
                                {{ $booking->formatted_class_type }}
                            </span>

                            <span class="px-2.5 py-0.5 rounded-full text-xs font-bold uppercase tracking-wider bg-amber-50 text-amber-800 shrink-0">
                                Pending
                            </span>
                        </div>

                        <!-- Booking Number (Bigger Font) & Requested Date Below It -->
                        <div>
                            <a href="{{ route('admin.bookings.show', $booking) }}" class="font-mono font-black text-xl sm:text-2xl text-[#780000] hover:underline block leading-tight tracking-tight">
                                {{ $booking->booking_number }}
                            </a>
                            <div class="text-xs text-[#8E8E93] mt-1 font-medium">
                                Requested on <strong class="text-[#1D1D1F] font-semibold">{{ $req->requested_at ? $req->requested_at->format('M d, Y') : $req->created_at->format('M d, Y') }}</strong>
                            </div>
                        </div>
                    </div>

                    <!-- Students Connected to Booking (All Students Listed, No Count) -->
                    @php
                        $studentNames = $booking->participants->pluck('name')->filter()->values();
                        $studentsDisplay = $studentNames->isNotEmpty() ? $studentNames->implode(', ') : $booking->contact_name;
                    @endphp
                    <div class="mt-3">
                        <span class="font-extrabold text-sm text-[#1D1D1F] leading-snug block">
                            {{ $studentsDisplay }}
                        </span>
                    </div>

                    <!-- Key Details Grid (2x2) Matching Reference Style -->
                    <div class="mt-3 grid grid-cols-2 gap-3">
                        <div>
                            <span class="text-[10px] font-bold uppercase tracking-wider text-[#8E8E93] block">DIVE DATE</span>
                            <span class="text-xs sm:text-sm font-bold text-[#1D1D1F] block mt-0.5 leading-snug">
                                {{ $booking->formatted_date_range }}
                            </span>
                        </div>
                        <div>
                            <span class="text-[10px] font-bold uppercase tracking-wider text-[#8E8E93] block">REFUND CLAIM AMOUNT</span>
                            <span class="text-xs sm:text-sm font-bold text-[#1D1D1F] block mt-0.5 leading-snug">
                                ₱{{ number_format($claimAmount, 2) }}
                            </span>
                        </div>
                        <div>
                            <span class="text-[10px] font-bold uppercase tracking-wider text-[#8E8E93] block">METHOD</span>
                            <span class="text-xs sm:text-sm font-bold text-[#1D1D1F] uppercase block mt-0.5 leading-snug">
                                {{ $payment->payment_method ?? 'GCash' }}
                            </span>
                        </div>
                        <div>
                            <span class="text-[10px] font-bold uppercase tracking-wider text-[#8E8E93] block">TYPE</span>
                            <span class="text-xs sm:text-sm font-bold text-[#1D1D1F] capitalize block mt-0.5 leading-snug">
                                {{ $payment->payment_type ?? 'Downpayment' }}
                            </span>
                        </div>
                    </div>

                    <!-- Policy Status / Notes -->
                    <div class="mt-3 space-y-1">
                        <span class="text-[10px] font-bold uppercase tracking-wider text-[#8E8E93] block">POLICY STATUS</span>
                        <div class="flex items-center gap-1.5 font-bold text-xs {{ $isEligible ? 'text-emerald-800' : 'text-amber-900' }}">
                            @if($isEligible)
                                <span>Eligible for Full Refund</span>
                            @else
                                <span>Refund Not Available Under Policy</span>
                            @endif
                        </div>
                        @if($req->notes)
                            <p class="text-xs text-[#6E6E73] italic pt-0.5">
                                Note: "{{ $req->notes }}"
                            </p>
                        @endif
                    </div>
                </div>

                <!-- Request Actions -->
                <div class="space-y-2">
                    <div class="flex items-center gap-2">
                        <!-- Reject Action -->
                        <button type="button" 
                                @click="openRejectModal('{{ route('admin.payments.refunds.reject', $req) }}', '{{ $booking->booking_number }}', '₱{{ number_format($claimAmount, 2) }}')"
                                class="btn-secondary py-2 px-3 text-sm font-semibold text-center shrink-0">
                            Reject
                        </button>

                        @if(!$isEligible)
                            <!-- Forfeit Deposit Action -->
                            <form action="{{ route('admin.payments.refunds.forfeit', $req) }}" method="POST" class="flex-1">
                                @csrf
                                <input type="hidden" name="forfeit_reason" value="cancellation_outside_policy_window">
                                <button type="submit" 
                                        onclick="return confirm('Forfeit ₱{{ number_format($claimAmount, 2) }} deposit under camp cancellation policy for {{ $booking->booking_number }}?')"
                                        class="btn-secondary w-full py-2 text-sm font-bold text-center">
                                    Forfeit Deposit (₱0)
                                </button>
                            </form>
                        @else
                            <!-- Approve Refund Action -->
                            <form action="{{ route('admin.payments.refunds.approve', $req) }}" method="POST" class="flex-1">
                                @csrf
                                <button type="submit" 
                                        onclick="return confirm('Approve & execute ₱{{ number_format($claimAmount, 2) }} refund via PayMongo for {{ $booking->booking_number }}?')"
                                        class="btn-primary w-full py-2 text-sm font-bold shadow-2xs text-center">
                                    Approve & Execute (₱{{ number_format($claimAmount, 2) }})
                                </button>
                            </form>
                        @endif
                    </div>

                    @if(!$isEligible)
                        <!-- Policy Override Refund Action -->
                        <form action="{{ route('admin.payments.refunds.approve', $req) }}" method="POST">
                            @csrf
                            <input type="hidden" name="notes" value="Administrative policy override: 100% refund approved">
                            <button type="submit" 
                                    onclick="return confirm('Override policy and execute full refund of ₱{{ number_format($claimAmount, 2) }} via PayMongo for {{ $booking->booking_number }}?')"
                                    class="btn-danger w-full py-1.5 text-sm font-bold text-center">
                                Override Policy: Execute Refund (₱{{ number_format($claimAmount, 2) }})
                            </button>
                        </form>
                    @endif
                </div>

            </div>
            @empty
            <div class="col-span-full bg-white rounded-xl border border-[#E5E5EA] p-10 text-center space-y-3">
                <div class="w-10 h-10 rounded-full bg-[#F8EAEA] text-[#780000] border border-[#780000]/30 flex items-center justify-center mx-auto">
                    <svg class="w-5 h-5 text-[#780000]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                </div>
                <h3 class="font-bold text-base text-[#1D1D1F]">No Pending Refund Requests</h3>
                <p class="text-sm text-[#6E6E73] max-w-md mx-auto">
                    All customer cancellation refund claims have been reviewed and processed.
                </p>
            </div>
            @endforelse
        </div>
    </div>

    <!-- Processed Refund History -->
    <div class="space-y-3 pt-6 border-t border-[#E5E5EA]">
        <div class="flex items-center justify-between">
            <div>
                <h2 class="font-bold text-base text-[#1D1D1F]">Processed Refund History</h2>
                <p class="text-sm text-[#6E6E73] mt-0.5">Audit ledger of approved, forfeited, and rejected refund transactions.</p>
            </div>
        </div>

        <div class="bg-white rounded-xl border border-[#E5E5EA] overflow-hidden shadow-2xs">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="bg-[#F2F2F7] border-b border-[#E5E5EA] text-[#6E6E73] font-bold">
                        <tr>
                            <th class="py-3 px-4 text-left">Booking #</th>
                            <th class="py-3 px-4 text-left">Lead Guest</th>
                            <th class="py-3 px-4 text-left">Amount</th>
                            <th class="py-3 px-4 text-left">Resolution</th>
                            <th class="py-3 px-4 text-left">Processed By</th>
                            <th class="py-3 px-4 text-left">Date Processed</th>
                            <th class="py-3 px-4 text-left">Gateway Reference</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[#E5E5EA]">
                        @forelse($processedRefunds as $pr)
                        <tr class="hover:bg-[#F2F2F7] transition-colors">
                            <td class="py-3 px-4 text-left">
                                <a href="{{ route('admin.bookings.show', $pr->booking) }}" class="font-mono font-bold text-[#780000] hover:underline">
                                    {{ $pr->booking->booking_number }}
                                </a>
                            </td>
                            <td class="py-3 px-4 text-left font-medium text-[#1D1D1F]">
                                {{ $pr->booking->contact_name }}
                            </td>
                            <td class="py-3 px-4 text-left font-bold text-[#1D1D1F]">
                                ₱{{ number_format($pr->payment->amount ?? 0, 2) }}
                            </td>
                            <td class="py-3 px-4 text-left">
                                @if($pr->status === 'approved')
                                    <span class="px-2 py-0.5 rounded-md text-sm font-bold bg-emerald-50 text-emerald-700">
                                        Refunded
                                    </span>
                                @elseif($pr->status === 'forfeited')
                                    <span class="px-2 py-0.5 rounded-md text-sm font-bold bg-[#F2F2F7] text-[#1D1D1F]">
                                        Forfeited
                                    </span>
                                @else
                                    <span class="px-2 py-0.5 rounded-md text-sm font-bold bg-rose-50 text-rose-700">
                                        Rejected
                                    </span>
                                @endif
                            </td>
                            <td class="py-3 px-4 text-[#1D1D1F]">
                                {{ $pr->reviewer->name ?? 'System Administrator' }}
                            </td>
                            <td class="py-3 px-4 text-[#6E6E73] whitespace-nowrap">
                                {{ $pr->reviewed_at ? $pr->reviewed_at->format('M d, Y g:i A') : '-' }}
                            </td>
                            <td class="py-3 px-4 font-mono text-sm text-[#6E6E73]">
                                {{ $pr->paymongo_refund_id ?? ($pr->status === 'forfeited' ? 'Forfeited to Camp' : 'N/A') }}
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="7" class="py-8 text-center text-sm text-[#8E8E93]">
                                No processed refund records found.
                            </td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{ $processedRefunds->links() }}
        </div>
    </div>

    <!-- Rejection Reason Modal -->
    <div x-show="rejectModalOpen" 
         x-cloak 
         class="fixed inset-0 z-50 flex justify-end bg-black/40">
        <div class="dive-side-panel h-full overflow-y-auto overscroll-contain bg-white sm:max-w-md w-full p-6 space-y-4 shadow-2xl border-l border-[#E5E5EA]" 
             @click.outside="rejectModalOpen = false">
            <div class="flex items-center justify-between">
                <h3 class="font-bold text-base text-[#1D1D1F]">
                    Reject Refund Request
                </h3>
                <button type="button" @click="rejectModalOpen = false" aria-label="Close reject refund modal" class="text-base font-bold text-[#8E8E93] hover:text-[#1D1D1F]">✕</button>
            </div>

            <form :action="rejectFormAction" method="POST" class="space-y-4">
                @csrf
                <p class="text-sm text-[#6E6E73]">
                    Please provide a reason for rejecting the refund request (<span class="font-bold text-[#1D1D1F]" x-text="rejectAmount"></span>) for Booking #<strong class="text-[#1D1D1F]" x-text="rejectBookingNumber"></strong>:
                </p>

                <div>
                    <textarea name="notes" 
                              x-model="rejectNotes" 
                              rows="3" 
                              required
                              placeholder="e.g. Request does not meet cancellation criteria or chargeback already settled..." 
                              class="w-full px-3 py-2 rounded-lg border border-[#D1D1D6] text-sm text-[#1D1D1F] bg-white"></textarea>
                </div>

                <div class="flex items-center justify-end gap-2.5 pt-2 border-t border-[#E5E5EA]">
                    <button type="button" @click="rejectModalOpen = false" class="btn-secondary px-3.5 py-1.5 text-sm font-semibold">
                        Cancel
                    </button>
                    <button type="submit" class="btn-danger px-4 py-1.5 text-sm font-bold shadow-2xs">
                        Confirm Rejection
                    </button>
                </div>
            </form>
        </div>
    </div>

</div>
@endsection
