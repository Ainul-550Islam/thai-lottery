<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Normalized AML risk classification.
 *
 * Measured evidence is scored by AmlRiskAssessmentService (a
 * deterministic function of wallet/transaction/account facts — never
 * a client's word); the score band yields exactly one of these four.
 * Severity order is meaningful: High strictly outranks Medium.
 */
enum AmlRiskLevel: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Critical = 'critical';

    public function label(): string
    {
        return match ($this) {
            self::Low => 'Low',
            self::Medium => 'Medium',
            self::High => 'High',
            self::Critical => 'Critical',
        };
    }

    /**
     * Severity rank (0-3); comparison exists so 'escalate when risk
     * rises' is a number fact, not a string trick.
     */
    public function severity(): int
    {
        return match ($this) {
            self::Low => 0,
            self::Medium => 1,
            self::High => 2,
            self::Critical => 3,
        };
    }

    public function isMoreSevereThan(self $other): bool
    {
        return $this->severity() > $other->severity();
    }

    /**
     * Map a numeric assessment score (0-100) to its pronounced level.
     * Bands are the lane's single authority on thresholds.
     */
    public static function fromScore(int $score): self
    {
        return match (true) {
            $score >= 80 => self::Critical,
            $score >= 55 => self::High,
            $score >= 25 => self::Medium,
            default => self::Low,
        };
    }
}
