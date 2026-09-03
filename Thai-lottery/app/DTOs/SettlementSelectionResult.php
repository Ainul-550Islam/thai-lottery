<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\SettlementSimulationStatus;
use App\ValueObjects\PayoutMultiplier;

/**
 * The simulated settlement outcome of ONE selection.
 *
 * This is the audit record Phase 5.1 requirement G asks for, one row per selection:
 *
 *   ticket                    ticketId / ticketNumber
 *   selected number           selection (a digit string)
 *   market                    marketKey
 *   winning status            matched / status
 *   configured multiplier     multiplier (from configuration, never from a caller)
 *   simulated prize amount    simulatedPrize (an exact decimal string)
 *   settlement status         status
 *
 * NON-MONETARY BY CONSTRUCTION
 * simulatedPrize is a CALCULATED FIGURE FOR AUDIT. Holding this object means no
 * balance changed, because Phase 5.1 changes none: it credits no wallet, debits no
 * wallet, writes no ledger entry, creates no financial transaction, creates no
 * payouts row and calls no payment gateway. The value is written to
 * bet_items.actual_payout, which is a plain reporting column on the bet line and is
 * not a balance, and to nothing else. There is deliberately no walletId, no
 * financialTransactionId and no payoutId field, so this object cannot be handed to
 * finance code that would try to pay it.
 *
 * EXACT DECIMALS ONLY
 * simulatedPrize is a decimal STRING at the currency scale, produced by
 * App\Services\Betting\MarketPayoutService through
 * App\Services\Betting\BetCalculationService, which is pure BCMath. A losing
 * selection carries exactly '0.00', not 0 and not 0.0. No (float), (double),
 * intval(), floatval() or round() appears anywhere in this class.
 *
 * THE MULTIPLIER IS THE CONFIGURED ONE
 * multiplier is whatever MarketPayoutService::multiplierFor($marketKey) returned,
 * which reads config('lottery.markets.<key>.payout_multiplier'). For run_top that is
 * 3 and for run_bottom it is 4. The legacy per bet type rate
 * BetType::Run->payoutMultiplier() (12) is never used, and
 * legacyMultiplierWasAvoided() lets a test assert that from the record itself.
 *
 * PERMUTATIONS DO NOT MULTIPLY MONEY
 * For a Tod selection, coveredNumberCount reports how many arrangements the one
 * selection covered (123 covers 6, 112 covers 3, 111 covers 1, 007 covers 3). It is
 * a coverage figure. charges is always 1 and simulatedPrize is stake x multiplier
 * exactly once, never multiplied by coveredNumberCount.
 */
