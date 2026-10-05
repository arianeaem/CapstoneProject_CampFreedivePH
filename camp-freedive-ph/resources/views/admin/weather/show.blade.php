@extends('layouts.admin')

@section('title', $batch->display_name . ' - Weather Risk Assessment | Camp FreedivePH')

@section('breadcrumb')
    <a href="{{ portal_route('weather.index') }}" class="text-[#6E6E73] hover:text-[#780000] font-medium transition-colors">Safety Monitoring</a>
    <svg class="w-3.5 h-3.5 text-[#8E8E93] shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m9 18 6-6-6-6"/></svg>
    <span class="font-bold text-[#1D1D1F]">{{ $batch->batch_number }}</span>
@endsection

@section('content')
<div class="space-y-6 text-sm" x-data="{
    openOverrideModal: false,
    openCancelModal: false,
    openActionsMenu: false,
    cancelReason: '{{ $overallClassification === 'Critical Risk' ? 'Critical Risk sea conditions (strong wind, big waves or currents)' : '' }}'
}">
    
    <!-- Top Header Bar -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <div class="flex items-center gap-2">
                <h1 class="text-2xl sm:text-3xl font-extrabold text-[#1D1D1F] tracking-tight">
                    {{ $batch->batch_number }}
                </h1>
                @if($batch->status === 'cancelled_by_camp')
                    <span class="px-2 py-0.5 rounded-md text-xs font-bold bg-[#FEF2F2] text-[#DC2626]">
                        Cancelled by Camp
                    </span>
                @endif
            </div>
            
            <div class="mt-1 text-sm text-[#6E6E73] space-y-1">
                <div class="flex items-center gap-1 font-medium text-[#1D1D1F]">
                    <span>{{ $batch->start_date->format('F d, Y (l)') }} - {{ $batch->end_date->format('F d, Y (l)') }}</span>
                </div>
            </div>
        </div>

        <!-- Action Controls: Primary Button + 3-Dot More Actions Menu -->
        <div class="flex items-center gap-2.5 flex-wrap">
            @php
                $cbState = $circuitStatus['state'] ?? 'CLOSED';
                $isCbOpen = ($cbState === 'OPEN');
                $isCbHalfOpen = ($cbState === 'HALF_OPEN');
                $isPrimaryActive = ($isMLReachable && !$isCbOpen);
                $isConcluded = ($batch->end_date && $batch->end_date->isPast()) || in_array($batch->status, ['completed', 'cancelled_by_camp']);
            @endphp

            @if($isConcluded)
                <div class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-slate-200 bg-slate-100 text-slate-700 text-xs font-bold shadow-2xs">
                    <span class="w-2 h-2 rounded-full bg-slate-500"></span>
                    <span>Concluded Session · Archived Records</span>
                </div>
            @endif

            <!-- Primary Action: Run Live Assessment -->
            @if(!$isConcluded)
                <form action="{{ route('admin.weather.assess', $batch) }}" method="POST" class="inline">
                    @csrf
                    <button type="submit" class="btn-primary min-h-[44px] px-4 py-2.5 text-sm font-bold shadow-2xs inline-flex items-center justify-center gap-2 active:scale-[0.99] transition-all focus:outline-none focus:ring-2 focus:ring-[#780000]">
                        <svg class="w-4 h-4 text-white shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="23 4 23 10 17 10"></polyline><polyline points="1 20 1 14 7 14"></polyline><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"></path></svg>
                        <span>Run Live Assessment</span>
                    </button>
                </form>
            @endif

            <!-- 3-Dot More Actions Menu (Secondary & Destructive Actions) -->
            <div class="relative inline-block text-left" @click.outside="openActionsMenu = false">
                <button type="button" 
                        @click="openActionsMenu = !openActionsMenu"
                        :aria-expanded="openActionsMenu"
                        aria-haspopup="true"
                        aria-label="More batch safety actions"
                        class="min-h-[44px] min-w-[44px] w-11 h-11 inline-flex items-center justify-center rounded-xl bg-white border border-[#E5E5EA] text-[#1D1D1F] hover:bg-[#F2F2F7] hover:border-[#D1D1D6] active:scale-[0.97] transition-all focus:outline-none focus:ring-2 focus:ring-[#780000] cursor-pointer shadow-2xs">
                    <svg class="w-5 h-5 text-[#1D1D1F]" viewBox="0 0 24 24" fill="currentColor">
                        <circle cx="12" cy="12" r="1.75"/>
                        <circle cx="19" cy="12" r="1.75"/>
                        <circle cx="5" cy="12" r="1.75"/>
                    </svg>
                </button>

                <!-- Contextual Menu Dropdown -->
                <div x-show="openActionsMenu" 
                     x-cloak
                     x-transition:enter="transition ease-out duration-100"
                     x-transition:enter-start="transform opacity-0 scale-95"
                     x-transition:enter-end="transform opacity-100 scale-100"
                     x-transition:leave="transition ease-in duration-75"
                     x-transition:leave-start="transform opacity-100 scale-100"
                     x-transition:leave-end="transform opacity-0 scale-95"
                     class="absolute right-0 mt-2 w-60 rounded-2xl bg-white shadow-xl border border-[#E5E5EA] p-1.5 z-50 focus:outline-none text-sm">
                    
                    <!-- 1. Manual PAGASA Override -->
                    @if(!$isConcluded)
                        <button type="button"
                                @click="openActionsMenu = false; openOverrideModal = true"
                                class="w-full min-h-[40px] px-3 py-2 text-sm font-semibold text-[#1D1D1F] hover:bg-[#F2F2F7] rounded-xl flex items-center gap-2.5 transition-colors cursor-pointer text-left">
                            <span>Apply Manual Override</span>
                        </button>
                    @endif

                    <!-- 2. View Batch Profile -->
                    <a href="{{ route('admin.batches.show', $batch) }}" 
                       class="w-full min-h-[40px] px-3 py-2 text-sm font-semibold text-[#1D1D1F] hover:bg-[#F2F2F7] rounded-xl flex items-center gap-2.5 transition-colors cursor-pointer text-left">
                        <span>View Batch Profile</span>
                    </a>

                    <!-- 3. Destructive: Cancel Batch Trigger -->
                    @if($batch->status !== 'cancelled_by_camp' && !$isConcluded)
                        <div class="h-px bg-[#F2F2F7] my-1"></div>

                        <button type="button"
                                @click="openActionsMenu = false; openCancelModal = true"
                                class="w-full min-h-[40px] px-3 py-2 text-sm font-semibold text-rose-700 hover:bg-rose-50 rounded-xl flex items-center gap-2.5 transition-colors cursor-pointer text-left">
                            <span>Cancel Batch (Weather Risk)</span>
                        </button>
                    @endif
                </div>
            </div>

        </div>
    </div>

    <!-- Main Safety Assessment Summary Banner -->
    @php
        $mlRec = $batchMLAssessment['overall_recommendation'] ?? ($overallClassification ?? 'Safe');
        $displayVerdict = ($isPrimaryActive || $isConcluded) ? $mlRec : $overallClassification;
        $verdictBadgeClass = match($displayVerdict) {
            'Very Safe', 'Safe' => 'bg-emerald-600 text-white',
            'Moderate' => 'bg-amber-500 text-white',
            'High Risk' => 'bg-rose-600 text-white',
            'Critical Risk' => 'bg-red-700 text-white',
            default => 'bg-gray-600 text-white',
        };
        $verdictScore = match($displayVerdict) {
            'Very Safe' => 5,
            'Safe' => 4,
            'Moderate' => 3,
            'High Risk' => 2,
            'Critical Risk' => 1,
            default => 4,
        };
        $verdictBarColor = match($displayVerdict) {
            'Very Safe', 'Safe' => 'bg-emerald-500',
            'Moderate' => 'bg-amber-500',
            'High Risk' => 'bg-rose-500',
            'Critical Risk' => 'bg-red-600',
            default => 'bg-emerald-500',
        };
    @endphp
    <div class="bg-white rounded-xl border border-[#E5E5EA] p-6 sm:p-7 space-y-4 shadow-2xs">
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
            <div class="space-y-2">
                <span class="text-xs font-extrabold uppercase tracking-widest text-[#6E6E73] block">
                    Overall Batch Assessment
                </span>
                <div class="flex items-center gap-3 flex-wrap">
                    <span class="inline-flex items-center px-4 py-1.5 rounded-full text-base font-black tracking-wide uppercase {{ $verdictBadgeClass }}">
                        {{ $displayVerdict }}
                    </span>

                    <!-- 5-Bar Visual Score Gauge -->
                    <div class="flex items-center gap-1 sm:gap-1.5">
                        @for($i = 1; $i <= 5; $i++)
                            <div class="h-2 w-5 sm:w-6 rounded-full transition-all duration-300 {{ $i <= $verdictScore ? ['bg-[#EF4444]', 'bg-[#F97316]', 'bg-[#F59E0B]', 'bg-[#84CC16]', 'bg-[#10B981]'][$i - 1] : 'bg-[#E5E5EA]' }}"></div>
                        @endfor
                    </div>
                </div>
            </div>

            <!-- Override Status -->
            <div class="space-y-1 md:text-right shrink-0">
                <span class="text-[10px] uppercase font-extrabold tracking-wider text-[#6E6E73] block">Override Advisory Status</span>
                @if($latestOverride && count($latestOverride->active_advisories) > 0)
                    <span class="text-xs font-bold text-white bg-rose-600 px-3 py-1 rounded-full inline-block shadow-2xs">
                        Active: {{ implode(', ', $latestOverride->active_advisories) }}
                    </span>
                @else
                    <span class="text-xs font-bold text-[#065F46] bg-[#ECFDF5] px-3 py-1 rounded-full inline-block">
                        NOT OVERRIDDEN
                    </span>
                @endif
            </div>
        </div>

        <!-- Recommendation Text -->
        <div>
            <h3 class="text-sm font-bold text-[#1D1D1F]">
                {{ \App\Services\WeatherForecastService::MEANING_MAP[$displayVerdict] ?? 'Proceed with standard camp freediving protocols.' }}
            </h3>
        </div>

    </div>

    <!-- Model Comparison: Historical Model vs Legacy Model -->
    @if(!$isConcluded)
    @php
        $segColors = ['bg-[#EF4444]', 'bg-[#F97316]', 'bg-[#F59E0B]', 'bg-[#84CC16]', 'bg-[#10B981]'];
        $scoreOf = fn ($c) => match ($c) { 'Very Safe' => 5, 'Safe' => 4, 'Moderate' => 3, 'High Risk' => 2, 'Critical Risk' => 1, default => 0 };
        $toneText = fn ($c, $seasonal = false) => $seasonal ? 'text-[#3A3A3C]' : match ($c) {
            'Very Safe', 'Safe' => 'text-[#047857]',
            'Moderate' => 'text-[#B45309]',
            'High Risk', 'Critical Risk' => 'text-[#B91C1C]',
            default => 'text-[#3A3A3C]',
        };
        $histModel = $modelComparison['historical'] ?? null;
        $legacyModel = $modelComparison['legacy'] ?? null;
        $models = [
            ['name' => 'Historical Model', 'about' => 'Estimates conditions from past years of weather at the dive site.', 'data' => $histModel],
            ['name' => 'Legacy Model', 'about' => "Uses this week's live weather forecast, then checks it with our safety model.", 'data' => $legacyModel],
        ];
        $modelNote = function (?array $m) {
            if (empty($m['available'])) return 'No result for these dates yet.';
            if (!empty($m['is_seasonal_estimate'])) return 'Showing typical conditions for this time of year — live data is not available yet.';
            if (str_contains($m['data_source'] ?? '', 'safety model unavailable')) return 'Safety model is offline, so the live forecast was checked with standard safety rules instead.';
            return null;
        };
        $bothAvailable = !empty($histModel['available']) && !empty($legacyModel['available']);
        $modelsAgree = $bothAvailable
            && ($histModel['day1']['classification'] ?? null) === ($legacyModel['day1']['classification'] ?? null)
            && ($histModel['day2']['classification'] ?? null) === ($legacyModel['day2']['classification'] ?? null);
    @endphp
    <div class="bg-white rounded-xl border border-[#E5E5EA] p-5 sm:p-6 space-y-4 shadow-2xs">
        <div class="flex flex-col sm:flex-row sm:items-start justify-between gap-3">
            <div>
                <h2 class="text-base font-extrabold text-[#1D1D1F]">Model Comparison</h2>
                <p class="text-xs text-[#6E6E73] mt-0.5">Two forecasts checked the same dates. Use them together to judge the trip.</p>
            </div>
            @if($modelComparison)
                <span class="inline-flex items-center gap-1.5 self-start px-3 py-1 rounded-full text-xs font-bold {{ $modelsAgree ? 'bg-[#ECFDF5] text-[#065F46]' : ($bothAvailable ? 'bg-[#FFFBEB] text-[#92400E]' : 'bg-[#F2F2F7] text-[#6E6E73]') }}">
                    <span class="w-1.5 h-1.5 rounded-full {{ $modelsAgree ? 'bg-[#10B981]' : ($bothAvailable ? 'bg-[#F59E0B]' : 'bg-[#AEAEB2]') }}"></span>
                    {{ $modelsAgree ? 'Both models agree' : ($bothAvailable ? 'Models give different ratings' : 'Only one model available') }}
                </span>
            @endif
        </div>

        @if($modelComparison)
            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                @foreach($models as $model)
                    @php
                        $m = $model['data'];
                        $available = !empty($m['available']);
                        $seasonal = !empty($m['is_seasonal_estimate']);
                        $overall = $m['overall_classification'] ?? null;
                        $note = $modelNote($m);
                    @endphp
                    <div class="rounded-xl border border-[#E5E5EA] p-4 space-y-3">
                        <div>
                            <div class="text-sm font-extrabold text-[#1D1D1F]">{{ $model['name'] }}</div>
                            <p class="text-xs text-[#6E6E73] leading-snug mt-0.5">{{ $model['about'] }}</p>
                        </div>

                        @if($available)
                            <div class="flex items-center justify-between gap-3 flex-wrap">
                                <span class="text-lg font-black {{ $toneText($overall, $seasonal) }}">
                                    {{ $overall }}@if($seasonal)<span class="text-xs font-semibold text-[#6E6E73]"> · typical season</span>@endif
                                </span>
                                @unless($seasonal)
                                    <div class="flex items-center gap-1" role="img" aria-label="Safety score {{ $scoreOf($overall) }} out of 5">
                                        @for($i = 1; $i <= 5; $i++)
                                            <div class="h-1.5 w-5 rounded-full {{ $i <= $scoreOf($overall) ? $segColors[$i - 1] : 'bg-[#E5E5EA]' }}"></div>
                                        @endfor
                                    </div>
                                @endunless
                            </div>

                            <div class="rounded-lg bg-[#F8F9FA] divide-y divide-[#E5E5EA] text-xs">
                                @foreach(['day1' => 'Day 1', 'day2' => 'Day 2'] as $dayKey => $dayLabel)
                                    <div class="flex items-center justify-between gap-2 px-3 py-2">
                                        <span class="text-[#6E6E73]">
                                            <strong class="text-[#1D1D1F]">{{ $dayLabel }}</strong> · {{ $m[$dayKey]['date'] ?? '' }}
                                        </span>
                                        <span class="font-bold {{ $toneText($m[$dayKey]['classification'] ?? null, $seasonal) }}">{{ $m[$dayKey]['classification'] ?? 'N/A' }}</span>
                                    </div>
                                @endforeach
                            </div>
                        @endif

                        @if($note)
                            <p class="text-xs text-[#6E6E73] leading-snug">{{ $note }}</p>
                        @endif
                    </div>
                @endforeach
            </div>

            @if($bothAvailable && !$modelsAgree)
                <p class="text-xs text-[#92400E] bg-[#FFFBEB] border border-[#FDE68A] rounded-lg px-3 py-2">
                    The two models don't fully agree. Check the hourly conditions below before confirming or cancelling this trip.
                </p>
            @endif
        @else
            <p class="text-xs text-[#6E6E73]">The model comparison isn't available right now. Try “Run Live Assessment” again in a few minutes.</p>
        @endif
    </div>
    @endif

    <!-- Day 1 & Day 2 Comparative Marine Condition Panels -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        
        <!-- Day 1 Panel -->
        @php
            $hourlyD1 = !empty($day1MLAssessment['hourly_assessments']) ? $day1MLAssessment['hourly_assessments'] : ($day1Continuous24h['hourly'] ?? []);
            $recD1 = ($isPrimaryActive || $isConcluded) ? ($day1MLAssessment['overall_recommendation'] ?? ($day1Assessment->overall_classification ?? 'Safe')) : ($day1Assessment->overall_classification ?? 'Safe');
            $badgeD1 = match($recD1) {
                'Very Safe', 'Safe' => 'bg-emerald-600 text-white',
                'Moderate' => 'bg-amber-500 text-white',
                'High Risk' => 'bg-rose-600 text-white',
                'Critical Risk' => 'bg-red-700 text-white',
                default => 'bg-gray-600 text-white',
            };
            $hasFull24hD1 = count($hourlyD1) > 5;
        @endphp
        <div class="bg-white rounded-xl border border-[#E5E5EA] p-5 sm:p-6 space-y-4 shadow-2xs">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <span class="px-2.5 py-1 rounded-lg text-xs font-black bg-[#F8EAEA] text-[#780000] border border-[#F1D5D5]">
                        DAY 1
                    </span>
                    <h2 class="text-base font-extrabold text-[#1D1D1F]">
                        {{ $day1Assessment->dive_date->format('F d, Y (l)') }}
                    </h2>
                </div>

                <span class="px-3 py-1 rounded-full text-xs font-black uppercase {{ $badgeD1 }}">
                    {{ $recD1 }}
                </span>
            </div>

            <!-- Day 1 Quick Stats -->
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-2.5 text-xs">
                <div class="p-2.5 rounded-lg bg-[#F2F2F7]">
                    <span class="text-[#6E6E73] block uppercase font-bold">Roughest Hour</span>
                    <strong class="text-sm font-extrabold text-[#1D1D1F]">
                        {{ $day1Assessment->worst_hour ? $day1Assessment->worst_hour->format('g:i A') : 'N/A' }}
                    </strong>
                </div>

                <div class="p-2.5 rounded-lg bg-[#F2F2F7]">
                    <span class="text-[#6E6E73] block uppercase font-bold">Forecast Made</span>
                    <strong class="text-sm font-extrabold text-[#1D1D1F]">
                        @if($isConcluded)
                            Concluded
                        @else
                            @php $leadH = (int) round($day1Assessment->lead_time_hours ?? 0); @endphp
                            {{ $leadH >= 48 ? round($leadH / 24) . ' days' : $leadH . ' hours' }} before dive
                        @endif
                    </strong>
                </div>


                <div class="p-2.5 rounded-lg bg-[#F2F2F7]">
                    <span class="text-[#6E6E73] block uppercase font-bold">Reliability</span>
                    <strong class="text-xs font-bold text-[#1D1D1F]">
                        {{ $isConcluded ? 'Archived Record' : ($day1Assessment->reliability['label'] ?? 'High') }}
                    </strong>
                </div>
            </div>

            <!-- Day 1 Hourly Table -->
            @if(!empty($hourlyD1))
            <div x-data="{ showAllHours: false }" class="space-y-3">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                    @if($hasFull24hD1)
                    <button type="button" 
                            @click="showAllHours = !showAllHours" 
                            class="px-3 py-1.5 rounded-xl border border-[#D1D1D6] hover:border-[#780000] bg-white hover:bg-[#F2F2F7] text-xs font-bold text-[#1D1D1F] flex items-center gap-1.5 transition-all shadow-2xs cursor-pointer">
                        <span x-text="showAllHours ? 'Show dive hours only' : 'Show all 24 hours'"></span>
                        <svg class="w-3.5 h-3.5 transition-transform" :class="showAllHours ? 'rotate-180' : ''" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m6 9 6 6 6-6"/></svg>
                    </button>
                    @else
                    <span class="text-xs text-[#8E8E93] font-semibold bg-[#F2F2F7] px-2 py-0.5 rounded-md">
                        Dive hours (morning &amp; afternoon)
                    </span>
                    @endif
                </div>

                <div class="overflow-x-auto rounded-xl border border-[#E5E5EA]">
                    <table class="w-full text-left text-xs">
                        <thead class="bg-[#F2F2F7] border-b border-[#E5E5EA] uppercase font-extrabold text-[#6E6E73]">
                            <tr>
                                <th class="py-2.5 px-3 whitespace-nowrap">Time</th>
                                <th class="py-2.5 px-2 whitespace-nowrap" title="Average height of the waves">Wave Height</th>
                                <th class="py-2.5 px-2 whitespace-nowrap" title="Seconds between waves (longer = smoother)">Time Between Waves</th>
                                <th class="py-2.5 px-2 whitespace-nowrap" title="Height of rolling swells from far away">Swell</th>
                                <th class="py-2.5 px-2 whitespace-nowrap" title="How fast the water is moving">Current</th>
                                <th class="py-2.5 px-2 whitespace-nowrap" title="Expected rain">Rain</th>
                                <th class="py-2.5 px-2 whitespace-nowrap" title="Air pressure (a sudden drop can mean a storm)">Air Pressure</th>
                                <th class="py-2.5 px-3 whitespace-nowrap" title="Wind Speed & Direction">Wind Speed &amp; Gusts</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[#E5E5EA] bg-white">
                            @foreach($hourlyD1 as $h)
                            @php
                                $hourNum = (int) ($h['hour'] ?? 0);
                                $isAmHour = in_array($hourNum, [10, 11, 12]);
                                $isPmHour = in_array($hourNum, [16, 17]);
                                $rowRisk = $h['final_tier_name'] ?? $h['classification'] ?? 'Safe';
                                $rowLineColor = match($rowRisk) {
                                    'Very Safe', 'Safe' => 'bg-emerald-500',
                                    'Moderate' => 'bg-amber-500',
                                    'High Risk' => 'bg-rose-500',
                                    'Critical Risk' => 'bg-red-600',
                                    default => 'bg-gray-400',
                                };
                                $hs = (float) ($h['predicted_hs'] ?? $h['wave_height'] ?? 0.70);
                                $hsP10 = (float) ($h['hs_p10'] ?? max(0.05, round($hs - 0.15, 2)));
                                $hsP90 = (float) ($h['hs_p90'] ?? round($hs + 0.18, 2));
                                $tp = (float) ($h['predicted_tp'] ?? $h['wave_period'] ?? 6.1);
                                $swell = (float) ($h['predicted_swell_height'] ?? $h['swell_height'] ?? 0.50);
                                $current = (float) ($h['predicted_current_speed'] ?? $h['ocean_current'] ?? 0.30);
                                $rain = (float) ($h['rain_rate_mm_hr'] ?? $h['rain'] ?? 0.0);
                                $pressure = (float) ($h['slp'] ?? $h['sea_level_pressure'] ?? 1010.5);
                                $ws = (float) ($h['predicted_wind_speed'] ?? $h['wind_speed'] ?? 12.0);
                                $wg = (float) ($h['wind_gusts'] ?? $ws * 1.25);
                            @endphp
                            <tr x-show="showAllHours || {{ ($isAmHour || $isPmHour) ? 'true' : 'false' }}" 
                                class="hover:bg-[#F2F2F7] transition-colors {{ ($isAmHour || $isPmHour) ? 'bg-[#F8EAEA]/20 font-semibold' : '' }}">
                                <td class="py-2.5 px-3 whitespace-nowrap font-mono text-[#1D1D1F]">
                                    <div class="flex items-center gap-2">
                                        <span class="w-1.5 h-4.5 rounded-full {{ $rowLineColor }} shrink-0" title="Rating: {{ $rowRisk }}"></span>
                                        <div class="flex items-center gap-1.5">
                                            <span>{{ sprintf('%02d:00', $hourNum) }}</span>
                                            @if($isAmHour)
                                                <span class="text-[10px] px-1 py-0.2 rounded bg-[#780000] text-white font-extrabold uppercase tracking-wider">AM</span>
                                            @elseif($isPmHour)
                                                <span class="text-[10px] px-1 py-0.2 rounded bg-[#780000] text-white font-extrabold uppercase tracking-wider">PM</span>
                                            @endif
                                        </div>
                                    </div>
                                </td>
                                <td class="py-2 px-2 whitespace-nowrap font-medium text-[#1D1D1F]">
                                    <strong>{{ number_format($hs, 2) }}m</strong>
                                    <span class="text-[11px] text-[#8E8E93] block font-mono" title="Expected wave height range from lowest to highest">Between {{ number_format($hsP10, 2) }} – {{ number_format($hsP90, 2) }}m</span>
                                </td>
                                <td class="py-2 px-2 whitespace-nowrap font-medium text-[#1D1D1F]">{{ number_format($tp, 1) }}s</td>
                                <td class="py-2 px-2 whitespace-nowrap font-medium text-[#1D1D1F]">{{ number_format($swell, 2) }}m</td>
                                <td class="py-2 px-2 whitespace-nowrap font-medium text-[#1D1D1F]">{{ number_format($current, 2) }}m/s</td>
                                <td class="py-2 px-2 whitespace-nowrap font-medium text-[#1D1D1F]">{{ number_format($rain, 1) }}mm</td>
                                <td class="py-2 px-2 whitespace-nowrap font-medium text-[#1D1D1F]">{{ round($pressure) }}hPa</td>
                                <td class="py-2 px-3 whitespace-nowrap font-medium text-[#1D1D1F]">
                                    <span>{{ round($ws) }} km/h</span>
                                    <span class="text-xs text-[#8E8E93] block">Gusts: {{ round($wg) }} km/h</span>
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
            @else
            <p class="text-xs text-[#6E6E73] italic py-4 text-center">Hourly details are not available yet.</p>
            @endif
        </div>

        <!-- Day 2 Panel -->
        @php
            $hourlyD2 = !empty($day2MLAssessment['hourly_assessments']) ? $day2MLAssessment['hourly_assessments'] : ($day2Continuous24h['hourly'] ?? []);
            $recD2 = ($isPrimaryActive || $isConcluded) ? ($day2MLAssessment['overall_recommendation'] ?? ($day2Assessment->overall_classification ?? 'Safe')) : ($day2Assessment->overall_classification ?? 'Safe');
            $badgeD2 = match($recD2) {
                'Very Safe', 'Safe' => 'bg-emerald-600 text-white',
                'Moderate' => 'bg-amber-500 text-white',
                'High Risk' => 'bg-rose-600 text-white',
                'Critical Risk' => 'bg-red-700 text-white',
                default => 'bg-gray-600 text-white',
            };
            $hasFull24hD2 = count($hourlyD2) > 5;
        @endphp
        <div class="bg-white rounded-xl border border-[#E5E5EA] p-5 sm:p-6 space-y-4 shadow-2xs">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <span class="px-2.5 py-1 rounded-lg text-xs font-black bg-[#F8EAEA] text-[#780000] border border-[#F1D5D5]">
                        DAY 2
                    </span>
                    <h2 class="text-base font-extrabold text-[#1D1D1F]">
                        {{ $day2Assessment->dive_date->format('F d, Y (l)') }}
                    </h2>
                </div>

                <span class="px-3 py-1 rounded-full text-xs font-black uppercase {{ $badgeD2 }}">
                    {{ $recD2 }}
                </span>
            </div>

            <!-- Day 2 Quick Stats -->
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-2.5 text-xs">
                <div class="p-2.5 rounded-lg bg-[#F2F2F7]">
                    <span class="text-[#6E6E73] block uppercase font-bold">Roughest Hour</span>
                    <strong class="text-sm font-extrabold text-[#1D1D1F]">
                        {{ $day2Assessment->worst_hour ? $day2Assessment->worst_hour->format('g:i A') : 'N/A' }}
                    </strong>
                </div>

                <div class="p-2.5 rounded-lg bg-[#F2F2F7]">
                    <span class="text-[#6E6E73] block uppercase font-bold">Forecast Made</span>
                    <strong class="text-sm font-extrabold text-[#1D1D1F]">
                        @if($isConcluded)
                            Concluded
                        @else
                            @php $leadH = (int) round($day2Assessment->lead_time_hours ?? 0); @endphp
                            {{ $leadH >= 48 ? round($leadH / 24) . ' days' : $leadH . ' hours' }} before dive
                        @endif
                    </strong>
                </div>


                <div class="p-2.5 rounded-lg bg-[#F2F2F7]">
                    <span class="text-[#6E6E73] block uppercase font-bold">Reliability</span>
                    <strong class="text-xs font-bold text-[#1D1D1F]">
                        {{ $isConcluded ? 'Archived Record' : ($day2Assessment->reliability['label'] ?? 'High') }}
                    </strong>
                </div>
            </div>

            <!-- Day 2 Hourly Table -->
            @if(!empty($hourlyD2))
            <div x-data="{ showAllHours: false }" class="space-y-3">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                    @if($hasFull24hD2)
                    <button type="button" 
                            @click="showAllHours = !showAllHours" 
                            class="px-3 py-1.5 rounded-xl border border-[#D1D1D6] hover:border-[#780000] bg-white hover:bg-[#F2F2F7] text-xs font-bold text-[#1D1D1F] flex items-center gap-1.5 transition-all shadow-2xs cursor-pointer">
                        <span x-text="showAllHours ? 'Show dive hours only' : 'Show all 24 hours'"></span>
                        <svg class="w-3.5 h-3.5 transition-transform" :class="showAllHours ? 'rotate-180' : ''" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m6 9 6 6 6-6"/></svg>
                    </button>
                    @else
                    <span class="text-xs text-[#8E8E93] font-semibold bg-[#F2F2F7] px-2 py-0.5 rounded-md">
                        Dive hours (morning &amp; afternoon)
                    </span>
                    @endif
                </div>

                <div class="overflow-x-auto rounded-xl border border-[#E5E5EA]">
                    <table class="w-full text-left text-xs">
                        <thead class="bg-[#F2F2F7] border-b border-[#E5E5EA] uppercase font-extrabold text-[#6E6E73]">
                            <tr>
                                <th class="py-2.5 px-3 whitespace-nowrap">Time</th>
                                <th class="py-2.5 px-2 whitespace-nowrap" title="Average height of the waves">Wave Height</th>
                                <th class="py-2.5 px-2 whitespace-nowrap" title="Seconds between waves (longer = smoother)">Time Between Waves</th>
                                <th class="py-2.5 px-2 whitespace-nowrap" title="Height of rolling swells from far away">Swell</th>
                                <th class="py-2.5 px-2 whitespace-nowrap" title="How fast the water is moving">Current</th>
                                <th class="py-2.5 px-2 whitespace-nowrap" title="Expected rain">Rain</th>
                                <th class="py-2.5 px-2 whitespace-nowrap" title="Air pressure (a sudden drop can mean a storm)">Air Pressure</th>
                                <th class="py-2.5 px-3 whitespace-nowrap" title="Wind Speed & Direction">Wind Speed &amp; Gusts</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[#E5E5EA] bg-white">
                            @foreach($hourlyD2 as $h)
                            @php
                                $hourNum = (int) ($h['hour'] ?? 0);
                                $isAmHour = in_array($hourNum, [10, 11, 12]);
                                $isPmHour = in_array($hourNum, [16, 17]);
                                $rowRisk = $h['final_tier_name'] ?? $h['classification'] ?? 'Safe';
                                $rowLineColor = match($rowRisk) {
                                    'Very Safe', 'Safe' => 'bg-emerald-500',
                                    'Moderate' => 'bg-amber-500',
                                    'High Risk' => 'bg-rose-500',
                                    'Critical Risk' => 'bg-red-600',
                                    default => 'bg-gray-400',
                                };
                                $hs = (float) ($h['predicted_hs'] ?? $h['wave_height'] ?? 0.70);
                                $hsP10 = (float) ($h['hs_p10'] ?? max(0.05, round($hs - 0.15, 2)));
                                $hsP90 = (float) ($h['hs_p90'] ?? round($hs + 0.18, 2));
                                $tp = (float) ($h['predicted_tp'] ?? $h['wave_period'] ?? 6.1);
                                $swell = (float) ($h['predicted_swell_height'] ?? $h['swell_height'] ?? 0.50);
                                $current = (float) ($h['predicted_current_speed'] ?? $h['ocean_current'] ?? 0.30);
                                $rain = (float) ($h['rain_rate_mm_hr'] ?? $h['rain'] ?? 0.0);
                                $pressure = (float) ($h['slp'] ?? $h['sea_level_pressure'] ?? 1010.5);
                                $ws = (float) ($h['predicted_wind_speed'] ?? $h['wind_speed'] ?? 12.0);
                                $wg = (float) ($h['wind_gusts'] ?? $ws * 1.25);
                            @endphp
                            <tr x-show="showAllHours || {{ ($isAmHour || $isPmHour) ? 'true' : 'false' }}" 
                                class="hover:bg-[#F2F2F7] transition-colors {{ ($isAmHour || $isPmHour) ? 'bg-[#F8EAEA]/20 font-semibold' : '' }}">
                                <td class="py-2.5 px-3 whitespace-nowrap font-mono text-[#1D1D1F]">
                                    <div class="flex items-center gap-2">
                                        <span class="w-1.5 h-4.5 rounded-full {{ $rowLineColor }} shrink-0" title="Rating: {{ $rowRisk }}"></span>
                                        <div class="flex items-center gap-1.5">
                                            <span>{{ sprintf('%02d:00', $hourNum) }}</span>
                                            @if($isAmHour)
                                                <span class="text-[10px] px-1 py-0.2 rounded bg-[#780000] text-white font-extrabold uppercase tracking-wider">AM</span>
                                            @elseif($isPmHour)
                                                <span class="text-[10px] px-1 py-0.2 rounded bg-[#780000] text-white font-extrabold uppercase tracking-wider">PM</span>
                                            @endif
                                        </div>
                                    </div>
                                </td>
                                <td class="py-2 px-2 whitespace-nowrap font-medium text-[#1D1D1F]">
                                    <strong>{{ number_format($hs, 2) }}m</strong>
                                    <span class="text-[11px] text-[#8E8E93] block font-mono" title="Expected wave height range from lowest to highest">Between {{ number_format($hsP10, 2) }} – {{ number_format($hsP90, 2) }}m</span>
                                </td>
                                <td class="py-2 px-2 whitespace-nowrap font-medium text-[#1D1D1F]">{{ number_format($tp, 1) }}s</td>
                                <td class="py-2 px-2 whitespace-nowrap font-medium text-[#1D1D1F]">{{ number_format($swell, 2) }}m</td>
                                <td class="py-2 px-2 whitespace-nowrap font-medium text-[#1D1D1F]">{{ number_format($current, 2) }}m/s</td>
                                <td class="py-2 px-2 whitespace-nowrap font-medium text-[#1D1D1F]">{{ number_format($rain, 1) }}mm</td>
                                <td class="py-2 px-2 whitespace-nowrap font-medium text-[#1D1D1F]">{{ round($pressure) }}hPa</td>
                                <td class="py-2 px-3 whitespace-nowrap font-medium text-[#1D1D1F]">
                                    <span>{{ round($ws) }} km/h</span>
                                    <span class="text-xs text-[#8E8E93] block">Gusts: {{ round($wg) }} km/h</span>
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
            @else
            <p class="text-xs text-[#6E6E73] italic py-4 text-center">Hourly details are not available yet.</p>
            @endif
        </div>

    </div>

    <!-- Assessment History & Audit Trail -->
    <div x-data="{ openAuditTrail: false }" class="bg-white rounded-xl border border-[#E5E5EA] overflow-hidden shadow-2xs">
        <button type="button" 
                @click="openAuditTrail = !openAuditTrail" 
                class="w-full p-5 sm:p-6 flex items-center justify-between text-left hover:bg-[#F2F2F7] transition-colors cursor-pointer select-none">
            <div class="flex items-center gap-3">
                <span class="text-base font-extrabold text-[#1D1D1F]">Assessment History</span>
                <span class="px-2.5 py-0.5 rounded-full text-xs font-bold bg-[#F2F2F7] text-[#6E6E73]">
                    {{ count($assessmentRuns) }} run(s)
                </span>
            </div>
            <div class="flex items-center gap-2 text-xs font-bold text-[#780000]">
                <span x-text="openAuditTrail ? 'Hide History' : 'View Audit History'"></span>
                <svg class="w-4 h-4 transition-transform duration-200" :class="openAuditTrail ? 'rotate-180' : ''" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"></polyline></svg>
            </div>
        </button>

        <div x-show="openAuditTrail" x-cloak class="p-5 sm:p-6 pt-0 border-t border-[#E5E5EA] space-y-3">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between text-xs text-[#6E6E73] pt-4 pb-2 border-b border-[#E5E5EA] gap-2">
                <div class="flex items-center gap-2">
                    <span class="font-bold text-[#1D1D1F]">Checked with:</span>
                    <span class="inline-flex items-center gap-1.5 font-medium {{ $isPrimaryActive ? 'text-emerald-700' : 'text-blue-700' }}">
                        <span class="w-1.5 h-1.5 rounded-full {{ $isPrimaryActive ? 'bg-emerald-500' : 'bg-blue-500' }}"></span>
                        <span>
                            @if($isPrimaryActive)
                                Live forecast + safety model
                            @elseif($isCbOpen)
                                Standard safety rules (safety model offline)
                            @elseif($isCbHalfOpen)
                                Standard safety rules (safety model reconnecting)
                            @else
                                Standard safety rules
                            @endif
                        </span>
                    </span>
                </div>
                <span>Chronological assessment history</span>
            </div>

            @forelse($assessmentRuns as $timestamp => $records)
            @php
                $d1 = $records->firstWhere('day_number', 1);
                $d2 = $records->firstWhere('day_number', 2);
                $primary = $d1 ?: $d2;
                $runAssessor = ($d1 && $d1->assessor) ? $d1->assessor->name : (($d2 && $d2->assessor) ? $d2->assessor->name : 'Automatic check');
                $runTime = ($primary && $primary->assessed_at) ? $primary->assessed_at->format('M d, Y, h:i A') : $timestamp;
            @endphp
            <div class="p-3.5 rounded-xl bg-[#F2F2F7] border border-[#E5E5EA] flex flex-col sm:flex-row sm:items-center justify-between gap-3 text-xs">
                <div class="flex items-center gap-3">
                    <div class="w-2.5 h-2.5 rounded-full bg-[#780000] shrink-0"></div>
                    <div>
                        <strong class="text-[#1D1D1F] font-bold">
                            Run at {{ $runTime }}
                        </strong>
                        <div class="flex items-center gap-1.5 mt-0.5 flex-wrap">
                            <span class="text-xs text-[#6E6E73]">
                                Assessor: <strong class="text-[#1D1D1F]">{{ $runAssessor }}</strong>
                            </span>
                        </div>
                    </div>
                </div>

                <div class="flex items-center gap-4 self-end sm:self-center">
                    <div class="flex items-center gap-1.5">
                        <span class="text-[#6E6E73] font-semibold">Day 1:</span>
                        <span class="px-2.5 py-0.5 rounded-full text-xs font-bold {{ $d1 ? $d1->classification_badge['class'] : 'bg-gray-100 text-gray-600' }}">
                            {{ $d1 ? $d1->overall_classification : 'N/A' }}
                        </span>
                    </div>

                    <div class="flex items-center gap-1.5">
                        <span class="text-[#6E6E73] font-semibold">Day 2:</span>
                        <span class="px-2.5 py-0.5 rounded-full text-xs font-bold {{ $d2 ? $d2->classification_badge['class'] : 'bg-gray-100 text-gray-600' }}">
                            {{ $d2 ? $d2->overall_classification : 'N/A' }}
                        </span>
                    </div>

                    @if(($d1 && $d1->override_triggered) || ($d2 && $d2->override_triggered))
                        <span class="px-2.5 py-0.5 rounded-full text-xs font-black bg-[#FEF2F2] text-[#991B1B]">
                            Manual Override
                        </span>
                    @endif
                </div>
            </div>
            @empty
            <p class="text-xs text-[#6E6E73] py-2 text-center">No past assessment runs recorded yet.</p>
            @endforelse
        </div>
    </div>

    <!-- Manual Safety Override Modal -->
    <div x-show="openOverrideModal" x-cloak class="fixed inset-0 z-50 bg-black/40 flex justify-end">
        <div class="dive-side-panel h-full overflow-y-auto overscroll-contain bg-white sm:max-w-lg w-full p-6 space-y-4 shadow-2xl border-l border-[#E5E5EA]" @click.outside="openOverrideModal = false">
            <div class="flex items-center justify-between">
                <h3 class="text-base font-extrabold text-[#1D1D1F]">Apply Manual Override</h3>
                <button type="button" @click="openOverrideModal = false" aria-label="Close override modal" class="text-lg font-bold text-[#8E8E93] hover:text-[#1D1D1F]">✕</button>
            </div>

            <p class="text-xs text-[#6E6E73]">
                Forces both <strong>Day 1</strong> and <strong>Day 2</strong> to <strong>Critical Risk</strong> because of a PAGASA weather advisory or another hazard, such as an oil spill or a no-sail order.
            </p>

            <form action="{{ route('admin.weather.override', $batch) }}" method="POST" class="space-y-4 text-xs" x-data="{ otherHazard: '' }">
                @csrf

                <div>
                    <label class="block font-bold text-[#1D1D1F] mb-1">
                        Tropical Cyclone Wind Signal (TCWS)
                    </label>
                    <select name="tcws_signal" class="w-full px-3 py-2 rounded-xl border border-[#D1D1D6] text-xs text-[#1D1D1F] bg-white font-medium">
                        <option value="0">No Active TCWS Signal</option>
                        <option value="1">Signal No. 1</option>
                        <option value="2">Signal No. 2</option>
                        <option value="3" selected>Signal No. 3 (Forces Critical Risk)</option>
                        <option value="4">Signal No. 4 (Forces Critical Risk)</option>
                        <option value="5">Signal No. 5 (Forces Critical Risk)</option>
                    </select>
                </div>

                <div class="space-y-2 p-3.5 rounded-xl">
                    <span class="block font-bold text-[#1D1D1F] mb-1 uppercase tracking-wider text-xs">Active Severe Marine Advisories</span>

                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" name="gale_warning" value="1" class="rounded border-[#D1D1D6] text-[#780000]">
                        <span class="font-semibold text-[#1D1D1F]">PAGASA Marine Gale Warning</span>
                    </label>

                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" name="thunderstorm_advisory" value="1" class="rounded border-[#D1D1D6] text-[#780000]">
                        <span class="font-semibold text-[#1D1D1F]">Severe Thunderstorm / Lightning Advisory</span>
                    </label>

                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" name="typhoon_within_distance" value="1" class="rounded border-[#D1D1D6] text-[#780000]">
                        <span class="font-semibold text-[#1D1D1F]">Typhoon within Safety Distance (Batangas Coast)</span>
                    </label>

                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" name="tsunami_warning" value="1" class="rounded border-[#D1D1D6] text-[#780000]">
                        <span class="font-semibold text-[#1D1D1F]">Tsunami / Severe Marine Hazard Warning</span>
                    </label>
                </div>

                <!-- Other (non-weather) hazard -->
                <div class="space-y-2 p-3.5 rounded-xl">
                    <label for="other-hazard" class="block font-bold text-[#1D1D1F] uppercase tracking-wider text-xs">Other Hazard (Not Weather)</label>
                    <select id="other-hazard" name="other_hazard" x-model="otherHazard" class="w-full px-3 py-2 rounded-xl border border-[#D1D1D6] text-xs text-[#1D1D1F] bg-white font-medium">
                        <option value="">None</option>
                        @foreach(\App\Http\Controllers\Admin\WeatherSafetyController::OTHER_HAZARDS as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    <div x-show="otherHazard === 'other'" x-cloak>
                        <input type="text" name="other_hazard_detail" maxlength="150" :required="otherHazard === 'other'"
                               placeholder="Describe the hazard, e.g. Fish kill reported near Mainit Point"
                               class="w-full px-3 py-2 rounded-xl border border-[#D1D1D6] text-xs text-[#1D1D1F] bg-white">
                    </div>
                    <p class="text-[#6E6E73]">Choosing a hazard also forces <strong>Critical Risk</strong> for both days.</p>
                </div>

                <div>
                    <label class="block font-bold text-[#1D1D1F] mb-1">
                        Details / Source <span class="text-[#780000]">*</span>
                    </label>
                    <textarea name="reason" required rows="2" placeholder="e.g. PAGASA Severe Weather Bulletin #4, or Coast Guard advisory on oil spill near Anilao" class="w-full px-3 py-2 rounded-xl border border-[#D1D1D6] text-xs text-[#1D1D1F] bg-white"></textarea>
                </div>

                <div class="banner banner-error">
                    <label class="flex items-start gap-2 cursor-pointer">
                        <input type="checkbox" name="cancel_batch" value="1" class="rounded border-[#D1D1D6] text-[#780000] mt-0.5">
                        <span class="font-bold text-[#991B1B]">
                            Also cancel this batch now: every guest gets a full refund and the cancellation email.
                        </span>
                    </label>
                </div>

                <div class="flex items-center justify-end gap-2 pt-2 border-t border-[#E5E5EA]">
                    <button type="button" @click="openOverrideModal = false" class="btn-secondary px-3.5 py-1.5 text-xs">Cancel</button>
                    <button type="submit" class="btn-primary px-5 py-1.5 text-xs font-bold shadow-2xs">
                        Apply Override
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Batch Cancellation Panel -->
    @php
        $cancelBookings = $batch->bookings->whereNotIn('status', ['cancelled', 'cancelled_by_guest', 'cancelled_by_camp', 'completed', 'no_show']);
        $cancelGuests = $cancelBookings->sum(fn ($b) => max(1, $b->participants->count()));
        $cancelPaidBookings = $cancelBookings->where('status', '!=', 'pending_downpayment')->count();
        $cancelReasonPresets = [
            'Critical Risk sea conditions (strong wind, big waves or currents)',
            'Typhoon / storm signal raised by PAGASA',
            'Coast Guard no-sail order',
            'Oil spill or water contamination',
        ];
    @endphp
    <div x-show="openCancelModal" x-cloak class="fixed inset-0 z-50 bg-black/40 flex justify-end">
        <div class="dive-side-panel h-full overflow-y-auto overscroll-contain bg-white sm:max-w-lg w-full p-6 space-y-5 shadow-2xl border-l border-[#E5E5EA]"
             x-data="{ understood: false }" @click.outside="openCancelModal = false">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h3 class="text-lg font-extrabold text-[#1D1D1F]">Cancel this batch</h3>
                    <p class="text-xs text-[#6E6E73] mt-0.5">{{ $batch->batch_number }} &middot; {{ $batch->formatted_date_range }}</p>
                </div>
                <button type="button" @click="openCancelModal = false" aria-label="Close" class="text-lg font-bold text-[#8E8E93] hover:text-[#1D1D1F]">&#10005;</button>
            </div>

            <!-- Who is affected -->
            <div class="grid grid-cols-3 gap-2 text-center">
                <div class="rounded-xl bg-[#F2F2F7] p-3">
                    <div class="text-xl font-extrabold text-[#1D1D1F]">{{ $cancelBookings->count() }}</div>
                    <div class="text-[11px] text-[#6E6E73] font-semibold">Bookings</div>
                </div>
                <div class="rounded-xl bg-[#F2F2F7] p-3">
                    <div class="text-xl font-extrabold text-[#1D1D1F]">{{ $cancelGuests }}</div>
                    <div class="text-[11px] text-[#6E6E73] font-semibold">Divers</div>
                </div>
                <div class="rounded-xl bg-[#F2F2F7] p-3">
                    <div class="text-xl font-extrabold text-[#1D1D1F]">{{ $cancelPaidBookings }}</div>
                    <div class="text-[11px] text-[#6E6E73] font-semibold">Will get a refund</div>
                </div>
            </div>

            <!-- What happens -->
            <div class="space-y-1.5 text-xs">
                <p class="font-bold text-[#1D1D1F]">What happens when you confirm</p>
                <ol class="list-decimal pl-5 space-y-1 text-[#3A3A3C]">
                    <li>The batch and its {{ $cancelBookings->count() }} active booking(s) are marked <strong>Cancelled by Camp</strong>.</li>
                    <li>A <strong>full refund (100%)</strong> of what each guest paid is created and waits for you in <strong>Refunds</strong>.</li>
                    <li>Each guest gets the email below: why it was cancelled, their refund, and that they can ask to move to another date for free instead.</li>
                </ol>
                <p class="banner banner-warning mt-2">This cannot be undone.</p>
            </div>

            <form action="{{ route('admin.weather.cancel', $batch) }}" method="POST" class="space-y-4 text-xs">
                @csrf

                <div class="space-y-2">
                    <label for="cancel-reason" class="block font-bold text-[#1D1D1F]">
                        Why are you cancelling? <span class="text-[#780000]">*</span>
                    </label>
                    <div class="flex flex-wrap gap-1.5">
                        @foreach($cancelReasonPresets as $preset)
                            <button type="button" @click="cancelReason = @js($preset)"
                                    :class="cancelReason === @js($preset) ? 'bg-[#780000] text-white' : 'bg-[#F2F2F7] text-[#3A3A3C] hover:bg-[#E5E5EA]'"
                                    class="px-2.5 py-1 rounded-full text-[11px] font-semibold transition-colors">{{ $preset }}</button>
                        @endforeach
                    </div>
                    <input id="cancel-reason" type="text" name="cancellation_reason" x-model="cancelReason" required maxlength="1000"
                           placeholder="Pick one above or type your own reason"
                           class="w-full px-3.5 py-2.5 rounded-xl border border-[#D1D1D6] text-xs text-[#1D1D1F] bg-white font-medium">
                    <p class="text-[#6E6E73]">Guests will read this exact sentence, so keep it simple.</p>
                </div>

                <!-- Email preview: mirrors resources/views/emails/batch_weather_cancellation.blade.php -->
                <div class="space-y-1.5">
                    <span class="block font-bold text-[#1D1D1F]">Email each guest will receive</span>
                    <div class="rounded-xl bg-[#F2F2F7] p-2 text-xs text-[#1D1D1F] leading-relaxed">
                        <div class="px-4 py-2 bg-[#780000] text-white font-extrabold rounded-t-lg">Camp FreedivePH</div>
                        <div class="p-4 space-y-2.5 bg-white rounded-b-lg">
                            <p class="text-[11px] text-[#6E6E73]">Subject: Your {{ $batch->start_date->format('M d') }} dive has been cancelled for your safety</p>
                            <p class="text-sm font-extrabold">Your dive has been cancelled for your safety</p>
                            <p>Hello <strong>[Guest name]</strong>,</p>
                            <p>We're sorry, but we had to cancel your freediving trip on <strong>{{ $batch->formatted_date_range }}</strong>. Our safety team decided the sea will not be safe for diving on those dates.</p>
                            <div class="rounded-lg bg-[#FEF2F2] text-[#991B1B] px-3 py-2">
                                <strong class="block">Why we cancelled</strong>
                                <span x-text="cancelReason || '[Your reason]'"></span>
                            </div>
                            <div class="rounded-lg bg-[#ECFDF5] text-[#065F46] px-3 py-2">
                                <strong class="block">You will not lose any money</strong>
                                We have already started a full refund of the <strong>[amount they paid]</strong>. It goes back to the payment method you used. You don't need to do anything.
                            </div>
                            <p><strong>Would you rather dive on another date?</strong> Just reply to this email or message us, and we will move your booking to another available date for free instead of refunding you.</p>
                            <p class="text-[#6E6E73]">Also shows their booking number, PIN and a "View my booking" button.</p>
                        </div>
                    </div>
                </div>

                <label class="flex items-start gap-2 cursor-pointer">
                    <input type="checkbox" x-model="understood" class="rounded border-[#D1D1D6] text-[#780000] mt-0.5">
                    <span class="text-[#1D1D1F]">I understand this cancels <strong>{{ $cancelBookings->count() }} booking(s)</strong>, starts full refunds and emails every guest.</span>
                </label>

                <div class="flex items-center justify-end gap-2 pt-3 border-t border-[#E5E5EA]">
                    <button type="button" @click="openCancelModal = false" class="btn-secondary px-3.5 py-1.5 text-xs">Keep batch</button>
                    <button type="submit" :disabled="!understood || !cancelReason.trim()" class="btn-danger px-4 py-2 text-xs font-bold disabled:opacity-40 disabled:cursor-not-allowed">
                        Cancel batch &amp; email guests
                    </button>
                </div>
            </form>
        </div>
    </div>

</div>
@endsection
