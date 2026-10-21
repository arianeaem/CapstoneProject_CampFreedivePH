@extends('layouts.admin')

@section('title', $batch->display_name . ' - Batch Dashboard | Camp FreedivePH')

@section('breadcrumb')
    <a href="{{ portal_route('batches.index') }}" class="text-[#6E6E73] hover:text-[#780000] font-medium transition-colors">Batches</a>
    <svg class="w-3.5 h-3.5 text-[#6E6E73] shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m9 18 6-6-6-6"/></svg>
    <span class="font-bold text-[#1D1D1F]">{{ $batch->batch_number }}</span>
@endsection

@section('content')
@php
    $isActive = in_array($batch->status, ['confirmed', 'open']);
    $activeBookings = $batch->bookings->whereNotIn('status', ['cancelled_by_camp', 'cancelled_by_guest', 'cancelled', 'pending_downpayment']);
    $participantCount = $batch->total_participants_count;
    $capacity = $batch->computed_capacity;
    $occPct = (int) round($batch->occupancy_percentage ?? 0);
    $spotsLeft = max(0, $capacity - $participantCount);
    $coaches = $assignedCoaches ?? collect();
    $balanceDue = $batch->outstanding_balance_bookings->sum('balance_amount');
    $vanBookings = $activeBookings->where('pickup_option', 'carpool');
    $vanRiders = $vanBookings->sum(fn($b) => $b->participants->count());
    $assignRoute = auth()->user()->isOwner() ? route('owner.batches.assign_participant', $batch) : route('admin.batches.assign_participant', $batch);
