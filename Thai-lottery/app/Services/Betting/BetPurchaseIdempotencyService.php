<?php

declare(strict_types=1);

namespace App\Services\Betting;

use App\DTOs\BetPurchaseData;
use App\DTOs\BetPurchaseResult;
use App\Enums\Currency;
use App\Exceptions\BetPurchaseIdempotencyException;
use App\Exceptions\FinancialException;
use App\Models\Bet;
use App\Models\BetItem;
use App\Models\FinancialTransaction;
use App\Models\LedgerEntry;
use App\Models\Ticket;
use App\Services\Finance\IdempotencyService;
use App\Services\Finance\Money;
use App\ValueObjects\BetAmount;
use Illuminate\Support\Facades\Schema;

/**
 * Request-level idempotency for bet purchases, enforced by the database.
 *
 * WHY THIS CLASS EXISTS ALONGSIDE App\Services\Finance\IdempotencyService AND DOES
 * NOT REPLACE IT
 * Phase 2.1's IdempotencyService owns idempotency for FINANCIAL TRANSACTIONS: it is
 * backed by the UNIQUE index on financial_transactions.idempotency_key and it knows
 * how to claim a key, detect a duplicate-key violation and decide whether a reuse is a
 * legitimate replay of the same movement. None of that is reimplemented here. What
 * this class adds is the PURCHASE dimension, which is strictly larger than the
 * financial one:
 *
 *   - A purchase's identity includes the draw, the market, the number and the stake.
 *     Two purchases can present identical wallet, amount and type - the only things
 *     the financial layer compares - while being bets on different numbers. Only a
 *     purchase-level comparison can refuse that.
 *   - A purchase creates rows the financial layer knows nothing about: the bet, the
 *     bet item and the ticket. Idempotency has to cover those too, or a retry could
 *     be correctly refused a second debit while still minting a second ticket.
 *
 * Key derivation, key normalisation and duplicate-violation detection are all
 * DELEGATED to the Phase 2.1 service, so both layers agree on what a legal key looks
 * like and there is exactly one implementation of each of those rules.
 *
 * WHY THE DATABASE ENFORCES THIS AND NOT A CACHE
 * The Phase 1 schema declares bets.idempotency_key as string(128) with a UNIQUE
 * index. That index is the enforcement: two concurrent processes attempting the same
 * key cannot both insert, regardless of cache state, shared or otherwise. A cache
 * could not make that promise - two application servers with independent array caches
 * would both see a miss, and a cache eviction between a retry would reopen the double
 * debit. The cache is not consulted anywhere in this file.
 *
 * TWO DERIVED KEYS, ONE CLIENT KEY
 * The client sends one key. Two derived keys are computed from it: one stored on
 * bets.idempotency_key and one handed to the wallet engine for
 * financial_transactions.idempotency_key. They are derived with different scopes so
 * they can never collide with each other, and both are deterministic functions of
 * (user, draw, client key) so a retry reproduces both exactly.
 *
 * WHY THE USER AND DRAW ARE MIXED INTO THE KEY
 * The stored key must be globally unique, but the guarantee the specification asks
 * for is "same user + same draw + same key". Mixing the user and draw into the digest
 * means two different players may use the identical client key - which they will,
 * since clients often use a UUID per device or a sequence per session - without one
 * player's request being mistaken for the other's replay.
 *
 * NUMBER DUPLICATION IS NOT REQUEST DUPLICATION
 * Nothing here keys on the number. Buying '123' twice with two different client keys
 * creates two bets, two debits and two tickets, because those are two requests.
 * Buying '123' twice with the SAME client key creates one bet, and the second call
 * returns it.
 */
final class BetPurchaseIdempotencyService
{
    /** Digest scope for the key stored on bets.idempotency_key. */
    public const BET_KEY_SCOPE = 'betpurchase';

    /** Digest scope for the key handed to the Phase 2.1 wallet engine. */
    public const FINANCIAL_KEY_SCOPE = 'betdebit';

    /** Shortest client key accepted before derivation. */
    public const MIN_CLIENT_KEY_LENGTH = 8;

