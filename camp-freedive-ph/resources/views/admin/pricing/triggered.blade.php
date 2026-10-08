@extends('layouts.admin')

@section('title', 'Bookings Triggered by ' . $rule->name . ' | Camp FreedivePH')

@section('breadcrumb')
    <a href="{{ portal_route('pricing.index') }}" class="text-[#6E6E73] hover:text-[#780000] font-medium transition-colors">Dynamic Pricing</a>
    <svg class="w-3.5 h-3.5 text-[#8E8E93] shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m9 18 6-6-6-6"/></svg>
    <a href="{{ portal_route('pricing.edit', $rule) }}" class="text-[#6E6E73] hover:text-[#780000] font-medium transition-colors">{{ $rule->name }}</a>
    <svg class="w-3.5 h-3.5 text-[#8E8E93] shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m9 18 6-6-6-6"/></svg>
    <span class="font-bold text-[#1D1D1F]">Triggered History</span>
@endsection

@section('content')
<div class="space-y-6 text-sm">

    <!-- Top Header & Details -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl sm:text-3xl font-extrabold text-[#1D1D1F] tracking-tight">
                {{ $rule->name }}
            </h1>
            <div class="flex items-center gap-2 mt-1.5 flex-wrap">
                <span class="inline-flex items-center px-2 py-0.5 rounded-md text-sm font-bold bg-[#F2F2F7] border border-[#E5E5EA] text-[#1D1D1F]">
                    {{ $rule->condition_summary }}
                </span>
                <span class="inline-flex items-center px-2 py-0.5 rounded-md text-sm font-bold {{ $rule->adjustment_type === 'increase' ? 'bg-rose-50 text-rose-700' : 'bg-emerald-50 text-emerald-700' }}">
                    {{ $rule->formatted_adjustment }}
                </span>
                <span class="text-sm text-[#6E6E73]">
                    Applies to {{ $rule->formatted_applies_to }}
                </span>
            </div>
        </div>

        <div class="flex items-center gap-2.5">
            <a href="{{ portal_route('pricing.edit', $rule) }}" class="btn-primary px-4 py-2 text-sm font-bold shadow-2xs flex items-center gap-2">
                <img src="{{ asset('icons/icons8-edit-60.png') }}" alt="Edit" class="w-5 h-5 object-contain inline-block shrink-0 brightness-0 invert">
                <span>Edit Rule</span>
            </a>
            <a href="{{ portal_route('pricing.index') }}" class="btn-secondary px-3.5 py-2 text-sm font-semibold flex items-center gap-1.5">
                <span>View All Rules</span>
            </a>
        </div>
    </div>

    <!-- Pricing Trigger Metrics -->
    <div class="bg-white rounded-xl border border-[#E5E5EA] p-4 shadow-2xs">
        <div class="grid grid-cols-1 sm:grid-cols-3 items-center gap-y-4">
            <div class="px-4 py-1">
                <span class="text-sm font-bold text-[#6E6E73] uppercase tracking-wider block">Bookings Triggered</span>
                <div class="text-2xl font-extrabold text-[#780000] mt-0.5">{{ number_format($totalCount) }}</div>
            </div>

            <div class="relative px-4 py-1">
                <div class="hidden sm:block absolute left-0 top-2 bottom-2 w-px bg-[#E5E5EA]"></div>
                <span class="text-sm font-bold text-[#6E6E73] uppercase tracking-wider block">Total Rule Price Impact</span>
                <div class="text-2xl font-extrabold mt-0.5 {{ $totalImpact >= 0 ? 'text-rose-700' : 'text-emerald-700' }}">
                    {{ $totalImpact >= 0 ? '+' : '−' }}₱{{ number_format(abs($totalImpact), 2) }}
                </div>
            </div>

            <div class="relative px-4 py-1">
                <div class="hidden sm:block absolute left-0 top-2 bottom-2 w-px bg-[#E5E5EA]"></div>
                <span class="text-sm font-bold text-[#6E6E73] uppercase tracking-wider block">Rule Status</span>
                <div class="text-2xl font-extrabold mt-0.5 {{ $rule->status === 'active' ? 'text-emerald-700' : 'text-gray-600' }}">
                    {{ ucfirst($rule->status) }}
                </div>
            </div>
        </div>
    </div>

    <!-- Date Filter Bar -->
    <div class="bg-white rounded-xl border border-[#E5E5EA] p-4 shadow-2xs">
        <form method="GET" action="{{ route('admin.pricing.triggered', $rule) }}" class="flex flex-col sm:flex-row sm:items-end gap-3 text-sm">
            <div>
                <label class="block font-semibold text-[#6E6E73] mb-1">Dive Date From</label>
                <x-date-picker name="date_from" :value="request('date_from')" placeholder="Any date" />
            </div>

            <div>
                <label class="block font-semibold text-[#6E6E73] mb-1">Dive Date To</label>
                <x-date-picker name="date_to" :value="request('date_to')" placeholder="Any date" />
            </div>

            <div class="flex items-center gap-2">
                <button type="submit" class="btn-primary px-4 py-1.5 text-sm font-bold shadow-2xs">
                    Filter
                </button>
                @if(request('date_from') || request('date_to'))
                <a href="{{ route('admin.pricing.triggered', $rule) }}" class="btn-secondary px-3 py-1.5 text-sm font-semibold">
                    Reset
                </a>
                @endif
            </div>
        </form>
    </div>

    <!-- Triggered Bookings Table -->
    <div class="bg-white rounded-xl border border-[#E5E5EA] overflow-hidden shadow-2xs">
        <div class="overflow-x-auto">
            <table class="w-full text-left min-w-[900px]">
                <thead class="bg-[#F2F2F7] border-b border-[#E5E5EA] text-sm uppercase font-bold text-[#6E6E73]">
                    <tr>
                        <th class="p-4 pl-6 text-left">Booking Number</th>
                        <th class="p-4 text-left">Contact Name</th>
                        <th class="p-4 text-left">Dive Date</th>
                        <th class="p-4 text-left">Class</th>
                        <th class="p-4 text-left">Base Adjusted Rate</th>
                        <th class="p-4 text-left">Rule Delta (Per Pax)</th>
                        <th class="p-4 pr-6 text-left">Date Booked</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[#E5E5EA]">
                    @forelse($adjustments as $adj)
                    @php
                        $booking = $adj->booking;
                    @endphp
                    <tr @if($booking) onclick="window.location='{{ route('admin.bookings.show', $booking) }}'" class="hover:bg-[#F2F2F7] cursor-pointer transition-colors text-sm group" @else class="hover:bg-[#F2F2F7] transition-colors text-sm" @endif>
                        <!-- Booking Number -->
                        <td class="p-4 pl-6 text-left font-mono font-bold text-[#780000] group-hover:underline">
                            {{ $booking ? $booking->booking_number : '-' }}
                        </td>

                        <!-- Contact Name -->
                        <td class="p-4 text-left">
                            <div class="font-bold text-[#1D1D1F]">{{ $booking ? $booking->contact_name : 'Unknown Contact' }}</div>
                            <div class="text-sm text-[#6E6E73]">{{ $booking ? $booking->contact_phone : '' }}</div>
                        </td>

                        <!-- Dive Date -->
                        <td class="p-4 text-left font-medium text-[#1D1D1F]">
                            {{ $booking ? $booking->start_date->format('M d, Y') : '-' }}
                        </td>

                        <!-- Class -->
                        <td class="p-4 text-left capitalize font-semibold text-[#1D1D1F]">
                            {{ $booking ? $booking->class_type : '-' }}
                        </td>

                        <!-- Base -> Adjusted Rate -->
                        <td class="p-4 text-left">
                            <div class="flex items-center gap-1.5 text-sm">
                                <span class="line-through text-[#8E8E93]">₱{{ number_format($adj->base_price, 2) }}</span>
                                <span class="text-[#8E8E93]">→</span>
                                <span class="font-bold text-[#1D1D1F]">₱{{ number_format($adj->adjusted_price, 2) }}</span>
                            </div>
                        </td>

                        <!-- Rule Delta -->
                        <td class="p-4 text-left">
                            <span class="inline-flex items-center px-2 py-0.5 rounded-md text-sm font-bold {{ $adj->adjustment_amount >= 0 ? 'bg-rose-50 text-rose-700' : 'bg-emerald-50 text-emerald-700' }}">
                                {{ $adj->adjustment_amount >= 0 ? '+' : '−' }}₱{{ number_format(abs($adj->adjustment_amount), 2) }}
                            </span>
                        </td>

                        <!-- Date Booked -->
                        <td class="p-4 pr-6 text-left text-sm text-[#6E6E73]">
                            {{ $adj->created_at->format('M d, Y h:i A') }}
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="7" class="py-10 text-center text-[#6E6E73]">
                            <div class="space-y-1">
                                <div class="font-bold text-[#1D1D1F]">No Bookings Have Triggered This Rule Yet</div>
                                <p class="text-sm">Once bookings meet this rule's conditions during checkout, they will be logged here automatically.</p>
                            </div>
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{ $adjustments->links() }}
    </div>

</div>
@endsection
