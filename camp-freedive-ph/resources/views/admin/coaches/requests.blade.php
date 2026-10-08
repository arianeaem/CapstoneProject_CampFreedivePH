@extends('layouts.admin')

@section('title', 'Review Coach Requests | Camp FreedivePH')

@section('breadcrumb')
    <a href="{{ portal_route('coaches.index') }}" class="text-[#6E6E73] hover:text-[#780000] font-medium transition-colors">Coaches &amp; Schedules</a>
    <svg class="w-3.5 h-3.5 text-[#8E8E93] shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m9 18 6-6-6-6"/></svg>
    <span class="font-bold text-[#1D1D1F]">Coach Requests</span>
@endsection

@section('content')
<div class="space-y-6 text-sm">
    
    <!-- Top Header -->
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl sm:text-3xl font-extrabold text-[#1D1D1F] tracking-tight">Review Coach Requests</h1>
        </div>

        <a href="{{ route('admin.coaches.matching') }}" class="btn-primary px-4 py-2 text-sm sm:text-sm font-bold shadow-2xs">
            Matching Queue
        </a>
    </div>

    <!-- Pending Requests Grouped by Batch -->
    <div class="space-y-6">

        @forelse($pendingRequests as $batchId => $groupRequests)
        @php 
            $batch = $groupRequests->first()->batch;
            $headcount = (int) $batch->total_participants_count;
            $coachesNeeded = $headcount > 0 ? (int) ceil($headcount / $coachRatio) : 0;
            $alreadyApproved = (int) ($approvedByBatch[$batch->id] ?? 0);
        @endphp
        <div class="bg-white rounded-xl border border-[#E5E5EA] p-5 sm:p-6 shadow-2xs" x-data="{ selectedRequests: [] }">
            
            <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">
                <!-- Left: Batch Details & Action -->
                <div class="lg:col-span-4 space-y-4 lg:pr-6 lg:border-r border-[#E5E5EA]">
                    <div>
                        <h2 class="font-extrabold text-xl sm:text-2xl text-[#1D1D1F] tracking-tight">
                            {{ $batch->batch_number ?? ($batch->batch_code ?? 'Batch ' . $batch->id) }}
                        </h2>

                        <!-- Details (2x2) -->
                        <div class="grid grid-cols-2 gap-3.5 mt-3.5">
                            <div>
                                <span class="text-[10px] font-bold uppercase tracking-wider text-[#8E8E93] block">DIVE DATE</span>
                                <span class="text-xs sm:text-sm font-bold text-[#1D1D1F] block mt-0.5 leading-snug">
                                    {{ $batch->formatted_date_range }}
                                </span>
                            </div>
                            <div>
                                <span class="text-[10px] font-bold uppercase tracking-wider text-[#8E8E93] block">APPLICANTS</span>
                                <span class="text-xs sm:text-sm font-bold text-[#1D1D1F] block mt-0.5 leading-snug">
                                    {{ $groupRequests->count() }}
                                </span>
                            </div>
                            <div>
                                <span class="text-[10px] font-bold uppercase tracking-wider text-[#8E8E93] block">APPROVED</span>
                                <span class="text-xs sm:text-sm font-bold text-[#1D1D1F] block mt-0.5 leading-snug">
                                    {{ $alreadyApproved }} <span class="text-[#6E6E73] font-normal text-xs">(Rec: {{ $coachesNeeded }})</span>
                                </span>
                            </div>
                            <div>
                                <span class="text-[10px] font-bold uppercase tracking-wider text-[#8E8E93] block">PARTICIPANTS</span>
                                <span class="text-xs sm:text-sm font-bold text-[#1D1D1F] block mt-0.5 leading-snug">
                                    {{ $headcount }} {{ Str::plural('Participant', $headcount) }}
                                </span>
                            </div>
                        </div>
                    </div>

                    <!-- Bulk Approve Action -->
                    <form action="{{ auth()->user()->isOwner() ? route('owner.coaches.requests.bulk_approve') : route('admin.coaches.requests.bulk_approve') }}" 
                          method="POST" 
                          x-show="selectedRequests.length > 0" 
                          x-cloak
                          class="pt-2">
                        @csrf
                        <template x-for="rId in selectedRequests" :key="rId">
                            <input type="hidden" name="request_ids[]" :value="rId">
                        </template>
                        <button type="submit" 
                                onclick="return confirm('Approve the selected ' + selectedRequests.length + ' coach applicant(s) for ' + '{{ addslashes($batch->batch_number ?? $batch->batch_code) }}' + '?')"
                                class="btn-primary w-full px-4 py-2 text-sm font-bold shadow-2xs flex items-center justify-center gap-1.5">
                            <span>Approve Selected (<span x-text="selectedRequests.length"></span>)</span>
                        </button>
                    </form>
                </div>

                <!-- Right: Selecting of Coaches -->
                <div class="lg:col-span-8">
                    <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-3">
                        @foreach($groupRequests as $req)
                        <div @click="if (selectedRequests.includes({{ $req->id }})) { selectedRequests = selectedRequests.filter(id => id !== {{ $req->id }}) } else { selectedRequests.push({{ $req->id }}) }"
                             class="p-4 rounded-xl flex flex-col justify-between gap-2.5 transition-all cursor-pointer select-none bg-transparent border border-transparent hover:ring-1 hover:ring-[#780000]"
                             :class="selectedRequests.includes({{ $req->id }}) ? 'ring-2 ring-[#780000]' : ''">
                            <div class="space-y-2.5">
                                <div class="flex items-center gap-3">
                                    <!-- 2px border on avatar -->
                                    <div class="w-10 h-10 rounded-full bg-[#F8EAEA] border-2 border-[#780000] text-[#780000] font-extrabold text-sm flex items-center justify-center shrink-0">
                                        {{ strtoupper(substr($req->coach->name, 0, 1)) }}
                                    </div>

                                    <div class="min-w-0 flex-1">
                                        <strong class="font-bold text-sm text-[#1D1D1F] block truncate">{{ $req->coach->name }}</strong>
                                        <span class="text-xs text-[#8E8E93] block">Applied {{ $req->created_at->format('M d, Y g:i A') }}</span>
                                    </div>
                                </div>

                                @if($req->notes)
                                    <p class="text-sm text-[#3A3A3C] italic p-2.5 rounded-lg border border-[#E5E5EA] bg-transparent leading-relaxed">
                                        "{{ $req->notes }}"
                                    </p>
                                @endif
                            </div>
                        </div>
                        @endforeach
                    </div>
                </div>
            </div>

        </div>
        @empty
        <div class="bg-white rounded-xl border border-[#E5E5EA] p-8 text-center text-sm text-[#6E6E73] shadow-2xs">
            No pending coach requests at this time.
        </div>
        @endforelse
    </div>

    <!-- Reviewed Request History -->
    @if($reviewedRequests->count() > 0)
    <div class="space-y-4 pt-4">
        <div class="flex items-center justify-between">
            <h2 class="text-base font-extrabold text-[#1D1D1F]">Reviewed History</h2>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4 sm:gap-5 items-start">
            @foreach($reviewedRequests->groupBy('batch_id') as $batchId => $groupReviewed)
            @php
                $revBatch = $groupReviewed->first()->batch;
                $revHeadcount = (int) ($revBatch?->total_participants_count ?? 0);
                $revCoachesNeeded = $revHeadcount > 0 ? (int) ceil($revHeadcount / 4) : 0;
                $approvedCount = $groupReviewed->where('status', 'approved')->count();
                $rejectedCount = $groupReviewed->whereIn('status', ['rejected', 'cancelled', 'not_selected'])->count();
                $totalReviewed = $groupReviewed->count();
            @endphp
            <div class="bg-white rounded-xl border border-[#E5E5EA] p-4 sm:p-5 shadow-2xs h-fit" x-data="{ open: false }">
                <div>
                    <!-- Batch Header & Accordion Toggle Arrow Beside Batch Number -->
                    <div class="flex items-center justify-between cursor-pointer select-none" @click="open = !open">
                        <h3 class="font-extrabold text-lg sm:text-xl text-[#1D1D1F] tracking-tight hover:text-[#780000] transition-colors">
                            {{ $revBatch->batch_number ?? ($revBatch->batch_code ?? 'Batch ' . ($revBatch->id ?? '')) }}
                        </h3>

                        <button type="button" class="p-1 text-[#8E8E93] hover:text-[#1D1D1F] transition-transform duration-200" :class="open ? 'rotate-180' : ''" aria-label="Toggle coaches list">
                            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m6 9 6 6 6-6"/></svg>
                        </button>
                    </div>

                    <!-- 2x2 Key Details Grid -->
                    <div class="grid grid-cols-2 gap-3 mt-3">
                        <div>
                            <span class="text-[10px] font-bold uppercase tracking-wider text-[#8E8E93] block">DIVE DATE</span>
                            <span class="text-xs sm:text-sm font-bold text-[#1D1D1F] block mt-0.5 leading-snug">
                                {{ $revBatch ? $revBatch->formatted_date_range : 'N/A' }}
                            </span>
                        </div>
                        <div>
                            <span class="text-[10px] font-bold uppercase tracking-wider text-[#8E8E93] block">APPLICANTS</span>
                            <span class="text-xs sm:text-sm font-bold text-[#1D1D1F] block mt-0.5 leading-snug">
                                {{ $totalReviewed }}
                            </span>
                        </div>
                        <div>
                            <span class="text-[10px] font-bold uppercase tracking-wider text-[#8E8E93] block">APPROVED</span>
                            <span class="text-xs sm:text-sm font-bold text-[#1D1D1F] block mt-0.5 leading-snug">
                                {{ $approvedCount }} <span class="text-[#6E6E73] font-normal text-xs">(Rec: {{ $revCoachesNeeded }})</span>
                            </span>
                        </div>
                        <div>
                            <span class="text-[10px] font-bold uppercase tracking-wider text-[#8E8E93] block">NOT SELECTED</span>
                            <span class="text-xs sm:text-sm font-bold text-[#1D1D1F] block mt-0.5 leading-snug">
                                {{ $rejectedCount }}
                            </span>
                        </div>
                    </div>

                    <!-- Coach list -->
                    <div x-show="open" x-cloak class="pt-3 mt-3 border-t border-[#F2F2F7] space-y-2">
                        <span class="text-[10px] font-bold uppercase tracking-wider text-[#8E8E93] block">APPLIED COACHES</span>
                        <div class="space-y-1.5">
                            @foreach($groupReviewed as $rev)
                            <div class="flex items-center justify-between py-1 gap-2">
                                <div class="min-w-0 flex-1">
                                    <span class="text-xs sm:text-sm font-bold text-[#1D1D1F] block truncate">{{ $rev->coach->name }}</span>
                                    <span class="text-[11px] text-[#8E8E93] block">
                                        Reviewed on {{ $rev->reviewed_at ? $rev->reviewed_at->format('M d, Y') : $rev->updated_at->format('M d, Y') }}
                                    </span>
                                </div>

                                <span class="px-2 py-0.5 rounded-full text-xs font-bold shrink-0 {{ $rev->status === 'approved' ? 'bg-emerald-50 text-emerald-800' : 'bg-zinc-100 text-zinc-700' }}">
                                    {{ $rev->status === 'approved' ? 'Approved' : 'Not Selected' }}
                                </span>
                            </div>
                            @endforeach
                        </div>
                    </div>
                </div>

            </div>
            @endforeach
        </div>
    </div>
    @endif

</div>
@endsection
