@extends('layouts.admin')

@section('title', 'Payments & Refunds | Camp FreedivePH')

@section('content')
<div class="space-y-6 text-sm">
    
    <!-- Top Header -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl sm:text-3xl font-extrabold text-[#1D1D1F] tracking-tight">Payments & Refunds</h1>
        </div>

        <div class="flex items-center gap-2.5 flex-wrap">
            <a href="{{ route('admin.payments.create') }}" class="btn-primary px-4 py-2 text-sm sm:text-sm font-bold shadow-2xs flex items-center gap-1.5">
                <svg class="w-5 h-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>
                <span>Record Payment</span>
            </a>
        </div>
    </div>

    <!-- Payment Summary Metrics -->
    <div class="bg-white rounded-xl border border-[#E5E5EA] p-2.5 sm:p-4 shadow-2xs">
        <div class="grid grid-cols-2 lg:grid-cols-4 items-center gap-2 sm:gap-4">
            <!-- Total Collections -->
            <div class="px-2 sm:px-4 py-1">
                <span class="text-sm sm:text-sm font-bold text-[#6E6E73] uppercase tracking-wider block truncate">Total Collections</span>
                <div class="text-base sm:text-2xl font-extrabold text-emerald-700 mt-0.5">₱{{ number_format($stats['total_gross'] ?? 0, 2) }}</div>
                <span class="text-sm text-[#8E8E93] hidden sm:block mt-0.5">Verified completed payments</span>
            </div>

            <!-- Net Received -->
            <div class="relative px-2 sm:px-4 py-1 border-l border-[#E5E5EA] sm:border-l-0">
                <div class="hidden sm:block absolute left-0 top-2 bottom-2 w-px bg-[#E5E5EA]"></div>
                <span class="text-sm sm:text-sm font-bold text-[#6E6E73] uppercase tracking-wider block truncate">Net Received</span>
                <div class="text-base sm:text-2xl font-extrabold text-[#1D1D1F] mt-0.5">₱{{ number_format($stats['total_net'] ?? 0, 2) }}</div>
                <span class="text-sm text-[#8E8E93] hidden sm:block mt-0.5">Net of gateway processing fees</span>
            </div>

            <!-- Total Refunded -->
            <div class="relative px-2 sm:px-4 py-1 pt-2 sm:pt-1 border-t lg:border-t-0 border-[#E5E5EA]">
                <div class="hidden lg:block absolute left-0 top-2 bottom-2 w-px bg-[#E5E5EA]"></div>
                <span class="text-sm sm:text-sm font-bold text-[#6E6E73] uppercase tracking-wider block truncate">Total Refunded</span>
                <div class="text-base sm:text-2xl font-extrabold text-[#780000] mt-0.5">₱{{ number_format($stats['total_refunded'] ?? 0, 2) }}</div>
                <span class="text-sm text-[#8E8E93] hidden sm:block mt-0.5">Returned to guest accounts</span>
            </div>

            <!-- Forfeited -->
            <div class="relative px-2 sm:px-4 py-1 pt-2 sm:pt-1 border-t lg:border-t-0 border-l border-[#E5E5EA] sm:border-l-0">
                <div class="hidden sm:block absolute left-0 top-2 bottom-2 w-px bg-[#E5E5EA]"></div>
                <span class="text-sm sm:text-sm font-bold text-[#6E6E73] uppercase tracking-wider block truncate">Forfeited</span>
                <div class="text-base sm:text-2xl font-extrabold text-[#1D1D1F] mt-0.5">₱{{ number_format($stats['total_forfeited'] ?? 0, 2) }}</div>
                <span class="text-sm text-[#8E8E93] hidden sm:block mt-0.5">Non-refundable cancellations</span>
            </div>
        </div>
    </div>

    <!-- Payments Ledger Table -->
    <div class="bg-white rounded-xl border border-[#E5E5EA] overflow-hidden shadow-2xs">
        
        <!-- Table Toolbar Header -->
        <div class="p-2.5 sm:p-4 border-b border-[#E5E5EA]">
            <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-2.5 sm:gap-3">
                
                <!-- Payment Stage Tabs -->
                <div class="flex items-center gap-1.5 overflow-x-auto pb-1 lg:pb-0 scrollbar-none -mx-0.5 px-0.5">
                    <a href="{{ request()->fullUrlWithQuery(['stage' => '']) }}" 
                       class="px-3 py-1.5 rounded-lg text-sm font-bold transition-all shrink-0 {{ !request('stage') ? 'bg-[#780000] text-white shadow-2xs' : 'bg-white text-[#6E6E73] hover:text-[#1D1D1F] hover:bg-[#F2F2F7] border border-[#E5E5EA]' }}">
                        All Stages
                    </a>
                    <a href="{{ request()->fullUrlWithQuery(['stage' => 'downpayment']) }}" 
                       class="px-3 py-1.5 rounded-lg text-sm font-bold transition-all shrink-0 {{ request('stage') === 'downpayment' ? 'bg-[#780000] text-white shadow-2xs' : 'bg-white text-[#6E6E73] hover:text-[#1D1D1F] hover:bg-[#F2F2F7] border border-[#E5E5EA]' }}">
                        Downpayment
                    </a>
                    <a href="{{ request()->fullUrlWithQuery(['stage' => 'balance_settlement']) }}" 
                       class="px-3 py-1.5 rounded-lg text-sm font-bold transition-all shrink-0 {{ request('stage') === 'balance_settlement' ? 'bg-[#780000] text-white shadow-2xs' : 'bg-white text-[#6E6E73] hover:text-[#1D1D1F] hover:bg-[#F2F2F7] border border-[#E5E5EA]' }}">
                        Balance Settlement
                    </a>
                    <a href="{{ request()->fullUrlWithQuery(['stage' => 'full']) }}" 
                       class="px-3 py-1.5 rounded-lg text-sm font-bold transition-all shrink-0 {{ request('stage') === 'full' ? 'bg-[#780000] text-white shadow-2xs' : 'bg-white text-[#6E6E73] hover:text-[#1D1D1F] hover:bg-[#F2F2F7] border border-[#E5E5EA]' }}">
                        Full Payment
                    </a>
                </div>

                <!-- Search and Filter Controls -->
                <div class="flex items-center gap-2 w-full lg:w-auto" x-data="{ openFilters: false }">
                    <form method="GET" action="{{ route('admin.payments.index') }}" class="flex-1 min-w-0 lg:flex-initial">
                        @if(request('stage'))
                            <input type="hidden" name="stage" value="{{ request('stage') }}">
                        @endif
                        @if(request('status'))
                            <input type="hidden" name="status" value="{{ request('status') }}">
                        @endif
                        @if(request('method'))
                            <input type="hidden" name="method" value="{{ request('method') }}">
                        @endif

                        <div class="relative w-full sm:w-64">
                            <input type="text" 
                                   name="search" 
                                   value="{{ request('search') }}" 
                                   placeholder="Search booking, txn, guest..." 
                                   class="w-full pl-8 pr-3 py-1.5 text-sm rounded-lg border border-[#D1D1D6] bg-white focus:bg-white focus:border-[#780000]">
                            <svg class="w-3.5 h-3.5 text-[#8E8E93] absolute left-2.5 top-1/2 -translate-y-1/2" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="11" cy="11" r="8"></circle>
                                <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                            </svg>
                        </div>
                    </form>

                    <!-- Filter Controls -->
                    <div class="relative shrink-0">
                        <button type="button" 
                                @click="openFilters = !openFilters" 
                                class="btn-secondary flex items-center justify-center gap-1.5 px-3 py-1.5 text-sm font-semibold whitespace-nowrap shrink-0 cursor-pointer">
                            <img src="{{ asset('icons/icons8-filter-60.png') }}" alt="Filter" class="w-4.5 h-4.5 object-contain inline-block shrink-0">
                            <span class="whitespace-nowrap">Filter</span>
                            @if(request()->anyFilled(['status', 'method', 'date_from', 'date_to']))
                                <span class="w-2 h-2 rounded-full bg-[#780000] shrink-0"></span>
                            @endif
                        </button>

                        <!-- Filter Form Dropdown -->
                        <div x-show="openFilters" 
                             @click.outside="openFilters = false" 
                             x-cloak 
                             x-transition:enter="transition ease-out duration-150 transform"
                             x-transition:enter-start="opacity-0 scale-95 -translate-y-1"
                             x-transition:enter-end="opacity-100 scale-100 translate-y-0"
                             x-transition:leave="transition ease-in duration-100 transform"
                             x-transition:leave-start="opacity-100 scale-100 translate-y-0"
                             x-transition:leave-end="opacity-0 scale-95 -translate-y-1"
                             class="absolute right-0 mt-2 w-[calc(100vw-2rem)] sm:w-80 max-w-sm bg-white rounded-2xl border border-[#E5E5EA] shadow-xl p-4 z-50 space-y-3">
                            <div class="flex items-center justify-between">
                                <h4 class="font-bold text-sm text-[#1D1D1F]">Filter Payments</h4>
                                <a href="{{ route('admin.payments.index') }}" class="text-sm text-[#780000] hover:underline font-bold">Reset</a>
                            </div>

                            <form method="GET" action="{{ route('admin.payments.index') }}" class="space-y-3 text-sm">
                                @if(request('stage'))
                                    <input type="hidden" name="stage" value="{{ request('stage') }}">
                                @endif
                                @if(request('search'))
                                    <input type="hidden" name="search" value="{{ request('search') }}">
                                @endif

                                <div>
                                    <label class="block font-bold text-[#6E6E73] text-sm mb-1">Payment Status</label>
                                    <select name="status" class="w-full px-3 py-2 rounded-xl border border-[#D1D1D6] text-sm font-medium">
                                        <option value="">All Statuses</option>
                                        <option value="paid" {{ request('status') === 'paid' ? 'selected' : '' }}>Paid / Completed</option>
                                        <option value="pending" {{ request('status') === 'pending' ? 'selected' : '' }}>Pending</option>
                                        <option value="refund_requested" {{ request('status') === 'refund_requested' ? 'selected' : '' }}>Refund Requested</option>
                                        <option value="refunded" {{ request('status') === 'refunded' ? 'selected' : '' }}>Refunded</option>
                                        <option value="forfeited" {{ request('status') === 'forfeited' ? 'selected' : '' }}>Forfeited</option>
                                        <option value="failed" {{ request('status') === 'failed' ? 'selected' : '' }}>Failed / Cancelled</option>
                                    </select>
                                </div>

                                <div>
                                    <label class="block font-bold text-[#6E6E73] text-sm mb-1">Payment Method</label>
                                    <select name="method" class="w-full px-3 py-2 rounded-xl border border-[#D1D1D6] text-sm font-medium">
                                        <option value="">All Methods</option>
                                        <option value="gcash" {{ request('method') === 'gcash' ? 'selected' : '' }}>GCash</option>
                                        <option value="bpi_bank_transfer" {{ request('method') === 'bpi_bank_transfer' ? 'selected' : '' }}>BPI Bank Transfer</option>
                                        <option value="card" {{ request('method') === 'card' ? 'selected' : '' }}>Credit / Debit Card</option>
                                        <option value="cash" {{ request('method') === 'cash' ? 'selected' : '' }}>Cash at Camp</option>
                                    </select>
                                </div>

                                <div class="pt-2 border-t border-[#E5E5EA] flex justify-end">
                                    <button type="submit" class="btn-primary w-full py-2 text-sm font-bold shadow-2xs">
                                        Apply Filter
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="bg-[#F2F2F7] border-b border-[#E5E5EA] text-[#6E6E73] font-bold">
                    <tr>
                        <th class="py-3 px-4 text-left">Transaction ID</th>
                        <th class="py-3 px-4 text-left">Booking #</th>
                        <th class="py-3 px-4 text-left">Lead Guest</th>
                        <th class="py-3 px-4 text-left">Stage</th>
                        <th class="py-3 px-4 text-left">Method</th>
                        <th class="py-3 px-4 text-left">Amount</th>
                        <th class="py-3 px-4 text-left">Status</th>
                        <th class="py-3 px-4 text-left pr-6">Date</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[#E5E5EA]">
                    @forelse($payments as $payment)
                    <tr onclick="window.location='{{ route('admin.payments.show', $payment) }}'" class="hover:bg-[#F2F2F7] cursor-pointer transition-colors group">
                        <!-- Transaction Details -->
                        <td class="py-3 px-4 text-left font-mono font-bold text-[#1D1D1F] group-hover:text-[#780000]">
                            {{ $payment->transaction_id ?? ('TXN-' . $payment->id) }}
                            @if($payment->paymongo_payment_id)
                                <span class="block text-sm font-normal text-[#6E6E73]">{{ $payment->paymongo_payment_id }}</span>
                            @endif
                        </td>

                        <!-- Booking Reference -->
                        <td class="py-3 px-4 text-left" onclick="event.stopPropagation()">
                            @if($payment->booking)
                                <a href="{{ route('admin.bookings.show', $payment->booking) }}" class="font-mono font-bold text-[#780000] hover:underline">
                                    {{ $payment->booking->booking_number }}
                                </a>
                            @else
                                <span class="text-[#8E8E93]">No Booking</span>
                            @endif
                        </td>

                        <!-- Lead Guest Contact -->
                        <td class="py-3 px-4 text-left">
                            @if($payment->booking)
                                <strong class="text-[#1D1D1F] block">{{ $payment->booking->contact_name }}</strong>
                                <span class="text-[#6E6E73] text-sm block">{{ $payment->booking->contact_phone }}</span>
                            @else
                                <span class="text-[#8E8E93]">N/A</span>
                            @endif
                        </td>

                        <!-- Payment Stage -->
                        <td class="py-3 px-4 text-left">
                            <span class="px-2 py-0.5 rounded-md bg-[#F2F2F7] text-[#1D1D1F] font-semibold text-sm">
                                {{ $payment->payment_stage_label }}
                            </span>
                        </td>

                        <!-- Payment Method -->
                        <td class="py-3 px-4 text-left font-medium text-[#1D1D1F]">
                            {{ $payment->formatted_payment_method }}
                        </td>

                        <!-- Payment Amount -->
                        <td class="py-3 px-4 text-left font-bold text-sm text-[#1D1D1F]">
                            ₱{{ number_format($payment->amount, 2) }}
                        </td>

                        <!-- Payment Status -->
                        <td class="py-3 px-4 text-left">
                            <span class="px-2 py-0.5 rounded-md text-sm font-bold inline-block {{ $payment->status_badge['class'] }}">
                                {{ $payment->status_badge['label'] }}
                            </span>
                        </td>

                        <!-- Payment Date -->
                        <td class="py-3 px-4 text-left pr-6 text-[#6E6E73] whitespace-nowrap">
                            {{ $payment->paid_at ? $payment->paid_at->format('M d, Y g:i A') : $payment->created_at->format('M d, Y g:i A') }}
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="8" class="py-8 text-center text-sm text-[#8E8E93]">
                            No payment transactions matching your search criteria found.
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <!-- Table Pagination -->
        {{ $payments->links() }}
    </div>

</div>
@endsection
