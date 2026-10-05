@extends('layouts.admin')

@section('title', 'Demand Forecast | Camp FreedivePH')

@section('content')
@php
    $fmt = fn ($n) => rtrim(rtrim(number_format((float) $n, 1), '0'), '.');
    $basis = $modelInfo['data_basis'] ?? null;
    $cmp = $modelInfo['baseline_comparison'] ?? null;
    $validated = $cmp['model_validated'] ?? null;
    $updatedAt = $modelInfo['generated_at'] ?? null;
@endphp

<div class="space-y-6 text-sm"
     x-data="{
         selectedHorizon: '30',
         horizons: @js($horizons),

         get months() { return (this.horizons[this.selectedHorizon] || {}).months || []; },
         get batches() { return (this.horizons[this.selectedHorizon] || {}).batches || []; },

         getMax(metric) {
             let max = 0;
             for (const b of this.batches) {
                 const vals = metric === 'divers' ? [b.divers, b.booked] : [b[metric]];
                 for (const v of vals) if ((v || 0) > max) max = v;
             }
             if (metric === 'revenue') return Math.max(max, 10000);
             if (metric === 'coaches') return Math.max(max, 4);
             if (metric === 'bookings') return Math.max(max, 5);
             return Math.max(max, 10);
         },
         barHeight(value, metric) {
             return Math.max(Math.round(((value || 0) / this.getMax(metric)) * 100), 6);
         },
         whole(v) { return Math.round(v || 0).toLocaleString(); },
         shortPeso(v) {
             if (v >= 1000000) return '₱' + (v / 1000000).toFixed(1) + 'M';
             if (v >= 1000) return '₱' + (v / 1000).toFixed(0) + 'k';
             return '₱' + Math.round(v || 0).toLocaleString();
         },
         formatCurrency(v) {
             return '₱' + Number(v || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
         },
         plural(n, one, many) { return n + ' ' + (n === 1 ? one : many); }
     }">

    <!-- Page header -->
    <div>
        <h1 class="text-2xl sm:text-3xl font-extrabold text-[#1D1D1F] tracking-tight">Demand Forecast</h1>
        <p class="text-[#6E6E73] mt-1 max-w-3xl">
            How many divers, bookings and how much revenue we expect for the upcoming batches, to help plan batches and coaches.
            These are <strong class="text-[#1D1D1F]">estimates</strong>, shown separately from actual bookings.
        </p>
    </div>

    @if(!$hasForecast && empty($batchForecasts))
        <div class="rounded-xl border border-[#FF8D28]/40 bg-[#FFF9F2] p-4 text-[#1D1D1F]">
            <strong>No forecast yet.</strong>
            Expected numbers appear here once the forecast has been generated for scheduled batches.
            <span class="block text-xs text-[#6E6E73] mt-1">For the tech team: run <code class="font-mono">python retrain_pipeline.py</code> in the <code class="font-mono">demand-forecast</code> folder.</span>
        </div>
    @endif

    @if(!$rules['rules_loaded'])
        <div class="rounded-xl border border-[#D70015]/30 bg-[#FFF5F5] p-4 text-[#1D1D1F]">
            <strong>Demand labels are using default settings.</strong>
            <span class="block text-xs text-[#6E6E73] mt-1">For the tech team: <code class="font-mono">demand-forecast/demand_thresholds.json</code> was not found.</span>
        </div>
    @endif

    @if(!empty($batchForecasts) && ($validated === false || $basis === 'limited_history'))
        <div class="rounded-xl border border-[#FDE68A] bg-[#FFFBEB] p-4 flex gap-3">
            <svg class="w-5 h-5 shrink-0 text-[#B45309] mt-0.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 8v5"/><path d="M12 16h.01"/></svg>
            <p class="text-[#92400E]">
                <strong>Use these numbers as a rough guide.</strong>
                The forecast learned from {{ $modelInfo['training_batches'] ?? 'past' }} past batches
                {{ $validated === false ? 'and is not yet more accurate than looking at past months' : 'and there is not much history yet' }},
                so check actual bookings before making big decisions. It gets better as more batches are completed.
            </p>
        </div>
    @endif

    <!-- Coming months: look-ahead selector + month cards (same design as Reports & Analytics) -->
    <div class="bg-white rounded-xl border border-[#E5E5EA] p-4 sm:p-6 shadow-2xs space-y-5">
        <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4">
            <div>
                <h2 class="text-base sm:text-lg font-extrabold text-[#1D1D1F]">Coming months</h2>
                <p class="text-xs sm:text-sm text-[#6E6E73] mt-1">Totals of the scheduled batches in each month.</p>
            </div>

            <div class="flex flex-col sm:flex-row sm:items-center gap-3 w-full lg:w-auto">
                @if($updatedAt)
                    <div class="inline-flex items-center gap-1.5 text-xs sm:text-sm font-semibold text-[#6E6E73] bg-[#F2F2F7] px-2.5 py-1.5 rounded-lg self-start sm:self-auto">
                        <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                        <span>Updated {{ \Carbon\Carbon::parse($updatedAt)->diffForHumans() }}</span>
                    </div>
                @endif

                <div class="grid grid-cols-4 sm:inline-flex items-center p-1 bg-[#F2F2F7] rounded-xl border border-[#E5E5EA] w-full sm:w-auto" role="tablist" aria-label="Look ahead">
                    @foreach(array_keys($horizons) as $days)
                        <button type="button" role="tab"
                                @click="selectedHorizon = '{{ $days }}'"
                                :aria-selected="selectedHorizon === '{{ $days }}'"
                                :class="selectedHorizon === '{{ $days }}' ? 'bg-white text-[#780000] font-black shadow-2xs' : 'text-[#6E6E73] font-semibold hover:text-[#1D1D1F]'"
                                class="px-2.5 sm:px-3 py-1.5 text-xs sm:text-sm rounded-lg transition-all duration-150 cursor-pointer text-center">
                            {{ $days }} Days
                        </button>
                    @endforeach
                </div>
            </div>
        </div>

        <div>
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-1 mb-3">
                <span class="text-xs sm:text-sm font-black uppercase tracking-wider text-[#6E6E73]">
                    Expected per month (<span x-text="months.length"></span>)
                </span>
                <span class="text-xs sm:text-sm font-semibold text-[#8E8E93]" x-text="'Batches starting in the next ' + selectedHorizon + ' days'"></span>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
                <template x-for="(m, index) in months" :key="m.month">
                    <div class="p-4 sm:p-5 rounded-2xl bg-gradient-to-b from-[#780000]/[0.04] via-white to-white border border-[#E5E5EA] shadow-2xs space-y-3.5 relative overflow-hidden">
                        <div class="absolute -top-10 -right-10 w-36 h-36 bg-gradient-to-br from-[#780000]/20 via-[#9E2A2B]/12 to-transparent rounded-full blur-2xl pointer-events-none"></div>
                        <div class="absolute -top-8 left-1/4 w-32 h-20 bg-gradient-to-b from-[#780000]/10 to-transparent rounded-full blur-xl pointer-events-none"></div>

                        <div class="relative z-10 flex items-start justify-between gap-3">
                            <h4 class="text-base font-black text-[#1D1D1F] pt-0.5" x-text="m.month_label"></h4>
                            <div class="flex flex-col items-end gap-1 shrink-0">
                                <span class="text-[11px] font-black uppercase tracking-wider px-2.5 py-0.5 rounded-full whitespace-nowrap"
                                      :class="{
                                          'bg-amber-50 text-amber-800': m.demand_level === 'High',
                                          'bg-emerald-50 text-emerald-800': m.demand_level === 'Medium',
                                          'bg-slate-100 text-slate-700': m.demand_level === 'Low'
                                      }"
                                      x-text="m.demand_level + ' Demand'"></span>
                                <span class="text-xs font-bold px-2 py-0.5 rounded-md whitespace-nowrap inline-flex items-center"
                                      :class="{
                                          'bg-rose-50 text-[#780000]': m.season_period === 'Peak',
                                          'bg-teal-50 text-teal-800': m.season_period === 'Shoulder',
                                          'bg-slate-100 text-slate-700': m.season_period === 'Off-Peak'
                                      }"
                                      x-text="m.season_period + ' Season'"></span>
                            </div>
                        </div>

                        <div class="relative z-10 pt-0.5">
                            <span class="text-[11px] font-bold text-[#6E6E73] uppercase tracking-wider block">Expected revenue</span>
                            <div class="text-lg sm:text-xl font-black text-[#780000] tracking-tight mt-0.5 truncate" x-text="formatCurrency(m.predicted_revenue_php)"></div>
                        </div>

                        <div class="relative z-10 pt-2 border-t border-[#F2F2F7] space-y-1.5 text-xs sm:text-sm text-[#6E6E73]">
                            <div class="flex items-center justify-between">
                                <span>Expected divers</span>
                                <strong class="text-[#1D1D1F] font-extrabold whitespace-nowrap" x-text="whole(m.predicted_participants) + ' divers'"></strong>
                            </div>
                            <div class="flex items-center justify-between">
                                <span>Avg divers per batch</span>
                                <span class="font-bold text-[#1D1D1F] whitespace-nowrap"><span x-text="whole(m.avg_participants_per_batch)"></span><span class="font-normal text-[#8E8E93]"> divers</span></span>
                            </div>
                            <div class="flex items-center justify-between">
                                <span>Expected bookings</span>
                                <strong class="text-[#1D1D1F] font-extrabold whitespace-nowrap" x-text="whole(m.predicted_bookings) + ' bookings'"></strong>
                            </div>
                            <div class="flex items-center justify-between">
                                <span>Batches</span>
                                <span class="font-bold text-[#1D1D1F] whitespace-nowrap" x-text="plural(m.batches, 'batch', 'batches')"></span>
                            </div>
                            <div class="flex items-center justify-between pt-1.5 text-[#780000] font-black">
                                <span>Coaches per batch</span>
                                <span class="bg-[#780000]/10 px-2 py-0.5 rounded-md text-xs font-black whitespace-nowrap" x-text="plural(m.coaches_peak, 'coach', 'coaches')"></span>
                            </div>
                        </div>
                    </div>
                </template>

                <div x-show="months.length === 0" class="col-span-full p-8 text-center bg-[#F2F2F7] rounded-xl border border-dashed border-[#E5E5EA] text-sm text-[#8E8E93]">
                    No batches scheduled in this period yet.
                </div>
            </div>

            <p class="text-xs text-[#6E6E73] mt-3 leading-relaxed">
                <strong class="text-[#1D1D1F]">Demand</strong> shows how busy a batch is:
                Low = {{ $fmt($rules['low_max']) }} divers or fewer, Medium = {{ $fmt($rules['low_max']) }}–{{ $fmt($rules['medium_max']) }} divers, High = more than {{ $fmt($rules['medium_max']) }} divers.
                <strong class="text-[#1D1D1F]">Season</strong>: Peak = our busiest time of year, Shoulder = in-between, Off-Peak = our quietest time.
            </p>
        </div>
    </div>

    <!-- Per-batch charts (same chart design as Reports & Analytics) -->
    @php
        $charts = [
            ['metric' => 'divers', 'title' => 'Divers per batch', 'desc' => 'Divers already booked next to the divers we expect by the trip date', 'unit' => ['diver', 'divers'], 'booked' => true],
            ['metric' => 'bookings', 'title' => 'Bookings per batch', 'desc' => 'How many bookings we expect for each batch', 'unit' => ['booking', 'bookings'], 'booked' => false],
            ['metric' => 'revenue', 'title' => 'Revenue per batch', 'desc' => 'How much revenue we expect from each batch', 'unit' => null, 'booked' => false],
            ['metric' => 'coaches', 'title' => 'Coaches needed per batch', 'desc' => 'How many coaches each batch needs to keep 1 coach for every ' . $diversPerCoach . ' divers', 'unit' => ['coach', 'coaches'], 'booked' => false],
        ];
    @endphp
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 sm:gap-6">
        @foreach($charts as $chart)
            @php $m = $chart['metric']; @endphp
            <div class="bg-white rounded-xl border border-[#E5E5EA] p-4 sm:p-5 shadow-2xs space-y-4 flex flex-col justify-between">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                    <div>
                        <h3 class="text-sm sm:text-base font-extrabold text-[#1D1D1F]">{{ $chart['title'] }}</h3>
                        <p class="text-xs sm:text-sm text-[#6E6E73] mt-0.5">{{ $chart['desc'] }}</p>
                    </div>
                    <div class="flex items-center gap-3 text-xs sm:text-sm shrink-0 self-start sm:self-auto">
                        @if($chart['booked'])
                            <div class="flex items-center gap-1.5">
                                <span class="w-3 h-3 rounded-sm bg-[#780000]"></span>
                                <span class="font-bold text-[#1D1D1F]">Booked so far</span>
                            </div>
                        @endif
                        <div class="flex items-center gap-1.5">
                            <span class="w-3 h-3 rounded-sm bg-[#00C3D0]"></span>
                            <span class="font-bold text-[#1D1D1F]">Expected</span>
                        </div>
                    </div>
                </div>

                <div class="pt-2">
                    <div class="flex items-end justify-around gap-2 sm:gap-4 h-52 pt-8 pb-2 px-2 sm:px-4 relative overflow-visible">
                        <div class="absolute inset-0 flex flex-col justify-between pt-8 pb-2 px-2 pointer-events-none z-0">
                            <div class="w-full border-b border-dashed border-[#D1D1D6]/70"></div>
                            <div class="w-full border-b border-dashed border-[#D1D1D6]/70"></div>
                            <div class="w-full border-b border-dashed border-[#D1D1D6]/70"></div>
                            <div class="w-full border-b border-dashed border-[#D1D1D6]/70"></div>
                        </div>

                        <template x-for="b in batches" :key="'{{ $m }}_' + b.key">
                            <div class="flex-1 flex flex-col items-center h-full justify-end relative z-10 px-1 sm:px-2 max-w-[110px] sm:max-w-[130px] lg:max-w-[150px]">
                                <div class="flex items-end justify-center gap-1.5 sm:gap-2.5 w-full h-full">
                                    @if($chart['booked'])
                                        <!-- Booked so far -->
                                        <div class="flex-1 flex flex-col items-center h-full justify-end group relative w-full max-w-[38px] sm:max-w-[48px] lg:max-w-[56px]">
                                            <div class="absolute bottom-full mb-2.5 left-1/2 -translate-x-1/2 min-w-[180px] w-max max-w-[250px] p-2.5 sm:p-3 rounded-xl bg-[#1D1D1F] text-white opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all duration-200 z-30 pointer-events-none shadow-xl space-y-1.5 text-left">
                                                <div class="flex items-center justify-between gap-1.5 pb-1 border-b border-white/10 flex-wrap">
                                                    <span class="font-bold text-xs text-[#F1D5D5]" x-text="b.date"></span>
                                                    <span class="text-[10px] uppercase px-1.5 py-0.5 rounded font-black tracking-wider bg-[#780000] text-white">Booked</span>
                                                </div>
                                                <div class="text-white font-black text-xs sm:text-sm" x-text="plural(b.booked, 'diver', 'divers')"></div>
                                                <div class="text-gray-300 text-xs flex items-center justify-between gap-2">
                                                    <span>Slots</span>
                                                    <span class="font-bold text-white" x-text="b.booked + ' of ' + b.capacity"></span>
                                                </div>
                                            </div>
                                            <span class="text-xs sm:text-sm font-black mb-1 truncate max-w-full text-[#780000]" x-text="b.booked"></span>
                                            <div class="w-full rounded-t-md transition-all duration-200 group-hover:opacity-90 cursor-pointer bg-[#780000]"
                                                 :style="'height: ' + barHeight(b.booked, 'divers') + '%;'"></div>
                                        </div>
                                    @endif

                                    <!-- Expected -->
                                    <div class="flex-1 flex flex-col items-center h-full justify-end group relative w-full {{ $chart['booked'] ? 'max-w-[38px] sm:max-w-[48px] lg:max-w-[56px]' : 'max-w-[56px] sm:max-w-[70px] lg:max-w-[80px]' }}">
                                        <div class="absolute bottom-full mb-2.5 left-1/2 -translate-x-1/2 min-w-[180px] w-max max-w-[250px] p-2.5 sm:p-3 rounded-xl bg-[#1D1D1F] text-white opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all duration-200 z-30 pointer-events-none shadow-xl space-y-1.5 text-left">
                                            <div class="flex items-center justify-between gap-1.5 pb-1 border-b border-white/10 flex-wrap">
                                                <span class="font-bold text-xs text-sky-200" x-text="b.date"></span>
                                                <span class="text-[10px] uppercase px-1.5 py-0.5 rounded font-black tracking-wider bg-[#003049] text-white">Expected</span>
                                            </div>
                                            @if($m === 'revenue')
                                                <div class="text-white font-black text-xs sm:text-sm" x-text="formatCurrency(b.revenue)"></div>
                                            @else
                                                <div class="text-white font-black text-xs sm:text-sm" x-text="whole(b.{{ $m }}) + ' {{ $chart['unit'][1] }}'"></div>
                                            @endif
                                            @if($m === 'divers')
                                                <div class="text-gray-300 text-xs flex items-center justify-between gap-2" x-show="b.low !== null && b.high !== null">
                                                    <span>Could be</span>
                                                    <span class="font-bold text-white" x-text="whole(b.low) + ' to ' + whole(b.high) + ' divers'"></span>
                                                </div>
                                            @endif
                                            <div class="text-gray-300 text-xs flex items-center justify-between gap-2">
                                                <span>Demand</span>
                                                <span class="font-bold text-white" x-text="b.demand + ' demand'"></span>
                                            </div>
                                            <div class="text-gray-300 text-xs flex items-center justify-between gap-2">
                                                <span>Starts in</span>
                                                <span class="font-bold text-white" x-text="plural(b.days_to_start, 'day', 'days')"></span>
                                            </div>
                                        </div>
                                        <span class="text-xs sm:text-sm font-black mb-1 truncate max-w-full text-[#003049]"
                                              x-text="{{ $m === 'revenue' ? 'shortPeso(b.revenue)' : 'whole(b.' . $m . ')' }}"></span>
                                        <div class="w-full rounded-t-md transition-all duration-200 group-hover:opacity-90 cursor-pointer bg-[#00C3D0]"
                                             :style="'height: ' + barHeight(b.{{ $m }}, '{{ $m }}') + '%;'"></div>
                                    </div>
                                </div>
                            </div>
                        </template>

                        <div x-show="batches.length === 0" class="absolute inset-0 flex items-center justify-center text-sm text-[#8E8E93]">
                            No batches scheduled in this period yet.
                        </div>
                    </div>

                    <div class="flex items-center justify-around gap-2 sm:gap-4 px-2 sm:px-4 text-center pt-2">
                        <template x-for="b in batches" :key="'label_{{ $m }}_' + b.key">
                            <div class="flex-1 max-w-[110px] sm:max-w-[130px] lg:max-w-[150px] min-w-0 truncate" :title="b.date + ' · ' + b.code">
                                <span class="text-xs sm:text-sm font-bold text-[#1D1D1F] block truncate" x-text="b.label"></span>
                            </div>
                        </template>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    <!-- How accurate is the forecast? (collapsible, technical) -->
    @if(!empty($batchForecasts) || ($modelInfo['model_version'] ?? null))
        <div x-data="{ open: false }" class="bg-white rounded-xl border border-[#E5E5EA] shadow-2xs">
            <button type="button" @click="open = !open" :aria-expanded="open"
                    class="w-full min-h-[52px] px-5 py-3 flex items-center justify-between gap-2 text-left focus:outline-none focus-visible:ring-2 focus-visible:ring-[#780000] rounded-xl">
                <span class="text-sm sm:text-base font-extrabold text-[#1D1D1F]">How accurate is the forecast?</span>
                <svg class="w-4 h-4 shrink-0 text-[#6E6E73] transition-transform" :class="open ? 'rotate-180' : ''" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
            </button>
            <div x-show="open" x-cloak class="px-5 pb-5 space-y-3">
                @if($cmp)
                    <p class="text-[#3A3A3C]">
                        We tested the forecast on the {{ $cmp['test_batches'] ?? '—' }} most recent real batches and measured how far off it was on average.
                        <strong>Smaller is better.</strong> It is compared with two simple methods.
                    </p>
                    <div class="overflow-x-auto">
                        <table class="w-full text-xs sm:text-sm">
                            <thead>
                                <tr class="text-left text-[#6E6E73] text-xs">
                                    <th class="py-1.5 pr-4 font-semibold">Average miss in&hellip;</th>
                                    <th class="py-1.5 pr-4 font-semibold">This forecast</th>
                                    <th class="py-1.5 pr-4 font-semibold">"Same as last batch"</th>
                                    <th class="py-1.5 pr-4 font-semibold">"Same as that month usually"</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach(['participant_count' => 'Divers per batch', 'booking_count' => 'Bookings per batch'] as $key => $label)
                                    @if(isset($cmp[$key]))
                                        @php
                                            $vals = [$cmp[$key]['model']['MAE'], $cmp[$key]['naive']['MAE'], $cmp[$key]['seasonal_naive']['MAE']];
                                            $best = min($vals);
                                        @endphp
                                        <tr class="border-t border-[#E5E5EA]">
                                            <td class="py-2 pr-4 font-semibold text-[#1D1D1F]">{{ $label }}</td>
                                            @foreach($vals as $v)
                                                <td class="py-2 pr-4 {{ $v == $best ? 'font-bold text-[#047857]' : 'text-[#3A3A3C]' }}">±{{ $fmt($v) }}{{ $v == $best ? ' (best)' : '' }}</td>
                                            @endforeach
                                        </tr>
                                    @endif
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
                <p class="text-xs text-[#6E6E73]">
                    Model {{ $modelInfo['model_version'] ?? '—' }} &middot; updated {{ $updatedAt ?? '—' }}
                    &middot; learned from {{ $modelInfo['training_batches'] ?? '—' }} past batches.
                    The "could be" range covers 8 out of 10 likely outcomes. A forecast is never lower than the divers already booked.
                </p>
            </div>
        </div>
    @endif

</div>
@endsection
