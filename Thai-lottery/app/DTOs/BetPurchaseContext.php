<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\BetMarket;
use App\Enums\BetSelectionType;
use App\Enums\BetSide;
use App\Enums\BetType;
use App\Enums\Currency;
use App\Models\Draw;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Finance\Money;
use App\ValueObjects\BetAmount;
use App\ValueObjects\LotteryNumber;
use App\ValueObjects\PayoutMultiplier;

/**
 * Everything the purchase transaction needs, all of it already derived and
 * validated, and none of it client-supplied.
 *
 * WHY A SEPARATE CONTEXT OBJECT EXISTS
 * The transaction body must be short, ordered and free of decisions. Every question
 * that can be answered before the transaction opens - which market, which canonical
 * number, which multiplier, what potential payout, which wallet, which bet type -
 * is answered here, OUTSIDE the transaction, so that the critical section between
 * the wallet row lock and the commit performs writes only. Holding a row lock while
 * resolving configuration or re-reading a draw lengthens the lock for no reason and
 * makes deadlocks and lock timeouts more likely.
 *
 * WHY THE WALLET IS CARRIED BUT ITS BALANCE IS NOT
 * The wallet is carried so its PRIMARY KEY is known before the transaction opens;
 * that is all this object's wallet is used for. Its balance columns are stale by
 * definition - they were read without a lock - and no step of the pipeline is
 * allowed to make a money decision from them. The authoritative balance is read from
 * the row returned by SELECT ... FOR UPDATE inside the transaction.
 *
 * WHY THE MULTIPLIER AND PAYOUT ARE HERE AT ALL
 * They are resolved by Phase 4.2 from config('lottery.payouts.multiplier_source'),
 * which is the project's single declared source for a rate. Carrying the resolved
 * values means the transaction never re-derives them, so the number written to
 * bet_items cannot drift from the number the risk engine reserved capacity against.
 *
 * IMMUTABILITY
 * Readonly throughout. Nothing in the transaction may adjust the stake, the number
 * or the multiplier after validation: a purchase must debit exactly what was
 * validated and reserved.
 */
