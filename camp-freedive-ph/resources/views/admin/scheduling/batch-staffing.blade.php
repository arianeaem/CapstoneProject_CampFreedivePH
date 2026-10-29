@extends('layouts.admin')

@section('title', 'Batch Staffing — ' . $batch->name . ' | Camp FreedivePH')

@section('breadcrumb')
    <a href="{{ portal_route('coaches.index') }}" class="text-[#6E6E73] hover:text-[#780000] font-medium transition-colors">Coaches</a>
    <svg class="w-3.5 h-3.5 text-[#8E8E93] shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m9 18 6-6-6-6"/></svg>
    <span class="font-bold text-[#1D1D1F]">Batch Staffing</span>
@endsection

@section('content')
<div class="space-y-6 text-sm" x-data="{ confirmModal: false, revokeModal: false, assignModal: false, removeModal: false, selectedCoach: null, selectedDate: '' }">

    @if(session('success'))
        <div class="bg-emerald-50 border border-emerald-200 rounded-xl px-4 py-3 text-emerald-800 font-medium">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="bg-rose-50 border border-rose-200 rounded-xl px-4 py-3 text-rose-800 font-medium">{{ session('error') }}</div>
    @endif

    {{-- Batch Header --}}
    <div class="bg-white rounded-2xl border border-[#E5E5EA] p-6">
        <div class="flex items-center justify-between flex-wrap gap-4">
            <div>
                <h1 class="text-2xl font-extrabold text-[#1D1D1F] tracking-tight">{{ $batch->name }}</h1>
                <p class="text-[#6E6E73] mt-1">{{ $batch->formatted_date_range }}</p>
            </div>
            <div class="flex items-center gap-3">
                <span class="inline-flex items-center rounded-full px-3 py-1 text-xs font-semibold {{ $staffing['fully_covered'] ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-800' }}">
                    {{ $staffing['fully_covered'] ? 'Fully Staffed' : 'Understaffed' }}
                </span>
            </div>
        </div>

        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mt-5">
            <div class="bg-[#F5F5F7] rounded-xl p-3 text-center">
                <div class="text-2xl font-bold text-[#1D1D1F]">{{ $staffing['eligible_participants'] }}</div>
                <div class="text-[#6E6E73] text-xs mt-0.5">Eligible Participants</div>
            </div>
            <div class="bg-[#F5F5F7] rounded-xl p-3 text-center">
                <div class="text-2xl font-bold text-[#1D1D1F]">1:{{ $staffing['ratio'] }}</div>
                <div class="text-[#6E6E73] text-xs mt-0.5">Coach Ratio</div>
            </div>
            <div class="bg-[#F5F5F7] rounded-xl p-3 text-center">
                <div class="text-2xl font-bold text-[#1D1D1F]">{{ $staffing['required_coaches'] }}</div>
                <div class="text-[#6E6E73] text-xs mt-0.5">Required Coaches</div>
            </div>
            <div class="bg-[#F5F5F7] rounded-xl p-3 text-center">
                <div class="text-2xl font-bold {{ $staffing['fully_covered'] ? 'text-emerald-700' : 'text-amber-800' }}">
                    {{ collect($staffing['dates'])->min('committed_count') }}
                </div>
                <div class="text-[#6E6E73] text-xs mt-0.5">Min Assigned (any date)</div>
            </div>
        </div>
    </div>

    {{-- Per-date Coverage --}}
    <div class="bg-white rounded-2xl border border-[#E5E5EA] p-6">
        <h2 class="text-lg font-bold text-[#1D1D1F] mb-4">Per-Date Coverage</h2>
        <div class="grid gap-3">
            @foreach($staffing['dates'] as $date => $info)
            <div class="flex items-center justify-between bg-[#F5F5F7] rounded-xl px-4 py-3">
                <div>
                    <span class="font-semibold text-[#1D1D1F]">{{ \Carbon\Carbon::parse($date)->format('l, M d') }}</span>
                </div>
                <div class="flex items-center gap-3">
                    <span class="text-[#6E6E73]">{{ $info['committed_count'] }} / {{ $info['required_count'] }} coaches</span>
                    <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $info['covered'] ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700' }}">
                        {{ $info['covered'] ? 'Covered' : 'Needs ' . ($info['required_count'] - $info['committed_count']) . ' more' }}
                    </span>
                </div>
            </div>
            @endforeach
        </div>
    </div>

    {{-- Coach Roster --}}
    <div class="bg-white rounded-2xl border border-[#E5E5EA] p-6">
        <h2 class="text-lg font-bold text-[#1D1D1F] mb-4">Coach Availability &amp; Assignments</h2>

        <div class="overflow-x-auto">
            <table class="w-full text-left">
                <thead>
                    <tr class="border-b border-[#E5E5EA]">
                        <th class="pb-3 pr-4 font-semibold text-[#1D1D1F]">Coach</th>
                        @foreach(array_keys($staffing['dates']) as $date)
                        <th class="pb-3 px-2 text-center font-semibold text-[#1D1D1F] whitespace-nowrap">{{ \Carbon\Carbon::parse($date)->format('M d') }}</th>
                        @endforeach
                        <th class="pb-3 pl-4 font-semibold text-[#1D1D1F]">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($coaches as $entry)
                    @php $coach = $entry['coach']; $dates = $entry['dates']; @endphp
                    <tr class="border-b border-[#F5F5F7] hover:bg-[#FAFAFA]">
                        <td class="py-3 pr-4">
                            <div class="font-semibold text-[#1D1D1F]">{{ $coach->full_name }}</div>
                            <div class="text-[#8E8E93] text-xs">{{ $coach->certification_level }}</div>
                        </td>
                        @foreach($dates as $date => $info)
                        <td class="py-3 px-2 text-center">
                            @if($info['committed_to_this_batch'])
                                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold bg-blue-50 text-blue-700" title="Assigned to this batch">Assigned</span>
                            @elseif($info['conflict_batch'])
                                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold bg-rose-50 text-rose-700" title="Committed to {{ $info['conflict_batch'] }}">Conflict</span>
                            @elseif($info['confirmed'])
                                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold bg-emerald-50 text-emerald-700" title="Staff-confirmed available">Confirmed</span>
                            @elseif($info['available'])
                                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold bg-amber-50 text-amber-800" title="Self-declared, needs staff confirmation">Declared</span>
                            @else
                                <span class="text-[#C7C7CC]">&mdash;</span>
                            @endif
                        </td>
                        @endforeach
                        <td class="py-3 pl-4">
                            <div class="flex items-center gap-1 flex-wrap">
                                @php
                                    $allConfirmed = collect($dates)->every(fn ($d) => $d['confirmed'] || $d['committed_to_this_batch']);
                                    $anyCommittedHere = collect($dates)->contains(fn ($d) => $d['committed_to_this_batch']);
                                    $anyDeclaredUnconfirmed = collect($dates)->contains(fn ($d) => $d['available'] && !$d['confirmed'] && !$d['committed']);
                                    $hasConflict = collect($dates)->contains(fn ($d) => $d['conflict_batch']);
                                @endphp

                                @if($anyDeclaredUnconfirmed)
                                    @foreach($dates as $date => $info)
                                        @if($info['available'] && !$info['confirmed'] && !$info['committed'])
                                        <form method="POST" action="{{ portal_route('coaches.scheduling.confirm', $coach) }}">
                                            @csrf
                                            <input type="hidden" name="date" value="{{ $date }}">
                                            <button type="submit" class="text-xs font-medium text-emerald-700 hover:text-emerald-900 underline" title="Confirm {{ \Carbon\Carbon::parse($date)->format('M d') }}">
                                                Confirm {{ \Carbon\Carbon::parse($date)->format('M d') }}
                                            </button>
                                        </form>
                                        @endif
                                    @endforeach
                                @endif

                                @if($allConfirmed && !$anyCommittedHere && !$hasConflict)
                                <form method="POST" action="{{ portal_route('coaches.scheduling.assign', $batch) }}">
                                    @csrf
                                    <input type="hidden" name="coach_id" value="{{ $coach->id }}">
                                    <button type="submit" class="inline-flex items-center rounded-lg px-2.5 py-1 text-xs font-semibold bg-blue-600 text-white hover:bg-blue-700 transition-colors">
                                        Assign
                                    </button>
                                </form>
                                @endif

                                @if($anyCommittedHere)
                                <form method="POST" action="{{ portal_route('coaches.scheduling.remove', $batch) }}">
                                    @csrf
                                    <input type="hidden" name="coach_id" value="{{ $coach->id }}">
                                    <button type="submit" class="inline-flex items-center rounded-lg px-2.5 py-1 text-xs font-semibold bg-rose-50 text-rose-700 hover:bg-rose-100 transition-colors">
                                        Remove
                                    </button>
                                </form>
                                @endif

                                @foreach($dates as $date => $info)
                                    @if($info['confirmed'] && !$info['committed'])
                                    <form method="POST" action="{{ portal_route('coaches.scheduling.revoke', $coach) }}">
                                        @csrf
                                        <input type="hidden" name="date" value="{{ $date }}">
                                        <button type="submit" class="text-xs font-medium text-rose-600 hover:text-rose-800 underline" title="Revoke confirmation for {{ \Carbon\Carbon::parse($date)->format('M d') }}">
                                            Revoke {{ \Carbon\Carbon::parse($date)->format('M d') }}
                                        </button>
                                    </form>
                                    @endif
                                @endforeach
                            </div>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
