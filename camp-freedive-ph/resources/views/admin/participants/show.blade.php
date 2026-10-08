@extends('layouts.admin')

@section('title', $participant->full_name . ' | Participants | Camp FreedivePH')

@section('breadcrumb')
    <a href="{{ portal_route('participants.index') }}" class="text-[#6E6E73] hover:text-[#780000] font-medium transition-colors">Participants</a>
    <svg class="w-3.5 h-3.5 text-[#8E8E93] shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m9 18 6-6-6-6"/></svg>
    <span class="font-bold text-[#1D1D1F]">{{ $participant->full_name }}</span>
@endsection

@section('content')
@php
    $label = fn ($v) => $v ? ucwords(str_replace('_', ' ', $v)) : '—';
    $activeCount = $history->filter(fn ($row) => $row->booking && !in_array($row->booking->status, \App\Models\Participant::CANCELLED_STATUSES, true))->count();
@endphp
<div class="max-w-5xl mx-auto space-y-6 text-sm" x-data="{ editing: {{ $errors->any() ? 'true' : 'false' }} }">

    @if($errors->any())
        <div class="banner banner-error" role="alert">
            <ul class="list-disc pl-5">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
        </div>
    @endif

    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl sm:text-3xl font-extrabold text-[#1D1D1F] tracking-tight">{{ $participant->full_name }}</h1>
            <p class="text-sm text-[#6E6E73] mt-1">
                {{ $activeCount }} {{ Str::plural('booking', $activeCount) }}
                @if($participant->last_dive_date) · last dive {{ $participant->last_dive_date->format('M d, Y') }} @endif
                @if($participant->isMinor()) · <span class="font-bold text-[#92400E]">Under 18</span> @endif
            </p>
        </div>
        <button type="button" @click="editing = !editing" class="btn-secondary px-4 py-2 text-sm font-semibold self-start sm:self-auto" x-text="editing ? 'Cancel editing' : 'Edit details'"></button>
    </div>

    <!-- Details -->
    <div class="bg-white rounded-2xl border border-[#E5E5EA] p-5 sm:p-6">
        <div x-show="!editing" class="grid grid-cols-2 sm:grid-cols-3 gap-4">
            <div><span class="text-xs font-bold text-[#8E8E93] uppercase tracking-wider block">Birthdate</span>{{ $participant->birthdate?->format('M d, Y') ?? '—' }}{{ $participant->age !== null ? " ({$participant->age})" : '' }}</div>
            <div><span class="text-xs font-bold text-[#8E8E93] uppercase tracking-wider block">Gender</span>{{ $label($participant->gender) }}</div>
            <div><span class="text-xs font-bold text-[#8E8E93] uppercase tracking-wider block">Swimmer status</span>{{ $label($participant->latest_swimmer_status) }}</div>
            <div class="col-span-2 sm:col-span-3">
                <span class="text-xs font-bold text-[#8E8E93] uppercase tracking-wider block">Latest health notes</span>
                {{ $participant->latest_health_condition ?: 'None declared' }}
                @if($participant->health_updated_at)
                    <span class="text-xs text-[#8E8E93]">(given {{ $participant->health_updated_at->format('M d, Y') }})</span>
                @endif
            </div>
            <div><span class="text-xs font-bold text-[#8E8E93] uppercase tracking-wider block">Last booking contact</span>{{ $participant->last_email ?? '—' }}<br>{{ $participant->last_phone }}</div>
            @if($guardianBooking)
                <div class="col-span-2 rounded-xl border border-[#FDE68A] bg-[#FFFBEB] px-3.5 py-2.5">
                    <span class="text-xs font-bold text-[#92400E] uppercase tracking-wider block">Guardian on file (latest booking)</span>
                    {{ $guardianBooking->guardian_name }} · {{ $guardianBooking->guardian_relationship === 'legal_guardian' ? 'Legal guardian' : 'Parent' }} · {{ $guardianBooking->guardian_phone }}
                    <span class="block text-xs text-[#6E6E73]">A new booking still needs fresh consent.</span>
                </div>
            @endif
        </div>

        <form x-show="editing" x-cloak method="POST" action="{{ portal_route('participants.update', $participant) }}" class="grid grid-cols-1 sm:grid-cols-2 gap-3" data-native>
            @csrf
            @method('PUT')
            <label class="sm:col-span-2"><span class="block text-xs font-bold text-[#6E6E73] mb-1">Full name</span>
                <input type="text" name="full_name" value="{{ old('full_name', $participant->full_name) }}" required class="w-full px-3 py-2 rounded-xl border border-[#D1D1D6]"></label>
            <label><span class="block text-xs font-bold text-[#6E6E73] mb-1">Birthdate</span>
                <input type="date" name="birthdate" value="{{ old('birthdate', $participant->birthdate?->format('Y-m-d')) }}" max="{{ now()->toDateString() }}" class="w-full px-3 py-2 rounded-xl border border-[#D1D1D6]"></label>
            <label><span class="block text-xs font-bold text-[#6E6E73] mb-1">Gender</span>
                <select name="gender" class="w-full px-3 py-2 rounded-xl border border-[#D1D1D6] font-medium">
                    <option value="">Not given</option>
                    @foreach(['female' => 'Female', 'male' => 'Male', 'non_binary' => 'Non-binary', 'prefer_not_to_say' => 'Prefer not to say'] as $v => $l)
                        <option value="{{ $v }}" @selected(old('gender', $participant->gender) === $v)>{{ $l }}</option>
                    @endforeach
                </select></label>
            <label><span class="block text-xs font-bold text-[#6E6E73] mb-1">Swimmer status</span>
                <select name="latest_swimmer_status" class="w-full px-3 py-2 rounded-xl border border-[#D1D1D6] font-medium">
                    <option value="">Not given</option>
                    @foreach(['non_swimmer' => 'Non-swimmer', 'casual_swimmer' => 'Casual / beginner swimmer', 'swimmer' => 'Confident swimmer'] as $v => $l)
                        <option value="{{ $v }}" @selected(old('latest_swimmer_status', $participant->latest_swimmer_status) === $v)>{{ $l }}</option>
                    @endforeach
                </select></label>
            <label class="sm:col-span-2"><span class="block text-xs font-bold text-[#6E6E73] mb-1">Latest health notes</span>
                <textarea name="latest_health_condition" rows="2" class="w-full px-3 py-2 rounded-xl border border-[#D1D1D6]">{{ old('latest_health_condition', $participant->latest_health_condition) }}</textarea></label>
            <p class="sm:col-span-2 text-xs text-[#8E8E93]">Changes here update the directory record only; past bookings keep the details given at the time. Every change is logged.</p>
            <div class="sm:col-span-2"><button type="submit" class="btn-primary px-5 py-2 font-bold">Save details</button></div>
        </form>
    </div>

    <!-- History -->
    <div class="bg-white rounded-2xl border border-[#E5E5EA] overflow-x-auto">
        <div class="p-5 pb-3"><h2 class="text-base font-extrabold text-[#1D1D1F]">Booking history</h2></div>
        <table class="w-full text-left min-w-[760px]">
            <thead class="bg-[#F2F2F7] border-y border-[#E5E5EA] text-sm uppercase font-bold text-[#6E6E73]">
                <tr><th class="p-4 pl-5">Trip</th><th class="p-4">Booking</th><th class="p-4">Class</th><th class="p-4">Batch</th><th class="p-4">Coach</th><th class="p-4">Status</th><th class="p-4 pr-5">Paid</th></tr>
            </thead>
            <tbody class="divide-y divide-[#E5E5EA]">
                @forelse($history as $row)
                    @php
                        $b = $row->booking;
                        $coaches = $row->assignments->sortByDesc('id')->pluck('coach.name')->filter()->unique();
                    @endphp
                    <tr>
                        <td class="p-4 pl-5 whitespace-nowrap font-semibold">{{ $b?->start_date?->format('M d, Y') ?? '—' }}</td>
                        <td class="p-4">@if($b)<a href="{{ portal_route('bookings.show', $b) }}" class="font-mono font-bold text-[#780000] hover:underline">{{ $b->booking_number }}</a>@endif</td>
                        <td class="p-4">{{ $b?->formatted_class_type }}</td>
                        <td class="p-4">{{ $b?->batch?->batch_code ?? '—' }}</td>
                        <td class="p-4">{{ $coaches->implode(', ') ?: '—' }}</td>
                        <td class="p-4">@if($b)<span class="px-2 py-0.5 rounded text-sm font-bold {{ $b->status_badge['bg'] }}">{{ $b->status_badge['label'] }}</span>@endif</td>
                        <td class="p-4 pr-5 whitespace-nowrap">₱{{ number_format((float) ($b?->payments->whereIn('status', ['completed', 'paid'])->sum('amount') ?? 0), 2) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="p-6 text-center text-[#6E6E73]">No bookings linked yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($mergedRecords->isNotEmpty())
        <div class="bg-white rounded-2xl border border-[#E5E5EA] p-5 space-y-3">
            <h2 class="text-base font-extrabold text-[#1D1D1F]">Merged records</h2>
            @foreach($mergedRecords as $m)
                <div class="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-[#E5E5EA] p-3">
                    <span>{{ $m->full_name }} · born {{ $m->birthdate?->format('M d, Y') ?? 'unknown' }}</span>
                    <form method="POST" action="{{ portal_route('participants.unmerge', $m) }}" data-native>
                        @csrf
                        <button class="text-sm font-bold text-[#780000] hover:underline">Undo merge</button>
                    </form>
                </div>
            @endforeach
        </div>
    @endif

    <!-- Anonymise -->
    <details class="bg-white rounded-2xl border border-[#FECACA] p-5">
        <summary class="font-bold text-[#B91C1C] cursor-pointer">Anonymise this participant (on request)</summary>
        <form method="POST" action="{{ portal_route('participants.anonymise', $participant) }}" class="mt-3 space-y-3" data-native>
            @csrf
            <p class="text-[#6E6E73]">Clears their name, birthdate, contact and health details from this record and from all of their bookings. Booking amounts stay in reports. This cannot be undone.</p>
            <label class="flex items-start gap-2"><input type="checkbox" name="confirm" value="1" class="mt-0.5"> <span>I understand this cannot be undone.</span></label>
            <button class="px-4 py-2 rounded-xl bg-[#B91C1C] text-white font-bold">Anonymise</button>
        </form>
    </details>
</div>
@endsection
