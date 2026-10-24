@extends('layouts.admin')

@section('title', 'Refund Cases | Camp FreedivePH')

@section('breadcrumb')
    <a href="{{ portal_route('payments.index') }}" class="text-[#6E6E73] hover:text-[#780000] font-medium transition-colors">Payments &amp; Refunds</a>
    <svg class="w-3.5 h-3.5 text-[#8E8E93] shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m9 18 6-6-6-6"/></svg>
    <span class="font-bold text-[#1D1D1F]">Refund Cases</span>
@endsection

@section('content')
<div class="space-y-6 text-sm">
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl sm:text-3xl font-extrabold text-[#1D1D1F] tracking-tight">Refund Cases</h1>
            <p class="text-[#6E6E73] mt-1">Cancelled bookings with money on them. Approving an amount never sends money; "Send refund" does, and only what PayMongo confirms (or a documented offline payout) counts as refunded.</p>
        </div>
        <a href="{{ portal_route('payments.refunds') }}" class="btn-secondary px-3.5 py-2 text-sm font-semibold">Legacy refund queue</a>
    </div>

    <div class="flex flex-wrap gap-2">
        @foreach(['open' => 'All open', ...$labels, 'all' => 'All'] as $key => $label)
            <a href="{{ portal_route('refund_cases.index', ['status' => $key]) }}" class="px-3 py-1.5 rounded-full border text-sm font-semibold {{ $status === $key ? 'bg-[#780000] text-white border-[#780000]' : 'bg-white text-[#1D1D1F] border-[#E5E5EA]' }}">
                {{ $label }}@if(isset($counts[$key])) <span class="ml-1 font-bold">{{ $counts[$key] }}</span>@endif
            </a>
        @endforeach
    </div>

    <div class="bg-white rounded-xl border border-[#E5E5EA] shadow-2xs overflow-x-auto">
        <table class="w-full text-left">
            <thead class="bg-[#F9F9FB] text-[#6E6E73] uppercase text-xs tracking-wider">
                <tr>
                    <th class="px-4 py-3">Case</th>
                    <th class="px-4 py-3">Booking</th>
                    <th class="px-4 py-3">Reason</th>
                    <th class="px-4 py-3">Status</th>
                    <th class="px-4 py-3 text-right">Approved</th>
                    <th class="px-4 py-3">Opened</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-[#E5E5EA]">
                @forelse($cases as $case)
                    <tr>
                        <td class="px-4 py-3 font-semibold"><a href="{{ portal_route('refund_cases.show', $case) }}" class="text-[#780000] hover:underline">#{{ $case->id }}</a></td>
                        <td class="px-4 py-3 font-mono">{{ $case->booking?->booking_number }}</td>
                        <td class="px-4 py-3">
                            {{ $case->reasonLabel() }}
                            @if($case->isHistorical())<span class="ml-1 inline-block px-2 py-0.5 rounded-full border text-xs font-semibold bg-amber-50 text-amber-800 border-amber-200">Earlier terms</span>@endif
                        </td>
                        <td class="px-4 py-3">{{ $case->confirmedPartRefundedBalanceOpen() ? 'Downpayment refunded; balance under review' : ($labels[$case->status] ?? $case->status) }}@if($case->balance_review === 'unresolved') <span class="text-amber-700 font-semibold">· balance unresolved</span>@endif</td>
                        <td class="px-4 py-3 text-right whitespace-nowrap">{{ $case->approved_centavos !== null ? '₱' . number_format($case->approved_centavos / 100, 2) : '—' }}</td>
                        <td class="px-4 py-3 whitespace-nowrap text-[#6E6E73]">{{ $case->created_at?->setTimezone('Asia/Manila')->format('M d, Y g:i A') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-8 text-center text-[#6E6E73]">No refund cases here.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $cases->links() }}
</div>
@endsection
