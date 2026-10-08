@extends('layouts.admin')

@section('title', $coach->name . ' - Coach Detail | Camp FreedivePH')

@section('breadcrumb')
    <a href="{{ portal_route('coaches.index') }}" class="text-[#6E6E73] hover:text-[#780000] font-medium transition-colors">Coaches &amp; Schedules</a>
    <svg class="w-3.5 h-3.5 text-[#8E8E93] shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m9 18 6-6-6-6"/></svg>
    <span class="font-bold text-[#1D1D1F]">{{ $coach->name }}</span>
@endsection

@section('content')
<div class="space-y-6 text-sm" x-data="{ 
    openReassignModal: false, 
    selectedParticipantId: null, 
    selectedParticipantName: '' 
}">
    
    <!-- Top Coach Overview Banner Header -->
    <div class="flex flex-col sm:flex-row sm:items-start justify-between gap-4">
        <div class="space-y-1.5 min-w-0">
            <!-- Coach Overview Heading & Details -->
            <div>
                <h1 class="text-2xl sm:text-3xl font-extrabold text-[#1D1D1F] tracking-tight">{{ $coach->name }}</h1>
                <div class="flex items-center gap-2 sm:gap-3 flex-wrap text-sm text-[#6E6E73] mt-1.5">
                    <span>{{ $coach->email }}</span>
                    <span>{{ $coach->phone ?: 'No phone recorded' }}</span>
                    <span>Joined {{ $coach->created_at->format('M Y') }}</span>
                    <span class="px-2.5 py-0.5 rounded-full text-xs font-bold {{ $coach->status === 'active' ? 'bg-[#E8F5E9] text-[#1B5E20]' : 'bg-[#F2F2F7] text-[#6E6E73]' }}">
                        {{ ucfirst($coach->status) }}
                    </span>
                    <span class="px-2.5 py-0.5 rounded-full text-xs font-semibold bg-[#F2F2F7] text-[#1D1D1F]">
                        Freediving Coach
                    </span>
                </div>
            </div>
        </div>

        <div class="flex items-center gap-2 sm:gap-2.5 w-full sm:w-auto shrink-0">
            <a href="{{ route('admin.users.edit', $coach) }}" class="btn-secondary px-3.5 sm:px-4 py-2 text-sm font-semibold flex items-center justify-center gap-2 flex-1 sm:flex-initial">
                <span>Edit Account</span>
            </a>

            <a href="{{ route('admin.coaches.matching') }}" class="btn-primary px-4 py-2 text-sm font-bold flex items-center justify-center gap-1.5 flex-1 sm:flex-initial shadow-2xs">
                <span>Assign Participants</span>
            </a>
        </div>
    </div>

    <!-- Coach Profile Content -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        
        <!-- Assigned Participants & Schedule -->
        <div class="lg:col-span-2 space-y-6">
            
            <!-- Assigned Participants Section -->
            <div class="space-y-4">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-1 pb-1">
                    <div>
                        <h3 class="text-base font-bold text-[#1D1D1F]">Assigned Participants</h3>
                    </div>

                    <span class="text-xs sm:text-sm text-[#6E6E73] font-medium sm:text-right">
                        {{ $activeAssignments->count() }} Active Participant(s) across {{ $upcomingAssignments->pluck('dive_date')->unique()->count() }} Upcoming Dive Date(s)
                    </span>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    @forelse($activeAssignments as $assignment)
                    @php 
                        $p = $assignment->participant; 
                        $classType = $p->booking?->formatted_class_type ?? ucfirst($p->booking?->class_type ?? 'Discovery');
                        $swimmerLabel = match(strtolower($p->swimmer_status ?? 'swimmer')) {
                            'non_swimmer' => 'Non-Swimmer',
                            'casual_swimmer' => 'Casual Swimmer',
                            'confident_swimmer' => 'Confident Swimmer',
                            'beginner' => 'Beginner Swimmer',
                            'intermediate' => 'Intermediate Swimmer',
                            'advanced' => 'Advanced Swimmer',
                            default => ucfirst(str_replace('_', ' ', $p->swimmer_status ?? 'Swimmer'))
                        };
                        $rawCondition = trim($p->health_condition ?? '');
                        $cleanCondition = strtolower(rtrim($rawCondition, '.'));
                        $nonMedicalEntries = [
                            '', 'none', 'none declared', 'n/a', 'na', 'no', 'nil', 
                            'nothing', 'fit for diving', 'fit to dive', 'first time freediving', 
                            'first timer', 'first time', 'good', 'healthy', 'normal', 'ok', 'okay', 
                            'none / fit for diving', 'fit', 'all good', 'no medical condition', 'no issues',
                            'fit and ready', 'ready', 'none declared / fit for diving'
                        ];
                        $isNoMedical = empty($rawCondition) || in_array($cleanCondition, $nonMedicalEntries);

                        $startDate = $assignment->batch?->start_date ?? $p->booking?->start_date ?? $assignment->dive_date;
                        $endDate = $assignment->batch?->end_date ?? $p->booking?->end_date ?? $assignment->dive_date;
                        $formattedDiveDate = 'N/A';
                        if ($startDate && $endDate) {
                            if ($startDate->eq($endDate)) {
                                $formattedDiveDate = $startDate->format('M d, Y');
                            } elseif ($startDate->year === $endDate->year) {
                                $formattedDiveDate = $startDate->format('M d') . ' - ' . $endDate->format('M d, Y');
                            } else {
                                $formattedDiveDate = $startDate->format('M d, Y') . ' - ' . $endDate->format('M d, Y');
                            }
                        } elseif ($startDate) {
                            $formattedDiveDate = $startDate->format('M d, Y');
                        }
                    @endphp
                    <div class="bg-white rounded-2xl border border-[#E5E5EA] p-4 sm:p-5 flex flex-col justify-between space-y-4 shadow-2xs">
                        <div class="space-y-3">
                            <!-- Participant Header: Name, Age • Swimmer on Left, Class Badge on Right -->
                            <div class="flex items-start justify-between gap-2">
                                <div class="min-w-0 flex-1">
                                    <h4 class="font-extrabold text-base text-[#1D1D1F] leading-tight truncate">{{ $p->name }}</h4>
                                    <p class="text-sm text-[#6E6E73] font-medium mt-1">
                                        Age {{ $p->age }} <span class="text-[#AEAEB2] mx-1">•</span> {{ $swimmerLabel }}
                                    </p>
                                </div>
                                <span class="px-2.5 py-0.5 rounded-full text-xs font-bold bg-[#E5E5EA] text-[#1D1D1F] shrink-0">
                                    {{ $p->booking?->class_type === 'discovery' ? 'Discovery' : ($p->booking?->class_type === 'fundive' ? 'Fundive' : 'Refinement') }}
                                </span>
                            </div>

                            @if($assignment->is_ratio_override)
                                <div class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-bold bg-[#FDE8E8] text-[#9B1C1C]">
                                    <svg class="w-3 h-3 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                                    <span>Ratio Override Active</span>
                                </div>
                            @endif

                            <!-- 2x2 Data Grid (Medical Note, Batch, Booking Ref, Dive Date) -->
                            <div class="grid grid-cols-2 gap-x-4 gap-y-3 pt-1">
                                <div>
                                    <span class="text-xs font-bold uppercase tracking-wider text-[#8E8E93] block">MEDICAL NOTE</span>
                                    <span class="text-sm font-semibold block mt-0.5 {{ !$isNoMedical ? 'text-amber-800' : 'text-[#1D1D1F]' }}">
                                        {{ !$isNoMedical ? $rawCondition : 'None' }}
                                    </span>
                                </div>

                                <div>
                                    <span class="text-xs font-bold uppercase tracking-wider text-[#8E8E93] block">BATCH</span>
                                    <span class="text-sm font-semibold text-[#1D1D1F] block mt-0.5">
                                        {{ $assignment->batch?->batch_number ?? ($assignment->batch?->batch_code ?? '—') }}
                                    </span>
                                </div>

                                <div>
                                    <span class="text-xs font-bold uppercase tracking-wider text-[#8E8E93] block">BOOKING REF</span>
                                    <span class="text-sm font-semibold text-[#1D1D1F] block mt-0.5">
                                        @if($p->booking)
                                            <a href="{{ route('admin.bookings.show', $p->booking) }}" class="font-mono hover:text-[#780000] transition-colors">
                                                {{ $p->booking->booking_number }}
                                            </a>
                                        @else
                                            —
                                        @endif
                                    </span>
                                </div>

                                <div>
                                    <span class="text-xs font-bold uppercase tracking-wider text-[#8E8E93] block">DIVE DATE</span>
                                    <span class="text-sm font-semibold text-[#1D1D1F] block mt-0.5">
                                        {{ $formattedDiveDate }}
                                    </span>
                                </div>
                            </div>
                        </div>

                        <!-- Action Footer: Reassign Away -->
                        <div class="pt-3 flex items-center justify-end">
                            <button type="button" 
                                    @click="selectedParticipantId = {{ $p->id }}; selectedParticipantName = '{{ addslashes($p->name) }}'; openReassignModal = true"
                                    class="px-4 py-1.5 text-xs sm:text-sm font-bold rounded-xl border border-[#D1D1D6] bg-white hover:border-[#780000] hover:text-[#780000] text-[#1D1D1F] shadow-2xs transition-colors cursor-pointer">
                                Reassign Away
                            </button>
                        </div>
                    </div>
                    @empty
                    <div class="col-span-full py-8 text-center bg-[#F2F2F7] rounded-xl">
                        <p class="text-sm text-[#6E6E73]">
                            No participants currently assigned to this coach. Use the <a href="{{ route('admin.coaches.matching') }}" class="text-[#780000] font-bold underline">Matching Queue</a> to assign participants.
                        </p>
                    </div>
                    @endforelse
                </div>
            </div>

            <!-- Completed Dive History Section -->
            <div class="space-y-4">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-1 pb-1">
                    <div>
                        <h3 class="text-base font-bold text-[#1D1D1F]">Completed Dive History</h3>
                    </div>

                    <span class="text-xs sm:text-sm text-[#6E6E73] font-medium sm:text-right">
                        {{ $pastBatches->count() }} Completed Batch(es) · {{ $pastAssignments->count() }} Past Participant(s)
                    </span>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    @forelse($pastBatches as $batchKey => $assignmentsInBatch)
                    @php
                        $firstAssignment = $assignmentsInBatch->first();
                        $batch = $firstAssignment->batch;
                        $startDate = $batch?->start_date ?? $firstAssignment->dive_date;
                        $endDate = $batch?->end_date ?? $firstAssignment->dive_date;
                        $dateStr = 'N/A';
                        if ($startDate && $endDate) {
                            if ($startDate->eq($endDate)) {
                                $dateStr = $startDate->format('M d, Y');
                            } elseif ($startDate->year === $endDate->year) {
                                $dateStr = $startDate->format('M d') . ' - ' . $endDate->format('M d, Y');
                            } else {
                                $dateStr = $startDate->format('M d, Y') . ' - ' . $endDate->format('M d, Y');
                            }
                        } elseif ($startDate) {
                            $dateStr = $startDate->format('M d, Y');
                        }
                    @endphp
                    <div class="bg-white rounded-2xl border border-[#E5E5EA] p-4 sm:p-5 flex flex-col justify-between space-y-4 shadow-2xs">
                        <div class="space-y-3">
                            <!-- Batch Header: Name, Dive Date & Status -->
                            <div class="flex items-start justify-between gap-2">
                                <div class="min-w-0 flex-1">
                                    <h4 class="font-extrabold text-base text-[#1D1D1F] leading-tight truncate">
                                        {{ $batch?->batch_number ?? ($batch?->batch_code ?? 'Batch Schedule') }}
                                    </h4>
                                    <p class="text-sm text-[#6E6E73] font-medium mt-1">
                                        {{ $dateStr }}
                                    </p>
                                </div>
                                <span class="px-2.5 py-0.5 rounded-full text-xs font-bold bg-[#E8F5E9] text-[#1B5E20] shrink-0">
                                    {{ ucfirst(str_replace('_', ' ', $batch?->status ?? 'completed')) }}
                                </span>
                            </div>

                            <!-- Participant List & Booking Link -->
                            <div class="space-y-1.5 pt-1">
                                <span class="text-xs font-bold uppercase tracking-wider text-[#8E8E93] block">COACHED PARTICIPANTS</span>
                                <div class="divide-y divide-[#E5E5EA] px-3">
                                    @foreach($assignmentsInBatch as $assignment)
                                        @php
                                            $student = $assignment->participant;
                                            $booking = $student?->booking;
                                        @endphp
                                        <div class="py-2.5 first:pt-2.5 last:pb-2.5 flex items-center justify-between text-sm gap-2">
                                            <span class="font-bold text-[#1D1D1F] truncate">{{ $student?->name ?? 'Participant' }}</span>
                                            @if($booking)
                                                <a href="{{ route('admin.bookings.show', $booking) }}" 
                                                   class="font-mono text-sm font-bold text-[#780000] hover:underline shrink-0"
                                                   title="View Booking Details">
                                                    {{ $booking->booking_number }}
                                                </a>
                                            @else
                                                <span class="text-sm text-[#8E8E93] shrink-0">—</span>
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    </div>
                    @empty
                    <div class="col-span-full py-8 text-center bg-[#F2F2F7] rounded-xl">
                        <p class="text-sm text-[#6E6E73]">
                            No past completed dive history found for this coach.
                        </p>
                    </div>
                    @endforelse
                </div>
            </div>

        </div>

        <!-- Availability Calendar -->
        <div class="space-y-6">
            
            <!-- Availability Calendar Section -->
            <div class="bg-white rounded-2xl border border-[#E5E5EA] p-5 sm:p-6 shadow-2xs space-y-4">
                <div class="border-b border-[#E5E5EA] pb-3">
                    <h3 class="text-base font-bold text-[#1D1D1F]">Availability Calendar</h3>
                    <p class="text-xs sm:text-sm text-[#6E6E73] mt-0.5">Dates marked by the coach in the Coach Portal.</p>
                </div>

                <div class="space-y-2">
                    @forelse($coach->coachAvailabilities as $avail)
                    <div class="p-3 rounded-xl flex items-center justify-between text-sm {{ $avail->status_badge['class'] }}">
                        <div>
                            <strong class="block font-bold">{{ $avail->date->format('F d, Y (l)') }}</strong>
                            @if($avail->notes)
                                <span class="text-xs opacity-80 block mt-0.5">{{ $avail->notes }}</span>
                            @endif
                        </div>

                        <span class="px-2.5 py-0.5 rounded-full text-xs font-bold bg-white text-[#1D1D1F] shadow-2xs">
                            {{ $avail->status_badge['label'] }}
                        </span>
                    </div>
                    @empty
                    <p class="text-sm text-[#6E6E73] py-4 text-center">
                        No availability calendar entries submitted yet by this coach.
                    </p>
                    @endforelse
                </div>
            </div>

        </div>

    </div>

    <!-- Reassign Participant Modal -->
    <div x-show="openReassignModal" x-cloak class="fixed inset-0 z-50 bg-black/40 flex justify-end">
        <div class="dive-side-panel h-full overflow-y-auto overscroll-contain bg-white sm:max-w-md w-full p-6 space-y-4 shadow-2xl border-l border-[#E5E5EA]" @click.outside="openReassignModal = false">
            <h3 class="text-lg font-bold text-[#1D1D1F]">Reassign Participant Away</h3>
            <p class="text-sm text-[#6E6E73]">
                Reassign <strong class="text-[#1D1D1F]" x-text="selectedParticipantName"></strong> away from Coach {{ $coach->name }} to another coach.
            </p>

            <form action="{{ route('admin.coaches.reassign_student', $coach) }}" method="POST" class="space-y-3 text-sm">
                @csrf
                <input type="hidden" name="participant_id" :value="selectedParticipantId">

                <div>
                    <label class="block font-bold text-[#1D1D1F] mb-1.5">Select New Coach <span class="text-[#780000]">*</span></label>
                    <select name="new_coach_id" required class="w-full px-3 py-2 rounded-xl border border-[#D1D1D6] text-sm text-[#1D1D1F] bg-white font-medium">
                        <option value="">-- Choose Active Coach --</option>
                        @foreach($otherCoaches as $c)
                            <option value="{{ $c->id }}">{{ $c->name }} ({{ $c->email }})</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="block font-bold text-[#1D1D1F] mb-1.5">Reassignment Reason <span class="text-[#780000]">*</span></label>
                    <textarea name="reason" required rows="3" placeholder="e.g. Original coach reported sick / Schedule balance adjustment" class="w-full px-3.5 py-2.5 rounded-xl border border-[#D1D1D6] text-sm text-[#1D1D1F] bg-white font-medium"></textarea>
                </div>

                <div class="flex items-center justify-end gap-2 pt-3">
                    <button type="button" @click="openReassignModal = false" class="btn-secondary px-3.5 py-1.5 text-sm">Cancel</button>
                    <button type="submit" class="btn-primary px-4 py-1.5 text-sm font-bold shadow-2xs">
                        Confirm Reassignment
                    </button>
                </div>
            </form>
        </div>
    </div>

</div>
@endsection
