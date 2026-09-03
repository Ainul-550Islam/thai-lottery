<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Outcome of a pre-bet risk evaluation.
 *
 * This is the single value a caller branches on. It is deliberately small: the
 * WHY of a decision lives in the stable reason code carried alongside it by
 * App\Services\Risk\RiskDecisionService, never in extra enum cases, so new
 * rejection causes never require a schema-visible vocabulary change.
 *
 * Review exists because config/risk.php models a band between "loud" and
 * "blocked": 'auto_block.block_at_level' is Critical while
 * 'alerts.notify_at_level' is High, and 'override.allowed' is true with a
 * required permission. A High assessment that has not consumed its ceiling is
 * therefore neither a clean allow nor a hard reject.
 *
 * NOT PERSISTED: no column in the audited schema stores a risk decision, so this
 * enum is an in-memory result type only. Phase 3.1 adds no migration.
 */
enum RiskDecision: string
{
    /** The bet may proceed. Capacity was available, and reserved if requested. */
    case Allow = 'allow';

    /**
     * The bet may proceed only after a permitted operator override.
     *
     * Treated as a refusal by any caller that cannot obtain an override:
     * isAllowed() returns false for this case on purpose, so forgetting to
     * handle Review fails closed rather than open.
     */
    case Review = 'review';

    /** The bet must not proceed. */
    case Reject = 'reject';

    public function label(): string
    {
        return match ($this) {
            self::Allow => 'Allowed',
            self::Review => 'Manual Review Required',
            self::Reject => 'Rejected',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Allow => 'green',
            self::Review => 'yellow',
            self::Reject => 'red',
        };
    }

    /**
     * Only Allow permits the bet to continue without human intervention.
     */
    public function isAllowed(): bool
    {
        return $this === self::Allow;
    }

    public function isRejected(): bool
    {
        return $this === self::Reject;
    }

    public function requiresReview(): bool
    {
        return $this === self::Review;
    }

    /**
     * Whether the caller must stop before touching money.
     *
     * Fails closed for Review, which is the safe reading.
     */
    public function blocksBet(): bool
    {
        return $this !== self::Allow;
    }

    /**
     * The decision implied by a severity band, given the operator configuration.
     *
     * $blockAtLevel comes from config('risk.auto_block.block_at_level') and
     * $reviewAtLevel from config('risk.alerts.notify_at_level'). Capacity checks
     * are NOT expressed here: a consumed ceiling is always Reject regardless of
     * band, and that is decided by the engine, not by this mapping.
     */
    public static function fromRiskLevel(
        RiskLevel $level,
        RiskLevel $blockAtLevel,
        ?RiskLevel $reviewAtLevel = null,
    ): self {
        if ($level->atLeast($blockAtLevel)) {
            return self::Reject;
        }

        if ($reviewAtLevel instanceof RiskLevel && $level->atLeast($reviewAtLevel)) {
            return self::Review;
        }

        return self::Allow;
    }

    /**
     * Combine several decisions into the most restrictive one.
     *
     * Used when one request is evaluated against multiple independent ceilings
     * (stake ceiling and payout-liability ceiling): any Reject wins, then any
     * Review, and Allow survives only when every input allowed. An empty list is
     * a programming error rather than an implicit allow, so it yields Reject.
     *
     * @param  iterable<self>  $decisions
     */
    public static function mostRestrictive(iterable $decisions): self
    {
        $result = null;

        foreach ($decisions as $decision) {
            if ($decision === self::Reject) {
                return self::Reject;
            }

            if ($decision === self::Review || $result === null) {
                $result = $decision === self::Review ? self::Review : $decision;
            }
        }

        return $result ?? self::Reject;
    }
}
