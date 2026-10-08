@extends('layouts.admin')

@section('title', 'My Schedule | Coach Portal')

@section('content')
@php
    // "None", "Fit for diving" and similar mean no health issue to flag
    $hasHealthNote = function ($p) {
        $raw = trim($p->health_condition ?? '');
        $clean = strtolower(rtrim($raw, '.'));
        if ($raw === '' || in_array($clean, ['none', 'none declared', 'no', 'n/a', 'na', 'nil', 'normal'], true)) {
            return false;
        }
        foreach (['fit for diving', 'none', 'cleared medical waiver', 'first time freediving', 'certified aida', 'working on frenzel'] as $prefix) {
            if (str_starts_with($clean, $prefix)) {
                return false;
            }
        }

        return true;
    };
    $swimBadge = function ($status) {
        $s = strtolower($status ?? 'swimmer');
        return match (true) {
            in_array($s, ['confident', 'confident_swimmer', 'swimmer'], true) => ['Confident swimmer', 'bg-emerald-50 text-emerald-800'],
            $s === 'non_swimmer' => ['Non-swimmer', 'bg-rose-50 text-rose-700'],
            default => ['Beginner swimmer', 'bg-blue-50 text-blue-800'],
        };
    };
    $whenLabel = function ($date) {
        $days = (int) \Carbon\Carbon::today()->diffInDays($date->copy()->startOfDay(), false);
        return match (true) {
            $days <= 0 => 'Today',
            $days === 1 => 'Tomorrow',
            $days < 7 => "In {$days} days",
            default => 'In ' . intdiv($days, 7) . ' ' . Str::plural('week', intdiv($days, 7)),
        };
    };
    $firstUpcomingId = collect($upcomingBatches)->first()['batch']->id ?? null;
@endphp

