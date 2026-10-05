<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Executive Report Summary - Camp FreedivePH</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@500;600;700;800;900&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css'])
    <style>
        body {
            font-family: 'Plus Jakarta Sans', system-ui, sans-serif;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
        h1, h2, h3, h4, h5, h6 {
            font-family: 'Outfit', 'Plus Jakarta Sans', sans-serif;
        }
        @media print {
            .no-print {
                display: none !important;
            }
            @page {
                margin: 1.2cm;
                size: portrait;
            }
            .page-break {
                page-break-before: always;
            }
            body {
                background: #FFFFFF !important;
                padding: 0 !important;
            }
            .report-card {
                box-shadow: none !important;
                border: none !important;
                padding: 0 !important;
            }
        }
    </style>
</head>
<body class="bg-[#F8F8FA] text-[#1D1D1F] p-4 sm:p-8" onload="window.print()">

    <!-- On-Screen Navigation & Action Bar (Hidden in Print) -->
    <div class="no-print max-w-4xl mx-auto mb-6 flex items-center justify-between gap-4">
        <a href="{{ (auth()->user()->isOwner() ? route('owner.reports.index') : route('admin.reports.index')) . '?' . http_build_query(['preset' => $range['preset'], 'start_date' => $range['start']->format('Y-m-d'), 'end_date' => $range['end']->format('Y-m-d')]) }}" 
           class="inline-flex items-center gap-2 px-4 py-2 bg-white text-[#1D1D1F] hover:text-[#780000] rounded-xl border border-[#E5E5EA] shadow-2xs font-bold text-sm transition-colors">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path></svg>
            <span>Back to Reports & Analytics</span>
        </a>

        <div class="flex items-center gap-2.5">
            <button type="button" 
                    onclick="window.print()" 
                    class="btn-primary px-4 py-2 text-sm font-bold flex items-center gap-2 shadow-2xs cursor-pointer">
                <img src="{{ asset('icons/icons8-print-60.png') }}" class="w-4 h-4 invert shrink-0" alt="Print">
                <span>Print / Save PDF</span>
            </button>
        </div>
    </div>

    <!-- Official Report Document (Borderless Clean Composition) -->
    <div class="report-card max-w-4xl mx-auto bg-white rounded-2xl p-8 sm:p-10 space-y-8 shadow-xs">
        
        <!-- Header -->
        <div class="flex items-start justify-between pb-6 border-b border-[#F2F2F7]">
            <div class="flex items-center gap-3.5">
                <img src="{{ asset('images/logo.png') }}" alt="Camp FreedivePH" class="w-12 h-12 rounded-full object-contain shrink-0">
                <div>
                    <h1 class="text-xl font-black text-[#1D1D1F] tracking-tight">Camp Freedive<span class="text-[#780000]">PH</span></h1>
                </div>
            </div>
            <div class="text-right text-sm">
                <div class="font-extrabold text-[#780000]">{{ $range['label'] }}</div>
                <div class="text-[#8E8E93] text-xs">{{ $range['start']->year === $range['end']->year ? $range['start']->format('M d') . ' - ' . $range['end']->format('M d, Y') : $range['start']->format('M d, Y') . ' - ' . $range['end']->format('M d, Y') }}</div>
                <div class="text-[#8E8E93] text-[11px] mt-0.5">Generated: {{ \Carbon\Carbon::now('Asia/Manila')->format('M d, Y h:i A') }}</div>
            </div>
        </div>

        <!-- 1. Executive Summary KPIs -->
        <div class="space-y-3">
            <h2 class="text-xs font-extrabold uppercase tracking-wider text-[#6E6E73]">1. Key Performance Indicators</h2>
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 text-sm">
                @if($isOwner)
                    <div class="p-4 rounded-xl bg-[#F8F8FA]">
                        <span class="text-xs font-bold uppercase text-[#6E6E73] block">Net Revenue Collected</span>
                        <span class="text-lg font-black text-[#780000] block mt-1">₱{{ number_format($data['financials']['net_revenue'] ?? 0, 2) }}</span>
                    </div>
                @endif
                <div class="p-4 rounded-xl bg-[#F8F8FA]">
                    <span class="text-xs font-bold uppercase text-[#6E6E73] block">Total Guests (Participants)</span>
                    <span class="text-lg font-black text-[#1D1D1F] block mt-1">{{ $data['bookings']['total_participants'] ?? 0 }} guests</span>
                </div>
                <div class="p-4 rounded-xl bg-[#F8F8FA]">
                    <span class="text-xs font-bold uppercase text-[#6E6E73] block">Average Camp Fill Rate</span>
                    <span class="text-lg font-black text-[#1D1D1F] block mt-1">{{ $data['operations']['avg_occupancy'] ?? 0 }}%</span>
                </div>
                <div class="p-4 rounded-xl bg-[#F8F8FA]">
                    <span class="text-xs font-bold uppercase text-[#6E6E73] block">Coach Safety Compliance</span>
                    <span class="text-lg font-black text-emerald-700 block mt-1">{{ $data['operations']['safety_compliance_rate'] ?? 100 }}%</span>
                </div>
            </div>
        </div>

        @if($isOwner)
            <!-- 2. Financial Breakdown (Owner Only) -->
            <div class="space-y-3">
                <h2 class="text-xs font-extrabold uppercase tracking-wider text-[#6E6E73]">2. Revenue by Course Package</h2>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-[#F8F8FA] text-[#6E6E73] font-bold">
                            <tr>
                                <th class="p-3 rounded-l-lg">Course Package</th>
                                <th class="p-3 text-center">Bookings</th>
                                <th class="p-3 text-center">Guests</th>
                                <th class="p-3 text-right">Revenue (PHP)</th>
                                <th class="p-3 text-right rounded-r-lg">Revenue Share</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[#F2F2F7]">
                            @foreach($data['financials']['packages'] ?? [] as $key => $pkg)
                                <tr>
                                    <td class="p-3 font-bold text-[#1D1D1F]">{{ $pkg['name'] }}</td>
                                    <td class="p-3 text-center">{{ $pkg['bookings_count'] }}</td>
                                    <td class="p-3 text-center">{{ $pkg['pax_count'] }} pax</td>
                                    <td class="p-3 text-right font-extrabold">₱{{ number_format($pkg['revenue'], 2) }}</td>
                                    <td class="p-3 text-right font-bold text-[#780000]">{{ $pkg['share'] }}%</td>
                                </tr>
                            @endforeach
                            <tr class="bg-[#F8F8FA] font-extrabold text-[#1D1D1F]">
                                <td class="p-3 rounded-l-lg">Total Gross Collections</td>
                                <td class="p-3 text-center">{{ $data['bookings']['total_bookings'] ?? 0 }}</td>
                                <td class="p-3 text-center">{{ $data['bookings']['total_participants'] ?? 0 }} pax</td>
                                <td class="p-3 text-right text-[#780000]">₱{{ number_format($data['financials']['gross_revenue'] ?? 0, 2) }}</td>
                                <td class="p-3 text-right rounded-r-lg">100.0%</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

        <!-- 3. Operational Batches & Coach Staffing Summary -->
        <div class="space-y-3">
            <h2 class="text-xs font-extrabold uppercase tracking-wider text-[#6E6E73]">{{ $isOwner ? '3' : '2' }}. Camp Batches & Coach Assignments</h2>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="bg-[#F8F8FA] text-[#6E6E73] font-bold">
                        <tr>
                            <th class="p-3 rounded-l-lg">Batch Name</th>
                            <th class="p-3">Dates</th>
                            <th class="p-3">Package</th>
                            <th class="p-3 text-center">Participants</th>
                            <th class="p-3 rounded-r-lg">Assigned Coaches</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[#F2F2F7]">
                        @forelse($data['operations']['batches_list'] ?? [] as $batch)
                            @php
                                $pax = $batch->total_participants_count;
                                $cap = $batch->max_capacity ?: 20;
                                $distinctCoaches = $batch->assigned_coaches;
                            @endphp
                            <tr>
                                <td class="p-3 font-bold text-[#780000]">{{ $batch->display_name }}</td>
                                @php 
                                    $bStart = \Carbon\Carbon::parse($batch->start_date); 
                                    $bEnd = \Carbon\Carbon::parse($batch->end_date); 
                                @endphp
                                <td class="p-3 text-[#6E6E73]">{{ $bStart->year === $bEnd->year ? $bStart->format('M d') . ' - ' . $bEnd->format('M d, Y') : $bStart->format('M d, Y') . ' - ' . $bEnd->format('M d, Y') }}</td>
                                <td class="p-3 font-semibold">{{ ucfirst($batch->class_type ?? 'Discovery') }}</td>
                                <td class="p-3 text-center font-bold text-[#1D1D1F]">{{ $pax }} / {{ $cap }} pax</td>
                                <td class="p-3 text-sm font-semibold">{{ $distinctCoaches->pluck('name')->implode(', ') ?: 'No coach assigned' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="p-4 text-center text-[#8E8E93]">No batches scheduled during this period.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Footer Signoff -->
        <div class="pt-6 border-t border-[#F2F2F7] flex items-center justify-between text-xs text-[#8E8E93]">
            <span>Camp FreedivePH &copy; {{ date('Y') }} - Confidential Internal Report</span>
            <span>Generated by {{ $user->name }} ({{ ucfirst($user->role) }})</span>
        </div>

    </div>

</body>
</html>
