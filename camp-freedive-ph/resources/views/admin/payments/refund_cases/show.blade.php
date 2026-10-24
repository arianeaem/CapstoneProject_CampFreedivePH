@extends('layouts.admin')

@section('title', 'Refund Case #' . $case->id . ' | Camp FreedivePH')

@section('breadcrumb')
    <a href="{{ portal_route('payments.index') }}" class="text-[#6E6E73] hover:text-[#780000] font-medium transition-colors">Payments &amp; Refunds</a>
    <svg class="w-3.5 h-3.5 text-[#8E8E93] shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m9 18 6-6-6-6"/></svg>
    <a href="{{ portal_route('refund_cases.index') }}" class="text-[#6E6E73] hover:text-[#780000] font-medium transition-colors">Refund Cases</a>
    <svg class="w-3.5 h-3.5 text-[#8E8E93] shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m9 18 6-6-6-6"/></svg>
    <span class="font-bold text-[#1D1D1F]">#{{ $case->id }}</span>
@endsection

@section('content')
@php
    $peso = fn ($c) => '₱' . number_format(($c ?? 0) / 100, 2);
    $open = in_array($case->status, ['awaiting_guest_choice', 'awaiting_staff_review'], true);
@endphp
<div class="space-y-6 text-sm">
    <div>
        <h1 class="text-2xl sm:text-3xl font-extrabold text-[#1D1D1F] tracking-tight">Refund case #{{ $case->id }} · {{ $case->booking?->booking_number }}</h1>
        <p class="mt-1 font-semibold">{{ $case->confirmedPartRefundedBalanceOpen() ? 'Downpayment refunded; balance under review (case stays open)' : ($labels[$case->status] ?? $case->status) }} · {{ $case->reasonLabel() }} · activity started: {{ $case->activity_started }}</p>
    </div>

    @if($case->isHistorical())
        <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-amber-900">
            <strong>Booked under the earlier published terms.</strong> Those terms quoted {{ $peso($case->quoted_centavos) }} for this cancellation (kept on record). Nothing is repriced, paid or closed automatically: record the earlier-terms decision below, with a rationale. Only the owner can apply the current terms instead, and only the owner can then close the case without that refund.
            @if($case->historical_decision)
                <div class="mt-2">Decision: <strong>{{ str_replace('_', ' ', $case->historical_decision) }}</strong>
                    by user #{{ $case->historical_decided_by }} on {{ $case->historical_decided_at?->setTimezone('Asia/Manila')->format('M d, Y g:i A') }}. Rationale: {{ $case->historical_rationale }}</div>
            @endif
        </div>
    @endif
    @if($case->balance_review === 'unresolved')
        <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-amber-900">
            <strong>Balance unresolved.</strong> The guest paid more than the downpayment. Only the downpayment can be approved under the confirmed weather rule; the rest is not forfeited and not refunded until the client policy is confirmed. This case stays open.
        </div>
    @endif
    @if($position['reconciliation_needed'])
        <div class="rounded-xl border border-red-200 bg-red-50 p-4 text-red-900"><strong>Reconciliation needed:</strong> older refund records on this booking are incomplete or inconsistent. Check them in PayMongo before approving.</div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <div class="bg-white rounded-xl border border-[#E5E5EA] p-4 shadow-2xs">
            <h2 class="font-bold text-base mb-3">Money on this booking</h2>
            <dl class="grid grid-cols-2 gap-y-2">
                <dt class="text-[#6E6E73]">Paid (receipts)</dt><dd>{{ $peso($position['paid']) }}</dd>
                <dt class="text-[#6E6E73]">Refunded (confirmed)</dt><dd>{{ $peso($position['refunded']) }}</dd>
                <dt class="text-[#6E6E73]">In progress (reserved)</dt><dd>{{ $peso($position['reserved']) }}</dd>
                <dt class="text-[#6E6E73]">Still refundable</dt><dd>{{ $peso($position['refundable']) }}</dd>
                <dt class="text-[#6E6E73]">Downpayment</dt><dd>₱{{ number_format((float) $case->booking?->downpayment_amount, 2) }}</dd>
                <dt class="text-[#6E6E73]">Policy allows now</dt><dd>{{ $entitlement['blocked'] ? '— (' . $entitlement['blocked'] . ')' : $peso($entitlement['cap']) }}</dd>
                <dt class="text-[#6E6E73]">Approved</dt><dd>{{ $case->approved_centavos !== null ? $peso($case->approved_centavos) : '—' }}</dd>
                <dt class="text-[#6E6E73]">Guest's choice</dt><dd>{{ $case->guest_choice ?? 'not recorded' }}</dd>
                @if($case->requested_centavos !== null)<dt class="text-[#6E6E73]">Staff asked for</dt><dd>{{ $peso($case->requested_centavos) }} (not approved)</dd>@endif
            </dl>
        </div>

        <div class="bg-white rounded-xl border border-[#E5E5EA] p-4 shadow-2xs space-y-4">
            <h2 class="font-bold text-base">Next steps</h2>
            @if($open)
                <form method="POST" action="{{ portal_route('refund_cases.classify', $case) }}" class="space-y-2">
                    @csrf
                    <label class="form-label">Confirm the reason and whether the activity had started</label>
                    <select name="reason_category" class="form-select">
                        @foreach(\App\Models\BookingRefund::REASONS as $key => $label)
                            <option value="{{ $key }}" @selected($case->reason_category === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                    <select name="activity_started" class="form-select">
                        @foreach(['no' => 'Activity had not started', 'yes' => 'Activity had started', 'unknown' => 'Not known'] as $key => $label)
                            <option value="{{ $key }}" @selected($case->activity_started === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                    <input name="note" required maxlength="500" class="form-input" placeholder="How you confirmed it">
                    <button class="btn-secondary px-3 py-1.5 text-sm font-semibold">Save reason</button>
                </form>

                <form method="POST" action="{{ portal_route('refund_cases.choice', $case) }}" class="space-y-2">
                    @csrf
                    <label class="form-label">Record the guest's choice</label>
                    <select name="guest_choice" class="form-select"><option value="refund">Refund</option><option value="reschedule">Move to another date (closes this case)</option></select>
                    <input name="note" required maxlength="500" class="form-input" placeholder="e.g. Guest replied by email on Nov 3">
                    <button class="btn-secondary px-3 py-1.5 text-sm font-semibold">Save choice</button>
                </form>

                @if($case->isHistorical() && ((int) $case->quoted_centavos > 0 || $case->reason_category === 'guest_voluntary'))
                    <form method="POST" action="{{ portal_route('refund_cases.historical', $case) }}" class="space-y-2">
                        @csrf
                        <label class="form-label">Earlier-terms decision</label>
                        <select name="decision" class="form-select">
                            <option value="honor_previous_terms">Honor the earlier terms (up to {{ $peso($case->quoted_centavos) }})</option>
                            <option value="apply_current_terms">Apply the current terms instead (owner only; the case stays open)</option>
                        </select>
                        <textarea name="rationale" required minlength="10" maxlength="1000" class="form-textarea" placeholder="Why (recorded in the audit log)"></textarea>
                        <button class="btn-secondary px-3 py-1.5 text-sm font-semibold">Save decision</button>
                    </form>
                @endif

                @if(! $entitlement['blocked'] && $entitlement['cap'] > 0)
                    <form method="POST" action="{{ portal_route('refund_cases.approve', $case) }}" class="space-y-2">
                        @csrf
                        <label class="form-label">Approve an amount (max {{ $peso($entitlement['cap']) }}). This does not send money.</label>
                        <input name="amount" type="number" step="0.01" min="0.01" max="{{ $entitlement['cap'] / 100 }}" required class="form-input" value="{{ $entitlement['cap'] / 100 }}">
                        <input name="note" required maxlength="500" class="form-input" placeholder="Approval note">
                        <button class="btn-primary px-3 py-1.5 text-sm font-bold">Approve amount</button>
                    </form>
                @endif
            @endif

            @if($case->status === 'approved')
                <form method="POST" action="{{ portal_route('refund_cases.execute', $case) }}" onsubmit="return confirm('Send the approved refund now? Each payment is sent to PayMongo once; unclear answers are never re-sent.')">
                    @csrf
                    <button class="btn-primary px-3 py-1.5 text-sm font-bold">Send refund ({{ $peso($case->approved_centavos) }})</button>
                </form>
            @endif

            @if(in_array($case->status, ['awaiting_guest_choice', 'awaiting_staff_review', 'approved'], true) && $case->balance_review !== 'unresolved')
                <form method="POST" action="{{ portal_route('refund_cases.close', $case) }}" class="space-y-2">
                    @csrf
                    <input name="note" required minlength="10" maxlength="500" class="form-input" placeholder="Why close without (further) refund (recorded)">
                    <button class="btn-secondary px-3 py-1.5 text-sm font-semibold">Close without refund</button>
                </form>
            @endif
        </div>
    </div>

    <div class="bg-white rounded-xl border border-[#E5E5EA] p-4 shadow-2xs">
        <h2 class="font-bold text-base mb-3">Refund attempts</h2>
        <table class="w-full text-left">
            <thead class="text-[#6E6E73] text-xs uppercase tracking-wider"><tr><th class="py-1">Payment</th><th class="py-1">Method</th><th class="py-1 text-right">Amount</th><th class="py-1">Status</th><th class="py-1">PayMongo refund</th><th class="py-1">Action</th></tr></thead>
            <tbody class="divide-y divide-[#E5E5EA]">
                @forelse($case->attempts as $attempt)
                    @php $stale = $attempt->status === 'submitting' && $attempt->submitted_at?->lt(now()->subMinutes($staleMinutes)); @endphp
                    <tr>
                        <td class="py-1.5">#{{ $attempt->payment_id }} ({{ $attempt->payment?->payment_type }})</td>
                        <td class="py-1.5">{{ $attempt->method }}</td>
                        <td class="py-1.5 text-right">{{ $peso($attempt->amount_centavos) }}</td>
                        <td class="py-1.5 font-semibold">{{ $stale ? 'unknown (submission interrupted)' : str_replace('_', ' ', $attempt->status) }}{{ $attempt->error_code ? ' · ' . $attempt->error_code : '' }}</td>
                        <td class="py-1.5 font-mono text-xs">{{ $attempt->provider_refund_id ?? '—' }}</td>
                        <td class="py-1.5">
                            @if(in_array($attempt->status, ['unknown', 'provider_pending'], true) || $stale)
                                <form method="POST" action="{{ portal_route('refund_cases.attempts.verify', [$case, $attempt]) }}" class="space-y-1">
                                    @csrf
                                    @if(! $attempt->provider_refund_id)
                                        <input name="provider_refund_id" class="form-input" placeholder="ref_... found in the PayMongo dashboard">
                                    @endif
                                    <input name="note" maxlength="1000" class="form-input" placeholder="Note (kept as unverified evidence)">
                                    <button class="btn-secondary px-2 py-1 text-xs font-semibold">Check with PayMongo</button>
                                    <p class="text-xs text-[#6E6E73]">The outcome changes only if PayMongo confirms this refund for the same payment, amount and operation. Until then it stays reserved and is never re-sent.</p>
                                </form>
                            @elseif($attempt->status === 'offline_awaiting_payout')
                                <form method="POST" action="{{ portal_route('refund_cases.attempts.offline', [$case, $attempt]) }}" class="space-y-1">
                                    @csrf
                                    <input name="evidence" required minlength="10" class="form-input" placeholder="How and when it was paid back (reference)">
                                    <button class="btn-secondary px-2 py-1 text-xs font-semibold">Confirm payout</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="py-2 text-[#6E6E73]">No refund has been sent for this case.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="bg-white rounded-xl border border-[#E5E5EA] p-4 shadow-2xs">
        <h2 class="font-bold text-base mb-3">History</h2>
        @if($case->review_note)<p class="mb-2 text-[#4A4A4F]">{{ $case->review_note }}</p>@endif
        <ul class="divide-y divide-[#E5E5EA]">
            @forelse($history as $log)
                <li class="py-2"><span class="font-semibold">{{ $log->action }}</span> <span class="text-[#6E6E73]">{{ $log->created_at?->setTimezone('Asia/Manila')->format('M d, Y g:i A') }} · {{ $log->actor_name }}</span><div class="text-[#4A4A4F]">{{ $log->description }}</div></li>
            @empty
                <li class="py-2 text-[#6E6E73]">No history yet.</li>
            @endforelse
        </ul>
    </div>
</div>
@endsection
