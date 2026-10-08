@extends('layouts.admin')

@section('title', 'Booking Management | Camp FreedivePH')

@section('content')
<div class="space-y-6 text-sm">
    
    <!-- Top Header -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl sm:text-3xl font-extrabold text-[#1D1D1F] tracking-tight">Booking Management</h1>
        </div>

        <div class="flex items-center gap-2.5 flex-wrap">
            <a href="{{ route('admin.bookings.requests') }}" class="btn-secondary px-3.5 py-2 text-sm sm:text-sm font-semibold flex items-center gap-2">
                <span>Pending Requests</span>
                @if($pendingRequestsCount > 0)
                    <span class="px-2 py-0.5 rounded-md text-sm font-bold bg-[#780000] text-white">
                        {{ $pendingRequestsCount }}
                    </span>
                @endif
            </a>

            <a href="{{ route('admin.bookings.create') }}" class="btn-primary px-4 py-2 text-sm sm:text-sm font-bold flex items-center gap-1.5 shadow-2xs">
                <svg class="w-5 h-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>
                <span>Create Booking</span>
            </a>
        </div>
    </div>

    <!-- Booking Status Metrics -->
    <div class="bg-white rounded-xl border border-[#E5E5EA] p-4 shadow-2xs">
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 items-center gap-y-4">
            <!-- Total -->
            <div class="px-4 py-1">
                <span class="text-sm text-[#6E6E73] font-bold uppercase tracking-wider block">Total</span>
                <div class="text-2xl font-extrabold text-[#1D1D1F] mt-0.5">{{ $stats['total'] }}</div>
            </div>

            <!-- Confirmed -->
            <div class="relative px-4 py-1">
                <div class="hidden sm:block absolute left-0 top-2 bottom-2 w-px bg-[#E5E5EA]"></div>
                <span class="text-sm text-[#6E6E73] font-bold uppercase tracking-wider block">Confirmed</span>
                <div class="text-2xl font-extrabold text-emerald-700 mt-0.5">{{ $stats['confirmed'] }}</div>
            </div>

            <!-- Rescheduled -->
            <div class="relative px-4 py-1">
                <div class="hidden sm:block absolute left-0 top-2 bottom-2 w-px bg-[#E5E5EA]"></div>
                <span class="text-sm text-[#6E6E73] font-bold uppercase tracking-wider block">Rescheduled</span>
                <div class="text-2xl font-extrabold text-[#1D1D1F] mt-0.5">{{ $stats['rescheduled'] }}</div>
            </div>

            <!-- Completed -->
            <div class="relative px-4 py-1">
                <div class="hidden lg:block absolute left-0 top-2 bottom-2 w-px bg-[#E5E5EA]"></div>
                <span class="text-sm text-[#6E6E73] font-bold uppercase tracking-wider block">Completed</span>
                <div class="text-2xl font-extrabold text-[#1D1D1F] mt-0.5">{{ $stats['completed'] }}</div>
            </div>

            <!-- No-Show -->
            <div class="relative px-4 py-1">
                <div class="hidden sm:block absolute left-0 top-2 bottom-2 w-px bg-[#E5E5EA]"></div>
                <span class="text-sm text-[#6E6E73] font-bold uppercase tracking-wider block">No-Show</span>
                <div class="text-2xl font-extrabold text-[#6E6E73] mt-0.5">{{ $stats['no_show'] }}</div>
            </div>

            <!-- Cancelled -->
            <div class="relative px-4 py-1">
                <div class="hidden sm:block absolute left-0 top-2 bottom-2 w-px bg-[#E5E5EA]"></div>
                <span class="text-sm text-[#6E6E73] font-bold uppercase tracking-wider block">Cancelled</span>
                <div class="text-2xl font-extrabold text-rose-700 mt-0.5">{{ $stats['cancelled'] }}</div>
            </div>
        </div>
    </div>

    <!-- Bookings Table -->
    <div class="bg-white rounded-xl border border-[#E5E5EA] overflow-hidden shadow-2xs">
        
        <!-- Table Toolbar Header -->
        <div class="p-3 sm:p-4 border-b border-[#E5E5EA]">
            <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-3">
                
                <!-- Class Package Tabs -->
                <div class="flex items-center gap-1.5 overflow-x-auto pb-1 lg:pb-0 scrollbar-none">
                    <a href="{{ request()->fullUrlWithQuery(['class_type' => '']) }}" 
                       class="px-3 py-1.5 rounded-lg text-sm font-bold transition-all shrink-0 {{ !request('class_type') ? 'bg-[#780000] text-white shadow-2xs' : 'bg-white text-[#6E6E73] hover:text-[#1D1D1F] hover:bg-[#F2F2F7] border border-[#E5E5EA]' }}">
                        All Classes
                    </a>
                    <a href="{{ request()->fullUrlWithQuery(['class_type' => 'discovery']) }}" 
                       class="px-3 py-1.5 rounded-lg text-sm font-bold transition-all shrink-0 {{ request('class_type') === 'discovery' ? 'bg-[#780000] text-white shadow-2xs' : 'bg-white text-[#6E6E73] hover:text-[#1D1D1F] hover:bg-[#F2F2F7] border border-[#E5E5EA]' }}">
                        Discovery
                    </a>
                    <a href="{{ request()->fullUrlWithQuery(['class_type' => 'fundive']) }}" 
                       class="px-3 py-1.5 rounded-lg text-sm font-bold transition-all shrink-0 {{ request('class_type') === 'fundive' ? 'bg-[#780000] text-white shadow-2xs' : 'bg-white text-[#6E6E73] hover:text-[#1D1D1F] hover:bg-[#F2F2F7] border border-[#E5E5EA]' }}">
                        Fundive
                    </a>
                    <a href="{{ request()->fullUrlWithQuery(['class_type' => 'refinement']) }}" 
                       class="px-3 py-1.5 rounded-lg text-sm font-bold transition-all shrink-0 {{ request('class_type') === 'refinement' ? 'bg-[#780000] text-white shadow-2xs' : 'bg-white text-[#6E6E73] hover:text-[#1D1D1F] hover:bg-[#F2F2F7] border border-[#E5E5EA]' }}">
                        Refinement
                    </a>
                </div>

                <!-- Search and Filter Controls -->
                <div class="flex items-center gap-2 w-full lg:w-auto" x-data="{ openFilters: false }">
                    <form method="GET" action="{{ route('admin.bookings.index') }}" class="flex-1 min-w-0 lg:flex-initial">
                        @if(request('class_type'))
                            <input type="hidden" name="class_type" value="{{ request('class_type') }}">
                        @endif
                        @if(request('status'))
                            <input type="hidden" name="status" value="{{ request('status') }}">
                        @endif
                        @if(request('batch_status'))
                            <input type="hidden" name="batch_status" value="{{ request('batch_status') }}">
                        @endif
                        @if(request('sort'))
                            <input type="hidden" name="sort" value="{{ request('sort') }}">
                        @endif

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
                            @if(request()->anyFilled(['status', 'batch_status', 'sort']))
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
                                <a href="{{ route('admin.bookings.index') }}" class="popover-reset">Reset</a>
                            </div>

                            <form method="GET" action="{{ route('admin.bookings.index') }}" class="space-y-3 text-sm">
                                @if(request('class_type'))
                                    <input type="hidden" name="class_type" value="{{ request('class_type') }}">
                                @endif
                                @if(request('search'))
                                    <input type="hidden" name="search" value="{{ request('search') }}">
                                @endif

                                <div>
                                    <label class="block font-bold text-[#6E6E73] text-sm mb-1">Sort By</label>
                                    <select name="sort" class="w-full px-3 py-2 rounded-xl border border-[#D1D1D6] text-sm font-medium">
                                        <option value="created_desc" {{ request('sort', 'created_desc') === 'created_desc' ? 'selected' : '' }}>Newest Booking First (Created)</option>
                                        <option value="created_asc" {{ request('sort') === 'created_asc' ? 'selected' : '' }}>Oldest Booking First (Created)</option>
                                        <option value="dive_date_asc" {{ request('sort') === 'dive_date_asc' ? 'selected' : '' }}>Upcoming Dive Date (Soonest First)</option>
                                        <option value="dive_date_desc" {{ request('sort') === 'dive_date_desc' ? 'selected' : '' }}>Past Dive Date (Furthest First)</option>
                                        <option value="amount_desc" {{ request('sort') === 'amount_desc' ? 'selected' : '' }}>Highest Booking Amount (₱)</option>
                                        <option value="amount_asc" {{ request('sort') === 'amount_asc' ? 'selected' : '' }}>Lowest Booking Amount (₱)</option>
                                        <option value="guest_asc" {{ request('sort') === 'guest_asc' ? 'selected' : '' }}>Participant Name (A Z)</option>
                                        <option value="status" {{ request('sort') === 'status' ? 'selected' : '' }}>Booking Status</option>
                                    </select>
                                </div>

                                <div>
                                    <label class="block font-bold text-[#6E6E73] text-sm mb-1">Status</label>
                                    <select name="status" class="w-full px-3 py-2 rounded-xl border border-[#D1D1D6] text-sm font-medium">
                                        <option value="">All Statuses</option>
                                        <option value="confirmed" {{ request('status') === 'confirmed' ? 'selected' : '' }}>Confirmed</option>
                                        <option value="rescheduled" {{ request('status') === 'rescheduled' ? 'selected' : '' }}>Rescheduled</option>
                                        <option value="reschedule_requested" {{ request('status') === 'reschedule_requested' ? 'selected' : '' }}>Reschedule Requested</option>
                                        <option value="cancellation_requested" {{ request('status') === 'cancellation_requested' ? 'selected' : '' }}>Cancellation Requested</option>
                                        <option value="completed" {{ request('status') === 'completed' ? 'selected' : '' }}>Completed</option>
                                        <option value="no_show" {{ request('status') === 'no_show' ? 'selected' : '' }}>No-Show</option>
                                        <option value="cancelled_by_camp" {{ request('status') === 'cancelled_by_camp' ? 'selected' : '' }}>Cancelled by Camp</option>
                                        <option value="cancelled_by_guest" {{ request('status') === 'cancelled_by_guest' ? 'selected' : '' }}>Cancelled by Participant</option>
                                    </select>
                                </div>

                                <div>
                                    <label class="block font-bold text-[#6E6E73] text-sm mb-1">Batch Assignment</label>
                                    <select name="batch_status" class="w-full px-3 py-2 rounded-xl border border-[#D1D1D6] text-sm font-medium">
                                        <option value="">All Batches</option>
                                        <option value="unassigned" {{ request('batch_status') === 'unassigned' || request('unassigned') === '1' ? 'selected' : '' }}>No Batch (Unassigned)</option>
                                        <option value="assigned" {{ request('batch_status') === 'assigned' ? 'selected' : '' }}>Batch Assigned</option>
                                    </select>
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
                    <th class="p-4 pl-6">Booking Number</th>
                    <th class="p-4">Contact Name</th>
                    <th class="p-4">Class Package</th>
                    <th class="p-4">Dive Dates</th>
                    <th class="p-4">Batch Assignment</th>
                    <th class="p-4">Status</th>
                    <th class="p-4 pr-6">Payment Status</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-[#E5E5EA]">
                @forelse($bookings as $b)
                <tr onclick="window.location='{{ route('admin.bookings.show', $b) }}'" class="hover:bg-[#F2F2F7] cursor-pointer transition-colors text-sm sm:text-sm group">
                    <!-- Booking Reference -->
                    <td class="p-4 pl-6 font-mono">
                        <span class="font-bold text-[#780000] group-hover:underline block text-sm">
                            {{ $b->booking_number }}
                        </span>
                        <span class="text-sm text-[#8E8E93]">PIN: {{ $b->pin }}</span>
                        @if($b->createdBy)
                            <span class="text-sm text-[#6E6E73] block">Manual: {{ $b->createdBy->name }}</span>
                        @endif
                    </td>

                    <!-- Participant Details -->
                    <td class="p-4">
                        <div class="font-bold text-[#1D1D1F]">{{ $b->contact_name }}</div>
                        <div class="text-sm text-[#6E6E73]">{{ $b->contact_phone }}</div>
                    </td>

                    <!-- Package Details -->
                    <td class="p-4">
                        <span class="font-semibold text-[#1D1D1F] block">{{ $b->formatted_class_type }}</span>
                        <span class="text-sm text-[#6E6E73] block">{{ $b->pickup_option === 'carpool' ? 'Carpool' : 'Own Transpo' }}</span>
                        <span class="text-sm text-[#6E6E73] block">{{ $b->participants->count() }} pax</span>
                    </td>

                    <!-- Trip Dates -->
                    <td class="p-4 whitespace-nowrap">
                        <strong class="text-[#1D1D1F] block">{{ $b->start_date->format('M d') }} - {{ $b->end_date->format('M d, Y') }}</strong>
                        <span class="text-xs text-[#8E8E93] block mt-0.5">{{ $b->start_date->format('D') }} - {{ $b->end_date->format('D') }}</span>
                    </td>

                    <!-- Batch Assignment -->
                    <td class="p-4" onclick="event.stopPropagation()">
                        @if($b->batch)
                            <a href="{{ route('admin.batches.show', $b->batch) }}" class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg text-[#166534] text-sm font-semibold hover:underline">
                                <span>{{ $b->batch->batch_code }}</span>
                            </a>
                        @else
                            @if(in_array($b->status, ['confirmed', 'rescheduled']))
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-sm font-bold text-[#92400E]">
                                    No Batch
                                </span>
                            @else
                                <span class="text-sm text-[#8E8E93] italic">Unassigned</span>
                            @endif
                        @endif
                    </td>

                    <!-- Booking Status -->
                    <td class="p-4">
                        <span class="px-2 py-0.5 rounded text-sm font-bold {{ $b->status_badge['bg'] }}">
                            {{ $b->status_badge['label'] }}
                        </span>
                    </td>

                    <!-- Payment Status -->
                    <td class="p-4 pr-6">
                        <span class="px-2 py-0.5 rounded text-sm font-bold {{ $b->payment_status_badge['class'] }}">
                            {{ $b->payment_status_badge['label'] }}
                        </span>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="7" class="p-8 text-center text-[#6E6E73]">
                        No bookings found matching your search or filters.
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
        {{ $bookings->links() }}
    </div>

</div>
@endsection
