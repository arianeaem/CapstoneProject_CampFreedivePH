@extends('layouts.admin')

@section('title', 'Coach Dashboard | Camp FreedivePH')

@section('content')
@php
    $next = $nextSessionData;
    $riskTone = fn ($c) => match ($c) {
        'Critical Risk' => 'bg-[#FEF2F2] text-[#B91C1C]',
        'High Risk' => 'bg-[#FFFBEB] text-[#B45309]',
        'Moderate Risk', 'Moderate' => 'bg-[#FEF9C3] text-[#854D0E]',
        default => 'bg-[#00C3D0]/10 text-[#00636A]',
    };
@endphp
<div class="space-y-5" x-data="{
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

    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div class="flex items-center gap-3.5 min-w-0">
            <a href="{{ route('profile.show') }}" data-native title="Change your profile photo">
                <x-user-avatar :user="$coach" class="w-14 h-14 text-lg" />
            </a>
            <div class="min-w-0">
                <h1 class="text-2xl sm:text-3xl font-extrabold text-[#1D1D1F] tracking-tight">Welcome back, {{ $coach->name }}!</h1>
                <p class="text-sm text-[#6E6E73] mt-1">It's {{ now('Asia/Manila')->format('l, F d, Y') }}. Here's your coaching at a glance.</p>
            </div>
        </div>
        <div class="flex items-center gap-2.5 flex-wrap">
            <a href="{{ route('coach.availability.index') }}" class="btn-secondary min-h-[44px] px-4 py-2 rounded-xl text-sm font-bold inline-flex items-center">My availability</a>
            <a href="{{ route('coach.requests.index') }}" class="btn-primary min-h-[44px] px-4 py-2 rounded-xl text-sm font-bold inline-flex items-center shadow-2xs">Open camp slots ({{ $activeOpeningsCount }})</a>
        </div>
    </div>

    <!-- 1. Summary cards -->
    <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4">
        @php
            $cards = [
                ['label' => 'Upcoming dives', 'value' => $upcomingConfirmedDivesCount, 'hint' => Str::plural('Batch', $upcomingConfirmedDivesCount) . ' you are coaching'],
                ['label' => 'Days you are free', 'value' => $availableDaysCount, 'hint' => 'Available dates you offered', 'tone' => 'text-[#00838C]'],
                ['label' => 'Open camp slots', 'value' => $activeOpeningsCount, 'hint' => $pendingRequestsCount > 0 ? $pendingRequestsCount . ' of your ' . Str::plural('request', $pendingRequestsCount) . ' waiting for approval' : 'Batches asking for extra coaches', 'tone' => $activeOpeningsCount > 0 ? 'text-[#B45309]' : null, 'url' => $activeOpeningsCount > 0 ? route('coach.requests.index') : null],
                ['label' => 'Participants coached', 'value' => $totalStudentsMentored, 'hint' => 'All participants assigned to you'],
            ];
        @endphp
        @foreach($cards as $card)
            <div class="relative overflow-hidden rounded-2xl border border-[#E5E5EA] bg-gradient-to-b from-[#780000]/[0.04] via-white to-white p-5 flex flex-col gap-2 shadow-2xs">
                <div class="absolute -top-10 -right-10 w-32 h-32 bg-gradient-to-br from-[#780000]/15 via-[#9E2A2B]/8 to-transparent rounded-full blur-2xl pointer-events-none" aria-hidden="true"></div>
                <span class="relative text-[11px] font-bold uppercase tracking-wider text-[#6E6E73]">{{ $card['label'] }}</span>
                <div class="relative text-2xl sm:text-3xl font-black tracking-tight {{ $card['tone'] ?? 'text-[#780000]' }}">{{ $card['value'] }}</div>
                <div class="relative text-xs text-[#6E6E73]">
                    @if(!empty($card['url']))
                        <a href="{{ $card['url'] }}" class="font-semibold text-[#B45309] hover:underline">{{ $card['hint'] }} &rarr;</a>
                    @else
                        {{ $card['hint'] }}
                    @endif
                </div>
            </div>
        @endforeach
    </div>

    <!-- 2. Next dive + upcoming dives -->
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-5 items-start">

        <!-- Your next dive -->
        <div class="lg:col-span-7 bg-white rounded-2xl border border-[#E5E5EA] p-5 space-y-4">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h2 class="text-base font-extrabold text-[#1D1D1F] flex items-center gap-2"><span class="w-1 h-4 rounded-full bg-[#780000]" aria-hidden="true"></span>Your next dive</h2>
                    <p class="text-sm text-[#8E8E93]">
                        @if($next && $next['is_shared_pool'])
                            You're on the coach team for this batch
                        @else
                            The batch you're coaching next
                        @endif
                    </p>
                </div>
                <a href="{{ route('coach.schedule.index') }}" class="text-sm font-bold text-[#780000] hover:underline shrink-0">Full schedule &rarr;</a>
            </div>

            @if($next)
                <div class="rounded-xl bg-gradient-to-b from-[#780000]/[0.04] to-white border border-[#E5E5EA] p-4 space-y-3">
                    <div class="flex flex-wrap items-end justify-between gap-2">
                        <div>
                            <span class="block text-[11px] font-bold uppercase tracking-wider text-[#6E6E73]">{{ $next['batch']->batch_number }}</span>
                            <span class="block text-xl sm:text-2xl font-black text-[#780000] tracking-tight">
                                {{ $next['batch']->formatted_date_range }}
                            </span>
                            <span class="block text-xs text-[#6E6E73]">
                                {{ $next['batch']->start_date->format('D') }} - {{ $next['batch']->end_date->format('D') }}
                                &middot; starts {{ $next['batch']->start_date->copy()->setTime(6, 30)->diffForHumans() }}
                            </span>
                        </div>
                        <div class="text-right">
                            <span class="block text-2xl font-black text-[#1D1D1F]">{{ $next['students_count'] }}</span>
                            <span class="block text-xs text-[#6E6E73]">{{ Str::plural('participant', $next['students_count']) }}</span>
                        </div>
                    </div>
                    @if(!empty($next['class_breakdown']))
                        <div class="flex flex-wrap gap-1.5">
                            @foreach($next['class_breakdown'] as $cType => $cnt)
                                <span class="px-2.5 py-1 rounded-lg text-xs font-bold bg-[#F8EAEA] text-[#780000]">{{ $cnt }} {{ $cType }}</span>
                            @endforeach
                        </div>
                    @endif
                </div>

                <!-- Weather (uses components/dive-safety/status) -->
                <x-dive-safety.status :classification="$next['weather_class']" :engines="$next['model_comparison'] ?? null" />

                <div class="flex flex-col sm:flex-row gap-2.5">
                    <a href="{{ route('coach.schedule.index') }}" class="btn-primary flex-1 min-h-[44px] rounded-xl text-sm font-bold inline-flex items-center justify-center shadow-2xs">View full schedule</a>
                    @if($next['can_request_release'])
                        <button type="button" @click="openReleaseModal = true" class="btn-secondary flex-1 min-h-[44px] rounded-xl text-sm font-bold text-[#B91C1C]">Ask to be released</button>
                    @endif
                </div>
            @else
                <div class="rounded-xl bg-[#F8F9FA] p-6 text-center space-y-3">
                    <p class="font-bold text-[#1D1D1F]">No dives assigned yet</p>
                    <p class="text-sm text-[#6E6E73] max-w-md mx-auto">You don't have a batch coming up. Keep your available dates updated so the camp can match you with participants.</p>
                    <a href="{{ route('coach.availability.index') }}" class="btn-primary min-h-[44px] px-4 rounded-xl text-sm font-bold inline-flex items-center shadow-2xs">Update my available dates</a>
                </div>
            @endif
        </div>

        <!-- My upcoming dives -->
        <div class="lg:col-span-5 bg-white rounded-2xl border border-[#E5E5EA] overflow-hidden">
            <div class="p-5 pb-3">
                <h2 class="text-base font-extrabold text-[#1D1D1F] flex items-center gap-2"><span class="w-1 h-4 rounded-full bg-[#780000]" aria-hidden="true"></span>My upcoming dives</h2>
                <p class="text-sm text-[#8E8E93]">Your next {{ max(1, $upcomingAssignments->count()) }} assigned {{ Str::plural('batch', $upcomingAssignments->count()) }}</p>
            </div>
            <div class="px-5 pb-4">
                <table class="w-full text-left text-sm">
                    <thead>
                        <tr class="text-[#8E8E93] text-xs border-b border-[#E5E5EA]">
                            <th class="py-2.5 pr-3 font-semibold">Dates</th>
                            <th class="py-2.5 pr-3 font-semibold">Participants</th>
                            <th class="py-2.5 font-semibold">Sea safety</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[#F2F2F7]">
                        @forelse($upcomingAssignments as $group)
                            @php
                                $ub = $group->first()->batch;
                                $uClass = $ub?->latestDay1Assessment?->overall_classification ?? 'Not checked yet';
                            @endphp
                            <tr>
                                <td class="py-3 pr-3">
                                    <span class="block font-semibold text-[#1D1D1F] whitespace-nowrap">{{ $ub?->start_date->format('M d') }} – {{ $ub?->end_date?->format('M d') }}</span>
                                    <span class="block text-xs text-[#8E8E93]">{{ $ub?->batch_number }} &middot; {{ $ub?->start_date->diffForHumans() }}</span>
                                </td>
                                <td class="py-3 pr-3 font-semibold text-[#1D1D1F]">{{ $group->pluck('participant_id')->unique()->count() }}</td>
                                <td class="py-3">
                                    <span class="inline-flex px-2 py-0.5 rounded-md text-xs font-bold whitespace-nowrap {{ $riskTone($uClass) }}">{{ $uClass }}</span>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="py-8 text-center text-[#8E8E93]">No assigned dives yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- 3. Your participants -->
    @if($next)
        <div class="bg-white rounded-2xl border border-[#E5E5EA] p-5 space-y-4">
            <div class="flex flex-col sm:flex-row sm:items-start justify-between gap-2">
                <div>
                    <h2 class="text-base font-extrabold text-[#1D1D1F] flex items-center gap-2"><span class="w-1 h-4 rounded-full bg-[#780000]" aria-hidden="true"></span>Your participants for {{ $next['batch']->batch_number }}</h2>
                    <p class="text-sm text-[#8E8E93]">Check swimming ability and health notes before the boat leaves</p>
                </div>
                <span class="text-sm font-extrabold text-[#780000] bg-[#F8EAEA] px-3 py-1 rounded-xl self-start">{{ $next['students_count'] }} {{ Str::plural('participant', $next['students_count']) }}</span>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-3">
                @foreach($next['students'] as $student)
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
                        [$swimLabel, $swimTone] = match (true) {
                            in_array($swim, ['confident', 'confident_swimmer']) => ['Confident swimmer', 'bg-[#00C3D0]/10 text-[#00636A]'],
                            $swim === 'non_swimmer' => ['Non-swimmer', 'bg-[#FEF2F2] text-[#B91C1C]'],
                            default => ['Swimmer', 'bg-[#F2F2F7] text-[#3A3A3C]'],
                        };
                        $classType = $student->booking?->formatted_class_type ?? 'Discovery';
                        $shortClassType = ucfirst($student->booking?->class_type ?? 'Discovery');
                    @endphp
                    <div class="rounded-xl border border-[#E5E5EA] {{ $hasMedical ? 'border-l-4 border-l-[#B45309]' : '' }} p-4 space-y-3">
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <span class="block font-extrabold text-[#1D1D1F] truncate">{{ $student->name }}</span>
                                <span class="block text-xs text-[#8E8E93]">{{ $student->age }} yrs &middot; {{ $classType }}</span>
                                <x-participant-history-badge :bp="$student" class="mt-1" />
                            </div>
                            <span class="px-2 py-0.5 rounded-md text-xs font-bold bg-[#F8EAEA] text-[#780000] shrink-0">{{ $shortClassType }}</span>
                        </div>
                        <dl class="divide-y divide-[#F2F2F7] text-sm">
                            <div class="flex items-center justify-between gap-2 py-1.5">
                                <dt class="text-[#6E6E73]">Swimming</dt>
                                <dd><span class="px-2 py-0.5 rounded-md text-xs font-bold {{ $swimTone }}">{{ $swimLabel }}</span></dd>
                            </div>
                            <div class="flex items-center justify-between gap-2 py-1.5">
                                <dt class="text-[#6E6E73] shrink-0">Health</dt>
                                <dd class="min-w-0 text-right">
                                    @if($hasMedical)
                                        <span class="block px-2 py-0.5 rounded-md text-xs font-bold bg-[#FFFBEB] text-[#B45309] truncate" title="{{ $student->health_condition }}">{{ $student->health_condition }}</span>
                                    @else
                                        <span class="text-xs text-[#8E8E93] font-semibold">None declared</span>
                                    @endif
                                </dd>
                            </div>
                            <div class="flex items-center justify-between gap-2 py-1.5">
                                <dt class="text-[#6E6E73]">Booked by</dt>
                                <dd class="font-semibold text-[#1D1D1F] truncate">{{ $student->booking?->contact_name ?? $student->name }}</dd>
                            </div>
                            <div class="flex items-center justify-between gap-2 py-1.5">
                                <dt class="text-[#6E6E73]">Contact</dt>
                                <dd class="truncate">
                                    <a href="tel:{{ $student->booking?->contact_phone ?: '09185559876' }}" class="font-semibold text-[#780000] hover:underline">
                                        {{ $student->booking?->contact_phone ?: ($student->booking?->contact_email ?: '0918 555 9876') }}
                                    </a>
                                </dd>
                            </div>
                        </dl>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    <!-- 4. Next 7 days + open camp slots -->
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-5 items-start">

        <!-- Next 7 days -->
        <div class="lg:col-span-7 bg-white rounded-2xl border border-[#E5E5EA] p-5 space-y-4">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h2 class="text-base font-extrabold text-[#1D1D1F] flex items-center gap-2"><span class="w-1 h-4 rounded-full bg-[#780000]" aria-hidden="true"></span>Your next 7 days</h2>
                    <p class="text-sm text-[#8E8E93]">Tap a day to mark yourself free or not free</p>
                </div>
                <a href="{{ route('coach.availability.index') }}" class="text-sm font-bold text-[#780000] hover:underline shrink-0">Full month &rarr;</a>
            </div>

            <div class="overflow-x-auto pb-1 scrollbar-none -mx-1 px-1">
                <div class="grid grid-cols-7 gap-2 min-w-[520px]">
                    @foreach($quickDays as $day)
                        @if($day['status'] === 'assigned')
                            @php
                                $isDay1 = ($day['assigned_day_number'] ?? 1) === 1;
                            @endphp
                            <div class="rounded-xl p-2.5 text-center select-none {{ $isDay1 ? 'bg-[#780000] text-white' : 'bg-[#00C3D0] text-[#0A3538]' }}" title="Diving (Day {{ $isDay1 ? 1 : 2 }}) - locked">
                                <span class="block text-[11px] font-bold uppercase {{ $isDay1 ? 'text-white/80' : 'text-[#0A3538]/80' }}">{{ $day['date']->format('D') }}</span>
                                <span class="block text-base font-black">{{ $day['date']->format('M j') }}</span>
                                <span class="block text-[11px] font-bold">Dive day {{ $isDay1 ? 1 : 2 }}</span>
                            </div>
                        @else
                            @php $isFree = $day['status'] === 'available'; @endphp
                            <form action="{{ route('coach.availability.toggle') }}" method="POST" @submit="isTogglingDate = true; togglingDateStr = '{{ $day['date_str'] }}'" class="relative">
                                @csrf
                                <input type="hidden" name="date" value="{{ $day['date_str'] }}">
                                <button type="submit" :disabled="isTogglingDate"
                                        class="w-full rounded-xl p-2.5 text-center transition-colors cursor-pointer disabled:opacity-60 disabled:cursor-not-allowed {{ $isFree ? 'bg-[#00C3D0]/15 text-[#00636A] hover:bg-[#00C3D0]/25' : 'bg-[#F2F2F7] text-[#1D1D1F] hover:bg-[#F8EAEA]' }} {{ $day['is_today'] ? 'ring-2 ring-[#780000]/40' : '' }}"
                                        title="{{ $isFree ? 'Tap to mark as not free' : 'Tap to mark as free' }}">
                                    <span class="block text-[11px] font-bold uppercase {{ $isFree ? 'text-[#00838C]' : 'text-[#8E8E93]' }}">{{ $day['is_today'] ? 'Today' : $day['date']->format('D') }}</span>
                                    <span class="block text-base font-black">{{ $day['date']->format('M j') }}</span>
                                    <span class="block text-[11px] font-bold {{ $isFree ? '' : 'text-[#8E8E93]' }}">{{ $isFree ? 'Free' : 'Not set' }}</span>
                                </button>
                                <div x-show="isTogglingDate && togglingDateStr === '{{ $day['date_str'] }}'" x-cloak class="absolute inset-0 bg-white/80 rounded-xl flex items-center justify-center">
                                    <svg class="animate-spin w-5 h-5 text-[#780000]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><circle cx="12" cy="12" r="10" stroke-opacity="0.25"></circle><path d="M4 12a8 8 0 018-8"></path></svg>
                                </div>
                            </form>
                        @endif
                    @endforeach
                </div>
            </div>

            <div class="flex flex-wrap gap-x-4 gap-y-1 text-xs text-[#6E6E73]">
                <span class="inline-flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-sm bg-[#780000]"></span>Dive day 1</span>
                <span class="inline-flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-sm bg-[#00C3D0]"></span>Dive day 2</span>
                <span class="inline-flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-sm bg-[#00C3D0]/30"></span>Free</span>
                <span class="inline-flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-sm bg-[#E5E5EA]"></span>Not set</span>
            </div>
        </div>

        <!-- Open camp slots -->
        <div class="lg:col-span-5 bg-white rounded-2xl border border-[#E5E5EA] p-5 space-y-4">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h2 class="text-base font-extrabold text-[#1D1D1F] flex items-center gap-2"><span class="w-1 h-4 rounded-full bg-[#780000]" aria-hidden="true"></span>Batches that need a coach</h2>
                    <p class="text-sm text-[#8E8E93]">{{ $activeOpeningsCount > 0 ? $activeOpeningsCount . ' open ' . Str::plural('slot', $activeOpeningsCount) . ' you can volunteer for' : 'No open slots right now' }}</p>
                </div>
                <a href="{{ route('coach.requests.index') }}" class="text-sm font-bold text-[#780000] hover:underline shrink-0">See all &rarr;</a>
            </div>

            @if($openCoachOpenings->isEmpty())
                <div class="rounded-xl bg-[#00C3D0]/10 p-5">
                    <p class="font-bold text-[#00636A]">You're all caught up</p>
                    <p class="text-sm text-[#00838C]">We'll show batches here when they need extra coaches.</p>
                </div>
            @else
                <ul class="space-y-2">
                    @foreach($openCoachOpenings as $opening)
                        @php
                            $myRequest = $opening->requests->first();
                            $reqStatus = $myRequest?->status;
                            $b = $opening->batch;
                            $datesDisplay = ($b && $b->start_date && $b->end_date && $b->start_date->ne($b->end_date))
                                ? $b->formatted_date_range . ' (' . $b->start_date->format('D') . ' - ' . $b->end_date->format('D') . ')'
                                : $opening->dive_date->format('M d, Y · l');
                        @endphp
                        <li class="flex items-center gap-3 rounded-xl border border-[#E5E5EA] border-l-4 border-l-[#780000] p-3">
                            <span class="flex-1 min-w-0">
                                <span class="block text-sm font-semibold text-[#1D1D1F]">{{ $b?->batch_number ?? 'Batch' }} &middot; {{ $opening->needed_students_count }} {{ Str::plural('participant', $opening->needed_students_count) }} need a coach</span>
                                <span class="block text-xs text-[#8E8E93] truncate">{{ $datesDisplay }}</span>
                            </span>
                            @if($myRequest)
                                @if($reqStatus === 'approved')
                                    <span class="px-2.5 py-1 rounded-lg text-xs font-bold bg-[#00C3D0]/10 text-[#00636A] shrink-0">Accepted</span>
                                @elseif($reqStatus === 'not_selected')
                                    <span class="px-2.5 py-1 rounded-lg text-xs font-bold bg-[#F2F2F7] text-[#6E6E73] shrink-0">Filled</span>
                                @else
                                    <span class="px-2.5 py-1 rounded-lg text-xs font-bold bg-[#FFFBEB] text-[#B45309] shrink-0">Waiting for approval</span>
                                @endif
                            @else
                                <button type="button"
                                        @click="openVolunteerModal({{ json_encode($opening) }}, '{{ addslashes($datesDisplay) }}')"
                                        class="btn-primary min-h-[40px] px-3.5 text-xs font-bold rounded-xl shrink-0">Volunteer</button>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
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
         class="fixed inset-0 z-50 bg-black/40 backdrop-blur-sm flex justify-end"
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-150"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0">
        <div class="dive-side-panel h-full overflow-y-auto overscroll-contain bg-white sm:max-w-lg w-full p-5 sm:p-6 space-y-4 shadow-2xl border-l border-[#E5E5EA]" 
             @click.outside="openReleaseModal = false"
             x-transition:enter="transition ease-out duration-200"
             x-transition:enter-start="opacity-0 translate-x-full"
             x-transition:enter-end="opacity-100 translate-x-0"
             x-transition:leave="transition ease-in duration-150"
             x-transition:leave-start="opacity-100 translate-x-0"
             x-transition:leave-end="opacity-0 translate-x-full">
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
         class="fixed inset-0 z-50 bg-black/40 backdrop-blur-sm flex justify-end"
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-150"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0">
        <div class="dive-side-panel h-full overflow-y-auto overscroll-contain bg-white sm:max-w-lg w-full p-5 sm:p-6 space-y-4 shadow-2xl border-l border-[#E5E5EA]" 
             @click.outside="openApplyModal = false"
             x-transition:enter="transition ease-out duration-200"
             x-transition:enter-start="opacity-0 translate-x-full"
             x-transition:enter-end="opacity-100 translate-x-0"
             x-transition:leave="transition ease-in duration-150"
             x-transition:leave-start="opacity-100 translate-x-0"
             x-transition:leave-end="opacity-0 translate-x-full">
            <div class="flex items-start justify-between gap-3">
                <div class="space-y-1">
                    <h3 id="volunteer-modal-title" class="text-base sm:text-lg font-extrabold text-[#1D1D1F]">Volunteer for Camp Slot</h3>
                    <p class="text-sm text-[#6E6E73]">
                        <span class="font-bold text-[#1D1D1F]" x-text="selectedOpeningBatch"></span> &bull; 
                        <span x-text="selectedOpeningDate"></span>
                    </p>
                    <p class="text-xs font-semibold text-[#780000]" x-show="selectedOpeningNeeded">
                        <span x-text="selectedOpeningNeeded"></span> Participant(s) needing a coach
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
                        Submitting interest notifies Camp Admin. If selected, participants will be automatically matched to your roster.
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
