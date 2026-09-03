<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The settlement status of ONE selection inside a simulated settlement run.
 *
 * Required by Phase 5.1 requirement H, which says the settlement output must show a
 * "settlement status" per selection alongside the ticket, number, market, winning
 * status, multiplier and simulated prize.
 *
 * WHY THIS IS NOT App\Enums\BetStatus AND NOT App\Enums\PayoutStatus
 * -----------------------------------------------------------------
 * BetStatus is the status of a bet AGGREGATE (pending, active, won, lost, refunded,
 * cancelled) and one bet can hold several selections, so it cannot express the
 * outcome of an individual selection.
 *
 * PayoutStatus describes a row in the payouts table, which is a REAL MONEY
 * obligation carrying a wallet_id and a financial_transaction_id. Phase 5.1 writes
 * no payouts row at all, so borrowing its vocabulary would misdescribe a simulation
 * as a payment.
 *
 * NON-MONETARY BY CONSTRUCTION
 * None of these cases means money moved. Won means "this selection matched the
 * drawn value under its market's rule, and the simulated prize was calculated".
 * It does not mean a balance changed, because Phase 5.1 changes no balance.
 */
enum SettlementSimulationStatus: string
{
    /**
     * Not yet evaluated in this run.
     */
    case Pending = 'pending';

    /**
     * The selection matched under its market's rule. A simulated prize amount was
     * calculated and recorded. NO money moved.
     */
    case Won = 'won';

    /**
     * The selection did not match. The simulated prize is exactly '0.00'.
     */
    case Lost = 'lost';

    /**
     * The selection was already in a final state before this run and was therefore
     * left untouched, which is what makes a repeated run a no-op.
     */
    case AlreadySettled = 'already_settled';

    /**
     * The selection cannot be settled and the run is refused because of it: its
     * market could not be resolved, its recorded market contradicts the market
     * derived from its bet type and position, or its configured multiplier cannot be
     * stored exactly. Never a silent skip.
     */
    case NotSettleable = 'not_settleable';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Won => 'Won (simulated)',
            self::Lost => 'Lost',
            self::AlreadySettled => 'Already settled',
            self::NotSettleable => 'Not settleable',
        };
    }

    /**
     * Whether this status represents a matched selection.
     */
    public function isWinning(): bool
    {
        return $this === self::Won;
    }

    /**
     * Whether the run evaluated this selection and reached a decision.
     */
    public function isDecided(): bool
    {
        return $this === self::Won || $this === self::Lost;
    }

    /**
     * Whether this status means the run must be refused as a whole.
     */
    public function isRefusal(): bool
    {
        return $this === self::NotSettleable;
    }

    /**
     * The bet aggregate status a decided selection implies.
     *
     * Returns null for every status that is not a decision, so a caller can never
     * derive a bet status from a pending, already settled or unsettleable selection.
     */
    public function toBetStatus(): ?BetStatus
    {
        return match ($this) {
            self::Won => BetStatus::Won,
            self::Lost => BetStatus::Lost,
            self::Pending, self::AlreadySettled, self::NotSettleable => null,
        };
    }

    /**
     * The status a match decision maps onto.
     *
     * Deliberately takes a bool rather than a MarketMatchResult so this enum keeps
     * no dependency on the market rule engine.
     */
    public static function fromMatched(bool $matched): self
    {
        return $matched ? self::Won : self::Lost;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
