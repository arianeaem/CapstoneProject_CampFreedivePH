@extends('layouts.admin')

@section('title', 'Guest Choice Queue | Camp FreedivePH')

@section('breadcrumb')
    <a href="{{ portal_route('payments.exceptions') }}" class="text-[#6E6E73] hover:text-[#780000] font-medium transition-colors">Payment Exceptions</a>
    <svg class="w-3.5 h-3.5 text-[#8E8E93] shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m9 18 6-6-6-6"/></svg>
    <span class="font-bold text-[#1D1D1F]">Guest choice queue</span>
@endsection

@section('content')
@php
    $when = fn ($t) => $t?->setTimezone('Asia/Manila')->format('M d, Y g:i A');
    $what = fn ($c) => $c->choice === 'alternative_date' ? 'Another date: ' . $c->requested_start_date?->format('M d, Y') : 'Refund review';
@endphp
<div class="space-y-6 text-sm">
    <div>
        <h1 class="text-2xl sm:text-3xl font-extrabold text-[#1D1D1F] tracking-tight">Guest choice queue</h1>
        <p class="text-[#6E6E73] mt-1">Guest choices waiting for an independent check or for action, and the money still held. Open an exception to see its evidence (each view is recorded).</p>
    </div>

    <div class="bg-white rounded-xl border border-[#E5E5EA] p-4 shadow-2xs">
        <h2 class="font-bold text-base mb-3">Unresolved payment exceptions</h2>
        <table class="w-full text-left">
            <thead class="text-[#6E6E73] text-xs uppercase tracking-wider"><tr><th class="py-1">Kind</th><th class="py-1">Open</th><th class="py-1 text-right">Held</th><th class="py-1">Oldest</th></tr></thead>
            <tbody class="divide-y divide-[#E5E5EA]">
                @forelse($unresolved as $kind => $row)
                    <tr><td class="py-1.5">{{ \App\Models\PaymentException::KINDS[$kind] ?? str_replace('_', ' ', $kind) }}</td><td class="py-1.5">{{ $row['count'] }}</td>
                        <td class="py-1.5 text-right">₱{{ number_format($row['centavos'] / 100, 2) }}</td><td class="py-1.5">{{ $when($row['oldest']) }}</td></tr>
                @empty
                    <tr><td colspan="4" class="py-2 text-[#6E6E73]">None.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @foreach([['Waiting for independent verification', $awaitingVerification, 'recorder', 'recorded_at'], ['Verified, waiting for action', $awaitingAction, 'verifier', 'verified_at']] as [$title, $rows, $who, $at])
        <div class="bg-white rounded-xl border border-[#E5E5EA] p-4 shadow-2xs">
            <h2 class="font-bold text-base mb-3">{{ $title }} ({{ $rows->count() }})</h2>
            <table class="w-full text-left">
                <thead class="text-[#6E6E73] text-xs uppercase tracking-wider"><tr><th class="py-1">Exception</th><th class="py-1">Choice</th><th class="py-1">By</th><th class="py-1">When</th></tr></thead>
                <tbody class="divide-y divide-[#E5E5EA]">
                    @forelse($rows as $choice)
                        <tr><td class="py-1.5"><a class="font-semibold text-[#780000]" href="{{ portal_route('payments.exceptions.show', $choice->payment_exception_id) }}">#{{ $choice->payment_exception_id }}</a></td>
                            <td class="py-1.5">{{ $what($choice) }}</td><td class="py-1.5">{{ $choice->{$who}?->name }}</td><td class="py-1.5">{{ $when($choice->{$at}) }}</td></tr>
                    @empty
                        <tr><td colspan="4" class="py-2 text-[#6E6E73]">None.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endforeach

    <div class="bg-white rounded-xl border border-[#E5E5EA] p-4 shadow-2xs">
        <h2 class="font-bold text-base mb-3">Owner refund review ({{ $reviews->count() }})</h2>
        <table class="w-full text-left">
            <thead class="text-[#6E6E73] text-xs uppercase tracking-wider"><tr><th class="py-1">Item</th><th class="py-1">Exception</th><th class="py-1 text-right">Received</th><th class="py-1">Linked refund case</th><th class="py-1">Routed</th></tr></thead>
            <tbody class="divide-y divide-[#E5E5EA]">
                @forelse($reviews as $review)
                    <tr><td class="py-1.5">#{{ $review->id }} · {{ str_replace('_', ' ', $review->status) }}</td><td class="py-1.5"><a class="font-semibold text-[#780000]" href="{{ portal_route('payments.exceptions.show', $review->payment_exception_id) }}">#{{ $review->payment_exception_id }}</a></td>
                        <td class="py-1.5 text-right">₱{{ number_format($review->received_centavos / 100, 2) }}</td><td class="py-1.5">{{ $review->existing_booking_refund_id ? '#' . $review->existing_booking_refund_id : '—' }}</td><td class="py-1.5">{{ $when($review->routed_at) }}</td></tr>
                @empty
                    <tr><td colspan="5" class="py-2 text-[#6E6E73]">None.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