<div class="space-y-6 text-sm" x-data="{
    activeTab: '{{ $activeTab }}',
    releaseModalOpen: false,
    selectedBatch: null,
    submittingRelease: false,
    openBatches: @json($firstUpcomingId ? [$firstUpcomingId] : []),
    toggleBatch(id) {
        this.openBatches = this.openBatches.includes(id) ? this.openBatches.filter(x => x !== id) : [...this.openBatches, id];
    },
    isBatchOpen(id) { return this.openBatches.includes(id); }
}">

    <!-- Top Header -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl sm:text-3xl font-extrabold text-[#1D1D1F] tracking-tight">My Schedule</h1>
            <p class="text-sm text-[#6E6E73] mt-1">Your upcoming dives and the participants assigned to you, plus your past groups.</p>
        </div>

        <div class="flex items-center bg-[#F2F2F7] rounded-xl border border-[#E5E5EA] p-1 w-full sm:w-auto" role="tablist" aria-label="Schedule">
            <button type="button" id="tab-upcoming" role="tab" :aria-selected="activeTab === 'upcoming'" aria-controls="panel-upcoming"
                    @click="activeTab = 'upcoming'"
                    :class="activeTab === 'upcoming' ? 'bg-white text-[#1D1D1F] font-bold shadow-xs' : 'text-[#6E6E73] font-semibold hover:text-[#1D1D1F]'"
                    class="flex-1 sm:flex-initial min-h-[40px] px-4 py-2 rounded-lg text-sm transition-all cursor-pointer">
                Upcoming dives <span class="ml-1 px-1.5 py-0.5 rounded-md text-xs font-bold bg-[#780000] text-white">{{ count($upcomingBatches) }}</span>
            </button>
            <button type="button" id="tab-history" role="tab" :aria-selected="activeTab === 'history'" aria-controls="panel-history"
                    @click="activeTab = 'history'"
                    :class="activeTab === 'history' ? 'bg-white text-[#1D1D1F] font-bold shadow-xs' : 'text-[#6E6E73] font-semibold hover:text-[#1D1D1F]'"
                    class="flex-1 sm:flex-initial min-h-[40px] px-4 py-2 rounded-lg text-sm transition-all cursor-pointer">
                Past dives <span class="ml-1 px-1.5 py-0.5 rounded-md text-xs font-bold bg-[#E5E5EA] text-[#3A3A3C]">{{ $totalCompletedBatchesCount }}</span>
            </button>
        </div>
    </div>

    <!-- ============ UPCOMING ============ -->
    <div x-show="activeTab === 'upcoming'" role="tabpanel" id="panel-upcoming" aria-labelledby="tab-upcoming" class="space-y-3">
        @forelse($upcomingBatches as $item)
            @php
                $batch = $item['batch'];
                $students = $item['students'];
                $releaseReq = $item['release_request'];
                $healthCount = $students->filter($hasHealthNote)->count();
                $nonSwimmers = $students->filter(fn ($s) => strtolower($s->swimmer_status ?? '') === 'non_swimmer')->count();
                $minors = $students->filter(fn ($s) => $s->age !== null && $s->age < 18)->count();
                $when = $whenLabel($batch->start_date);
            @endphp

            <div class="bg-white rounded-xl border overflow-hidden shadow-2xs {{ $item['is_current_dive'] ? 'border-[#780000]' : 'border-[#E5E5EA]' }}">
                <!-- Summary row (click to open the roster) -->
                <div class="p-4 sm:p-5 flex flex-col md:flex-row md:items-center gap-4 cursor-pointer hover:bg-[#FAFAFA] transition-colors"
                     @click="toggleBatch({{ $batch->id }})">

                    <!-- Date block -->
                    <div class="flex items-center gap-4 min-w-0 flex-1">
                        <div class="w-16 shrink-0 rounded-xl border border-[#E5E5EA] text-center overflow-hidden">
                            <div class="text-[11px] font-black uppercase tracking-wider py-0.5 {{ $item['is_current_dive'] ? 'bg-[#780000] text-white' : 'bg-[#F8EAEA] text-[#780000]' }}">{{ $batch->start_date->format('M') }}</div>
                            <div class="text-2xl font-black text-[#1D1D1F] leading-tight">{{ $batch->start_date->format('d') }}</div>
                            <div class="text-[11px] font-semibold text-[#6E6E73] pb-1">{{ $batch->start_date->format('D') }}–{{ $batch->end_date->format('D') }}</div>
                        </div>

                        <div class="min-w-0 space-y-1.5">
                            <div class="flex items-center gap-2 flex-wrap">
                                <h2 class="text-base sm:text-lg font-extrabold text-[#1D1D1F]">{{ $batch->batch_number }}</h2>
                                <span class="px-2 py-0.5 rounded-md text-xs font-bold {{ $item['is_current_dive'] ? 'bg-[#780000] text-white' : 'bg-[#F2F2F7] text-[#3A3A3C]' }}">{{ $when }}</span>
                                <span class="px-2 py-0.5 rounded-md text-xs font-bold {{ $item['weather_badge']['class'] ?? 'bg-gray-100 text-gray-700' }}">Weather: {{ $item['weather_badge']['label'] ?? $item['weather_class'] }}</span>
                            </div>
                            <p class="text-[#6E6E73]">{{ $batch->formatted_date_range }}</p>
                            <div class="flex items-center gap-1.5 flex-wrap">
                                <span class="px-2 py-0.5 rounded-md text-xs font-bold bg-[#F2F2F7] text-[#1D1D1F]">{{ $item['students_count'] }} {{ Str::plural('participant', $item['students_count']) }}</span>
                                @foreach($item['class_counts'] as $class => $count)
                                    <span class="px-2 py-0.5 rounded-md text-xs font-semibold bg-white border border-[#E5E5EA] text-[#3A3A3C]">{{ $count }} {{ $class }}</span>
                                @endforeach
                                @if($healthCount)
                                    <span class="px-2 py-0.5 rounded-md text-xs font-bold bg-amber-100 text-amber-900">{{ $healthCount }} health {{ Str::plural('note', $healthCount) }}</span>
                                @endif
                                @if($nonSwimmers)
                                    <span class="px-2 py-0.5 rounded-md text-xs font-bold bg-rose-50 text-rose-700">{{ $nonSwimmers }} non-{{ Str::plural('swimmer', $nonSwimmers) }}</span>
                                @endif
                                @if($minors)
                                    <span class="px-2 py-0.5 rounded-md text-xs font-bold bg-amber-50 text-amber-800">{{ $minors }} under 18</span>
                                @endif
                            </div>
                        </div>
                    </div>

                    <!-- Actions -->
                    <div class="flex items-center gap-2 shrink-0 self-start md:self-center" @click.stop>
                        @if($releaseReq)
                            <span class="px-3 py-2 rounded-xl bg-amber-50 text-amber-800 border border-amber-300 text-sm font-bold">Release requested</span>
                        @elseif($item['can_request_release'])
                            <button type="button"
                                    @click="selectedBatch = {{ json_encode($item) }}; submittingRelease = false; releaseModalOpen = true"
                                    class="px-3 py-2 rounded-xl text-rose-700 hover:bg-rose-50 text-sm font-bold transition-colors cursor-pointer">
                                Request release
                            </button>
                        @endif
                        <button type="button" @click="toggleBatch({{ $batch->id }})"
                                :aria-expanded="isBatchOpen({{ $batch->id }})" aria-controls="roster-{{ $batch->id }}"
                                class="btn-secondary px-3.5 py-2 text-sm font-bold flex items-center gap-1.5">
                            <span x-text="isBatchOpen({{ $batch->id }}) ? 'Hide participants' : 'Show participants'"></span>
                            <svg class="w-4 h-4 transition-transform" :class="isBatchOpen({{ $batch->id }}) ? 'rotate-180' : ''" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><polyline points="6 9 12 15 18 9"></polyline></svg>
                        </button>
                    </div>
                </div>

                <!-- Roster + weather details -->
                <div x-show="isBatchOpen({{ $batch->id }})" x-cloak id="roster-{{ $batch->id }}" class="border-t border-[#E5E5EA]">
                    <div class="overflow-x-auto">
                        <table class="w-full text-left min-w-[760px]">
                            <thead class="bg-[#F2F2F7] border-b border-[#E5E5EA] text-sm uppercase font-bold text-[#6E6E73]">
                                <tr>
                                    <th class="p-4 pl-5">Participant</th>
                                    <th class="p-4">Age</th>
                                    <th class="p-4">Class</th>
                                    <th class="p-4">Swimming</th>
                                    <th class="p-4">Health notes</th>
                                    <th class="p-4 pr-5">Booking contact</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-[#E5E5EA]">
                                @foreach($students as $s)
                                    @php [$swimLabel, $swimClass] = $swimBadge($s->swimmer_status); @endphp
                                    <tr class="hover:bg-[#F2F2F7] transition-colors">
                                        <td class="p-4 pl-5">
                                            <div class="font-bold text-[#1D1D1F]">{{ $s->name }}</div>
                                            <x-participant-history-badge :bp="$s" class="mt-1" />
                                        </td>
                                        <td class="p-4 whitespace-nowrap">
                                            <span class="font-semibold text-[#1D1D1F]">{{ $s->age }} yrs</span>
                                            @if($s->age !== null && $s->age < 18)
                                                <span class="block text-xs font-bold text-amber-800">Under 18</span>
                                            @endif
                                        </td>
                                        <td class="p-4 font-semibold text-[#1D1D1F]">{{ ucfirst($s->booking?->class_type ?? '—') }}</td>
                                        <td class="p-4"><span class="px-2 py-0.5 rounded text-sm font-bold whitespace-nowrap {{ $swimClass }}">{{ $swimLabel }}</span></td>
                                        <td class="p-4 max-w-[220px]">
                                            @if($hasHealthNote($s))
                                                <span class="inline-block px-2 py-0.5 rounded text-sm font-bold bg-amber-100 text-amber-900">{{ $s->health_condition }}</span>
                                            @else
                                                <span class="text-[#8E8E93]">None declared</span>
                                            @endif
                                        </td>
                                        <td class="p-4 pr-5">
                                            <div class="font-semibold text-[#1D1D1F]">{{ $s->booking?->contact_name ?? '—' }}</div>
                                            @if($s->booking?->contact_phone)
                                                <a href="tel:{{ $s->booking->contact_phone }}" class="text-sm font-bold text-[#780000] hover:underline">{{ $s->booking->contact_phone }}</a>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="p-4 sm:p-5 border-t border-[#E5E5EA] bg-[#FAFAFA]">
                        <x-dive-safety.status :classification="$item['weather_class']"
                                              :description="\App\Services\WeatherForecastService::MEANING_MAP[$item['weather_class']] ?? ($item['assessment']?->recommended_action ?? null)"
                                              :engines="$item['model_comparison'] ?? null" />
                    </div>
                </div>
            </div>
        @empty
            <div class="bg-white rounded-xl p-10 border border-[#E5E5EA] text-center space-y-3 shadow-2xs">
                <h3 class="text-base font-bold text-[#1D1D1F]">No upcoming dives yet</h3>
                <p class="text-sm text-[#6E6E73] max-w-md mx-auto">You have no participants assigned for upcoming dates. Keep your availability calendar up to date, or check the Open Slot Requests board.</p>
                <a href="{{ route('coach.availability.index') }}" class="btn-primary px-4 py-2 text-sm font-bold inline-flex">Open availability calendar</a>
            </div>
        @endforelse
    </div>

    <!-- ============ PAST DIVES ============ -->
    <div x-show="activeTab === 'history'" x-cloak role="tabpanel" id="panel-history" aria-labelledby="tab-history" class="space-y-6">

        <!-- History metrics -->
        <div class="bg-white rounded-xl border border-[#E5E5EA] p-4 shadow-2xs">
            <div class="grid grid-cols-2 items-center gap-y-4">
                <div class="px-4 py-1">
                    <span class="text-sm text-[#6E6E73] font-bold uppercase tracking-wider block">Batches coached</span>
                    <div class="text-2xl font-extrabold text-[#1D1D1F] mt-0.5">{{ $totalCompletedBatchesCount }}</div>
                </div>
                <div class="relative px-4 py-1">
                    <div class="absolute left-0 top-2 bottom-2 w-px bg-[#E5E5EA]"></div>
                    <span class="text-sm text-[#6E6E73] font-bold uppercase tracking-wider block">Participants coached</span>
                    <div class="text-2xl font-extrabold text-[#780000] mt-0.5">{{ $totalPastStudentsCount }}</div>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-xl border border-[#E5E5EA] overflow-hidden shadow-2xs">
            <!-- Table Toolbar Header -->
            <div class="p-3 sm:p-4 border-b border-[#E5E5EA]">
                <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-3">
                    <div class="flex items-center gap-1.5 overflow-x-auto pb-1 lg:pb-0 scrollbar-none">
                        @foreach(['' => 'All Classes', 'discovery' => 'Discovery', 'fundive' => 'Fundive', 'refinement' => 'Refinement'] as $value => $label)
                            <a href="{{ request()->fullUrlWithQuery(['class_type' => $value, 'tab' => 'history']) }}"
                               class="px-3 py-1.5 rounded-lg text-sm font-bold transition-all shrink-0 {{ (string) request('class_type') === $value ? 'bg-[#780000] text-white shadow-2xs' : 'bg-white text-[#6E6E73] hover:text-[#1D1D1F] hover:bg-[#F2F2F7] border border-[#E5E5EA]' }}">
                                {{ $label }}
                            </a>
                        @endforeach
                    </div>

                    <div class="relative shrink-0 self-end lg:self-auto" x-data="{ openFilters: false }">
                        <button type="button" @click="openFilters = !openFilters"
                                class="btn-secondary flex items-center justify-center gap-1.5 px-3 py-1.5 text-sm font-semibold whitespace-nowrap cursor-pointer">
                            <img src="{{ asset('icons/icons8-filter-60.png') }}" alt="Filter" class="w-4.5 h-4.5 object-contain inline-block shrink-0">
                            <span>Filter</span>
                            @if(request()->anyFilled(['date_from', 'date_to']))
                                <span class="w-2 h-2 rounded-full bg-[#780000] shrink-0"></span>
                            @endif
                        </button>
                        <div x-show="openFilters" @click.outside="openFilters = false" x-cloak
                             x-transition:enter="transition ease-out duration-150 transform"
                             x-transition:enter-start="opacity-0 scale-95 -translate-y-1"
                             x-transition:enter-end="opacity-100 scale-100 translate-y-0"
                             class="popover-panel absolute right-0 mt-2 space-y-3">
                            <div class="flex items-center justify-between">
                                <h4 class="popover-title">Filter Past Dives</h4>
                                <a href="{{ route('coach.schedule.index', ['tab' => 'history']) }}" class="popover-reset">Reset</a>
                            </div>
                            <form method="GET" action="{{ route('coach.schedule.index') }}" class="space-y-3 text-sm">
                                <input type="hidden" name="tab" value="history">
                                @if(request('class_type'))
                                    <input type="hidden" name="class_type" value="{{ request('class_type') }}">
                                @endif
                                <div>
                                    <label class="block font-bold text-[#6E6E73] text-sm mb-1">Dive Date From</label>
                                    <x-date-picker name="date_from" :value="request('date_from')" placeholder="Any date" />
                                </div>
                                <div>
                                    <label class="block font-bold text-[#6E6E73] text-sm mb-1">Dive Date To</label>
                                    <x-date-picker name="date_to" :value="request('date_to')" placeholder="Any date" />
                                </div>
                                <div class="pt-2 border-t border-[#E5E5EA]">
                                    <button type="submit" class="btn-primary w-full py-2 text-sm font-bold shadow-2xs">Apply Filter</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left min-w-[720px]">
                    <thead class="bg-[#F2F2F7] border-b border-[#E5E5EA] text-sm uppercase font-bold text-[#6E6E73]">
                        <tr>
                            <th class="p-4 pl-6">Dive dates</th>
                            <th class="p-4">Batch</th>
                            <th class="p-4">Participants</th>
                            <th class="p-4 pr-6 text-right">Roster</th>
                        </tr>
                    </thead>
                    @forelse($historyBatches as $item)
                        @php $batch = $item['batch']; $key = 'hist_' . $batch->id; @endphp
                        <tbody class="border-b border-[#E5E5EA]">
                            <tr class="hover:bg-[#F2F2F7] cursor-pointer transition-colors" @click="toggleBatch('{{ $key }}')">
                                <td class="p-4 pl-6 whitespace-nowrap">
                                    <strong class="text-[#1D1D1F] block">{{ $batch->formatted_date_range }}</strong>
                                    <span class="text-xs text-[#8E8E93]">{{ $batch->start_date->format('D') }}–{{ $batch->end_date->format('D') }}</span>
                                </td>
                                <td class="p-4 font-bold text-[#1D1D1F]">{{ $batch->batch_number }}</td>
                                <td class="p-4">
                                    <span class="font-semibold text-[#1D1D1F]">{{ $item['students_count'] }} {{ Str::plural('participant', $item['students_count']) }}</span>
                                    <span class="block text-sm text-[#6E6E73]">{{ collect($item['class_counts'])->map(fn ($n, $c) => "{$n} {$c}")->implode(', ') }}</span>
                                </td>
                                <td class="p-4 pr-6 text-right">
                                    <span class="text-sm font-bold text-[#780000]" x-text="isBatchOpen('{{ $key }}') ? 'Hide' : 'Show'"></span>
                                </td>
                            </tr>
                            <tr x-show="isBatchOpen('{{ $key }}')" x-cloak>
                                <td colspan="4" class="p-0 bg-[#FAFAFA]">
                                    <table class="w-full text-left">
                                        <tbody class="divide-y divide-[#E5E5EA]">
                                            @foreach($item['students'] as $s)
                                                @php [$swimLabel, $swimClass] = $swimBadge($s->swimmer_status); @endphp
                                                <tr>
                                                    <td class="py-3 pl-10 pr-4">
                                                        <span class="font-bold text-[#1D1D1F]">{{ $s->name }}</span>
                                                        <x-participant-history-badge :bp="$s" class="ml-1" />
                                                    </td>
                                                    <td class="py-3 px-4 whitespace-nowrap">{{ $s->age }} yrs</td>
                                                    <td class="py-3 px-4 font-semibold">{{ ucfirst($s->booking?->class_type ?? '—') }}</td>
                                                    <td class="py-3 px-4"><span class="px-2 py-0.5 rounded text-sm font-bold whitespace-nowrap {{ $swimClass }}">{{ $swimLabel }}</span></td>
                                                    <td class="py-3 px-4 pr-6">
                                                        @if($hasHealthNote($s))
                                                            <span class="inline-block px-2 py-0.5 rounded text-sm font-bold bg-amber-100 text-amber-900">{{ $s->health_condition }}</span>
                                                        @else
                                                            <span class="text-[#8E8E93]">None declared</span>
                                                        @endif
                                                    </td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </td>
                            </tr>
                        </tbody>
                    @empty
                        <tbody>
                            <tr><td colspan="4" class="p-8 text-center text-[#6E6E73]">No past dives found for the selected filters.</td></tr>
                        </tbody>
                    @endforelse
                </table>
            </div>
        </div>
    </div>

    <!-- Emergency Release Panel -->
    <div x-show="releaseModalOpen"
         x-cloak
         role="dialog"
         aria-modal="true"
         aria-labelledby="schedule-release-modal-title"
         @keydown.escape.window="releaseModalOpen = false"
         class="fixed inset-0 z-50 bg-black/40 backdrop-blur-sm flex justify-end"
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-150"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0">
        <div class="dive-side-panel h-full overflow-y-auto overscroll-contain bg-white sm:max-w-lg w-full p-5 sm:p-6 shadow-2xl border-l border-[#E5E5EA] space-y-6 relative"
             @click.outside="releaseModalOpen = false">

            <div class="flex items-start justify-between border-b border-[#E5E5EA] pb-4">
                <div>
                    <h3 id="schedule-release-modal-title" class="text-lg font-black text-[#1D1D1F]">Request Assignment Release</h3>
                    <p class="text-sm font-semibold text-rose-600 mt-0.5">Emergency staffing request</p>
                </div>
                <button type="button" @click="releaseModalOpen = false" aria-label="Close release panel"
                        class="w-11 h-11 -mr-2 -mt-1 rounded-full flex items-center justify-center text-[#6E6E73] hover:text-[#1D1D1F] hover:bg-[#F2F2F7] transition-all cursor-pointer">
                    <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path d="M18 6L6 18M6 6l12 12"/></svg>
                </button>
            </div>

            <template x-if="selectedBatch">
                <form action="{{ route('coach.availability.release') }}" method="POST" @submit="submittingRelease = true" class="space-y-4">
                    @csrf
                    <input type="hidden" name="batch_id" :value="selectedBatch.batch?.id">
                    <input type="hidden" name="dive_date" :value="selectedBatch.dive_date">

                    <div class="p-3.5 rounded-xl bg-[#F2F2F7] text-sm space-y-1">
                        <div class="font-bold text-[#1D1D1F]" x-text="selectedBatch.batch?.name || selectedBatch.batch?.batch_number"></div>
                        <div class="text-[#6E6E73]">Participants assigned: <strong x-text="selectedBatch.students_count"></strong></div>
                    </div>

                    <div class="space-y-1.5">
                        <label for="schedule-release-reason" class="block text-sm font-bold text-[#1D1D1F]">Reason for emergency release <span class="text-rose-500">*</span></label>
                        <textarea id="schedule-release-reason" name="reason" rows="4" required
                                  placeholder="Please explain the emergency or unavoidable circumstance..."
                                  class="w-full text-sm rounded-xl border border-[#D1D1D6] focus:border-[#780000] focus:ring-[#780000] p-3"></textarea>
                    </div>

                    <div class="flex items-center justify-end gap-2.5 pt-2">
                        <button type="button" @click="releaseModalOpen = false" class="btn-secondary px-4 py-2 text-sm font-semibold rounded-xl cursor-pointer">Cancel</button>
                        <button type="submit" :disabled="submittingRelease"
                                class="btn-danger px-5 py-2 text-sm font-bold rounded-xl cursor-pointer disabled:opacity-50 disabled:cursor-not-allowed flex items-center gap-2">
                            <span x-show="!submittingRelease">Submit release request</span>
                            <span x-show="submittingRelease" x-cloak>Submitting...</span>
                        </button>
                    </div>
                </form>
            </template>
        </div>
    </div>
</div>
@endsection
