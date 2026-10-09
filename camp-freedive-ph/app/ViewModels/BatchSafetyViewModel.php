<?php

namespace App\ViewModels;

use App\Enums\RiskClassification;

/**
 * Ready-to-show values for the Safety Monitoring batch page (admin.weather.show).
 * Moved here from the @php blocks in the view: which result to show, badge colors,
 * the Day 1 / Day 2 panels, the history and the cancel panel numbers.
 */
class BatchSafetyViewModel
{
    /** Five score parts, from worst (red) to best (green). */
    public const SEGMENT_COLORS = ['bg-[#EF4444]', 'bg-[#F97316]', 'bg-[#F59E0B]', 'bg-[#84CC16]', 'bg-[#10B981]'];

    public const CANCEL_REASON_PRESETS = [
        'Critical Risk sea conditions (strong wind, big waves or currents)',
        'Typhoon / storm signal raised by PAGASA',
        'Coast Guard no-sail order',
        'Oil spill or water contamination',
    ];

    public readonly bool $isConcluded;
    public readonly string $verdict;

    public function __construct(protected array $data)
    {
        $batch = $data['batch'];

        $this->isConcluded = ($batch->end_date && $batch->end_date->isPast())
            || in_array($batch->status, ['completed', 'cancelled_by_camp'], true);
        $this->verdict = $data['overallClassification'] ?? 'Not Available';
    }

    // --- styling

    public static function score(?string $classification, int $default = 0): int
    {
        return RiskClassification::tryFromLabel($classification)?->score() ?? $default;
    }

    public static function badgeClass(?string $classification): string
    {
        return match ($classification) {
            'Very Safe', 'Safe' => 'bg-emerald-600 text-white',
            'Moderate' => 'bg-amber-500 text-white',
            'High Risk' => 'bg-rose-600 text-white',
            'Critical Risk' => 'bg-red-700 text-white',
            default => 'bg-gray-600 text-white',
        };
    }

    public static function lineColor(?string $classification): string
    {
        return match ($classification) {
            'Very Safe', 'Safe' => 'bg-emerald-500',
            'Moderate' => 'bg-amber-500',
            'High Risk' => 'bg-rose-500',
            'Critical Risk' => 'bg-red-600',
            default => 'bg-gray-400',
        };
    }

    public static function toneText(?string $classification, bool $seasonal = false): string
    {
        return $seasonal ? 'text-[#3A3A3C]' : match ($classification) {
            'Very Safe', 'Safe' => 'text-[#047857]',
            'Moderate' => 'text-[#B45309]',
            'High Risk', 'Critical Risk' => 'text-[#B91C1C]',
            default => 'text-[#3A3A3C]',
        };
    }

    // --- overall result

    public function verdictBadgeClass(): string
    {
        return self::badgeClass($this->verdict);
    }

    public function verdictScore(): int
    {
        return self::score($this->verdict, 0);
    }

    /** "Roughest: Moderate at 12 PM · current 0.42 m/s" from the roughest day, or null. */
    public function peakLabel(): ?string
    {
        if (in_array($this->verdict, ['Critical Risk', 'Not Available'], true)) {
            return null;
        }
        $rank = fn ($a) => \App\Services\WeatherForecastService::RISK_RANK[$a?->peak_classification] ?? 0;
        $a1 = $this->data['day1Assessment'] ?? null;
        $a2 = $this->data['day2Assessment'] ?? null;
        $worst = $rank($a2) > $rank($a1) ? $a2 : $a1;

        return $worst && $rank($worst) >= (\App\Services\WeatherForecastService::RISK_RANK[$this->verdict] ?? 0)
            ? $worst->peak_label
            : null;
    }

    public function verdictMeaning(): string
    {
        return \App\Services\WeatherForecastService::MEANING_MAP[$this->verdict] ?? 'Proceed with standard camp freediving protocols.';
    }

    // --- Day 1 / Day 2 panels

