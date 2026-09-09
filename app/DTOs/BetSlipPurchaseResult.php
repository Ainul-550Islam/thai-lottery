<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\BetPurchaseStatus;
use App\Models\Bet;
use App\Models\FinancialTransaction;
use App\Models\Ticket;
use App\Services\Finance\Money;
use App\ValueObjects\BetAmount;

/**
 * Committed outcome of an atomic multi-item bet slip purchase.
 */
final readonly class BetSlipPurchaseResult
{
    /**
     * @param  BetPurchaseStatus  $status
     * @param  Ticket  $ticket
     * @param  list<BetPurchaseResult>  $itemResults
     * @param  BetAmount  $totalStake
     * @param  Money  $totalPotentialPayout
     * @param  string  $idempotencyKey
     * @param  list<int>  $ledgerEntryIds
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public BetPurchaseStatus $status,
        public Ticket $ticket,
        public array $itemResults,
        public BetAmount $totalStake,
        public Money $totalPotentialPayout,
        public string $idempotencyKey,
        public array $ledgerEntryIds = [],
        public array $context = [],
    ) {
    }

    /**
     * @param  Ticket  $ticket
     * @param  list<BetPurchaseResult>  $itemResults
     * @param  BetAmount  $totalStake
     * @param  Money  $totalPotentialPayout
     * @param  string  $idempotencyKey
     * @param  list<int>  $ledgerEntryIds
     * @param  array<string, mixed>  $context
     */
    public static function purchased(
        Ticket $ticket,
        array $itemResults,
        BetAmount $totalStake,
        Money $totalPotentialPayout,
        string $idempotencyKey,
        array $ledgerEntryIds = [],
        array $context = [],
    ): self {
        return new self(
            status: BetPurchaseStatus::Purchased,
            ticket: $ticket,
            itemResults: $itemResults,
            totalStake: $totalStake,
            totalPotentialPayout: $totalPotentialPayout,
            idempotencyKey: $idempotencyKey,
            ledgerEntryIds: $ledgerEntryIds,
            context: $context,
        );
    }

    /**
     * @param  Ticket  $ticket
     * @param  list<BetPurchaseResult>  $itemResults
     * @param  BetAmount  $totalStake
     * @param  Money  $totalPotentialPayout
     * @param  string  $idempotencyKey
     * @param  list<int>  $ledgerEntryIds
     * @param  array<string, mixed>  $context
     */
    public static function replayed(
        Ticket $ticket,
        array $itemResults,
        BetAmount $totalStake,
        Money $totalPotentialPayout,
        string $idempotencyKey,
        array $ledgerEntryIds = [],
        array $context = [],
    ): self {
        return new self(
            status: BetPurchaseStatus::Replayed,
            ticket: $ticket,
            itemResults: $itemResults,
            totalStake: $totalStake,
            totalPotentialPayout: $totalPotentialPayout,
            idempotencyKey: $idempotencyKey,
            ledgerEntryIds: $ledgerEntryIds,
            context: array_merge($context, ['replayed' => true]),
        );
    }

    public function isReplay(): bool
    {
        return $this->status->isReplay();
    }

    public function ticketId(): int
    {
        return (int) $this->ticket->getKey();
    }

    public function ticketNumber(): string
    {
        return (string) $this->ticket->ticket_number;
    }

    /**
     * @return list<Bet>
     */
    public function bets(): array
    {
        return array_map(static fn (BetPurchaseResult $res): Bet => $res->bet, $this->itemResults);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status->value,
            'replayed' => $this->isReplay(),
            'ticket_id' => $this->ticketId(),
            'ticket_number' => $this->ticketNumber(),
            'total_stake' => $this->totalStake->amount(),
            'currency' => $this->totalStake->currency()->value,
            'total_potential_payout' => $this->totalPotentialPayout->toString(),
            'item_count' => count($this->itemResults),
            'items' => array_map(static fn (BetPurchaseResult $res): array => $res->toArray(), $this->itemResults),
            'idempotency_key' => $this->idempotencyKey,
            'ledger_entry_ids' => $this->ledgerEntryIds,
            'context' => $this->context,
        ];
    }
}
