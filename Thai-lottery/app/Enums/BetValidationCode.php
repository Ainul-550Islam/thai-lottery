<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Stable machine-readable outcome codes for pre-bet validation.
 *
 * API clients, logs and the future betting controller key on these values. They
 * are contract: a case may be added, but a backing value must never be reworded,
 * because a client may already branch on it. Human wording lives in the reason
 * string on App\DTOs\BetValidationResult and may change freely.
 *
 * RELATIONSHIP TO THE RISK CODES
 * Phase 3.1 owns its own codes (NUMBER_LIMIT_EXCEEDED, NUMBER_BLOCKED,
 * HOT_NUMBER, MISSING_LIMIT, INVALID_LIMIT and so on) and they are NOT copied
 * here. When the risk engine refuses a selection, validation reports
 * RISK_REJECTED and carries the engine's own verdict, including its reason code,
 * verbatim in the result. Duplicating the risk vocabulary would let the two
 * drift apart.
 *
 * SPECIFICATION_REQUIRED IS NOT A FAILURE OF THE PLAYER
 * It means the project has not yet declared a rule the domain needs, so the
 * platform refuses to sell rather than invent one. It is reported distinctly from
 * every player-input error precisely so it cannot be mistaken for one.
 */
enum BetValidationCode: string
{
    case Valid = 'VALID';

    case DrawNotFound = 'DRAW_NOT_FOUND';
    case DrawClosed = 'DRAW_CLOSED';

    case InvalidMarket = 'INVALID_MARKET';
    case UnsupportedMarket = 'UNSUPPORTED_MARKET';
    case InvalidSide = 'INVALID_SIDE';
    case InvalidSelectionType = 'INVALID_SELECTION_TYPE';

    case InvalidNumber = 'INVALID_NUMBER';
    case InvalidDigits = 'INVALID_DIGITS';

    case InvalidAmount = 'INVALID_AMOUNT';
    case InvalidMultiplier = 'INVALID_MULTIPLIER';

    case RiskRejected = 'RISK_REJECTED';

    case SpecificationRequired = 'SPECIFICATION_REQUIRED';

    case ValidationFailed = 'VALIDATION_FAILED';

    public function label(): string
    {
        return match ($this) {
            self::Valid => 'Valid',
            self::DrawNotFound => 'Draw not found',
            self::DrawClosed => 'Draw is not open for betting',
            self::InvalidMarket => 'Invalid market',
            self::UnsupportedMarket => 'Unsupported market combination',
            self::InvalidSide => 'Invalid side for this market',
            self::InvalidSelectionType => 'Invalid selection type for this market',
            self::InvalidNumber => 'Invalid lottery number',
            self::InvalidDigits => 'Wrong number of digits',
            self::InvalidAmount => 'Invalid stake',
            self::InvalidMultiplier => 'Invalid payout multiplier',
            self::RiskRejected => 'Rejected by the risk engine',
            self::SpecificationRequired => 'Business rule not specified',
            self::ValidationFailed => 'Validation could not be completed',
        };
    }

    /**
     * Does this code mean the selection may proceed.
     */
    public function isAcceptable(): bool
    {
        return $this === self::Valid;
    }

    public function acceptance(): BetAcceptance
    {
        return $this->isAcceptable() ? BetAcceptance::Accept : BetAcceptance::Reject;
    }

    /**
     * Is the cause something the player supplied, as opposed to a platform or
     * configuration problem.
     *
     * Used to decide whether a rejection is worth surfacing to the player as a
     * correctable input error. It is not a security boundary.
     */
    public function isPlayerCorrectable(): bool
    {
        return match ($this) {
            self::InvalidNumber,
            self::InvalidDigits,
            self::InvalidAmount,
            self::InvalidSide,
            self::InvalidSelectionType,
            self::InvalidMarket => true,
            default => false,
        };
    }

    /**
     * Does this code indicate a missing or contradictory business rule rather
     * than a bad request.
     */
    public function isSpecificationGap(): bool
    {
        return $this === self::SpecificationRequired;
    }

    /**
     * Does this code originate in the Phase 3.1 risk engine.
     */
    public function isRiskOriginated(): bool
    {
        return $this === self::RiskRejected;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
