@extends('layouts.admin')

@section('title', 'Open Slot Requests | Coach Portal')

@section('content')
<div class="space-y-6" x-data="{ 
    activeTab: '{{ $activeTab }}', 
    applyModalOpen: false, 
    selectedOpening: null, 
    submittingApply: false,
    withdrawModalOpen: false,
    selectedWithdrawUrl: '',
    selectedWithdrawBatch: '',
    selectedWithdrawDate: '',
    submittingWithdraw: false,
    openWithdrawModal(url, batchName, dateStr) {
        this.selectedWithdrawUrl = url;
        this.selectedWithdrawBatch = batchName;
        this.selectedWithdrawDate = dateStr;
        this.submittingWithdraw = false;
        this.withdrawModalOpen = true;
    }
}">
    
    <!-- Top Header & Tabs -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl sm:text-3xl font-extrabold text-[#1D1D1F] tracking-tight">Open Dive Slot Requests</h1>
        </div>

        <!-- Tab Switcher -->
        <div class="flex items-center bg-[#F2F2F7] rounded-xl border border-[#E5E5EA] p-1 w-full md:w-auto"
             role="tablist"
             aria-label="Dive Slot Request Tabs">
            <button type="button" 
                    id="tab-open-slots"
                    role="tab"
                    :aria-selected="activeTab === 'open_slots' ? 'true' : 'false'"
                    aria-controls="panel-open-slots"
                    :tabindex="activeTab === 'open_slots' ? '0' : '-1'"
                    @click="activeTab = 'open_slots'"
                    @keydown.arrow-right.prevent="activeTab = 'my_requests'; $nextTick(() => document.getElementById('tab-my-requests')?.focus())"
                    :class="activeTab === 'open_slots' ? 'bg-white text-[#1D1D1F] font-bold shadow-xs' : 'text-[#6E6E73] font-semibold hover:text-[#1D1D1F]'"
                    class="flex-1 md:flex-initial min-h-[44px] px-3.5 sm:px-4 py-2.5 rounded-lg text-sm transition-all flex items-center justify-center gap-1.5 sm:gap-2 cursor-pointer focus:outline-none focus-visible:ring-2 focus-visible:ring-[#780000]">
                <span>Available Openings ({{ count($openings) }})</span>
            </button>
            <div class="w-px h-5 bg-[#E5E5EA] mx-0.5 shrink-0" aria-hidden="true"></div>
            <button type="button" 
                    id="tab-my-requests"
                    role="tab"
                    :aria-selected="activeTab === 'my_requests' ? 'true' : 'false'"
                    aria-controls="panel-my-requests"
                    :tabindex="activeTab === 'my_requests' ? '0' : '-1'"
                    @click="activeTab = 'my_requests'"
                    @keydown.arrow-left.prevent="activeTab = 'open_slots'; $nextTick(() => document.getElementById('tab-open-slots')?.focus())"
                    :class="activeTab === 'my_requests' ? 'bg-white text-[#1D1D1F] font-bold shadow-xs' : 'text-[#6E6E73] font-semibold hover:text-[#1D1D1F]'"
                    class="flex-1 md:flex-initial min-h-[44px] px-3.5 sm:px-4 py-2.5 rounded-lg text-sm transition-all flex items-center justify-center gap-1.5 sm:gap-2 cursor-pointer focus:outline-none focus-visible:ring-2 focus-visible:ring-[#780000]">
                <span>My Requests ({{ count($myRequests) }})</span>
            </button>
        </div>
    </div>

    <!-- Available Camp Openings (3-column grid) -->
    <div x-show="activeTab === 'open_slots'" role="tabpanel" id="panel-open-slots" aria-labelledby="tab-open-slots">
        @if(count($openings) > 0)
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5 sm:gap-6">
                @foreach($openings as $op)
                    @php
                        $batch = $op->batch;
                        $hasApplied = in_array($op->id, $myRequestedOpeningIds);
                    @endphp

                    <div class="bg-white rounded-2xl border border-[#E5E5EA] p-4 sm:p-5 hover:border-[#D1D1D6] hover:shadow-sm transition-all flex flex-col justify-between space-y-4">
                        
                        <!-- Slot Opening Information -->
                        <div class="space-y-3">
                            <div class="flex items-start justify-between gap-2.5">
                                <div class="min-w-0 flex-1">
                                    <h3 class="text-base sm:text-lg font-black text-[#1D1D1F] tracking-tight">
                                        {{ $batch?->batch_number ?? $batch?->name ?? 'Camp Freediving Session' }}
                                    </h3>
                                </div>
                                <span class="text-sm font-black px-2.5 py-1 rounded-lg bg-amber-100 text-amber-900 tracking-wider uppercase shrink-0 whitespace-nowrap">
                                    Needs Coach
                                </span>
                            </div>

                            <div class="space-y-1 text-sm text-[#6E6E73] pt-0.5">
                                <p>
                                    Dive Dates: <strong class="text-[#1D1D1F]">
                                        @if($batch && $batch->start_date && $batch->end_date && $batch->start_date->ne($batch->end_date))
                                            {{ $batch->start_date->format('M d') }} - {{ $batch->end_date->format('d, Y') }} ({{ $batch->start_date->format('D') }} - {{ $batch->end_date->format('D') }})
                                        @else
                                            {{ $op->dive_date->format('M d, Y') }}
                                        @endif
                                    </strong>
                                </p>
                                <p>
                                    Participants Needing Coach: <strong class="text-[#1D1D1F]">{{ $op->needed_students_count }} Participant(s)</strong>
                                </p>
                            </div>

                            @if($op->notes)
                                <div >
                                    <span class="block text-sm font-bold uppercase tracking-wider text-[#8E8E93]">Camp Staff Note</span>
                                    <p class="text-sm text-[#3A3A3C] leading-relaxed">{{ $op->notes }}</p>
                                </div>
                            @endif
                        </div>

                        <!-- Request Action -->
                        <div class="pt-2">
                            @if($hasApplied)
                                <div class="w-full min-h-[44px] py-2.5 px-3 rounded-xl bg-[#F2F2F7] text-[#6E6E73] text-sm font-bold flex items-center justify-center gap-2 text-center">
                                    <svg class="w-4 h-4 text-[#6E6E73] shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                                    <span>Request Pending Admin Review</span>
                                </div>
                            @else
                                <button type="button" 
                                        @click="selectedOpening = {{ json_encode($op) }}; submittingApply = false; applyModalOpen = true"
                                        class="btn-primary w-full min-h-[44px] py-2.5 rounded-xl text-white text-sm font-bold transition-all flex items-center justify-center gap-2 cursor-pointer shadow-xs active:scale-[0.99] focus:outline-none focus-visible:ring-2 focus-visible:ring-[#780000] focus-visible:ring-offset-2">
                                    <span>Request This Slot</span>
                                    <span aria-hidden="true">→</span>
                                </button>
                            @endif
                        </div>

                    </div>
                @endforeach
            </div>
        @else
            <div class="bg-white rounded-2xl p-8 sm:p-12 border border-[#E5E5EA] text-center space-y-3">
                <h3 class="text-base font-bold text-[#1D1D1F]">All Camp Slots Currently Staffed</h3>
                <p class="text-sm text-[#6E6E73] max-w-md mx-auto leading-relaxed">
                    There are no unstaffed dive openings broadcasted at the moment. When the camp has overflow participants needing a coach, openings will appear here.
                </p>
            </div>
        @endif
    </div>

    <!-- Submitted Requests (3-column grid) -->
    <div x-show="activeTab === 'my_requests'" role="tabpanel" id="panel-my-requests" aria-labelledby="tab-my-requests">
        @if(count($myRequests) > 0)
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5 sm:gap-6">
                @foreach($myRequests as $req)
                    @php
                        $batch = $req->batch ?: $req->opening?->batch;
                        $badge = $req->status_badge;
                        $diveDate = $batch?->start_date ?: $req->opening?->dive_date;
                    @endphp

                    <div class="bg-white rounded-2xl border border-[#E5E5EA] p-4 sm:p-5 hover:border-[#D1D1D6] hover:shadow-sm transition-all flex flex-col justify-between space-y-4">
                        
                        <!-- Request Details -->
                        <div class="space-y-3">
                            <div class="flex items-start justify-between gap-2.5">
                                <div class="min-w-0 flex-1">
                                    <h3 class="text-base sm:text-lg font-black text-[#1D1D1F] tracking-tight">
                                        {{ $batch?->batch_number ?? $batch?->name ?? 'Camp Freedive Batch' }}
                                    </h3>
                                </div>
                                <span class="px-2.5 py-1 rounded-lg text-sm font-black tracking-wider uppercase shrink-0 whitespace-nowrap {{ $badge['class'] }}">
                                    {{ $badge['label'] }}
                                </span>
                            </div>

                            <div class="space-y-1 text-sm text-[#6E6E73] pt-0.5">
                                <p>
                                    Dive Dates: <strong class="text-[#1D1D1F]">
                                        @if($batch && $batch->start_date && $batch->end_date && $batch->start_date->ne($batch->end_date))
                                            {{ $batch->start_date->format('M d') }} - {{ $batch->end_date->format('d, Y') }} ({{ $batch->start_date->format('D') }} - {{ $batch->end_date->format('D') }})
                                        @elseif($diveDate)
                                            {{ $diveDate->format('M d, Y') }}
                                        @else
                                            Weekend Batch
                                        @endif
                                    </strong>
                                </p>
                                <p>
                                    Submitted: <strong class="text-[#1D1D1F]">{{ $req->created_at->format('M d, Y') }} at {{ $req->created_at->format('g:i A') }}</strong>
                                </p>
                            </div>

                            @if($req->notes)
                                <div >
                                    <span class="block text-sm font-bold uppercase tracking-wider text-[#8E8E93]">Your Note</span>
                                    <p class="text-sm text-[#3A3A3C] leading-relaxed">{{ $req->notes }}</p>
                                </div>
                            @endif

                                @if($req->status === 'not_selected')
                                <div class="p-3 rounded-xl bg-[#F2F2F7] text-sm text-[#6E6E73] flex items-center gap-2.5">
                                    <svg class="w-4 h-4 shrink-0 text-[#6E6E73]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
                                    <span>This slot was filled by another coach. Thank you for volunteering!</span>
                                </div>
                            @endif
                        </div>

                        <!-- Request Actions -->
                        <div class="pt-2">
                            @if($req->status === 'pending')
                                @php
                                    $batchName = $batch?->batch_number ?? $batch?->name ?? 'Camp Freediving Session';
                                    $formattedDate = $diveDate ? $diveDate->format('M d, Y') : '';
                                    $withdrawAction = route('coach.requests.withdraw', $req);
                                @endphp
                                <button type="button" 
                                         @click="openWithdrawModal('{{ $withdrawAction }}', '{{ addslashes($batchName) }}', '{{ addslashes($formattedDate) }}')"
                                         class="w-full min-h-[44px] py-2.5 rounded-xl text-sm font-bold text-[#6E6E73] hover:text-rose-600 hover:bg-rose-50 border border-[#E5E5EA] hover:border-rose-200 transition-all text-center cursor-pointer active:scale-[0.99] focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-500">
                                    Withdraw Request
                                </button>
                            @elseif($req->status === 'approved')
                                <div class="w-full min-h-[44px] py-2.5 px-3 rounded-xl bg-emerald-50 text-emerald-800 text-sm font-bold flex items-center justify-center gap-2">
                                    <svg class="w-4 h-4 text-emerald-600 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"></polyline></svg>
                                    <span>Added to Your Schedule</span>
                                </div>
                            @else
                                <div class="w-full min-h-[44px] py-2.5 px-3 rounded-xl bg-[#F2F2F7] text-[#6E6E73] text-sm font-bold flex items-center justify-center text-center">
                                    Request Concluded
                                </div>
                            @endif
                        </div>

                    </div>
                @endforeach
            </div>
        @else
            <div class="bg-white rounded-2xl p-10 border border-[#E5E5EA] text-center text-sm text-[#8E8E93]">
                You haven't submitted any slot requests yet.
            </div>
        @endif
    </div>

    <!-- Request Slot Confirmation Modal -->
    <div x-show="applyModalOpen" 
         x-cloak 
         role="dialog"
         aria-modal="true"
         aria-labelledby="request-slot-modal-title"
         @keydown.escape.window="applyModalOpen = false"
         class="fixed inset-0 z-50 bg-black/40 backdrop-blur-sm flex justify-end"
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-150"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0">
        <div class="dive-side-panel h-full overflow-y-auto overscroll-contain bg-white sm:max-w-lg w-full p-5 sm:p-6 shadow-2xl border-l border-[#E5E5EA] space-y-6 relative" 
             @click.outside="applyModalOpen = false"
             x-transition:enter="transition ease-out duration-200"
             x-transition:enter-start="opacity-0 translate-x-full"
             x-transition:enter-end="opacity-100 translate-x-0"
             x-transition:leave="transition ease-in duration-150"
             x-transition:leave-start="opacity-100 translate-x-0"
             x-transition:leave-end="opacity-0 translate-x-full">
            
            <div class="flex items-start justify-between border-b border-[#E5E5EA] pb-4">
                <div>
                    <h3 id="request-slot-modal-title" class="text-lg font-black text-[#1D1D1F]">Request Open Dive Slot</h3>
                    <p class="text-sm font-semibold text-[#00838F] mt-0.5">Volunteer Request</p>
                </div>
                <button type="button" 
                        @click="applyModalOpen = false" 
                        aria-label="Close volunteer request modal" 
                        class="w-11 h-11 min-h-[44px] min-w-[44px] -mr-2 -mt-1 rounded-full flex items-center justify-center text-[#6E6E73] hover:text-[#1D1D1F] hover:bg-[#F2F2F7] transition-all cursor-pointer focus:outline-none focus-visible:ring-2 focus-visible:ring-[#780000]">
                    <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                        <path d="M18 6L6 18M6 6l12 12"/>
                    </svg>
                </button>
            </div>

            <template x-if="selectedOpening">
                <form :action="'{{ url('/coach/open-requests') }}/' + selectedOpening.id + '/apply'" 
                      method="POST" 
                      @submit="submittingApply = true" 
                      class="space-y-4">
                    @csrf
                    
                    <div class="p-4 rounded-xl bg-[#F2F2F7] space-y-2 text-sm shadow-2xs">
                        <div class="font-bold text-sm text-[#1D1D1F]" x-text="selectedOpening.batch?.name"></div>
                        <div>Dive Date: <strong x-text="selectedOpening.dive_date"></strong></div>
                        <div>Participants Needing Coach: <strong x-text="selectedOpening.needed_students_count"></strong></div>
                    </div>

                    <div class="space-y-1.5">
                        <label for="apply-notes" class="block text-sm font-bold text-[#1D1D1F]">
                            Optional Note to Camp Admin
                        </label>
                        <textarea id="apply-notes"
                                  name="notes" 
                                  rows="3" 
                                  placeholder="E.g., I have gear ready and available for this entire weekend..."
                                  class="w-full text-sm rounded-xl border border-[#D1D1D6] focus:border-[#780000] focus:ring-[#780000] p-3"></textarea>
                    </div>

                    <div class="banner banner-info">
                        Submitting interest notifies Camp Admin. If selected, participants will be automatically matched to your roster.
                    </div>

                    <div class="flex items-center justify-end gap-2.5 pt-2">
                        <button type="button" 
                                @click="applyModalOpen = false" 
                                class="btn-secondary min-h-[44px] px-4 py-2 text-sm font-semibold rounded-xl cursor-pointer transition-all active:scale-[0.99] focus:outline-none focus-visible:ring-2 focus-visible:ring-[#780000]">
                            Cancel
                        </button>
                        <button type="submit" 
                                :disabled="submittingApply" 
                                class="btn-primary min-h-[44px] px-5 py-2 text-sm font-bold rounded-xl cursor-pointer transition-all active:scale-[0.99] focus:outline-none focus-visible:ring-2 focus-visible:ring-[#780000] focus-visible:ring-offset-2 disabled:opacity-50 disabled:cursor-not-allowed flex items-center justify-center gap-2">
                            <span x-show="!submittingApply">Submit Slot Request</span>
                            <span x-show="submittingApply" x-cloak class="inline-flex items-center gap-2">
                                <svg class="animate-spin h-4 w-4 text-white" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path></svg>
                                <span>Submitting...</span>
                            </span>
                        </button>
                    </div>
                </form>
            </template>

        </div>
    </div>

    <!-- Withdraw Slot Request Modal -->
    <div x-show="withdrawModalOpen" 
         x-cloak 
         role="dialog"
         aria-modal="true"
         aria-labelledby="withdraw-slot-modal-title"
         @keydown.escape.window="withdrawModalOpen = false"
         class="fixed inset-0 z-50 bg-black/40 backdrop-blur-sm flex justify-end"
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-150"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0">
        <div class="dive-side-panel h-full overflow-y-auto overscroll-contain bg-white sm:max-w-md w-full p-5 sm:p-6 shadow-2xl border-l border-[#E5E5EA] space-y-6 relative" 
             @click.outside="withdrawModalOpen = false"
             x-transition:enter="transition ease-out duration-200"
             x-transition:enter-start="opacity-0 translate-x-full"
             x-transition:enter-end="opacity-100 translate-x-0"
             x-transition:leave="transition ease-in duration-150"
             x-transition:leave-start="opacity-100 translate-x-0"
             x-transition:leave-end="opacity-0 translate-x-full">
            
            <div class="flex items-start justify-between border-b border-[#E5E5EA] pb-4">
                <div>
                    <h3 id="withdraw-slot-modal-title" class="text-lg font-black text-[#1D1D1F]">Withdraw Slot Request</h3>
                    <p class="text-sm font-semibold text-rose-600 mt-0.5">Request Cancellation</p>
                </div>
                <button type="button" 
                        @click="withdrawModalOpen = false" 
                        aria-label="Close withdrawal modal" 
                        class="w-11 h-11 min-h-[44px] min-w-[44px] -mr-2 -mt-1 rounded-full flex items-center justify-center text-[#6E6E73] hover:text-[#1D1D1F] hover:bg-[#F2F2F7] transition-all cursor-pointer focus:outline-none focus-visible:ring-2 focus-visible:ring-[#780000]">
                    <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                        <path d="M18 6L6 18M6 6l12 12"/>
                    </svg>
                </button>
            </div>

            <form :action="selectedWithdrawUrl" 
                  method="POST" 
                  @submit="submittingWithdraw = true" 
                  class="space-y-4">
                @csrf
                @method('DELETE')
                
                <div class="p-4 rounded-xl bg-[#F2F2F7] space-y-2 text-sm shadow-2xs">
                    <div class="font-bold text-sm text-[#1D1D1F]" x-text="selectedWithdrawBatch"></div>
                    <div x-show="selectedWithdrawDate">Dive Date: <strong x-text="selectedWithdrawDate"></strong></div>
                </div>

                <p class="text-sm text-[#3A3A3C] leading-relaxed">
                    Are you sure you want to withdraw your request for this dive opening? You will no longer be considered for this session.
                </p>

                <div class="flex items-center justify-end gap-2.5 pt-2">
                    <button type="button" 
                            @click="withdrawModalOpen = false" 
                            class="btn-secondary min-h-[44px] px-4 py-2 text-sm font-semibold rounded-xl cursor-pointer transition-all active:scale-[0.99] focus:outline-none focus-visible:ring-2 focus-visible:ring-[#780000]">
                        Keep Request
                    </button>
                    <button type="submit" 
                            :disabled="submittingWithdraw" 
                            class="btn-danger min-h-[44px] px-5 py-2 text-sm font-bold rounded-xl cursor-pointer transition-all active:scale-[0.99] focus:outline-none focus-visible:ring-2 focus-visible:ring-[#D70015] focus-visible:ring-offset-2 disabled:opacity-50 disabled:cursor-not-allowed flex items-center justify-center gap-2">
                        <span x-show="!submittingWithdraw">Withdraw Request</span>
                        <span x-show="submittingWithdraw" x-cloak class="inline-flex items-center gap-2">
                            <svg class="animate-spin h-4 w-4 text-white" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path></svg>
                            <span>Withdrawing...</span>
                        </span>
                    </button>
                </div>
            </form>

        </div>
    </div>

</div>
@endsection
