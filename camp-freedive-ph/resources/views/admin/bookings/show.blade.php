@extends('layouts.admin')

@section('title', $booking->formatted_class_type . ' #' . $booking->booking_number . ' | Camp FreedivePH')

@section('breadcrumb')
    <a href="{{ portal_route('bookings.index') }}" class="text-[#6E6E73] hover:text-[#780000] font-medium transition-colors">Bookings</a>
    <svg class="w-3.5 h-3.5 text-[#8E8E93] shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg>
    <span class="font-bold text-[#1D1D1F]">{{ $booking->booking_number }}</span>
@endsection

@section('content')
@php
    $pax = $booking->participants->count();
    $balance = (float) $booking->balance_amount;
    $paid = max(0, (float) $booking->total_amount - $balance);
    $pendingReschedule = $booking->rescheduleRequests->firstWhere('status', 'pending');
    $pendingCancel = $booking->cancellationRequests->firstWhere('status', 'pending');
    $creatorName = $booking->createdBy?->name;
    $facebook = trim($booking->contact_facebook ?? '');
    $label = 'block text-xs font-bold uppercase tracking-wider text-[#6E6E73] mb-0.5';
@endphp
<div class="space-y-6 text-sm" x-data="{ openStatusModal: false }">

    <!-- Header: what this booking is, its state, the two actions -->
    <div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-start">
        <div class="min-w-0 space-y-1.5">
            <div class="flex flex-wrap items-center gap-2.5">
                <h1 class="font-mono text-2xl font-extrabold tracking-tight text-[#1D1D1F] sm:text-3xl">{{ $booking->booking_number }}</h1>
                <span class="rounded-full px-2.5 py-0.5 text-sm font-bold {{ $booking->status_badge['bg'] }}">{{ $booking->status_badge['label'] }}</span>
                <span class="rounded-full px-2.5 py-0.5 text-sm font-bold {{ $booking->payment_status_badge['class'] }}">{{ $booking->payment_status_badge['label'] }}</span>
            </div>
            <p class="text-[#6E6E73]">
                <strong class="text-[#1D1D1F]">{{ $booking->formatted_class_type }}</strong>
                · {{ $booking->start_date->format('M d') }} – {{ $booking->end_date->format('M d, Y') }}
                @if($booking->batch)
                    · <a href="{{ portal_route('batches.show', $booking->batch) }}" class="font-semibold text-[#1D1D1F] underline decoration-[#D1D1D6] underline-offset-2 hover:decoration-[#1D1D1F]">{{ $booking->batch->batch_number }}</a>
                @else
                    · Not in a batch yet
                @endif
            </p>
        </div>

        <div class="flex w-full shrink-0 items-center gap-2.5 sm:w-auto">
            <a href="{{ route('admin.bookings.edit', $booking) }}" class="btn-secondary min-h-[44px] flex-1 px-4 font-semibold sm:flex-initial">Edit details</a>
            <button type="button" @click="openStatusModal = true" class="btn-primary min-h-[44px] flex-1 px-4 font-bold sm:flex-initial">Change status</button>
        </div>
    </div>

    <!-- Needs attention: a guest request is waiting -->
    @if($pendingReschedule || $pendingCancel)
        <div class="flex flex-col gap-3 rounded-xl bg-amber-50 p-4 text-amber-900 sm:flex-row sm:items-center sm:justify-between" role="status">
            <div>
                @if($pendingReschedule)
                    <strong>Reschedule requested.</strong>
                    The guest wants to move to {{ $pendingReschedule->requested_start_date?->format('M d') }} – {{ $pendingReschedule->requested_end_date?->format('M d, Y') }}{{ $pendingReschedule->reason ? ' (' . $pendingReschedule->reason . ')' : '' }}.
                @endif
                @if($pendingCancel)
                    <strong>Cancellation requested.</strong>
                    Refund under the policy: ₱{{ number_format($pendingCancel->calculated_refund_amount, 2) }}{{ $pendingCancel->reason ? ' (' . $pendingCancel->reason . ')' : '' }}.
                @endif
            </div>
            <a href="{{ route('admin.bookings.requests') }}" class="btn-secondary min-h-[44px] shrink-0">Review request</a>
        </div>
    @endif

    <!-- At a glance -->
    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <div class="kpi-tile">
            <span class="kpi-label">Participants</span>
            <span class="kpi-value">{{ $pax }}</span>
            <span class="kpi-sub">{{ $booking->participants->filter->has_health_note->count() > 0 ? $booking->participants->filter->has_health_note->count() . ' with a health note' : 'No health notes' }}</span>
        </div>
        <div class="kpi-tile">
            <span class="kpi-label">Total price</span>
            <span class="kpi-value">₱{{ number_format($booking->total_amount) }}</span>
            <span class="kpi-sub">₱{{ number_format($booking->downpayment_amount) }} downpayment</span>
        </div>
        <div class="kpi-tile">
            <span class="kpi-label">Paid</span>
            <span class="kpi-value text-emerald-700">₱{{ number_format($paid) }}</span>
            <span class="kpi-sub">{{ $booking->payments->count() }} {{ Str::plural('payment', $booking->payments->count()) }}</span>
        </div>
        <div class="kpi-tile {{ $balance > 0 ? 'border-amber-300 bg-amber-50' : '' }}">
            <span class="kpi-label">Balance due</span>
            <span class="kpi-value {{ $balance > 0 ? 'text-amber-800' : '' }}">₱{{ number_format($balance) }}</span>
            <span class="kpi-sub {{ $balance > 0 ? 'font-semibold text-amber-900' : '' }}">{{ $balance > 0 ? 'Collect at camp, before orientation' : 'Fully paid' }}</span>
        </div>
    </div>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">

        <div class="space-y-6 lg:col-span-2">

            <!-- Participants -->
            <section class="card space-y-4 sm:p-6" aria-labelledby="participants-title">
                <h2 id="participants-title" class="text-base font-bold text-[#1D1D1F]">Participant(s) ({{ $pax }} pax)</h2>

                <div class="divide-y divide-[#E5E5EA]">
                    @foreach($booking->participants as $index => $p)
                        @php($isNonSwimmer = strtolower($p->swimmer_status ?? '') === 'non_swimmer')
                        <div class="space-y-2.5 py-4 first:pt-0 last:pb-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-[#F2F2F7] text-xs font-bold text-[#1D1D1F]" aria-hidden="true">{{ $index + 1 }}</span>
                                <strong class="text-base font-bold text-[#1D1D1F]">{{ $p->name }}</strong>
                                <span class="font-medium text-[#6E6E73]">({{ $p->age }} yrs old)</span>
                                <span class="rounded-md px-2 py-0.5 font-semibold {{ $isNonSwimmer ? 'bg-rose-50 text-rose-700' : 'bg-[#F2F2F7] text-[#1D1D1F]' }}">{{ $isNonSwimmer ? 'Non-swimmer' : 'Swimmer' }}</span>
                                <x-participant-history-badge :bp="$p" />
                            </div>

                            <div class="grid grid-cols-1 gap-3 pt-1 sm:grid-cols-3">
                                <div>
                                    <span class="{{ $label }}">Gender</span>
                                    <span class="text-[#1D1D1F]">{{ $p->gender_label }}</span>
                                </div>
                                <div>
                                    <span class="{{ $label }}">Health notes</span>
                                    @if($p->has_health_note)
                                        <span class="break-words font-medium text-[#92400E]">⚠ {{ $p->health_condition }}</span>
                                    @else
                                        <span class="break-words text-[#1D1D1F]">{{ $p->health_condition ?: 'None declared' }}</span>
                                    @endif
                                </div>
                                <div>
                                    <span class="{{ $label }}">Assigned coach</span>
                                    <span class="{{ $p->coach ? 'font-bold text-[#1D1D1F]' : 'text-[#6E6E73]' }}">{{ $p->coach?->name ?? 'Not assigned yet' }}</span>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </section>

            <!-- Booker -->
            <section class="card space-y-4 sm:p-6" aria-labelledby="booker-title">
                <h2 id="booker-title" class="border-b border-[#E5E5EA] pb-3 text-base font-bold text-[#1D1D1F]">Booked by</h2>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <span class="{{ $label }}">Name</span>
                        <strong class="text-base text-[#1D1D1F]">{{ $booking->contact_name }}</strong>
                        @if($booking->contact_birthdate)
                            <span class="block text-[#6E6E73]">Born {{ $booking->contact_birthdate->format('M d, Y') }} ({{ $booking->contact_birthdate->age }} yrs old)</span>
                        @endif
                    </div>
                    <div>
                        <span class="{{ $label }}">Mobile</span>
                        <a href="tel:{{ preg_replace('/[^0-9+]/', '', $booking->contact_phone) }}" class="font-medium text-[#1D1D1F] underline decoration-[#D1D1D6] underline-offset-2 hover:decoration-[#1D1D1F]">{{ $booking->contact_phone }}</a>
                    </div>
                    <div>
                        <span class="{{ $label }}">Email</span>
                        <a href="mailto:{{ $booking->contact_email }}" class="break-all font-medium text-[#1D1D1F] underline decoration-[#D1D1D6] underline-offset-2 hover:decoration-[#1D1D1F]">{{ $booking->contact_email }}</a>
                    </div>
                    <div>
                        <span class="{{ $label }}">Facebook</span>
                        @if($facebook === '')
                            <span class="text-[#6E6E73]">Not given</span>
                        @elseif(str_starts_with($facebook, 'http'))
                            <a href="{{ $facebook }}" target="_blank" rel="noopener noreferrer" class="break-all font-medium text-[#1D1D1F] underline decoration-[#D1D1D6] underline-offset-2 hover:decoration-[#1D1D1F]">{{ $facebook }}</a>
                        @else
                            <span class="break-all font-medium text-[#1D1D1F]">{{ $facebook }}</span>
                        @endif
                    </div>
                    @if($booking->guardian_consent_at)
                        <div class="rounded-xl border border-[#FDE68A] bg-[#FFFBEB] px-3.5 py-2.5 sm:col-span-2">
                            <span class="mb-0.5 block text-xs font-bold uppercase tracking-wider text-[#92400E]">Guardian consent (participant under 18)</span>
                            <span class="font-medium text-[#1D1D1F]">
                                {{ $booking->guardian_name }} · {{ $booking->guardian_relationship === 'legal_guardian' ? 'Legal guardian' : 'Parent' }} · {{ $booking->guardian_phone }}
                            </span>
                            <span class="mt-0.5 block text-[#6E6E73]">Given {{ $booking->guardian_consent_at->format('M d, Y g:i A') }}</span>
                        </div>
                    @endif
                </div>
            </section>

            <!-- Trip -->
            <section class="card space-y-4 sm:p-6" aria-labelledby="trip-title">
                <h2 id="trip-title" class="border-b border-[#E5E5EA] pb-3 text-base font-bold text-[#1D1D1F]">Trip details</h2>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <span class="{{ $label }}">Dates</span>
                        <span class="font-medium text-[#1D1D1F]">{{ $booking->start_date->format('D, M d') }} – {{ $booking->end_date->format('D, M d, Y') }}</span>
                    </div>
                    <div>
                        <span class="{{ $label }}">Batch</span>
                        @if($booking->batch)
                            <a href="{{ portal_route('batches.show', $booking->batch) }}" class="font-medium text-[#1D1D1F] underline decoration-[#D1D1D6] underline-offset-2 hover:decoration-[#1D1D1F]">{{ $booking->batch->batch_number }}</a>
                        @else
                            <span class="text-[#6E6E73]">Not in a batch yet</span>
                        @endif
                    </div>
                    <div>
                        <span class="{{ $label }}">Class</span>
                        <span class="font-medium text-[#1D1D1F]">{{ $booking->formatted_class_type }}{{ $booking->is_certified_diver ? ' · Certified diver' : '' }}</span>
                    </div>
                    <div>
                        <span class="{{ $label }}">Transport</span>
                        <span class="font-medium text-[#1D1D1F]">{{ $booking->pickup_option === 'carpool' ? 'Camp van from Manila' : 'Own transport' }}</span>
                        @if($booking->pickup_option === 'carpool' && $booking->pickup_location)
                            <span class="block break-words text-[#6E6E73]">Pickup: {{ $booking->pickup_location }}</span>
                        @endif
                    </div>
                    <div>
                        <span class="{{ $label }}">Boat dive add-on</span>
                        <span class="font-medium text-[#1D1D1F]">{{ $booking->boat_dive ? 'Yes' : 'No' }}</span>
                    </div>
                    <div>
                        <span class="{{ $label }}">Guest PIN</span>
                        <span class="select-all font-mono text-base font-bold text-[#1D1D1F]">{{ $booking->pin }}</span>
                        <span class="block text-[#6E6E73]">With the booking number, opens Manage Booking.</span>
                    </div>
                </div>
            </section>
        </div>

        <!-- Money and policy -->
        <aside class="space-y-6">
            <section class="card space-y-4 sm:p-6" aria-labelledby="price-title">
                <h2 id="price-title" class="border-b border-[#E5E5EA] pb-3 text-base font-bold text-[#1D1D1F]">Price breakdown</h2>
                <dl class="space-y-2.5">
                    <div class="flex items-center justify-between gap-2">
                        <dt class="text-[#6E6E73]">Course fee ({{ $pax }} pax)</dt>
                        <dd class="font-semibold text-[#1D1D1F]">₱{{ number_format($booking->subtotal, 2) }}</dd>
                    </div>
                    @if($booking->carpool_fee > 0)
                        <div class="flex items-center justify-between gap-2">
                            <dt class="text-[#6E6E73]">Camp van (round trip)</dt>
                            <dd class="font-semibold text-[#1D1D1F]">₱{{ number_format($booking->carpool_fee, 2) }}</dd>
                        </div>
                    @endif
                    @if($booking->boat_dive_fee > 0)
                        <div class="flex items-center justify-between gap-2">
                            <dt class="text-[#6E6E73]">Sanctuary boat dive</dt>
                            <dd class="font-semibold text-[#1D1D1F]">₱{{ number_format($booking->boat_dive_fee, 2) }}</dd>
                        </div>
                    @endif
                    <div class="flex items-center justify-between gap-2">
                        <dt class="text-[#6E6E73]">Mabini LGU dive pass</dt>
                        <dd class="font-semibold text-[#1D1D1F]">₱{{ number_format($booking->lgu_fee, 2) }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-2">
                        <dt class="text-[#6E6E73]">Environmental fee</dt>
                        <dd class="font-semibold text-[#1D1D1F]">₱{{ number_format($booking->environmental_fee, 2) }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-2 border-t border-[#E5E5EA] pt-3 text-base">
                        <dt class="font-bold text-[#1D1D1F]">Total</dt>
                        <dd class="font-extrabold text-[#1D1D1F]">₱{{ number_format($booking->total_amount, 2) }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-2">
                        <dt class="text-[#6E6E73]">Paid</dt>
                        <dd class="font-semibold text-emerald-700">₱{{ number_format($paid, 2) }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-2">
                        <dt class="font-semibold text-[#1D1D1F]">Balance due</dt>
                        <dd class="font-bold {{ $balance > 0 ? 'text-amber-800' : 'text-[#1D1D1F]' }}">₱{{ number_format($balance, 2) }}</dd>
                    </div>
                </dl>
            </section>

            <section class="card space-y-4 sm:p-6" aria-labelledby="payments-title">
                <h2 id="payments-title" class="border-b border-[#E5E5EA] pb-3 text-base font-bold text-[#1D1D1F]">Payments received</h2>
                @forelse($booking->payments as $payment)
                    <div class="space-y-0.5 border-b border-[#F2F2F7] pb-3 last:border-0 last:pb-0">
                        <div class="flex items-center justify-between gap-2">
                            <span class="font-semibold text-[#1D1D1F]">{{ ['gcash' => 'GCash', 'maya' => 'Maya', 'bpi_bank_transfer' => 'BPI bank transfer', 'cash' => 'Cash', 'card' => 'Card'][$payment->payment_method] ?? ucwords(str_replace('_', ' ', $payment->payment_method)) }}</span>
                            <span class="font-bold text-emerald-700">₱{{ number_format($payment->amount, 2) }}</span>
                        </div>
                        <span class="block text-[#6E6E73]">{{ $payment->paid_at ? $payment->paid_at->format('M d, Y g:i A') : 'Date not recorded' }}</span>
                        <span class="block select-all break-all font-mono text-[#6E6E73]">{{ $payment->transaction_id }}</span>
                    </div>
                @empty
                    <p class="text-[#6E6E73]">No payments yet.</p>
                @endforelse
                <a href="{{ route('admin.payments.index', ['search' => $booking->booking_number]) }}" class="btn-secondary min-h-[44px] w-full">Open in Payments & Refunds</a>
            </section>

            @isset($policy)
                <section class="card space-y-3 sm:p-6" aria-labelledby="policy-title">
                    <h2 id="policy-title" class="border-b border-[#E5E5EA] pb-3 text-base font-bold text-[#1D1D1F]">If the guest asks today</h2>
                    <p class="text-[#6E6E73]">{{ max(0, $policy['days_until_dive']) }} {{ Str::plural('day', max(0, $policy['days_until_dive'])) }} before the dive.</p>
                    <dl class="space-y-2.5">
                        <div>
                            <dt class="font-semibold text-[#1D1D1F]">{{ $policy['reschedule_allowed'] ? '✓ Can reschedule' : '✕ Can’t reschedule' }}</dt>
                            <dd class="text-[#6E6E73]">{{ preg_replace('/^(Not Allowed|Allowed):\s*/', '', $policy['reschedule_message']) }}</dd>
                        </div>
                        <div>
                            <dt class="font-semibold text-[#1D1D1F]">{{ $policy['cancel_allowed'] ? '✓ Can cancel' : '✕ Can’t cancel' }}</dt>
                            <dd class="text-[#6E6E73]">{{ preg_replace('/^(Not Allowed|Allowed):\s*/', '', $policy['cancel_message']) }}</dd>
                        </div>
                    </dl>
                </section>
            @endisset
        </aside>
    </div>

    <!-- History -->
    <section class="card space-y-4 sm:p-6" aria-labelledby="history-title">
        <div class="flex flex-col justify-between gap-1 border-b border-[#E5E5EA] pb-3 sm:flex-row sm:items-center">
            <h2 id="history-title" class="text-base font-bold text-[#1D1D1F]">History</h2>
            <span class="text-[#6E6E73]">Created {{ $booking->created_at->format('M d, Y g:i A') }} by {{ $creatorName ?? 'the guest (website)' }}</span>
        </div>

        <ol class="divide-y divide-[#E5E5EA]">
            @forelse($booking->statusLogs as $log)
                <li class="space-y-1 py-3">
                    <div class="flex flex-col justify-between gap-1 sm:flex-row sm:items-baseline">
                        <span class="break-words font-bold text-[#1D1D1F]">
                            @if($log->old_status && $log->old_status !== $log->new_status && $log->old_status !== 'new')
                                {{ ucfirst(str_replace('_', ' ', $log->old_status)) }} → {{ ucfirst(str_replace('_', ' ', $log->new_status)) }}
                            @elseif($log->old_status === 'new' || $log->old_status === null)
                                Booking created ({{ str_replace('_', ' ', $log->new_status) }})
                            @else
                                Details changed
                            @endif
                        </span>
                        <span class="shrink-0 text-[#6E6E73]">{{ $log->created_at->format('M d, Y g:i A') }}</span>
                    </div>
                    @if($log->note)
                        <p class="break-words text-[#3A3A3C]">{{ $log->note }}</p>
                    @endif
                    <span class="block text-[#6E6E73]">By {{ $log->user?->name ?? ($creatorName ?? 'the guest (website)') }}</span>
                </li>
            @empty
                <li class="space-y-1 py-3">
                    <div class="flex flex-col justify-between gap-1 sm:flex-row sm:items-baseline">
                        <span class="font-bold text-[#1D1D1F]">Booking created</span>
                        <span class="shrink-0 text-[#6E6E73]">{{ $booking->created_at->format('M d, Y g:i A') }}</span>
                    </div>
                    <span class="block text-[#6E6E73]">By {{ $creatorName ?? 'the guest (website)' }}</span>
                </li>
            @endforelse
        </ol>
    </section>

    <!-- Status Change Panel -->
    <x-side-panel show="openStatusModal"
                  :eyebrow="'Booking ' . $booking->booking_number"
                  title="Update booking status"
                  :description="$booking->contact_name">
        <form id="booking-status-form" action="{{ route('admin.bookings.status.update', $booking) }}" method="POST" class="space-y-4">
            @csrf
            @method('PATCH')

            <div>
                <label for="booking-new-status" class="form-label form-label-required">New status</label>
                <select id="booking-new-status" name="status" required class="form-select w-full">
                    <option value="confirmed" {{ $booking->status === 'confirmed' ? 'selected' : '' }}>Confirmed</option>
                    <option value="completed" {{ $booking->status === 'completed' ? 'selected' : '' }}>Completed (Dive Completed)</option>
                    <option value="rescheduled" {{ $booking->status === 'rescheduled' ? 'selected' : '' }}>Rescheduled</option>
                    <option value="no_show" {{ $booking->status === 'no_show' ? 'selected' : '' }}>No-Show (Forfeits Downpayment)</option>
                    <option value="cancelled_by_camp" {{ $booking->status === 'cancelled_by_camp' ? 'selected' : '' }}>Cancelled by Camp (Weather/Admin)</option>
                    <option value="cancelled_by_guest" {{ $booking->status === 'cancelled_by_guest' ? 'selected' : '' }}>Cancelled by Participant</option>
                </select>
            </div>

            <div>
                <label for="booking-status-note" class="form-label">Reason (optional)</label>
                <textarea id="booking-status-note" name="note" rows="3" placeholder="Why is the status changing?" class="form-textarea"></textarea>
            </div>

            <div class="banner banner-warning">
                The change shows right away in the Coach Portal, batch schedule and reports. If you cancel, process the refund in <strong>Payments &amp; Refunds</strong>.
            </div>
        </form>

        <x-slot:footer>
            <button type="button" @click="openStatusModal = false" class="btn-secondary">Cancel</button>
            <button type="submit" form="booking-status-form" class="btn-primary">Update status</button>
        </x-slot:footer>
    </x-side-panel>

</div>
@endsection
