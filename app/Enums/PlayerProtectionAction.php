<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * PlayerProtectionAction — the controlled protection-action vocabulary.
 *
 * Every act is deliberate: which gate it composes (wallet and/or
 * betting), whether a counter-act (Release) may lift it, and which
 * default duration the act carries. These facts are part of the act
 * itself, determined once here — never re-derived in the services.
 */
enum PlayerProtectionAction: string
{
    case SelfExclude = 'self_exclude';
    case LockAccount = 'lock_account';
    case LowerLimit = 'lower_limit';
    case SuspendMarketing = 'suspend_marketing';
    case CoolingOff = 'cooling_off';
    case Review = 'review';

    /**
     * The composition surface: wallet gates are shared with the
     * compliance lane; betting suspension blocks staking flows.
     */
    public function touchesWallet(): bool
    {
        return match ($this) {
            self::LockAccount => true,
            self::SelfExclude, self::CoolingOff, self::LowerLimit,
            self::SuspendMarketing, self::Review => false,
        };
    }

    public function blocksBettingActivity(): bool
    {
        return match ($this) {
            self::SelfExclude, self::LockAccount, self::CoolingOff => true,
            self::LowerLimit, self::SuspendMarketing, self::Review => false,
        };
    }

    /**
     * Acts that represent an enduring restriction distinguished from
     * the moment-act of Review: these can be lifted by release, all
     * others complete at apply time.
     */
    public function isDurableRestriction(): bool
    {
        return match ($this) {
            self::LockAccount, self::LowerLimit, self::SuspendMarketing => true,
            self::SelfExclude, self::CoolingOff => false, // their own lifecycle governs unsealing
            self::Review => false,
        };
    }

    /**
     * Default length of a CoolingOff pronouncement (hours) when the
     * desk supplies no explicit horizon — legal floor, server-side.
     */
    public function defaultHorizonHours(): ?int
    {
        return $this === self::CoolingOff ? 24 : null;
    }
}
