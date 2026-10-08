@extends('layouts.admin')

@section('title', 'Dashboard | Camp FreedivePH')

@section('content')
@php
    $peso = fn ($v) => '₱' . number_format((float) $v, 2);
    $unmatched = (int) $operationalStats['unmatched_students_count'];

    // Attention items (same data as before, one tidy list)
    $attention = collect([
        ['count' => $actionInbox['reschedules']->count(), 'label' => 'Reschedule requests', 'hint' => 'Participants asking to move their dates', 'url' => route('admin.bookings.requests'), 'action' => 'Review'],
        ['count' => $actionInbox['cancellations']->count(), 'label' => 'Cancellation requests', 'hint' => 'Participants asking to cancel', 'url' => route('admin.bookings.requests'), 'action' => 'Review'],
        ['count' => $actionInbox['refunds']->count(), 'label' => 'Refunds to process', 'hint' => 'Approved cancellations waiting for payment', 'url' => route('admin.bookings.requests'), 'action' => 'Process'],
        ['count' => $actionInbox['releases']->count(), 'label' => 'Coach release requests', 'hint' => 'Coaches asking to leave a batch', 'url' => route('admin.coaches.requests'), 'action' => 'Review'],
        ['count' => $actionInbox['understaffed']->count(), 'label' => 'Batches needing coaches', 'hint' => 'Not enough coaches for the participants booked', 'url' => route('admin.coaches.matching'), 'action' => 'Match'],
        ['count' => $actionInbox['weather_alerts']->count(), 'label' => 'Weather warnings', 'hint' => 'Batches rated High or Critical Risk', 'url' => route('admin.weather.index'), 'action' => 'Check'],
    ])->filter(fn ($i) => $i['count'] > 0)->values();

    // "How full are the next batches" chart
    $chartBatches = $upcomingBatches->map(fn ($b) => [
        'batch' => $b,
        'pax' => $b->total_participants_count,
        'capacity' => $b->computed_capacity,
    ]);
    $chartMax = max(1, (int) $chartBatches->max('capacity'));
    $fullestPax = (int) $chartBatches->max('pax');
    // Highlight only one bar (the first batch with the most participants)
    $fullestIndex = $fullestPax > 0 ? $chartBatches->search(fn ($c) => $c['pax'] === $fullestPax) : null;

@endphp