    /** Longest client key accepted; matches the width of the database column. */
    public const MAX_CLIENT_KEY_LENGTH = 128;

    /**
     * Characters a client key may contain.
     *
     * Identical to the Phase 2.1 pattern so a key that is legal for a deposit is
     * legal for a bet. Restricting the alphabet keeps keys safe in logs, URLs and
     * index lookups without any escaping.
     */
    private const CLIENT_KEY_PATTERN = '/^[A-Za-z0-9_.:\-]+$/';

    public function __construct(
        private readonly IdempotencyService $idempotency,
    ) {
    }

    /**
     * Confirm the schema can actually enforce request-level idempotency.
     *
     * Called before the first purchase in a process rather than trusting a migration
     * to be present. If the column or the table is missing the purchase is refused
     * loudly, because the alternative - proceeding without enforcement - would silently
     * permit double debits on a retried request.
     *
     * @throws BetPurchaseIdempotencyException
     */
    public function assertSchemaSupportsIdempotency(): void
    {
        if (! Schema::hasTable('bets')) {
            throw BetPurchaseIdempotencyException::schemaChangeRequired(
                'the bets table does not exist, so no bet-level idempotency can be enforced',
            );
        }

        if (! Schema::hasColumn('bets', 'idempotency_key')) {
            throw BetPurchaseIdempotencyException::schemaChangeRequired(
                'bets.idempotency_key does not exist. Add a string(128) column with a UNIQUE '
                .'index before enabling bet purchases',
                ['table' => 'bets', 'column' => 'idempotency_key'],
            );
        }

        if (! Schema::hasColumn('financial_transactions', 'idempotency_key')) {
            throw BetPurchaseIdempotencyException::schemaChangeRequired(
                'financial_transactions.idempotency_key does not exist, so the wallet debit '
                .'cannot be made idempotent',
                ['table' => 'financial_transactions', 'column' => 'idempotency_key'],
            );
        }
    }

    /**
     * Validate the raw client key.
     *
     * @throws BetPurchaseIdempotencyException
     */
    public function assertClientKeyUsable(string $clientKey): string
    {
        $key = trim($clientKey);

        if ($key === '') {
            throw BetPurchaseIdempotencyException::keyRequired();
        }

        $length = strlen($key);

        if ($length < self::MIN_CLIENT_KEY_LENGTH || $length > self::MAX_CLIENT_KEY_LENGTH) {
            throw BetPurchaseIdempotencyException::keyInvalid(
                sprintf(
                    'it must be between %d and %d characters long, and this one is %d',
                    self::MIN_CLIENT_KEY_LENGTH,
                    self::MAX_CLIENT_KEY_LENGTH,
                    $length,
                ),
                ['key_length' => $length],
            );
        }

        if (preg_match(self::CLIENT_KEY_PATTERN, $key) !== 1) {
            throw BetPurchaseIdempotencyException::keyInvalid(
                'it may only contain letters, digits and the characters _ . : -',
                ['key_length' => $length],
            );
        }

        return $key;
    }

    /**
     * The key written to bets.idempotency_key.
     *
     * @throws BetPurchaseIdempotencyException
     */
    public function betKeyFor(BetPurchaseData $data): string
    {
        return $this->derive(self::BET_KEY_SCOPE, $data);
    }

    /**
     * The key handed to the Phase 2.1 wallet engine for the debit.
     *
     * @throws BetPurchaseIdempotencyException
     */
    public function financialKeyFor(BetPurchaseData $data): string
    {
        return $this->derive(self::FINANCIAL_KEY_SCOPE, $data);
    }

    /**
     * The bet that already owns a derived key, if any.
     *
     * Soft-deleted rows are included because the UNIQUE index covers them: a bet that
     * was cancelled and soft deleted still owns its key, and reporting the key as free
     * would produce an insert the database rejects.
     */
    public function findExistingBet(string $betKey): ?Bet
    {
        return Bet::withTrashed()
            ->where('idempotency_key', $betKey)
            ->first();
    }

