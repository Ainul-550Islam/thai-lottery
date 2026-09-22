<?php

declare(strict_types=1);

namespace App\DTOs\Draw;

/**
 * The reconciliation lane's one-shot judgment pack.
 *
 * WHAT IT CARRIES
 * - draw_id + winning_numbers: the draw under comparison and the numbers
 *   the lane believes won (rows of bet_type/prize_tier/position/number, as
 *   fetched — order-free; the SERVICE canonicalizes).
 * - expected_winners / expected_payout: the totals the RESULT lane
 *   asserts (from winning_numbers.total_winners/total_payout);
 * - expected_prize_settled / expected_payouts_paid: totals any consumer
 *   lane (prize settlements, payout records) claims for the same draw.
 * - tolerance_minor: absolute money-difference tolerated per side before
 *   a line is drift. 0.00 default — the house counts exactly; even one
 *   satang of unexplained difference between result and payout lanes is
 *   a LINE, every time.
 *
 * Money inside this DTO is always decimal-string (never float).
 */
final readonly class DrawReconciliationData
{
    /**
     * @param  array<int, array{bet_type: string, prize_tier: ?string, position: ?string, number: string}>  $winningNumbers
     */
    public function __construct(
        public int $drawId,
        public array $winningNumbers,
        public string $expectedWinners,
        public string $expectedPayout,
        public string $expectedPrizeSettled,
        public string $expectedPayoutsPaid,
        public ?string $toleranceMinor = '0.00',
    ) {
    }

    /**
     * @param  array<int, array{bet_type: string, prize_tier: ?string, position: ?string, number: string}>  $winningNumbers
     *
     * @throws \App\Exceptions\DrawReconciliationException
     */
    public static function fromInput(
        int $drawId,
        array $winningNumbers,
        string $expectedWinners,
        string $expectedPayout,
        string $expectedPrizeSettled,
        string $expectedPayoutsPaid,
        ?string $toleranceMinor = '0.00',
    ): self {
        if ($drawId < 1) {
            throw \App\Exceptions\DrawReconciliationException::malformed(
                'the draw handle must be a positive integer',
            );
        }

        foreach (['expected_winners' => $expectedWinners, 'expected_payout' => $expectedPayout, 'expected_prize_settled' => $expectedPrizeSettled, 'expected_payouts_paid' => $expectedPayoutsPaid] as $field => $value) {
            if (! preg_match('/^-?\d+(\.\d{1,2})?$/', trim($value))) {
                throw \App\Exceptions\DrawReconciliationException::malformed(
                    sprintf('the %s must be a decimal string (money by money, never float)', $field),
                );
            }
        }

        if ($toleranceMinor !== null && ! preg_match('/^\d+(\.\d{1,2})?$/', trim($toleranceMinor))) {
            throw \App\Exceptions\DrawReconciliationException::malformed(
                'the tolerance must be a non-negative decimal string',
            );
        }

        foreach ($winningNumbers as $index => $row) {
            if (! is_array($row) || trim((string) ($row['bet_type'] ?? '')) === '' || trim((string) ($row['number'] ?? '')) === '') {
                throw \App\Exceptions\DrawReconciliationException::malformed(
                    sprintf('winning_numbers[%d]: every row must carry bet_type and number', $index),
                );
            }
        }

        return new self(
            drawId: $drawId,
            winningNumbers: $winningNumbers,
            expectedWinners: trim($expectedWinners),
            expectedPayout: trim($expectedPayout),
            expectedPrizeSettled: trim($expectedPrizeSettled),
            expectedPayoutsPaid: trim($expectedPayoutsPaid),
            toleranceMinor: $toleranceMinor !== null ? trim($toleranceMinor) : null,
        );
    }

    /**
     * Deterministic conversation identity for (draw, this exact evidence).
     */
    public function reconciliationKey(): string
    {
        return hash('sha256', sprintf(
            'draw-recon:%d:%s:%s:%s:%s:%s',
            $this->drawId,
            DrawCertificationData::canonicalFingerprint($this->drawId, $this->winningNumbers),
            $this->expectedWinners,
            $this->expectedPayout,
            $this->expectedPrizeSettled,
            $this->expectedPayoutsPaid,
        ));
    }
}
