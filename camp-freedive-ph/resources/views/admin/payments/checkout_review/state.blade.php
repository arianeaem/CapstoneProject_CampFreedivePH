@php
    $label = match ($intent->status) {
        'paid_unallocated' => ['Paid, date full', 'bg-red-50 text-red-800 border-red-200'],
        'payment_review' => ['Payment review', 'bg-amber-50 text-amber-800 border-amber-200'],
        'paid' => ['Paid, booking not finished', 'bg-amber-50 text-amber-800 border-amber-200'],
        'finalized' => ['Booking confirmed', 'bg-emerald-50 text-emerald-800 border-emerald-200'],
        'pending' => ['Awaiting payment', 'bg-slate-50 text-slate-700 border-slate-200'],
        default => [ucfirst(str_replace('_', ' ', $intent->status)), 'bg-slate-50 text-slate-700 border-slate-200'],
    };
@endphp
<span class="inline-block px-2 py-0.5 rounded-full border text-xs font-bold {{ $label[1] }}">{{ $label[0] }}</span>
@if($intent->resolution_status)
    <span class="inline-block px-2 py-0.5 rounded-full border text-xs font-semibold bg-white text-[#6E6E73] border-[#E5E5EA]">Flag: {{ str_replace('_', ' ', $intent->resolution_status) }}</span>
@endif
