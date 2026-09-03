<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\BetPurchaseStatus;
use App\Models\Bet;
use App\Models\BetItem;
use App\Models\FinancialTransaction;
use App\Models\Ticket;
use App\Services\Finance\Money;
use App\ValueObjects\BetAmount;

/**
 * The committed outcome of a successful bet purchase.
 *
 * WHY THIS OBJECT ONLY EVER DESCRIBES SUCCESS
 * A purchase either commits completely or throws. There is no field here for a
 * failure reason and no way to express a half-purchase, so a caller holding this
 * object holds a guarantee: the bet, the bet item, the ticket, the wallet debit and
 * the balanced ledger posting all exist and all committed together. Failure is
 * carried by exceptions instead, which is what makes the rollback automatic.
 *
 * WHY IT DISTINGUISHES A REPLAY
 * When status is Replayed, this request created nothing: it found an earlier bet
 * under the same idempotency key and is returning it. The rows are real and
 * committed, the money moved exactly once, and it moved during the FIRST request.
 * A caller that needs to know whether it was the request that spent the money -
 * for analytics, for a receipt, for a push notification - reads isReplay() rather
 * than guessing from timestamps.
 *
 * WHY ONE ITEM AND ONE TICKET, NOT COLLECTIONS
 * One purchase is one logical selection: one bet, one bet item, one ticket. Notably
 * a 3D Tod selection on '123' covers six arrangements of those digits but remains
 * ONE selection with ONE stake, ONE charge and ONE ticket. Making these fields
 * collections would invite a future change to create six of each, which is exactly
 * the outcome this phase forbids.
 */
final readonly class BetPurchaseResult
{
    /**
     * @param  BetPurchaseStatus  $status  Purchased when this request performed the
     *                                    mutation, Replayed when an earlier identical
     *                                    request had already performed it
     * @param  Bet  $bet  the committed bet
     * @param  BetItem  $item  the single committed bet item
     * @param  Ticket  $ticket  the committed ticket
     * @param  FinancialTransaction|null  $transaction  the committed wallet debit; null only
     *                                                  when replaying a bet whose debit row
     *                                                  cannot be located, which the
     *                                                  idempotency service refuses before
     *                                                  reaching here
     * @param  BetAmount  $stake  the exact amount debited
     * @param  Money  $potentialPayout  the exact liability accepted
     * @param  string  $idempotencyKey  the derived key stored on bets.idempotency_key
     * @param  list<int>  $ledgerEntryIds  the ids of the balanced double-entry rows
     * @param  array<string, mixed>  $riskReservation  the verbatim Phase 3.1 reservation
     *                                                 result, empty on a replay because no
     *                                                 new capacity was consumed
     * @param  array<string, mixed>  $context  diagnostic context only
     */
    public function __construct(
        public BetPurchaseStatus $status,
        public Bet $bet,
        public BetItem $item,
        public Ticket $ticket,
        public ?FinancialTransaction $transaction,
        public BetAmount $stake,
        public Money $potentialPayout,
        public string $idempotencyKey,
        public array $ledgerEntryIds = [],
        public array $riskReservation = [],
        public array $context = [],
    ) {
    }

    /**
     * A brand new purchase performed by this request.
     *
     * @param  list<int>  $ledgerEntryIds
     * @param  array<string, mixed>  $riskReservation
     * @param  array<string, mixed>  $context
     */
    public static function purchased(
        Bet $bet,
        BetItem $item,
        Ticket $ticket,
        FinancialTransaction $transaction,
        BetAmount $stake,
        Money $potentialPayout,
        string $idempotencyKey,
        array $ledgerEntryIds = [],
        array $riskReservation = [],
        array $context = [],
    ): self {
        return new self(
            status: BetPurchaseStatus::Purchased,
            bet: $bet,
            item: $item,
            ticket: $ticket,
            transaction: $transaction,
            stake: $stake,
            potentialPayout: $potentialPayout,
            idempotencyKey: $idempotencyKey,
            ledgerEntryIds: $ledgerEntryIds,
            riskReservation: $riskReservation,
            context: $context,
        );
    }

    /**
     * An earlier purchase returned unchanged for a repeated idempotency key.
     *
     * The risk reservation is deliberately empty: the capacity was consumed by the
     * first request and must not be reserved again, so there is no new reservation to
     * report. Reporting the original reservation here would read like a second
     * reservation had occurred.
     *
     * @param  list<int>  $ledgerEntryIds
     * @param  array<string, mixed>  $context
     */
    public static function replayed(
        Bet $bet,
        BetItem $item,
        Ticket $ticket,
        ?FinancialTransaction $transaction,
        BetAmount $stake,
        Money $potentialPayout,
        string $idempotencyKey,
        array $ledgerEntryIds = [],
        array $context = [],
    ): self {
        return new self(
            status: BetPurchaseStatus::Replayed,
            bet: $bet,
            item: $item,
            ticket: $ticket,
            transaction: $transaction,
            stake: $stake,
            potentialPayout: $potentialPayout,
            idempotencyKey: $idempotencyKey,
            ledgerEntryIds: $ledgerEntryIds,
            riskReservation: [],
            context: array_merge($context, ['replayed' => true]),
        );
    }

    public function isReplay(): bool
    {
        return $this->status->isReplay();
    }

    public function betId(): int
    {
        return (int) $this->bet->getKey();
    }

    public function betItemId(): int
    {
        return (int) $this->item->getKey();
    }

    public function ticketId(): int
    {
        return (int) $this->ticket->getKey();
    }

    public function betNumber(): string
    {
        return (string) $this->bet->bet_number;
    }

    public function ticketNumber(): string
    {
        return (string) $this->ticket->ticket_number;
    }

    public function financialTransactionId(): ?int
    {
        $key = $this->transaction?->getKey();

        return $key === null ? null : (int) $key;
    }

    /**
     * How many ledger rows the debit produced. A single wallet debit is a two-row
     * double entry, so anything other than 2 is worth surfacing.
     */
    public function ledgerEntryCount(): int
    {
        return count($this->ledgerEntryIds);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status->value,
            'replayed' => $this->isReplay(),
            'bet_id' => $this->betId(),
            'bet_number' => $this->betNumber(),
            'bet_item_id' => $this->betItemId(),
            'ticket_id' => $this->ticketId(),
            'ticket_number' => $this->ticketNumber(),
            'financial_transaction_id' => $this->financialTransactionId(),
            'stake' => $this->stake->amount(),
            'currency' => $this->stake->currency()->value,
            'potential_payout' => $this->potentialPayout->toString(),
            'idempotency_key' => $this->idempotencyKey,
            'ledger_entry_ids' => $this->ledgerEntryIds,
            'ledger_entry_count' => $this->ledgerEntryCount(),
            'risk_reservation' => $this->riskReservation,
            'context' => $this->context,
        ];
    }
}
