{{--
    Server-rendered dive safety status (same look as the live evaluation): status line with the
    5-line indicator, a short explanation and the roughest-hour note.

    Usage:
        <x-dive-safety.status :classification="$weatherClass" />
--}}
@props([
    'classification' => 'Safe',
    'description' => null,
    'label' => 'Weather status',
    'peak' => null,
])
@php
    $tone = fn (?string $c) => !$c ? 'neutral' : match ($c) {
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
    $score = ['Very Safe' => 5, 'Safe' => 4, 'Moderate' => 3, 'High Risk' => 2, 'Critical Risk' => 1][$classification] ?? 0;
    $style = $styles[$tone($classification)];
    $description ??= \App\Services\WeatherForecastService::MEANING_MAP[$classification] ?? 'Standard marine safety protocols in effect.';
@endphp
<div {{ $attributes->merge(['class' => 'space-y-1.5']) }}>
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
        @if($peak)
            <p class="text-xs sm:text-sm font-semibold text-[#B45309]">{{ $peak }}</p>
        @endif
    </div>
</div>
