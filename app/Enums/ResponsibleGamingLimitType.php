<?php

declare(strict_types=1);

namespace App\Enums;

enum ResponsibleGamingLimitType: string
{
    case DailyDeposit = 'daily_deposit';
    case SingleBet = 'single_bet';
    case DailyWagering = 'daily_wagering';
    case SelfExclusion = 'self_exclusion';
    case CoolOff = 'cool_off';
}
