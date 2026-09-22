<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * SecurityRiskLevel — the deterministic risk classification the
 * security lane speaks. Bands + threshold live here so assessment
 * stays a pure function of evidence and never drifts per-caller.
 */
enum SecurityRiskLevel: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Critical = 'critical';

    public function severity(): int
    {
        return match ($this) {
            self::Low => 0,
            self::Medium => 40,
            self::High => 70,
            self::Critical => 90,
        };
    }

    public function isMoreSevereThan(self $other): bool
    {
        return $this->severity() > $other->severity();
    }

    /**
     * The band map the assessment service uses — a single source of
     * truth shared by the review job's filter.
     */
    public static function fromScore(int $score): self
    {
        return match (true) {
            $score >= self::Critical->severity() => self::Critical,
            $score >= self::High->severity() => self::High,
            $score >= self::Medium->severity() => self::Medium,
            default => self::Low,
        };
    }
}