    /**
     * Resolve a derived key against the request that presented it.
     *
     * Returns the existing bet when the key was used for MATERIALLY THE SAME purchase,
     * which is the replay case. Returns null when the key is unused. Throws when the
     * key was used for a different purchase, because replaying a bet the player did
     * not ask for is worse than refusing the request.
     *
     * @throws BetPurchaseIdempotencyException
     */
    public function resolve(string $betKey, BetPurchaseData $data): ?Bet
    {
        $existing = $this->findExistingBet($betKey);

        if (! $existing instanceof Bet) {
            return null;
        }

        $mismatched = $this->mismatchedFields($existing, $data);

        if ($mismatched !== []) {
            throw BetPurchaseIdempotencyException::payloadMismatch(
                $betKey,
                $mismatched,
                [
                    'existing_bet_id' => (int) $existing->getKey(),
                    'existing_bet_number' => (string) $existing->bet_number,
                    'request_fingerprint' => $data->fingerprint(),
                ],
            );
        }

        return $existing;
    }

    /**
     * Which parts of the request disagree with an existing bet under the same key.
     *
     * The number is compared against the bet's single item rather than against the bet,
     * because the number lives on bet_items in this schema. Comparison is by exact
     * string for the number - '007' must not equal '7' - and by exact decimal
     * comparison for the stake, so '10.00' and '10' are recognised as the same money
     * while 10.55 and 10.50 are not.
     *
     * @return list<string>
     */
    public function mismatchedFields(Bet $existing, BetPurchaseData $data): array
    {
        $mismatched = [];

        if ((int) $existing->user_id !== $data->userId) {
            $mismatched[] = 'user_id';
        }

        if ((int) $existing->draw_id !== $data->drawId) {
            $mismatched[] = 'draw_id';
        }

        $storedMarket = $this->storedMarketOf($existing);

        if ($storedMarket !== null && $storedMarket !== $data->marketKey) {
            $mismatched[] = 'market';
        }

        $storedNumber = $this->storedNumberOf($existing);

        if ($storedNumber !== null && ! $this->numberMatches($storedNumber, $data->rawNumber)) {
            $mismatched[] = 'number';
        }

        if (! $this->stakeMatches((string) $existing->stake_amount, $data->rawStake, $this->currencyOf($existing)->scale())) {
            $mismatched[] = 'stake';
        }

        return $mismatched;
    }

    /**
     * Build the replay result for an already committed bet.
     *
     * Everything is read back from the database rather than reconstructed, so the
     * caller receives the rows that actually exist. A bet whose companion rows cannot
     * be read is refused instead of being reported as a partially populated success:
     * that condition means the earlier purchase did not commit as a whole and needs
     * investigation, not a replay.
     *
     * @throws BetPurchaseIdempotencyException
     * @throws FinancialException
     */
    public function replayResultFor(Bet $bet): BetPurchaseResult
    {
        $betId = (int) $bet->getKey();

        $item = BetItem::query()
            ->where('bet_id', $betId)
            ->orderBy('id')
            ->first();

        if (! $item instanceof BetItem) {
            throw BetPurchaseIdempotencyException::replayIncomplete($betId, 'bet item');
        }

        $ticketId = $bet->ticket_id;

        if ($ticketId === null) {
            throw BetPurchaseIdempotencyException::replayIncomplete($betId, 'ticket link');
        }

        $ticket = Ticket::query()->whereKey((int) $ticketId)->first();

        if (! $ticket instanceof Ticket) {
            throw BetPurchaseIdempotencyException::replayIncomplete($betId, 'ticket');
        }

        $transaction = $this->debitFor($bet);

        if (! $transaction instanceof FinancialTransaction) {
            throw BetPurchaseIdempotencyException::replayIncomplete($betId, 'wallet debit');
        }

        $currency = $this->currencyOf($bet);

        return BetPurchaseResult::replayed(
            bet: $bet,
            item: $item,
            ticket: $ticket,
            transaction: $transaction,
            stake: BetAmount::fromDatabase((string) $bet->stake_amount, $currency),
            potentialPayout: Money::fromDatabase((string) $bet->potential_payout, $currency),
            idempotencyKey: (string) $bet->idempotency_key,
            ledgerEntryIds: $this->ledgerEntryIdsFor($transaction),
            context: [
                'replay_of_bet_id' => $betId,
                'replay_of_transaction_id' => (int) $transaction->getKey(),
            ],
        );
    }

