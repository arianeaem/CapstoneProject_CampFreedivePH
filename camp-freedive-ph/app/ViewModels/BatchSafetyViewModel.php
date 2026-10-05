<?php

namespace App\ViewModels;

use App\Enums\RiskClassification;

/**
 * Ready-to-show values for the Safety Monitoring batch page (admin.weather.show).
 * Moved here from the @php blocks in the view: which result to show, badge colors,
 * model comparison notes, the Day 1 / Day 2 panels, the history and the cancel panel numbers.
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

    public readonly bool $isCbOpen;
    public readonly bool $isCbHalfOpen;
    public readonly bool $isPrimaryActive;
    public readonly bool $isConcluded;
    public readonly string $verdict;

    public function __construct(protected array $data)
    {
        $batch = $data['batch'];
        $cbState = $data['circuitStatus']['state'] ?? 'CLOSED';

        $this->isCbOpen = $cbState === 'OPEN';
        $this->isCbHalfOpen = $cbState === 'HALF_OPEN';
        $this->isPrimaryActive = ($data['isMLReachable'] ?? false) && !$this->isCbOpen;
        $this->isConcluded = ($batch->end_date && $batch->end_date->isPast())
            || in_array($batch->status, ['completed', 'cancelled_by_camp'], true);

        $mlRec = $data['batchMLAssessment']['overall_recommendation'] ?? ($data['overallClassification'] ?? 'Safe');
        $this->verdict = ($this->isPrimaryActive || $this->isConcluded) ? $mlRec : ($data['overallClassification'] ?? 'Safe');
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
        return self::score($this->verdict, 4);
    }

    public function verdictMeaning(): string
    {
        return \App\Services\WeatherForecastService::MEANING_MAP[$this->verdict] ?? 'Proceed with standard camp freediving protocols.';
    }

    public function checkedWithLabel(): string
    {
        return match (true) {
            $this->isPrimaryActive => 'Live forecast + safety model',
            $this->isCbOpen => 'Standard safety rules (safety model offline)',
            $this->isCbHalfOpen => 'Standard safety rules (safety model reconnecting)',
            default => 'Standard safety rules',
        };
    }

    // --- model comparison

    /** @return array<int, array{name: string, about: string, available: bool, seasonal: bool, overall: ?string, score: int, days: array, note: ?string}> */
    public function models(): array
    {
        $engines = $this->data['modelComparison'] ?? [];
        $defs = [
            ['historical', 'Historical Model', 'Estimates conditions from past years of weather at the dive site.'],
            ['legacy', 'Legacy Model', "Uses this week's live weather forecast, then checks it with our safety model."],
        ];

        return array_map(function ($def) use ($engines) {
            [$key, $name, $about] = $def;
            $m = $engines[$key] ?? null;
            $seasonal = !empty($m['is_seasonal_estimate']);
            $overall = $m['overall_classification'] ?? null;

            return [
                'name' => $name,
                'about' => $about,
                'available' => !empty($m['available']),
                'seasonal' => $seasonal,
                'overall' => $overall,
                'tone' => self::toneText($overall, $seasonal),
                'score' => self::score($overall),
                'days' => [
                    ['label' => 'Day 1', 'date' => $m['day1']['date'] ?? '', 'classification' => $m['day1']['classification'] ?? 'N/A', 'tone' => self::toneText($m['day1']['classification'] ?? null, $seasonal)],
                    ['label' => 'Day 2', 'date' => $m['day2']['date'] ?? '', 'classification' => $m['day2']['classification'] ?? 'N/A', 'tone' => self::toneText($m['day2']['classification'] ?? null, $seasonal)],
                ],
                'note' => match (true) {
                    empty($m['available']) => 'No result for these dates yet.',
                    $seasonal => 'Showing typical conditions for this time of year — live data is not available yet.',
                    str_contains($m['data_source'] ?? '', 'safety model unavailable') => 'Safety model is offline, so the live forecast was checked with standard safety rules instead.',
                    default => null,
                },
            ];
        }, $defs);
    }

    public function bothModelsAvailable(): bool
    {
        $e = $this->data['modelComparison'] ?? [];

        return !empty($e['historical']['available']) && !empty($e['legacy']['available']);
    }

    public function modelsAgree(): bool
    {
        $e = $this->data['modelComparison'] ?? [];

        return $this->bothModelsAvailable()
            && ($e['historical']['day1']['classification'] ?? null) === ($e['legacy']['day1']['classification'] ?? null)
            && ($e['historical']['day2']['classification'] ?? null) === ($e['legacy']['day2']['classification'] ?? null);
    }

    // --- Day 1 / Day 2 panels

    public function day(int $number): array
    {
        $assessment = $this->data["day{$number}Assessment"];
        $ml = $this->data["day{$number}MLAssessment"] ?? null;
        $continuous = $this->data["day{$number}Continuous24h"] ?? null;

        $hourly = !empty($ml['hourly_assessments']) ? $ml['hourly_assessments'] : ($continuous['hourly'] ?? []);
        $fallback = $assessment->overall_classification ?? 'Safe';
        $rec = ($this->isPrimaryActive || $this->isConcluded) ? ($ml['overall_recommendation'] ?? $fallback) : $fallback;
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

    /** @return array{bookings: int, divers: int, refunds: int} */
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
