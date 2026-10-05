<?php

namespace App\Enums;

/**
 * The five sea-safety ratings. The value is the label shown to people ('Critical Risk');
 * key() is the snake_case form stored in batches.risk_classification ('critical_risk').
 */
enum RiskClassification: string
{
    case VerySafe = 'Very Safe';
    case Safe = 'Safe';
    case Moderate = 'Moderate';
    case HighRisk = 'High Risk';
    case CriticalRisk = 'Critical Risk';

    /** Accepts either form ('Critical Risk' or 'critical_risk'); returns null for anything else. */
    public static function tryFromLabel(?string $value): ?self
    {
        if ($value === null) {
            return null;
        }

        return self::tryFrom($value) ?? match (strtolower(str_replace([' ', '-'], '_', $value))) {
            'very_safe' => self::VerySafe,
            'safe' => self::Safe,
            'moderate', 'moderate_risk' => self::Moderate,
            'high_risk', 'high' => self::HighRisk,
            'critical_risk', 'critical' => self::CriticalRisk,
            default => null,
        };
    }

    /** snake_case form used in the database (batches.risk_classification). */
    public function key(): string
    {
        return match ($this) {
            self::VerySafe => 'very_safe',
            self::Safe => 'safe',
            self::Moderate => 'moderate',
            self::HighRisk => 'high_risk',
            self::CriticalRisk => 'critical_risk',
        };
    }

    /** 1 (Very Safe) to 5 (Critical Risk); matches WeatherForecastService::RISK_RANK. */
    public function rank(): int
    {
        return match ($this) {
            self::VerySafe => 1,
            self::Safe => 2,
            self::Moderate => 3,
            self::HighRisk => 4,
            self::CriticalRisk => 5,
        };
    }

    /** Safety score for the 5-bar gauge: 5 = Very Safe, 1 = Critical Risk. */
    public function score(): int
    {
        return 6 - $this->rank();
    }

    /** High or Critical Risk. */
    public function isDangerous(): bool
    {
        return $this->rank() >= 4;
    }
}