<div class="space-y-5">

    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl sm:text-3xl font-extrabold text-[#1D1D1F] tracking-tight">Welcome back, {{ $user->name }}</h1>
            <p class="text-sm text-[#6E6E73] mt-1">It's {{ now('Asia/Manila')->format('l, F d, Y') }}. Here's how the camp is doing.</p>
        </div>
        <a href="{{ $isOwner ? route('owner.bookings.create') : route('admin.bookings.create') }}"
           class="btn-primary min-h-[44px] px-4 py-2 rounded-xl text-sm font-bold inline-flex items-center gap-2 self-start sm:self-auto shadow-2xs">
            <svg class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
            <span>Walk-in Booking</span>
        </a>
    </div>

    <!-- 1. Summary cards -->
    <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4">
        @php
            $cards = [];
            if ($isOwner) {
                $cards[] = ['label' => 'Money collected', 'value' => $peso($financials['gross_revenue']), 'hint' => $peso($financials['outstanding_balances']) . ' still to be paid'];
            } else {
                $cards[] = ['label' => 'Coaches available', 'value' => $operationalStats['active_coaches_count'], 'hint' => 'Active coach accounts'];
            }
            $cards[] = ['label' => 'Participants this month', 'value' => $operationalStats['active_divers_month'], 'hint' => 'Booked on trips this month'];
            $cards[] = ['label' => 'Average batch fill', 'value' => $operationalStats['avg_occupancy'] . '%', 'hint' => 'Across ' . $operationalStats['total_active_batches'] . ' upcoming ' . Str::plural('batch', $operationalStats['total_active_batches'])];
            $cards[] = ['label' => 'Participants without a coach', 'value' => $unmatched, 'hint' => $unmatched > 0 ? 'Need a coach assigned' : 'Everyone has a coach', 'tone' => $unmatched > 0 ? 'text-[#B45309]' : 'text-[#00838C]', 'url' => $unmatched > 0 ? route('admin.coaches.matching') : null];
        @endphp
        @foreach($cards as $card)
            <div class="relative overflow-hidden rounded-2xl border border-[#E5E5EA] bg-gradient-to-b from-[#780000]/[0.04] via-white to-white p-5 flex flex-col gap-2 shadow-2xs">
                <div class="absolute -top-10 -right-10 w-32 h-32 bg-gradient-to-br from-[#780000]/15 via-[#9E2A2B]/8 to-transparent rounded-full blur-2xl pointer-events-none" aria-hidden="true"></div>
                <span class="relative text-[11px] font-bold uppercase tracking-wider text-[#6E6E73]">{{ $card['label'] }}</span>
                <div class="relative text-2xl sm:text-3xl font-black tracking-tight {{ $card['tone'] ?? 'text-[#780000]' }}">{{ $card['value'] }}</div>
                <div class="relative text-xs text-[#6E6E73]">
                    @if(!empty($card['url']))
                        <a href="{{ $card['url'] }}" class="font-semibold text-[#B45309] hover:underline">{{ $card['hint'] }} &rarr;</a>
                    @else
                        {{ $card['hint'] }}
                    @endif
                </div>
            </div>
        @endforeach
    </div>

    <!-- 2. Batch fill chart + needs attention -->
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-5 items-start">

        <!-- How full are the next batches -->
        <div class="lg:col-span-7 bg-white rounded-2xl border border-[#E5E5EA] p-5 space-y-4">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h2 class="text-base font-extrabold text-[#1D1D1F] flex items-center gap-2"><span class="w-1 h-4 rounded-full bg-[#780000]" aria-hidden="true"></span>How full are the next batches</h2>
                    <p class="text-sm text-[#8E8E93]">Participants booked out of each batch's {{ $chartMax }} slots</p>
                </div>
                <a href="{{ route('admin.batches.index') }}" class="text-sm font-bold text-[#780000] hover:underline shrink-0">All batches &rarr;</a>
            </div>

            @if($chartBatches->isEmpty())
                <div class="py-10 text-center text-sm text-[#8E8E93] rounded-xl bg-[#F8F9FA]">
                    No upcoming batches yet. <a href="{{ route('admin.batches.create') }}" class="font-bold text-[#780000] hover:underline">Create a batch</a>
                </div>
            @else
                <div class="flex gap-3">
                    <!-- Y-axis -->
                    <div class="flex flex-col justify-between text-xs text-[#8E8E93] h-48 pb-6 text-right shrink-0">
                        @foreach([$chartMax, (int) round($chartMax * 2 / 3), (int) round($chartMax / 3), 0] as $tick)
                            <span>{{ $tick }}</span>
                        @endforeach
                    </div>
                    <!-- Bars -->
                    <div class="flex-1 grid gap-3" style="grid-template-columns: repeat({{ $chartBatches->count() }}, minmax(0, 1fr));">
                        @foreach($chartBatches as $i => $c)
                            @php
                                $height = max(3, round($c['pax'] / $chartMax * 100));
                                $isFullest = $i === $fullestIndex;
                            @endphp
                            <div class="flex flex-col items-center gap-2">
                                <div class="w-full h-42 flex flex-col items-center justify-end gap-1">
                                    <span class="whitespace-nowrap rounded-md text-xs font-bold px-1.5 py-0.5 {{ $isFullest ? 'bg-[#780000] text-white' : 'text-[#00838C]' }}">{{ $c['pax'] }}{{ $isFullest ? ' of ' . $c['capacity'] : '' }}</span>
                                    <div class="w-full max-w-16 rounded-lg {{ $isFullest ? 'bg-gradient-to-b from-[#780000] to-[#B4544F]' : 'bg-[#00C3D0]/10 dashboard-bar-stripes' }}"
                                         style="height: {{ $height }}%"
                                         role="img" aria-label="{{ $c['batch']->batch_number }}: {{ $c['pax'] }} of {{ $c['capacity'] }} participants booked"
                                         title="{{ $c['batch']->batch_number }}: {{ $c['pax'] }} of {{ $c['capacity'] }} participants"></div>
                                </div>
                                <span class="text-xs text-[#6E6E73] text-center leading-tight">{{ $c['batch']->start_date->format('M d') }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            <!-- Package mini cards -->
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                @foreach($packageAnalytics as $pData)
                    <div class="rounded-xl border border-[#E5E5EA] bg-gradient-to-b from-[#780000]/[0.03] to-white p-3.5 space-y-2">
                        <div>
                            <span class="block text-xs font-semibold text-[#3A3A3C]">{{ $pData['name'] }}</span>
                            <span class="block text-xs text-[#8E8E93]">{{ $pData['share_percentage'] }}% of all bookings</span>
                        </div>
                        <div class="text-lg font-black text-[#780000]">
                            {{ $isOwner ? $peso($pData['revenue']) : $pData['bookings_count'] . ' ' . Str::plural('booking', $pData['bookings_count']) }}
                        </div>
                        <div class="text-xs text-[#8E8E93]">
                            {{ $pData['bookings_count'] }} {{ Str::plural('booking', $pData['bookings_count']) }} &middot; {{ $pData['pax_count'] }} {{ Str::plural('participant', $pData['pax_count']) }}
                        </div>
                        <!-- Share ticks -->
                        <div class="flex gap-[3px]" aria-hidden="true">
                            @for($t = 1; $t <= 20; $t++)
                                <span class="h-3 flex-1 rounded-[1px] {{ $t <= round($pData['share_percentage'] / 5) ? 'bg-[#780000]' : 'bg-[#F1D5D5]/60' }}"></span>
                            @endfor
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        <!-- Needs your attention -->
        <div class="lg:col-span-5 bg-white rounded-2xl border border-[#E5E5EA] p-5 space-y-4">
            <div>
                <h2 class="text-base font-extrabold text-[#1D1D1F] flex items-center gap-2"><span class="w-1 h-4 rounded-full bg-[#780000]" aria-hidden="true"></span>Needs your attention</h2>
                <p class="text-sm text-[#8E8E93]">
                    {{ $attention->isEmpty() ? 'Nothing is waiting for you right now.' : $actionInbox['total_count'] . ' ' . Str::plural('item', $actionInbox['total_count']) . ' waiting for a decision' }}
                </p>
            </div>

            @if($attention->isEmpty())
                <div class="rounded-xl bg-[#00C3D0]/10 border border-[#00C3D0]/30 p-5">
                    <p class="font-bold text-[#00636A]">You're all caught up</p>
                    <p class="text-sm text-[#00838C]">No requests, refunds or warnings need action.</p>
                </div>
            @else
                <ul class="space-y-2">
                    @foreach($attention as $item)
                        <li>
                            <a href="{{ $item['url'] }}" class="group flex items-center gap-3 rounded-xl border border-[#E5E5EA] p-3 hover:bg-[#F8EAEA]/40 transition-colors">
                                <span class="flex-1 min-w-0">
                                    <span class="block text-sm font-semibold text-[#1D1D1F]">{{ $item['label'] }}</span>
                                    <span class="block text-xs text-[#8E8E93] truncate">{{ $item['hint'] }}</span>
                                </span>
                                <span class="min-w-8 text-center rounded-full bg-[#780000] text-white text-sm font-black px-2 py-0.5">{{ $item['count'] }}</span>
                                <span class="text-xs font-bold text-[#780000] shrink-0">{{ $item['action'] }} &rarr;</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif

            <a href="{{ route('admin.bookings.requests') }}"
               class="btn-primary w-full min-h-[44px] rounded-xl text-sm font-bold inline-flex items-center justify-center shadow-2xs">
                Open booking requests
            </a>
        </div>
    </div>

    <!-- 3. Recent bookings + upcoming batches -->
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-5 items-start">

        <!-- Recent bookings -->
        <div class="lg:col-span-7 bg-white rounded-2xl border border-[#E5E5EA] overflow-hidden">
            <div class="p-5 pb-3 flex items-center justify-between gap-3">
                <div>
                    <h2 class="text-base font-extrabold text-[#1D1D1F] flex items-center gap-2"><span class="w-1 h-4 rounded-full bg-[#780000]" aria-hidden="true"></span>Recent bookings</h2>
                    <p class="text-sm text-[#8E8E93]">The latest paid reservations</p>
                </div>
                <a href="{{ route('admin.bookings.index') }}" class="text-sm font-bold text-[#780000] hover:underline shrink-0">All bookings &rarr;</a>
            </div>
            <div class="overflow-x-auto px-5 pb-4">
                <table class="w-full text-left text-sm">
                    <thead>
                        <tr class="text-[#8E8E93] text-xs border-b border-[#E5E5EA]">
                            <th class="py-2.5 pr-4 font-semibold">Booking Contact</th>
                            <th class="py-2.5 pr-4 font-semibold">Package</th>
                            <th class="py-2.5 pr-4 font-semibold">Trip dates</th>
                            <th class="py-2.5 pr-4 font-semibold">Participants</th>
                            <th class="py-2.5 font-semibold">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[#F2F2F7]">
                        @forelse($recentBookings as $b)
                            <tr tabindex="0" role="link"
                                onclick="window.location='{{ route('admin.bookings.show', $b) }}'"
                                onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();window.location='{{ route('admin.bookings.show', $b) }}';}"
                                aria-label="View booking {{ $b->booking_number }} for {{ $b->contact_name }}"
                                class="hover:bg-[#F8F9FA] focus:bg-[#F8F9FA] focus:outline-none cursor-pointer transition-colors">
                                <td class="py-3 pr-4">
                                    <span class="block font-semibold text-[#1D1D1F] whitespace-nowrap">{{ $b->contact_name }}</span>
                                    <span class="block text-xs text-[#8E8E93] font-mono">{{ $b->booking_number }}</span>
                                </td>
                                <td class="py-3 pr-4 text-[#3A3A3C]">{{ $b->formatted_class_type }}</td>
                                <td class="py-3 pr-4 text-[#3A3A3C] whitespace-nowrap">{{ $b->start_date->format('M d') }} – {{ $b->end_date->format('M d, Y') }}</td>
                                <td class="py-3 pr-4 font-semibold text-[#1D1D1F]">{{ $b->participants->count() }}</td>
                                <td class="py-3">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-bold {{ $b->status_badge['class'] }}">{{ $b->status_badge['label'] }}</span>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="py-8 text-center text-[#8E8E93]">No paid bookings yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Upcoming batches checklist -->
        <div class="lg:col-span-5 bg-white rounded-2xl border border-[#E5E5EA] overflow-hidden">
            <div class="p-5 pb-3">
                <h2 class="text-base font-extrabold text-[#1D1D1F] flex items-center gap-2"><span class="w-1 h-4 rounded-full bg-[#780000]" aria-hidden="true"></span>Upcoming batches</h2>
                <p class="text-sm text-[#8E8E93]">A check means the batch has enough coaches</p>
            </div>
            <div class="px-5 pb-4">
                <table class="w-full text-left text-sm">
                    <thead>
                        <tr class="text-[#8E8E93] text-xs border-b border-[#E5E5EA]">
                            <th class="py-2.5 pr-3 font-semibold">Batch</th>
                            <th class="py-2.5 pr-3 font-semibold">Participants</th>
                            <th class="py-2.5 font-semibold">Coaches</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[#F2F2F7]">
                        @forelse($upcomingBatches as $batch)
                            @php
                                $pax = $batch->total_participants_count;
                                $coachesNeeded = $pax > 0 ? (int) ceil($pax / 4) : 0;
                                $coachesAssigned = $batch->assigned_coaches_count;
                                $staffed = $coachesAssigned >= $coachesNeeded;
                            @endphp
                            <tr>
                                <td class="py-3 pr-3">
                                    <span class="flex items-center gap-2">
                                        <span class="w-5 h-5 rounded-full flex items-center justify-center shrink-0 {{ $staffed ? 'text-[#00838C]' : 'bg-[#FFFBEB] text-[#B45309]' }}" aria-hidden="true">
                                            @if($staffed)
                                                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M20 6 9 17l-5-5"/></svg>
                                            @else
                                                <span class="text-xs font-black">!</span>
                                            @endif
                                        </span>
                                        <span>
                                            <span class="block font-semibold text-[#1D1D1F]">{{ $batch->start_date->format('M d') }} – {{ $batch->end_date?->format('M d') }}</span>
                                            <span class="block text-xs text-[#8E8E93]">{{ $batch->batch_number }} &middot; {{ $batch->start_date->diffForHumans() }}</span>
                                        </span>
                                    </span>
                                </td>
                                <td class="py-3 pr-3 font-semibold text-[#1D1D1F]">{{ $pax }}</td>
                                <td class="py-3 whitespace-nowrap">
                                    @if($pax === 0)
                                        <span class="text-[#8E8E93]">No participants yet</span>
                                    @elseif($staffed)
                                        <span class="text-[#00838C] font-semibold">{{ $coachesAssigned }} of {{ $coachesNeeded }}</span>
                                    @else
                                        <a href="{{ route('admin.coaches.matching') }}" class="text-[#B45309] font-semibold hover:underline">{{ $coachesAssigned }} of {{ $coachesNeeded }} &middot; add {{ $coachesNeeded - $coachesAssigned }}</a>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="py-8 text-center text-[#8E8E93]">No upcoming batches.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    @if($isOwner)
        <!-- 4. Money (owner only) -->
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-5 items-start">

            <div class="lg:col-span-4 bg-white rounded-2xl border border-[#E5E5EA] p-5 space-y-4">
                <div>
                    <h2 class="text-base font-extrabold text-[#1D1D1F] flex items-center gap-2"><span class="w-1 h-4 rounded-full bg-[#780000]" aria-hidden="true"></span>Money</h2>
                    <p class="text-sm text-[#8E8E93]">All payments so far</p>
                </div>
                <dl class="divide-y divide-[#F2F2F7] text-sm">
                    <div class="flex items-center justify-between py-2.5">
                        <dt class="text-[#6E6E73]">Money collected</dt>
                        <dd class="font-bold text-[#780000]">{{ $peso($financials['gross_revenue']) }}</dd>
                    </div>
                    <div class="flex items-center justify-between py-2.5">
                        <dt class="text-[#6E6E73]">Still to be paid</dt>
                        <dd class="font-bold text-[#B45309]">{{ $peso($financials['outstanding_balances']) }}</dd>
                    </div>
                    <div class="flex items-center justify-between py-2.5">
                        <dt class="text-[#6E6E73]">Refunded</dt>
                        <dd class="font-bold text-[#B91C1C]">{{ $peso($financials['refunds_processed']) }}</dd>
                    </div>
                    <div class="flex items-center justify-between py-2.5">
                        <dt class="font-semibold text-[#1D1D1F]">Money kept after refunds</dt>
                        <dd class="font-extrabold text-[#047857]">{{ $peso($financials['net_revenue']) }}</dd>
                    </div>
                </dl>
            </div>

            <div class="lg:col-span-3 bg-white rounded-2xl border border-[#E5E5EA] p-5 space-y-4">
                <div class="flex items-start justify-between gap-2">
                    <div>
                        <h2 class="text-base font-extrabold text-[#1D1D1F] flex items-center gap-2"><span class="w-1 h-4 rounded-full bg-[#780000]" aria-hidden="true"></span>Price adjustments</h2>
                        <p class="text-sm text-[#8E8E93]">From your pricing rules</p>
                    </div>
                    <a href="{{ route('admin.pricing.index') }}" class="text-sm font-bold text-[#780000] hover:underline shrink-0">Rules &rarr;</a>
                </div>
                <div class="text-2xl font-black text-[#780000]">{{ $dynamicPricingStats['net_lift'] < 0 ? '−' : '+' }}{{ $peso(abs($dynamicPricingStats['net_lift'])) }}</div>
                <dl class="space-y-2 text-sm">
                    <div class="flex items-center justify-between">
                        <dt class="text-[#6E6E73]">Extra from busy-day pricing</dt>
                        <dd class="font-semibold text-[#047857]">+{{ $peso($dynamicPricingStats['positive_yield']) }}</dd>
                    </div>
                    <div class="flex items-center justify-between">
                        <dt class="text-[#6E6E73]">Discounts given</dt>
                        <dd class="font-semibold text-[#B91C1C]">−{{ $peso($dynamicPricingStats['discount_given']) }}</dd>
                    </div>
                    <div class="flex items-center justify-between">
                        <dt class="text-[#6E6E73]">Rules turned on</dt>
                        <dd class="font-semibold text-[#1D1D1F]">{{ $dynamicPricingStats['active_rules'] }}</dd>
                    </div>
                </dl>
            </div>

            <div class="lg:col-span-5 bg-white rounded-2xl border border-[#E5E5EA] p-5 space-y-3">
                <div class="flex items-start justify-between gap-2">
                    <div>
                        <h2 class="text-base font-extrabold text-[#1D1D1F] flex items-center gap-2"><span class="w-1 h-4 rounded-full bg-[#780000]" aria-hidden="true"></span>Recent activity</h2>
                        <p class="text-sm text-[#8E8E93]">What your team changed lately</p>
                    </div>
                    <a href="{{ route('admin.audit_logs.index') }}" class="text-sm font-bold text-[#780000] hover:underline shrink-0">See all &rarr;</a>
                </div>
                <ul class="divide-y divide-[#F2F2F7]">
                    @forelse($recentAuditLogs as $log)
                        <li class="py-2.5 flex items-start gap-3 text-sm">
                            <span class="w-1.5 h-1.5 rounded-full bg-[#00C3D0] mt-2 shrink-0" aria-hidden="true"></span>
                            <div class="flex-1 min-w-0">
                                <p class="text-[#1D1D1F] line-clamp-2">{{ $log->description ?? $log->details }}</p>
                                <p class="text-xs text-[#8E8E93]">{{ $log->user?->name ?? 'System' }} &middot; {{ $log->created_at->diffForHumans() }}</p>
                            </div>
                        </li>
                    @empty
                        <li class="py-4 text-center text-sm text-[#8E8E93]">No activity yet.</li>
                    @endforelse
                </ul>
            </div>
        </div>
    @endif

</div>
@endsection
