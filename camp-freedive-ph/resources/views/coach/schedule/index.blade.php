@extends('layouts.admin')

@section('title', 'My Assigned Schedule | Coach Portal')

@section('content')
<div class="space-y-6" x-data="{ 
    activeTab: '{{ $activeTab }}', 
    releaseModalOpen: false, 
    selectedBatch: null, 
    submittingRelease: false,
    openFilters: false,
    openBatches: [],
    toggleBatch(batchId) {
        if (this.openBatches.includes(batchId)) {
            this.openBatches = this.openBatches.filter(id => id !== batchId);
        } else {
            this.openBatches.push(batchId);
        }
    },
    isBatchOpen(batchId) {
        return this.openBatches.includes(batchId);
    }
}">
    
    <!-- Top Header & Tabs -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl sm:text-3xl font-extrabold text-[#1D1D1F] tracking-tight">My Assigned Schedule &amp; Student Rosters</h1>
        </div>

        <!-- Tab Controls -->
        <div class="flex items-center bg-[#F2F2F7] rounded-xl border border-[#E5E5EA] p-1 w-full md:w-auto"
             role="tablist"
             aria-label="Coach Schedule Tabs">
            <button type="button" 
                    id="tab-upcoming"
                    role="tab"
                    :aria-selected="activeTab === 'upcoming' ? 'true' : 'false'"
                    aria-controls="panel-upcoming"
                    :tabindex="activeTab === 'upcoming' ? '0' : '-1'"
                    @click="activeTab = 'upcoming'"
                    @keydown.arrow-right.prevent="activeTab = 'history'; $nextTick(() => document.getElementById('tab-history')?.focus())"
                    :class="activeTab === 'upcoming' ? 'bg-white text-[#1D1D1F] font-bold shadow-xs' : 'text-[#6E6E73] font-semibold hover:text-[#1D1D1F]'"
                    class="flex-1 md:flex-initial min-h-[44px] px-3.5 sm:px-4 py-2.5 rounded-lg text-sm transition-all flex items-center justify-center gap-2 cursor-pointer focus:outline-none focus-visible:ring-2 focus-visible:ring-[#780000]">
                <span>Upcoming Dives ({{ count($upcomingBatches) }})</span>
            </button>
            <div class="w-px h-5 bg-[#E5E5EA] mx-0.5 shrink-0" aria-hidden="true"></div>
            <button type="button" 
                    id="tab-history"
                    role="tab"
                    :aria-selected="activeTab === 'history' ? 'true' : 'false'"
                    aria-controls="panel-history"
                    :tabindex="activeTab === 'history' ? '0' : '-1'"
                    @click="activeTab = 'history'"
                    @keydown.arrow-left.prevent="activeTab = 'upcoming'; $nextTick(() => document.getElementById('tab-upcoming')?.focus())"
                    :class="activeTab === 'history' ? 'bg-white text-[#1D1D1F] font-bold shadow-xs' : 'text-[#6E6E73] font-semibold hover:text-[#1D1D1F]'"
                    class="flex-1 md:flex-initial min-h-[44px] px-3.5 sm:px-4 py-2.5 rounded-lg text-sm transition-all flex items-center justify-center gap-2 cursor-pointer focus:outline-none focus-visible:ring-2 focus-visible:ring-[#780000]">
                <span>Past History ({{ $totalCompletedBatchesCount }})</span>
            </button>
        </div>
    </div>

    <!-- 1. UPCOMING CONFIRMED ASSIGNMENTS (Batch Card that opens Assigned Student Roster on click) -->
    <div x-show="activeTab === 'upcoming'" role="tabpanel" id="panel-upcoming" aria-labelledby="tab-upcoming" class="space-y-4">
        @forelse($upcomingBatches as $item)
            @php
                $batch = $item['batch'];
                $students = $item['students'];
                $weatherBadge = $item['weather_badge'];
                $assessment = $item['assessment'];
                $releaseReq = $item['release_request'];

                $wClass = strtolower(trim($item['weather_class'] ?? 'Safe'));
                if (str_contains($wClass, 'safe')) {
                    $wBg = 'bg-emerald-100 text-emerald-900';
                    $wDot = 'bg-emerald-600';
                } elseif (str_contains($wClass, 'mod') || str_contains($wClass, 'adv') || str_contains($wClass, 'warn') || str_contains($wClass, 'caut')) {
                    $wBg = 'bg-amber-100 text-amber-900';
                    $wDot = 'bg-amber-600';
                } elseif (str_contains($wClass, 'risk') || str_contains($wClass, 'crit') || str_contains($wClass, 'high') || str_contains($wClass, 'dan')) {
                    $wBg = 'bg-rose-100 text-rose-900';
                    $wDot = 'bg-rose-600';
                } else {
                    $wBg = 'bg-emerald-100 text-emerald-900';
                    $wDot = 'bg-emerald-600';
                }
            @endphp

            <div class="bg-white rounded-2xl border border-[#E5E5EA] overflow-hidden">
                
                <!-- Batch Header Banner -->
                <div class="p-4 sm:p-6 select-none flex flex-col md:flex-row md:items-center justify-between gap-4 bg-white">
                    
                    <!-- Left Column: Weather + Batch Title + Dive Dates & Students -->
                    <div class="space-y-2.5 flex-1 min-w-0">
                        <!-- Weather Safety Badge & Description -->
                        <div class="flex items-center gap-2.5 flex-wrap">
                            <span class="px-2.5 py-1 rounded-lg text-sm font-black {{ $wBg }} inline-flex items-center shadow-2xs shrink-0">
                                <span>{{ $item['weather_class'] }}</span>
                            </span>
                            <span class="text-sm text-[#6E6E73] font-medium leading-tight">
                                {{ \App\Services\WeatherForecastService::MEANING_MAP[$item['weather_class']] ?? ($assessment?->recommended_action ?? 'Standard marine safety protocols in effect.') }}
                            </span>
                        </div>

                        <div>
                            <h2 class="text-xl sm:text-2xl font-black text-[#1D1D1F] tracking-tight">
                                {{ $batch->batch_number }}
                            </h2>
                            <div class="space-y-1 mt-1 text-sm text-[#6E6E73]">
                                <p>
                                    Dive Dates: <strong class="text-[#1D1D1F]">{{ $batch->formatted_date_range }} ({{ $batch->start_date->format('D') }} - {{ $batch->end_date->format('D') }})</strong>
                                </p>
                                <p>
                                    Assigned Students: <strong class="text-[#1D1D1F]">{{ $item['students_count'] }} Student(s)</strong>
                                    @if(!empty($item['class_counts']))
                                        <span class="text-[#6E6E73] mx-1">·</span>
                                        <span class="text-[#1D1D1F] font-semibold">
                                            @php
                                                $classBreakdownStrs = [];
                                                foreach ($item['class_counts'] as $cType => $cnt) {
                                                    $classBreakdownStrs[] = "{$cnt} {$cType}";
                                                }
                                            @endphp
                                            {{ implode(', ', $classBreakdownStrs) }}
                                        </span>
                                    @endif
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- Right Column: Release Action & Expand Roster Button -->
                    <div class="flex items-center gap-2.5 shrink-0 self-start md:self-center">
                        <!-- Release Action -->
                        @if($releaseReq)
                            <span class="min-h-[44px] px-3.5 py-2.5 rounded-xl bg-amber-50 text-amber-800 border border-amber-300 text-sm font-bold shadow-2xs inline-flex items-center">
                                Release Requested
                            </span>
                        @elseif($item['can_request_release'])
                            <button type="button" 
                                    @click="selectedBatch = {{ json_encode($item) }}; submittingRelease = false; releaseModalOpen = true"
                                    class="min-h-[44px] px-3.5 py-2.5 rounded-xl bg-rose-50 text-rose-700 hover:bg-rose-100 text-sm font-bold transition-all cursor-pointer active:scale-[0.98] focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-600">
                                Request Release
                            </button>
                        @else
                            <span class="min-h-[44px] px-3.5 py-2.5 rounded-xl text-sm font-bold bg-[#F2F2F7] text-[#6E6E73] inline-flex items-center" title="Release requests are locked within 48 hours of dive start">
                                Locked (&lt;48h)
                            </span>
                        @endif

                        <!-- Expand / Collapse Roster Toggle Button -->
                        <button type="button" 
                                id="roster-toggle-{{ $batch->id }}"
                                @click="toggleBatch({{ $batch->id }})"
                                :aria-expanded="isBatchOpen({{ $batch->id }}) ? 'true' : 'false'"
                                aria-controls="roster-panel-{{ $batch->id }}"
                                class="min-h-[44px] px-4 py-2.5 rounded-xl bg-white hover:bg-[#F2F2F7] border border-[#E5E5EA] text-sm font-bold text-[#1D1D1F] flex items-center justify-center gap-2 transition-all cursor-pointer active:scale-[0.98] focus:outline-none focus-visible:ring-2 focus-visible:ring-[#780000]">
                            <span x-text="isBatchOpen({{ $batch->id }}) ? 'Hide Roster' : 'View Student Roster'"></span>
                            <svg class="w-4 h-4 text-[#6E6E73] transition-transform duration-200" 
                                 :class="isBatchOpen({{ $batch->id }}) ? 'rotate-180' : ''"
                                 viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"
                                 aria-hidden="true">
                                 <polyline points="6 9 12 15 18 9"></polyline>
                            </svg>
                        </button>
                    </div>

                </div>

                <!-- Expandable Assigned Student Roster Section (Dashboard Card Format) -->
                <div x-show="isBatchOpen({{ $batch->id }})" 
                     x-cloak 
                     id="roster-panel-{{ $batch->id }}"
                     role="region"
                     aria-labelledby="roster-toggle-{{ $batch->id }}"
                     class="border-t border-[#E5E5EA] bg-[#F2F2F7]/80 p-4 sm:p-6 space-y-4">
                    
                    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 pb-1">
                        <div>
                            <h3 class="text-base font-black text-[#1D1D1F]">Assigned Student Roster</h3>
                            <p class="text-sm text-[#6E6E73]">Review student swimming abilities and health conditions prior to boat departure</p>
                        </div>
                        <span class="text-sm font-extrabold text-[#1D1D1F] bg-white border border-[#E5E5EA] px-3 py-1 rounded-xl self-start sm:self-auto shadow-2xs">
                            {{ $item['students_count'] }} Diver(s) in Group
                        </span>
                    </div>

                    <!-- 2-Column Student Cards Grid (Matching Dashboard Format) -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 sm:gap-5">
                        @foreach($students as $student)
                            @php
                                $rawCondition = trim($student->health_condition ?? '');
                                $cleanHealth = strtolower(rtrim($rawCondition, '.'));
                                $isNoneOrGeneral = empty($cleanHealth) 
                                    || in_array($cleanHealth, ['none', 'none declared', 'no', 'n/a', 'na', 'nil', 'normal', 'fit for diving', 'fit for diving, no declared medical issues', 'cleared medical waiver', 'first time freediving'])
                                    || str_starts_with($cleanHealth, 'fit for diving')
                                    || str_starts_with($cleanHealth, 'none')
                                    || str_starts_with($cleanHealth, 'cleared medical waiver')
                                    || str_starts_with($cleanHealth, 'first time freediving')
                                    || str_starts_with($cleanHealth, 'certified aida')
                                    || str_starts_with($cleanHealth, 'working on frenzel');
                                $hasMedical = !$isNoneOrGeneral && !empty($rawCondition);

                                $swim = strtolower($student->swimmer_status ?? 'swimmer');
                                $classType = $student->booking?->formatted_class_type ?? 'Discovery';
                                $shortClassType = ucfirst($student->booking?->class_type ?? 'Discovery');
                            @endphp
                            
                            <!-- Individual Student Card -->
                            <div class="bg-white rounded-2xl border border-[#E5E5EA] p-4 sm:p-5 space-y-4 flex flex-col justify-between">
                                
                                <!-- Card Header: Student Name & Compact Package Badge -->
                                <div class="flex items-start justify-between gap-2.5">
                                    <div class="min-w-0 flex-1">
                                        <span class="text-sm uppercase font-bold text-[#6E6E73] tracking-wider block">Student</span>
                                        <h4 class="font-black text-[#1D1D1F] text-base sm:text-lg tracking-tight mt-0.5 truncate">{{ $student->name }}</h4>
                                    </div>
                                    <span class="px-2.5 py-1 rounded-lg text-sm font-black bg-[#F8EAEA] text-[#780000] shrink-0">
                                        {{ $shortClassType }}
                                    </span>
                                </div>

                                <!-- Information Rows (3 Semantic Rows) -->
                                <div class="space-y-3.5 text-sm">
                                    
                                    <!-- Row 1: Age & Package -->
                                    <div class="grid grid-cols-2 gap-3 sm:gap-4 items-start">
                                        <div>
                                            <span class="text-sm font-bold text-[#6E6E73] uppercase tracking-wider block mb-0.5">Age</span>
                                            <span class="font-extrabold text-sm sm:text-base text-[#1D1D1F] block">{{ $student->age }} yrs</span>
                                        </div>
                                        <div>
                                            <span class="text-sm font-bold text-[#6E6E73] uppercase tracking-wider block mb-0.5">Package</span>
                                            <span class="font-extrabold text-sm sm:text-sm text-[#1D1D1F] block leading-snug">{{ $classType }}</span>
                                        </div>
                                    </div>

                                    <!-- Row 2: Swimming Ability & Health/Medical -->
                                    <div class="grid grid-cols-2 gap-3 sm:gap-4 items-start">
                                        <div>
                                            <span class="text-sm font-bold text-[#6E6E73] uppercase tracking-wider block mb-1">Swimming Ability</span>
                                            @if($swim === 'confident' || $swim === 'confident_swimmer')
                                                 <span class="inline-flex items-center px-2.5 py-1 rounded-lg font-black text-sm bg-emerald-100 text-emerald-900 shadow-2xs">
                                                     <span>Confident Swimmer</span>
                                                 </span>
                                             @elseif($swim === 'non_swimmer')
                                                 <span class="inline-flex items-center px-2.5 py-1 rounded-lg font-black text-sm bg-rose-100 text-rose-900 shadow-2xs">
                                                     <span>Non-Swimmer</span>
                                                 </span>
                                             @else
                                                 <span class="inline-flex items-center px-2.5 py-1 rounded-lg font-black text-sm bg-blue-100 text-blue-900 shadow-2xs">
                                                     <span>Swimmer</span>
                                                 </span>
                                             @endif
                                        </div>

                                        <div>
                                            <span class="text-sm font-bold text-[#6E6E73] uppercase tracking-wider block mb-1">Health / Medical</span>
                                            @if($hasMedical)
                                                <span class="font-bold text-sm text-amber-900 inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-amber-100 shadow-2xs">
                                                    <svg class="w-3.5 h-3.5 text-amber-800 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                                                    <span class="truncate">{{ $student->health_condition }}</span>
                                                </span>
                                            @else
                                                <span class="text-sm sm:text-sm text-[#6E6E73] font-bold block pt-1">None declared</span>
                                            @endif
                                        </div>
                                    </div>

                                    <!-- Row 3: Lead Booker & Contact -->
                                    <div class="grid grid-cols-2 gap-3 sm:gap-4 items-start">
                                        <div>
                                            <span class="text-sm font-bold text-[#6E6E73] uppercase tracking-wider block mb-0.5">Lead Booker</span>
                                            <span class="font-extrabold text-sm sm:text-sm text-[#1D1D1F] truncate block">{{ $student->booking?->contact_name ?? $student->name }}</span>
                                        </div>
                                        <div>
                                            <span class="text-sm font-bold text-[#6E6E73] uppercase tracking-wider block mb-0.5">Contact</span>
                                            <a href="tel:{{ $student->booking?->contact_phone ?: '09185559876' }}" class="font-extrabold text-sm sm:text-sm text-[#780000] hover:underline block truncate">
                                                {{ $student->booking?->contact_phone ?: ($student->booking?->contact_email ?: '0918 555 9876') }}
                                            </a>
                                        </div>
                                    </div>

                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>

            </div>
        @empty
            <div class="bg-white rounded-2xl p-12 border border-[#E5E5EA] text-center space-y-3">
                <h3 class="text-base font-bold text-[#1D1D1F]">No Upcoming Confirmed Assignments</h3>
                <p class="text-sm text-[#6E6E73] max-w-md mx-auto leading-relaxed">
                    You currently have no students assigned for upcoming dates. Make sure your availability calendar is marked free or browse the Open Slot Requests board.
                </p>
                <div class="pt-2">
                    <a href="{{ route('coach.availability.index') }}" class="btn-primary min-h-[44px] px-4 py-2.5 rounded-xl text-white text-sm font-bold inline-flex items-center gap-2 shadow-xs active:scale-[0.98] focus:outline-none focus-visible:ring-2 focus-visible:ring-[#780000] focus-visible:ring-offset-2">
                        <span>Open Availability Calendar</span>
                    </a>
                </div>
            </div>
        @endforelse
    </div>

    <!-- 2. PAST DIVE HISTORY (Admin/Owner Filter Pattern & Expandable Batches) -->
    <div x-show="activeTab === 'history'" role="tabpanel" id="panel-history" aria-labelledby="tab-history" class="space-y-6">
        
        <!-- Filter Bar Toolbar (Identical to Admin/Owner Filter Patterns) -->
        <div class="bg-white rounded-2xl p-3 sm:p-4 border border-[#E5E5EA]">
            <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-3">
                
                <!-- Quick Class Package Tabs -->
                <div class="flex items-center gap-1.5 overflow-x-auto pb-1 lg:pb-0 scrollbar-none">
                    <a href="{{ request()->fullUrlWithQuery(['class_type' => '', 'tab' => 'history']) }}" 
                       class="px-3 py-1.5 rounded-lg text-sm font-bold transition-all shrink-0 {{ !request('class_type') ? 'bg-[#780000] text-white shadow-2xs' : 'bg-white text-[#6E6E73] hover:text-[#1D1D1F] hover:bg-[#F2F2F7] border border-[#E5E5EA]' }}">
                        All Classes
                    </a>
                    <a href="{{ request()->fullUrlWithQuery(['class_type' => 'discovery', 'tab' => 'history']) }}" 
                       class="px-3 py-1.5 rounded-lg text-sm font-bold transition-all shrink-0 {{ request('class_type') === 'discovery' ? 'bg-[#780000] text-white shadow-2xs' : 'bg-white text-[#6E6E73] hover:text-[#1D1D1F] hover:bg-[#F2F2F7] border border-[#E5E5EA]' }}">
                        Discovery
                    </a>
                    <a href="{{ request()->fullUrlWithQuery(['class_type' => 'fundive', 'tab' => 'history']) }}" 
                       class="px-3 py-1.5 rounded-lg text-sm font-bold transition-all shrink-0 {{ request('class_type') === 'fundive' ? 'bg-[#780000] text-white shadow-2xs' : 'bg-white text-[#6E6E73] hover:text-[#1D1D1F] hover:bg-[#F2F2F7] border border-[#E5E5EA]' }}">
                        Fundive
                    </a>
                    <a href="{{ request()->fullUrlWithQuery(['class_type' => 'refinement', 'tab' => 'history']) }}" 
                       class="px-3 py-1.5 rounded-lg text-sm font-bold transition-all shrink-0 {{ request('class_type') === 'refinement' ? 'bg-[#780000] text-white shadow-2xs' : 'bg-white text-[#6E6E73] hover:text-[#1D1D1F] hover:bg-[#F2F2F7] border border-[#E5E5EA]' }}">
                        Refinement
                    </a>
                </div>

                <!-- Search & Advanced Filter Controls Dropdown -->
                <div class="flex items-center gap-2 self-end lg:self-auto shrink-0 relative">
                    <button type="button" 
                            @click="openFilters = !openFilters" 
                            class="px-3.5 py-1.5 rounded-xl border border-[#E5E5EA] bg-white hover:bg-[#F2F2F7] text-[#1D1D1F] text-sm font-semibold flex items-center justify-center gap-1.5 whitespace-nowrap shrink-0 cursor-pointer transition-all">
                        <img src="{{ asset('icons/icons8-filter-60.png') }}" alt="Filter" class="w-4.5 h-4.5 object-contain inline-block shrink-0">
                        <span class="whitespace-nowrap">Filter</span>
                        @if(request()->filled('date_from') || request()->filled('date_to') || request()->filled('class_type'))
                            <span class="w-2 h-2 rounded-full bg-[#780000] shrink-0"></span>
                        @endif
                    </button>
                    <!-- Filter Dropdown Menu (Standardized) -->
                    <div x-show="openFilters" 
                         @click.outside="openFilters = false" 
                         x-cloak 
                         x-transition:enter="transition ease-out duration-150 transform"
                         x-transition:enter-start="opacity-0 scale-95 -translate-y-1"
                         x-transition:enter-end="opacity-100 scale-100 translate-y-0"
                         x-transition:leave="transition ease-in duration-100 transform"
                         x-transition:leave-start="opacity-100 scale-100 translate-y-0"
                         x-transition:leave-end="opacity-0 scale-95 -translate-y-1"
                         class="absolute right-0 top-full mt-2 w-[calc(100vw-2rem)] sm:w-80 max-w-sm bg-white rounded-2xl border border-[#E5E5EA] shadow-xl p-4 z-50 space-y-3">
                        <div class="flex items-center justify-between">
                            <h4 class="font-bold text-sm text-[#1D1D1F]">Filter Past History</h4>
                            <a href="{{ route('coach.schedule.index', ['tab' => 'history']) }}" class="text-sm text-[#780000] hover:underline font-bold">Reset</a>
                        </div>

                        <form method="GET" action="{{ route('coach.schedule.index') }}" class="space-y-3 text-sm">
                            <input type="hidden" name="tab" value="history">

                            <div>
                                <label class="block font-bold text-[#6E6E73] text-sm mb-1">From Date</label>
                                <input type="date" 
                                       name="date_from" 
                                       value="{{ request('date_from') }}" 
                                       class="w-full px-3 py-2 rounded-xl border border-[#D1D1D6] text-sm font-medium">
                            </div>

                            <div>
                                <label class="block font-bold text-[#6E6E73] text-sm mb-1">To Date</label>
                                <input type="date" 
                                       name="date_to" 
                                       value="{{ request('date_to') }}" 
                                       class="w-full px-3 py-2 rounded-xl border border-[#D1D1D6] text-sm font-medium">
                            </div>

                            <div>
                                <label class="block font-bold text-[#6E6E73] text-sm mb-1">Class Type</label>
                                <select name="class_type" class="w-full px-3 py-2 rounded-xl border border-[#D1D1D6] text-sm font-medium">
                                    <option value="">All Class Types</option>
                                    <option value="discovery" {{ request('class_type') === 'discovery' ? 'selected' : '' }}>Discovery</option>
                                    <option value="fundive" {{ request('class_type') === 'fundive' ? 'selected' : '' }}>Fundive</option>
                                    <option value="refinement" {{ request('class_type') === 'refinement' ? 'selected' : '' }}>Refinement</option>
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

        <!-- History Statistics Cards -->
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div class="p-4 sm:p-5 rounded-2xl bg-white border border-[#E5E5EA] flex items-center justify-between">
                <div>
                    <div class="text-sm text-[#6E6E73] font-bold uppercase tracking-wider">Completed Sessions</div>
                    <div class="text-2xl sm:text-3xl font-black text-[#1D1D1F] mt-1">{{ $totalCompletedBatchesCount }} Batches</div>
                </div>
            </div>
            <div class="p-4 sm:p-5 rounded-2xl bg-white border border-[#E5E5EA] flex items-center justify-between">
                <div>
                    <div class="text-sm text-[#6E6E73] font-bold uppercase tracking-wider">Total Students Coached</div>
                    <div class="text-2xl sm:text-3xl font-black text-[#1D1D1F] mt-1">{{ $totalPastStudentsCount }} Students</div>
                </div>
            </div>
        </div>

        <!-- Past Sessions List (Expandable Batch Cards) -->
        @forelse($historyBatches as $item)
            @php
                $batch = $item['batch'];
                $students = $item['students'];
            @endphp
            <div class="bg-white rounded-2xl border border-[#E5E5EA] hover:border-[#D1D1D6] transition-all overflow-hidden">
                
                <!-- Past Batch Header -->
                <div class="p-4 sm:p-5 select-none flex flex-col sm:flex-row sm:items-center justify-between gap-3 bg-white">
                    <div class="min-w-0 flex-1">
                        <h3 class="text-base font-black text-[#1D1D1F]">{{ $batch->batch_number }}</h3>
                        <div class="space-y-0.5 mt-1 text-sm text-[#6E6E73]">
                            <p>
                                Dive Dates: <strong class="text-[#1D1D1F]">{{ $batch->formatted_date_range }} ({{ $batch->start_date->format('D') }} - {{ $batch->end_date->format('D') }})</strong>
                            </p>
                            <p>
                                Students Coached: <strong class="text-[#1D1D1F]">{{ $item['students_count'] }} Student(s)</strong>
                                @if(!empty($item['class_counts']))
                                    <span class="text-[#6E6E73] mx-1">·</span>
                                    <span class="text-[#1D1D1F] font-semibold">
                                        @php
                                            $histBreakdownStrs = [];
                                            foreach ($item['class_counts'] as $cType => $cnt) {
                                                $histBreakdownStrs[] = "{$cnt} {$cType}";
                                            }
                                        @endphp
                                        {{ implode(', ', $histBreakdownStrs) }}
                                    </span>
                                @endif
                            </p>
                        </div>
                    </div>

                    <button type="button" 
                            id="roster-toggle-hist-{{ $batch->id }}"
                            @click="toggleBatch('hist_{{ $batch->id }}')"
                            :aria-expanded="isBatchOpen('hist_{{ $batch->id }}') ? 'true' : 'false'"
                            aria-controls="roster-panel-hist-{{ $batch->id }}"
                            class="min-h-[44px] px-4 py-2 rounded-xl bg-white hover:bg-[#F2F2F7] border border-[#E5E5EA] text-sm font-bold text-[#1D1D1F] flex items-center justify-center gap-2 transition-all cursor-pointer self-start sm:self-auto active:scale-[0.98] focus:outline-none focus-visible:ring-2 focus-visible:ring-[#780000]">
                        <span x-text="isBatchOpen('hist_{{ $batch->id }}') ? 'Hide Past Roster' : 'View Past Roster'"></span>
                        <svg class="w-3.5 h-3.5 text-[#6E6E73] transition-transform duration-200" 
                             :class="isBatchOpen('hist_{{ $batch->id }}') ? 'rotate-180' : ''"
                             viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"
                             aria-hidden="true">
                            <polyline points="6 9 12 15 18 9"></polyline>
                        </svg>
                    </button>
                </div>

                <!-- Expandable Past Student Roster -->
                <div x-show="isBatchOpen('hist_{{ $batch->id }}')" 
                     x-cloak 
                     id="roster-panel-hist-{{ $batch->id }}"
                     role="region"
                     aria-labelledby="roster-toggle-hist-{{ $batch->id }}"
                     class="space-y-3">
                    <div class="overflow-x-auto border border-[#E5E5EA] rounded-xl bg-white">
                        <table class="w-full text-left text-sm">
                            <thead class="bg-[#F2F2F7] border-b border-[#E5E5EA] text-[#6E6E73] font-bold uppercase text-sm">
                                <tr>
                                    <th class="px-4 py-3">Student Name</th>
                                    <th class="px-4 py-3">Age</th>
                                    <th class="px-4 py-3">Class Type</th>
                                    <th class="px-4 py-3">Swimming Ability</th>
                                    <th class="px-4 py-3">Medical Notes</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-[#E5E5EA] bg-white text-[#1D1D1F]">
                                @foreach($students as $s)
                                    @php 
                                        $swimStatus = strtolower($s->swimmer_status ?? 'swimmer'); 
                                        $rawCondition = trim($s->health_condition ?? '');
                                        $cleanHealth = strtolower(rtrim($rawCondition, '.'));
                                        $isNoneOrGeneral = empty($cleanHealth) 
                                            || in_array($cleanHealth, ['none', 'none declared', 'no', 'n/a', 'na', 'nil', 'normal', 'fit for diving', 'fit for diving, no declared medical issues', 'cleared medical waiver', 'first time freediving'])
                                            || str_starts_with($cleanHealth, 'fit for diving')
                                            || str_starts_with($cleanHealth, 'none')
                                            || str_starts_with($cleanHealth, 'cleared medical waiver')
                                            || str_starts_with($cleanHealth, 'first time freediving')
                                            || str_starts_with($cleanHealth, 'certified aida')
                                            || str_starts_with($cleanHealth, 'working on frenzel');
                                        $hasMedical = !$isNoneOrGeneral && !empty($rawCondition);
                                    @endphp
                                    <tr class="hover:bg-[#F2F2F7] transition-colors">
                                        <td class="px-4 py-3 font-bold text-[#1D1D1F]">{{ $s->name }}</td>
                                        <td class="px-4 py-3 text-[#6E6E73] font-semibold">{{ $s->age }} yrs</td>
                                        <td class="px-4 py-3 text-[#780000] font-bold">{{ $s->booking?->formatted_class_type ?? 'Discovery' }}</td>
                                        <td class="px-4 py-3">
                                            @if($swimStatus === 'confident' || $swimStatus === 'confident_swimmer')
                                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-lg text-sm font-black bg-emerald-100 text-emerald-900 shadow-2xs">
                                                    <span>Confident Swimmer</span>
                                                </span>
                                            @elseif($swimStatus === 'non_swimmer')
                                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-lg text-sm font-black bg-rose-100 text-rose-900 shadow-2xs">
                                                    <span>Non-Swimmer</span>
                                                </span>
                                            @else
                                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-lg text-sm font-black bg-blue-100 text-blue-900 shadow-2xs">
                                                    <span>Swimmer</span>
                                                </span>
                                            @endif
                                        </td>
                                        <td class="px-4 py-3">
                                            @if($hasMedical)
                                                <span class="font-bold text-xs text-amber-900 inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-amber-100 shadow-2xs" title="{{ $rawCondition }}">
                                                    <svg class="w-3.5 h-3.5 text-amber-800 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                                                    <span class="truncate max-w-[180px]">{{ $rawCondition }}</span>
                                                </span>
                                            @else
                                                <span class="text-xs text-[#6E6E73] font-semibold">None declared</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        @empty
            <div class="bg-white rounded-2xl p-10 border border-[#E5E5EA] text-center text-[#6E6E73] text-sm">
                No past completed dive history found for the selected criteria.
            </div>
        @endforelse

    </div>

    <!-- Emergency Release Modal -->
    <div x-show="releaseModalOpen" 
         x-cloak 
         role="dialog"
         aria-modal="true"
         aria-labelledby="schedule-release-modal-title"
         @keydown.escape.window="releaseModalOpen = false"
         class="fixed inset-0 z-50 overflow-y-auto bg-black/50 backdrop-blur-sm flex items-center justify-center p-3 sm:p-4"
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-150"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0">
        <div class="bg-white rounded-2xl max-w-lg w-full p-5 sm:p-6 shadow-2xl border border-[#E5E5EA] space-y-6 relative" 
             @click.outside="releaseModalOpen = false"
             x-transition:enter="transition ease-out duration-200"
             x-transition:enter-start="opacity-0 scale-95"
             x-transition:enter-end="opacity-100 scale-100"
             x-transition:leave="transition ease-in duration-150"
             x-transition:leave-start="opacity-100 scale-100"
             x-transition:leave-end="opacity-0 scale-95">
            
            <div class="flex items-start justify-between border-b border-[#E5E5EA] pb-4">
                <div>
                    <h3 id="schedule-release-modal-title" class="text-lg font-black text-[#1D1D1F]">Request Assignment Release</h3>
                    <p class="text-sm font-semibold text-rose-600 mt-0.5">Emergency Staffing Request</p>
                </div>
                <button type="button" 
                        @click="releaseModalOpen = false" 
                        aria-label="Close release modal" 
                        class="w-11 h-11 min-h-[44px] min-w-[44px] -mr-2 -mt-1 rounded-full flex items-center justify-center text-[#6E6E73] hover:text-[#1D1D1F] hover:bg-[#F2F2F7] transition-all cursor-pointer focus:outline-none focus-visible:ring-2 focus-visible:ring-[#780000]">
                    <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                        <path d="M18 6L6 18M6 6l12 12"/>
                    </svg>
                </button>
            </div>

            <template x-if="selectedBatch">
                <form action="{{ route('coach.availability.release') }}" 
                      method="POST" 
                      @submit="submittingRelease = true" 
                      class="space-y-4">
                    @csrf
                    <input type="hidden" name="batch_id" :value="selectedBatch.batch?.id">
                    <input type="hidden" name="dive_date" :value="selectedBatch.dive_date">

                    <div class="p-3.5 rounded-xl bg-[#F2F2F7] text-sm space-y-1 shadow-2xs">
                        <div class="font-bold text-[#1D1D1F]" x-text="selectedBatch.batch?.name || selectedBatch.batch?.batch_number"></div>
                        <div class="text-[#6E6E73]">Students Assigned: <strong x-text="selectedBatch.students_count"></strong></div>
                    </div>

                    <div class="space-y-1.5">
                        <label for="schedule-release-reason" class="block text-sm font-bold text-[#1D1D1F]">
                            Reason for Emergency Release <span class="text-rose-500">*</span>
                        </label>
                        <textarea id="schedule-release-reason"
                                  name="reason" 
                                  rows="4" 
                                  required 
                                  placeholder="Please explain the emergency or unavoidable circumstance..."
                                  class="w-full text-sm rounded-xl border border-[#D1D1D6] focus:border-[#780000] focus:ring-[#780000] p-3"></textarea>
                    </div>

                    <div class="flex items-center justify-end gap-2.5 pt-2">
                        <button type="button" 
                                @click="releaseModalOpen = false" 
                                class="btn-secondary min-h-[44px] px-4 py-2 text-sm font-semibold rounded-xl cursor-pointer transition-all active:scale-[0.99] focus:outline-none focus-visible:ring-2 focus-visible:ring-[#780000]">
                            Cancel
                        </button>
                        <button type="submit" 
                                :disabled="submittingRelease" 
                                class="btn-danger min-h-[44px] px-5 py-2 text-sm font-bold rounded-xl cursor-pointer transition-all active:scale-[0.99] focus:outline-none focus-visible:ring-2 focus-visible:ring-[#D70015] focus-visible:ring-offset-2 disabled:opacity-50 disabled:cursor-not-allowed flex items-center justify-center gap-2">
                            <span x-show="!submittingRelease">Submit Release Request</span>
                            <span x-show="submittingRelease" x-cloak class="inline-flex items-center gap-2">
                                <svg class="animate-spin h-4 w-4 text-white" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path></svg>
                                <span>Submitting...</span>
                            </span>
                        </button>
                    </div>
                </form>
            </template>

        </div>
    </div>

</div>
@endsection
