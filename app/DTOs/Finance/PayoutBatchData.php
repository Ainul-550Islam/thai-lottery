<?php

declare(strict_types=1);

namespace App\DTOs\Finance;

use App\Enums\Currency;

/**
 * Immutable definition of a payout BATCH at creation time.
 *
 * WHAT THIS OBJECT NAMES
 * ----------------------
 * Everything that decides a batch's identity and one money fact it claims:
 * WHICH payout obligations are in (their reference numbers), in WHICH
 * currency, with WHAT aggregate amount, under WHICH deterministic batch
 * key.
 *
 * WHY THE BATCH KEY IS DERIVED, NEVER MINTED
 * ------------------------------------------
 * A random id would let the same queue message, run twice, manufacture two
 * batches over the same payouts. The batch key instead derives by sha256
 * over (ordered member references + currency): identical content always
 * derives the same key, so a replay lands back on the same batch record —
 * and two DIFFERENT intentions (different member sets or currencies) can
 * never collide on one.
 *
 * AGGREGATE VERIFICATION
 * ----------------------
 * The creator claims an aggregate amount. PayoutBatchService recomputes the
 * sum from the member rows themselves and compares bcmath-exact: a naming
 * mismatch refutes creation. The aggregate is part of the creation data
 * because it lets the audit answer "what did the generator THINK this batch
 * was worth" independently of "what did the rows contain".
 *
 * Decimals never go through float anywhere below.
 */
class PayoutBatchData
{
    /**
     * @param  list<string>  $payoutReferences  Ordered reference numbers of
     *                                          the member payouts. Order is
     *                                          canonicalized (sorted) before
     *                                          any derivation — the caller's
     *                                          list order must not affect the
     *                                          batch's identity.
     * @param  string  $aggregateAmount  The claimed 2-decimal total of the
     *                                  member payouts, e.g. '12500.00'.
     * @param  string|null  $note  Operator-visible creation rationale. Never
     *                             a credential, secret, or personal detail
     *                             beyond what the references already name.
     * @param  array<string, mixed>  $context  Safe diagnostic context (run tag,
     *                                         generator minute, source lane).
     */
    public function __construct(
        public readonly array $payoutReferences,
        public readonly string $aggregateAmount,
        public readonly Currency $currency,
        public readonly ?string $note,
        public readonly array $context = [],
    ) {
    }

    /**
     * The deterministic batch key for a member set in a currency.
     *
     * Derivation: sha256('payout-batch:' + sorted(references) + currency).
     * Sorting is part of the definition: two callers passing the same
     * members in different orders derive the same key and therefore the
     * same batch.
     *
     * @param  list<string>  $references
     */
    public static function deriveBatchKey(array $references, Currency $currency): string
    {
        $sorted = array_values($references);
        sort($sorted, SORT_STRING);

        return hash('sha256', sprintf(
            'payout-batch:%s:%s',
            implode('|', $sorted),
            $currency->value,
        ));
    }

    /**
     * This batch's own key, on the instance.
     */
    public function batchKey(): string
    {
        return self::deriveBatchKey($this->payoutReferences, $this->currency);
    }

    /**
     * The claimed total, bcmath-canonicalized to exactly two decimals.
     * Never a float at any step; the ctor stores whatever string it was
     * given and the canonical form is computed at derivation/verify time.
     */
    public function canonicalAggregate(): string
    {
        return bcadd($this->aggregateAmount, '0.00', 2);
    }

    /**
     * Is the aggregate string well-formed (digits with at most 2 decimals)?
     */
    public function aggregateIsWellFormed(): bool
    {
        return preg_match('/^\d+(\.\d{1,2})?$/', $this->aggregateAmount) === 1;
    }

    /**
     * Batches never carry an empty member list: a zero-member batch is a
     * generator bug, not a business object.
     */
    public function hasMembers(): bool
    {
        return $this->payoutReferences !== [];
    }

    /**
     * The creation data in projection form for rows, audits and logs.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'batch_key' => $this->batchKey(),
            'payout_references' => $this->canonicalReferences(),
            'member_count' => count($this->payoutReferences),
            'aggregate_amount' => $this->canonicalAggregate(),
            'currency' => $this->currency->value,
            'note' => $this->note,
            'context' => $this->context,
        ];
    }

    /**
     * @return list<string>
     */
    public function canonicalReferences(): array
    {
        $sorted = array_values($this->payoutReferences);
        sort($sorted, SORT_STRING);

        return $sorted;
    }
}