    /**
     * The wallet debit that paid for a bet.
     *
     * Located through the polymorphic reference the purchase writes onto the financial
     * transaction, not through a stored transaction id, because bets carry no
     * financial_transaction_id column in this schema.
     */
    public function debitFor(Bet $bet): ?FinancialTransaction
    {
        return FinancialTransaction::query()
            ->where('reference_type', Bet::class)
            ->where('reference_id', (int) $bet->getKey())
            ->orderBy('id')
            ->first();
    }

    /**
     * The ids of the double-entry rows a debit produced.
     *
     * @return list<int>
     */
    public function ledgerEntryIdsFor(FinancialTransaction $transaction): array
    {
        return LedgerEntry::query()
            ->where('financial_transaction_id', (int) $transaction->getKey())
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
    }

    /**
     * Whether a driver exception is the UNIQUE violation that arbitrates a race.
     *
     * Delegated to Phase 2.1 so both layers recognise a duplicate key identically.
     */
    public function isDuplicateKeyViolation(\Illuminate\Database\QueryException $exception): bool
    {
        return $this->idempotency->isDuplicateKeyViolation($exception);
    }

    /**
     * @throws BetPurchaseIdempotencyException
     */
    private function derive(string $scope, BetPurchaseData $data): string
    {
        $clientKey = $this->assertClientKeyUsable($data->idempotencyKey);

        $identifier = implode('|', [
            (string) $data->userId,
            (string) $data->drawId,
            $clientKey,
        ]);

        try {
            return $this->idempotency->deterministicKey($scope, $identifier);
        } catch (FinancialException $exception) {
            throw BetPurchaseIdempotencyException::keyInvalid(
                'the derived key was refused by the finance idempotency rules',
                ['scope' => $scope],
                $exception,
            );
        }
    }

    private function storedMarketOf(Bet $bet): ?string
    {
        $metadata = $bet->metadata;

        if (! is_array($metadata)) {
            return null;
        }

        $market = $metadata['market'] ?? null;

        return is_string($market) ? $market : null;
    }

    private function storedNumberOf(Bet $bet): ?string
    {
        $number = BetItem::query()
            ->where('bet_id', (int) $bet->getKey())
            ->orderBy('id')
            ->value('number');

        return is_string($number) ? $number : null;
    }

    /**
     * Exact string comparison, with the project's zero-padding policy applied to the
     * raw side only.
     *
     * A client that retried with '7' where it first sent '007' is treated as the same
     * request, because Phase 4.2's canonicalisation would produce '007' from both. A
     * client that retried with '070' is NOT, because that is a different number.
     */
    private function numberMatches(string $stored, string $raw): bool
    {
        if ($stored === $raw) {
            return true;
        }

        if (preg_match('/^\d+$/', $raw) !== 1) {
            return false;
        }

        if (strlen($raw) > strlen($stored)) {
            return false;
        }

        return $stored === str_pad($raw, strlen($stored), '0', STR_PAD_LEFT);
    }

    /**
     * Exact decimal comparison. No float, no round.
     */
    private function stakeMatches(string $stored, string $raw, int $scale): bool
    {
        if ($stored === $raw) {
            return true;
        }

        if (! is_numeric($raw) || ! is_numeric($stored)) {
            return false;
        }

        // The comparison scale is the currency's own scale, taken from the enum so no
        // scale is hard-coded here. Comparing at a wider scale would report 10.001 and
        // 10.00 as different money even though the column cannot hold the difference.
        return bccomp($stored, $raw, $scale) === 0;
    }

    /**
     * The currency of a stored bet, tolerating an uncast raw column value.
     */
    private function currencyOf(Bet $bet): Currency
    {
        return $bet->currency instanceof Currency
            ? $bet->currency
            : Currency::from((string) $bet->currency);
    }
}