final readonly class BetPurchaseContext
{
    /**
     * @param  BetPurchaseData  $data  the sanitised request
     * @param  User  $user  the resolved player
     * @param  Wallet  $wallet  the server-resolved spendable wallet; only its key is trusted
     * @param  Draw  $draw  the resolved draw
     * @param  string  $marketKey  a configured market key such as '3d_direct'
     * @param  BetMarket  $market  the market family the key decomposes into
     * @param  BetSide  $side  top or bottom, from the market definition
     * @param  BetSelectionType  $selectionType  direct, tod or run
     * @param  BetType  $betType  the persistence type, read from the market definition
     * @param  LotteryNumber  $number  the canonical, zero-padded number
     * @param  string  $position  the configured position of the market ('top' / 'bottom')
     * @param  BetAmount  $stake  the exact stake
     * @param  PayoutMultiplier  $multiplier  the rate resolved from the declared source
     * @param  Money  $potentialPayout  stake x multiplier, exact
     * @param  BetCalculationResult  $calculation  the verbatim Phase 4.2 calculation
     * @param  BetValidationResult  $validation  the verbatim Phase 4.2 accepted verdict
     * @param  string  $betIdempotencyKey  the key stored on bets.idempotency_key
     * @param  string  $financialIdempotencyKey  the key handed to the Phase 2.1 wallet engine
     * @param  list<string>  $permutations  for Tod, the distinct arrangements this ONE
     *                                      selection covers; informational only, never
     *                                      a list of separate charges
     * @param  array<string, mixed>  $riskPreview  the read-only Phase 3.1 assessment
     */
    public function __construct(
        public BetPurchaseData $data,
        public User $user,
        public Wallet $wallet,
        public Draw $draw,
        public string $marketKey,
        public BetMarket $market,
        public BetSide $side,
        public BetSelectionType $selectionType,
        public BetType $betType,
        public LotteryNumber $number,
        public string $position,
        public BetAmount $stake,
        public PayoutMultiplier $multiplier,
        public Money $potentialPayout,
        public BetCalculationResult $calculation,
        public BetValidationResult $validation,
        public string $betIdempotencyKey,
        public string $financialIdempotencyKey,
        public array $permutations = [],
        public array $riskPreview = [],
    ) {
    }

    public function userId(): int
    {
        return (int) $this->user->getKey();
    }

    /**
     * The only wallet fact this object is trusted for.
     */
    public function walletId(): int
    {
        return (int) $this->wallet->getKey();
    }

    public function drawId(): int
    {
        return (int) $this->draw->getKey();
    }

    public function currency(): Currency
    {
        return $this->stake->currency();
    }

    /**
     * The stake as Money, for the wallet and risk engines.
     */
    public function stakeMoney(): Money
    {
        return $this->stake->money();
    }

    /**
     * The canonical number as a string, always zero-padded, never numeric.
     */
    public function canonicalNumber(): string
    {
        return $this->number->value();
    }

    /**
     * True when this selection is a 3D Tod selection.
     *
     * Tod is the one market where a single selection covers several arrangements of
     * the same digits. It remains ONE selection: one bet, one bet item, one stake,
     * one debit, one ledger movement, one ticket. This accessor exists so the
     * pipeline can record the arrangement count as metadata, not so it can branch
     * into creating several charges.
     */
    public function isTod(): bool
    {
        return $this->selectionType === BetSelectionType::Tod;
    }

    /**
     * How many distinct arrangements this one selection covers.
     *
     * 1 for every market except Tod. For Tod it is 6 for three distinct digits
     * ('123'), 3 when two digits repeat ('112') and 1 when all three repeat ('111').
     * It is written to metadata for transparency and is NEVER used as a multiplier of
     * the stake or of the number of rows created.
     */
    public function permutationCount(): int
    {
        return $this->permutations === [] ? 1 : count($this->permutations);
    }

    /**
     * The number of logical selections this purchase creates. Always exactly one.
     *
     * Kept as a named method rather than a literal at the call sites so that the
     * "one selection means one bet item" rule is stated in one place.
     */
    public function totalNumbers(): int
    {
        return 1;
    }

    /**
     * Diagnostic metadata persisted on the bet, the bet item and the ticket.
     *
     * Everything here is derived server-side. The client's own metadata is merged in
     * under a separate 'client' key so it can never overwrite a derived value.
     *
     * @return array<string, mixed>
     */
    public function metadataForPersistence(): array
    {
        return [
            'phase' => '4.3',
            'market' => $this->marketKey,
            'market_family' => $this->market->value,
            'selection_type' => $this->selectionType->value,
            'side' => $this->side->value,
            'position' => $this->position,
            'bet_type' => $this->betType->value,
            'number' => $this->number->value(),
            'digits' => $this->number->digits(),
            'stake' => $this->stake->amount(),
            'payout_multiplier' => $this->multiplier->value(),
            'potential_payout' => $this->potentialPayout->toString(),
            'exact_product' => $this->calculation->exactProduct,
            'rounded_up' => $this->calculation->wasRounded,
            'permutation_count' => $this->permutationCount(),
            'permutations' => $this->permutations,
            'logical_selections' => $this->totalNumbers(),
            'idempotency_key' => $this->betIdempotencyKey,
            'ignored_client_fields' => $this->data->ignoredClientFields,
            'client' => $this->data->metadata,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'request' => $this->data->toArray(),
            'user_id' => $this->userId(),
            'wallet_id' => $this->walletId(),
            'draw_id' => $this->drawId(),
            'market' => $this->marketKey,
            'bet_type' => $this->betType->value,
            'side' => $this->side->value,
            'selection_type' => $this->selectionType->value,
            'position' => $this->position,
            'number' => $this->number->value(),
            'stake' => $this->stake->amount(),
            'currency' => $this->currency()->value,
            'payout_multiplier' => $this->multiplier->value(),
            'potential_payout' => $this->potentialPayout->toString(),
            'permutation_count' => $this->permutationCount(),
            'bet_idempotency_key' => $this->betIdempotencyKey,
            'financial_idempotency_key' => $this->financialIdempotencyKey,
            'risk_preview' => $this->riskPreview,
        ];
    }
}
