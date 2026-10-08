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

    <!-- Triggered Bookings Table -->
    <div class="bg-white rounded-xl border border-[#E5E5EA] overflow-hidden shadow-2xs">

        <!-- Table Toolbar Header -->
        <div class="p-3 sm:p-4 border-b border-[#E5E5EA]">
            <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-3">

                <!-- Class Package Tabs -->
                <div class="flex items-center gap-1.5 overflow-x-auto pb-1 lg:pb-0 scrollbar-none">
                    @foreach(['' => 'All Classes', 'discovery' => 'Discovery', 'fundive' => 'Fundive', 'refinement' => 'Refinement'] as $value => $label)
                        <a href="{{ request()->fullUrlWithQuery(['class_type' => $value, 'page' => null]) }}"
                           class="px-3 py-1.5 rounded-lg text-sm font-bold transition-all shrink-0 {{ (string) request('class_type') === $value ? 'bg-[#780000] text-white shadow-2xs' : 'bg-white text-[#6E6E73] hover:text-[#1D1D1F] hover:bg-[#F2F2F7] border border-[#E5E5EA]' }}">
                            {{ $label }}
                        </a>
                    @endforeach
                </div>

                <!-- Search and Filter Controls -->
                <div class="flex items-center gap-2 w-full lg:w-auto lg:ml-auto" x-data="{ openFilters: false }">
                    <form method="GET" action="{{ portal_route('pricing.triggered', $rule) }}" class="flex-1 min-w-0 lg:flex-initial">
                        @foreach(['class_type', 'date_from', 'date_to', 'sort'] as $keep)
                            @if(request($keep))
                                <input type="hidden" name="{{ $keep }}" value="{{ request($keep) }}">
                            @endif
                        @endforeach

                        <div class="relative w-full sm:w-64">
                            <input type="text"
                                   name="search"
                                   value="{{ request('search') }}"
                                   placeholder="Search booking #, name..."
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
                            <span class="whitespace-nowrap">Filter & Sort</span>
                            @if(request()->anyFilled(['date_from', 'date_to', 'sort']))
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
                             class="popover-panel absolute right-0 mt-2 space-y-3">
                            <div class="flex items-center justify-between">
                                <h4 class="popover-title">Filter & Sort Bookings</h4>
                                <a href="{{ portal_route('pricing.triggered', $rule) }}" class="popover-reset">Reset</a>
                            </div>

                            <form method="GET" action="{{ portal_route('pricing.triggered', $rule) }}" class="space-y-3 text-sm">
                                @foreach(['class_type', 'search'] as $keep)
                                    @if(request($keep))
                                        <input type="hidden" name="{{ $keep }}" value="{{ request($keep) }}">
                                    @endif
                                @endforeach

                                <div>
                                    <label class="block font-bold text-[#6E6E73] text-sm mb-1">Sort By</label>
                                    <select name="sort" class="w-full px-3 py-2 rounded-xl border border-[#D1D1D6] text-sm font-medium">
                                        <option value="booked_desc" {{ request('sort', 'booked_desc') === 'booked_desc' ? 'selected' : '' }}>Newest Booking First (Booked)</option>
                                        <option value="booked_asc" {{ request('sort', 'booked_desc') === 'booked_asc' ? 'selected' : '' }}>Oldest Booking First (Booked)</option>
                                        <option value="dive_asc" {{ request('sort', 'booked_desc') === 'dive_asc' ? 'selected' : '' }}>Dive Date (Soonest First)</option>
                                        <option value="dive_desc" {{ request('sort', 'booked_desc') === 'dive_desc' ? 'selected' : '' }}>Dive Date (Latest First)</option>
                                        <option value="impact_desc" {{ request('sort', 'booked_desc') === 'impact_desc' ? 'selected' : '' }}>Largest Price Change (₱)</option>
                                    </select>
                                </div>

                                <div>
                                    <label class="block font-bold text-[#6E6E73] text-sm mb-1">Dive Date From</label>
                                    <x-date-picker name="date_from" :value="request('date_from')" placeholder="Any date" />
                                </div>

                                <div>
                                    <label class="block font-bold text-[#6E6E73] text-sm mb-1">Dive Date To</label>
                                    <x-date-picker name="date_to" :value="request('date_to')" placeholder="Any date" />
                                </div>

                                <div class="pt-2 border-t border-[#E5E5EA] flex justify-end">
                                    <button type="submit" class="btn-primary w-full py-2 text-sm font-bold shadow-2xs">
                                        Apply Filter & Sort
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

            </div>
        </div>

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
                    <tr @if($booking) onclick="window.location='{{ portal_route('bookings.show', $booking) }}'" class="hover:bg-[#F2F2F7] cursor-pointer transition-colors text-sm group" @else class="hover:bg-[#F2F2F7] transition-colors text-sm" @endif>
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
                                <div class="font-bold text-[#1D1D1F]">{{ request()->anyFilled(['class_type', 'search', 'date_from', 'date_to']) ? 'No bookings match your search or filters.' : 'No Bookings Have Triggered This Rule Yet' }}</div>
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
