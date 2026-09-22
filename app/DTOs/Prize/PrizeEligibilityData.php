<?php

declare(strict_types=1);

namespace App\DTOs\Prize;

/**
 * Eligibility snapshot vocabulary.
 *
 * WHAT IT CARRIES, BY LANE (every lane GATHERED, never trusted)
 * - ownership: authoritative principal derived from the payout/ticket,
 *   never from client claims.
 * - kyc_status: the account's own KycStatus value at snapshot time.
 * - claim_window_allows: ClaimWindowService's reading on the payout.
 * - self_excluded: ResponsibleGamingLimit's reading through the user.
 * - ticket_state: the ticket's ledger status at snapshot time.
 *
 * The DTO is the E VALUE PACK: what the rules were fed, one snapshot.
 * The SERVICE turns it into an Eligible/Ineligible/Blocked decision —
 * never the reverse (callers never pre-announce their own verdict).
 */
final readonly class PrizeEligibilityData
{
    public function __construct(
        public int $payoutId,
        public int $authoritativeUserId,
        public string $kycStatus,
        public bool $claimWindowAllows,
        public bool $selfExcluded,
        public string $ticketState,
    ) {
    }

    /**
     * The snapshot identity: one decision space per (payout, principal).
     */
    public static function decisionKey(int $payoutId, int $userId): string
    {
        return hash('sha256', sprintf('prize-elig:%d:%d', $payoutId, $userId));
    }

    /**
     * @return array{payout_id: int, user_id: int, kyc_status: string, claim_window_allows: bool, self_excluded: bool, ticket_state: string}
     */
    public function toArray(): array
    {
        return [
            'payout_id' => $this->payoutId,
            'user_id' => $this->authoritativeUserId,
            'kyc_status' => $this->kycStatus,
            'claim_window_allows' => $this->claimWindowAllows,
            'self_excluded' => $this->selfExcluded,
            'ticket_state' => $this->ticketState,
        ];
    }
}
