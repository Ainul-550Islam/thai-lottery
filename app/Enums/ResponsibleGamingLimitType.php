<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * ResponsibleGamingLimitType — the limit taxonomy.
 *
 * PRE-EXISTING vocabulary (first five cases, semantics untouched):
 * the legacy per-user row lanes and exclusion markers. Batch-14
 * expands the taxonomy ADDITIVELY with the periodic ceilings the
 * enforcement facade reads: weekly/monthly deposit, daily/weekly/
 * monthly loss, and the stake limit. Every case pronounces its own
 * measure and rolling period — never re-derived by the services.
 */
enum ResponsibleGamingLimitType: string
{
    case DailyDeposit = 'daily_deposit';
    case SingleBet = 'single_bet';
    case DailyWagering = 'daily_wagering';
    case SelfExclusion = 'self_exclusion';
    case CoolOff = 'cool_off';
    case WeeklyDeposit = 'weekly_deposit';
    case MonthlyDeposit = 'monthly_deposit';
    case DailyLoss = 'daily_loss';
    case WeeklyLoss = 'weekly_loss';
    case MonthlyLoss = 'monthly_loss';
    case StakeLimit = 'stake_limit';

    /**
     * The rolling window over which the ceiling is measured.
     * `null` = per-act ceilings (single bet / stake) measured at
     * the attempt itself, and exclusion markers that carry no sum.
     */
    public function rollingPeriodDays(): ?int
    {
        return match ($this) {
            self::DailyDeposit, self::DailyWagering, self::DailyLoss => 1,
            self::WeeklyDeposit, self::WeeklyLoss => 7,
            self::MonthlyDeposit, self::MonthlyLoss => 30,
            self::SingleBet, self::StakeLimit,
            self::SelfExclusion, self::CoolOff => null,
        };
    }

    /**
     * What flows count toward this ceiling — deposits in, staking
     * volume (wagering), net loss, or per-act stake. The enforcement
     * facade reads this to decide which evidence sums to consult.
     */
    public function measure(): string
    {
        return match ($this) {
            self::DailyDeposit, self::WeeklyDeposit, self::MonthlyDeposit => 'deposits',
            self::DailyWagering => 'wagering',
            self::DailyLoss, self::WeeklyLoss, self::MonthlyLoss => 'net_loss',
            self::SingleBet, self::StakeLimit => 'per_act_stake',
            self::SelfExclusion, self::CoolOff => 'none',
        };
    }

    /**
     * The ceiling types the versioned limit lane enforces. Exclusion
     * markers belong to the self-exclusion lane and never become
     * version rows.
     */
    public function isVersionedCeiling(): bool
    {
        return $this->measure() !== 'none';
    }

    public function label(): string
    {
        return str_replace('_', ' ', $this->value);
    }
}
