@extends('layouts.admin')

@section('title', 'Batches & Schedules | Camp FreedivePH')

@section('content')
<div class="space-y-6 text-sm">
    
    <!-- Top Header & Actions -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl sm:text-3xl font-extrabold text-[#1D1D1F] tracking-tight">Batches & Schedules</h1>
        </div>

        <div class="flex items-center gap-2.5 flex-wrap">
            @if(isset($unbatchedCount) && $unbatchedCount > 0)
                <a href="{{ route('admin.bookings.index', ['batch_status' => 'unassigned']) }}" 
                   class="min-h-[44px] inline-flex items-center justify-center gap-2 px-3.5 py-2 rounded-xl bg-amber-50 text-sm font-bold text-amber-900 hover:bg-amber-100 active:scale-[0.99] transition-all shadow-2xs focus:outline-none focus:ring-2 focus:ring-amber-500">
                    <span>{{ $unbatchedCount }} Confirmed Booking(s) Have No Batch Assigned</span>
                </a>
            @endif

            <a href="{{ route('admin.batches.create') }}" 
               class="btn-primary min-h-[44px] px-4 py-2.5 text-sm font-bold inline-flex items-center justify-center gap-1.5 shrink-0 shadow-2xs active:scale-[0.99] transition-all focus:outline-none focus:ring-2 focus:ring-[#780000]">
                <svg class="w-5 h-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>
                <span>Create Batch</span>
            </a>
        </div>
    </div>

    <!-- Search and Filter Toolbar -->
    <div class="bg-white rounded-xl border border-[#E5E5EA] p-2.5 sm:p-3 shadow-2xs">
        <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-2.5 sm:gap-3">
            
            <!-- Batch Status Filters -->
            <div role="tablist" aria-label="Filter batches by lifecycle status" class="flex items-center gap-1.5 overflow-x-auto pb-1 lg:pb-0 scrollbar-none -mx-0.5 px-0.5">
                <a href="{{ request()->fullUrlWithQuery(['status' => '']) }}" 
                   role="tab"
                   aria-selected="{{ !request('status') ? 'true' : 'false' }}"
                   class="min-h-[44px] px-3.5 py-2 inline-flex items-center justify-center rounded-xl text-sm font-bold transition-all shrink-0 focus:outline-none focus:ring-2 focus:ring-[#780000] {{ !request('status') ? 'bg-[#780000] text-white shadow-2xs' : 'bg-white text-[#6E6E73] hover:text-[#1D1D1F] hover:bg-[#F2F2F7] border border-[#E5E5EA]' }}">
                    All Batches
                </a>
                <a href="{{ request()->fullUrlWithQuery(['status' => 'confirmed']) }}" 
                   role="tab"
                   aria-selected="{{ request('status') === 'confirmed' ? 'true' : 'false' }}"
                   class="min-h-[44px] px-3.5 py-2 inline-flex items-center justify-center rounded-xl text-sm font-bold transition-all shrink-0 focus:outline-none focus:ring-2 focus:ring-[#780000] {{ request('status') === 'confirmed' ? 'bg-[#780000] text-white shadow-2xs' : 'bg-white text-[#6E6E73] hover:text-[#1D1D1F] hover:bg-[#F2F2F7] border border-[#E5E5EA]' }}">
                    Confirmed
                </a>
                <a href="{{ request()->fullUrlWithQuery(['status' => 'completed']) }}" 
                   role="tab"
                   aria-selected="{{ request('status') === 'completed' ? 'true' : 'false' }}"
                   class="min-h-[44px] px-3.5 py-2 inline-flex items-center justify-center rounded-xl text-sm font-bold transition-all shrink-0 focus:outline-none focus:ring-2 focus:ring-[#780000] {{ request('status') === 'completed' ? 'bg-[#780000] text-white shadow-2xs' : 'bg-white text-[#6E6E73] hover:text-[#1D1D1F] hover:bg-[#F2F2F7] border border-[#E5E5EA]' }}">
                    Completed
                </a>
                <a href="{{ request()->fullUrlWithQuery(['status' => 'rescheduled']) }}" 
                   role="tab"
                   aria-selected="{{ request('status') === 'rescheduled' ? 'true' : 'false' }}"
                   class="min-h-[44px] px-3.5 py-2 inline-flex items-center justify-center rounded-xl text-sm font-bold transition-all shrink-0 focus:outline-none focus:ring-2 focus:ring-[#780000] {{ request('status') === 'rescheduled' ? 'bg-[#780000] text-white shadow-2xs' : 'bg-white text-[#6E6E73] hover:text-[#1D1D1F] hover:bg-[#F2F2F7] border border-[#E5E5EA]' }}">
                    Rescheduled
                </a>
                <a href="{{ request()->fullUrlWithQuery(['status' => 'cancelled_by_camp']) }}" 
                   role="tab"
                   aria-selected="{{ request('status') === 'cancelled_by_camp' ? 'true' : 'false' }}"
                   class="min-h-[44px] px-3.5 py-2 inline-flex items-center justify-center rounded-xl text-sm font-bold transition-all shrink-0 focus:outline-none focus:ring-2 focus:ring-[#780000] {{ request('status') === 'cancelled_by_camp' ? 'bg-[#780000] text-white shadow-2xs' : 'bg-white text-[#6E6E73] hover:text-[#1D1D1F] hover:bg-[#F2F2F7] border border-[#E5E5EA]' }}">
                    Cancelled
                </a>
            </div>

            <!-- Search and Advanced Filters -->
            <div class="flex items-center gap-2 w-full lg:w-auto" x-data="{ openFilters: false }">
                <form action="{{ route('admin.batches.index') }}" method="GET" class="flex-1 min-w-0 lg:flex-initial">
                    @if(request('status'))
                        <input type="hidden" name="status" value="{{ request('status') }}">
                    @endif
                    @if(request('staffing'))
                        <input type="hidden" name="staffing" value="{{ request('staffing') }}">
                    @endif
                    @if(request('sort'))
                        <input type="hidden" name="sort" value="{{ request('sort') }}">
                    @endif
                    @if(request('coach'))
                        <input type="hidden" name="coach" value="{{ request('coach') }}">
                    @endif

                    <div class="relative w-full sm:w-64">
                        <input type="text" 
                               name="search" 
                               value="{{ request('search') }}" 
                               placeholder="Search batch or coach..." 
                               aria-label="Search batches by number, name, keyword, or coach name"
                               class="w-full min-h-[44px] pl-9 pr-3 py-2 text-sm rounded-xl border border-[#D1D1D6] bg-white focus:bg-white focus:border-[#780000] focus:ring-2 focus:ring-[#780000]/20 focus:outline-none transition-all">
                        <svg class="w-4 h-4 text-[#6E6E73] absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <circle cx="11" cy="11" r="8"></circle>
                            <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                        </svg>
                    </div>
                </form>

                <!-- Advanced Filter Toggle -->
                <div class="relative shrink-0">
                    <button type="button" 
                            @click="openFilters = !openFilters" 
                            :aria-expanded="openFilters ? 'true' : 'false'"
                            aria-haspopup="true"
                            class="btn-secondary min-h-[44px] flex items-center justify-center gap-2 px-3.5 py-2 rounded-xl text-sm font-semibold whitespace-nowrap shrink-0 cursor-pointer active:scale-[0.98] transition-all focus:outline-none focus:ring-2 focus:ring-[#780000]">
                        <img src="{{ asset('icons/icons8-filter-60.png') }}" alt="Filter" class="w-4.5 h-4.5 object-contain inline-block shrink-0">
                        <span class="whitespace-nowrap">Filter & Sort</span>
                        @if(request()->anyFilled(['staffing', 'sort', 'date_from', 'date_to', 'coach']))
                            <span class="w-2 h-2 rounded-full bg-[#780000] shrink-0" aria-hidden="true"></span>
                            <span class="sr-only">(Filters applied)</span>
                        @endif
                    </button>

                    <!-- Advanced Filter Options Dropdown -->
                    <div x-show="openFilters" 
                         x-cloak 
                         @click.outside="openFilters = false" 
                         x-transition:enter="transition ease-out duration-150 transform"
                         x-transition:enter-start="opacity-0 scale-95 -translate-y-1"
                         x-transition:enter-end="opacity-100 scale-100 translate-y-0"
                         x-transition:leave="transition ease-in duration-100 transform"
                         x-transition:leave-start="opacity-100 scale-100 translate-y-0"
                         x-transition:leave-end="opacity-0 scale-95 -translate-y-1"
                         class="absolute right-0 mt-2 w-[calc(100vw-2rem)] sm:w-80 max-w-sm bg-white rounded-2xl shadow-xl border border-[#E5E5EA] p-4 z-50 space-y-3">
                        <div class="flex items-center justify-between">
                            <h4 class="font-bold text-sm text-[#1D1D1F]">Filter & Sort Batches</h4>
                            <a href="{{ route('admin.batches.index') }}" class="min-h-[44px] px-3 py-1.5 rounded-lg text-sm text-[#780000] hover:bg-[#F2F2F7] active:scale-[0.98] transition-all font-bold inline-flex items-center justify-center focus:outline-none focus:ring-2 focus:ring-[#780000]">Reset</a>
                        </div>

                        <form action="{{ route('admin.batches.index') }}" method="GET" class="space-y-3 text-sm">
                            @if(request('status'))
                                <input type="hidden" name="status" value="{{ request('status') }}">
                            @endif
                            @if(request('search'))
                                <input type="hidden" name="search" value="{{ request('search') }}">
                            @endif

                            <div>
                                <label for="filter-sort" class="block font-bold text-[#6E6E73] text-sm mb-1">Sort By</label>
                                <select id="filter-sort" name="sort" class="w-full min-h-[44px] px-3 py-2 rounded-xl border border-[#D1D1D6] text-sm font-medium focus:border-[#780000] focus:ring-2 focus:ring-[#780000]/20 focus:outline-none transition-all">
                                    <option value="date_asc" {{ request('sort', 'date_asc') === 'date_asc' ? 'selected' : '' }}>Soonest Dive Date (Upcoming First)</option>
                                    <option value="date_desc" {{ request('sort') === 'date_desc' ? 'selected' : '' }}>Latest Dive Date (Newest to Oldest)</option>
                                    <option value="batch_asc" {{ request('sort') === 'batch_asc' ? 'selected' : '' }}>Batch Number (Ascending)</option>
                                    <option value="batch_desc" {{ request('sort') === 'batch_desc' ? 'selected' : '' }}>Batch Number (Descending)</option>
                                    <option value="capacity_desc" {{ request('sort') === 'capacity_desc' ? 'selected' : '' }}>Highest Occupancy / Pax</option>
                                    <option value="capacity_asc" {{ request('sort') === 'capacity_asc' ? 'selected' : '' }}>Lowest Occupancy / Pax</option>
                                    <option value="created_desc" {{ request('sort') === 'created_desc' ? 'selected' : '' }}>Recently Created</option>
                                </select>
                            </div>

                            <div>
                                <label for="filter-coach" class="block font-bold text-[#6E6E73] text-sm mb-1">Coach</label>
                                <select id="filter-coach" name="coach" class="w-full min-h-[44px] px-3 py-2 rounded-xl border border-[#D1D1D6] text-sm font-medium focus:border-[#780000] focus:ring-2 focus:ring-[#780000]/20 focus:outline-none transition-all">
                                    <option value="">All Coaches</option>
                                    @foreach($coachOptions as $coachOption)
                                        <option value="{{ $coachOption->id }}" {{ (string) request('coach') === (string) $coachOption->id ? 'selected' : '' }}>{{ $coachOption->name }}</option>
                                    @endforeach
                                </select>
                            </div>

                            <div>
                                <label for="filter-staffing" class="block font-bold text-[#6E6E73] text-sm mb-1">Staffing Status</label>
                                <select id="filter-staffing" name="staffing" class="w-full min-h-[44px] px-3 py-2 rounded-xl border border-[#D1D1D6] text-sm font-medium focus:border-[#780000] focus:ring-2 focus:ring-[#780000]/20 focus:outline-none transition-all">
                                    <option value="">All Staffing States</option>
                                    <option value="pending" {{ request('staffing') === 'pending' ? 'selected' : '' }}>Instructor Pending</option>
                                    <option value="staffed" {{ request('staffing') === 'staffed' ? 'selected' : '' }}>Fully Staffed</option>
                                </select>
                            </div>

                            <div class="pt-2 border-t border-[#E5E5EA] flex justify-end">
                                <button type="submit" class="btn-primary w-full min-h-[44px] py-2.5 rounded-xl text-sm font-bold shadow-2xs active:scale-[0.99] transition-all focus:outline-none focus:ring-2 focus:ring-[#780000]">
                                    Apply Filter & Sort
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <!-- Batches List -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 sm:gap-5">
        @forelse($batches as $batch)
        <div tabindex="0"
             role="link"
             aria-label="View details for {{ $batch->batch_number }}, {{ $batch->formatted_date_range }}, Status: {{ $batch->status_badge['label'] }}"
             onclick="window.location='{{ route('admin.batches.show', $batch) }}'" 
             onkeydown="if(event.key === 'Enter' || event.key === ' ') { event.preventDefault(); window.location='{{ route('admin.batches.show', $batch) }}'; }"
              class="bg-white rounded-xl border border-[#E5E5EA] p-4 sm:p-5 hover:ring-1 hover:ring-[#780000] focus:outline-none focus:ring-2 focus:ring-[#780000] active:scale-[0.99] cursor-pointer transition-all flex flex-col justify-between space-y-3 shadow-2xs group">
            
            <!-- Batch Information -->
            <div>
                <div class="flex items-start justify-between gap-2">
                    <div>
                        <span class="font-extrabold text-[#1D1D1F] text-base block leading-tight">
                            {{ $batch->batch_number }}
                        </span>
                    </div>
                    <span class="px-2.5 py-0.5 rounded-full text-xs font-bold shrink-0 {{ $batch->status_badge['class'] }}">
                        {{ $batch->status_badge['label'] }}
                    </span>
                </div>
                <div class="mt-1">
                    <strong class="text-[#1D1D1F] font-bold text-sm">{{ $batch->formatted_date_range }}</strong>
                </div>
                @if($batch->capacity_note)
                    <div class="mt-1.5 text-sm text-[#6E6E73] italic">
                        {{ $batch->capacity_note }}
                    </div>
                @endif

                <!-- Metrics and Operational Status -->
                <div class="mt-3 space-y-2 text-sm">

                    <!-- Staffing and Capacity Overview -->
                    <div class="space-y-1.5">
                        <div class="flex items-center justify-between text-sm">
                            <span class="font-bold text-[#1D1D1F]">
                                {{ $batch->total_participants_count }} pax / {{ $batch->computed_capacity }}
                            </span>
                            @if($batch->total_participants_count > 0 && $batch->is_coach_pending)
                                <span class="px-2.5 py-0.5 rounded-full text-xs font-bold text-amber-900 bg-amber-50">
                                    Instructor Pending
                                </span>
                            @elseif($batch->assigned_coaches_count > 0)
                                <span class="px-2.5 py-0.5 rounded-full text-xs font-bold text-emerald-800 bg-emerald-50">
                                    {{ $batch->assigned_coaches_count }} {{ Str::plural('Coach', $batch->assigned_coaches_count) }}
                                </span>
                            @endif
                        </div>
                        @php
                            $occPct = (int) round($batch->occupancy_percentage ?? 0);
                            $occWarning = $occPct >= 100 ? 'at capacity warning' : ($occPct > 70 ? 'high capacity warning' : 'normal capacity');
                        @endphp
                        <div role="progressbar" 
                             aria-valuenow="{{ $occPct }}" 
                             aria-valuemin="0" 
                             aria-valuemax="100" 
                             aria-label="Occupancy: {{ $occPct }} percent, {{ $occWarning }}"
                             class="w-full bg-[#E5E5EA] rounded-full h-2.5 overflow-hidden">
                            <div class="h-2.5 rounded-full {{ $occPct >= 100 ? 'bg-[#780000]' : ($occPct > 70 ? 'bg-amber-600' : 'bg-emerald-600') }}" 
                                 style="width: {{ min(100, $occPct) }}%"></div>
                        </div>
                    </div>

                </div>
            </div>

        </div>
        @empty
        <div class="col-span-full py-10 text-center text-[#6E6E73] bg-white rounded-xl border border-[#E5E5EA]">
            <p class="text-base font-bold text-[#1D1D1F]">No batches found</p>
            <p class="text-sm text-[#6E6E73] mt-1">Try adjusting your search criteria or create a new batch schedule.</p>
        </div>
        @endforelse
    </div>

    <!-- Pagination -->
    <div class="bg-[#F2F2F7] rounded-xl border border-[#E5E5EA] overflow-hidden [&>*]:border-t-0">
        {{ $batches->links() }}
    </div>

</div>
@endsection
