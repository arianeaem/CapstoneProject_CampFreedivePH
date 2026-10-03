@extends('layouts.admin')

@section('title', 'Coach Dashboard | Camp FreedivePH')

@section('content')
<div class="space-y-6" x-data="{ 
    openReleaseModal: false, 
    openApplyModal: false, 
    submittingRelease: false, 
    submittingApply: false,
    isTogglingDate: false,
    togglingDateStr: null,
    selectedOpening: null, 
    selectedOpeningId: null, 
    selectedOpeningDate: '',
    selectedOpeningBatch: '',
    selectedOpeningNeeded: 0,
    openVolunteerModal(opening, datesDisplay) {
        this.selectedOpening = opening;
        this.selectedOpeningId = opening.id;
        this.selectedOpeningDate = datesDisplay;
        this.selectedOpeningBatch = opening.batch?.batch_number || opening.batch?.name || 'Camp Session';
        this.selectedOpeningNeeded = opening.needed_students_count || 1;
        this.openApplyModal = true;
    }
}">
    
    <!-- Top Command Header -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl sm:text-3xl font-extrabold text-[#1D1D1F] tracking-tight">
                Welcome back, {{ $coach->name }}!
            </h1>
            <p class="text-sm text-[#6E6E73] mt-1">
                <span>It's {{ now('Asia/Manila')->format('l, F d, Y') }}</span>
            </p>
        </div>

        <div class="flex items-center gap-2.5 flex-wrap">
            <a href="{{ route('coach.availability.index') }}" class="px-4 py-2 rounded-xl text-sm font-bold bg-white text-[#1D1D1F] hover:bg-[#F2F2F7] border border-[#E5E5EA] transition-all flex items-center gap-2">
                <span>Manage Availability</span>
            </a>
            <a href="{{ route('coach.requests.index') }}" class="px-4 py-2 rounded-xl text-sm font-bold bg-[#780000] hover:bg-[#5E0000] text-white transition-all flex items-center gap-2">
                <span>Open Camp Slots ({{ $activeOpeningsCount }})</span>
            </a>
        </div>
    </div>

    <!-- Coach Summary KPIs (Icons Removed, Clean Minimal Numbers) -->
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4">
        
        <!-- Assigned Dives -->
        <div class="bg-white rounded-2xl p-4 sm:p-5 border border-[#E5E5EA]">
            <span class="text-sm font-bold text-[#6E6E73] uppercase tracking-wider block">Assigned Dives</span>
            <div class="text-2xl sm:text-3xl font-extrabold text-[#1D1D1F] mt-1">{{ $upcomingConfirmedDivesCount }}</div>
        </div>

        <!-- Available Dates Offered -->
        <div class="bg-white rounded-2xl p-4 sm:p-5 border border-[#E5E5EA]">
            <span class="text-sm font-bold text-[#6E6E73] uppercase tracking-wider block">Available Dates</span>
            <div class="text-2xl sm:text-3xl font-extrabold text-emerald-700 mt-1">{{ $availableDaysCount }}</div>
        </div>

        <!-- Open Camp Slots -->
        <div class="bg-white rounded-2xl p-4 sm:p-5 border border-[#E5E5EA]">
            <span class="text-sm font-bold text-[#6E6E73] uppercase tracking-wider block">Open Camp Slots</span>
            <div class="text-2xl sm:text-3xl font-extrabold text-amber-700 mt-1">{{ $activeOpeningsCount }}</div>
        </div>

        <!-- Total Divers Mentored -->
        <div class="bg-white rounded-2xl p-4 sm:p-5 border border-[#E5E5EA]">
            <span class="text-sm font-bold text-[#6E6E73] uppercase tracking-wider block">Students Coached</span>
            <div class="text-2xl sm:text-3xl font-extrabold text-[#780000] mt-1">{{ $totalStudentsMentored }}</div>
        </div>

    </div>

    <!-- PRIMARY OPERATIONAL SECTION: Left Column (Batch + Open Slots) & Right Column (Student Roster) -->
    @if($nextSessionData)
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">
            
            <!-- LEFT COLUMN (col-span-4): Upcoming Dive Batch Card + Open Camp Volunteer Slots Below It -->
            <div class="lg:col-span-4 space-y-6">
                
                <!-- Upcoming Dive Batch Card -->
                <div class="bg-white rounded-2xl border border-[#E5E5EA] p-5 sm:p-6 flex flex-col justify-between space-y-5">
                    <div class="space-y-3.5">
                        <!-- Weather Safety Badge & Description -->
                        <div class="flex items-center gap-2.5 flex-wrap">
                            @php
                                $wClass = strtolower(trim($nextSessionData['weather_class'] ?? 'Safe'));
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
                            <span class="px-2.5 py-1 rounded-lg text-sm font-black {{ $wBg }} inline-flex items-center shadow-2xs shrink-0">
                                <span>{{ $nextSessionData['weather_class'] }}</span>
                            </span>
                            <span class="text-sm text-[#6E6E73] font-medium leading-tight">
                                {{ \App\Services\WeatherForecastService::MEANING_MAP[$nextSessionData['weather_class']] ?? 'Standard marine safety protocols in effect.' }}
                            </span>
                        </div>

                        <!-- Batch Title -->
                        <div>
                            <span class="text-sm uppercase font-bold text-[#8E8E93] tracking-wider block">Upcoming Dive Batch</span>
                            <h2 class="text-2xl font-black text-[#1D1D1F] tracking-tight mt-0.5">
                                {{ $nextSessionData['batch']->batch_number }}
                            </h2>
                        </div>

                        <!-- Dive Dates & Students Line -->
                        <div class="space-y-1 text-sm text-[#6E6E73] pt-1">
                            <p>
                                Dive Dates: <strong class="text-[#1D1D1F]">{{ $nextSessionData['batch']->formatted_date_range }} ({{ $nextSessionData['batch']->start_date->format('D') }} - {{ $nextSessionData['batch']->end_date->format('D') }})</strong>
                            </p>
                            <p>
                                Assigned Students: <strong class="text-[#1D1D1F]">{{ $nextSessionData['students_count'] }} Student(s)</strong>
                                @if(!empty($nextSessionData['class_breakdown']))
                                    <span class="text-[#8E8E93] mx-1">·</span>
                                    <span class="text-[#1D1D1F] font-semibold">
                                        @php
                                            $dashBreakdownStrs = [];
                                            foreach ($nextSessionData['class_breakdown'] as $cType => $cnt) {
                                                $dashBreakdownStrs[] = "{$cnt} {$cType}";
                                            }
                                        @endphp
                                        {{ implode(', ', $dashBreakdownStrs) }}
                                    </span>
                                @endif
                            </p>
                        </div>
                    </div>

                    <!-- Action Area: Primary & Secondary (No Separator Line) -->
                    <div class="pt-3 flex flex-col sm:flex-row lg:flex-col xl:flex-row items-stretch gap-2.5">
                        <!-- Primary: Request Release -->
                        @if($nextSessionData['can_request_release'])
                            <button type="button" 
                                    @click="openReleaseModal = true"
                                    class="flex-1 px-4 py-2.5 rounded-xl bg-rose-50 text-rose-700 hover:bg-rose-100 text-sm font-bold transition-all flex items-center justify-center gap-1.5">
                                <span>Request Release</span>
                            </button>
                        @else
                            <span class="flex-1 px-3.5 py-2.5 rounded-xl text-sm font-bold bg-[#F2F2F7] text-[#8E8E93] text-center" title="Release requests are locked within 48 hours of dive start">
                                Locked (&lt;48h to dive)
                            </span>
                        @endif

                        <!-- Secondary: View Full Schedule -->
                        <a href="{{ route('coach.schedule.index') }}" class="flex-1 px-4 py-2.5 rounded-xl bg-white hover:bg-[#F2F2F7] text-[#1D1D1F] border border-[#E5E5EA] text-sm font-bold transition-all inline-flex items-center justify-center gap-1.5">
                            <span>Full Schedule</span>
                        </a>
                    </div>
                </div>

                <!-- OPEN CAMP VOLUNTEER SLOTS (Placed below the batch card on the left side) -->
                <div class="bg-white rounded-2xl border border-[#E5E5EA] p-5 sm:p-6 flex flex-col justify-between space-y-4">
                    <div class="space-y-4">
                        <!-- Header with Subtitle and View Board Button (No separator line) -->
                        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 pb-1">
                            <div>
                                <h3 class="text-base font-black text-[#1D1D1F]">Open Camp Volunteer Slots</h3>
                                <p class="text-sm text-[#6E6E73]">Batches seeking extra coach support</p>
                            </div>
                            <a href="{{ route('coach.requests.index') }}" class="px-3 py-1.5 rounded-xl bg-white hover:bg-[#F2F2F7] text-[#1D1D1F] border border-[#E5E5EA] text-sm font-bold transition-all shadow-2xs inline-flex items-center gap-1.5 self-start sm:self-auto">
                                <span>Board · {{ $activeOpeningsCount }}</span>
                                <svg class="w-3.5 h-3.5 text-[#6E6E73]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M7 17L17 7M17 7H7M17 7V17"/></svg>
                            </a>
                        </div>

                        <!-- Clean Horizontal Openings Cards -->
                        <div class="space-y-3">
                            @forelse($openCoachOpenings as $opening)
                                @php
                                    $myRequest = $opening->requests->first();
                                    $hasApplied = $myRequest !== null;
                                    $reqStatus = $myRequest?->status;
                                    $b = $opening->batch;
                                    $datesDisplay = ($b && $b->start_date && $b->end_date && $b->start_date->ne($b->end_date))
                                        ? $b->formatted_date_range . ' (' . $b->start_date->format('D') . ' - ' . $b->end_date->format('D') . ')'
                                        : $opening->dive_date->format('M d, Y · l');
                                @endphp
                                <div class="p-4 rounded-2xl bg-[#F2F2F7] flex flex-col sm:flex-row sm:items-center justify-between gap-3 transition-colors shadow-2xs">
                                    <div class="space-y-1">
                                        <div class="flex items-center gap-2 flex-wrap">
                                            <span class="font-black text-sm text-[#1D1D1F]">{{ $opening->batch?->batch_number ?? 'Batch' }}</span>
                                            <span class="px-2.5 py-0.5 rounded-lg text-sm font-black bg-amber-100 text-amber-900">
                                                NEEDS SUPPORT
                                            </span>
                                        </div>
                                        <p class="text-sm text-[#1D1D1F] font-semibold">
                                            Needs {{ $opening->needed_students_count }} student slots
                                        </p>
                                        <p class="text-sm text-[#6E6E73]">
                                            Dive dates: <strong class="text-[#1D1D1F]">{{ $datesDisplay }}</strong>
                                        </p>
                                    </div>

                                    <div class="shrink-0 pt-2 sm:pt-0">
                                        @if($hasApplied)
                                            @if($reqStatus === 'approved')
                                                <span class="px-3 py-1.5 rounded-xl text-sm font-black bg-emerald-100 text-emerald-900 inline-block">
                                                    Accepted
                                                </span>
                                            @elseif($reqStatus === 'not_selected')
                                                <span class="px-3 py-1.5 rounded-xl text-sm font-black bg-gray-100 text-gray-700 inline-block">
                                                    Filled
                                                </span>
                                            @else
                                                <span class="px-3 py-1.5 rounded-xl text-sm font-black bg-blue-100 text-blue-900 inline-block">
                                                    Applied (Pending)
                                                </span>
                                            @endif
                                        @else
                                            <button type="button" 
                                                    @click="openVolunteerModal({{ json_encode($opening) }}, '{{ addslashes($datesDisplay) }}')"
                                                    class="btn-primary min-h-[44px] px-4 py-2 text-sm font-bold rounded-xl cursor-pointer transition-all active:scale-[0.99] focus:outline-none focus-visible:ring-2 focus-visible:ring-[#780000]">
                                                Volunteer
                                            </button>
                                        @endif
                                    </div>
                                </div>
                            @empty
                                <div class="py-6 text-center text-sm text-[#6E6E73] bg-[#F2F2F7] rounded-2xl">
                                    No open broadcast volunteer slots right now.
                                </div>
                            @endforelse
                        </div>
                    </div>
                </div>

            </div>

            <!-- RIGHT: Assigned Student Roster in Card Format (col-span-8, Unboxed Container) -->
            <div class="lg:col-span-8 bg-transparent border-0 p-0 shadow-none space-y-3.5">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 pb-1">
                    <div>
                        <h3 class="text-base sm:text-lg font-black text-[#1D1D1F]">Assigned Student Roster</h3>
                        <p class="text-sm text-[#6E6E73]">Review student swimming abilities prior to boat departure</p>
                    </div>
                    <span class="text-sm font-extrabold text-[#1D1D1F] bg-[#F2F2F7] px-3 py-1 rounded-xl self-start sm:self-auto shadow-2xs">
                        {{ $nextSessionData['students_count'] }} Student(s) Assigned
                    </span>
                </div>

                <!-- Student Cards Grid (Brand-Aligned Card Format) -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 sm:gap-5">
                    @foreach($nextSessionData['students'] as $student)
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
                        
                        <!-- Individual Student Card (Mobile-Optimized Padding & Clean Spacing) -->
                        <div class="bg-white rounded-2xl border border-[#E5E5EA] p-4 sm:p-5 space-y-4 flex flex-col justify-between">
                            
                            <!-- Card Header: Student Name & Compact Package Badge (No border) -->
                            <div class="flex items-start justify-between gap-2.5">
                                <div class="min-w-0 flex-1">
                                    <span class="text-sm uppercase font-bold text-[#8E8E93] tracking-wider block">Student</span>
                                    <h4 class="font-black text-[#1D1D1F] text-base sm:text-lg tracking-tight mt-0.5 truncate">{{ $student->name }}</h4>
                                </div>
                                <span class="px-2.5 py-1 rounded-lg text-sm font-black bg-[#F8EAEA] text-[#780000] shrink-0">
                                    {{ $shortClassType }}
                                </span>
                            </div>

                            <!-- Information Rows (Semantic Row Grouping prevents vertical height collisions) -->
                            <div class="space-y-3.5 text-sm">
                                
                                <!-- Row 1: Age & Package -->
                                <div class="grid grid-cols-2 gap-3 sm:gap-4 items-start">
                                    <div>
                                        <span class="text-sm font-bold text-[#8E8E93] uppercase tracking-wider block mb-0.5">Age</span>
                                        <span class="font-extrabold text-sm sm:text-base text-[#1D1D1F] block">{{ $student->age }} yrs</span>
                                    </div>
                                    <div>
                                        <span class="text-sm font-bold text-[#8E8E93] uppercase tracking-wider block mb-0.5">Package</span>
                                        <span class="font-extrabold text-sm sm:text-sm text-[#1D1D1F] block leading-snug">{{ $classType }}</span>
                                    </div>
                                </div>

                                <!-- Row 2: Swimming Ability & Health/Medical -->
                                <div class="grid grid-cols-2 gap-3 sm:gap-4 items-start">
                                    <div>
                                        <span class="text-sm font-bold text-[#8E8E93] uppercase tracking-wider block mb-1">Swimming Ability</span>
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
                                        <span class="text-sm font-bold text-[#8E8E93] uppercase tracking-wider block mb-1">Health / Medical</span>
                                        @if($hasMedical)
                                            <span class="font-bold text-sm text-amber-900 inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-amber-100 shadow-2xs">
                                                <svg class="w-3.5 h-3.5 text-amber-800 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                                                <span class="truncate">{{ $student->health_condition }}</span>
                                            </span>
                                        @else
                                            <span class="text-sm sm:text-sm text-[#8E8E93] font-bold block pt-1">None declared</span>
                                        @endif
                                    </div>
                                </div>

                                <!-- Row 3: Lead Booker & Contact -->
                                <div class="grid grid-cols-2 gap-3 sm:gap-4 items-start">
                                    <div>
                                        <span class="text-sm font-bold text-[#8E8E93] uppercase tracking-wider block mb-0.5">Lead Booker</span>
                                        <span class="font-extrabold text-sm sm:text-sm text-[#1D1D1F] truncate block">{{ $student->booking?->contact_name ?? $student->name }}</span>
                                    </div>
                                    <div>
                                        <span class="text-sm font-bold text-[#8E8E93] uppercase tracking-wider block mb-0.5">Contact</span>
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
    @else
        <!-- Empty State: No Immediate Dives -->
        <div class="space-y-6">
            <div class="bg-white rounded-2xl border border-[#E5E5EA] p-8 text-center space-y-3">
                <div>
                    <h3 class="text-base font-extrabold text-[#1D1D1F]">No Immediate Dive Assignments</h3>
                    <p class="text-sm text-[#6E6E73] mt-1 max-w-md mx-auto">
                        You currently have no scheduled batches for this weekend. Keep your availability calendar updated to get matched by camp coordinators.
                    </p>
                </div>
                <div class="pt-2">
                    <a href="{{ route('coach.availability.index') }}" class="px-4 py-2.5 rounded-xl bg-[#780000] hover:bg-[#5E0000] text-white text-sm font-bold transition-all inline-flex items-center gap-2.5 shadow-2xs">
                        <img src="{{ asset('icons/icons8-calendar-60.png') }}" class="w-5 h-5 shrink-0 brightness-0 invert" alt="" aria-hidden="true">
                        <span>Update Availability Calendar</span>
                    </a>
                </div>
            </div>

            <!-- Open Camp Volunteer Slots below empty state -->
            @if($openCoachOpenings->isNotEmpty())
                <div class="bg-white rounded-2xl border border-[#E5E5EA] p-5 sm:p-6 space-y-4">
                    <div class="flex items-center justify-between pb-1">
                        <div>
                            <h3 class="text-base font-black text-[#1D1D1F]">Open Camp Volunteer Slots</h3>
                            <p class="text-sm text-[#6E6E73]">Batches seeking extra coach support</p>
                        </div>
                        <a href="{{ route('coach.requests.index') }}" class="px-3 py-1.5 rounded-xl bg-white hover:bg-[#F2F2F7] text-[#1D1D1F] border border-[#E5E5EA] text-sm font-bold transition-all shadow-2xs inline-flex items-center gap-1.5">
                            <span>Board · {{ $activeOpeningsCount }}</span>
                            <svg class="w-3.5 h-3.5 text-[#6E6E73]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M7 17L17 7M17 7H7M17 7V17"/></svg>
                        </a>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        @foreach($openCoachOpenings as $opening)
                            @php 
                                $myRequest = $opening->requests->first();
                                $hasApplied = $myRequest !== null;
                                $reqStatus = $myRequest?->status;
                                $b = $opening->batch;
                                $datesDisplay = ($b && $b->start_date && $b->end_date && $b->start_date->ne($b->end_date))
                                    ? $b->start_date->format('M d') . ' - ' . $b->end_date->format('d, Y') . ' (' . $b->start_date->format('D') . ' - ' . $b->end_date->format('D') . ')'
                                    : $opening->dive_date->format('M d, Y · l');
                            @endphp
                            <div class="p-4 rounded-2xl bg-[#F2F2F7] flex flex-col sm:flex-row sm:items-center justify-between gap-3 transition-colors shadow-2xs">
                                <div class="space-y-1">
                                    <div class="flex items-center gap-2">
                                        <span class="font-black text-sm text-[#1D1D1F]">{{ $opening->batch?->batch_number ?? 'Batch' }}</span>
                                        <span class="px-2.5 py-0.5 rounded-lg text-sm font-black bg-amber-100 text-amber-900">NEEDS SUPPORT</span>
                                    </div>
                                    <p class="text-sm text-[#1D1D1F] font-semibold">Needs {{ $opening->needed_students_count }} student slots</p>
                                    <p class="text-sm text-[#6E6E73]">Dive dates: <strong class="text-[#1D1D1F]">{{ $datesDisplay }}</strong></p>
                                </div>
                                <div class="shrink-0">
                                    @if($hasApplied)
                                        @if($reqStatus === 'approved')
                                            <span class="px-3 py-1.5 rounded-xl text-sm font-black bg-emerald-100 text-emerald-900">Accepted</span>
                                        @elseif($reqStatus === 'not_selected')
                                            <span class="px-3 py-1.5 rounded-xl text-sm font-black bg-gray-100 text-gray-700">Filled</span>
                                        @else
                                            <span class="px-3 py-1.5 rounded-xl text-sm font-black bg-blue-100 text-blue-900">Applied (Pending)</span>
                                        @endif
                                    @else
                                        <button type="button" 
                                                @click="openVolunteerModal({{ json_encode($opening) }}, '{{ addslashes($datesDisplay) }}')"
                                                class="btn-primary min-h-[44px] px-4 py-2 text-sm font-bold rounded-xl cursor-pointer transition-all active:scale-[0.99] focus:outline-none focus-visible:ring-2 focus-visible:ring-[#780000]">
                                            Volunteer
                                        </button>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
    @endif

    <!-- FULL WIDTH SECTION: Upcoming Availability Snapshot (One Horizontal Line · Whole Card Clickable) -->
    <div class="bg-white rounded-2xl border border-[#E5E5EA] p-5 sm:p-6 flex flex-col justify-between space-y-4">
        <div class="space-y-4">
            <!-- Header with Title, Subtitle, and Full Month View Button (No separator line) -->
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 pb-1">
                <div>
                    <h3 class="text-base font-black text-[#1D1D1F]">Upcoming Availability Snapshot</h3>
                    <p class="text-sm text-[#6E6E73]">Click any date card to toggle your availability</p>
                </div>
                <a href="{{ route('coach.availability.index') }}" class="px-3 py-1.5 rounded-xl bg-white hover:bg-[#F2F2F7] text-[#1D1D1F] border border-[#E5E5EA] text-sm font-bold transition-all shadow-2xs inline-flex items-center gap-1.5 self-start sm:self-auto">
                    <span>Full Month View</span>
                </a>
            </div>

            <!-- Single Horizontal Line of 7 Dates (Responsive Scrollable on Mobile, Grid on Tablet/Desktop) -->
            <div class="overflow-x-auto pb-2 scrollbar-none -mx-2 px-2 sm:mx-0 sm:px-0">
                <div class="grid grid-cols-7 gap-2 sm:gap-3 min-w-[580px] sm:min-w-0">
                    @foreach($quickDays as $day)
                        @if($day['status'] === 'assigned')
                            @php
                                $assignedDayNum = $day['assigned_day_number'] ?? 1;
                                $isDay1 = ($assignedDayNum === 1);
                                $bgClass = $isDay1 ? 'bg-[#780000] text-white' : 'bg-[#00C3D0] text-[#0A3538]';
                                $subtextColor = $isDay1 ? 'text-white/80' : 'text-[#0A3538]/80';
                                $labelColor = $isDay1 ? 'text-white/95' : 'text-[#0A3538]';
                                $assignedLabel = $isDay1 ? 'Day 1 Assigned' : 'Day 2 Assigned';
                            @endphp
                            <!-- Assigned: #780000 for Day 1 or #00C3D0 with dark slate text for Day 2 (6.3:1 contrast) -->
                            <div class="w-full rounded-2xl p-3 sm:p-4 text-center space-y-1.5 {{ $bgClass }} select-none flex flex-col justify-between" title="{{ $assignedLabel }} (Locked)">
                                <div>
                                    <span class="text-sm sm:text-sm font-black uppercase tracking-wider block {{ $subtextColor }} whitespace-nowrap">
                                        {{ $day['date']->format('D') }}
                                    </span>
                                    <span class="text-sm sm:text-lg font-black block mt-0.5 whitespace-nowrap">
                                        {{ $day['date']->format('M j') }}
                                    </span>
                                </div>
                                <span class="text-sm sm:text-sm font-extrabold uppercase tracking-wider block {{ $labelColor }} pt-0.5 whitespace-nowrap">
                                    {{ $assignedLabel }}
                                </span>
                            </div>
                        @elseif($day['status'] === 'available')
                            <!-- Available: Green Background with White Text (Whole Card is Button) -->
                            <form action="{{ route('coach.availability.toggle') }}" method="POST" @submit="isTogglingDate = true; togglingDateStr = '{{ $day['date_str'] }}'" class="w-full relative">
                                @csrf
                                <input type="hidden" name="date" value="{{ $day['date_str'] }}">
                                <button type="submit" 
                                        :disabled="isTogglingDate"
                                        class="w-full rounded-2xl p-3 sm:p-4 text-center space-y-1.5 bg-emerald-600 hover:bg-emerald-700 text-white transition-all cursor-pointer flex flex-col justify-between group relative disabled:opacity-60 disabled:cursor-not-allowed" 
                                        title="Click to remove availability">
                                    <div>
                                        <span class="text-sm sm:text-sm font-black uppercase tracking-wider block text-emerald-100 group-hover:text-white whitespace-nowrap">
                                            {{ $day['date']->format('D') }}
                                        </span>
                                        <span class="text-sm sm:text-lg font-black block text-white mt-0.5 whitespace-nowrap">
                                            {{ $day['date']->format('M j') }}
                                        </span>
                                    </div>
                                    <span class="text-sm sm:text-sm font-extrabold uppercase tracking-wider block text-emerald-100 group-hover:text-white pt-0.5 whitespace-nowrap">
                                        Available
                                    </span>
                                    <!-- Inline Loading Spinner Overlay -->
                                    <div x-show="isTogglingDate && togglingDateStr === '{{ $day['date_str'] }}'" 
                                         x-cloak 
                                         class="absolute inset-0 bg-emerald-800/80 rounded-2xl flex items-center justify-center backdrop-blur-2xs z-10">
                                        <svg class="animate-spin w-5 h-5 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3">
                                            <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-opacity="0.25"></circle>
                                            <path d="M4 12a8 8 0 018-8" stroke="currentColor"></path>
                                        </svg>
                                    </div>
                                </button>
                            </form>
                        @else
                            <!-- Not Set: No Background Color (Whole Card is Button) -->
                            <form action="{{ route('coach.availability.toggle') }}" method="POST" @submit="isTogglingDate = true; togglingDateStr = '{{ $day['date_str'] }}'" class="w-full relative">
                                @csrf
                                <input type="hidden" name="date" value="{{ $day['date_str'] }}">
                                <button type="submit" 
                                        :disabled="isTogglingDate"
                                        class="w-full rounded-2xl p-3 sm:p-4 text-center space-y-1.5 bg-white hover:bg-[#F2F2F7] text-[#1D1D1F] border border-[#E5E5EA] hover:border-[#780000] transition-all cursor-pointer flex flex-col justify-between group relative disabled:opacity-60 disabled:cursor-not-allowed" 
                                        title="Click to mark as available">
                                    <div>
                                        <span class="text-sm sm:text-sm font-black uppercase tracking-wider block text-[#8E8E93] group-hover:text-[#780000] whitespace-nowrap">
                                            {{ $day['date']->format('D') }}
                                        </span>
                                        <span class="text-sm sm:text-lg font-black block text-[#1D1D1F] mt-0.5 whitespace-nowrap">
                                            {{ $day['date']->format('M j') }}
                                        </span>
                                    </div>
                                    <span class="text-sm sm:text-sm font-bold uppercase tracking-wider block text-[#8E8E93] group-hover:text-[#780000] pt-0.5 whitespace-nowrap">
                                        Not Set
                                    </span>
                                    <!-- Inline Loading Spinner Overlay -->
                                    <div x-show="isTogglingDate && togglingDateStr === '{{ $day['date_str'] }}'" 
                                         x-cloak 
                                         class="absolute inset-0 bg-white/85 rounded-2xl flex items-center justify-center backdrop-blur-2xs z-10">
                                        <svg class="animate-spin w-5 h-5 text-[#780000]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3">
                                            <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-opacity="0.25"></circle>
                                            <path d="M4 12a8 8 0 018-8" stroke="currentColor"></path>
                                        </svg>
                                    </div>
                                </button>
                            </form>
                        @endif
                    @endforeach
                </div>
            </div>
        </div>
    </div>

    <!-- Release Request Modal -->
    @if($nextSessionData && $nextSessionData['can_request_release'])
    <div x-show="openReleaseModal" 
         x-cloak 
         role="dialog" 
         aria-modal="true" 
         aria-labelledby="release-modal-title"
         @keydown.escape.window="openReleaseModal = false"
         class="fixed inset-0 z-50 overflow-y-auto bg-black/50 backdrop-blur-sm flex items-center justify-center p-3 sm:p-4"
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-150"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0">
        <div class="bg-white rounded-2xl max-w-lg w-full p-5 sm:p-6 space-y-4 shadow-2xl border border-[#E5E5EA]" 
             @click.outside="openReleaseModal = false"
             x-transition:enter="transition ease-out duration-200"
             x-transition:enter-start="opacity-0 scale-95"
             x-transition:enter-end="opacity-100 scale-100"
             x-transition:leave="transition ease-in duration-150"
             x-transition:leave-start="opacity-100 scale-100"
             x-transition:leave-end="opacity-0 scale-95">
            <div class="flex items-start justify-between gap-3">
                <div class="space-y-1">
                    <h3 id="release-modal-title" class="text-base sm:text-lg font-extrabold text-[#1D1D1F]">Request Release from Assignment</h3>
                    <p class="text-sm text-[#6E6E73]">
                        <span class="font-bold text-[#1D1D1F]">{{ $nextSessionData['batch']->batch_number }}</span> &bull; 
                        <span>{{ $nextSessionData['dive_date']->format('M d, Y') }}</span>
                    </p>
                </div>
                <button type="button" 
                        @click="openReleaseModal = false" 
                        aria-label="Close release modal" 
                        class="w-11 h-11 min-h-[44px] min-w-[44px] -mr-2 rounded-full flex items-center justify-center text-[#6E6E73] hover:text-[#1D1D1F] hover:bg-[#F2F2F7] transition-all cursor-pointer focus:outline-none focus-visible:ring-2 focus-visible:ring-[#780000]">
                    <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                        <path d="M18 6L6 18M6 6l12 12"/>
                    </svg>
                </button>
            </div>

            <form action="{{ route('coach.availability.release') }}" method="POST" @submit="submittingRelease = true" class="space-y-4 text-sm">
                @csrf
                <input type="hidden" name="batch_id" value="{{ $nextSessionData['batch']->id }}">
                <input type="hidden" name="dive_date" value="{{ $nextSessionData['dive_date']->format('Y-m-d') }}">

                <div>
                    <label for="dashboard-release-reason" class="block font-bold text-[#1D1D1F] mb-1.5">Reason for Release <span class="text-[#780000]">*</span></label>
                    <textarea id="dashboard-release-reason" 
                              name="reason" 
                              rows="3" 
                              required 
                              placeholder="e.g. Medical emergency, urgent personal conflict" 
                              class="w-full px-3.5 py-2.5 rounded-xl border border-[#D1D1D6] text-sm text-[#1D1D1F] bg-white focus:border-[#780000] focus:ring-2 focus:ring-[#780000]/20 focus:outline-none transition-all"></textarea>
                    <p class="text-xs text-[#6E6E73] mt-2">
                        Your request will be submitted to the camp coordinator for approval. A replacement coach will be assigned from the availability pool.
                    </p>
                </div>

                <div class="flex items-center justify-end gap-2.5 pt-2">
                    <button type="button" 
                            @click="openReleaseModal = false" 
                            class="btn-secondary min-h-[44px] px-4 py-2.5 text-sm font-semibold rounded-xl cursor-pointer transition-all active:scale-[0.99] focus:outline-none focus-visible:ring-2 focus-visible:ring-[#780000]">
                        Cancel
                    </button>
                    <button type="submit" 
                            :disabled="submittingRelease" 
                            class="btn-danger min-h-[44px] px-5 py-2.5 text-sm font-bold rounded-xl cursor-pointer transition-all active:scale-[0.99] focus:outline-none focus-visible:ring-2 focus-visible:ring-[#D70015] focus-visible:ring-offset-2 disabled:opacity-50 disabled:cursor-not-allowed flex items-center justify-center gap-2">
                        <span x-show="!submittingRelease">Submit Release Request</span>
                        <span x-show="submittingRelease" x-cloak class="inline-flex items-center gap-2">
                            <svg class="animate-spin h-4 w-4 text-white" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path></svg>
                            <span>Submitting...</span>
                        </span>
                    </button>
                </div>
            </form>
        </div>
    </div>
    @endif

    <!-- Volunteer Request Modal -->
    <div x-show="openApplyModal" 
         x-cloak 
         role="dialog" 
         aria-modal="true" 
         aria-labelledby="volunteer-modal-title"
         @keydown.escape.window="openApplyModal = false"
         class="fixed inset-0 z-50 overflow-y-auto bg-black/50 backdrop-blur-sm flex items-center justify-center p-3 sm:p-4"
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-150"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0">
        <div class="bg-white rounded-2xl max-w-lg w-full p-5 sm:p-6 space-y-4 shadow-2xl border border-[#E5E5EA]" 
             @click.outside="openApplyModal = false"
             x-transition:enter="transition ease-out duration-200"
             x-transition:enter-start="opacity-0 scale-95"
             x-transition:enter-end="opacity-100 scale-100"
             x-transition:leave="transition ease-in duration-150"
             x-transition:leave-start="opacity-100 scale-100"
             x-transition:leave-end="opacity-0 scale-95">
            <div class="flex items-start justify-between gap-3">
                <div class="space-y-1">
                    <h3 id="volunteer-modal-title" class="text-base sm:text-lg font-extrabold text-[#1D1D1F]">Volunteer for Camp Slot</h3>
                    <p class="text-sm text-[#6E6E73]">
                        <span class="font-bold text-[#1D1D1F]" x-text="selectedOpeningBatch"></span> &bull; 
                        <span x-text="selectedOpeningDate"></span>
                    </p>
                    <p class="text-xs font-semibold text-[#780000]" x-show="selectedOpeningNeeded">
                        <span x-text="selectedOpeningNeeded"></span> Diver(s) needing instructor
                    </p>
                </div>
                <button type="button" 
                        @click="openApplyModal = false" 
                        aria-label="Close volunteer modal" 
                        class="w-11 h-11 min-h-[44px] min-w-[44px] -mr-2 rounded-full flex items-center justify-center text-[#6E6E73] hover:text-[#1D1D1F] hover:bg-[#F2F2F7] transition-all cursor-pointer focus:outline-none focus-visible:ring-2 focus-visible:ring-[#780000]">
                    <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                        <path d="M18 6L6 18M6 6l12 12"/>
                    </svg>
                </button>
            </div>

            <form :action="'{{ url('/coach/open-slot-requests') }}/' + selectedOpeningId + '/apply'" method="POST" @submit="submittingApply = true" class="space-y-4 text-sm">
                @csrf

                <div>
                    <label for="volunteer-notes" class="block font-bold text-[#1D1D1F] mb-1.5">Optional Note to Camp Admin</label>
                    <textarea id="volunteer-notes" 
                              name="notes" 
                              rows="3" 
                              placeholder="e.g. I have gear ready and available for this entire weekend..." 
                              class="w-full px-3.5 py-2.5 rounded-xl border border-[#D1D1D6] text-sm text-[#1D1D1F] bg-white focus:border-[#780000] focus:ring-2 focus:ring-[#780000]/20 focus:outline-none transition-all"></textarea>
                    <p class="text-xs text-[#6E6E73] mt-2">
                        Submitting interest notifies Camp Admin. If selected, students will be automatically matched to your roster.
                    </p>
                </div>

                <div class="flex items-center justify-end gap-2.5 pt-2">
                    <button type="button" 
                            @click="openApplyModal = false" 
                            class="btn-secondary min-h-[44px] px-4 py-2.5 text-sm font-semibold rounded-xl cursor-pointer transition-all active:scale-[0.99] focus:outline-none focus-visible:ring-2 focus-visible:ring-[#780000]">
                        Cancel
                    </button>
                    <button type="submit" 
                            :disabled="submittingApply" 
                            class="btn-primary min-h-[44px] px-5 py-2.5 text-sm font-bold rounded-xl cursor-pointer transition-all active:scale-[0.99] focus:outline-none focus-visible:ring-2 focus-visible:ring-[#780000] focus-visible:ring-offset-2 disabled:opacity-50 disabled:cursor-not-allowed flex items-center justify-center gap-2">
                        <span x-show="!submittingApply">Submit Volunteer Request</span>
                        <span x-show="submittingApply" x-cloak class="inline-flex items-center gap-2">
                            <svg class="animate-spin h-4 w-4 text-white" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path></svg>
                            <span>Submitting...</span>
                        </span>
                    </button>
                </div>
            </form>
        </div>
    </div>

</div>
@endsection
