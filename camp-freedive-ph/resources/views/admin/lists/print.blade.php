<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title }} - Camp FreedivePH</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@500;600;700;800;900&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css'])
    <style>
        body { font-family: 'Plus Jakarta Sans', system-ui, sans-serif; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        h1, h2, h3 { font-family: 'Outfit', 'Plus Jakarta Sans', sans-serif; }
        .rpt th { text-align: left; font-size: 11px; font-weight: 700; color: #FFFFFF; background: #780000; padding: 6px 8px; }
        .rpt td { padding: 7px 8px; border-bottom: 1px solid #E5E5EA; font-size: 11.5px; vertical-align: top; }
        .rpt .num { text-align: right; white-space: nowrap; }
        .rpt .nowrap { white-space: nowrap; }
        .rpt .min-110 {
            min-width: 110px;
        }
        @media print {
            .no-print { display: none !important; }
            @page { margin: 1.2cm; size: A4 {{ $landscape ? 'landscape' : 'portrait' }}; }
            body { background: #FFFFFF !important; padding: 0 !important; }
            .report-card { box-shadow: none !important; padding: 0 !important; max-width: none !important; }
            .avoid-break { break-inside: avoid; }
            /* Repeat the column header on every page and never split a row */
            .rpt thead { display: table-header-group; }
            .rpt tr { break-inside: avoid; }
        }
    </style>
</head>
{{-- Prints itself when opened on its own tab, unless there are too many records to print --}}
<body class="bg-[#F8F8FA] text-[#1D1D1F] p-4 sm:p-8" @unless($tooMany) onload="if (window.self === window.top) window.print()" @endunless>

    <div class="no-print {{ $landscape ? 'max-w-6xl' : 'max-w-4xl' }} mx-auto mb-6 flex items-center justify-between gap-4">
        <a href="{{ $backUrl }}" class="btn-secondary px-4 py-2 rounded-xl text-sm font-bold">&larr; Back to list</a>
        @unless($tooMany)
            <button type="button" onclick="window.print()" class="btn-primary px-4 py-2 rounded-xl text-sm font-bold">Print / Save as PDF</button>
        @endunless
    </div>

    <div class="report-card {{ $landscape ? 'max-w-6xl' : 'max-w-4xl' }} mx-auto bg-white rounded-2xl p-8 sm:p-10 space-y-6 shadow-xs">

        <!-- Header -->
        <div class="flex items-start justify-between gap-4 pb-5 border-b-2 border-[#780000]">
            <div class="flex items-center gap-3">
                <img src="{{ asset('images/logo.png') }}" alt="Camp FreedivePH" class="w-11 h-11 rounded-full object-contain shrink-0">
                <div>
                    <h1 class="text-xl font-black text-[#780000] tracking-tight">{{ $title }}</h1>
                    <p class="text-xs text-[#6E6E73]">Camp FreedivePH &middot; {{ number_format($count) }} matching {{ Str::plural('record', $count) }}</p>
                </div>
            </div>
            <div class="text-right text-xs text-[#6E6E73]">
                <div>Printed {{ now('Asia/Manila')->format('M d, Y g:i A') }}</div>
            </div>
        </div>

        <!-- Filters -->
        <section class="avoid-break">
            <h2 class="text-[10px] font-bold uppercase tracking-wider text-[#6E6E73] mb-1">Filters applied</h2>
            <p class="text-xs text-[#1D1D1F]">
                @foreach($filters as $label => $value)
                    <span class="whitespace-nowrap"><strong>{{ $label }}:</strong> {{ $value }}</span>@unless($loop->last) &middot; @endunless
                @endforeach
            </p>
        </section>

        @if($tooMany)
            <div class="rounded-xl bg-[#F8EAEA] p-5 text-sm text-[#1D1D1F]" role="alert">
                <strong class="text-[#780000]">Too many records to print.</strong>
                {{ number_format($count) }} records match these filters, and a printout can hold up to {{ $limit }}.
                Narrow the filters (for example by date or status), or use <strong>Export Excel</strong> to get every record.
            </div>
        @else
            <table class="rpt w-full">
                <thead>
                    <tr>
                        @foreach($columns as [$label, $class])
                            <th class="{{ $class }}">{{ $label }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @forelse($rows as $cells)
                        <tr>
                            @foreach($cells as $i => $cell)
                                <td class="{{ $columns[$i][1] }}">{{ $cell }}</td>
                            @endforeach
                        </tr>
                    @empty
                        <tr><td colspan="{{ count($columns) }}" class="text-center text-[#8E8E93]">No records match these filters.</td></tr>
                    @endforelse
                </tbody>
            </table>
        @endif

        <div class="pt-5 border-t border-[#F2F2F7] flex items-center justify-between text-[11px] text-[#8E8E93] avoid-break">
            <span>Camp FreedivePH &copy; {{ date('Y') }} &middot; For internal use only</span>
            <span>Prepared by {{ auth()->user()->name }} ({{ ucfirst(auth()->user()->role) }})</span>
        </div>
    </div>
</body>
</html>
