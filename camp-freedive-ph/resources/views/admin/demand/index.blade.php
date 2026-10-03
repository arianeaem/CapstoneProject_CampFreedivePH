@extends('layouts.admin')

@section('title', 'Demand Forecast | Camp FreedivePH')

@section('content')
<div class="space-y-6 text-sm">

    <div>
        <h1 class="text-2xl sm:text-3xl font-extrabold text-[#1D1D1F] tracking-tight">Demand Forecast</h1>
        <p class="text-[#6E6E73] mt-1">
            Expected bookings and participants per month, predicted by the ML model from the
            553 actual registration records. Forecast values are kept separate from actual bookings.
        </p>
    </div>

    @if(!$hasForecast)
        <div class="rounded-xl border border-[#FF8D28]/40 bg-[#FFF9F2] p-4 text-[#1D1D1F]">
            <strong>No ML forecast has been generated yet.</strong>
            Nothing is shown here until the model has run, so no estimated numbers can be mistaken for real ones.
            Run <code class="font-mono text-xs">python retrain_pipeline.py</code> in the <code class="font-mono text-xs">demand-forecast</code> folder.
        </div>
    @endif

    @if(!$rules['rules_loaded'])
        <div class="rounded-xl border border-[#D70015]/30 bg-[#FFF5F5] p-4 text-[#1D1D1F]">
            <strong>Demand rules file not found.</strong>
            Using built-in defaults. Expected <code class="font-mono text-xs">demand-forecast/demand_thresholds.json</code>.
        </div>
    @endif

    <!-- The one set of rules used by forecast, pricing and batch planning -->
    <div class="rounded-xl border border-[#E5E5EA] bg-white p-5 space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="font-extrabold text-base text-[#1D1D1F]">Demand &amp; Season Rules (single source)</h2>
            <span class="text-xs text-[#6E6E73]">
                From {{ $rules['batches_used'] ?? '—' }} actual batches
                @if(!empty($rules['generated_at'])) &middot; updated {{ $rules['generated_at'] }} @endif
            </span>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
            <div class="rounded-lg bg-[#F2F2F7] p-3">
                <div class="text-xs font-bold uppercase tracking-wider text-[#6E6E73]">Low demand</div>
                <div class="font-extrabold text-[#1D1D1F]">&le; {{ rtrim(rtrim(number_format($rules['low_max'], 1), '0'), '.') }} participants / batch</div>
            </div>
            <div class="rounded-lg bg-[#F2F2F7] p-3">
                <div class="text-xs font-bold uppercase tracking-wider text-[#6E6E73]">Medium demand</div>
                <div class="font-extrabold text-[#1D1D1F]">
                    {{ rtrim(rtrim(number_format($rules['low_max'], 1), '0'), '.') }} &ndash; {{ rtrim(rtrim(number_format($rules['medium_max'], 1), '0'), '.') }} participants / batch
                </div>
            </div>
            <div class="rounded-lg bg-[#F2F2F7] p-3">
                <div class="text-xs font-bold uppercase tracking-wider text-[#6E6E73]">High demand</div>
                <div class="font-extrabold text-[#1D1D1F]">&gt; {{ rtrim(rtrim(number_format($rules['medium_max'], 1), '0'), '.') }} participants / batch</div>
            </div>
        </div>

        <div>
            <div class="text-xs font-bold uppercase tracking-wider text-[#6E6E73] mb-2">Season by calendar month (from actual history)</div>
            <div class="grid grid-cols-3 sm:grid-cols-6 lg:grid-cols-12 gap-2">
                @foreach(range(1, 12) as $m)
                    @php
                        $season = $rules['season_by_month'][(string) $m] ?? 'Shoulder';
                        $tone = match($season) {
                            'Peak' => 'bg-[#780000]/10 text-[#780000]',
                            'Off-Peak' => 'bg-[#E5E5EA] text-[#3A3A3C]',
                            default => 'bg-[#FF8D28]/15 text-[#B35900]',
                        };
                    @endphp
                    <div class="rounded-lg px-2 py-2 text-center {{ $tone }}">
                        <div class="text-[11px] font-bold uppercase">{{ date('M', mktime(0, 0, 0, $m, 1)) }}</div>
                        <div class="text-xs font-extrabold">{{ $season }}</div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    @php
        $lvlTone = fn ($l) => match($l) {
            'High' => 'bg-[#780000]/10 text-[#780000]',
            'Low' => 'bg-[#E5E5EA] text-[#3A3A3C]',
            default => 'bg-[#FF8D28]/15 text-[#B35900]',
        };
        $fmt = fn ($n) => rtrim(rtrim(number_format((float) $n, 1), '0'), '.');
        $basis = $modelInfo['data_basis'] ?? null;
        $cmp = $modelInfo['baseline_comparison'] ?? null;
        $validated = $cmp['model_validated'] ?? null;
    @endphp

    <!-- Model status -->
    @if(!empty($batchForecasts) || $modelInfo['model_version'])
        <div class="rounded-xl border border-[#E5E5EA] bg-white p-5 space-y-3">
            <div class="flex flex-wrap items-center gap-2">
                <h2 class="font-extrabold text-base text-[#1D1D1F]">Model status</h2>
                @if($basis === 'limited_history')
                    <span class="rounded-full bg-[#FF8D28]/15 text-[#B35900] text-xs font-bold px-2.5 py-1">Limited history &middot; lower confidence</span>
                @elseif($basis === 'real_history')
                    <span class="rounded-full bg-[#E8F5E9] text-[#1B5E20] text-xs font-bold px-2.5 py-1">Real history</span>
                @endif
                @if($validated === false)
                    <span class="rounded-full bg-[#D70015]/10 text-[#D70015] text-xs font-bold px-2.5 py-1">Not better than simple baselines</span>
                @elseif($validated === true)
                    <span class="rounded-full bg-[#E8F5E9] text-[#1B5E20] text-xs font-bold px-2.5 py-1">Beats baselines</span>
                @endif
            </div>
            <div class="text-xs text-[#6E6E73]">
                Version <strong class="text-[#1D1D1F]">{{ $modelInfo['model_version'] ?? '—' }}</strong>
                &middot; generated {{ $modelInfo['generated_at'] ?? '—' }}
                &middot; trained on {{ $modelInfo['training_batches'] ?? '—' }} actual batches
            </div>

            @if($cmp)
                <div class="overflow-x-auto">
                    <table class="w-full text-xs sm:text-sm">
                        <thead>
                            <tr class="text-left text-[#6E6E73] uppercase tracking-wider text-[11px]">
                                <th class="py-1.5 pr-4">Average error (MAE, lower is better)</th>
                                <th class="py-1.5 pr-4">ML model</th>
                                <th class="py-1.5 pr-4">Same as previous batch</th>
                                <th class="py-1.5 pr-4">Usual for that month</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach(['participant_count' => 'Participants per batch', 'booking_count' => 'Bookings per batch'] as $key => $label)
                                @if(isset($cmp[$key]))
                                    <tr class="border-t border-[#E5E5EA]">
                                        <td class="py-1.5 pr-4 font-semibold text-[#1D1D1F]">{{ $label }}</td>
                                        <td class="py-1.5 pr-4">{{ $cmp[$key]['model']['MAE'] }}</td>
                                        <td class="py-1.5 pr-4">{{ $cmp[$key]['naive']['MAE'] }}</td>
                                        <td class="py-1.5 pr-4">{{ $cmp[$key]['seasonal_naive']['MAE'] }}</td>
                                    </tr>
                                @endif
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <p class="text-xs text-[#6E6E73]">
                    Tested on the {{ $cmp['test_batches'] ?? '—' }} most recent actual batches.
                    @if($validated === false)
                        The model is not yet more accurate than simple averages, so treat these forecasts as a guide only.
                        More actual batches will improve this.
                    @endif
                </p>
            @endif
        </div>
    @endif

    <!-- Per-batch forecast -->
    <div class="rounded-xl border border-[#E5E5EA] bg-white p-5 space-y-3">
        <div>
            <h2 class="font-extrabold text-base text-[#1D1D1F]">Forecast per scheduled batch</h2>
            <p class="text-xs text-[#6E6E73]">One forecast for each batch that is actually scheduled. Predicted values are ML forecasts, not actual bookings.</p>
        </div>

        @if(empty($batchForecasts))
            <p class="text-sm text-[#6E6E73]">
                No per-batch forecast yet. Schedule a batch (with at least one booking), then run
                <code class="font-mono text-xs">python retrain_pipeline.py</code> in the <code class="font-mono text-xs">demand-forecast</code> folder.
            </p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-xs sm:text-sm">
                    <thead>
                        <tr class="text-left text-[#6E6E73] uppercase tracking-wider text-[11px]">
                            <th class="py-1.5 pr-4">Batch</th>
                            <th class="py-1.5 pr-4">Date</th>
                            <th class="py-1.5 pr-4">Days to start</th>
                            <th class="py-1.5 pr-4">Booked so far</th>
                            <th class="py-1.5 pr-4">Predicted participants</th>
                            <th class="py-1.5 pr-4">Predicted bookings</th>
                            <th class="py-1.5 pr-4">Fill rate</th>
                            <th class="py-1.5 pr-4">Demand</th>
                            <th class="py-1.5 pr-4">Season</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($batchForecasts as $b)
                            <tr class="border-t border-[#E5E5EA]">
                                <td class="py-2 pr-4 font-semibold text-[#1D1D1F]">{{ $b['batch_code'] ?? '—' }}</td>
                                <td class="py-2 pr-4">{{ \Carbon\Carbon::parse($b['batch_date'])->format('M d, Y') }}</td>
                                <td class="py-2 pr-4">{{ $b['days_to_start'] }}</td>
                                <td class="py-2 pr-4">{{ $b['booked_so_far'] }} / {{ $b['capacity'] }}</td>
                                <td class="py-2 pr-4">
                                    <strong class="text-[#1D1D1F]">{{ $fmt($b['predicted_participants']) }}</strong>
                                    @if($b['lower_bound'] !== null && $b['upper_bound'] !== null)
                                        <span class="text-[#6E6E73]">({{ $fmt($b['lower_bound']) }}&ndash;{{ $fmt($b['upper_bound']) }})</span>
                                    @endif
                                </td>
                                <td class="py-2 pr-4">{{ $fmt($b['predicted_bookings']) }}</td>
                                <td class="py-2 pr-4">{{ $b['predicted_fill_rate'] !== null ? round($b['predicted_fill_rate'] * 100) . '%' : '—' }}</td>
                                <td class="py-2 pr-4"><span class="rounded-full px-2 py-0.5 text-xs font-bold {{ $lvlTone($b['demand_level']) }}">{{ $b['demand_level'] }}</span></td>
                                <td class="py-2 pr-4">{{ $b['season_period'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="text-xs text-[#6E6E73]">Range in brackets is an 80% prediction interval. A forecast is never lower than the participants already booked.</p>
        @endif
    </div>

    <!-- Monthly rollup of the per-batch forecasts -->
    @if(!empty($batchMonthly))
        <div class="rounded-xl border border-[#E5E5EA] bg-white p-5 space-y-3">
            <div>
                <h2 class="font-extrabold text-base text-[#1D1D1F]">Forecast per month</h2>
                <p class="text-xs text-[#6E6E73]">Totals are the sum of the per-batch forecasts above.</p>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-xs sm:text-sm">
                    <thead>
                        <tr class="text-left text-[#6E6E73] uppercase tracking-wider text-[11px]">
                            <th class="py-1.5 pr-4">Month</th>
                            <th class="py-1.5 pr-4">Batches</th>
                            <th class="py-1.5 pr-4">Total participants</th>
                            <th class="py-1.5 pr-4">Total bookings</th>
                            <th class="py-1.5 pr-4">Avg participants / batch</th>
                            <th class="py-1.5 pr-4">Demand</th>
                            <th class="py-1.5 pr-4">Season</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($batchMonthly as $m)
                            <tr class="border-t border-[#E5E5EA]">
                                <td class="py-2 pr-4 font-semibold text-[#1D1D1F]">{{ $m['month_label'] }}</td>
                                <td class="py-2 pr-4">{{ $m['batches'] }}</td>
                                <td class="py-2 pr-4">{{ $fmt($m['predicted_participants']) }}</td>
                                <td class="py-2 pr-4">{{ $fmt($m['predicted_bookings']) }}</td>
                                <td class="py-2 pr-4">{{ $fmt($m['avg_participants_per_batch']) }}</td>
                                <td class="py-2 pr-4"><span class="rounded-full px-2 py-0.5 text-xs font-bold {{ $lvlTone($m['demand_level']) }}">{{ $m['demand_level'] }}</span></td>
                                <td class="py-2 pr-4">{{ $m['season_period'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

</div>
@endsection