    public function day(int $number): array
    {
        $assessment = $this->data["day{$number}Assessment"];
        $continuous = $this->data["day{$number}Continuous24h"] ?? null;

        $hourly = $continuous['hourly'] ?? [];
        $rec = $assessment->overall_classification ?? 'Not Available';
        $leadH = (int) round($assessment->lead_time_hours ?? 0);

        return [
            'number' => $number,
            'assessment' => $assessment,
            'recommendation' => $rec,
            'badge' => self::badgeClass($rec),
            'rows' => array_map([$this, 'hourRow'], $hourly),
            'has_full_24h' => count($hourly) > 5,
            'forecast_made' => $this->isConcluded ? 'Concluded' : ($leadH >= 48 ? round($leadH / 24) . ' days' : $leadH . ' hours') . ' before dive',
            'reliability' => $this->isConcluded ? 'Archived Record' : ($assessment->reliability['label'] ?? 'High'),
        ];
    }

    /** One hourly row, with default values for anything missing. */
    protected function hourRow(array $h): array
    {
        $hour = (int) ($h['hour'] ?? 0);
        $risk = $h['final_tier_name'] ?? $h['classification'] ?? 'Safe';
        $hs = (float) ($h['predicted_hs'] ?? $h['wave_height'] ?? 0.70);
        $ws = (float) ($h['predicted_wind_speed'] ?? $h['wind_speed'] ?? 12.0);

        return [
            'hour' => $hour,
            'is_am' => in_array($hour, [10, 11, 12], true),
            'is_pm' => in_array($hour, [16, 17], true),
            'risk' => $risk,
            'line' => self::lineColor($risk),
            'hs' => $hs,
            'hs_p10' => (float) ($h['hs_p10'] ?? max(0.05, round($hs - 0.15, 2))),
            'hs_p90' => (float) ($h['hs_p90'] ?? round($hs + 0.18, 2)),
            'tp' => (float) ($h['predicted_tp'] ?? $h['wave_period'] ?? 6.1),
            'swell' => (float) ($h['predicted_swell_height'] ?? $h['swell_height'] ?? 0.50),
            'current' => (float) ($h['predicted_current_speed'] ?? $h['ocean_current'] ?? 0.30),
            'rain' => (float) ($h['rain_rate_mm_hr'] ?? $h['rain'] ?? 0.0),
            'pressure' => (float) ($h['slp'] ?? $h['sea_level_pressure'] ?? 1010.5),
            'wind' => $ws,
            'gusts' => (float) ($h['wind_gusts'] ?? $ws * 1.25),
        ];
    }

    // --- history

    /** @return array<int, array{time: string, assessor: string, day1: mixed, day2: mixed, overridden: bool}> */
    public function runs(): array
    {
        $runs = [];
        foreach ($this->data['assessmentRuns'] ?? [] as $timestamp => $records) {
            $d1 = $records->firstWhere('day_number', 1);
            $d2 = $records->firstWhere('day_number', 2);
            $primary = $d1 ?: $d2;

            $runs[] = [
                'time' => $primary?->assessed_at ? $primary->assessed_at->format('M d, Y, h:i A') : $timestamp,
                'assessor' => $d1?->assessor?->name ?? $d2?->assessor?->name ?? 'Automatic check',
                'day1' => $d1,
                'day2' => $d2,
                'overridden' => (bool) ($d1?->override_triggered || $d2?->override_triggered),
            ];
        }

        return $runs;
    }

    // --- cancel panel

    /** @return array{bookings: int, participants: int, refunds: int} */
    public function cancelImpact(): array
    {
        $bookings = $this->data['batch']->bookings
            ->whereNotIn('status', ['cancelled', 'cancelled_by_guest', 'cancelled_by_camp', 'completed', 'no_show']);

        return [
            'bookings' => $bookings->count(),
            'divers' => (int) $bookings->sum(fn ($b) => max(1, $b->participants->count())),
            'refunds' => $bookings->where('status', '!=', 'pending_downpayment')->count(),
        ];
    }
}
