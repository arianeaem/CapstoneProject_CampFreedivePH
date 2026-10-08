@extends('layouts.admin')

@section('title', $participant->full_name . ' | Camp FreedivePH')

@section('breadcrumb')
    <a href="{{ route('coach.schedule.index') }}" class="text-[#6E6E73] hover:text-[#780000] font-medium transition-colors">My Schedule</a>
    <svg class="w-3.5 h-3.5 text-[#8E8E93] shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m9 18 6-6-6-6"/></svg>
    <span class="font-bold text-[#1D1D1F]">{{ $participant->full_name }}</span>
@endsection

@section('content')
@php $label = fn ($v) => $v ? ucwords(str_replace('_', ' ', $v)) : '—'; @endphp
<div class="max-w-4xl mx-auto space-y-6 text-sm">
    <div>
        <h1 class="text-2xl sm:text-3xl font-extrabold text-[#1D1D1F] tracking-tight">{{ $participant->full_name }}</h1>
        <p class="text-sm text-[#6E6E73] mt-1">
            {{ $history->count() }} {{ Str::plural('booking', $history->count()) }}
            @if($participant->age !== null) · {{ $participant->age }} yrs @endif
            @if($participant->isMinor()) · <span class="font-bold text-[#92400E]">Under 18</span> @endif
        </p>
    </div>

    <div class="bg-white rounded-2xl border border-[#E5E5EA] p-5 grid grid-cols-2 sm:grid-cols-3 gap-4">
        <div><span class="text-xs font-bold text-[#8E8E93] uppercase tracking-wider block">Swimmer status</span>{{ $label($participant->latest_swimmer_status) }}</div>
        <div><span class="text-xs font-bold text-[#8E8E93] uppercase tracking-wider block">Gender</span>{{ $label($participant->gender) }}</div>
        <div class="col-span-2 sm:col-span-3">
            <span class="text-xs font-bold text-[#8E8E93] uppercase tracking-wider block">Latest health notes</span>
            {{ $participant->latest_health_condition ?: 'None declared' }}
            @if($participant->health_updated_at)<span class="text-xs text-[#8E8E93]">(given {{ $participant->health_updated_at->format('M d, Y') }})</span>@endif
        </div>
    </div>

    <div class="bg-white rounded-2xl border border-[#E5E5EA] overflow-x-auto">
        <div class="p-5 pb-3"><h2 class="text-base font-extrabold text-[#1D1D1F]">Dive history</h2></div>
        <table class="w-full text-left min-w-[560px]">
            <thead class="bg-[#F2F2F7] border-y border-[#E5E5EA] text-sm uppercase font-bold text-[#6E6E73]">
                <tr><th class="p-4 pl-5">Trip</th><th class="p-4">Class</th><th class="p-4">Batch</th><th class="p-4 pr-5">Coach</th></tr>
            </thead>
            <tbody class="divide-y divide-[#E5E5EA]">
                @foreach($history as $row)
                    @php $coaches = $row->assignments->sortByDesc('id')->pluck('coach.name')->filter()->unique(); @endphp
                    <tr>
                        <td class="p-4 pl-5 whitespace-nowrap font-semibold">{{ $row->booking->start_date?->format('M d, Y') }}</td>
                        <td class="p-4">{{ $row->booking->formatted_class_type }}</td>
                        <td class="p-4">{{ $row->booking->batch?->batch_code ?? '—' }}</td>
                        <td class="p-4 pr-5">{{ $coaches->implode(', ') ?: 'Not assigned yet' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
@endsection
