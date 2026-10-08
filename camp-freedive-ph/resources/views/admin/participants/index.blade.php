@extends('layouts.admin')

@section('title', 'Participants | Camp FreedivePH')

@section('content')
@php
    $tabs = [
        'all' => ['All participants', $stats['total']],
        'duplicates' => ['Possible duplicates', $reviews->count()],
        'retention' => ['Due for anonymisation', $dueForAnonymisation->count()],
    ];
    $classLabels = ['discovery' => 'Discovery', 'fundive' => 'Fundive', 'refinement' => 'Refinement'];
@endphp
<div class="space-y-6 text-sm">

    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl sm:text-3xl font-extrabold text-[#1D1D1F] tracking-tight">Participants</h1>
            <p class="text-sm text-[#6E6E73] mt-1">Everyone who has booked, with their history across bookings.</p>
        </div>
        <a href="{{ portal_route('participants.export', request()->query()) }}" data-native class="btn-secondary px-4 py-2 text-sm font-semibold self-start sm:self-auto">Export CSV</a>
    </div>

    <!-- Totals -->
    <div class="bg-white rounded-xl border border-[#E5E5EA] p-4 shadow-2xs grid grid-cols-3 gap-y-4">
        <div class="px-4 py-1">
            <span class="text-sm text-[#6E6E73] font-bold uppercase tracking-wider block">Participants</span>
            <div class="text-2xl font-extrabold text-[#1D1D1F] mt-0.5">{{ $stats['total'] }}</div>
        </div>
        <div class="relative px-4 py-1">
            <div class="absolute left-0 top-2 bottom-2 w-px bg-[#E5E5EA]"></div>
            <span class="text-sm text-[#6E6E73] font-bold uppercase tracking-wider block">2+ bookings</span>
            <div class="text-2xl font-extrabold text-[#1D1D1F] mt-0.5">{{ $stats['repeat'] }}</div>
        </div>
        <div class="relative px-4 py-1">
            <div class="absolute left-0 top-2 bottom-2 w-px bg-[#E5E5EA]"></div>
            <span class="text-sm text-[#6E6E73] font-bold uppercase tracking-wider block">Under 18</span>
            <div class="text-2xl font-extrabold text-[#1D1D1F] mt-0.5">{{ $stats['minors'] }}</div>
        </div>
    </div>

    <!-- Tabs -->
    <div class="flex flex-wrap gap-2 border-b border-[#E5E5EA]">
        @foreach($tabs as $key => [$label, $count])
            <a href="{{ portal_route('participants.index', ['tab' => $key]) }}"
               class="px-3.5 py-2 -mb-px border-b-2 font-semibold {{ $tab === $key ? 'border-[#780000] text-[#780000]' : 'border-transparent text-[#6E6E73] hover:text-[#1D1D1F]' }}">
                {{ $label }}
                <span class="ml-1 px-1.5 py-0.5 rounded-md text-xs font-bold {{ $tab === $key ? 'bg-[#780000] text-white' : 'bg-[#F2F2F7] text-[#6E6E73]' }}">{{ $count }}</span>
            </a>
        @endforeach
    </div>

    @if($tab === 'all')
        <!-- Search and filters -->
        <form method="GET" action="{{ portal_route('participants.index') }}" class="bg-white rounded-xl border border-[#E5E5EA] p-4 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-6 gap-3 items-end">
            <input type="hidden" name="tab" value="all">
            <label class="lg:col-span-2">
                <span class="block text-xs font-bold text-[#6E6E73] mb-1">Search</span>
                <input type="search" name="search" value="{{ request('search') }}" placeholder="Name, email or phone" class="w-full px-3 py-2 rounded-xl border border-[#D1D1D6]">
            </label>
            <label>
                <span class="block text-xs font-bold text-[#6E6E73] mb-1">Class taken</span>
                <select name="class_type" class="w-full px-3 py-2 rounded-xl border border-[#D1D1D6] font-medium">
                    <option value="">Any class</option>
                    @foreach($classLabels as $value => $label)
                        <option value="{{ $value }}" @selected(request('class_type') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>
            <label>
                <span class="block text-xs font-bold text-[#6E6E73] mb-1">History</span>
                <select name="history" class="w-full px-3 py-2 rounded-xl border border-[#D1D1D6] font-medium">
                    <option value="">Everyone</option>
                    <option value="repeat" @selected(request('history') === 'repeat')>2 or more bookings</option>
                    <option value="first" @selected(request('history') === 'first')>First booking only</option>
                </select>
            </label>
            <label>
                <span class="block text-xs font-bold text-[#6E6E73] mb-1">Age group</span>
                <select name="age_group" class="w-full px-3 py-2 rounded-xl border border-[#D1D1D6] font-medium">
                    <option value="">All ages</option>
                    <option value="adult" @selected(request('age_group') === 'adult')>18 and over</option>
                    <option value="minor" @selected(request('age_group') === 'minor')>Under 18</option>
                </select>
            </label>
            <label>
                <span class="block text-xs font-bold text-[#6E6E73] mb-1">Sort by</span>
                <select name="sort" class="w-full px-3 py-2 rounded-xl border border-[#D1D1D6] font-medium">
                    <option value="">Last dive (newest)</option>
                    <option value="dives" @selected(request('sort') === 'dives')>Most bookings</option>
                    <option value="name" @selected(request('sort') === 'name')>Name</option>
                </select>
            </label>
            <label>
                <span class="block text-xs font-bold text-[#6E6E73] mb-1">Last dive from</span>
                <input type="date" name="from" value="{{ request('from') }}" class="w-full px-3 py-2 rounded-xl border border-[#D1D1D6]">
            </label>
            <label>
                <span class="block text-xs font-bold text-[#6E6E73] mb-1">Last dive to</span>
                <input type="date" name="to" value="{{ request('to') }}" class="w-full px-3 py-2 rounded-xl border border-[#D1D1D6]">
            </label>
            <div class="flex gap-2 lg:col-span-2">
                <button type="submit" class="btn-primary px-4 py-2 font-bold">Apply</button>
                <a href="{{ portal_route('participants.index') }}" class="btn-secondary px-4 py-2 font-semibold">Clear</a>
            </div>
        </form>

        <div class="bg-white rounded-xl border border-[#E5E5EA] overflow-x-auto">
            <table class="w-full text-left min-w-[860px]">
                <thead class="bg-[#F2F2F7] border-b border-[#E5E5EA] text-sm uppercase font-bold text-[#6E6E73]">
                    <tr>
                        <th class="p-4 pl-6">Name</th>
                        <th class="p-4">Age / Gender</th>
                        <th class="p-4">Swimmer</th>
                        <th class="p-4">Classes taken</th>
                        <th class="p-4">Bookings</th>
                        <th class="p-4 pr-6">Last dive</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[#E5E5EA]">
                    @forelse($participants as $p)
                        @php
                            $classes = $p->bookingParticipants->pluck('booking')->filter()
                                ->reject(fn ($b) => in_array($b->status, \App\Models\Participant::CANCELLED_STATUSES, true))
                                ->pluck('class_type')->unique();
                        @endphp
                        <tr onclick="window.location='{{ portal_route('participants.show', $p) }}'" class="hover:bg-[#F2F2F7] cursor-pointer transition-colors group">
                            <td class="p-4 pl-6">
                                <span class="font-bold text-[#1D1D1F] group-hover:text-[#780000]">{{ $p->full_name }}</span>
                                @if($p->bookings_count >= 2)
                                    <span class="ml-1 px-2 py-0.5 rounded-md text-xs font-bold bg-[#F8EAEA] text-[#780000]">{{ $p->bookings_count }} bookings</span>
                                @endif
                                @if($p->last_email)<div class="text-xs text-[#8E8E93]">{{ $p->last_email }}</div>@endif
                            </td>
                            <td class="p-4">{{ $p->age ?? '—' }}{{ $p->gender ? ' · ' . str_replace('_', ' ', ucfirst($p->gender)) : '' }}</td>
                            <td class="p-4">{{ $p->latest_swimmer_status ? ucwords(str_replace('_', ' ', $p->latest_swimmer_status)) : '—' }}</td>
                            <td class="p-4">{{ $classes->map(fn ($c) => $classLabels[$c] ?? ucfirst($c))->implode(', ') ?: '—' }}</td>
                            <td class="p-4 font-semibold">{{ $p->bookings_count }}</td>
                            <td class="p-4 pr-6 whitespace-nowrap">{{ $p->last_dive_date?->format('M d, Y') ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="p-8 text-center text-[#6E6E73]">No participants match your search or filters.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div>{{ $participants->links() }}</div>

    @elseif($tab === 'duplicates')
        <p class="text-[#6E6E73]">These records may be the same person. Merge them to combine their history, or mark them as different people.</p>
        <div class="space-y-3">
            @forelse($reviews as $review)
                @php $a = $review->participantA; $b = $review->participantB; @endphp
                <div class="bg-white rounded-xl border border-[#E5E5EA] p-4 space-y-3">
                    <div class="text-xs font-bold text-[#92400E] uppercase tracking-wider">{{ $review->reason }}</div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        @foreach([$a, $b] as $person)
                            <a href="{{ portal_route('participants.show', $person) }}" class="rounded-xl border border-[#E5E5EA] p-3 hover:bg-[#F2F2F7]">
                                <div class="font-bold text-[#1D1D1F]">{{ $person->full_name }}</div>
                                <div class="text-xs text-[#6E6E73]">Born {{ $person->birthdate?->format('M d, Y') ?? 'unknown' }} · {{ $person->last_email ?? 'no email' }}</div>
                                <div class="text-xs text-[#6E6E73]">Last dive {{ $person->last_dive_date?->format('M d, Y') ?? '—' }}</div>
                            </a>
                        @endforeach
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <form method="POST" action="{{ portal_route('participants.merge', $a) }}" data-native>
                            @csrf
                            <input type="hidden" name="other_id" value="{{ $b->id }}">
                            <button class="btn-primary px-3.5 py-1.5 text-sm font-bold">Merge into {{ $a->full_name }}</button>
                        </form>
                        <form method="POST" action="{{ portal_route('participants.reviews.not_same', $review) }}" data-native>
                            @csrf
                            <button class="btn-secondary px-3.5 py-1.5 text-sm font-semibold">Not the same person</button>
                        </form>
                    </div>
                </div>
            @empty
                <div class="rounded-xl bg-[#00C3D0]/10 border border-[#00C3D0]/30 p-5 font-semibold text-[#00636A]">No possible duplicates to review.</div>
            @endforelse
        </div>

    @else
        <p class="text-[#6E6E73]">
            People with no booking for 3 years are anonymised on the 1st of each month (their bookings stay in reports without personal details).
            Health notes are cleared 12 months after the last dive. These records reach 3 years within the next 30 days.
        </p>
        <div class="bg-white rounded-xl border border-[#E5E5EA] overflow-x-auto">
            <table class="w-full text-left min-w-[600px]">
                <thead class="bg-[#F2F2F7] border-b border-[#E5E5EA] text-sm uppercase font-bold text-[#6E6E73]">
                    <tr><th class="p-4 pl-6">Name</th><th class="p-4">Last dive</th><th class="p-4 pr-6">Anonymised on</th></tr>
                </thead>
                <tbody class="divide-y divide-[#E5E5EA]">
                    @forelse($dueForAnonymisation as $p)
                        <tr onclick="window.location='{{ portal_route('participants.show', $p) }}'" class="hover:bg-[#F2F2F7] cursor-pointer">
                            <td class="p-4 pl-6 font-bold">{{ $p->full_name }}</td>
                            <td class="p-4">{{ $p->last_dive_date->format('M d, Y') }}</td>
                            <td class="p-4 pr-6">{{ $p->last_dive_date->copy()->addYears(\App\Services\ParticipantDirectoryService::RECORD_RETENTION_YEARS)->startOfMonth()->addMonth()->format('M d, Y') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="p-8 text-center text-[#6E6E73]">No records are due for anonymisation in the next 30 days.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endif
</div>
@endsection
