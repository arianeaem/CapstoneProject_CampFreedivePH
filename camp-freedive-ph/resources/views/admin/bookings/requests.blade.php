@extends('layouts.admin')

@section('title', 'Pending Guest Requests | Camp FreedivePH')

@section('breadcrumb')
    <a href="{{ portal_route('bookings.index') }}" class="text-[#6E6E73] hover:text-[#780000] font-medium transition-colors">Bookings</a>
    <svg class="w-3.5 h-3.5 text-[#8E8E93] shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m9 18 6-6-6-6"/></svg>
    <span class="font-bold text-[#1D1D1F]">Pending Guest Requests</span>
@endsection

@section('content')
<div class="space-y-6 text-sm" x-data="{
    rejectModalOpen: false,
    rejectFormAction: '',
    rejectBookingNumber: '',
    rejectRequestType: '',
    rejectNotes: '',
    openRejectModal(action, bookingNum, type) {
        this.rejectFormAction = action;
        this.rejectBookingNumber = bookingNum;
        this.rejectRequestType = type;
        this.rejectNotes = '';
        this.rejectModalOpen = true;
    }
}">
    
    <!-- Top Header -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl sm:text-3xl font-extrabold text-[#1D1D1F] tracking-tight">Pending Guest Requests</h1>
        </div>

        <div class="flex items-center gap-2.5 flex-wrap">
            <a href="{{ route('admin.bookings.index') }}" class="btn-secondary px-3.5 py-2 text-sm sm:text-sm font-semibold">
                View All Bookings
            </a>
        </div>
    </div>

    <!-- Pending Reschedule Requests -->
    <div class="space-y-3.5">
        <div class="flex items-center justify-between">
            <h2 class="font-bold text-base text-[#1D1D1F] flex items-center gap-2">
                <span>Reschedule Requests</span>
                @if(count($pendingReschedules) > 0)
                    <span class="px-2.5 py-0.5 rounded-full text-xs font-bold bg-amber-50 text-amber-800">
                        {{ count($pendingReschedules) }} Pending
                    </span>
                @endif
            </h2>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4 sm:gap-5">
            @forelse($pendingReschedules as $req)
            @php
                $booking = $req->booking;
                $resPolicy = $reschedulePolicies[$req->id] ?? null;
                $studentNames = $booking->participants->pluck('name')->filter()->values();
                $studentsDisplay = $studentNames->isNotEmpty() ? $studentNames->implode(', ') : $booking->contact_name;
            @endphp
            <div class="bg-white rounded-xl border border-[#E5E5EA] p-4 sm:p-5 shadow-2xs flex flex-col justify-between space-y-4">
                <!-- Request Header -->
                <div>
                    <div class="border-b border-[#F2F2F7] pb-3 space-y-1">
                        <!-- Booking Number -->
                        <a href="{{ route('admin.bookings.show', $booking) }}" class="font-mono font-black text-xl sm:text-2xl text-[#780000] hover:underline block leading-tight tracking-tight">
                            {{ $booking->booking_number }}
                        </a>
                        <div class="text-xs font-bold text-[#1D1D1F]">
                            {{ $booking->formatted_class_type }}
                        </div>
                        <div class="text-xs text-[#8E8E93] font-medium">
                            Requested on <strong class="text-[#1D1D1F] font-semibold">{{ $req->requested_at ? $req->requested_at->format('M d, Y') : $req->created_at->format('M d, Y') }}</strong>
                        </div>
                    </div>

                    <!-- Students Connected to Booking -->
                    <div class="mt-3">
                        <span class="font-extrabold text-sm text-[#1D1D1F] leading-snug block">
                            {{ $studentsDisplay }}
                        </span>
                    </div>

                    <!-- Key Details Grid (2x2) -->
                    <div class="mt-3 grid grid-cols-2 gap-3">
                        <div>
                            <span class="text-[10px] font-bold uppercase tracking-wider text-[#8E8E93] block">CURRENT DATES</span>
                            <span class="text-xs sm:text-sm font-bold text-[#1D1D1F] block mt-0.5 leading-snug">
                                {{ $booking->formatted_date_range }}
                            </span>
                        </div>
                        <div>
                            <span class="text-[10px] font-bold uppercase tracking-wider text-[#8E8E93] block">REQUESTED DATES</span>
                            <span class="text-xs sm:text-sm font-bold text-emerald-700 block mt-0.5 leading-snug">
                                {{ $req->requested_start_date->year === $req->requested_end_date->year ? $req->requested_start_date->format('M d') . ' - ' . $req->requested_end_date->format('M d, Y') : $req->requested_start_date->format('M d, Y') . ' - ' . $req->requested_end_date->format('M d, Y') }}
                            </span>
                        </div>
                    </div>

                    @if($req->reason)
                        <div class="mt-3 space-y-0.5">
                            <span class="text-[10px] font-bold uppercase tracking-wider text-[#8E8E93] block">GUEST REASON</span>
                            <p class="text-xs text-[#6E6E73] italic">"{{ $req->reason }}"</p>
                        </div>
                    @endif
                </div>

                <!-- Reschedule Actions -->
                <div class="flex items-center gap-2">
                    <button type="button" 
                            @click="openRejectModal('{{ route('admin.bookings.requests.reschedule.reject', $req) }}', '{{ $booking->booking_number }}', 'Reschedule')"
                            class="btn-secondary flex-1 py-2 text-sm font-semibold text-center">
                        Reject
                    </button>

                    <form action="{{ route('admin.bookings.requests.reschedule.approve', $req) }}" method="POST" class="flex-1">
                        @csrf
                        <button type="submit" 
                                onclick="return confirm('Approve reschedule for {{ $booking->booking_number }} to {{ $req->requested_start_date->format('M d, Y') }}?')"
                                class="btn-primary w-full py-2 text-sm font-bold shadow-2xs text-center">
                            Approve
                        </button>
                    </form>
                </div>
            </div>
            @empty
            <div class="col-span-full bg-white rounded-xl border border-[#E5E5EA] p-6 text-center text-sm text-[#8E8E93]">
                No pending guest reschedule requests.
            </div>
            @endforelse
        </div>
    </div>

    <!-- Cancellation Requests (Direct 1-Step Cancellation & Refund Execution) -->
    <div class="space-y-3.5 pt-4">
        <div class="flex items-center justify-between">
            <h2 class="font-bold text-base text-[#1D1D1F] flex items-center gap-2">
                <span>Cancellation Requests</span>
                @if(count($pendingCancellations) > 0)
                    <span class="px-2.5 py-0.5 rounded-full text-xs font-bold bg-[#780000] text-white">
                        {{ count($pendingCancellations) }} Pending
                    </span>
                @endif
            </h2>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4 sm:gap-5">
            @forelse($pendingCancellations as $req)
            @php
                $booking = $req->booking;
                $payment = $booking->payments->first();
                $policy = $cancellationPolicies[$req->id] ?? null;
                $isEligible = ($policy && ($policy['refund_percentage'] ?? 0) > 0);
                $recRefund = $policy['calculated_refund'] ?? $req->calculated_refund_amount;
                $claimAmount = $booking->paid_amount ?: ($payment->amount ?? 0);
                $studentNames = $booking->participants->pluck('name')->filter()->values();
                $studentsDisplay = $studentNames->isNotEmpty() ? $studentNames->implode(', ') : $booking->contact_name;
            @endphp
            <div class="bg-white rounded-xl border border-[#E5E5EA] p-4 sm:p-5 shadow-2xs flex flex-col justify-between space-y-4">
                <!-- Request Header -->
                <div>
                    <div class="border-b border-[#F2F2F7] pb-3 space-y-1">
                        <!-- Booking Number -->
                        <a href="{{ route('admin.bookings.show', $booking) }}" class="font-mono font-black text-xl sm:text-2xl text-[#780000] hover:underline block leading-tight tracking-tight">
                            {{ $booking->booking_number }}
                        </a>
                        <div class="text-xs font-bold text-[#1D1D1F]">
                            {{ $booking->formatted_class_type }}
                        </div>
                        <div class="text-xs text-[#8E8E93] font-medium">
                            Requested on <strong class="text-[#1D1D1F] font-semibold">{{ $req->requested_at ? $req->requested_at->format('M d, Y') : $req->created_at->format('M d, Y') }}</strong>
                        </div>
                    </div>

                    <!-- Students Connected to Booking -->
                    <div class="mt-3">
                        <span class="font-extrabold text-sm text-[#1D1D1F] leading-snug block">
                            {{ $studentsDisplay }}
                        </span>
                    </div>

                    <!-- Key Details Grid (2x2) -->
                    <div class="mt-3 grid grid-cols-2 gap-3">
                        <div>
                            <span class="text-[10px] font-bold uppercase tracking-wider text-[#8E8E93] block">DIVE DATE</span>
                            <span class="text-xs sm:text-sm font-bold text-[#1D1D1F] block mt-0.5 leading-snug">
                                {{ $booking->formatted_date_range }}
                            </span>
                        </div>
                        <div>
                            <span class="text-[10px] font-bold uppercase tracking-wider text-[#8E8E93] block">TOTAL PAID</span>
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

                    <!-- Policy Status / Message -->
                    <div class="mt-3 space-y-1">
                        <span class="text-[10px] font-bold uppercase tracking-wider text-[#8E8E93] block">POLICY STATUS</span>
                        <div class="flex items-center gap-1.5 font-bold text-xs {{ $isEligible ? 'text-emerald-800' : 'text-amber-900' }}">
                            @if($isEligible)
                                <span>100% Refund Eligible (₱{{ number_format($recRefund, 2) }})</span>
                            @else
                                <span>0% Refund (Downpayment Forfeited per Policy)</span>
                            @endif
                        </div>
                        <p class="text-xs text-[#6E6E73] leading-relaxed">
                            {{ $policy['cancel_message'] ?? 'Cancellation evaluated under standard policy.' }}
                        </p>
                    </div>

                    @if($req->reason)
                        <div class="mt-3 space-y-0.5">
                            <span class="text-[10px] font-bold uppercase tracking-wider text-[#8E8E93] block">GUEST REASON</span>
                            <p class="text-xs text-[#6E6E73] italic">"{{ $req->reason }}"</p>
                        </div>
                    @endif
                </div>

                <!-- Direct 1-Step Resolution Actions -->
                <div class="space-y-2">
                    <div class="flex items-center gap-2">
                        <!-- Reject Action -->
                        <button type="button" 
                                @click="openRejectModal('{{ route('admin.bookings.requests.cancellation.reject', $req) }}', '{{ $booking->booking_number }}', 'Cancellation')"
                                class="btn-secondary py-2 px-3 text-sm font-semibold text-center shrink-0">
                            Reject
                        </button>

                        @if(!$isEligible)
                            <!-- Forfeit Deposit Action (Direct 1-Step) -->
                            <form action="{{ route('admin.bookings.requests.cancellation.approve', $req) }}" method="POST" class="flex-1">
                                @csrf
                                <input type="hidden" name="action_type" value="forfeit">
                                <button type="submit" 
                                        onclick="return confirm('Approve cancellation with downpayment FORFEITED (₱0 refund) for {{ $booking->booking_number }} as per policy?')"
                                        class="btn-secondary w-full py-2 text-sm font-bold text-center">
                                    Forfeit Deposit (₱0)
                                </button>
                            </form>
                        @else
                            <!-- Approve & Execute Refund Action (Direct 1-Step) -->
                            <form action="{{ route('admin.bookings.requests.cancellation.approve', $req) }}" method="POST" class="flex-1">
                                @csrf
                                <input type="hidden" name="action_type" value="policy_refund">
                                <button type="submit" 
                                        onclick="return confirm('Approve cancellation & process PayMongo refund of ₱{{ number_format($recRefund, 2) }} for {{ $booking->booking_number }}?')"
                                        class="btn-primary w-full py-2 text-sm font-bold shadow-2xs text-center">
                                    Approve & Refund (₱{{ number_format($recRefund, 2) }})
                                </button>
                            </form>
                        @endif
                    </div>

                    @if(!$isEligible)
                        <!-- Policy Override Direct Refund Action -->
                        <form action="{{ route('admin.bookings.requests.cancellation.approve', $req) }}" method="POST">
                            @csrf
                            <input type="hidden" name="action_type" value="full_refund">
                            <button type="submit" 
                                    onclick="return confirm('Override policy and execute full 100% refund of ₱{{ number_format($claimAmount, 2) }} for {{ $booking->booking_number }}?')"
                                    class="btn-danger w-full py-1.5 text-sm font-bold text-center">
                                Override Policy: Approve 100% Refund (₱{{ number_format($claimAmount, 2) }})
                            </button>
                        </form>
                    @endif
                </div>
            </div>
            @empty
            <div class="col-span-full bg-white rounded-xl border border-[#E5E5EA] p-6 text-center text-sm text-[#8E8E93]">
                No pending guest cancellation requests.
            </div>
            @endforelse
        </div>
    </div>

    <!-- Rejection Modal -->
    <div x-show="rejectModalOpen" 
         x-cloak 
         class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50">
        <div class="bg-white rounded-xl max-w-md w-full p-6 space-y-4 shadow-2xl border border-[#E5E5EA]" 
             @click.outside="rejectModalOpen = false">
            <div class="flex items-center justify-between">
                <h3 class="font-bold text-base text-[#1D1D1F]">
                    Reject <span x-text="rejectRequestType"></span> Request
                </h3>
                <button type="button" @click="rejectModalOpen = false" aria-label="Close reject request modal" class="text-base font-bold text-[#8E8E93] hover:text-[#1D1D1F]">✕</button>
            </div>

            <form :action="rejectFormAction" method="POST" class="space-y-4">
                @csrf
                <p class="text-sm text-[#6E6E73]">
                    Please provide an optional reason for rejecting the request for Booking #<strong class="text-[#1D1D1F]" x-text="rejectBookingNumber"></strong>:
                </p>

                <div>
                    <textarea name="admin_notes" 
                              x-model="rejectNotes" 
                              rows="3" 
                              placeholder="e.g. Requested batch date is at full capacity, or policy window has passed..." 
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