@endphp
<div class="space-y-6 text-sm" x-data="{
    openCancelModal: false,
    openRescheduleModal: false,
    openMoveModal: false,
    openCompleteModal: false,
    openReactivateModal: false,
    openActionsMenu: false,
    selectedBookingId: null,
    selectedBookingNumber: ''
}">

    <!-- Header: name, status, dates, actions -->
    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div class="min-w-0 space-y-1">
            <div class="flex flex-wrap items-center gap-2.5">
                <h1 class="text-2xl font-extrabold tracking-tight text-[#1D1D1F] sm:text-3xl">{{ $batch->batch_number }}</h1>
                <span class="rounded-full px-2.5 py-0.5 text-sm font-bold {{ $batch->status_badge['class'] }}">{{ $batch->status_badge['label'] }}</span>
            </div>
            <p class="text-[#6E6E73]"><strong class="text-[#1D1D1F]">{{ $batch->formatted_date_range }}</strong></p>
        </div>

        <div class="flex items-center gap-2.5">
            <a href="{{ portal_route('batches.attendance', $batch) }}" target="_blank" rel="noopener"
               class="btn-secondary min-h-[44px] inline-flex items-center px-4 font-bold">Print attendance sheet</a>

            <button type="button"
                    @click="{{ $isActive ? 'openCompleteModal' : 'openReactivateModal' }} = true"
                    class="btn-primary min-h-[44px] flex-1 px-4 font-bold sm:flex-initial">
                {{ $isActive ? 'Mark as completed' : 'Reactivate batch' }}
            </button>

            <div class="relative" @click.outside="openActionsMenu = false" @keydown.escape="openActionsMenu = false">
                <button type="button"
                        @click="openActionsMenu = !openActionsMenu"
                        :aria-expanded="openActionsMenu"
                        aria-haspopup="true"
                        aria-label="More batch actions"
                        class="inline-flex h-11 w-11 cursor-pointer items-center justify-center rounded-xl border border-[#E5E5EA] bg-white text-[#1D1D1F] transition-colors hover:bg-[#F2F2F7] focus:outline-none focus-visible:ring-2 focus-visible:ring-[#780000]">
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="12" cy="12" r="1.75"/><circle cx="19" cy="12" r="1.75"/><circle cx="5" cy="12" r="1.75"/></svg>
                </button>

                <div x-show="openActionsMenu" x-cloak x-transition.opacity.duration.100ms class="menu-panel absolute right-0 z-20 mt-2" role="menu">
                    <button type="button" role="menuitem" @click="openActionsMenu = false; openRescheduleModal = true" class="menu-item">Reschedule batch</button>
                    @if($isActive)
                        <div class="menu-divider"></div>
                        <button type="button" role="menuitem" @click="openActionsMenu = false; openCancelModal = true" class="menu-item menu-item-danger">Cancel batch</button>
                    @endif
                </div>
            </div>
        </div>
    </div>

    @if($batch->status === 'cancelled_by_camp' && $batch->cancellation_reason)
        <div class="banner banner-error">
            <strong>Cancelled by the camp{{ $batch->cancelled_at ? ' on ' . $batch->cancelled_at->format('M d, Y g:i A') : '' }}.</strong>
            Reason: {{ $batch->cancellation_reason }}
        </div>
    @endif

    <!-- At a glance -->
    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <div class="kpi-tile">
            <span class="kpi-label">Participants</span>
            <span class="kpi-value">{{ $participantCount }} <span class="text-base font-semibold text-[#6E6E73]">/ {{ $capacity }}</span></span>
            <div role="progressbar" aria-valuenow="{{ $occPct }}" aria-valuemin="0" aria-valuemax="100" aria-label="{{ $occPct }}% full"
                 class="mt-2 h-1.5 w-full overflow-hidden rounded-full bg-[#E5E5EA]">
                <div class="h-full rounded-full {{ $occPct >= 100 ? 'bg-[#780000]' : ($occPct > 70 ? 'bg-amber-600' : 'bg-emerald-600') }}" style="width: {{ min(100, $occPct) }}%"></div>
            </div>
            <span class="kpi-sub">{{ $spotsLeft > 0 ? $spotsLeft . ' ' . Str::plural('spot', $spotsLeft) . ' left' : 'Full' }}</span>
        </div>

        <div class="kpi-tile">
            <span class="kpi-label">Bookings</span>
            <span class="kpi-value">{{ $activeBookings->count() }}</span>
            <span class="kpi-sub">{{ $vanRiders > 0 ? $vanRiders . ' riding the camp van' : 'All own transport' }}</span>
        </div>

        <div class="kpi-tile {{ $batch->is_coach_pending ? 'border-amber-300 bg-amber-50' : '' }}">
            <span class="kpi-label">Coaches</span>
            <span class="kpi-value">{{ $coaches->count() }}</span>
            <span class="kpi-sub {{ $batch->is_coach_pending ? 'font-semibold text-amber-900' : '' }}">
                {{ $batch->is_coach_pending ? 'No coach assigned yet' : ($coaches->isEmpty() ? 'None needed yet' : $coaches->pluck('name')->join(', ')) }}
            </span>
        </div>

        <div class="kpi-tile">
            <span class="kpi-label">Balance to collect</span>
            <span class="kpi-value {{ $balanceDue > 0 ? 'text-amber-800' : '' }}">₱{{ number_format($balanceDue) }}</span>
            <span class="kpi-sub">
                {{ $batch->outstanding_balance_bookings_count > 0 ? 'From ' . $batch->outstanding_balance_bookings_count . ' ' . Str::plural('booking', $batch->outstanding_balance_bookings_count) : 'Everyone has paid' }}
            </span>
        </div>
    </div>

    @if($batch->capacity_note)
        <p class="text-[#6E6E73]"><strong class="text-[#1D1D1F]">Capacity note:</strong> {{ $batch->capacity_note }}</p>
    @endif

    <div class="grid grid-cols-1 gap-6 xl:grid-cols-3">

        <!-- Bookings and their participants -->
        <section class="space-y-4 xl:col-span-2" aria-labelledby="batch-bookings-title">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2 id="batch-bookings-title" class="text-base font-bold text-[#1D1D1F]">
                    Bookings <span class="font-semibold text-[#6E6E73]">({{ $activeBookings->count() }})</span>
                </h2>
                @if($coaches->isNotEmpty())
                    <p class="text-[#6E6E73]">Changing a participant's coach saves right away.</p>
                @endif
            </div>

            <div class="space-y-4">
                @forelse($activeBookings as $booking)
                    <article class="flex flex-col rounded-2xl border border-[#E5E5EA] bg-white shadow-2xs">
                        <!-- Who booked, status -->
                        <div class="flex flex-col gap-2 border-b border-[#F2F2F7] px-4 py-3.5 sm:flex-row sm:items-start sm:justify-between sm:gap-3 sm:px-5">
                            <div class="min-w-0">
                                <a href="{{ route('admin.bookings.show', $booking) }}" class="whitespace-nowrap font-mono text-base font-bold text-[#1D1D1F] hover:text-[#780000]">{{ $booking->booking_number }}</a>
                                <p class="break-words text-[#6E6E73]">{{ $booking->contact_name }} · {{ ucfirst($booking->class_type ?? 'Discovery') }}</p>
                            </div>
                            <span class="shrink-0 self-start whitespace-nowrap rounded-full px-2.5 py-0.5 text-sm font-bold {{ $booking->status_badge['class'] }}">{{ $booking->status_badge['label'] }}</span>
                        </div>

                        <!-- Trip details -->
                        <dl class="grid grid-cols-2 gap-3 px-4 pt-3.5 sm:px-5">
                            <div>
                                <dt class="text-[#6E6E73]">Transport</dt>
                                <dd class="font-semibold text-[#1D1D1F]">{{ $booking->pickup_option === 'carpool' ? 'Camp van · ' . ($booking->pickup_location ?: 'Metro Manila') : 'Own transport' }}</dd>
                            </div>
                            <div>
                                <dt class="text-[#6E6E73]">Balance</dt>
                                <dd class="font-semibold {{ (float) $booking->balance_amount > 0 ? 'text-amber-800' : 'text-emerald-700' }}">
                                    {{ (float) $booking->balance_amount > 0 ? '₱' . number_format($booking->balance_amount, 2) . ' due' : 'Paid' }}
                                </dd>
                            </div>
                        </dl>

                        <!-- Participants (same style as the booking page) -->
                        <div class="flex-1 space-y-3 px-4 py-3.5 sm:px-5">
                            <h3 class="font-bold text-[#1D1D1F]">Participant(s) ({{ $booking->participants->count() }} pax)</h3>
                            <div class="divide-y divide-[#E5E5EA]">
                            @foreach($booking->participants as $index => $p)
                                @php
                                    $coach = $p->coach;
                                    $hasMedical = $p->has_health_note;
                                    $isNonSwimmer = strtolower($p->swimmer_status ?? '') === 'non_swimmer';
                                @endphp
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
                                            <span class="mb-0.5 block text-xs font-bold uppercase tracking-wider text-[#6E6E73]">Gender</span>
                                            <span class="text-[#1D1D1F]">{{ $p->gender_label }}</span>
                                        </div>
                                        <div>
                                            <span class="mb-0.5 block text-xs font-bold uppercase tracking-wider text-[#6E6E73]">Health notes</span>
                                            <span class="break-words {{ $hasMedical ? 'font-medium text-[#92400E]' : 'text-[#1D1D1F]' }}">{{ $hasMedical ? '⚠ ' . $p->health_condition : ($p->health_condition ?: 'None declared') }}</span>
                                        </div>
                                        <div>
                                            @if($coaches->isNotEmpty())
                                                <form action="{{ $assignRoute }}" method="POST">
                                                    @csrf
                                                    <input type="hidden" name="participant_id" value="{{ $p->id }}">
                                                    <label for="coach-{{ $p->id }}" class="mb-1 block text-xs font-bold uppercase tracking-wider text-[#6E6E73]">Assigned coach</label>
                                                    <select id="coach-{{ $p->id }}" name="coach_id" onchange="this.form.submit()"
                                                            class="form-select w-full min-h-[44px] {{ $coach ? 'font-bold' : 'text-[#6E6E73]' }}">
                                                        <option value="" @selected(!$coach)>Not assigned yet</option>
                                                        @foreach($coaches as $batchCoach)
                                                            <option value="{{ $batchCoach->id }}" @selected($coach && $coach->id === $batchCoach->id)>{{ $batchCoach->name }}</option>
                                                        @endforeach
                                                    </select>
                                                </form>
                                            @else
                                                <span class="mb-0.5 block text-xs font-bold uppercase tracking-wider text-[#6E6E73]">Assigned coach</span>
                                                <span class="{{ $coach ? 'font-bold text-[#1D1D1F]' : 'text-[#6E6E73]' }}">{{ $coach ? $coach->name : 'Not assigned yet' }}</span>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                            </div>
                        </div>

                        <div class="flex items-center justify-between gap-2 border-t border-[#F2F2F7] px-4 py-3 sm:px-5">
                            <button type="button"
                                    @click="selectedBookingId = {{ $booking->id }}; selectedBookingNumber = '{{ $booking->booking_number }}'; openMoveModal = true"
                                    class="btn-ghost min-h-[44px]">
                                Move to another batch
                            </button>
                            <a href="{{ route('admin.bookings.show', $booking) }}" class="btn-secondary min-h-[44px]">View booking</a>
                        </div>
                    </article>
                @empty
                    <div class="col-span-full rounded-2xl border border-dashed border-[#D1D1D6] bg-white p-8 text-center text-[#6E6E73]">
                        No bookings in this batch yet. New bookings for {{ $batch->formatted_date_range }} appear here.
                    </div>
                @endforelse
            </div>
        </section>

        <!-- Sidebar: coaches, transport, payments -->
        <aside class="grid content-start gap-6 md:grid-cols-2 xl:grid-cols-1">
            <div class="card space-y-3">
                <div class="flex items-center justify-between">
                    <h2 class="text-base font-bold text-[#1D1D1F]">Coaches</h2>
                    <span class="text-[#6E6E73]">{{ $coaches->count() }}</span>
                </div>
                @if($batch->is_coach_pending)
                    <div class="banner banner-warning">This batch has participants but no coach yet.</div>
                @elseif($coaches->isNotEmpty())
                    <ul class="divide-y divide-[#F2F2F7]">
                        @foreach($coaches as $c)
                            @php($coachPax = $activeBookings->flatMap->participants->filter(fn($p) => $p->coach?->id === $c->id)->count())
                            <li class="flex items-center justify-between gap-2 py-2 first:pt-0 last:pb-0">
                                <span class="font-semibold text-[#1D1D1F]">{{ $c->name }}</span>
                                <span class="text-[#6E6E73]">{{ $coachPax }} {{ Str::plural('participant', $coachPax) }}</span>
                            </li>
                        @endforeach
                    </ul>
                @else
                    <p class="text-[#6E6E73]">No coaches yet. Add one when bookings come in.</p>
                @endif
                <a href="{{ route('admin.coaches.matching') }}" class="btn-secondary min-h-[44px] w-full">Manage coaches</a>
            </div>

            <div class="card space-y-3">
                <div class="flex items-center justify-between">
                    <h2 class="text-base font-bold text-[#1D1D1F]">Camp van</h2>
                    <span class="text-[#6E6E73]">{{ $vanRiders }} {{ Str::plural('rider', $vanRiders) }}</span>
                </div>
                @if($vanBookings->isNotEmpty())
                    <ul class="divide-y divide-[#F2F2F7]">
                        @foreach($vanBookings as $vb)
                            <li class="flex items-center justify-between gap-2 py-2 first:pt-0 last:pb-0">
                                <div class="min-w-0">
                                    <span class="block break-words font-semibold text-[#1D1D1F]">{{ $vb->pickup_location ?: 'Metro Manila' }}</span>
                                    <span class="block break-words text-[#6E6E73]">{{ $vb->contact_name }} · {{ $vb->participants->count() }} {{ Str::plural('person', $vb->participants->count()) }}</span>
                                </div>
                                <a href="{{ route('admin.bookings.show', $vb) }}" class="shrink-0 font-semibold text-[#780000] hover:underline" aria-label="View booking {{ $vb->booking_number }}">View</a>
                            </li>
                        @endforeach
                    </ul>
                @else
                    <p class="text-[#6E6E73]">Nobody is riding the camp van. Everyone is coming on their own.</p>
                @endif
            </div>

            <div class="card space-y-3">
                <div class="flex items-center justify-between">
                    <h2 class="text-base font-bold text-[#1D1D1F]">Payments</h2>
                    <a href="{{ route('admin.payments.index') }}" class="font-semibold text-[#780000] hover:underline">All payments</a>
                </div>
                <dl class="space-y-2">
                    <div class="flex justify-between gap-2">
                        <dt class="text-[#6E6E73]">Expected total</dt>
                        <dd class="font-bold text-[#1D1D1F]">₱{{ number_format($batch->total_revenue, 2) }}</dd>
                    </div>
                    <div class="flex justify-between gap-2">
                        <dt class="text-[#6E6E73]">Collected</dt>
                        <dd class="font-bold text-emerald-700">₱{{ number_format($batch->collected_revenue, 2) }}</dd>
                    </div>
                    <div class="flex justify-between gap-2">
                        <dt class="text-[#6E6E73]">Pending refund requests</dt>
                        <dd class="font-bold {{ $batch->pending_refunds_count > 0 ? 'text-[#780000]' : 'text-[#1D1D1F]' }}">
                            @if($batch->pending_refunds_count > 0)
                                <a href="{{ route('admin.payments.refunds') }}" class="hover:underline">{{ $batch->pending_refunds_count }}</a>
                            @else
                                0
                            @endif
                        </dd>
                    </div>
                </dl>

                @if($batch->outstanding_balance_bookings->isNotEmpty())
                    <div class="border-t border-[#F2F2F7] pt-3">
                        <h3 class="mb-2 font-semibold text-[#1D1D1F]">Balance still due</h3>
                        <ul class="space-y-1.5">
                            @foreach($batch->outstanding_balance_bookings as $balBooking)
                                <li class="flex items-center justify-between gap-2">
                                    <a href="{{ route('admin.bookings.show', $balBooking) }}" class="whitespace-nowrap font-mono font-semibold text-[#780000] hover:underline">{{ $balBooking->booking_number }}</a>
                                    <span class="shrink-0 font-semibold text-amber-800">₱{{ number_format($balBooking->balance_amount, 2) }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </div>
        </aside>
    </div>

    <!-- Status history -->
    <section class="card space-y-4" aria-labelledby="batch-history-title">
        <h2 id="batch-history-title" class="text-base font-bold text-[#1D1D1F]">Status history</h2>
        <ol class="space-y-4">
            @forelse($batch->statusLogs as $log)
                <li class="flex items-start gap-3">
                    <span class="mt-1.5 h-2.5 w-2.5 shrink-0 rounded-full bg-[#780000]" aria-hidden="true"></span>
                    <div class="min-w-0 flex-1 space-y-0.5">
                        <div class="flex flex-col justify-between gap-1 sm:flex-row sm:items-baseline">
                            <strong class="text-[#1D1D1F]">{{ ucfirst(str_replace('_', ' ', $log->new_status)) }}</strong>
                            <span class="shrink-0 text-[#6E6E73]">{{ $log->created_at->format('M d, Y g:i A') }}</span>
                        </div>
                        @if($log->note)
                            <p class="break-words text-[#3A3A3C]">{{ $log->note }}</p>
                        @endif
                        <span class="block text-[#6E6E73]">By {{ $log->changer ? $log->changer->name : 'the system' }}</span>
                    </div>
                </li>
            @empty
                <li class="py-2 text-center text-[#6E6E73]">No status changes yet.</li>
            @endforelse
        </ol>
    </section>

    <!-- Cancel Batch Panel -->
    <x-side-panel show="openCancelModal" role="alertdialog"
                  :eyebrow="'Batch ' . $batch->batch_number"
                  title="Cancel this batch"
                  :description="$batch->display_name . ' · ' . $batch->formatted_date_range">
        <div class="banner banner-warning">
            All bookings in this batch become <strong>Cancelled by Camp</strong>, get a <strong>100% refund</strong>, and each participant receives a cancellation email.
        </div>

        <form id="cancel-batch-form" action="{{ route('admin.batches.update_status', $batch) }}" method="POST" class="space-y-4">
            @csrf
            <input type="hidden" name="status" value="cancelled_by_camp">

            <div>
                <label for="cancel-batch-note" class="form-label form-label-required">Reason for cancelling</label>
                <textarea id="cancel-batch-note" name="note" required rows="3" placeholder="e.g. Typhoon storm signal #2 in Batangas" class="form-textarea"></textarea>
                <span class="form-hint">Participants see this in their email.</span>
            </div>
        </form>

        <x-slot:footer>
            <button type="button" @click="openCancelModal = false" class="btn-secondary">Keep batch</button>
            <button type="submit" form="cancel-batch-form" class="btn-danger">Cancel batch</button>
        </x-slot:footer>
    </x-side-panel>

    <!-- Reschedule Batch Panel -->
    <x-side-panel show="openRescheduleModal"
                  :eyebrow="'Batch ' . $batch->batch_number"
                  title="Reschedule this batch"
                  :description="$batch->display_name . ' · ' . $batch->formatted_date_range">
        <div class="banner banner-info">
            All bookings in this batch become <strong>Rescheduled</strong>. Each participant gets an email asking them to pick a new date in <strong>Manage Booking</strong>.
        </div>

        <form id="reschedule-batch-form" action="{{ route('admin.batches.update_status', $batch) }}" method="POST" class="space-y-4">
            @csrf
            <input type="hidden" name="status" value="rescheduled">

            <div>
                <label for="reschedule-batch-note" class="form-label form-label-required">Message to participants</label>
                <textarea id="reschedule-batch-note" name="note" required rows="3" placeholder="e.g. Resort maintenance. Please choose a new weekend." class="form-textarea"></textarea>
                <span class="form-hint">Explain why and what they should do next.</span>
            </div>
        </form>

        <x-slot:footer>
            <button type="button" @click="openRescheduleModal = false" class="btn-secondary">Cancel</button>
            <button type="submit" form="reschedule-batch-form" class="btn-primary">Reschedule batch</button>
        </x-slot:footer>
    </x-side-panel>

    <!-- Move Booking Panel -->
    <x-side-panel show="openMoveModal" title="Move booking to another batch">
        <x-slot:eyebrow>Booking <span x-text="selectedBookingNumber"></span></x-slot:eyebrow>
        <x-slot:description>Pick another 2D1N batch, or remove the booking from any batch.</x-slot:description>

        <form id="move-booking-form" action="{{ route('admin.batches.move_booking', $batch) }}" method="POST" class="space-y-4">
            @csrf
            <input type="hidden" name="booking_id" :value="selectedBookingId">

            <div>
                <label for="move-target-batch-id" class="form-label">New batch</label>
                <select id="move-target-batch-id" name="target_batch_id" class="form-select w-full">
                    <option value="">Remove from batch (no batch)</option>
                    @foreach($otherBatches as $ob)
                        <option value="{{ $ob->id }}">
                            {{ $ob->display_name }} ({{ $ob->formatted_date_range }})
                        </option>
                    @endforeach
                </select>
            </div>

            <div>
                <label for="move-reason" class="form-label">Reason (optional)</label>
                <input id="move-reason" type="text" name="reason" placeholder="e.g. Booking was put in the wrong batch" class="form-input">
            </div>
        </form>

        <x-slot:footer>
            <button type="button" @click="openMoveModal = false" class="btn-secondary">Cancel</button>
            <button type="submit" form="move-booking-form" class="btn-primary">Move booking</button>
        </x-slot:footer>
    </x-side-panel>

    <!-- Complete Batch Panel -->
    <x-side-panel show="openCompleteModal"
                  :eyebrow="'Batch ' . $batch->batch_number"
                  title="Mark batch as completed"
                  :description="$batch->display_name . ' · ' . $batch->formatted_date_range">
        <p class="text-[#3A3A3C]">
            This closes the dive schedule. All active bookings in this batch become <strong>Completed</strong>.
        </p>

        <form id="complete-batch-form" action="{{ route('admin.batches.update_status', $batch) }}" method="POST">
            @csrf
            <input type="hidden" name="status" value="completed">
            <input type="hidden" name="note" value="2D1N dive schedule concluded successfully.">
        </form>

        <x-slot:footer>
            <button type="button" @click="openCompleteModal = false" class="btn-secondary">Cancel</button>
            <button type="submit" form="complete-batch-form" class="btn-primary">Mark completed</button>
        </x-slot:footer>
    </x-side-panel>

    <!-- Reactivate Batch Panel -->
    <x-side-panel show="openReactivateModal"
                  :eyebrow="'Batch ' . $batch->batch_number"
                  title="Reactivate batch"
                  :description="$batch->display_name . ' · ' . $batch->formatted_date_range">
        <p class="text-[#3A3A3C]">
            The batch goes back to <strong>Confirmed</strong> and reopens for scheduling and roster changes.
        </p>

        <form id="reactivate-batch-form" action="{{ route('admin.batches.update_status', $batch) }}" method="POST">
            @csrf
            <input type="hidden" name="status" value="confirmed">
            <input type="hidden" name="note" value="Reactivated batch to Confirmed status.">
        </form>

        <x-slot:footer>
            <button type="button" @click="openReactivateModal = false" class="btn-secondary">Cancel</button>
            <button type="submit" form="reactivate-batch-form" class="btn-primary">Reactivate batch</button>
        </x-slot:footer>
    </x-side-panel>

</div>
@endsection
