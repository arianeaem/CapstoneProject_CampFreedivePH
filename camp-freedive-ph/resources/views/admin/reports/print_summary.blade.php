@php
    $fin = $data['financials'] ?? [];
    $b = $data['bookings'] ?? [];
    $op = $data['operations'] ?? [];
    $co = $data['coaches'] ?? [];
    $peso = fn ($v) => '₱' . number_format((float) $v, 2);
    $total = (int) ($b['total_bookings'] ?? 0);
    $pct = fn ($n, $of) => $of > 0 ? round($n / $of * 100) . '%' : '0%';
    $ratio = (int) ($op['coach_ratio'] ?? 4);
    $period = $range['start']->year === $range['end']->year
        ? $range['start']->format('M d') . ' – ' . $range['end']->format('M d, Y')
        : $range['start']->format('M d, Y') . ' – ' . $range['end']->format('M d, Y');
    $section = 0;
    $g = $b['status_groups'] ?? [];
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports Summary - Camp FreedivePH</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@500;600;700;800;900&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css'])
    <style>
        body { font-family: 'Plus Jakarta Sans', system-ui, sans-serif; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        h1, h2, h3 { font-family: 'Outfit', 'Plus Jakarta Sans', sans-serif; }
        .rpt th { text-align: left; font-size: 11px; font-weight: 700; color: #FFFFFF; background: #780000; padding: 6px 10px; }
        .rpt td { padding: 6px 10px; border-bottom: 1px solid #F2F2F7; font-size: 12px; }
        .rpt tr.total td { font-weight: 800; border-top: 1.5px solid #780000; border-bottom: none; }
        .rpt .num { text-align: right; white-space: nowrap; }
        @media print {
            .no-print { display: none !important; }
            @page { margin: 1.2cm; size: portrait; }
            body { background: #FFFFFF !important; padding: 0 !important; }
            .report-card { box-shadow: none !important; padding: 0 !important; }
            .avoid-break { break-inside: avoid; }
        }
    </style>
</head>
{{-- Prints itself only when opened on its own tab; inside the Reports page's hidden frame the page triggers one print --}}
<body class="bg-[#F8F8FA] text-[#1D1D1F] p-4 sm:p-8" onload="if (window.self === window.top) window.print()">

    <div class="no-print max-w-4xl mx-auto mb-6 flex items-center justify-between gap-4">
        <a href="{{ (auth()->user()->isOwner() ? route('owner.reports.index') : route('admin.reports.index')) . '?' . http_build_query(['preset' => $range['preset'], 'start_date' => $range['start']->format('Y-m-d'), 'end_date' => $range['end']->format('Y-m-d')]) }}"
           class="btn-secondary px-4 py-2 rounded-xl text-sm font-bold">&larr; Back to Reports</a>
        <button type="button" onclick="window.print()" class="btn-primary px-4 py-2 rounded-xl text-sm font-bold">Print / Save as PDF</button>
    </div>

    <div class="report-card max-w-4xl mx-auto bg-white rounded-2xl p-8 sm:p-10 space-y-7 shadow-xs">

        <!-- Header -->
        <div class="flex items-start justify-between gap-4 pb-5 border-b-2 border-[#780000]">
            <div class="flex items-center gap-3">
                <img src="{{ asset('images/logo.png') }}" alt="Camp FreedivePH" class="w-11 h-11 rounded-full object-contain shrink-0">
                <div>
                    <h1 class="text-xl font-black text-[#780000] tracking-tight">Reports summary</h1>
                    <p class="text-xs text-[#6E6E73]">Camp FreedivePH &middot; {{ $isOwner ? 'Money, bookings, batches and coaches' : 'Bookings, batches and coaches' }}</p>
                </div>
            </div>
            <div class="text-right text-xs text-[#6E6E73]">
                <div class="text-sm font-extrabold text-[#1D1D1F]">{{ $range['label'] }}</div>
                <div>{{ $period }}</div>
                <div>Made on {{ now('Asia/Manila')->format('M d, Y g:i A') }}</div>
            </div>
        </div>

        <!-- At a glance -->
        <section class="space-y-3 avoid-break">
            <h2 class="text-base font-extrabold text-[#1D1D1F]">{{ ++$section }}. At a glance</h2>
            <div class="grid grid-cols-2 {{ $isOwner ? 'sm:grid-cols-5' : 'sm:grid-cols-4' }} gap-2.5">
                @php
                    $glance = [];
                    if ($isOwner) $glance[] = ['Money kept', $peso($fin['net_revenue'] ?? 0), 'After refunds'];
                    $glance[] = ['Bookings made', $total, ($b['paid_bookings'] ?? 0) . ' paid the downpayment'];
                    $glance[] = ['Participants', $b['total_participants'] ?? 0, 'In those bookings'];
                    $glance[] = ['How full batches are', ($op['avg_occupancy'] ?? 0) . '%', ($op['total_booked_pax'] ?? 0) . ' of ' . ($op['total_capacity_slots'] ?? 0) . ' slots'];
                    $glance[] = ['Enough coaches', ($op['safety_compliance_rate'] ?? 100) . '%', ($op['compliant_batches_count'] ?? 0) . ' of ' . ($op['running_batches_count'] ?? 0) . ' batches'];
                @endphp
                @foreach($glance as [$label, $value, $hint])
                    <div class="rounded-xl bg-[#F8EAEA]/50 p-3">
                        <span class="block text-[10px] font-bold uppercase tracking-wider text-[#6E6E73]">{{ $label }}</span>
                        <span class="block text-lg font-black text-[#780000] mt-0.5">{{ $value }}</span>
                        <span class="block text-[11px] text-[#6E6E73]">{{ $hint }}</span>
                    </div>
                @endforeach
            </div>
        </section>

        @if($isOwner)
            <!-- Money -->
            <section class="space-y-3 avoid-break">
                <h2 class="text-base font-extrabold text-[#1D1D1F]">{{ ++$section }}. Money</h2>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                    <table class="rpt w-full">
                        <thead><tr><th>What</th><th class="num">Amount</th></tr></thead>
                        <tbody>
                            <tr><td>Money collected</td><td class="num">{{ $peso($fin['gross_revenue'] ?? 0) }}</td></tr>
                            <tr><td>Refunded to participants</td><td class="num">{{ $peso($fin['refunds_processed'] ?? 0) }}</td></tr>
                            <tr class="total"><td>Money kept</td><td class="num">{{ $peso($fin['net_revenue'] ?? 0) }}</td></tr>
                            <tr><td>Paid as deposit</td><td class="num">{{ $peso($fin['downpayment_revenue'] ?? 0) }}</td></tr>
                            <tr><td>Paid in full</td><td class="num">{{ $peso($fin['balance_revenue'] ?? 0) }}</td></tr>
                            <tr><td>Still to collect</td><td class="num">{{ $peso($fin['outstanding_receivables'] ?? 0) }}</td></tr>
                            <tr><td>Average per participant</td><td class="num">{{ $peso($fin['arpd'] ?? 0) }}</td></tr>
                        </tbody>
                    </table>
                    <table class="rpt w-full">
                        <thead><tr><th>Package</th><th class="num">Bookings</th><th class="num">Participants</th><th class="num">Money</th><th class="num">Share</th></tr></thead>
                        <tbody>
                            @foreach($fin['packages'] ?? [] as $pkg)
                                <tr><td>{{ $pkg['name'] }}</td><td class="num">{{ $pkg['bookings_count'] }}</td><td class="num">{{ $pkg['pax_count'] }}</td><td class="num">{{ $peso($pkg['revenue']) }}</td><td class="num">{{ $pkg['share_percentage'] }}%</td></tr>
                            @endforeach
                            @php $pk = collect($fin['packages'] ?? []); @endphp
                            <tr class="total"><td>Total</td><td class="num">{{ $pk->sum('bookings_count') }}</td><td class="num">{{ $pk->sum('pax_count') }}</td><td class="num">{{ $peso($pk->sum('revenue')) }}</td><td class="num">{{ round($pk->sum('share_percentage'), 1) }}%</td></tr>
                            <tr><td>Carpool (estimate)</td><td class="num">{{ $fin['carpool']['bookings_count'] ?? 0 }}</td><td class="num">{{ $fin['carpool']['pax_count'] ?? 0 }}</td><td class="num">{{ $peso($fin['carpool']['estimated_revenue'] ?? 0) }}</td><td></td></tr>
                            <tr><td>Boat dive (estimate)</td><td class="num">{{ $fin['boat_dive']['bookings_count'] ?? 0 }}</td><td class="num">{{ $fin['boat_dive']['pax_count'] ?? 0 }}</td><td class="num">{{ $peso($fin['boat_dive']['estimated_revenue'] ?? 0) }}</td><td></td></tr>
                        </tbody>
                    </table>
                </div>
            </section>
        @endif

        <!-- Bookings -->
        <section class="space-y-3">
            <h2 class="text-base font-extrabold text-[#1D1D1F]">{{ ++$section }}. Bookings</h2>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-5 avoid-break">
                <table class="rpt w-full">
                    <thead><tr><th>Where every booking stands</th><th class="num">Bookings</th><th class="num">Share</th></tr></thead>
                    <tbody>
                        @foreach(['going' => 'Paid & going', 'completed' => 'Finished trip', 'waiting' => 'Waiting for downpayment', 'cancelled' => 'Cancelled', 'no_show' => 'No-show'] as $key => $label)
                            <tr><td>{{ $label }}</td><td class="num">{{ $g[$key] ?? 0 }}</td><td class="num">{{ $pct($g[$key] ?? 0, $total) }}</td></tr>
                        @endforeach
                        <tr class="total"><td>Total</td><td class="num">{{ $total }}</td><td class="num">{{ $total > 0 ? '100%' : '0%' }}</td></tr>
                    </tbody>
                </table>
                <table class="rpt w-full">
                    <thead><tr><th>Who books together</th><th class="num">Bookings</th><th class="num">Share</th></tr></thead>
                    <tbody>
                        @foreach(['solo' => 'Alone (1 participant)', 'duo' => 'Pairs (2 participants)', 'small_group' => 'Small groups (3–4)', 'large_group' => 'Big groups (5 or more)'] as $key => $label)
                            <tr><td>{{ $label }}</td><td class="num">{{ $b['group_sizes'][$key] ?? 0 }}</td><td class="num">{{ $pct($b['group_sizes'][$key] ?? 0, $total) }}</td></tr>
                        @endforeach
                        <tr class="total"><td>Total</td><td class="num">{{ array_sum($b['group_sizes'] ?? []) }}</td><td></td></tr>
                    </tbody>
                </table>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-5 avoid-break">
                @php $sw = $b['swimmer_ability'] ?? ['cannot_swim' => 0, 'can_swim' => 0, 'strong' => 0, 'total' => 0]; @endphp
                <table class="rpt w-full">
                    <thead><tr><th>Can Discovery participants swim?</th><th class="num">Participants</th><th class="num">Share</th></tr></thead>
                    <tbody>
                        @foreach(['cannot_swim' => "Can't swim", 'can_swim' => 'Can swim', 'strong' => 'Strong swimmer'] as $key => $label)
                            <tr><td>{{ $label }}</td><td class="num">{{ $sw[$key] }}</td><td class="num">{{ $pct($sw[$key], $sw['total']) }}</td></tr>
                        @endforeach
                        <tr class="total"><td>Total</td><td class="num">{{ $sw['total'] }}</td><td></td></tr>
                    </tbody>
                </table>
                @php $lt = $b['lead_times'] ?? []; $ltTotal = array_sum($lt); @endphp
                <table class="rpt w-full">
                    <thead><tr><th>How early participants book</th><th class="num">Bookings</th><th class="num">Share</th></tr></thead>
                    <tbody>
                        @foreach(['Under 4 days before' => $lt['under_3_days'] ?? 0, '4–7 days before' => $lt['4_to_7_days'] ?? 0, '1–2 weeks before' => $lt['8_to_14_days'] ?? 0, '15 or more days before' => ($lt['15_to_30_days'] ?? 0) + ($lt['over_30_days'] ?? 0)] as $label => $n)
                            <tr><td>{{ $label }}</td><td class="num">{{ $n }}</td><td class="num">{{ $pct($n, $ltTotal) }}</td></tr>
                        @endforeach
                        <tr class="total"><td>Total</td><td class="num">{{ $ltTotal }}</td><td></td></tr>
                    </tbody>
                </table>
            </div>
            <p class="text-xs text-[#6E6E73]">{{ $b['reschedule_count'] ?? 0 }} reschedule {{ Str::plural('request', $b['reschedule_count'] ?? 0) }} and {{ $b['cancellation_count'] ?? 0 }} cancel {{ Str::plural('request', $b['cancellation_count'] ?? 0) }} were made in this period.</p>
        </section>

        <!-- Batches & coaches -->
        <section class="space-y-3">
            <h2 class="text-base font-extrabold text-[#1D1D1F]">{{ ++$section }}. Batches &amp; coaches</h2>
            <p class="text-xs text-[#6E6E73]">
                {{ $op['total_batches'] ?? 0 }} batches: {{ $op['active_batches'] ?? 0 }} still to run, {{ $op['completed_batches'] ?? 0 }} finished, {{ $op['cancelled_batches'] ?? 0 }} cancelled.
                Rule: 1 coach for every {{ $ratio }} participants.
            </p>
            <table class="rpt w-full">
                <thead><tr><th>Batch</th><th>Dates</th><th class="num">Participants</th><th>Coaches</th><th>Enough coaches?</th><th>Status</th></tr></thead>
                <tbody>
                    @forelse($op['batches_list'] ?? [] as $batch)
                        @php
                            $pax = $batch->total_participants_count;
                            $coaches = $batch->assigned_coaches;
                            $needed = $pax > 0 ? (int) ceil($pax / $ratio) : 0;
                            $cancelled = in_array($batch->status, \App\Services\Analytics\AnalyticsService::BATCH_GROUPS['cancelled'], true);
                        @endphp
                        <tr>
                            <td class="font-semibold">{{ $batch->batch_number }}</td>
                            <td class="whitespace-nowrap">{{ $batch->start_date->format('M d') }} – {{ $batch->end_date?->format('M d, Y') }}</td>
                            <td class="num">{{ $pax }} / {{ $batch->computed_capacity }}</td>
                            <td>{{ $coaches->pluck('name')->implode(', ') ?: 'None yet' }}</td>
                            <td>{{ $cancelled ? '—' : ($pax === 0 ? 'No participants yet' : ($coaches->count() >= $needed ? 'Yes' : 'Needs ' . ($needed - $coaches->count()) . ' more')) }}</td>
                            <td>{{ \App\Services\Analytics\AnalyticsService::batchStatusLabel($batch->status) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-center text-[#8E8E93]">No batches in this period.</td></tr>
                    @endforelse
                    <tr class="total"><td>Total</td><td></td><td class="num">{{ $op['total_booked_pax'] ?? 0 }} / {{ $op['total_capacity_slots'] ?? 0 }}</td><td colspan="3">{{ $op['compliant_batches_count'] ?? 0 }} of {{ $op['running_batches_count'] ?? 0 }} batches have enough coaches</td></tr>
                </tbody>
            </table>

            <table class="rpt w-full avoid-break">
                <thead><tr><th>Coach</th><th>Account</th><th class="num">Batches</th><th class="num">Dive days</th><th class="num">Asked to be released</th></tr></thead>
                <tbody>
                    @forelse($co['coaches'] ?? [] as $c)
                        <tr><td class="font-semibold">{{ $c['name'] }}</td><td>{{ ucfirst($c['status']) }}</td><td class="num">{{ $c['assignments_count'] }}</td><td class="num">{{ $c['estimated_dive_days'] }}</td><td class="num">{{ $c['releases_count'] }}</td></tr>
                    @empty
                        <tr><td colspan="5" class="text-center text-[#8E8E93]">No coaches yet.</td></tr>
                    @endforelse
                    <tr class="total"><td>Total</td><td></td><td class="num">{{ $co['total_assignments_period'] ?? 0 }}</td><td class="num">{{ $co['total_dive_days'] ?? 0 }}</td><td class="num">{{ collect($co['coaches'] ?? [])->sum('releases_count') }}</td></tr>
                </tbody>
            </table>
        </section>

        <div class="pt-5 border-t border-[#F2F2F7] flex items-center justify-between text-[11px] text-[#8E8E93]">
            <span>Camp FreedivePH &copy; {{ date('Y') }} &middot; For internal use only</span>
            <span>Made by {{ $user->name }} ({{ ucfirst($user->role) }})</span>
        </div>
    </div>
</body>
</html>
