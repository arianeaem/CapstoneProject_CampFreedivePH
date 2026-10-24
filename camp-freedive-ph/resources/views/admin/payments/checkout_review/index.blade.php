@extends('layouts.admin')

@section('title', 'Checkout Review | Camp FreedivePH')

@section('breadcrumb')
    <a href="{{ portal_route('payments.index') }}" class="text-[#6E6E73] hover:text-[#780000] font-medium transition-colors">Payments &amp; Refunds</a>
    <svg class="w-3.5 h-3.5 text-[#8E8E93] shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m9 18 6-6-6-6"/></svg>
    <span class="font-bold text-[#1D1D1F]">Checkout Review</span>
@endsection

@section('content')
<div class="space-y-6 text-sm">
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl sm:text-3xl font-extrabold text-[#1D1D1F] tracking-tight">Checkout Review</h1>
            <p class="text-[#6E6E73] mt-1">Online checkouts that did not become a booking automatically. Paid cases need a manual decision; nothing here moves money.</p>
        </div>
        <a href="{{ portal_route('payments.index') }}" class="btn-secondary px-3.5 py-2 text-sm font-semibold">Payments Ledger</a>
    </div>

    <div class="flex flex-wrap gap-2">
        <a href="{{ portal_route('payments.checkout_review') }}" class="px-3 py-1.5 rounded-full border text-sm font-semibold {{ $queue === null ? 'bg-[#780000] text-white border-[#780000]' : 'bg-white text-[#1D1D1F] border-[#E5E5EA]' }}">All open</a>
        @foreach($queues as $key => $label)
            <a href="{{ portal_route('payments.checkout_review', ['queue' => $key]) }}" class="px-3 py-1.5 rounded-full border text-sm font-semibold {{ $queue === $key ? 'bg-[#780000] text-white border-[#780000]' : 'bg-white text-[#1D1D1F] border-[#E5E5EA]' }}">
                {{ $label }} <span class="ml-1 font-bold">{{ $counts[$key] }}</span>
            </a>
        @endforeach
    </div>

    <div class="bg-white rounded-xl border border-[#E5E5EA] shadow-2xs overflow-x-auto">
        <table class="w-full text-left">
            <thead class="bg-[#F9F9FB] text-[#6E6E73] uppercase text-xs tracking-wider">
                <tr>
                    <th class="px-4 py-3">Checkout</th>
                    <th class="px-4 py-3">State</th>
                    <th class="px-4 py-3">Dates</th>
                    <th class="px-4 py-3 text-right">Pax</th>
                    <th class="px-4 py-3 text-right">Paid</th>
                    <th class="px-4 py-3">Last change</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-[#E5E5EA]">
                @forelse($intents as $intent)
                    <tr>
                        <td class="px-4 py-3 font-semibold"><a href="{{ portal_route('payments.checkout_review.show', $intent) }}" class="text-[#780000] hover:underline">{{ $intent->reference }}</a></td>
                        <td class="px-4 py-3">
                            @include('admin.payments.checkout_review.state', ['intent' => $intent])
                        </td>
                        <td class="px-4 py-3 whitespace-nowrap">{{ $intent->start_date?->format('M d, Y') }} – {{ $intent->end_date?->format('M d, Y') }}</td>
                        <td class="px-4 py-3 text-right">{{ $intent->pax_count }}</td>
                        <td class="px-4 py-3 text-right whitespace-nowrap">{{ $intent->paid_amount !== null ? '₱' . number_format((float) $intent->paid_amount, 2) : '—' }}</td>
                        <td class="px-4 py-3 whitespace-nowrap text-[#6E6E73]">{{ $intent->updated_at?->setTimezone('Asia/Manila')->format('M d, Y g:i A') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-8 text-center text-[#6E6E73]">No checkouts need review.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $intents->links() }}
</div>
@endsection
