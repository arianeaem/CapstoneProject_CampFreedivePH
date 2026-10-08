@extends('layouts.admin')

@section('title', 'Availability Calendar | Coach Portal')

@section('content')
@php
    $selectableDates = array_values(array_filter(array_map(function($d) {
        return (!$d['is_past'] && !$d['is_assigned'] && $d['is_current_month']) ? $d['date_str'] : null;
    }, $calendarDays)));
@endphp
<div class="space-y-6" x-data="coachAvailabilityCalendar({{ json_encode($selectableDates) }})">
    
    <!-- Toast Notification Banner -->
    <div x-show="showToast" 
         x-cloak
         role="status"
         aria-live="polite"
         x-transition:enter="transition ease-out duration-250"
         x-transition:enter-start="opacity-0 -translate-y-4 scale-95"
         x-transition:enter-end="opacity-100 translate-y-0 scale-100"
         x-transition:leave="transition ease-in duration-150"
         x-transition:leave-start="opacity-100 translate-y-0 scale-100"
         x-transition:leave-end="opacity-0 -translate-y-4 scale-95"
         class="fixed top-5 right-4 sm:right-6 z-50 max-w-sm w-full shadow-2xl rounded-2xl p-4 flex items-start gap-3 border backdrop-blur-md transition-all"
         :class="toastType === 'error' 
             ? 'bg-rose-50/95 border-rose-200 text-rose-900 shadow-rose-950/10' 
             : 'bg-emerald-50/95 border-emerald-200 text-emerald-900 shadow-emerald-950/10'">
        
        <!-- Status Icon -->
        <div class="shrink-0 mt-0.5">
            <template x-if="toastType === 'error'">
                <svg class="w-5 h-5 text-rose-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                    <circle cx="12" cy="12" r="10"></circle>
                    <line x1="12" y1="8" x2="12" y2="12"></line>
                    <line x1="12" y1="16" x2="12.01" y2="16"></line>
                </svg>
            </template>
            <template x-if="toastType !== 'error'">
                <svg class="w-5 h-5 text-emerald-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                    <path d="M20 6L9 17l-5-5"></path>
                </svg>
            </template>
        </div>

        <div class="flex-1 min-w-0">
            <p class="text-sm font-bold leading-snug" x-text="toastMessage"></p>
        </div>

        <button type="button" 
                @click="showToast = false" 
                aria-label="Dismiss notification"
                class="shrink-0 -mr-1 -mt-1 w-8 h-8 rounded-full flex items-center justify-center text-gray-400 hover:text-gray-700 hover:bg-black/5 transition-colors cursor-pointer focus:outline-none focus-visible:ring-2 focus-visible:ring-[#780000]">
            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                <path d="M18 6L6 18M6 6l12 12"/>
            </svg>
        </button>
    </div>
    
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">
        
        <!-- Availability Calendar -->
        <div class="lg:col-span-8 xl:col-span-9 space-y-4">
            
            <div class="bg-white rounded-2xl border border-[#E5E5EA] overflow-hidden">
                
                <!-- Calendar Controls -->
                <!-- Calendar Controls -->
                <div class="p-3 sm:p-5 border-b border-[#E5E5EA] flex flex-col sm:flex-row sm:items-center justify-between gap-3 sm:gap-4 bg-white">
                    <!-- Month Navigator & Today Button Group -->
                    <div class="flex items-center gap-2 w-full sm:w-auto">
                        <div class="flex items-center justify-between bg-white rounded-xl border border-[#E5E5EA] p-0.5 sm:p-1 shadow-xs flex-1 sm:flex-initial min-w-0">
                            <a href="{{ route('coach.availability.index', ['year' => $prevMonth->year, 'month' => $prevMonth->month]) }}" 
                               class="w-10 h-10 sm:w-11 sm:h-11 min-h-[40px] sm:min-h-[44px] min-w-[40px] sm:min-w-[44px] rounded-lg hover:bg-[#F2F2F7] text-[#1D1D1F] flex items-center justify-center transition-colors shrink-0 focus:outline-none focus-visible:ring-2 focus-visible:ring-[#780000]" 
                               title="Previous Month"
                               aria-label="Previous Month">
                                <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><polyline points="15 18 9 12 15 6"></polyline></svg>
                            </a>
                            <span class="px-2 sm:px-4 text-xs sm:text-base font-extrabold text-[#1D1D1F] text-center select-none truncate sm:min-w-[140px]">
                                {{ $currentMonth->format('F Y') }}
                            </span>
                            <a href="{{ route('coach.availability.index', ['year' => $nextMonth->year, 'month' => $nextMonth->month]) }}" 
                               class="w-10 h-10 sm:w-11 sm:h-11 min-h-[40px] sm:min-h-[44px] min-w-[40px] sm:min-w-[44px] rounded-lg hover:bg-[#F2F2F7] text-[#1D1D1F] flex items-center justify-center transition-colors shrink-0 focus:outline-none focus-visible:ring-2 focus-visible:ring-[#780000]" 
                               title="Next Month"
                               aria-label="Next Month">
                                <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><polyline points="9 18 15 12 9 6"></polyline></svg>
                            </a>
                        </div>
                        <a href="{{ route('coach.availability.index') }}" 
                           class="min-h-[40px] sm:min-h-[44px] px-3 sm:px-4 py-2 sm:py-2.5 rounded-xl bg-white border border-[#E5E5EA] text-[#1D1D1F] hover:bg-[#F2F2F7] text-xs sm:text-sm font-bold inline-flex items-center justify-center shrink-0 transition-all shadow-xs active:scale-[0.98] focus:outline-none focus-visible:ring-2 focus-visible:ring-[#780000]">
                            Today
                        </a>
                    </div>

                    <!-- Bulk Edit Toggle -->
                    <button type="button" 
                            @click="toggleBulkMode()"
                            :class="bulkMode ? 'bg-[#780000] text-white border-[#780000]' : 'bg-white text-[#1D1D1F] border-[#E5E5EA] hover:bg-[#F2F2F7]'"
                            class="w-full sm:w-auto min-h-[44px] px-4 py-2.5 rounded-xl border text-sm font-bold transition-all flex items-center justify-center sm:justify-start gap-2 cursor-pointer shadow-xs active:scale-[0.98] focus:outline-none focus-visible:ring-2 focus-visible:ring-[#780000]">
                        <img src="{{ asset('icons/icons8-edit-60.png') }}" alt="Edit" class="w-5 h-5 object-contain inline-block shrink-0" :class="bulkMode ? 'brightness-0 invert' : ''">
                        <span x-text="bulkMode ? 'Exit Bulk Mode' : 'Bulk Edit Mode'"></span>
                    </button>
                </div>

                <!-- Calendar Grid Container with Responsive Horizontal Scroll for Mobile -->
                <div class="overflow-x-auto pb-2 scrollbar-none"
                     @touchstart="touchStartX = $event.touches[0].clientX; touchStartY = $event.touches[0].clientY; touchMoved = false"
                     @touchmove="if (Math.abs($event.touches[0].clientX - touchStartX) > 10 || Math.abs($event.touches[0].clientY - touchStartY) > 10) { touchMoved = true; }">
                    <div class="min-w-[620px] sm:min-w-0">
                        <!-- Day of Week Header -->
                        <div class="grid grid-cols-7 border-b border-[#E5E5EA] bg-white text-center text-sm font-bold text-[#8E8E93] py-3">
                            <span class="text-rose-600">Sun</span>
                            <span>Mon</span>
                            <span>Tue</span>
                            <span>Wed</span>
                            <span>Thu</span>
                            <span>Fri</span>
                            <span class="text-[#00C3D0]">Sat</span>
                        </div>

                        <!-- Calendar Days -->
                        <div class="grid grid-cols-7 gap-px bg-[#E5E5EA]">
                            @foreach($calendarDays as $day)
                                @php
                                    $isOtherMonth = !$day['is_current_month'];
                                    $isPast = $day['is_past'];
                                    $status = $day['status'];
                                    $isAssigned = $day['is_assigned'];
                                    $assignedDayNumber = $day['assigned_day_number'] ?? 1;
                                    $dateStr = $day['date_str'];
                                    $hasRelease = $day['has_release_request'];

                                    // Card background and text color
                                    if ($isAssigned) {
                                        if ($assignedDayNumber === 1) {
                                            $cellBg = 'bg-[#780000] text-white border-transparent';
                                            $numColor = 'text-white';
                                            $assignedTextColor = 'text-white';
                                            $assignedSubtextColor = 'text-white/90 font-semibold';
                                            $releaseLinkColor = 'text-white/90 hover:text-white';
                                            $statusTitle = 'Day 1 Assigned';
                                            $hoverEffect = 'hover:brightness-110';
                                        } else {
                                            $cellBg = 'bg-[#00C3D0] text-[#0A3538] border-transparent';
                                            $numColor = 'text-[#0A3538]';
                                            $assignedTextColor = 'text-[#0A3538]';
                                            $assignedSubtextColor = 'text-[#0A3538]/85 font-semibold';
                                            $releaseLinkColor = 'text-[#0A3538] hover:text-[#06282B] font-extrabold';
                                            $statusTitle = 'Day 2 Assigned';
                                            $hoverEffect = 'hover:brightness-95';
                                        }
                                    } elseif ($status === 'available') {
                                        $cellBg = 'bg-emerald-600 text-white border-transparent';
                                        $numColor = 'text-white';
                                        $statusTitle = 'Available';
                                        $hoverEffect = 'hover:bg-emerald-700';
                                    } else {
                                        $cellBg = 'bg-white text-[#1D1D1F]';
                                        $numColor = $isOtherMonth ? 'text-gray-400' : 'text-[#1D1D1F]';
                                        $statusTitle = '';
                                        $hoverEffect = 'hover:bg-[#F2F2F7]';
                                    }
                                @endphp

                                <div class="min-h-[100px] sm:min-h-[130px] p-1.5 sm:p-3.5 flex flex-col justify-between transition-all relative group select-none
                                            {{ $cellBg }}
                                            {{ $isOtherMonth ? 'opacity-30 pointer-events-none' : '' }}
                                            {{ $isPast ? 'opacity-55 cursor-not-allowed' : '' }}
                                            {{ $day['is_today'] ? 'ring-2 ring-inset ring-amber-400' : '' }}"
                                     :class="{
                                         'ring-4 ring-inset ring-amber-400 brightness-105': isSelectedInBulk('{{ $dateStr }}'),
                                         '{{ $hoverEffect }} cursor-pointer': !{{ $isPast ? 'true' : 'false' }} && !bulkMode,
                                         'cursor-pointer': bulkMode && !{{ $isPast ? 'true' : 'false' }} && !{{ $isAssigned ? 'true' : 'false' }}
                                     }"
                                     role="checkbox"
                                     :aria-checked="isSelectedInBulk('{{ $dateStr }}') ? 'true' : 'false'"
                                     :aria-label="'Select ' + '{{ $dateStr }}' + ' for bulk editing'"
                                     tabindex="{{ ($isPast || $isOtherMonth) ? '-1' : '0' }}"
                                     @keydown.enter.prevent="handleDayClick('{{ $dateStr }}', {{ $isAssigned ? 'true' : 'false' }}, {{ $isPast ? 'true' : 'false' }}, {{ json_encode($day) }})"
                                     @keydown.space.prevent="handleDayClick('{{ $dateStr }}', {{ $isAssigned ? 'true' : 'false' }}, {{ $isPast ? 'true' : 'false' }}, {{ json_encode($day) }})"
                                     @click="handleDayClick('{{ $dateStr }}', {{ $isAssigned ? 'true' : 'false' }}, {{ $isPast ? 'true' : 'false' }}, {{ json_encode($day) }})">
                                    
                                    <!-- Day Number & Header Indicators -->
                                    <div class="flex items-center justify-between gap-1 w-full min-w-0">
                                        <span class="font-black text-xs sm:text-base {{ $numColor }} shrink-0 leading-none">
                                            {{ $day['day_number'] }}
                                        </span>

                                        <div class="flex items-center gap-0.5 sm:gap-1 shrink-0">
                                            @if($day['is_today'])
                                                <span class="text-[9px] sm:text-[11px] font-black px-1 sm:px-1.5 py-0.5 rounded bg-amber-400 text-amber-950 uppercase tracking-tight leading-none whitespace-nowrap shrink-0">Today</span>
                                            @endif

                                            @if($hasRelease)
                                                <span class="text-[9px] sm:text-[11px] font-black px-1 sm:px-1.5 py-0.5 rounded bg-amber-400 text-amber-950 shadow-2xs whitespace-nowrap leading-none shrink-0" title="Release request pending">
                                                    Pending
                                                </span>
                                            @endif

                                            <!-- Bulk Mode Selection Indicator -->
                                            <template x-if="bulkMode && !{{ $isPast ? 'true' : 'false' }} && !{{ $isAssigned ? 'true' : 'false' }}">
                                                <div class="w-4 h-4 rounded border border-current flex items-center justify-center pointer-events-none transition-colors"
                                                     :class="isSelectedInBulk('{{ $dateStr }}') ? 'bg-amber-400 border-amber-400 text-black' : 'bg-transparent border-white/60 text-transparent'"
                                                     aria-hidden="true">
                                                    <svg x-show="isSelectedInBulk('{{ $dateStr }}')" class="w-3 h-3 text-black stroke-[3]" viewBox="0 0 24 24" fill="none" stroke="currentColor"><polyline points="20 6 9 17 4 12"></polyline></svg>
                                                </div>
                                            </template>
                                        </div>
                                    </div>

                                    <!-- Status text -->
                                    <div class="my-auto py-1">
                                        @if($isAssigned)
                                            <div class="space-y-0.5">
                                                <div class="font-black text-sm sm:text-sm {{ $assignedTextColor }} tracking-wide leading-tight whitespace-nowrap truncate">
                                                    {{ $statusTitle }}
                                                </div>
                                                <div class="text-sm sm:text-sm {{ $assignedSubtextColor }} truncate leading-tight whitespace-nowrap">
                                                    {{ $day['batch']?->batch_number ?? 'Dive Batch' }}
                                                    @if($day['students_count'] > 0)
                                                        ({{ $day['students_count'] }} pax)
                                                    @endif
                                                </div>
                                            </div>
                                        @elseif($status === 'available')
                                            <div class="font-black text-sm sm:text-sm text-white tracking-wide whitespace-nowrap">
                                                Available
                                            </div>
                                        @endif
                                    </div>

                                    <!-- Bottom: Action Links & Hover Hints -->
                                    <div class="text-sm text-right">
                                        @if($isAssigned && !$isPast)
                                            <button type="button" 
                                                    @click.stop="openReleaseModal({{ json_encode($day) }})"
                                                    class="text-sm font-bold {{ $releaseLinkColor }} underline transition-colors whitespace-nowrap">
                                                Release Request
                                            </button>
                                        @elseif(!$isPast && !$isOtherMonth)
                                            <span class="text-sm font-medium transition-opacity whitespace-nowrap {{ $status === 'available' ? 'text-emerald-100 opacity-0 group-hover:opacity-100' : 'text-[#8E8E93] opacity-0 group-hover:opacity-100' }}">
                                                {{ $status === 'available' ? 'Click to unselect' : 'Click to select' }}
                                            </span>
                                        @endif
                                    </div>

                                    <!-- Toggling Loading Spinner Overlay -->
                                    <div x-show="isToggling && (togglingDate === '{{ $dateStr }}' || get2D1NPair(togglingDate) === '{{ $dateStr }}')"
                                         x-cloak
                                         class="absolute inset-0 bg-black/40 rounded-lg flex items-center justify-center z-10">
                                        <svg class="animate-spin w-5 h-5 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3">
                                            <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-opacity="0.25"></circle>
                                            <path d="M4 12a8 8 0 018-8" stroke="currentColor"></path>
                                        </svg>
                                    </div>

                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>

            </div>
        </div>

        <!-- Availability Status & Legend -->
        <div class="lg:col-span-4 xl:col-span-3 space-y-6">
            
            <!-- Calendar Overview -->
            <div class="bg-white rounded-2xl p-5 sm:p-6 border border-[#E5E5EA] space-y-3">
                <h1 class="text-xl font-black text-[#1D1D1F]">My Availability Calendar</h1>
                <p class="text-sm text-[#6E6E73] leading-relaxed">
                    Click any date or weekend to select or unselect your availability. Assigned dates are locked and require an emergency release request.
                </p>
            </div>

            <!-- Status Legend -->
            <div class="bg-white rounded-2xl p-5 sm:p-6 border border-[#E5E5EA] space-y-4 text-sm">
                <span class="font-bold text-[#8E8E93] uppercase tracking-wider text-sm block">Status Legend</span>
                
                <div class="space-y-3">
                    <div class="flex items-center gap-2.5">
                        <span class="w-4 h-4 rounded-md bg-emerald-600 inline-block shrink-0 shadow-2xs"></span>
                        <span class="font-semibold text-[#1D1D1F]">Available</span>
                    </div>

                    <div class="flex items-center gap-2.5">
                        <span class="w-4 h-4 rounded-md bg-[#780000] inline-block shrink-0 shadow-2xs"></span>
                        <span class="font-semibold text-[#1D1D1F]">Assigned Color for Day 1</span>
                    </div>

                    <div class="flex items-center gap-2.5">
                        <span class="w-4 h-4 rounded-md bg-[#00C3D0] inline-block shrink-0 shadow-2xs"></span>
                        <span class="font-semibold text-[#1D1D1F]">Assigned Color for Day 2</span>
                    </div>

                    <div class="flex items-center gap-2.5">
                        <span class="w-4 h-4 rounded-md bg-amber-400 inline-block shrink-0 shadow-2xs"></span>
                        <span class="font-semibold text-[#1D1D1F]">Release Requested</span>
                    </div>
                </div>
            </div>

        </div>

    </div>

    <!-- Bulk Edit Action Bar -->
    <div x-show="bulkMode" 
         x-cloak
         x-transition:enter="transition ease-out duration-300"
         x-transition:enter-start="opacity-0 translate-y-10"
         x-transition:enter-end="opacity-100 translate-y-0"
         class="fixed bottom-4 sm:bottom-6 inset-x-3 sm:inset-x-4 max-w-2xl mx-auto z-40 bg-[#1D1D1F] text-white p-3.5 sm:p-4 rounded-2xl shadow-2xl border border-white/20 flex flex-col sm:flex-row items-center justify-between gap-3">
        <div class="flex items-center justify-between w-full sm:w-auto gap-3">
            <div>
                <div class="font-bold text-sm text-white flex items-center gap-1.5">
                    <span>Bulk Edit Mode Active</span>
                </div>
                <div class="text-sm text-gray-300">
                    <span x-text="selectedDates.length" class="font-black text-amber-300"></span> date(s) selected
                </div>
            </div>
        </div>

        <div class="flex items-center gap-2 w-full sm:w-auto">
            <button type="button" 
                    @click="applyBulk('available')"
                    :disabled="selectedDates.length === 0 || bulkSubmitting"
                    class="flex-1 sm:flex-none min-h-[44px] px-4 py-2.5 rounded-xl bg-emerald-600 hover:bg-emerald-500 disabled:opacity-40 text-white font-bold text-sm transition-all flex items-center justify-center gap-2 cursor-pointer active:scale-[0.98]">
                <span x-show="!bulkSubmitting">Mark Available</span>
                <span x-show="bulkSubmitting" class="flex items-center gap-1.5">
                    <svg class="animate-spin w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-opacity="0.25"></circle><path d="M4 12a8 8 0 018-8" stroke="currentColor"></path></svg>
                    <span>Saving...</span>
                </span>
            </button>
            <button type="button" 
                    @click="applyBulk('remove')"
                    :disabled="selectedDates.length === 0 || bulkSubmitting"
                    class="flex-1 sm:flex-none min-h-[44px] px-4 py-2.5 rounded-xl bg-rose-700 hover:bg-rose-600 disabled:opacity-40 text-white font-bold text-sm transition-all flex items-center justify-center gap-2 cursor-pointer active:scale-[0.98]">
                <span>Remove</span>
            </button>
            <button type="button" 
                    @click="clearBulkSelection()"
                    class="min-h-[44px] px-3.5 py-2.5 rounded-xl bg-[#2C2C2E] hover:bg-[#3A3A3C] text-white font-bold text-sm transition-all cursor-pointer border border-[#3A3A3C] inline-flex items-center justify-center active:scale-[0.98]">
                Clear
            </button>
        </div>
    </div>

    <!-- Emergency Release Request Modal -->
    <div x-show="releaseModalOpen" 
         x-cloak 
         role="dialog"
         aria-modal="true"
         aria-labelledby="availability-release-modal-title"
         @keydown.escape.window="releaseModalOpen = false"
         class="fixed inset-0 z-50 bg-black/40 backdrop-blur-sm flex justify-end"
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-150"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0">
        <div class="dive-side-panel h-full overflow-y-auto overscroll-contain bg-white sm:max-w-lg w-full p-5 sm:p-6 shadow-2xl border-l border-[#E5E5EA] space-y-6 relative" 
             @click.outside="releaseModalOpen = false"
             x-transition:enter="transition ease-out duration-200"
             x-transition:enter-start="opacity-0 translate-x-full"
             x-transition:enter-end="opacity-100 translate-x-0"
             x-transition:leave="transition ease-in duration-150"
             x-transition:leave-start="opacity-100 translate-x-0"
             x-transition:leave-end="opacity-0 translate-x-full">
            
            <div class="flex items-start justify-between border-b border-[#E5E5EA] pb-4">
                <div>
                    <h3 id="availability-release-modal-title" class="text-lg font-black text-[#1D1D1F]">Request Assignment Release</h3>
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

            <template x-if="selectedAssignedDay">
                <div class="space-y-4">
                    <!-- Session Details -->
                    <div class="p-4 rounded-xl bg-[#F2F2F7] space-y-2 text-sm shadow-2xs">
                        <div class="flex items-center justify-between">
                            <span class="font-bold text-[#1D1D1F]" x-text="selectedAssignedDay.batch?.batch_number || 'Dive Batch'"></span>
                            <span class="px-2 py-0.5 rounded bg-blue-100 text-blue-800 font-bold" x-text="selectedAssignedDay.students_count + ' Participant(s) Assigned'"></span>
                        </div>
                        <div><strong>Dive Date:</strong> <span x-text="selectedAssignedDay.date_str"></span></div>
                        <div x-text="'Hours until dive departure: ' + selectedAssignedDay.hours_until_dive + 'h'"></div>
                    </div>

                    <div class="banner banner-warning space-y-2">
                        <div class="font-bold">Emergency release requests are available at any time.</div>
                        <p class="leading-relaxed">
                            Submit the request as soon as possible so Camp Administration can arrange replacement staffing.
                        </p>
                    </div>

                    <!-- Emergency Release Form -->
                    <template x-if="selectedAssignedDay.can_request_release">
                        <form action="{{ route('coach.availability.release') }}" method="POST" @submit="submittingRelease = true" class="space-y-4">
                            @csrf
                            <input type="hidden" name="batch_id" :value="selectedAssignedDay.batch?.id">
                            <input type="hidden" name="dive_date" :value="selectedAssignedDay.date_str">

                            <div class="space-y-1.5">
                                <label for="emergency-release-reason" class="block text-sm font-bold text-[#1D1D1F]">
                                    Reason for Emergency Release <span class="text-rose-500">*</span>
                                </label>
                                <textarea id="emergency-release-reason"
                                          name="reason" 
                                          rows="4" 
                                          required 
                                          placeholder="Please explain the emergency, illness, or unavoidable circumstance requiring reassignment..."
                                          class="w-full text-sm rounded-xl border border-[#D1D1D6] focus:border-[#780000] focus:ring-[#780000] p-3"></textarea>
                                <span class="text-sm text-[#8E8E93]">Your request will be submitted to Camp Admin for review and participant reassignment.</span>
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
            </template>

        </div>
    </div>

