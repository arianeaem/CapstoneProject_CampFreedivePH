@extends('layouts.admin')

@section('title', 'Payment Exception #' . $exception->id . ' | Camp FreedivePH')

@section('breadcrumb')
    <a href="{{ portal_route('payments.exceptions') }}" class="text-[#6E6E73] hover:text-[#780000] font-medium transition-colors">Payment Exceptions</a>
    <svg class="w-3.5 h-3.5 text-[#8E8E93] shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m9 18 6-6-6-6"/></svg>
    <span class="font-bold text-[#1D1D1F]">#{{ $exception->id }}</span>
@endsection

@section('content')
@php
    $peso = fn ($c) => $c === null ? '—' : '₱' . number_format($c / 100, 2);
    $when = fn ($t) => $t?->setTimezone('Asia/Manila')->format('M d, Y g:i A');
    $open = $exception->status === 'open';
    $acted = $choices->firstWhere('status', 'acted_on');
    $input = 'w-full rounded-lg border border-[#E5E5EA] px-2 py-1.5';
@endphp
<div class="space-y-6 text-sm">
    <div>
        <h1 class="text-2xl sm:text-3xl font-extrabold text-[#1D1D1F] tracking-tight">Payment exception #{{ $exception->id }}</h1>
        <p class="text-[#6E6E73] mt-1">{{ $exception->label() }} · {{ $peso($exception->amount_centavos) }} held · {{ $exception->status }}{{ $exception->resolution ? ' (' . $exception->resolution . ')' : '' }}.
            Staff-only. Record what the guest chose, with a short text summary (never card numbers, PINs, passwords, or copied emails or phone numbers); a different owner or admin verifies it. Nothing here moves money or messages the guest. Your view of this page is recorded.</p>
    </div>

    <div class="bg-white rounded-xl border border-[#E5E5EA] p-4 shadow-2xs grid sm:grid-cols-2 gap-2">
        <div>Booking / checkout:
            @if($exception->booking)
                <a class="font-semibold text-[#780000]" href="{{ portal_route('bookings.show', $exception->booking) }}">{{ $exception->booking->booking_number }}</a> ({{ str_replace('_', ' ', $exception->booking->status) }})
            @else
                Checkout {{ $exception->checkoutIntent?->reference }} ({{ str_replace('_', ' ', (string) $exception->checkoutIntent?->status) }}) · no booking
            @endif
        </div>
        <div>Original price: {{ $peso((int) round((float) ($exception->booking ?? $exception->checkoutIntent)?->total_amount * 100)) }}, downpayment {{ $peso((int) round((float) ($exception->booking ?? $exception->checkoutIntent)?->downpayment_amount * 100)) }}</div>
        <div>Receipt: @if($exception->primaryPayment)<a class="font-mono text-xs" href="{{ portal_route('payments.show', $exception->primaryPayment) }}">{{ $exception->provider_payment_id ?? ('#' . $exception->primary_payment_id) }}</a>@endif</div>
        <div>Opened: {{ $when($exception->opened_at) }}{{ $exception->resolved_at ? ' · resolved ' . $when($exception->resolved_at) : '' }}</div>
        @if($exception->refundReview)
            <div class="sm:col-span-2 font-semibold">In owner refund review (item #{{ $exception->refundReview->id }}, {{ str_replace('_', ' ', $exception->refundReview->status) }}){{ $exception->refundReview->existing_booking_refund_id ? ' · linked refund case #' . $exception->refundReview->existing_booking_refund_id : '' }}. The money stays held until the owner decides.</div>
        @endif
    </div>

    @if($review = $exception->refundReview)
        <div class="bg-white rounded-xl border border-[#E5E5EA] p-4 shadow-2xs space-y-3">
            <h2 class="font-bold text-base">Refund decision</h2>
            <p class="text-[#6E6E73]">Received {{ $exception->currency }} {{ $peso($review->received_centavos) }} (verified payment, receipt #{{ $review->primary_payment_id }}{{ $review->booking_id ? '' : ', no booking' }}).
                Approval reserves the full verified amount with no deductions; it pays nothing out and does not close the exception. Payout and its verification are a separate step that is not available yet.</p>
            @if($review->status === 'approved_awaiting_payout')
                <p class="font-semibold">Approved {{ $review->approved_currency }} {{ $peso($review->approved_centavos) }} by {{ $review->approver_role }} #{{ $review->approved_by }} on {{ $when($review->approved_at) }} · awaiting payout (not paid).</p>
            @elseif($review->status === 'rejected_invalid')
                <p class="font-semibold">Rejected as invalid by #{{ $review->rejected_by }} on {{ $when($review->rejected_at) }}: {{ $review->rejection_reason }} The verified payment stays held.</p>
            @elseif($refundEntitlement)
                @if($refundEntitlement['blocked'])
                    <p class="text-[#780000]">{{ $refundEntitlement['blocked'] }}</p>
                @else
                    <form method="POST" action="{{ portal_route('payments.exceptions.refund_reviews.approve', [$exception, $review]) }}" class="space-y-2">
                        @csrf <input type="hidden" name="lock_version" value="{{ $review->lock_version }}">
                        <p>Entitlement now: <strong>{{ $refundEntitlement['currency'] }} {{ $peso($refundEntitlement['centavos']) }}</strong> (calculated by the system).</p>
                        <label class="block">Note (optional)<textarea name="approval_note" maxlength="500" class="{{ $input }}"></textarea></label>
                        <button class="btn-primary px-3 py-1.5 font-semibold">Approve refund</button>
                    </form>
                @endif
                <form method="POST" action="{{ portal_route('payments.exceptions.refund_reviews.reject', [$exception, $review]) }}" class="space-y-2">
                    @csrf <input type="hidden" name="lock_version" value="{{ $review->lock_version }}">
                    <label class="block">Reject as invalid or duplicate: reason (10–500 characters; the payment stays held)<textarea name="reason" required minlength="10" maxlength="500" class="{{ $input }}"></textarea></label>
                    <button class="btn-secondary px-3 py-1.5 font-semibold">Reject request</button>
                </form>
            @endif
        </div>
    @endif

    <div class="bg-white rounded-xl border border-[#E5E5EA] p-4 shadow-2xs">
        <h2 class="font-bold text-base mb-3">Guest choices</h2>
        <div class="overflow-x-auto">
        <table class="w-full text-left">
            <thead class="text-[#6E6E73] text-xs uppercase tracking-wider"><tr><th class="py-1">#</th><th class="py-1">Choice</th><th class="py-1">Status</th><th class="py-1">Evidence</th><th class="py-1">Recorded</th><th class="py-1">Verified / closed</th></tr></thead>
            <tbody class="divide-y divide-[#E5E5EA] align-top">
                @forelse($choices as $choice)
                    <tr>
                        <td class="py-1.5">#{{ $choice->id }}</td>
                        <td class="py-1.5">{{ $choice->choice === 'alternative_date' ? 'Another date: ' . $choice->requested_start_date?->format('M d') . '–' . $choice->requested_end_date?->format('M d, Y') : 'Refund review' }}</td>
                        <td class="py-1.5">{{ str_replace('_', ' ', $choice->status) }}{{ $choice->resolution_reference ? ' · ' . $choice->resolution_reference : '' }}</td>
                        <td class="py-1.5 max-w-md">
                            {{ $choice->evidence_channel }}{{ $choice->evidence_at ? ', ' . $when($choice->evidence_at) : '' }} · contact {{ str_replace('_', ' ', $choice->contact_match_method) }} {{ $choice->contact_match_outcome }}<br>
                            @if($redacted)
                                <span class="text-[#6E6E73]">Summary and reference redacted.</span>
                            @else
                                {{ $choice->evidence_summary }}{{ $choice->evidence_reference ? ' (ref. ' . $choice->evidence_reference . ')' : '' }}
                            @endif
                        </td>
                        <td class="py-1.5">{{ $names[$choice->recorded_by] ?? '#' . $choice->recorded_by }}<br>{{ $when($choice->recorded_at) }}</td>
                        <td class="py-1.5">
                            @if($choice->verified_by){{ $names[$choice->verified_by] ?? '' }}, {{ $when($choice->verified_at) }}@if(! $redacted)<br>{{ $choice->verification_note }}@endif<br>@endif
                            @if($choice->closed_by){{ $names[$choice->closed_by] ?? '' }}, {{ $when($choice->closed_at) }}@if(! $redacted)<br>{{ $choice->closed_reason }}@endif @endif
                            @if($choice->acted_by)Acted on by {{ $names[$choice->acted_by] ?? '' }}, {{ $when($choice->acted_at) }}@endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="py-2 text-[#6E6E73]">No guest choice recorded yet.</td></tr>
                @endforelse
            </tbody>
        </table>
        </div>
    </div>

    @if($open && $current)
        <div class="bg-white rounded-xl border border-[#E5E5EA] p-4 shadow-2xs space-y-4">
            <h2 class="font-bold text-base">Current choice #{{ $current->id }} ({{ $current->status }})</h2>
            @if($current->status === 'recorded')
                @if((int) $current->recorded_by === (int) $staff->id)
                    <p class="text-[#6E6E73]">You recorded this choice, so a different owner or admin must verify or reject it.</p>
                @else
                    <form method="POST" action="{{ portal_route('payments.exceptions.choices.verify', [$exception, $current]) }}" class="space-y-2">
                        @csrf <input type="hidden" name="lock_version" value="{{ $current->lock_version }}">
                        <label class="block">How did you independently confirm it? (10–500 characters)<textarea name="verification_note" required minlength="10" maxlength="500" class="{{ $input }}"></textarea></label>
                        <button class="btn-primary px-3 py-1.5 font-semibold">Verify</button>
                    </form>
                    <form method="POST" action="{{ portal_route('payments.exceptions.choices.reject', [$exception, $current]) }}" class="space-y-2">
                        @csrf <input type="hidden" name="lock_version" value="{{ $current->lock_version }}">
                        <label class="block">Why is the evidence not accepted?<textarea name="reason" required minlength="10" maxlength="500" class="{{ $input }}"></textarea></label>
                        <button class="btn-secondary px-3 py-1.5 font-semibold">Reject</button>
                    </form>
                @endif
                <form method="POST" action="{{ portal_route('payments.exceptions.choices.withdraw', [$exception, $current]) }}" class="space-y-2">
                    @csrf <input type="hidden" name="lock_version" value="{{ $current->lock_version }}">
                    <label class="block">Withdraw (recorded by mistake): reason<textarea name="reason" required minlength="10" maxlength="500" class="{{ $input }}"></textarea></label>
                    <button class="btn-secondary px-3 py-1.5 font-semibold">Withdraw</button>
                </form>
            @else
                @if($current->choice === 'refund_review')
                    <form method="POST" action="{{ portal_route('payments.exceptions.choices.refund_review', [$exception, $current]) }}">
                        @csrf <input type="hidden" name="lock_version" value="{{ $current->lock_version }}">
                        <p class="mb-2">Send to owner refund review. No amount is set and nothing is refunded; the money stays held for the owner's decision.</p>
                        <button class="btn-primary px-3 py-1.5 font-semibold">Send to owner refund review</button>
                    </form>
                @else
                    <form method="POST" action="{{ portal_route('payments.exceptions.choices.allocate', [$exception, $current]) }}">
                        @csrf <input type="hidden" name="lock_version" value="{{ $current->lock_version }}">
                        <p class="mb-2">Place the guest on {{ $current->requested_start_date->format('M d') }}–{{ $current->requested_end_date->format('M d, Y') }} at the original price and terms. It is placed only if the date has room and instructor capacity is confirmed; otherwise the attempt is recorded and nothing changes. No message is sent.</p>
                        <button class="btn-primary px-3 py-1.5 font-semibold">Allocate verified date</button>
                    </form>
                @endif
            @endif
        </div>
    @endif

    @if($open && ! $acted)
        <div class="bg-white rounded-xl border border-[#E5E5EA] p-4 shadow-2xs">
            <h2 class="font-bold text-base mb-1">{{ $current ? 'The guest changed their mind: record the new choice' : 'Record the guest\'s choice' }}</h2>
            <p class="text-[#6E6E73] mb-3">Only what the guest said, in your own words. Refer to the booking's contact details instead of copying them.</p>
            <form method="POST" action="{{ $current ? portal_route('payments.exceptions.choices.supersede', [$exception, $current]) : portal_route('payments.exceptions.choices.record', $exception) }}" class="grid sm:grid-cols-2 gap-3">
                @csrf
                @if($current)<input type="hidden" name="lock_version" value="{{ $current->lock_version }}">@endif
                <label>Choice<select name="choice" class="{{ $input }}"><option value="refund_review" @selected(old('choice') === 'refund_review')>Refund review</option><option value="alternative_date" @selected(old('choice') === 'alternative_date')>Another date (same price and terms)</option></select></label>
                <div class="grid grid-cols-2 gap-2">
                    <label>From<input type="date" name="requested_start_date" value="{{ old('requested_start_date') }}" class="{{ $input }}"></label>
                    <label>To<input type="date" name="requested_end_date" value="{{ old('requested_end_date') }}" class="{{ $input }}"></label>
                </div>
                <label>Channel<select name="evidence_channel" class="{{ $input }}">@foreach(\App\Models\PaymentExceptionChoice::CHANNELS as $c)<option value="{{ $c }}" @selected(old('evidence_channel') === $c)>{{ str_replace('_', ' ', $c) }}</option>@endforeach</select></label>
                <label>When the guest said it<input type="datetime-local" name="evidence_at" value="{{ old('evidence_at') }}" class="{{ $input }}"></label>
                <label>Reference (message id, call log entry)<input type="text" name="evidence_reference" maxlength="191" class="{{ $input }}"></label>
                <label>Contact matched by<select name="contact_match_method" class="{{ $input }}">@foreach(\App\Models\PaymentExceptionChoice::MATCH_METHODS as $m)<option value="{{ $m }}" @selected(old('contact_match_method') === $m)>{{ str_replace('_', ' ', $m) }}</option>@endforeach</select></label>
                <label class="sm:col-span-2">Summary (10–500 characters)<textarea name="evidence_summary" required minlength="10" maxlength="500" class="{{ $input }}"></textarea></label>
                @if($current)<label class="sm:col-span-2">Why the earlier choice is replaced<textarea name="reason" required minlength="10" maxlength="500" class="{{ $input }}"></textarea></label>@endif
                <label class="sm:col-span-2 flex items-center gap-2"><input type="checkbox" name="contact_match_outcome" value="matched" required> The guest's contact matches the booking or checkout</label>
                <div class="sm:col-span-2"><button class="btn-primary px-3 py-1.5 font-semibold">{{ $current ? 'Record new choice' : 'Record choice' }}</button></div>
            </form>
        </div>
    @endif

    <div class="bg-white rounded-xl border border-[#E5E5EA] p-4 shadow-2xs">
        <h2 class="font-bold text-base mb-3">Allocation attempts and history</h2>
        <ul class="space-y-1">
            @foreach($exception->allocationAttempts as $attempt)
                <li>{{ $when($attempt->attempted_at) }} · allocation {{ $attempt->outcome }}{{ $attempt->reason ? ' (' . str_replace('_', ' ', $attempt->reason) . ')' : '' }} for {{ $attempt->requested_start_date?->format('M d, Y') }}{{ $attempt->booking_id ? ' · booking #' . $attempt->booking_id : '' }}</li>
            @endforeach
            @foreach($exception->events->where('type', '!=', 'evidence_viewed') as $event)
                <li class="text-[#6E6E73]">{{ $when($event->created_at) }} · {{ str_replace('_', ' ', $event->type) }}</li>
            @endforeach
        </ul>
    </div>
</div>
@endsection
