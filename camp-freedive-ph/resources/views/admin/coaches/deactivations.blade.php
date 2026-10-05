@extends('layouts.admin')

@section('title', 'Pending Coach Deactivations | Camp FreedivePH')

@section('breadcrumb')
    <a href="{{ portal_route('coaches.index') }}" class="text-[#6E6E73] hover:text-[#780000] font-medium transition-colors">Coaches &amp; Schedules</a>
    <svg class="w-3.5 h-3.5 text-[#8E8E93] shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m9 18 6-6-6-6"/></svg>
    <span class="font-bold text-[#1D1D1F]">Deactivation Requests</span>
@endsection

@section('content')
<div class="space-y-6 text-sm" x-data="{ selectedRequest: null, openConfirmModal: false }">
    
    <!-- Top Header -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-extrabold text-[#1D1D1F]">Pending Coach Deactivations</h1>
        </div>

        <a href="{{ route('admin.coaches.index') }}" class="btn-secondary px-4 py-2 text-sm sm:text-sm font-semibold">
            View Active Roster
        </a>
    </div>

    <!-- Active Requests Table -->
    <div class="bg-white rounded-xl border border-[#E5E5EA] overflow-x-auto">
        <div class="p-5 border-b border-[#E5E5EA] flex items-center justify-between">
            <h3 class="font-bold text-[#1D1D1F]">Pending Requests Awaiting Owner Confirmation</h3>
            <span class="text-sm font-bold text-[#7E22CE]">{{ $pendingRequests->count() }} Request(s)</span>
        </div>

        <table class="w-full text-left min-w-[850px]">
            <thead class="bg-[#F2F2F7] border-b border-[#E5E5EA] text-sm uppercase font-bold text-[#6E6E73]">
                <tr>
                    <th class="p-4 pl-6">Coach Name</th>
                    <th class="p-4">Certification</th>
                    <th class="p-4">Proposed By</th>
                    <th class="p-4">Reason</th>
                    <th class="p-4">Requested At</th>
                    <th class="p-4 text-right pr-6">Action</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-[#E5E5EA]">
                @forelse($pendingRequests as $req)
                <tr class="hover:bg-[#F2F2F7]/60 transition-colors text-sm sm:text-sm">
                    <td class="p-4 pl-6 font-bold text-[#1D1D1F]">
                        <a href="{{ route('admin.coaches.show', $req->coach) }}" class="text-[#780000] hover:underline">
                            {{ $req->coach->full_name }}
                        </a>
                    </td>

                    <td class="p-4">
                        {{ $req->coach->certification_level }}
                    </td>

                    <td class="p-4">
                        {{ $req->requester->name }}
                    </td>

                    <td class="p-4 text-sm text-[#6E6E73] max-w-xs">
                        {{ $req->reason ?: 'No explanation provided.' }}
                    </td>

                    <td class="p-4 text-sm text-[#6E6E73] whitespace-nowrap">
                        {{ $req->created_at->format('M d, Y h:i A') }}
                    </td>

                    <td class="p-4 text-right pr-6 whitespace-nowrap">
                        @if(auth()->user()->isOwner())
                            <div class="flex items-center justify-end gap-2">
                                <button type="button" 
                                        @click="selectedRequest = {{ json_encode($req) }}; openConfirmModal = true"
                                        class="px-3 py-1.5 text-sm font-bold text-white bg-[#FF3B3C] hover:bg-[#D32F2F] rounded-xl transition-colors">
                                    Confirm Deactivation
                                </button>

                                <form action="{{ route('admin.coaches.deactivate.dismiss', $req) }}" method="POST">
                                    @csrf
                                    <button type="submit" class="btn-secondary px-3 py-1.5 text-sm font-bold">
                                        Dismiss
                                    </button>
                                </form>
                            </div>
                        @else
                            <span class="text-sm text-[#8E8E93] italic">Owner review required</span>
                        @endif
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="6" class="p-8 text-center text-[#6E6E73]">
                        No pending deactivation requests in queue.
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <!-- Processed Requests History -->
    <div class="bg-white rounded-xl border border-[#E5E5EA] overflow-x-auto">
        <div class="p-5 border-b border-[#E5E5EA]">
            <h3 class="font-bold text-[#1D1D1F]">Resolved Deactivation Requests</h3>
        </div>

        <table class="w-full text-left min-w-[800px]">
            <thead class="bg-[#F2F2F7] border-b border-[#E5E5EA] text-sm uppercase font-bold text-[#6E6E73]">
                <tr>
                    <th class="p-4 pl-6">Coach Name</th>
                    <th class="p-4">Decision</th>
                    <th class="p-4">Proposed By</th>
                    <th class="p-4">Resolved By (Owner)</th>
                    <th class="p-4 text-right pr-6">Resolved Date</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-[#E5E5EA]">
                @forelse($processedRequests as $pReq)
                <tr @if($pReq->coach) onclick="window.location='{{ route('admin.coaches.show', $pReq->coach) }}'" class="hover:bg-[#F2F2F7] cursor-pointer transition-colors text-sm sm:text-sm group" @else class="hover:bg-[#F2F2F7]/60 transition-colors text-sm sm:text-sm" @endif>
                    <td class="p-4 pl-6 font-bold text-[#1D1D1F] group-hover:text-[#780000]">
                        {{ $pReq->coach ? $pReq->coach->full_name : 'Deleted Coach' }}
                    </td>

                    <td class="p-4">
                        <span class="px-2.5 py-0.5 rounded-full text-sm font-bold {{ $pReq->status_badge['class'] }}">
                            {{ $pReq->status_badge['label'] }}
                        </span>
                    </td>

                    <td class="p-4 text-sm text-[#6E6E73]">
                        {{ $pReq->requester->name }}
                    </td>

                    <td class="p-4 text-sm text-[#6E6E73]">
                        {{ $pReq->resolver ? $pReq->resolver->name : 'N/A' }}
                    </td>

                    <td class="p-4 text-right pr-6 text-sm text-[#6E6E73] whitespace-nowrap">
                        {{ $pReq->resolved_at ? $pReq->resolved_at->format('M d, Y h:i A') : 'N/A' }}
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="5" class="p-6 text-center text-[#6E6E73]">
                        No resolution history recorded yet.
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
        {{ $processedRequests->links() }}
    </div>


    <!-- ========================================================================= -->
    <!-- MODAL: CONFIRM DEACTIVATION (OWNER ONLY) -->
    <!-- ========================================================================= -->
    <div x-show="openConfirmModal" x-cloak class="fixed inset-0 z-50 bg-black/40 flex justify-end">
        <div class="dive-side-panel h-full overflow-y-auto overscroll-contain bg-white sm:max-w-md w-full p-6 space-y-4 shadow-2xl border-l border-[#E5E5EA]" @click.outside="openConfirmModal = false">
            <h3 class="text-lg font-bold text-[#FF3B3C]">Confirm Coach Deactivation</h3>
            <p class="text-sm text-[#6E6E73]">
                Are you sure you want to deactivate <strong class="text-[#1D1D1F]" x-text="selectedRequest?.coach?.full_name"></strong>?
            </p>

            <div class="banner banner-warning">
                <strong>Conflict Check:</strong> If this coach has any upcoming 2D1N batch assignments, the system will block deactivation until those sessions are reassigned.
            </div>

            <form :action="'{{ url('/admin/coaches/deactivations') }}/' + selectedRequest?.id + '/confirm'" method="POST" class="pt-2">
                @csrf
                <div class="flex items-center justify-end gap-2">
                    <button type="button" @click="openConfirmModal = false" class="btn-secondary px-4 py-2 text-sm">Cancel</button>
                    <button type="submit" class="btn-danger px-5 py-2 text-sm font-bold">
                        Confirm & Deactivate
                    </button>
                </div>
            </form>
        </div>
    </div>

</div>
@endsection