</div>

@push('scripts')
<script>
function coachAvailabilityCalendar(selectableDates = []) {
    return {
        bulkMode: false,
        selectedDates: [],
        allSelectableDates: selectableDates,
        bulkSubmitting: false,
        submittingRelease: false,
        isToggling: false,
        togglingDate: null,
        releaseModalOpen: false,
        selectedAssignedDay: null,
        touchStartX: 0,
        touchStartY: 0,
        touchMoved: false,
        toastMessage: '',
        toastType: 'error',
        showToast: false,
        toastTimeout: null,

        notify(message, type = 'error') {
            this.toastMessage = message;
            this.toastType = type;
            this.showToast = true;
            if (this.toastTimeout) clearTimeout(this.toastTimeout);
            this.toastTimeout = setTimeout(() => {
                this.showToast = false;
            }, 4500);
        },

        toggleBulkMode() {
            this.bulkMode = !this.bulkMode;
            if (!this.bulkMode) {
                this.selectedDates = [];
            }
        },

        isSelectedInBulk(dateStr) {
            return this.selectedDates.includes(dateStr);
        },

        get2D1NPair(dateStr) {
            if (!dateStr) return '';
            const parts = dateStr.split('-');
            if (parts.length !== 3) return '';
            const y = parseInt(parts[0], 10);
            const m = parseInt(parts[1], 10) - 1;
            const d = parseInt(parts[2], 10);
            const dateObj = new Date(y, m, d);
            const dayOfWeek = dateObj.getDay(); // 0 = Sun, 6 = Sat
            
            let pairObj = new Date(y, m, d);
            if (dayOfWeek === 6) { // Saturday -> Sunday (+1)
                pairObj.setDate(pairObj.getDate() + 1);
            } else if (dayOfWeek === 0) { // Sunday -> Saturday (-1)
                pairObj.setDate(pairObj.getDate() - 1);
            } else { // Weekday -> Next day (+1)
                pairObj.setDate(pairObj.getDate() + 1);
            }
            
            const py = pairObj.getFullYear();
            const pm = String(pairObj.getMonth() + 1).padStart(2, '0');
            const pd = String(pairObj.getDate()).padStart(2, '0');
            return `${py}-${pm}-${pd}`;
        },

        handleDayClick(dateStr, isAssigned, isPast, dayData) {
            if (this.touchMoved) return; // Disregard tap if user was scrolling horizontally on mobile
            if (isPast) return;

            if (isAssigned) {
                this.openReleaseModal(dayData);
                return;
            }

            if (this.bulkMode) {
                const pairStr = this.get2D1NPair(dateStr);
                const pairDates = pairStr ? [dateStr, pairStr] : [dateStr];

                const alreadySelected = this.selectedDates.includes(dateStr);
                if (alreadySelected) {
                    this.selectedDates = this.selectedDates.filter(d => !pairDates.includes(d));
                } else {
                    pairDates.forEach(d => {
                        if (!this.selectedDates.includes(d)) {
                            this.selectedDates.push(d);
                        }
                    });
                }
                return;
            }

            // Normal single 2D1N toggle
            this.executeToggle(dateStr);
        },

        async executeToggle(dateStr) {
            if (this.isToggling) return;
            this.isToggling = true;
            this.togglingDate = dateStr;

            try {
                const res = await fetch("{{ route('coach.availability.toggle') }}", {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}'
                    },
                    body: JSON.stringify({ date: dateStr })
                });

                const data = await res.json();
                if (data.success) {
                    window.location.reload();
                } else {
                    this.notify(data.message || 'Failed to update availability.', 'error');
                    this.isToggling = false;
                    this.togglingDate = null;
                }
            } catch (err) {
                console.error(err);
                this.notify('Network connection error. Could not update availability.', 'error');
                this.isToggling = false;
                this.togglingDate = null;
            }
        },

        async applyBulk(status) {
            if (this.selectedDates.length === 0 || this.bulkSubmitting) return;
            this.bulkSubmitting = true;

            try {
                const res = await fetch("{{ route('coach.availability.bulk') }}", {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}'
                    },
                    body: JSON.stringify({
                        dates: this.selectedDates,
                        status: status
                    })
                });

                const data = await res.json();
                if (data.success) {
                    window.location.reload();
                } else {
                    this.notify(data.message || 'Failed to apply bulk update.', 'error');
                    this.bulkSubmitting = false;
                }
            } catch (err) {
                console.error(err);
                this.notify('Network connection error. Could not apply bulk update.', 'error');
                this.bulkSubmitting = false;
            }
        },

        clearBulkSelection() {
            this.selectedDates = [];
        },

        openReleaseModal(dayData) {
            this.selectedAssignedDay = dayData;
            this.submittingRelease = false;
            this.releaseModalOpen = true;
        }
    };
}
</script>
@endpush
@endsection
