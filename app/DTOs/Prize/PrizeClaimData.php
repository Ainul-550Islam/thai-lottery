<?php

declare(strict_types=1);

namespace App\DTOs\Prize;

/**
 * Player-side prize claim input with the claimant's verification context.
 *
 * WHAT A CLAIM CONTAINS
 * ---------------------
 * The identity of the win being asserted (bet id), the identity of the
 * person asserting (claimant user id), the payout method preference for
 * how the prize should be discharged, and the proof-bearing context the
 * validation service needs to answer "yes, this player owns this win"
 * without query-time ambiguity:
 *
 * - ticket_number, when the player claims through a physical-ticket
 *   verification path (the ticket code that proves physical possession)
 * - verification_code, the ticket's verification secret (when present)
 * - correlation + request identity for idempotency replay
 *
 * Immutable by construction: a claim is not edited. A different claim is a
 * new claim.
 */
final class PrizeClaimData
{
    /**
     * @param  string|null  $ticketNumber  Physical ticket reference, when the
     *                                     claim asserts ownership through
     *                                     ticket verification.
     * @param  string|null  $verificationCode  The ticket's verification
     *                                         secret; present only when the
     *                                         ticket verification lane asked.
     * @param  array<string, mixed>  $context  Safe diagnostic context.
     */
    public function __construct(
        public readonly int $betId,
        public readonly int $claimantUserId,
        public readonly \App\Enums\PrizePayoutMethod $payoutMethod,
        public readonly ?string $ticketNumber,
        public readonly ?string $verificationCode,
        public readonly ?string $claimKey,
        public readonly array $context = [],
    ) {
    }

    /**
     * The claim idempotency anchor: one claim per (bet, claimant, method)
     * forever. Submitted twice, the second submission routes onto the first
     * claim's recorded identity rather than minting a second review row.
     */
    public static function deriveClaimKey(int $betId, int $claimantUserId, \App\Enums\PrizePayoutMethod $method): string
    {
        return hash('sha256', sprintf('prize-claim:%d:%d:%s', $betId, $claimantUserId, $method->value));
    }

    /**
     * Whether the ticket-verification lane is invoked: both identifiers
     * present proves physical possession style claims; either missing and
     * the digital wallet lane applies.
     */
    public function hasTicketVerification(): bool
    {
        return $this->ticketNumber !== null && $this->ticketNumber !== ''
            && $this->verificationCode !== null && $this->verificationCode !== '';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'bet_id' => $this->betId,
            'claimant_user_id' => $this->claimantUserId,
            'payout_method' => $this->payoutMethod->value,
            'ticket_number' => $this->ticketNumber,
            'has_verification_code' => $this->verificationCode !== null,
            'claim_key' => $this->claimKey,
            'context' => $this->context,
        ];
    }
}
