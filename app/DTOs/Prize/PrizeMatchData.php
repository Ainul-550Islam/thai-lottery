<?php

declare(strict_types=1);

namespace App\DTOs\Prize;

/**
 * Immutable prize-match identity.
 *
 * THE GRAMMAR
 * - draw_id / bet_id / ticket_id: positive handles; the ticket is the
 *   physical paper, the bet is the placement — the SERVICE corroborates
 *   pair coherence (bet must sit on that draw and that ticket).
 * - prize_tier: non-empty canonical lower-case token (e.g. 'first',
 *   'last_two'); the tier presented must exist in the draw's result lane.
 * - matched_amount: decimal string (money as money, never float).
 * - result_fingerprint: exactly 64 lowercase hex (sha256) — must equal
 *   the court-derived fingerprint over the draw's winning numbers
 *   (batch-9's canonical formula; never trusted on speech).
 */
final readonly class PrizeMatchData
{
    public function __construct(
        public int $drawId,
        public int $betId,
        public ?int $ticketId,
        public string $prizeTier,
        public string $matchedAmount,
        public string $resultFingerprint,
    ) {
    }

    /**
     * @throws \App\Exceptions\PrizeMatchException
     */
    public static function fromInput(
        int $drawId,
        int $betId,
        ?int $ticketId,
        string $prizeTier,
        string $matchedAmount,
        string $resultFingerprint,
    ): self {
        $fp = strtolower(trim($resultFingerprint));
        $tier = strtolower(trim($prizeTier));
        $amount = trim($matchedAmount);

        if ($drawId < 1 || $betId < 1 || ($ticketId !== null && $ticketId < 1)) {
            throw \App\Exceptions\PrizeMatchException::malformed(
                'draw, bet and ticket handles must be positive integers',
            );
        }

        if ($tier === '' || strlen($tier) > 32 || ! preg_match('/^[a-z0-9_-]+$/', $tier)) {
            throw \App\Exceptions\PrizeMatchException::malformed(
                'the prize tier must be a canonical lower-case token',
            );
        }

        if (! preg_match('/^-?\d+(\.\d{1,2})?$/', $amount)) {
            throw \App\Exceptions\PrizeMatchException::malformed(
                'the matched amount must be a decimal string (money, never float)',
            );
        }

        if (! preg_match('/^[0-9a-f]{64}$/', $fp)) {
            throw \App\Exceptions\PrizeMatchException::malformed(
                'the result fingerprint must be exactly 64 lowercase hex characters',
            );
        }

        return new self(
            drawId: $drawId,
            betId: $betId,
            ticketId: $ticketId,
            prizeTier: $tier,
            matchedAmount: $amount,
            resultFingerprint: $fp,
        );
    }

    /**
     * Deterministic match identity: one (draw, bet, tier, amount, result)
     * conversation = one key = one row ever.
     */
    public function matchKey(): string
    {
        return hash('sha256', sprintf(
            'prize-match:%d:%d:%s:%s:%s',
            $this->drawId,
            $this->betId,
            $this->prizeTier,
            $this->matchedAmount,
            $this->resultFingerprint,
        ));
    }
}
