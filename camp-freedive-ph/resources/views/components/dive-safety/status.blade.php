{{--
    Server-rendered dive safety status (same look as the live evaluation): status line with the
    5-line indicator, a short explanation and collapsible "Forecast details" with the
    Historical Model vs Legacy Forecast comparison.

    Usage:
        <x-dive-safety.status :classification="$weatherClass" :engines="$modelComparison" />
--}}
@props([
    'classification' => 'Safe',
    'description' => null,
    'engines' => null,
    'label' => 'Weather status',
])
@php
    $tone = fn (?string $c, bool $seasonal = false) => (!$c || $seasonal) ? 'neutral' : match ($c) {
        'Very Safe', 'Safe' => 'safe',
        'Moderate' => 'caution',
        'High Risk', 'Critical Risk' => 'unsafe',
        default => 'neutral',
    };
    $styles = [
        'safe' => ['surface' => 'bg-[#F0FDF4] border-[#BBF7D0]', 'text' => 'text-[#047857]'],
        'caution' => ['surface' => 'bg-[#FFFBEB] border-[#FDE68A]', 'text' => 'text-[#B45309]'],
        'unsafe' => ['surface' => 'bg-[#FEF2F2] border-[#FECACA]', 'text' => 'text-[#B91C1C]'],
        'neutral' => ['surface' => 'bg-[#F5F5F7] border-[#E5E5EA]', 'text' => 'text-[#3A3A3C]'],
    ];
    $segments = ['bg-[#EF4444]', 'bg-[#F97316]', 'bg-[#F59E0B]', 'bg-[#84CC16]', 'bg-[#10B981]'];
    $score = ['Very Safe' => 5, 'Safe' => 4, 'Moderate' => 3, 'High Risk' => 2, 'Critical Risk' => 1][$classification] ?? 4;
    $style = $styles[$tone($classification)];
    $description ??= \App\Services\WeatherForecastService::MEANING_MAP[$classification] ?? 'Standard marine safety protocols in effect.';

    $models = $engines ? [
        array_merge(['title' => 'Historical Model', 'subtitle' => 'Seasonal baseline'], $engines['historical'] ?? []),
        array_merge(['title' => 'Legacy Forecast', 'subtitle' => 'Open-Meteo + ONNX'], $engines['legacy'] ?? []),
    ] : [];
    $panelId = 'forecast-details-' . uniqid();
@endphp
<div x-data="{ detailsOpen: false }" {{ $attributes->merge(['class' => 'space-y-1.5']) }}>
    <div class="rounded-xl border px-3.5 py-2.5 space-y-1 {{ $style['surface'] }}" role="status">
        <div class="flex items-center justify-between gap-x-3 gap-y-1 flex-wrap">
            <div class="min-w-0">
                <span class="block text-[11px] font-bold uppercase tracking-wider text-[#6E6E73]">{{ $label }}</span>
                <span class="text-base font-black tracking-tight {{ $style['text'] }}">{{ $classification }}</span>
            </div>
            <div class="flex items-center gap-1 shrink-0" role="img" aria-label="Safety score {{ $score }} out of 5">
                @for($i = 1; $i <= 5; $i++)
                    <div class="h-1.5 w-5 rounded-full {{ $i <= $score ? $segments[$i - 1] : 'bg-[#E5E5EA]' }}"></div>
                @endfor
            </div>
        </div>
        <p class="text-xs sm:text-sm text-[#48484A] leading-snug">{{ $description }}</p>
    </div>

    @if($models)
        <div>
            <button type="button"
                    @click.stop="detailsOpen = !detailsOpen"
                    :aria-expanded="detailsOpen"
                    aria-controls="{{ $panelId }}"
                    class="w-full min-h-9 flex items-center justify-between gap-2 rounded-lg text-xs font-semibold text-[#6E6E73] hover:text-[#1D1D1F] transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-[#780000]">
                <span>Forecast details</span>
                <svg class="w-4 h-4 transition-transform duration-200" :class="detailsOpen ? 'rotate-180' : ''" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
            </button>
            <div id="{{ $panelId }}" x-show="detailsOpen" x-cloak x-transition.opacity class="grid grid-cols-1 sm:grid-cols-2 gap-2 pt-1 text-xs">
                @foreach($models as $model)
                    @php $seasonal = !empty($model['is_seasonal_estimate']); @endphp
                    <div class="rounded-lg border border-[#E5E5EA] bg-white p-2.5 space-y-1.5 min-w-0">
                        <div>
                            <div class="font-bold text-[#1D1D1F]">{{ $model['title'] }}</div>
                            <div class="text-[#8E8E93]">{{ $model['subtitle'] }}</div>
                        </div>
                        @if(!empty($model['available']))
                            <div class="space-y-1">
                                @foreach(['day1' => 'Day 1', 'day2' => 'Day 2'] as $key => $dayLabel)
                                    @php $dayClass = $model[$key]['classification'] ?? 'N/A'; @endphp
                                    <div class="flex items-center justify-between gap-2 text-[#6E6E73]">
                                        <span>{{ $dayLabel }}</span>
                                        <span class="font-semibold {{ $styles[$tone($dayClass, $seasonal)]['text'] }}">{{ $seasonal ? "Seasonal ({$dayClass})" : $dayClass }}</span>
                                    </div>
                                @endforeach
                                @if(!empty($model['data_source']))
                                    <p class="text-[#8E8E93] leading-snug break-words pt-0.5">{{ $model['data_source'] }}</p>
                                @endif
                            </div>
                        @else
                            <p class="text-[#8E8E93]">Not available for these dates.</p>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    @endif
</div>
