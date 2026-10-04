@extends('layouts.admin')

@section('title', 'Batch Coach Assignment | Camp FreedivePH')

@section('breadcrumb')
    <a href="{{ portal_route('coaches.index') }}" class="text-[#6E6E73] hover:text-[#780000] font-medium transition-colors">Coaches &amp; Schedules</a>
    <svg class="w-3.5 h-3.5 text-[#8E8E93] shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m9 18 6-6-6-6"/></svg>
    <span class="font-bold text-[#1D1D1F]">Batch Coach Assignment</span>
@endsection

@section('content')
<div class="space-y-6 text-sm" x-data="{ 
    shareModalOpen: false, 
    shareBatchId: null, 
    shareBatchNumber: '', 
    shareActionUrl: '',
    unassignModalOpen: false,
    unassignBatchId: null,
    unassignCoachId: null,
    unassignCoachName: '',
    unassignBatchNumber: '',
    unassignActionUrl: ''
}">
    
    <!-- Top Header & Navigation -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl sm:text-3xl font-extrabold text-[#1D1D1F] tracking-tight">Batch Coach Assignment</h1>
        </div>

        <div class="flex items-center gap-2.5 flex-wrap">
            <a href="{{ route('admin.coaches.requests') }}" class="btn-secondary px-3.5 py-2 text-sm sm:text-sm font-semibold flex items-center gap-2">
                <span>Coach Requests</span>
                @if(isset($pendingRequestsCount) && $pendingRequestsCount > 0)
                    <span class="px-2 py-0.5 rounded-md text-sm font-bold bg-[#780000] text-white">
                        {{ $pendingRequestsCount }}
                    </span>
                @endif
            </a>

            <a href="{{ route('admin.coaches.index') }}" class="btn-secondary px-3.5 py-2 text-sm sm:text-sm font-semibold flex items-center gap-2">
                <span>Coach Roster</span>
            </a>
        </div>
    </div>

    <!-- Batch Groups Queue (2-Column Card Format) -->
    @if(isset($batchData) && count($batchData) > 0)
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 items-start">
            @foreach($batchData as $group)
            @php
                $batch = $group['batch'];
                $totalPax = $group['total_participants'];
                $neededCoaches = $group['needed_coaches'];
                $assignedCoaches = $group['assigned_coaches'];
                $assignedCount = $group['assigned_count'];
                $availableCoaches = $group['available_coaches'];
                $isStaffed = $assignedCount >= $neededCoaches;
                $mlRec = $group['ml_recommendation'] ?? null;
            @endphp
            <div class="bg-white rounded-xl border border-[#E5E5EA] p-5 sm:p-6 space-y-4 shadow-2xs flex flex-col justify-between" x-data="{ isOpen: false, selectedCoaches: [] }">
                
                <!-- Group Header -->
                <div class="space-y-3" :class="isOpen ? 'border-b border-[#E5E5EA] pb-4' : ''">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <h2 class="font-extrabold text-base sm:text-lg text-[#1D1D1F]">
                                {{ $batch->batch_number }}
                            </h2>
                            <div class="space-y-0.5 text-sm text-[#6E6E73] mt-1.5">
                                <div>
                                    <span class="font-bold text-[#1D1D1F]">{{ $batch->formatted_date_range }}</span>
                                </div>
                                <div class="text-[#6E6E73] mt-0.5 space-y-0.5">
                                    <div>Participants: <strong class="text-[#1D1D1F]">{{ $totalPax }}</strong></div>
                                    <div>
                                        @if($totalPax > 0)
                                            Required: <strong class="text-[#1D1D1F]">{{ $neededCoaches }} {{ Str::plural('Coach', $neededCoaches) }} (1:{{ app(\App\Services\SystemSettingService::class)->get('camp_operations.coach_student_ratio', 4) }} ratio)</strong>
                                        @else
                                            Required: <span class="text-[#8E8E93]">No participants</span>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Right Column: Coaches to fill badge on top, Share to Coaches, and Toggle Button at the bottom -->
                        <div class="flex flex-col items-end gap-2 shrink-0">
                            <!-- Coaches to Fill Badge -->
                            <span class="px-3 py-0.5 rounded-full text-sm font-black bg-[#F8EAEA] text-[#780000] inline-flex items-center justify-center whitespace-nowrap shadow-2xs">
                                {{ $assignedCount }} / {{ $neededCoaches }} {{ Str::plural('Coach', $neededCoaches) }}
                            </span>

                            <!-- Broadcast Slot Button -->
                            @if($group['open_broadcast'])
                                <span class="px-2.5 py-1 rounded-lg text-sm font-bold bg-emerald-50 text-emerald-800 inline-flex items-center whitespace-nowrap">
                                    Shared
                                </span>
                            @else
                                <button type="button" 
                                        @click="shareBatchId = {{ $batch->id }}; shareBatchNumber = '{{ addslashes($batch->batch_number) }}'; shareActionUrl = '{{ auth()->user()->isOwner() ? route('owner.coaches.matching.broadcast') : route('admin.coaches.matching.broadcast') }}'; shareModalOpen = true"
                                        class="btn-secondary px-3 py-1.5 text-sm font-semibold whitespace-nowrap cursor-pointer">
                                    <span>Share to Coaches</span>
                                </button>
                            @endif

                            <!-- Toggle Coaches Button (placed at the bottom of Share to Coaches) -->
                            <button type="button" 
                                    @click="isOpen = !isOpen"
                                    class="btn-secondary px-3 py-1.5 text-sm font-semibold inline-flex items-center gap-1.5 cursor-pointer whitespace-nowrap">
                                <span x-text="isOpen ? 'Hide Coaches' : 'Assign Coaches'"></span>
                                <svg class="w-4 h-4 text-[#6E6E73] transition-transform duration-200" 
                                     :class="isOpen ? 'rotate-180' : ''" 
                                     viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                                    <polyline points="6 9 12 15 18 9"></polyline>
                                </svg>
                            </button>
                        </div>
                    </div>
                </div>

                @if($totalPax > 0)
                    <!-- Toggleable Assigned & Available Coaches Section -->
                    <div x-show="isOpen" x-cloak class="space-y-4 pt-1">
                        <!-- Assigned Coaches Section -->
                        <div class="space-y-2.5">
                            <div class="flex items-center justify-between">
                                <h3 class="text-sm font-bold text-[#1D1D1F] uppercase tracking-wider flex items-center gap-1.5">
                                    <span>Assigned Coaches</span>
                                    <span class="px-2 py-0.5 rounded-full text-sm font-extrabold bg-[#F2F2F7] text-[#1D1D1F]">
                                        {{ $assignedCount }}
                                    </span>
                                </h3>
                            </div>

                            @if($assignedCount > 0)
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                                    @foreach($assignedCoaches as $assignedCoach)
                                        <div class="p-2.5 rounded-xl bg-[#F2F2F7] flex items-center justify-between gap-2">
                                            <div class="flex items-center gap-2 min-w-0">
                                                <div class="w-6 h-6 rounded-full bg-[#F8EAEA] text-[#780000] font-bold text-sm flex items-center justify-center shrink-0">
                                                    {{ strtoupper(substr($assignedCoach->name, 0, 1)) }}
                                                </div>
                                                <span class="font-bold text-sm text-[#1D1D1F] truncate">{{ $assignedCoach->name }}</span>
                                            </div>

                                            <button type="button" 
                                                    @click="unassignBatchId = {{ $batch->id }}; unassignCoachId = {{ $assignedCoach->id }}; unassignCoachName = '{{ addslashes($assignedCoach->name) }}'; unassignBatchNumber = '{{ addslashes($batch->batch_number) }}'; unassignActionUrl = '{{ auth()->user()->isOwner() ? route('owner.coaches.matching.unassign') : route('admin.coaches.matching.unassign') }}'; unassignModalOpen = true"
                                                    class="text-sm font-bold text-rose-600 hover:text-rose-800 hover:bg-rose-50 px-1.5 py-0.5 rounded transition-colors shrink-0 cursor-pointer">
                                                Remove
                                            </button>
                                        </div>
                                    @endforeach
                                </div>
                            @else
                                <div class="p-3 rounded-xl text-center text-sm text-[#8E8E93] bg-[#F2F2F7] border border-dashed border-[#E5E5EA]">
                                    No coaches assigned yet. Select available coaches below.
                                </div>
                            @endif
                        </div>

                        <!-- Available Coaches Section -->
                        <div class="space-y-2.5 pt-2">
                            <div class="flex items-center justify-between flex-wrap gap-2">
                                <h3 class="text-sm font-bold text-[#1D1D1F] uppercase tracking-wider">
                                    Available Coaches
                                </h3>

                                <!-- Bulk Assign Action -->
                                <form action="{{ auth()->user()->isOwner() ? route('owner.coaches.matching.assign') : route('admin.coaches.matching.assign') }}" method="POST" x-show="selectedCoaches.length > 0" x-cloak>
                                    @csrf
                                    <input type="hidden" name="batch_id" value="{{ $batch->id }}">
                                    <template x-for="cId in selectedCoaches" :key="cId">
                                        <input type="hidden" name="coach_ids[]" :value="cId">
                                    </template>
                                    <button type="submit" class="btn-primary px-3 py-1 text-sm font-bold shadow-2xs flex items-center gap-1">
                                        <span>Assign Selected (<span x-text="selectedCoaches.length"></span>)</span>
                                    </button>
                                </form>
                            </div>

                            <!-- Available Coach Selection Grid -->
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                                @forelse($availableCoaches as $coachItem)
                                    <label class="p-2.5 rounded-xl bg-[#F2F2F7] hover:bg-white hover:shadow-2xs flex items-start gap-2 transition-all cursor-pointer select-none">
                                        <input type="checkbox"
                                               :value="{{ $coachItem['id'] }}"
                                               x-model="selectedCoaches"
                                               class="rounded text-[#780000] focus:ring-[#780000] w-4 h-4 cursor-pointer shrink-0 mt-0.5">
                                        <span class="min-w-0 space-y-0.5">
                                            <span class="flex items-center gap-1.5">
                                                <span class="font-semibold text-sm text-[#1D1D1F] truncate">{{ $coachItem['name'] }}</span>
                                                @if($coachItem['is_fairest_pick'])
                                                    <span class="px-1.5 py-0.5 rounded-md text-[10px] leading-tight font-black bg-emerald-100 text-emerald-800 whitespace-nowrap">Fairest pick</span>
                                                @endif
                                            </span>
                                            <span class="block text-xs text-[#6E6E73]">
                                                {{ $coachItem['recent_student_count'] }} {{ Str::plural('student', $coachItem['recent_student_count']) }} in last 30 days
                                                &middot;
                                                @if($coachItem['days_since_last_assignment'] === null)
                                                    never assigned
                                                @else
                                                    last assigned {{ $coachItem['days_since_last_assignment'] }} {{ Str::plural('day', $coachItem['days_since_last_assignment']) }} ago
                                                @endif
                                            </span>
                                        </span>
                                    </label>
                                @empty
                                    <div class="col-span-full p-3 rounded-xl text-center text-sm text-[#8E8E93] bg-[#F2F2F7]">
                                        No other available coaches on this schedule.
                                    </div>
                                @endforelse
                            </div>
                        </div>
                    </div>
                @else
                    <div class="py-5 px-4 rounded-xl text-center text-sm text-[#8E8E93] bg-[#F2F2F7] border border-dashed border-[#E5E5EA]">
                        No participants registered in this batch yet. Coach matching will open once students join.
                    </div>
                @endif

            </div>
            @endforeach
        </div>
    @else
        <div class="bg-white rounded-xl border border-[#E5E5EA] p-8 text-center space-y-2">
            <h3 class="font-bold text-base text-[#1D1D1F]">No Batches Currently Scheduled</h3>
            <p class="text-sm text-[#6E6E73]">When new batches are created, you can assign coaches to them here.</p>
        </div>
    @endif

    <!-- Share to Coaches Modal -->
    <div x-show="shareModalOpen" x-cloak class="fixed inset-0 z-50 overflow-y-auto bg-black/50 flex items-center justify-center p-4">
        <div class="bg-white rounded-2xl max-w-md w-full p-6 space-y-4 shadow-2xl border border-[#E5E5EA]" @click.outside="shareModalOpen = false">
            <div class="flex items-center justify-between">
                <h3 class="text-lg font-bold text-[#1D1D1F]">Share to Coach Portal</h3>
                <button type="button" @click="shareModalOpen = false" class="text-lg font-bold text-[#8E8E93] hover:text-[#1D1D1F] cursor-pointer" aria-label="Close modal">✕</button>
            </div>
            <p class="text-sm text-[#6E6E73] leading-relaxed">
                Broadcast an open coaching slot for <strong class="text-[#780000] font-bold" x-text="shareBatchNumber"></strong> to all certified coaches in the Coach Portal. Coaches will be notified and can volunteer directly from their dashboard.
            </p>

            <form :action="shareActionUrl" method="POST" class="space-y-4 pt-1">
                @csrf
                <input type="hidden" name="batch_id" :value="shareBatchId">

                <div class="flex items-center justify-end gap-2 pt-3 border-t border-[#E5E5EA]">
                    <button type="button" @click="shareModalOpen = false" class="btn-secondary px-4 py-2 text-sm font-semibold rounded-xl cursor-pointer">
                        Cancel
                    </button>
                    <button type="submit" class="btn-primary px-5 py-2 text-sm font-bold rounded-xl shadow-2xs cursor-pointer">
                        Confirm &amp; Share
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Unassign Coach Modal -->
    <div x-show="unassignModalOpen" x-cloak class="fixed inset-0 z-50 overflow-y-auto bg-black/50 flex items-center justify-center p-4">
        <div class="bg-white rounded-2xl max-w-md w-full p-6 space-y-4 shadow-2xl border border-[#E5E5EA]" @click.outside="unassignModalOpen = false">
            <div class="flex items-center justify-between">
                <h3 class="text-lg font-bold text-[#1D1D1F]">Remove Coach Assignment</h3>
                <button type="button" @click="unassignModalOpen = false" class="text-lg font-bold text-[#8E8E93] hover:text-[#1D1D1F] cursor-pointer" aria-label="Close modal">✕</button>
            </div>
            <p class="text-sm text-[#6E6E73] leading-relaxed">
                Are you sure you want to remove <strong class="text-[#1D1D1F]" x-text="'Coach ' + unassignCoachName"></strong> from <strong class="text-[#780000]" x-text="unassignBatchNumber"></strong>?
            </p>

            <form :action="unassignActionUrl" method="POST" class="space-y-4 pt-1">
                @csrf
                <input type="hidden" name="batch_id" :value="unassignBatchId">
                <input type="hidden" name="coach_id" :value="unassignCoachId">

                <div class="flex items-center justify-end gap-2 pt-3 border-t border-[#E5E5EA]">
                    <button type="button" @click="unassignModalOpen = false" class="btn-secondary px-4 py-2 text-sm font-semibold rounded-xl cursor-pointer">
                        Cancel
                    </button>
                    <button type="submit" class="px-5 py-2 text-sm font-bold rounded-xl bg-rose-600 hover:bg-rose-700 text-white shadow-2xs cursor-pointer transition-colors">
                        Remove Coach
                    </button>
                </div>
            </form>
        </div>
    </div>

</div>
@endsection
