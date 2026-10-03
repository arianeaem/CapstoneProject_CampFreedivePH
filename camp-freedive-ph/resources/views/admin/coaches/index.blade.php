@extends('layouts.admin')

@section('title', 'Coach Roster & Schedules | Camp FreedivePH')

@section('content')
<div class="space-y-6 text-sm">
    
    <!-- Header and Actions -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl sm:text-3xl font-extrabold text-[#1D1D1F] tracking-tight">Coach Roster & Schedules</h1>
        </div>

        <div class="flex items-center gap-2.5 flex-wrap">
            <!-- Coach Requests Shortcut -->
            <a href="{{ route('admin.coaches.requests') }}" 
               class="btn-secondary px-3.5 py-2 text-sm sm:text-sm font-semibold flex items-center gap-1.5">
                <span>Coach Requests</span>
            </a>

            <!-- Matching Queue Shortcut (Primary Action on Right) -->
            <a href="{{ route('admin.coaches.matching') }}" 
               class="btn-primary px-4 py-2 text-sm sm:text-sm font-bold flex items-center gap-2 shadow-2xs">
                <span>Students Needing Coach</span>
                @if($unassignedStudentsCount > 0)
                    <span class="px-2 py-0.5 rounded-md text-sm font-bold bg-white text-[#780000]">
                        {{ $unassignedStudentsCount }}
                    </span>
                @endif
            </a>
        </div>
    </div>

    <!-- Toolbar and Filter Controls -->
    <div class="bg-white rounded-xl border border-[#E5E5EA] p-3 shadow-2xs">
        <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-3">
            
            <!-- Status Filter Tabs -->
            <div class="flex items-center gap-1.5 overflow-x-auto pb-1 lg:pb-0 scrollbar-none">
                <a href="{{ request()->fullUrlWithQuery(['status' => '']) }}" 
                   class="px-3 py-1.5 rounded-lg text-sm font-bold transition-all shrink-0 {{ !request('status') ? 'bg-[#780000] text-white shadow-2xs' : 'bg-white text-[#6E6E73] hover:text-[#1D1D1F] hover:bg-[#F2F2F7] border border-[#E5E5EA]' }}">
                    All Coaches ({{ $activeCount + $inactiveCount }})
                </a>
                <a href="{{ request()->fullUrlWithQuery(['status' => 'active']) }}" 
                   class="px-3 py-1.5 rounded-lg text-sm font-bold transition-all shrink-0 {{ request('status') === 'active' ? 'bg-[#780000] text-white shadow-2xs' : 'bg-white text-[#6E6E73] hover:text-[#1D1D1F] hover:bg-[#F2F2F7] border border-[#E5E5EA]' }}">
                    Active ({{ $activeCount }})
                </a>
                <a href="{{ request()->fullUrlWithQuery(['status' => 'inactive']) }}" 
                   class="px-3 py-1.5 rounded-lg text-sm font-bold transition-all shrink-0 {{ request('status') === 'inactive' ? 'bg-[#780000] text-white shadow-2xs' : 'bg-white text-[#6E6E73] hover:text-[#1D1D1F] hover:bg-[#F2F2F7] border border-[#E5E5EA]' }}">
                    Inactive ({{ $inactiveCount }})
                </a>
            </div>

            <!-- Search and Filter Controls -->
            <div class="flex items-center gap-2 w-full lg:w-auto" x-data="{ openFilters: false }">
                <form action="{{ route('admin.coaches.index') }}" method="GET" class="flex-1 min-w-0 lg:flex-initial">
                    @if(request('status'))
                        <input type="hidden" name="status" value="{{ request('status') }}">
                    @endif
                    @if(request('available_on'))
                        <input type="hidden" name="available_on" value="{{ request('available_on') }}">
                    @endif

                    <div class="relative w-full sm:w-64">
                        <input type="text" 
                               name="search" 
                               value="{{ request('search') }}" 
                               placeholder="Search coach name, email..." 
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
                        @if(request()->filled('available_on'))
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
                            <h4 class="font-bold text-sm text-[#1D1D1F]">Filter Coaches</h4>
                            <a href="{{ route('admin.coaches.index') }}" class="text-sm text-[#780000] hover:underline font-bold">Reset</a>
                        </div>

                        <form action="{{ route('admin.coaches.index') }}" method="GET" class="space-y-3 text-sm">
                            @if(request('status'))
                                <input type="hidden" name="status" value="{{ request('status') }}">
                            @endif
                            @if(request('search'))
                                <input type="hidden" name="search" value="{{ request('search') }}">
                            @endif

                            <div>
                                <label class="block font-bold text-[#6E6E73] text-sm mb-1">Available on Specific Date</label>
                                <input type="date" 
                                       name="available_on" 
                                       value="{{ request('available_on') }}" 
                                       class="w-full px-3 py-2 rounded-xl border border-[#D1D1D6] text-sm font-medium">
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

    <!-- Coach Roster -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 sm:gap-5">
        @forelse($coaches as $coach)
        @php
            $upcomingCount = $coach->assignedParticipants()
                ->where('status', 'assigned')
                ->where('dive_date', '>=', now()->toDateString())
                ->distinct('dive_date')
                ->count('dive_date');

            $nextAssignment = $coach->assignedParticipants()
                ->where('status', 'assigned')
                ->where('dive_date', '>=', now()->toDateString())
                ->with('batch')
                ->orderBy('dive_date', 'asc')
                ->first();

            $loadOnNext = $nextAssignment ? $coach->assignedCountForDate($nextAssignment->dive_date) : 0;
            $markedAvailableCount = $coach->coachAvailabilities->where('status', 'available')->where('date', '>=', now()->toDateString())->count();
        @endphp
        
        <div tabindex="0" 
             role="link" 
             onclick="window.location='{{ route('admin.coaches.show', $coach) }}'" 
             onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();window.location='{{ route('admin.coaches.show', $coach) }}';}" 
             class="bg-white rounded-xl border border-[#E5E5EA] p-4 sm:p-5 hover:ring-1 hover:ring-[#780000] focus:outline-none focus:ring-2 focus:ring-[#780000] active:scale-[0.99] cursor-pointer transition-all flex flex-col justify-between space-y-4 shadow-2xs group">
            
            <div class="space-y-3.5">
                <!-- Coach Header: Avatar + Name on Left, Status Badge on Right -->
                <div class="flex items-start justify-between gap-3">
                    <div class="flex items-center gap-3 min-w-0 flex-1">
                        <div class="w-11 h-11 rounded-full bg-[#F8EAEA] text-[#780000] border-2 border-[#780000] flex items-center justify-center font-extrabold text-base shrink-0 shadow-2xs">
                            {{ substr($coach->name, 0, 1) }}
                        </div>
                        <div class="min-w-0 flex-1">
                            <span class="font-extrabold text-[#1D1D1F] text-base block leading-tight truncate group-hover:text-[#780000] transition-colors">
                                {{ $coach->name }}
                            </span>
                            @if($coach->nickname)
                                <span class="text-xs sm:text-sm font-semibold text-[#780000] block mt-0.5">"{{ $coach->nickname }}"</span>
                            @endif
                        </div>
                    </div>

                    <!-- Status Badge -->
                    <span class="px-2.5 py-0.5 rounded-full text-xs font-bold shrink-0 {{ $coach->status === 'active' ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700' }}">
                        {{ ucfirst($coach->status) }}
                    </span>
                </div>

                <!-- 2x2 Data Grid (Matching Reference Picture Styling) -->
                <div class="grid grid-cols-2 gap-x-4 gap-y-3 pt-3 border-t border-[#F2F2F7]">
                    <div>
                        <span class="text-xs font-bold uppercase tracking-wider text-[#8E8E93] block">UPCOMING ACTIVE DATES</span>
                        <span class="text-sm font-semibold text-[#1D1D1F] block mt-0.5">
                            {{ $upcomingCount }} scheduled
                        </span>
                    </div>

                    <div>
                        <span class="text-xs font-bold uppercase tracking-wider text-[#8E8E93] block">CALENDAR AVAILABILITY</span>
                        <span class="text-sm font-semibold block mt-0.5 {{ $markedAvailableCount > 0 ? 'text-emerald-700' : 'text-[#8E8E93]' }}">
                            {{ $markedAvailableCount > 0 ? $markedAvailableCount . ' Open Dates' : '0 open dates' }}
                        </span>
                    </div>

                    <div>
                        <span class="text-xs font-bold uppercase tracking-wider text-[#8E8E93] block">NEXT DIVE BATCH</span>
                        <span class="text-sm font-semibold text-[#1D1D1F] block mt-0.5">
                            {{ $nextAssignment ? \Carbon\Carbon::parse($nextAssignment->dive_date)->format('M d, Y') : 'None' }}
                        </span>
                    </div>

                    <div>
                        <span class="text-xs font-bold uppercase tracking-wider text-[#8E8E93] block">NEXT BATCH LOAD</span>
                        <span class="text-sm font-semibold block mt-0.5 {{ $loadOnNext > ($coach->max_ratio ?? 4) ? 'text-rose-700 font-bold' : ($nextAssignment ? 'text-[#1D1D1F]' : 'text-[#8E8E93]') }}">
                            {{ $nextAssignment ? $loadOnNext . ' / ' . ($coach->max_ratio ?? 4) . ' Students' : '0 / ' . ($coach->max_ratio ?? 4) . ' Students' }}
                        </span>
                    </div>
                </div>
            </div>

        </div>
        @empty
        <div class="col-span-full py-10 text-center text-[#6E6E73] bg-white rounded-xl border border-[#E5E5EA]">
            <p class="text-base font-bold text-[#1D1D1F]">No coaches found</p>
            <p class="text-sm text-[#6E6E73] mt-1">Try adjusting your search criteria or clear active filters.</p>
        </div>
        @endforelse
    </div>

    <!-- Table Pagination -->
    <div class="bg-[#F2F2F7] rounded-xl border border-[#E5E5EA] overflow-hidden [&>*]:border-t-0">
        {{ $coaches->links() }}
    </div>

</div>
@endsection
