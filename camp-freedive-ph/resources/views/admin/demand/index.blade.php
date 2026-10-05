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
         // Point positions (0-100) for a series; 'scale' picks which metric's maximum to use
         chartPoints(series, scale) {
             const list = this.batches;
             const n = list.length;
             const max = this.getMax(scale) * 1.15;
             return list.map((b, i) => ({
                 x: n === 1 ? 50 : 6 + i * (88 / (n - 1)),
                 y: 96 - ((b[series] || 0) / max) * 88,
             }));
         },
         // Smooth curve through the points that never overshoots them (monotone cubic, Fritsch-Carlson),
         // so equal batches stay flat and no false peaks or dips are drawn
         linePath(pts) {
             const n = pts.length;
             if (!n) return '';
             if (n === 1) return 'M 0 ' + pts[0].y + ' L 100 ' + pts[0].y;
             const dx = [], m = [];
             for (let i = 0; i < n - 1; i++) {
                 dx.push(pts[i + 1].x - pts[i].x);
                 m.push((pts[i + 1].y - pts[i].y) / dx[i]);
             }
             const t = [m[0]];
             for (let i = 1; i < n - 1; i++) t.push(m[i - 1] * m[i] <= 0 ? 0 : (m[i - 1] + m[i]) / 2);
             t.push(m[n - 2]);
             for (let i = 0; i < n - 1; i++) {
                 if (m[i] === 0) { t[i] = 0; t[i + 1] = 0; continue; }
                 const a = t[i] / m[i], b = t[i + 1] / m[i], h = a * a + b * b;
                 if (h > 9) { const k = 3 / Math.sqrt(h); t[i] = k * a * m[i]; t[i + 1] = k * b * m[i]; }
             }
             let d = 'M ' + pts[0].x + ' ' + pts[0].y;
             for (let i = 0; i < n - 1; i++) {
                 const h = dx[i] / 3;
                 d += ' C ' + (pts[i].x + h) + ' ' + (pts[i].y + t[i] * h) + ', '
                     + (pts[i + 1].x - h) + ' ' + (pts[i + 1].y - t[i + 1] * h) + ', '
                     + pts[i + 1].x + ' ' + pts[i + 1].y;
             }
             return d;
         },
         areaPath(pts) {
             if (!pts.length) return '';
             const first = pts.length === 1 ? 0 : pts[0].x;
             const last = pts.length === 1 ? 100 : pts[pts.length - 1].x;
             return this.linePath(pts) + ' L ' + last + ' 100 L ' + first + ' 100 Z';
         },
         chartSummary(metric, title) {
             return title + ': ' + this.batches.map(b => b.label + ' ' + (metric === 'revenue' ? this.formatCurrency(b.revenue) : this.whole(b[metric]))).join(', ');
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
        <div class="banner banner-warning">
            <strong>No forecast yet.</strong>
            Expected numbers appear here once the forecast has been generated for scheduled batches.
            <span class="block text-xs text-[#6E6E73] mt-1">For the tech team: run <code class="font-mono">python retrain_pipeline.py</code> in the <code class="font-mono">demand-forecast</code> folder.</span>
        </div>
    @endif

    @if(!$rules['rules_loaded'])
        <div class="banner banner-error">
            <strong>Demand labels are using default settings.</strong>
            <span class="block text-xs text-[#6E6E73] mt-1">For the tech team: <code class="font-mono">demand-forecast/demand_thresholds.json</code> was not found.</span>
        </div>
    @endif

    @if(!empty($batchForecasts) && ($validated === false || $basis === 'limited_history'))
        <div class="banner banner-warning flex gap-3">
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

    <!-- Per-batch charts: smooth filled line charts -->
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
            <div class="bg-white rounded-xl border border-[#E5E5EA] p-4 sm:p-5 shadow-2xs space-y-4" x-data="{ hover: null }">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                    <div>
                        <h3 class="text-sm sm:text-base font-extrabold text-[#1D1D1F]">{{ $chart['title'] }}</h3>
                        <p class="text-xs sm:text-sm text-[#6E6E73] mt-0.5">{{ $chart['desc'] }}</p>
                    </div>
                    <div class="flex items-center gap-3 text-xs sm:text-sm shrink-0 self-start sm:self-auto">
                        @if($chart['booked'])
                            <div class="flex items-center gap-1.5">
                                <span class="w-4 border-t-2 border-dashed border-[#780000]"></span>
                                <span class="font-bold text-[#1D1D1F]">Booked so far</span>
                            </div>
                        @endif
                        <div class="flex items-center gap-1.5">
                            <span class="w-4 h-0.5 rounded-full bg-[#00C3D0]"></span>
                            <span class="font-bold text-[#1D1D1F]">Expected</span>
                        </div>
                    </div>
                </div>

                <template x-if="batches.length === 0">
                    <div class="h-52 flex items-center justify-center text-sm text-[#8E8E93] rounded-lg bg-[#F8F9FA]">No batches scheduled in this period yet.</div>
                </template>

                <template x-if="batches.length > 0">
                    <div>
                        <div class="relative h-52" role="img" :aria-label="chartSummary('{{ $m }}', '{{ $chart['title'] }}')" @mouseleave="hover = null">
                            <!-- Grid lines -->
                            <div class="absolute inset-0 flex flex-col justify-between pointer-events-none" aria-hidden="true">
                                <div class="border-b border-dashed border-[#E5E5EA]"></div>
                                <div class="border-b border-dashed border-[#E5E5EA]"></div>
                                <div class="border-b border-dashed border-[#E5E5EA]"></div>
                                <div class="border-b border-[#E5E5EA]"></div>
                            </div>

                            <!-- Area + lines -->
                            <svg class="absolute inset-0 w-full h-full overflow-visible" viewBox="0 0 100 100" preserveAspectRatio="none" aria-hidden="true">
                                <defs>
                                    <linearGradient id="area-{{ $m }}" x1="0" y1="0" x2="0" y2="1">
                                        <stop offset="0%" stop-color="#00C3D0" stop-opacity="0.28"/>
                                        <stop offset="100%" stop-color="#00C3D0" stop-opacity="0.02"/>
                                    </linearGradient>
                                </defs>
                                <path :d="areaPath(chartPoints('{{ $m }}', '{{ $m }}'))" fill="url(#area-{{ $m }})"/>
                                <path :d="linePath(chartPoints('{{ $m }}', '{{ $m }}'))" fill="none" stroke="#00C3D0" stroke-width="2.5" vector-effect="non-scaling-stroke" stroke-linecap="round" stroke-linejoin="round"/>
                                @if($chart['booked'])
                                    <path :d="linePath(chartPoints('booked', '{{ $m }}'))" fill="none" stroke="#780000" stroke-width="2" stroke-dasharray="6 5" vector-effect="non-scaling-stroke" stroke-linecap="round"/>
                                @endif
                            </svg>

                            <!-- Hovered column guide -->
                            <template x-if="hover !== null">
                                <div class="absolute top-0 bottom-0 border-l border-dashed border-[#00C3D0]/60 pointer-events-none" :style="'left: ' + chartPoints('{{ $m }}', '{{ $m }}')[hover].x + '%'"></div>
                            </template>

                            <!-- Points -->
                            <template x-for="(pt, i) in chartPoints('{{ $m }}', '{{ $m }}')" :key="'pt_{{ $m }}_' + i">
                                <span class="absolute w-3 h-3 -ml-1.5 -mt-1.5 rounded-full border-2 border-[#00C3D0] transition-transform pointer-events-none"
                                      :class="hover === i ? 'bg-[#00C3D0] scale-125' : 'bg-white'"
                                      :style="'left: ' + pt.x + '%; top: ' + pt.y + '%'"></span>
                            </template>
                            @if($chart['booked'])
                                <template x-for="(pt, i) in chartPoints('booked', '{{ $m }}')" :key="'bk_{{ $m }}_' + i">
                                    <span class="absolute w-2.5 h-2.5 -ml-[5px] -mt-[5px] rounded-full bg-[#780000] pointer-events-none"
                                          x-show="hover === i"
                                          :style="'left: ' + pt.x + '%; top: ' + pt.y + '%'"></span>
                                </template>
                            @endif

                            <!-- Hover zones (one per batch) -->
                            <template x-for="(b, i) in batches" :key="'zone_{{ $m }}_' + b.key">
                                <div class="absolute top-0 bottom-0 cursor-pointer"
                                     :style="'left: ' + (i * 100 / batches.length) + '%; width: ' + (100 / batches.length) + '%'"
                                     @mouseenter="hover = i" @focus="hover = i" @blur="hover = null" tabindex="0"
                                     :aria-label="b.date"></div>
                            </template>

                            <!-- Tooltip -->
                            <template x-if="hover !== null">
                                <div class="absolute z-30 pointer-events-none -translate-x-1/2 min-w-[180px] w-max max-w-[250px] p-2.5 sm:p-3 rounded-xl bg-[#1D1D1F] text-white shadow-xl space-y-1.5 text-left"
                                     :class="chartPoints('{{ $m }}', '{{ $m }}')[hover].y < 50 ? '' : '-translate-y-full'"
                                     :style="'left: ' + Math.min(85, Math.max(15, chartPoints('{{ $m }}', '{{ $m }}')[hover].x)) + '%; top: calc(' + chartPoints('{{ $m }}', '{{ $m }}')[hover].y + '% ' + (chartPoints('{{ $m }}', '{{ $m }}')[hover].y < 50 ? '+ 14px' : '- 14px') + ')'">
                                    <div class="flex items-center justify-between gap-2 pb-1 border-b border-white/10">
                                        <span class="font-bold text-xs text-sky-200" x-text="batches[hover].date"></span>
                                        <span class="text-[10px] uppercase px-1.5 py-0.5 rounded font-black tracking-wider bg-[#00C3D0] text-[#1D1D1F]">Expected</span>
                                    </div>
                                    @if($m === 'revenue')
                                        <div class="font-black text-xs sm:text-sm" x-text="formatCurrency(batches[hover].revenue)"></div>
                                    @else
                                        <div class="font-black text-xs sm:text-sm" x-text="whole(batches[hover].{{ $m }}) + ' {{ $chart['unit'][1] }}'"></div>
                                    @endif
                                    @if($chart['booked'])
                                        <div class="text-gray-300 text-xs flex items-center justify-between gap-2">
                                            <span>Booked so far</span>
                                            <span class="font-bold text-white" x-text="batches[hover].booked + ' of ' + batches[hover].capacity"></span>
                                        </div>
                                        <div class="text-gray-300 text-xs flex items-center justify-between gap-2" x-show="batches[hover].low !== null && batches[hover].high !== null">
                                            <span>Could be</span>
                                            <span class="font-bold text-white" x-text="whole(batches[hover].low) + ' to ' + whole(batches[hover].high) + ' divers'"></span>
                                        </div>
                                    @endif
                                    <div class="text-gray-300 text-xs flex items-center justify-between gap-2">
                                        <span>Demand</span>
                                        <span class="font-bold text-white" x-text="batches[hover].demand + ' demand'"></span>
                                    </div>
                                    <div class="text-gray-300 text-xs flex items-center justify-between gap-2">
                                        <span>Starts in</span>
                                        <span class="font-bold text-white" x-text="plural(batches[hover].days_to_start, 'day', 'days')"></span>
                                    </div>
                                </div>
                            </template>
                        </div>

                        <!-- X-axis labels -->
                        <div class="relative h-6 mt-2">
                            <template x-for="(b, i) in batches" :key="'label_{{ $m }}_' + b.key">
                                <span class="absolute -translate-x-1/2 text-xs sm:text-sm font-bold whitespace-nowrap transition-colors"
                                      :class="hover === i ? 'text-[#780000]' : 'text-[#1D1D1F]'"
                                      :style="'left: ' + chartPoints('{{ $m }}', '{{ $m }}')[i].x + '%'"
                                      :title="b.date + ' · ' + b.code"
                                      x-text="b.label"></span>
                            </template>
                        </div>
                    </div>
                </template>
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
