@php
    $fin = $data['financials'] ?? [];
    $peso = fn ($v) => '₱' . number_format((float) $v, 2);
    $delta = (float) ($fin['revenue_delta'] ?? 0);
    $gross = (float) ($fin['gross_revenue'] ?? 0);
@endphp

<div class="space-y-5">

    @include('admin.reports.partials.summary_cards', ['cards' => [
        [
            'label' => 'Money kept',
            'value' => $peso($fin['net_revenue'] ?? 0),
            'hint' => ($fin['refunds_processed'] ?? 0) > 0
                ? $peso($gross) . ' collected minus ' . $peso($fin['refunds_processed']) . ' refunded'
                : 'All payments collected, nothing refunded',
            'badge' => ($delta >= 0 ? '+' : '') . $delta . '% vs before',
            'badgeTone' => $delta >= 0 ? 'bg-[#00C3D0]/10 text-[#00636A]' : 'bg-[#FEF2F2] text-[#B91C1C]',
        ],
        [
            'label' => 'Paid as deposit',
            'value' => $peso($fin['downpayment_revenue'] ?? 0),
            'hint' => $peso($fin['balance_revenue'] ?? 0) . ' was paid in full instead',
            'tone' => 'text-[#1D1D1F]',
        ],
        [
            'label' => 'Still to collect',
            'value' => $peso($fin['outstanding_receivables'] ?? 0),
            'hint' => 'Unpaid balance of active bookings, due on arrival',
            'tone' => 'text-[#B45309]',
        ],
        [
            'label' => 'Average per participant',
            'value' => $peso($fin['arpd'] ?? 0),
            'hint' => $peso($fin['arpb'] ?? 0) . ' per booking on average',
            'tone' => 'text-[#00838C]',
        ],
    ]])

    <div class="grid grid-cols-1 lg:grid-cols-12 gap-5 items-start">

        <!-- Money by package -->
        <div class="lg:col-span-8 bg-white rounded-2xl border border-[#E5E5EA] p-5 space-y-4">
            <div>
                <h3 class="text-base font-extrabold text-[#1D1D1F] flex items-center gap-2"><span class="w-1 h-4 rounded-full bg-[#780000]" aria-hidden="true"></span>Money by package</h3>
                <p class="text-sm text-[#8E8E93]">How much each package brought in, and how many paid bookings and participants it had</p>
            </div>

            @php
                $stops = [];
                $at = 0;
                foreach ($fin['packages'] ?? [] as $pData) {
                    $share = (float) ($pData['share_percentage'] ?? 0);
                    if ($share > 0) {
                        $stops[] = "{$pData['color']} {$at}% " . ($at + $share) . '%';
                        $at += $share;
                    }
                }
                // Anything not linked to a package stays grey instead of being painted as the last package
                if ($at > 0 && $at < 100) {
                    $stops[] = "#E5E5EA {$at}% 100%";
                }
                $donut = $stops ? 'conic-gradient(' . implode(', ', $stops) . ')' : '#E5E5EA';
            @endphp

            <div class="flex flex-col md:flex-row items-center gap-6 md:gap-10">
                <div class="w-52 h-52 sm:w-60 sm:h-60 rounded-full flex items-center justify-center shrink-0" style="background: {{ $donut }};">
                    <div class="w-32 h-32 sm:w-38 sm:h-38 bg-white rounded-full flex flex-col items-center justify-center text-center p-3">
                        <span class="text-[11px] font-bold text-[#8E8E93] uppercase tracking-wider">Collected</span>
                        <span class="text-lg sm:text-xl font-black text-[#1D1D1F] tracking-tight">₱{{ number_format($gross) }}</span>
                    </div>
                </div>

                <div class="flex-1 w-full divide-y divide-[#F2F2F7]">
                    @foreach($fin['packages'] ?? [] as $pData)
                        <div class="py-3 flex items-center justify-between gap-3">
                            <div class="flex items-center gap-3 min-w-0">
                                <span class="w-1.5 self-stretch rounded-full shrink-0" style="background: {{ $pData['color'] }};"></span>
                                <div class="min-w-0">
                                    <span class="block font-extrabold text-[#1D1D1F]">{{ $pData['name'] }}</span>
                                    <span class="block text-xs text-[#6E6E73]">{{ $pData['bookings_count'] }} paid {{ Str::plural('booking', $pData['bookings_count']) }} &middot; {{ $pData['pax_count'] }} {{ Str::plural('participant', $pData['pax_count']) }}</span>
                                </div>
                            </div>
                            <div class="text-right shrink-0">
                                <span class="block text-lg sm:text-xl font-black text-[#1D1D1F] whitespace-nowrap">{{ $peso($pData['revenue']) }}</span>
                                <span class="block text-xs font-bold whitespace-nowrap" style="color: {{ $pData['color'] }};">{{ $pData['share_percentage'] }}% of money collected</span>
                            </div>
                        </div>
                    @endforeach
                    @if($at > 0 && $at < 99.5)
                        <p class="pt-3 text-xs text-[#8E8E93]">Grey part: {{ round(100 - $at, 1) }}% of payments are not linked to a package.</p>
                    @endif
                </div>
            </div>
        </div>

        <!-- Extras -->
        <div class="lg:col-span-4 bg-white rounded-2xl border border-[#E5E5EA] p-5 space-y-3">
            <div>
                <h3 class="text-base font-extrabold text-[#1D1D1F] flex items-center gap-2"><span class="w-1 h-4 rounded-full bg-[#780000]" aria-hidden="true"></span>Extras</h3>
                <p class="text-sm text-[#8E8E93]">Add-ons and price changes</p>
            </div>
            @php $netLift = (float) ($fin['dynamic_pricing']['net_lift'] ?? 0); @endphp
            <dl class="divide-y divide-[#F2F2F7] text-sm">
                <div class="py-2.5 flex items-center justify-between gap-3">
                    <dt>
                        <span class="block font-semibold text-[#1D1D1F]">Carpool from Manila</span>
                        <span class="block text-xs text-[#8E8E93]">{{ $fin['carpool']['pax_count'] ?? 0 }} riders &middot; estimate</span>
                    </dt>
                    <dd class="font-black text-[#1D1D1F] whitespace-nowrap">{{ $peso($fin['carpool']['estimated_revenue'] ?? 0) }}</dd>
                </div>
                <div class="py-2.5 flex items-center justify-between gap-3">
                    <dt>
                        <span class="block font-semibold text-[#1D1D1F]">Boat dive</span>
                        <span class="block text-xs text-[#8E8E93]">{{ $fin['boat_dive']['pax_count'] ?? 0 }} participants &middot; estimate</span>
                    </dt>
                    <dd class="font-black text-[#1D1D1F] whitespace-nowrap">{{ $peso($fin['boat_dive']['estimated_revenue'] ?? 0) }}</dd>
                </div>
                <div class="py-2.5 flex items-center justify-between gap-3">
                    <dt>
                        <span class="block font-semibold text-[#1D1D1F]">Price adjustments</span>
                        <span class="block text-xs text-[#8E8E93]">+{{ $peso($fin['dynamic_pricing']['positive_yield'] ?? 0) }} busy-day pricing, −{{ $peso($fin['dynamic_pricing']['discounts_given'] ?? 0) }} discounts</span>
                    </dt>
                    <dd class="font-black whitespace-nowrap {{ $netLift >= 0 ? 'text-[#047857]' : 'text-[#B91C1C]' }}">{{ $netLift < 0 ? '−' : '+' }}{{ $peso(abs($netLift)) }}</dd>
                </div>
            </dl>
        </div>
    </div>
</div>
