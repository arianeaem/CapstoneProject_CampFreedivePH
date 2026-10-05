{{--
    Dive safety evaluation (live weather check result), shared by the booking page,
    Manage Booking reschedule and admin booking creation.

    Usage (inside an Alpine component that holds the /api/weather/check response):
        <template x-if="forecast && !weatherLoading">
            <x-dive-safety.live-evaluation forecast="forecast" />
        </template>

    `forecast` is the Alpine expression of the response object. Display helpers come from `$safety`.
--}}
@props(['forecast' => 'forecast'])
@php
    $f = $forecast;
    $seasonal = "{$f}.is_seasonal_estimate";
    $panelId = 'forecast-details-' . uniqid();
@endphp
<div x-data="{ detailsOpen: false }" {{ $attributes->merge(['class' => 'space-y-4']) }}>

    <!-- 1. Primary safety result -->
    <div class="rounded-xl border px-4 py-3 space-y-1.5"
         :class="$safety.surface({{ $f }}.overall_classification, {{ $seasonal }})"
         role="status" aria-live="polite">
        <div class="flex items-center justify-between gap-x-3 gap-y-1.5 flex-wrap">
            <p class="text-lg sm:text-xl font-black tracking-tight leading-tight"
               :class="$safety.text({{ $f }}.overall_classification, {{ $seasonal }})"
               x-text="$safety.headline({{ $f }}.overall_classification, {{ $seasonal }})"></p>
            <!-- 5-line safety indicator: each line has its own color (1 red → 5 green) -->
            <template x-if="$safety.tone({{ $f }}.overall_classification, {{ $seasonal }}) !== 'neutral'">
                <div class="flex items-center gap-1 shrink-0" role="img"
                     :aria-label="'Safety score ' + $safety.score({{ $f }}.overall_classification) + ' out of 5'">
                    <template x-for="i in 5" :key="'bar-' + i">
                        <div class="h-1.5 w-5 sm:w-6 rounded-full transition-colors duration-300"
                             :class="$safety.bar(i, {{ $f }}.overall_classification)"></div>
                    </template>
                </div>
            </template>
        </div>
        <p class="text-xs sm:text-sm text-[#48484A] leading-snug" x-show="{{ $f }}.description" x-text="{{ $f }}.description"></p>
        <template x-if="{{ $f }}.confidence_advisory">
            <p class="text-xs text-[#6E6E73]" x-text="{{ $f }}.confidence_advisory"></p>
        </template>
    </div>

    <!-- 2. Dive days -->
    <template x-if="{{ $f }}.day1 && {{ $f }}.day2">
        <section class="space-y-1.5" aria-label="Your dive days">
            <h4 class="text-xs font-extrabold uppercase tracking-wider text-[#6E6E73]">Your dive days</h4>
            <div class="rounded-xl border border-[#E5E5EA] divide-y divide-[#E5E5EA]">
                <template x-for="(day, idx) in [{{ $f }}.day1, {{ $f }}.day2]" :key="'dive-day-' + idx">
                    <div class="relative flex items-center gap-3 pl-4 pr-3 py-2.5 min-w-0">
                        <div class="absolute left-1.5 top-2 bottom-2 w-1 rounded-full" :class="$safety.dot(day.classification, {{ $seasonal }})" aria-hidden="true"></div>
                        <div class="min-w-0 flex-1">
                            <div class="text-xs text-[#6E6E73]">
                                <span class="font-bold text-[#1D1D1F]" x-text="$safety.dayParts(day.date).month + ' ' + $safety.dayParts(day.date).day"></span>
                                <span x-text="' · Day ' + (idx + 1) + ($safety.dayParts(day.date).weekday ? ' · ' + $safety.dayParts(day.date).weekday : '')"></span>
                            </div>
                            <div class="text-sm font-extrabold truncate" :class="$safety.text(day.classification, {{ $seasonal }})"
                                 x-text="{{ $seasonal }} ? 'Seasonal outlook' : day.classification"></div>
                        </div>
                        <!-- Roughest hour (backend "worst_hour": when wind & waves peak) -->
                        <div class="text-right shrink-0" x-show="$safety.hasTime(day.worst_hour)" title="When wind and waves peak that day">
                            <div class="text-[11px] font-bold uppercase tracking-wider text-[#8E8E93]">Roughest<span class="sr-only"> (when wind and waves peak)</span></div>
                            <div class="text-sm font-black text-[#1D1D1F] whitespace-nowrap" x-text="day.worst_hour"></div>
                        </div>
                    </div>
                </template>
            </div>
        </section>
    </template>

    <!-- 3. Why? (compact chips) -->
    <template x-if="$safety.highlights({{ $f }}).length">
        <ul class="flex flex-wrap gap-1.5" aria-label="Why this rating">
            <template x-for="(item, idx) in $safety.highlights({{ $f }})" :key="'why-' + idx">
                <li class="inline-flex items-center gap-1 rounded-full bg-[#F5F5F7] px-2.5 py-1 text-xs text-[#1D1D1F]">
                    <span class="font-black" :class="item.ok ? 'text-[#10B981]' : 'text-[#F59E0B]'" aria-hidden="true" x-text="item.ok ? '✓' : '!'"></span>
                    <span x-text="item.text"></span>
                </li>
            </template>
        </ul>
    </template>

    <!-- 4. Forecast details (collapsible, technical) -->
    <div class="border-t border-[#E5E5EA] pt-1">
        <button type="button"
                @click="detailsOpen = !detailsOpen"
                :aria-expanded="detailsOpen"
                aria-controls="{{ $panelId }}"
                class="w-full min-h-11 flex items-center justify-between gap-2 rounded-lg text-sm font-semibold text-[#6E6E73] hover:text-[#1D1D1F] transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-[#780000]">
            <span>Forecast details</span>
            <svg class="w-4 h-4 transition-transform duration-200" :class="detailsOpen ? 'rotate-180' : ''" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
        </button>

        <div id="{{ $panelId }}" x-show="detailsOpen" x-cloak x-transition.opacity class="space-y-3 pt-1 pb-1 text-xs">

            <!-- Metadata -->
            <dl class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-[#6E6E73]">
                <template x-if="{{ $f }}.reliability">
                    <div class="contents">
                        <dt>Reliability</dt>
                        <dd class="text-[#1D1D1F] font-semibold" x-text="{{ $f }}.reliability"></dd>
                    </div>
                </template>
                <template x-if="{{ $f }}.data_source">
                    <div class="contents">
                        <dt>Source</dt>
                        <dd class="text-[#1D1D1F] break-words" x-text="{{ $f }}.data_source"></dd>
                    </div>
                </template>
                <template x-if="{{ $f }}.historical_replay_label">
                    <div class="contents">
                        <dt>Mode</dt>
                        <dd class="text-[#1D1D1F]" x-text="{{ $f }}.historical_replay_label"></dd>
                    </div>
                </template>
            </dl>

            <!-- Day notes -->
            <template x-if="{{ $f }}.day1 && {{ $f }}.day2">
                <div class="space-y-1.5">
                    <template x-for="(day, idx) in [{{ $f }}.day1, {{ $f }}.day2]" :key="'note-' + idx">
                        <p class="text-[#48484A] leading-relaxed" x-show="day.recommended_action">
                            <span class="font-semibold text-[#1D1D1F]" x-text="'Day ' + (idx + 1) + ': '"></span><span x-text="day.recommended_action"></span>
                        </p>
                    </template>
                </div>
            </template>

            <!-- Model comparison: Historical Model vs Legacy Forecast -->
            <template x-if="{{ $f }}.engines">
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-1 xl:grid-cols-2 gap-2">
                    <template x-for="engine in $safety.engines({{ $f }})" :key="engine.title">
                        <div class="rounded-lg border border-[#E5E5EA] p-2.5 space-y-1.5 min-w-0">
                            <div>
                                <div class="font-bold text-[#1D1D1F]" x-text="engine.title"></div>
                                <div class="text-[#8E8E93]" x-text="engine.subtitle"></div>
                            </div>
                            <template x-if="engine.available">
                                <div class="space-y-1">
                                    <template x-for="(day, idx) in [engine.day1, engine.day2]" :key="engine.title + '-' + idx">
                                        <div class="flex items-center justify-between gap-2 text-[#6E6E73]">
                                            <span x-text="'Day ' + (idx + 1)"></span>
                                            <span class="font-semibold" :class="$safety.text(day.classification, engine.is_seasonal_estimate)"
                                                  x-text="engine.is_seasonal_estimate ? 'Seasonal (' + day.classification + ')' : day.classification"></span>
                                        </div>
                                    </template>
                                    <p class="text-[#8E8E93] leading-snug break-words pt-0.5" x-show="engine.data_source" x-text="engine.data_source"></p>
                                </div>
                            </template>
                            <template x-if="!engine.available">
                                <p class="text-[#8E8E93]">Not available for these dates.</p>
                            </template>
                        </div>
                    </template>
                </div>
            </template>
        </div>
    </div>
</div>
