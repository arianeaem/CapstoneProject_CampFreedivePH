@extends('layouts.admin')

@section('title', $intent->reference . ' | Checkout Review | Camp FreedivePH')

@section('breadcrumb')
    <a href="{{ portal_route('payments.index') }}" class="text-[#6E6E73] hover:text-[#780000] font-medium transition-colors">Payments &amp; Refunds</a>
    <svg class="w-3.5 h-3.5 text-[#8E8E93] shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m9 18 6-6-6-6"/></svg>
    <a href="{{ portal_route('payments.checkout_review') }}" class="text-[#6E6E73] hover:text-[#780000] font-medium transition-colors">Checkout Review</a>
    <svg class="w-3.5 h-3.5 text-[#8E8E93] shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m9 18 6-6-6-6"/></svg>
    <span class="font-bold text-[#1D1D1F]">{{ $intent->reference }}</span>
@endsection

@section('content')
@php $v = $intent->verification ?? []; @endphp
<div class="space-y-6 text-sm">
    <div class="flex flex-col sm:flex-row sm:items-start justify-between gap-4">
        <div>
            <h1 class="text-2xl sm:text-3xl font-extrabold text-[#1D1D1F] tracking-tight">{{ $intent->reference }}</h1>
            <div class="mt-2">@include('admin.payments.checkout_review.state', ['intent' => $intent])</div>
        </div>
        @if($canRecheck)
            <form method="POST" action="{{ portal_route('payments.checkout_review.recheck', $intent) }}">
                @csrf
                <button type="submit" class="btn-primary px-4 py-2 text-sm font-bold">Re-check payment with PayMongo</button>
            </form>
        @endif
    </div>

    @if($intent->status === 'paid_unallocated')
        <div class="rounded-xl border border-red-200 bg-red-50 p-4 text-red-900">
            <strong>Manual decision needed.</strong> The customer paid, but the date was full, so no booking was created. Contact the customer and agree on another date or the next step under the existing refund and cancellation policy. Nothing on this screen moves money or creates a booking.
        </div>
    @elseif($intent->status === 'payment_review')
        <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-amber-900">
            <strong>Payment under review ({{ $intent->failure_reason ?? 'see history' }}).</strong> Do not tell the customer the booking is confirmed. Re-check with PayMongo, or check the payment in the PayMongo dashboard.
        </div>
    @elseif($intent->resolution_status)
        <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-amber-900">
            <strong>{{ $intent->resolution_note }}</strong>
        </div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <div class="bg-white rounded-xl border border-[#E5E5EA] p-4 shadow-2xs">
            <h2 class="font-bold text-base mb-3">Checkout</h2>
            <dl class="grid grid-cols-2 gap-y-2">
                <dt class="text-[#6E6E73]">Dates</dt><dd>{{ $intent->start_date?->format('M d, Y') }} – {{ $intent->end_date?->format('M d, Y') }}</dd>
                <dt class="text-[#6E6E73]">Class</dt><dd>{{ ucfirst($intent->class_type) }}</dd>
                <dt class="text-[#6E6E73]">Participants</dt><dd>{{ $intent->pax_count }}</dd>
                <dt class="text-[#6E6E73]">Total / downpayment</dt><dd>₱{{ number_format((float) $intent->total_amount, 2) }} / ₱{{ number_format((float) $intent->downpayment_amount, 2) }}</dd>
                <dt class="text-[#6E6E73]">Started</dt><dd>{{ $intent->created_at?->setTimezone('Asia/Manila')->format('M d, Y g:i A') }}</dd>
                <dt class="text-[#6E6E73]">Booking</dt><dd>{{ $intent->booking?->booking_number ?? 'None' }}</dd>
                <dt class="text-[#6E6E73]">Contact</dt><dd>{{ $contact['name'] ?? '—' }}</dd>
                <dt class="text-[#6E6E73]">Email</dt><dd class="break-all">{{ $contact['email'] ?? '—' }}</dd>
                <dt class="text-[#6E6E73]">Phone</dt><dd>{{ $contact['phone'] ?? '—' }}</dd>
            </dl>
        </div>

        <div class="bg-white rounded-xl border border-[#E5E5EA] p-4 shadow-2xs">
            <h2 class="font-bold text-base mb-3">Verified payment evidence</h2>
            @if($intent->paymongo_payment_id)
                <dl class="grid grid-cols-2 gap-y-2">
                    <dt class="text-[#6E6E73]">Amount</dt><dd>{{ $intent->paid_currency }} {{ number_format((float) $intent->paid_amount, 2) }}</dd>
                    <dt class="text-[#6E6E73]">Method</dt><dd>{{ $intent->payment_method ?? '—' }}</dd>
                    <dt class="text-[#6E6E73]">Paid at</dt><dd>{{ $intent->paid_at?->setTimezone('Asia/Manila')->format('M d, Y g:i A') ?? '—' }}</dd>
                    <dt class="text-[#6E6E73]">PayMongo payment</dt><dd class="break-all font-mono text-xs">{{ $intent->paymongo_payment_id }}</dd>
                    <dt class="text-[#6E6E73]">Checkout session</dt><dd class="break-all font-mono text-xs">{{ $intent->paymongo_checkout_session_id }}</dd>
                    <dt class="text-[#6E6E73]">Verified via</dt><dd>{{ $v['source'] ?? '—' }} {{ isset($v['verified_at']) ? '(' . \Carbon\Carbon::parse($v['verified_at'])->setTimezone('Asia/Manila')->format('M d, Y g:i A') . ')' : '' }}</dd>
                    <dt class="text-[#6E6E73]">Mode</dt><dd>{{ ($v['livemode'] ?? null) === true ? 'Live' : (($v['livemode'] ?? null) === false ? 'Test' : '—') }}</dd>
                </dl>
            @else
                <p class="text-[#6E6E73]">No verified payment is recorded for this checkout.</p>
                @if($intent->paymongo_checkout_session_id)
                    <p class="mt-2 text-xs text-[#6E6E73]">Checkout session: <span class="font-mono break-all">{{ $intent->paymongo_checkout_session_id }}</span></p>
                @endif
            @endif
        </div>
    </div>

    @if(!empty($v['review_history']))
        <div class="bg-white rounded-xl border border-[#E5E5EA] p-4 shadow-2xs">
            <h2 class="font-bold text-base mb-3">Review events</h2>
            <p class="text-xs text-[#6E6E73] mb-2">Payment-review evidence, as PayMongo reported it during verification. It is not a recorded receipt, is not counted as cash, and nothing here moves money.</p>
            <ul class="space-y-1">
                @foreach(is_array($v['review_history']) ? $v['review_history'] : [] as $event)
                    @continue(! is_array($event))
                    @php
                        $d = is_array($event['details'] ?? null) ? $event['details'] : [];
                        $actual = is_int($d['actual_centavos'] ?? null) ? $d['actual_centavos'] : null;
                        $expected = is_int($d['expected_centavos'] ?? null) ? $d['expected_centavos'] : null;
                        $currency = is_string($d['actual_currency'] ?? null) ? $d['actual_currency'] : null;
                    @endphp
                    <li><span class="font-semibold">{{ is_string($event['reason'] ?? null) ? $event['reason'] : 'review' }}</span> <span class="text-[#6E6E73]">via {{ is_string($event['source'] ?? null) ? $event['source'] : '—' }}{{ is_string($event['payment_id'] ?? null) ? ', payment ' . $event['payment_id'] : '' }}{{ is_string($event['recorded_at'] ?? null) ? ', ' . \Carbon\Carbon::parse($event['recorded_at'])->setTimezone('Asia/Manila')->format('M d, Y g:i A') : '' }}{{ is_int($event['occurrences'] ?? null) && $event['occurrences'] > 1 ? ' (seen ' . $event['occurrences'] . ' times)' : '' }}</span>
                        @if($actual !== null || $currency !== null)
                            <span class="block text-xs">PayMongo reported: {{ $actual !== null ? '₱' . number_format($actual / 100, 2) : 'amount not recorded' }}{{ $expected !== null ? ' (expected ₱' . number_format($expected / 100, 2) . ')' : '' }}{{ $currency !== null ? ', currency ' . $currency : '' }}</span>
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="bg-white rounded-xl border border-[#E5E5EA] p-4 shadow-2xs">
        <div class="flex items-center justify-between gap-4 mb-3">
            <h2 class="font-bold text-base">Messages</h2>
            @if($messages->contains('status', 'failed'))
                <form method="POST" action="{{ portal_route('payments.checkout_review.resend', $intent) }}">
                    @csrf
                    <button type="submit" class="btn-secondary px-3 py-1.5 text-sm font-semibold">Retry failed messages</button>
                </form>
            @endif
        </div>
        <table class="w-full text-left">
            <thead class="text-[#6E6E73] text-xs uppercase tracking-wider">
                <tr><th class="py-1">Message</th><th class="py-1">To</th><th class="py-1">Status</th><th class="py-1 text-right">Attempts</th><th class="py-1">Last error</th></tr>
            </thead>
            <tbody class="divide-y divide-[#E5E5EA]">
                @forelse($messages as $message)
                    <tr>
                        <td class="py-1.5">{{ str_replace('_', ' ', $message->kind) }}</td>
                        <td class="py-1.5 break-all">{{ $message->isForCustomer() ? 'Customer' : $message->recipient }}</td>
                        <td class="py-1.5 font-semibold {{ $message->status === 'failed' ? 'text-red-700' : ($message->status === 'sent' ? 'text-emerald-700' : 'text-amber-700') }}">
                            {{ ucfirst($message->status) }}{{ $message->sent_at ? ' ' . $message->sent_at->setTimezone('Asia/Manila')->format('M d, g:i A') : '' }}
                        </td>
                        <td class="py-1.5 text-right">{{ $message->attempts }}</td>
                        <td class="py-1.5 text-[#6E6E73]">{{ $message->last_error ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="py-2 text-[#6E6E73]">No messages for this checkout.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="bg-white rounded-xl border border-[#E5E5EA] p-4 shadow-2xs">
        <h2 class="font-bold text-base mb-3">History</h2>
        <ul class="divide-y divide-[#E5E5EA]">
            @forelse($history as $log)
                <li class="py-2">
                    <div class="flex justify-between gap-4"><span class="font-semibold">{{ $log->action }}</span><span class="text-[#6E6E73] whitespace-nowrap">{{ $log->created_at?->setTimezone('Asia/Manila')->format('M d, Y g:i A') }}</span></div>
                    <div class="text-[#4A4A4F]">{{ $log->description }}</div>
                </li>
            @empty
                <li class="py-2 text-[#6E6E73]">No history recorded.</li>
            @endforelse
        </ul>
    </div>
</div>
@endsection