final readonly class SettlementSelectionResult
{
    /**
     * @param  int  $betItemId  bet_items.id, the selection settled
     * @param  int  $betId  bets.id, the parent bet
     * @param  int|null  $ticketId  tickets.id, null when the bet carries no ticket
     * @param  string|null  $ticketNumber  the human readable ticket number
     * @param  int  $userId  the owner of the bet, for the audit trail only
     * @param  string  $marketKey  one of 3d_direct, 3d_tod, 2d_top, 2d_bottom, run_top, run_bottom
     * @param  string  $selection  the selected number, as a digit string
     * @param  string  $winningValue  the drawn value this selection was compared against
     * @param  bool  $matched  whether it matched under its market's verified rule
     * @param  string  $matchMode  exact, permutation or digit_contains
     * @param  string|null  $matchedValue  which arrangement or digit matched, null when it lost
     * @param  PayoutMultiplier  $multiplier  the configured rate that was applied
     * @param  string  $stake  the selection's stake, an exact decimal string
     * @param  string  $simulatedPrize  stake x multiplier when matched, '0.00' when not
     * @param  string  $currency  the currency code of the stake, for reporting
     * @param  SettlementSimulationStatus  $status  the settlement status of this selection
     * @param  int  $charges  always 1: one selection is one simulated ticket item
     * @param  int|null  $coveredNumberCount  arrangements covered, permutation markets only
     * @param  int|null  $occurrences  times a Run digit occurred, diagnostics only
     * @param  bool  $wasRounded  whether the exact product needed rounding to the currency scale
     * @param  string|null  $exactProduct  the untruncated product, when one was computed
     * @param  array<string, scalar|null>  $context  diagnostic context only
     */
    public function __construct(
        public int $betItemId,
        public int $betId,
        public ?int $ticketId,
        public ?string $ticketNumber,
        public int $userId,
        public string $marketKey,
        public string $selection,
        public string $winningValue,
        public bool $matched,
        public string $matchMode,
        public ?string $matchedValue,
        public PayoutMultiplier $multiplier,
        public string $stake,
        public string $simulatedPrize,
        public string $currency,
        public SettlementSimulationStatus $status,
        public int $charges = 1,
        public ?int $coveredNumberCount = null,
        public ?int $occurrences = null,
        public bool $wasRounded = false,
        public ?string $exactProduct = null,
        public array $context = [],
    ) {}

    /**
     * Whether this selection matched under its market's rule.
     */
    public function isWinner(): bool
    {
        return $this->matched;
    }

    /**
     * The simulated prize as an exact decimal string.
     *
     * Never a balance. Never paid. '0.00' for a losing selection.
     */
    public function simulatedPrize(): string
    {
        return $this->simulatedPrize;
    }

    /**
     * Whether the simulated prize is exactly zero, compared as a decimal string.
     */
    public function simulatedPrizeIsZero(): bool
    {
        return bccomp($this->simulatedPrize, '0', 2) === 0;
    }

    /**
     * The configured multiplier that was applied, as an exact decimal string.
     */
    public function multiplierValue(): string
    {
        return $this->multiplier->value();
    }

    /**
     * Whether the applied multiplier can be stored in the unsignedInteger
     * bet_items.payout_multiplier column without loss.
     */
    public function multiplierFitsBetItemColumn(): bool
    {
        return $this->multiplier->fitsBetItemColumn();
    }

    /**
     * Whether the legacy per bet type Run multiplier was avoided.
     *
     * The Phase 5.1 specification is explicit that Run markets must use their own
     * configured rates and never the legacy BetType multiplier of 12. This compares
     * the applied rate against that legacy value for the two Run markets and returns
     * true for every non-Run market, where the question does not arise.
     */
    public function legacyMultiplierWasAvoided(string $legacyRunMultiplier = '12'): bool
    {
        if ($this->marketKey !== 'run_top' && $this->marketKey !== 'run_bottom') {
            return true;
        }

        return bccomp($this->multiplier->value(), $legacyRunMultiplier, PayoutMultiplier::SCALE) !== 0;
    }

    /**
     * Whether the stake was charged exactly once.
     *
     * Always true. A Tod selection covering six arrangements is still one charge.
     */
    public function chargedOnce(): bool
    {
        return $this->charges === 1;
    }

    /**
     * Whether the simulated prize equals stake x multiplier exactly once.
     *
     * Compares against a BCMath recomputation, so a payout that had been multiplied
     * by a permutation count or an occurrence count would fail here. Rounded
     * products are excluded from the check and reported through wasRounded instead,
     * since for them the stored value legitimately differs from the raw product.
     */
    public function payoutIsSinglyCharged(): bool
    {
        if (! $this->matched) {
            return $this->simulatedPrizeIsZero();
        }

        if ($this->wasRounded) {
            return true;
        }

        $expected = bcmul($this->stake, $this->multiplier->value(), 2);

        return bccomp($this->simulatedPrize, $expected, 2) === 0;
    }

    /**
     * The values written to the bet_items row for this selection.
     *
     * Exactly three columns, all of them reporting columns on the bet line. None is
     * a balance and none belongs to the finance schema.
     *
     * @return array{is_winner: bool, actual_payout: string, payout_multiplier: string}
     */
    public function toBetItemColumns(): array
    {
        return [
            'is_winner' => $this->matched,
            'actual_payout' => $this->simulatedPrize,
            'payout_multiplier' => $this->multiplier->toBetItemColumn(),
        ];
    }

    /**
     * The full audit row, in the shape requirement G names.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'bet_item_id' => $this->betItemId,
            'bet_id' => $this->betId,
            'ticket_id' => $this->ticketId,
            'ticket_number' => $this->ticketNumber,
            'user_id' => $this->userId,
            'market' => $this->marketKey,
            'selected_number' => $this->selection,
            'winning_number' => $this->winningValue,
            'winning_status' => $this->matched ? 'won' : 'lost',
            'match_mode' => $this->matchMode,
            'matched_value' => $this->matchedValue,
            'configured_multiplier' => $this->multiplier->value(),
            'stake' => $this->stake,
            'simulated_prize_amount' => $this->simulatedPrize,
            'currency' => $this->currency,
            'settlement_status' => $this->status->value,
            'charges' => $this->charges,
            'covered_number_count' => $this->coveredNumberCount,
            'occurrences' => $this->occurrences,
            'was_rounded' => $this->wasRounded,
            'exact_product' => $this->exactProduct,
            'is_simulation' => true,
            'financial_effect' => 'none',
            'wallet_credited' => false,
            'ledger_entry_created' => false,
            'financial_transaction_created' => false,
            'payout_row_created' => false,
            'context' => $this->context,
        ];
    }
}
