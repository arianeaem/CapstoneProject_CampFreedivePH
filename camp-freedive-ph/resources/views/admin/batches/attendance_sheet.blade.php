<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance Sheet - {{ $batch->batch_number }} - Camp FreedivePH</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@500;600;700;800;900&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css'])
    <style>
        body { font-family: 'Plus Jakarta Sans', system-ui, sans-serif; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        h1, h2, h3 { font-family: 'Outfit', 'Plus Jakarta Sans', sans-serif; }
        .rpt th { text-align: left; font-size: 11px; font-weight: 700; color: #FFFFFF; background: #780000; padding: 6px 8px; }
        /* Tall rows leave room to handwrite remarks */
        .rpt td { padding: 14px 8px; border-bottom: 1px solid #E5E5EA; font-size: 12px; vertical-align: middle; }
        .rpt .day { text-align: center; width: 64px; white-space: nowrap; }
        .box { display: inline-block; width: 16px; height: 16px; border: 1.5px solid #780000; border-radius: 3px; vertical-align: middle; }
        /* Sign-off: names sit on the writing line */
        .signoff td { vertical-align: bottom; }
        .line { border-bottom: 1px solid #8E8E93; height: 48px; }
        @media print {
            .no-print { display: none !important; }
            @page { margin: 1.2cm; size: A4 portrait; }
            body { background: #FFFFFF !important; padding: 0 !important; }
            .report-card { box-shadow: none !important; padding: 0 !important; }
            .avoid-break { break-inside: avoid; }
            /* Repeat the column header on every page and never split a participant's row */
            .rpt thead { display: table-header-group; }
            .rpt tr { break-inside: avoid; }
            h2 { break-after: avoid; }
        }
    </style>
</head>
<body class="bg-[#F8F8FA] text-[#1D1D1F] p-4 sm:p-8" onload="if (window.self === window.top) window.print()">

    <div class="no-print max-w-4xl mx-auto mb-6 flex items-center justify-between gap-4">
        <a href="{{ portal_route('batches.show', $batch) }}" class="btn-secondary px-4 py-2 rounded-xl text-sm font-bold">&larr; Back to batch</a>
        <button type="button" onclick="window.print()" class="btn-primary px-4 py-2 rounded-xl text-sm font-bold">Print / Save as PDF</button>
    </div>

    <div class="report-card max-w-4xl mx-auto bg-white rounded-2xl p-8 sm:p-10 space-y-6 shadow-xs">

        <!-- Header -->
        <div class="flex items-start justify-between gap-4 pb-5 border-b-2 border-[#780000]">
            <div class="flex items-center gap-3">
                <img src="{{ asset('images/logo.png') }}" alt="Camp FreedivePH" class="w-11 h-11 rounded-full object-contain shrink-0">
                <div>
                    <h1 class="text-xl font-black text-[#780000] tracking-tight">Attendance sheet</h1>
                    <p class="text-xs text-[#6E6E73]">Camp FreedivePH &middot; {{ $batch->batch_number }}{{ $batch->batch_code ? ' (' . $batch->batch_code . ')' : '' }}</p>
                </div>
            </div>
            <div class="text-right text-xs text-[#6E6E73]">
                <div class="text-sm font-extrabold text-[#1D1D1F]">{{ $batch->formatted_date_range }}</div>
                <div>{{ $batch->status_badge['label'] }} &middot; {{ $participants->count() }} {{ Str::plural('participant', $participants->count()) }}</div>
                <div>Printed {{ now('Asia/Manila')->format('M d, Y g:i A') }}</div>
            </div>
        </div>

        <!-- Batch details -->
        <section class="grid grid-cols-1 sm:grid-cols-2 gap-2.5 avoid-break">
            <div class="rounded-xl bg-[#F8EAEA]/50 p-3">
                <span class="block text-[10px] font-bold uppercase tracking-wider text-[#6E6E73]">Dive dates</span>
                @forelse($days as $i => $day)
                    <span class="block text-sm font-bold text-[#1D1D1F]">Day {{ $i + 1 }}: {{ $day->format('l, M d, Y') }}</span>
                @empty
                    <span class="block text-sm font-bold text-[#1D1D1F]">Not set</span>
                @endforelse
            </div>
            <div class="rounded-xl bg-[#F8EAEA]/50 p-3">
                <span class="block text-[10px] font-bold uppercase tracking-wider text-[#6E6E73]">Assigned instructors</span>
                <span class="block text-sm font-bold text-[#1D1D1F]">{{ $coaches->pluck('name')->implode(', ') ?: 'None yet' }}</span>
            </div>
        </section>

        <!-- Participants -->
        <section class="space-y-3">
            <h2 class="text-base font-extrabold text-[#1D1D1F]">Participants</h2>
            <table class="rpt w-full">
                <thead>
                    <tr>
                        <th style="width: 28px;">#</th>
                        <th>Participant</th>
                        <th>Class</th>
                        <th style="width: 20%;">Instructor</th>
                        @foreach($days as $i => $day)
                            <th class="day">Day {{ $i + 1 }}<br><span class="font-semibold">{{ $day->format('M d') }}</span></th>
                        @endforeach
                        <th style="width: 25%;">Remarks</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($participants as $i => $p)
                        <tr>
                            <td class="text-[#6E6E73]">{{ $i + 1 }}</td>
                            <td class="font-semibold">{{ $p['name'] }}</td>
                            <td>{{ $p['class'] }}</td>
                            <td>{{ $p['coach'] ?? 'Not assigned' }}</td>
                            @foreach($days as $day)
                                <td class="day"><span class="box" aria-label="Present on {{ $day->format('M d') }}"></span></td>
                            @endforeach
                            <td></td>
                        </tr>
                    @empty
                        <tr><td colspan="{{ 5 + $days->count() }}" class="text-center text-[#8E8E93]">No participants in active bookings.</td></tr>
                    @endforelse
                </tbody>
            </table>
            <p class="text-[11px] text-[#6E6E73]">Tick the box when the participant is present for that day's session. Note late arrivals, early leaves or other issues under Remarks.</p>
        </section>

        <!-- Sign-off -->
        <section class="space-y-3 avoid-break">
            <h2 class="text-base font-extrabold text-[#1D1D1F]">Instructor sign-off</h2>
            <table class="rpt signoff w-full">
                <thead><tr><th>Instructor</th><th>Signature</th><th style="width: 22%;">Date</th></tr></thead>
                <tbody>
                    @foreach($coaches->isNotEmpty() ? $coaches->pluck('name') : collect(['', '']) as $name)
                        <tr><td class="font-semibold" style="width: 30%;">{{ $name }}<div class="line {{ $name ? 'hidden' : '' }}"></div></td><td><div class="line"></div></td><td><div class="line"></div></td></tr>
                    @endforeach
                </tbody>
            </table>
        </section>

        <div class="pt-5 border-t border-[#F2F2F7] flex items-center justify-between text-[11px] text-[#8E8E93] avoid-break">
            <span>Camp FreedivePH &copy; {{ date('Y') }} &middot; For internal use only</span>
            <span>Printed by {{ auth()->user()->name }} ({{ ucfirst(auth()->user()->role) }})</span>
        </div>
    </div>
</body>
</html>
