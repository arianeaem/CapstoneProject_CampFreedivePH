@extends('layouts.admin')

@section('title', $booking->formatted_class_type . ' #' . $booking->booking_number . ' | Camp FreedivePH')

@section('breadcrumb')
    <a href="{{ portal_route('bookings.index') }}" class="text-[#6E6E73] hover:text-[#780000] font-medium transition-colors">Bookings</a>
    <svg class="w-3.5 h-3.5 text-[#8E8E93] shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m9 18 6-6-6-6"/></svg>
    <span class="font-bold text-[#1D1D1F]">{{ $booking->booking_number }}</span>
@endsection

@section('content')
<div class="space-y-6 text-sm" x-data="{ openStatusModal: false }">
    
    <!-- Top Header & Booking Overview Banner Header -->
    <div class="flex flex-col sm:flex-row sm:items-start justify-between gap-4">
        <div class="space-y-1.5 min-w-0">
            <!-- Booking Overview Heading -->
            <div>
                <h1 class="text-2xl sm:text-3xl font-extrabold text-[#1D1D1F] tracking-tight">{{ $booking->formatted_class_type }}</h1>
                <div class="flex items-center gap-2 flex-wrap text-sm text-[#6E6E73] mt-1.5">
                    <span><strong class="text-[#1D1D1F]">{{ $booking->start_date->format('F d, Y') }}</strong> - <strong class="text-[#1D1D1F]">{{ $booking->end_date->format('F d, Y') }}</strong></span>
                    <span class="px-2.5 py-0.5 rounded-full text-xs font-bold {{ $booking->status_badge['bg'] }}">
                        {{ $booking->status_badge['label'] }}
                    </span>
                    <span class="px-2.5 py-0.5 rounded-full text-xs font-bold {{ $booking->payment_status_badge['class'] }}">
                        {{ $booking->payment_status_badge['label'] }}
                    </span>
                </div>
            </div>
        </div>

        <div class="flex items-center gap-2 sm:gap-2.5 w-full sm:w-auto shrink-0">
            <a href="{{ route('admin.bookings.edit', $booking) }}" class="btn-secondary px-3.5 sm:px-4 py-2 text-sm font-semibold flex items-center justify-center gap-2 flex-1 sm:flex-initial">
                <img src="{{ asset('icons/icons8-edit-60.png') }}" alt="Edit" class="w-5 h-5 object-contain inline-block shrink-0">
                <span>Edit Details</span>
            </a>

            <button type="button" 
                    @click="openStatusModal = true"
                    class="btn-primary px-4 py-2 text-sm font-bold flex items-center justify-center gap-1.5 flex-1 sm:flex-initial">
                <span>Change Status</span>
            </button>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        
        <!-- Left Column: Participants, Logistics & History -->
        <div class="lg:col-span-2 space-y-6">
            
            <!-- Participants Roster -->
            <div class="bg-white rounded-xl border border-[#E5E5EA] p-4 sm:p-6 space-y-4 shadow-2xs">
                <div class="flex items-center justify-between pb-3">
                    <h3 class="text-base font-bold text-[#1D1D1F]">Participant(s) ({{ $booking->participants->count() }} pax)</h3>
                </div>

                <div class="divide-y divide-[#E5E5EA]">
                    @foreach($booking->participants as $index => $p)
                    <div class="py-4 first:pt-0 last:pb-0 space-y-2.5">
                        <!-- Participant Card Header -->
                        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                            <div class="flex items-center gap-2.5 flex-wrap">
                                <span class="w-6 h-6 rounded-full bg-[#F2F2F7] text-[#1D1D1F] font-bold text-xs flex items-center justify-center shrink-0">
                                    {{ $index + 1 }}
                                </span>
                                <strong class="font-bold text-base text-[#1D1D1F]">{{ $p->name }}</strong>
                                <span class="text-sm text-[#6E6E73] font-medium">({{ $p->age }} yrs old)</span>
                            </div>
                            <div class="flex items-center gap-2 shrink-0">
                                <span class="text-xs sm:text-sm px-2.5 py-0.5 rounded-md font-semibold bg-[#F2F2F7] text-[#1D1D1F]">
                                    {{ ucfirst(str_replace('_', ' ', $p->swimmer_status ?: 'Swimmer')) }}
                                </span>
                            </div>
                        </div>

                        <!-- Participant Card Details -->
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-sm pt-1">
                            <div>
                                <span class="text-xs font-bold text-[#8E8E93] uppercase tracking-wider block mb-0.5">Medical & Health Notes</span>
                                <span class="text-sm break-words {{ $p->health_condition && $p->health_condition !== 'None declared' ? 'text-[#92400E] font-medium' : 'text-[#6E6E73]' }}">
                                    {{ $p->health_condition ?: 'None declared' }}
                                </span>
                            </div>

                            @if($p->coach)
                                <div>
                                    <span class="text-xs font-bold text-[#8E8E93] uppercase tracking-wider block mb-0.5">Assigned Coach</span>
                                    <span class="text-sm font-bold text-[#780000] break-words">{{ $p->coach->name }}</span>
                                </div>
                            @endif
                        </div>
                    </div>
                    @endforeach
                </div>
            </div>

            <!-- Contact and Logistics -->
            <div class="bg-white rounded-xl border border-[#E5E5EA] p-4 sm:p-6 space-y-4 shadow-2xs">
                <h3 class="text-base font-bold text-[#1D1D1F] border-b border-[#E5E5EA] pb-3">Contact & Logistics</h3>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
                    <div>
                        <span class="text-xs font-bold text-[#8E8E93] uppercase tracking-wider block mb-0.5">Primary Booker Name</span>
                        <strong class="text-[#1D1D1F] text-sm sm:text-base">{{ $booking->contact_name }}</strong>
                    </div>
                    <div>
                        <span class="text-xs font-bold text-[#8E8E93] uppercase tracking-wider block mb-0.5">Contact Email</span>
                        <span class="text-[#1D1D1F] font-medium break-all">{{ $booking->contact_email }}</span>
                    </div>
                    <div>
                        <span class="text-xs font-bold text-[#8E8E93] uppercase tracking-wider block mb-0.5">Mobile Phone</span>
                        <span class="text-[#1D1D1F] font-medium">{{ $booking->contact_phone }}</span>
                    </div>
                    <div>
                        <span class="text-xs font-bold text-[#8E8E93] uppercase tracking-wider block mb-0.5">Transportation</span>
                        <strong class="text-[#1D1D1F]">{{ $booking->pickup_option === 'carpool' ? 'Manila Carpool Van' : 'Own Transportation' }}</strong>
                        @if($booking->pickup_location)
                            <div class="text-xs text-[#6E6E73] mt-0.5 break-words">{{ $booking->pickup_location }}</div>
                        @endif
                    </div>
                    <div>
                        <span class="text-xs font-bold text-[#8E8E93] uppercase tracking-wider block mb-0.5">Boat Dive Add-on</span>
                        <span class="text-[#1D1D1F] font-medium">{{ $booking->boat_dive ? 'Yes (+₱600/pax Sanctuary Boat Dive)' : 'No' }}</span>
                    </div>
                    <div>
                        <span class="text-xs font-bold text-[#8E8E93] uppercase tracking-wider block mb-0.5">Reservation PIN (Guest Access)</span>
                        <strong class="text-[#780000] font-mono text-base">{{ $booking->pin }}</strong>
                    </div>
                </div>
            </div>

        </div>

        <!-- Right Column: Invoice Breakdown & Payment Snapshot -->
        <div class="space-y-6">
            
            <!-- Itemized Invoice Breakdown -->
            <div class="bg-white rounded-xl border border-[#E5E5EA] p-4 sm:p-6 space-y-4 shadow-2xs">
                <h3 class="text-base font-bold text-[#1D1D1F] border-b border-[#E5E5EA] pb-3">Itemized Invoice</h3>

                <div class="space-y-2.5 text-sm">
                    <div class="flex justify-between items-center gap-2">
                        <span class="text-[#6E6E73]">Course Fee ({{ $booking->participants->count() }} pax):</span>
                        <strong class="text-[#1D1D1F]">₱{{ number_format($booking->subtotal, 2) }}</strong>
                    </div>

                    @if($booking->carpool_fee > 0)
                    <div class="flex justify-between items-center gap-2">
                        <span class="text-[#6E6E73]">Carpool Van (Roundtrip):</span>
                        <strong class="text-[#1D1D1F]">₱{{ number_format($booking->carpool_fee, 2) }}</strong>
                    </div>
                    @endif

                    @if($booking->boat_dive_fee > 0)
                    <div class="flex justify-between items-center gap-2">
                        <span class="text-[#6E6E73]">Sanctuary Boat Dive:</span>
                        <strong class="text-[#1D1D1F]">₱{{ number_format($booking->boat_dive_fee, 2) }}</strong>
                    </div>
                    @endif

                    <div class="flex justify-between items-center gap-2">
                        <span class="text-[#6E6E73]">Mabini LGU Dive Pass:</span>
                        <strong class="text-[#1D1D1F]">₱{{ number_format($booking->lgu_fee, 2) }}</strong>
                    </div>

                    <div class="flex justify-between items-center gap-2">
                        <span class="text-[#6E6E73]">Municipal Environmental Fee:</span>
                        <strong class="text-[#1D1D1F]">₱{{ number_format($booking->environmental_fee, 2) }}</strong>
                    </div>

                    <div class="pt-3 flex justify-between items-center text-sm sm:text-base">
                        <strong class="text-[#1D1D1F]">Total Amount:</strong>
                        <strong class="text-[#780000] text-base sm:text-lg font-extrabold">₱{{ number_format($booking->total_amount, 2) }}</strong>
                    </div>

                    <div class="flex justify-between items-center text-sm text-emerald-700">
                        <span class="font-medium">Required Downpayment:</span>
                        <strong class="font-bold">₱{{ number_format($booking->downpayment_amount, 2) }}</strong>
                    </div>

                    <div class="flex justify-between items-center text-sm text-[#6E6E73]">
                        <span>Remaining Balance:</span>
                        <strong class="text-[#1D1D1F]">₱{{ number_format($booking->balance_amount, 2) }}</strong>
                    </div>
                </div>
            </div>

            <!-- Payment Summary Snapshot -->
            <div class="bg-white rounded-xl border border-[#E5E5EA] p-4 sm:p-6 space-y-4 shadow-2xs">
                <div class="flex items-center justify-between pb-3">
                    <h3 class="text-base font-bold text-[#1D1D1F]">Payment Summary</h3>
                    <span class="text-xs sm:text-sm text-[#8E8E93]">Read-Only Snapshot</span>
                </div>

                <div class="space-y-3">
                    @forelse($booking->payments as $payment)
                    <div class="bg-[#F2F2F7] p-3.5 rounded-xl text-sm space-y-1.5 shadow-2xs">
                        <div class="flex items-center justify-between gap-2">
                            <span class="font-bold text-[#1D1D1F] uppercase text-xs sm:text-sm tracking-wide">{{ str_replace('_', ' ', $payment->payment_method) }}</span>
                            <span class="font-bold text-emerald-700">₱{{ number_format($payment->amount, 2) }}</span>
                        </div>
                        <div class="text-xs sm:text-sm text-[#6E6E73] break-all">Ref: <span class="font-mono font-medium text-[#1D1D1F]">{{ $payment->transaction_id }}</span></div>
                        <div class="text-xs text-[#8E8E93]">Paid at: {{ $payment->paid_at ? $payment->paid_at->format('M d, Y h:i A') : 'N/A' }}</div>
                    </div>
                    @empty
                    <p class="text-sm text-[#6E6E73] py-2">No payments recorded yet.</p>
                    @endforelse
                </div>

                <!-- Payment Navigation -->
                <div class="pt-2">
                    <a href="{{ route('admin.payments.index', ['search' => $booking->booking_number]) }}" 
                       class="btn-secondary w-full py-2.5 text-sm font-bold text-center block">
                        Manage Payment in Payments & Refunds
                    </a>
                </div>
            </div>

        </div>

    </div>

    <!-- Booking Audit Trail & History (Placed at the bottom for clean mobile flow) -->
    <div class="bg-white rounded-xl border border-[#E5E5EA] p-4 sm:p-6 space-y-4 shadow-2xs">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-1 border-b border-[#E5E5EA] pb-3">
            <h3 class="text-base font-bold text-[#1D1D1F]">Booking Audit Trail & History</h3>
            <span class="text-xs sm:text-sm text-[#8E8E93]">
                Created {{ $booking->created_at->format('M d, Y h:i A') }}
            </span>
        </div>

        <div class="divide-y divide-[#E5E5EA]">
            @forelse($booking->statusLogs as $log)
            <div class="py-3 text-sm space-y-1">
                <div class="flex flex-col sm:flex-row sm:items-baseline justify-between gap-1">
                    <span class="font-bold text-[#1D1D1F] break-words">
                        @if($log->old_status && $log->old_status !== $log->new_status && $log->old_status !== 'new')
                            {{ ucfirst(str_replace('_', ' ', $log->old_status)) }} → {{ ucfirst(str_replace('_', ' ', $log->new_status)) }}
                        @elseif($log->old_status === 'new' || $log->old_status === null)
                            Reservation Initialized ({{ ucfirst(str_replace('_', ' ', $log->new_status)) }})
                        @else
                            Details Modified
                        @endif
                    </span>
                    <span class="text-xs sm:text-sm text-[#8E8E93] shrink-0">{{ $log->created_at->format('M d, Y h:i A') }}</span>
                </div>
                <p class="text-[#6E6E73] break-words">{{ $log->note }}</p>
                <span class="text-xs sm:text-sm text-[#8E8E93] block">Actor: <strong class="text-[#1D1D1F]">{{ $log->user ? $log->user->name : ($booking->created_by ? 'Staff User' : 'Website Direct Booking') }}</strong></span>
            </div>
            @empty
            <!-- Fallback Creation Log -->
            <div class="py-3 text-sm space-y-1">
                <div class="flex flex-col sm:flex-row sm:items-baseline justify-between gap-1">
                    <span class="font-bold text-[#1D1D1F]">Reservation Created</span>
                    <span class="text-xs sm:text-sm text-[#8E8E93] shrink-0">{{ $booking->created_at->format('M d, Y h:i A') }}</span>
                </div>
                <p class="text-[#6E6E73] break-words">Initial booking reservation logged in system for {{ $booking->contact_name }}.</p>
                <span class="text-xs sm:text-sm text-[#8E8E93] block">Actor: <strong class="text-[#1D1D1F]">{{ $booking->creator ? $booking->creator->name : 'Website Direct Booking' }}</strong></span>
            </div>
            @endforelse
        </div>
    </div>

    <!-- Status Change Modal -->
    <div x-show="openStatusModal" x-cloak class="fixed inset-0 z-50 bg-black/40 flex justify-end">
        <div class="dive-side-panel h-full overflow-y-auto overscroll-contain bg-white sm:max-w-lg w-full p-5 sm:p-7 space-y-4 sm:space-y-5 shadow-2xl border-l border-[#E5E5EA]" @click.outside="openStatusModal = false">
            <div class="flex items-center justify-between pb-3">
                <h3 class="text-lg font-bold text-[#1D1D1F]">Update Booking Status</h3>
                <button type="button" @click="openStatusModal = false" aria-label="Close status modal" class="text-[#8E8E93] hover:text-[#1D1D1F] font-bold text-lg p-1">✕</button>
            </div>

            <form action="{{ route('admin.bookings.status.update', $booking) }}" method="POST" class="space-y-4 text-sm">
                @csrf
                @method('PATCH')

                <div>
                    <label class="block font-bold text-[#1D1D1F] mb-1.5">New Status <span class="text-[#780000]">*</span></label>
                    <select name="status" required class="w-full px-3.5 py-2.5 rounded-xl border border-[#D1D1D6] focus:border-[#780000] text-sm text-[#1D1D1F] bg-white font-medium">
                        <option value="confirmed" {{ $booking->status === 'confirmed' ? 'selected' : '' }}>Confirmed</option>
                        <option value="completed" {{ $booking->status === 'completed' ? 'selected' : '' }}>Completed (Dive Completed)</option>
                        <option value="rescheduled" {{ $booking->status === 'rescheduled' ? 'selected' : '' }}>Rescheduled</option>
                        <option value="no_show" {{ $booking->status === 'no_show' ? 'selected' : '' }}>No-Show (Forfeits Downpayment)</option>
                        <option value="cancelled_by_camp" {{ $booking->status === 'cancelled_by_camp' ? 'selected' : '' }}>Cancelled by Camp (Weather/Admin)</option>
                        <option value="cancelled_by_guest" {{ $booking->status === 'cancelled_by_guest' ? 'selected' : '' }}>Cancelled by Guest</option>
                    </select>
                </div>

                <div>
                    <label class="block font-bold text-[#1D1D1F] mb-1.5">Reason / Status Note</label>
                    <textarea name="note" rows="3" placeholder="Explain why this status is being changed..." class="w-full px-3.5 py-2 rounded-xl border border-[#D1D1D6] text-sm text-[#1D1D1F] bg-white"></textarea>
                </div>

                <div class="banner banner-warning">
                    <strong>Note:</strong> Status changes propagate immediately to the Coach Portal, Batch schedule, and Reports. If setting to Cancelled, please process the refund via Payments & Refunds.
                </div>

                <div class="flex items-center justify-end gap-2.5 pt-3 border-t border-[#E5E5EA]">
                    <button type="button" @click="openStatusModal = false" class="btn-secondary px-4 py-2 text-sm font-semibold">Cancel</button>
                    <button type="submit" class="btn-primary px-5 py-2 text-sm font-bold">
                        Update Status
                    </button>
                </div>
            </form>
        </div>
    </div>

</div>
@endsection
