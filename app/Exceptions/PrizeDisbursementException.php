<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;

/**
 * The settlement lane's pronounced refusals.
 *
 * - PRIZE_DISB_MALFORMED              grammar never accepted the ask.
 * - PRIZE_DISB_NOT_FOUND              named payout/disbursement missing.
 * - PRIZE_DISB_PAYOUT_MISMATCH        presented settlement fingerprint /
 *                                     amount disagrees with the payout row.
 * - PRIZE_DISB_UNAPPROVED_CLAIM       the claim isn't Approved — only
 *                                     approved settlements get money.
 * - PRIZE_DISB_INELIGIBLE             the eligibility lane didn't admit.
 * - PRIZE_DISB_DUPLICATE              different ask under the same key —
 *                                     fork, pronounced.
 * - PRIZE_DISB_RESERVATION_CONFLICT   asked reservation exceeds what the
 *                                     payout conserves (bcmath), or a
 *                                     live reservation already stands.
 * - PRIZE_DISB_REVERSAL_VIOLATION     asked reversal can't conversation
 *                                     with the row's current life.
 */
final class PrizeDisbursementException extends Exception
{
    public const CODE_MALFORMED = 'PRIZE_DISB_MALFORMED';

    public const CODE_NOT_FOUND = 'PRIZE_DISB_NOT_FOUND';

    public const CODE_PAYOUT_MISMATCH = 'PRIZE_DISB_PAYOUT_MISMATCH';

    public const CODE_UNAPPROVED_CLAIM = 'PRIZE_DISB_UNAPPROVED_CLAIM';

    public const CODE_INELIGIBLE = 'PRIZE_DISB_INELIGIBLE';

    public const CODE_DUPLICATE = 'PRIZE_DISB_DUPLICATE';

    public const CODE_RESERVATION_CONFLICT = 'PRIZE_DISB_RESERVATION_CONFLICT';

    public const CODE_REVERSAL_VIOLATION = 'PRIZE_DISB_REVERSAL_VIOLATION';

    public function __construct(
        string $message,
        private readonly string $errorCode,
        private readonly array $errorContext = [],
    ) {
        parent::__construct($message);
    }

    public static function malformed(string $reason, array $context = []): self
    {
        return new self(
            sprintf('Prize disbursement refused: %s', $reason),
            self::CODE_MALFORMED,
            $context + ['reason' => $reason],
        );
    }

    public static function notFound(string $reference, array $context = []): self
    {
        return new self(
            sprintf('Prize disbursement refused: [%s] is not on the ledger', $reference),
            self::CODE_NOT_FOUND,
            $context + ['reference' => $reference],
        );
    }

    public static function payoutMismatch(int $payoutId, string $expected, string $presented, array $context = []): self
    {
        return new self(
            sprintf('Prize disbursement refused: settlement facts disagree for payout #%d (%s vs %s)', $payoutId, $expected, $presented),
            self::CODE_PAYOUT_MISMATCH,
            $context + ['payout_id' => $payoutId, 'expected' => $expected, 'presented' => $presented],
        );
    }

    public static function unapprovedClaim(int $payoutId, string $claimStatus, array $context = []): self
    {
        return new self(
            sprintf('Prize disbursement refused: claim on payout #%d is [%s]; only Approved settlements get money', $payoutId, $claimStatus),
            self::CODE_UNAPPROVED_CLAIM,
            $context + ['payout_id' => $payoutId, 'claim_status' => $claimStatus],
        );
    }

    public static function ineligible(int $payoutId, string $status, array $context = []): self
    {
        return new self(
            sprintf('Prize disbursement refused: eligibility for payout #%d is [%s]; only Eligible admits', $payoutId, $status),
            self::CODE_INELIGIBLE,
            $context + ['payout_id' => $payoutId, 'eligibility' => $status],
        );
    }

    public static function duplicate(string $disbursementKey, array $context = []): self
    {
        return new self(
            sprintf('Prize disbursement refused: a different ask presented under key %s...', substr($disbursementKey, 0, 12)),
            self::CODE_DUPLICATE,
            $context + ['disbursement_key' => $disbursementKey],
        );
    }

    public static function reservationConflict(int $payoutId, string $asked, string $available, array $context = []): self
    {
        return new self(
            sprintf('Prize disbursement refused: payout #%d conserves %s available for reservation, the ask wanted %s', $payoutId, $available, $asked),
            self::CODE_RESERVATION_CONFLICT,
            $context + ['payout_id' => $payoutId, 'asked' => $asked, 'available' => $available],
        );
    }

    public static function reversalViolation(string $disbursementKey, string $status, array $context = []): self
    {
        return new self(
            sprintf('Prize disbursement refused: reversal cannot conversation with a [%s] row', $status),
            self::CODE_REVERSAL_VIOLATION,
            $context + ['disbursement_key' => $disbursementKey, 'status' => $status],
        );
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return $this->errorContext;
    }
}
