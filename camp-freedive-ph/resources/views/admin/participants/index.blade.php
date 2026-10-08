@extends('layouts.admin')

@section('title', 'Participants | Camp FreedivePH')

@if($tab !== 'all')
@section('breadcrumb')
    <a href="{{ portal_route('participants.index') }}" class="text-[#6E6E73] hover:text-[#780000] font-medium transition-colors">Participants</a>
    <svg class="w-3.5 h-3.5 text-[#8E8E93] shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m9 18 6-6-6-6"/></svg>
    <span class="font-bold text-[#1D1D1F]">{{ $tab === 'duplicates' ? 'Possible Duplicates' : 'Due for Anonymisation' }}</span>
@endsection
@endif

@section('content')
@php $classLabels = ['discovery' => 'Discovery', 'fundive' => 'Fundive', 'refinement' => 'Refinement']; @endphp
<div class="space-y-6 text-sm">

    <!-- Top Header -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl sm:text-3xl font-extrabold text-[#1D1D1F] tracking-tight">
                {{ $tab === 'duplicates' ? 'Possible Duplicates' : ($tab === 'retention' ? 'Due for Anonymisation' : 'Participants') }}
            </h1>
        </div>

        <div class="flex items-center gap-2.5 flex-wrap">
            @if($tab !== 'all')
                <a href="{{ portal_route('participants.index') }}" class="btn-secondary px-3.5 py-2 text-sm font-semibold">All Participants</a>
            @endif
            @if($tab !== 'duplicates')
                <a href="{{ portal_route('participants.index', ['tab' => 'duplicates']) }}" class="btn-secondary px-3.5 py-2 text-sm sm:text-sm font-semibold flex items-center gap-2">
                    <span>Possible Duplicates</span>
                    @if($reviews->count() > 0)
                        <span class="px-2 py-0.5 rounded-md text-sm font-bold bg-[#780000] text-white">{{ $reviews->count() }}</span>
                    @endif
                </a>
            @endif
            @if($tab !== 'retention')
                <a href="{{ portal_route('participants.index', ['tab' => 'retention']) }}" class="btn-secondary px-3.5 py-2 text-sm sm:text-sm font-semibold flex items-center gap-2">
                    <span>Due for Anonymisation</span>
                    @if($dueForAnonymisation->count() > 0)
                        <span class="px-2 py-0.5 rounded-md text-sm font-bold bg-[#780000] text-white">{{ $dueForAnonymisation->count() }}</span>
                    @endif
                </a>
            @endif
            @if($tab === 'all')
                <a href="{{ portal_route('participants.export', request()->query()) }}" data-native class="btn-primary px-4 py-2 text-sm sm:text-sm font-bold flex items-center gap-1.5 shadow-2xs">
                    <span>Export CSV</span>
                </a>
            @endif
        </div>
    </div>

    @if($tab === 'all')
        <!-- Participant Metrics -->
        <div class="bg-white rounded-xl border border-[#E5E5EA] p-4 shadow-2xs">
            <div class="grid grid-cols-1 sm:grid-cols-3 items-center gap-y-4">
                <div class="px-4 py-1">
                    <span class="text-sm text-[#6E6E73] font-bold uppercase tracking-wider block">Participants</span>
                    <div class="text-2xl font-extrabold text-[#1D1D1F] mt-0.5">{{ $stats['total'] }}</div>
                </div>
                <div class="relative px-4 py-1">
                    <div class="hidden sm:block absolute left-0 top-2 bottom-2 w-px bg-[#E5E5EA]"></div>
                    <span class="text-sm text-[#6E6E73] font-bold uppercase tracking-wider block">2+ Bookings</span>
                    <div class="text-2xl font-extrabold text-[#780000] mt-0.5">{{ $stats['repeat'] }}</div>
                </div>
                <div class="relative px-4 py-1">
                    <div class="hidden sm:block absolute left-0 top-2 bottom-2 w-px bg-[#E5E5EA]"></div>
                    <span class="text-sm text-[#6E6E73] font-bold uppercase tracking-wider block">Under 18</span>
                    <div class="text-2xl font-extrabold text-[#B45309] mt-0.5">{{ $stats['minors'] }}</div>
                </div>
            </div>
        </div>

        <!-- Participants Table -->
        <div class="bg-white rounded-xl border border-[#E5E5EA] overflow-hidden shadow-2xs">

        <!-- Table Toolbar Header -->
        <div class="p-3 sm:p-4 border-b border-[#E5E5EA]">
            <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-3">

                <!-- Class Package Tabs -->
                <div class="flex items-center gap-1.5 overflow-x-auto pb-1 lg:pb-0 scrollbar-none">
                    @foreach(['' => 'All Classes', 'discovery' => 'Discovery', 'fundive' => 'Fundive', 'refinement' => 'Refinement'] as $value => $label)
                        <a href="{{ request()->fullUrlWithQuery(['class_type' => $value, 'page' => null]) }}"
                           class="px-3 py-1.5 rounded-lg text-sm font-bold transition-all shrink-0 {{ (string) request('class_type') === $value ? 'bg-[#780000] text-white shadow-2xs' : 'bg-white text-[#6E6E73] hover:text-[#1D1D1F] hover:bg-[#F2F2F7] border border-[#E5E5EA]' }}">
                            {{ $label }}
                        </a>
                    @endforeach
                </div>

                <!-- Search and Filter Controls -->
                <div class="flex items-center gap-2 w-full lg:w-auto lg:ml-auto" x-data="{ openFilters: false }">
                    <form method="GET" action="{{ portal_route('participants.index') }}" class="flex-1 min-w-0 lg:flex-initial">
                        @foreach(['class_type', 'history', 'age_group', 'from', 'to', 'sort'] as $keep)
                            @if(request($keep))
                                <input type="hidden" name="{{ $keep }}" value="{{ request($keep) }}">
                            @endif
                        @endforeach

                        <div class="relative w-full sm:w-64">
                            <input type="text"
                                   name="search"
                                   value="{{ request('search') }}"
                                   placeholder="Search name, email, phone..."
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
                            @if(request()->anyFilled(['history', 'age_group', 'from', 'to', 'sort']))
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
                                <h4 class="popover-title">Filter & Sort Participants</h4>
                                <a href="{{ portal_route('participants.index') }}" class="popover-reset">Reset</a>
                            </div>

                            <form method="GET" action="{{ portal_route('participants.index') }}" class="space-y-3 text-sm">
                                @foreach(['class_type', 'search'] as $keep)
                                    @if(request($keep))
                                        <input type="hidden" name="{{ $keep }}" value="{{ request($keep) }}">
                                    @endif
                                @endforeach

                                <div>
                                    <label class="block font-bold text-[#6E6E73] text-sm mb-1">Sort By</label>
                                    <select name="sort" class="w-full px-3 py-2 rounded-xl border border-[#D1D1D6] text-sm font-medium">
                                        <option value="" {{ request('sort', '') === '' ? 'selected' : '' }}>Last Dive (Newest First)</option>
                                        <option value="dives" {{ request('sort', '') === 'dives' ? 'selected' : '' }}>Most Bookings First</option>
                                        <option value="name" {{ request('sort', '') === 'name' ? 'selected' : '' }}>Name (A–Z)</option>
                                    </select>
                                </div>

                                <div>
                                    <label class="block font-bold text-[#6E6E73] text-sm mb-1">Booking History</label>
                                    <select name="history" class="w-full px-3 py-2 rounded-xl border border-[#D1D1D6] text-sm font-medium">
                                        <option value="" {{ request('history', '') === '' ? 'selected' : '' }}>Everyone</option>
                                        <option value="repeat" {{ request('history', '') === 'repeat' ? 'selected' : '' }}>2 or More Bookings</option>
                                        <option value="first" {{ request('history', '') === 'first' ? 'selected' : '' }}>First Booking Only</option>
                                    </select>
                                </div>

                                <div>
                                    <label class="block font-bold text-[#6E6E73] text-sm mb-1">Age Group</label>
                                    <select name="age_group" class="w-full px-3 py-2 rounded-xl border border-[#D1D1D6] text-sm font-medium">
                                        <option value="" {{ request('age_group', '') === '' ? 'selected' : '' }}>All Ages</option>
                                        <option value="adult" {{ request('age_group', '') === 'adult' ? 'selected' : '' }}>18 and Over</option>
                                        <option value="minor" {{ request('age_group', '') === 'minor' ? 'selected' : '' }}>Under 18</option>
                                    </select>
                                </div>

                                <div>
                                    <label class="block font-bold text-[#6E6E73] text-sm mb-1">Last Dive From</label>
                                    <x-date-picker name="from" :value="request('from')" placeholder="Any date" />
                                </div>

                                <div>
                                    <label class="block font-bold text-[#6E6E73] text-sm mb-1">Last Dive To</label>
                                    <x-date-picker name="to" :value="request('to')" placeholder="Any date" />
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
                        <th class="p-4 pl-6">Participant</th>
                        <th class="p-4">Age / Gender</th>
                        <th class="p-4">Swimmer Status</th>
                        <th class="p-4">Classes Taken</th>
                        <th class="p-4">Bookings</th>
                        <th class="p-4 pr-6">Last Dive</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[#E5E5EA]">
                    @forelse($participants as $p)
                        @php
                            $classes = $p->bookingParticipants->pluck('booking')->filter()
                                ->reject(fn ($b) => in_array($b->status, \App\Models\Participant::CANCELLED_STATUSES, true))
                                ->pluck('class_type')->unique();
                        @endphp
                        <tr onclick="window.location='{{ portal_route('participants.show', $p) }}'" class="hover:bg-[#F2F2F7] cursor-pointer transition-colors text-sm group">
                            <td class="p-4 pl-6">
                                <div class="font-bold text-[#1D1D1F] group-hover:text-[#780000] group-hover:underline">{{ $p->full_name }}</div>
                                <div class="text-sm text-[#6E6E73]">{{ $p->last_email ?? $p->last_phone ?? '—' }}</div>
                            </td>
                            <td class="p-4">
                                <span class="font-semibold text-[#1D1D1F] block">{{ $p->age !== null ? $p->age . ' yrs' : '—' }}</span>
                                <span class="text-sm text-[#6E6E73] block">{{ $p->gender ? ucwords(str_replace('_', ' ', $p->gender)) : '' }}</span>
                            </td>
                            <td class="p-4">{{ $p->latest_swimmer_status ? ucwords(str_replace('_', ' ', $p->latest_swimmer_status)) : '—' }}</td>
                            <td class="p-4 font-semibold text-[#1D1D1F]">{{ $classes->map(fn ($c) => $classLabels[$c] ?? ucfirst($c))->implode(', ') ?: '—' }}</td>
                            <td class="p-4">
                                <span class="px-2 py-0.5 rounded text-sm font-bold {{ $p->bookings_count >= 2 ? 'bg-[#F8EAEA] text-[#780000]' : 'bg-[#F2F2F7] text-[#6E6E73]' }}">
                                    {{ $p->bookings_count }} {{ Str::plural('booking', $p->bookings_count) }}
                                </span>
                            </td>
                            <td class="p-4 pr-6 whitespace-nowrap">
                                @if($p->last_dive_date)
                                    <strong class="text-[#1D1D1F] block">{{ $p->last_dive_date->format('M d, Y') }}</strong>
                                    <span class="text-xs text-[#8E8E93] block mt-0.5">{{ $p->last_dive_date->format('D') }}</span>
                                @else
                                    <span class="text-sm text-[#8E8E93] italic">No dives yet</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="p-8 text-center text-[#6E6E73]">No participants found matching your search or filters.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
            </div>
            {{ $participants->links() }}
        </div>

    @elseif($tab === 'duplicates')
        <div class="bg-white rounded-xl border border-[#E5E5EA] overflow-hidden shadow-2xs">
            <div class="p-3 sm:p-4 border-b border-[#E5E5EA] text-[#6E6E73]">
                These records may be the same person. Merge them to combine their history, or mark them as different people.
            </div>
            <div class="overflow-x-auto">
            <table class="w-full text-left min-w-[900px]">
                <thead class="bg-[#F2F2F7] border-b border-[#E5E5EA] text-sm uppercase font-bold text-[#6E6E73]">
                    <tr>
                        <th class="p-4 pl-6">Record A</th>
                        <th class="p-4">Record B</th>
                        <th class="p-4">Why They Match</th>
                        <th class="p-4 pr-6 text-right">Decision</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[#E5E5EA]">
                    @forelse($reviews as $review)
                        @php $a = $review->participantA; $b = $review->participantB; @endphp
                        <tr class="text-sm">
                            @foreach([$a, $b] as $i => $person)
                                <td class="p-4 {{ $i === 0 ? 'pl-6' : '' }}">
                                    <a href="{{ portal_route('participants.show', $person) }}" class="font-bold text-[#1D1D1F] hover:text-[#780000] hover:underline">{{ $person->full_name }}</a>
                                    <div class="text-sm text-[#6E6E73]">Born {{ $person->birthdate?->format('M d, Y') ?? 'unknown' }}</div>
                                    <div class="text-xs text-[#8E8E93]">{{ $person->last_email ?? 'No email' }} · last dive {{ $person->last_dive_date?->format('M d, Y') ?? '—' }}</div>
                                </td>
                            @endforeach
                            <td class="p-4">
                                <span class="px-2 py-0.5 rounded text-sm font-bold bg-amber-50 text-amber-800">{{ $review->reason }}</span>
                            </td>
                            <td class="p-4 pr-6">
                                <div class="flex items-center justify-end gap-2 flex-wrap">
                                    <form method="POST" action="{{ portal_route('participants.merge', $a) }}" data-native>
                                        @csrf
                                        <input type="hidden" name="other_id" value="{{ $b->id }}">
                                        <button class="btn-primary px-3 py-1.5 text-sm font-bold shadow-2xs">Merge</button>
                                    </form>
                                    <form method="POST" action="{{ portal_route('participants.reviews.not_same', $review) }}" data-native>
                                        @csrf
                                        <button class="btn-secondary px-3 py-1.5 text-sm font-semibold">Not the Same</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="p-8 text-center text-[#6E6E73]">No possible duplicates to review.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
            </div>
        </div>

    @else
        <div class="bg-white rounded-xl border border-[#E5E5EA] overflow-hidden shadow-2xs">
            <div class="p-3 sm:p-4 border-b border-[#E5E5EA] text-[#6E6E73]">
                Records are anonymised on the 1st of each month once a participant has had no booking for 3 years; their bookings stay in reports without personal details.
                Health notes are cleared 12 months after the last dive. These records reach 3 years within the next 30 days.
            </div>
            <div class="overflow-x-auto">
            <table class="w-full text-left min-w-[640px]">
                <thead class="bg-[#F2F2F7] border-b border-[#E5E5EA] text-sm uppercase font-bold text-[#6E6E73]">
                    <tr>
                        <th class="p-4 pl-6">Participant</th>
                        <th class="p-4">Last Dive</th>
                        <th class="p-4 pr-6">Anonymised On</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[#E5E5EA]">
                    @forelse($dueForAnonymisation as $p)
                        <tr onclick="window.location='{{ portal_route('participants.show', $p) }}'" class="hover:bg-[#F2F2F7] cursor-pointer transition-colors text-sm group">
                            <td class="p-4 pl-6">
                                <div class="font-bold text-[#1D1D1F] group-hover:text-[#780000] group-hover:underline">{{ $p->full_name }}</div>
                                <div class="text-sm text-[#6E6E73]">{{ $p->last_email ?? '—' }}</div>
                            </td>
                            <td class="p-4 font-semibold text-[#1D1D1F]">{{ $p->last_dive_date->format('M d, Y') }}</td>
                            <td class="p-4 pr-6">
                                <span class="px-2 py-0.5 rounded text-sm font-bold bg-rose-50 text-rose-700">
                                    {{ $p->last_dive_date->copy()->addYears(\App\Services\ParticipantDirectoryService::RECORD_RETENTION_YEARS)->startOfMonth()->addMonth()->format('M d, Y') }}
                                </span>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="3" class="p-8 text-center text-[#6E6E73]">No records are due for anonymisation in the next 30 days.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
            </div>
        </div>
    @endif
</div>
@endsection
