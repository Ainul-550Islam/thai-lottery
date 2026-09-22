<?php

declare(strict_types=1);

namespace App\DTOs\Payout;

use App\Enums\Currency;

/**
 * Immutable fact-pack for ONE payout statement: the full account of a
 * completed payout's money journey — gross → tax → net — with its
 * references.
 *
 * WHAT ONE OBJECT NAMES
 * ---------------------
 * The statement data AS OF the payout's completion: which claim asserted
 * the prize, which payout obligation settled it, which batch executed it,
 * and the three money numbers the winner and regulator both read: the
 * GROSS (what was won), the TAX withheld (what was kept for the state's
 * share), and the NET (what actually moved to the claimant). Absent lanes
 * (no claim — auto-settled prize; no batch — wallet-internal credit)
 * arrive as NULLS, never as fabricated ids.
 *
 * DETERMINISTIC DOCUMENT KEY
 * --------------------------
 * The statement identity derives from the payout reference alone: one
 * payout, one lawful statement, forever — regardless of when the
 * statement job re-runs. So regeneration re-derives the SAME key, joins
 * the SAME document, and the invariant "no two statements for one payout"
 * holds by derivation, not by trust.
 *
 * MONEY INTEGRITY
 * ---------------
 * gross = tax + net is the structural invariant the statement service
 * RE-PROVES while composing: bcmath equality, else the statement refuses
 * to exist (a statement that misstates its own identity equation is
 * worse than none — an audit would reconcile a lie).
 *
 * Decimals never go through float anywhere below.
 */
class PayoutStatementData
{
    /**
     * @param  string|null  $claimReference  The claim's own reference when a
     *                                      claim asserted the prize; null
     *                                      for auto-credited digital prizes.
     * @param  string|null  $batchReference  Key of the batch that executed
     *                                      this payout; null for wallet-
     *                                      internal credits without one.
     * @param  string  $completedAt  ISO-8601 moment the payout completed —
     *                              the fact-time the statement cites.
     * @param  array<string, mixed>  $context  Composition context the
     *                                        statement job adds (run tag,
     *                                        source rows seen); never PII.
     */
    public function __construct(
        public readonly string $payoutReference,
        public readonly ?string $claimReference,
        public readonly ?string $batchReference,
        public readonly string $grossAmount,
        public readonly string $taxAmount,
        public readonly string $netAmount,
        public readonly Currency $currency,
        public readonly int $claimantUserId,
        public readonly string $completedAt,
        public readonly array $context = [],
    ) {
    }

    /**
     * The document identity for a payout: one payout, one statement.
     */
    public static function deriveDocumentKey(string $payoutReference): string
    {
        return hash('sha256', sprintf('payout-statement:%s', $payoutReference));
    }

    public function documentKey(): string
    {
        return self::deriveDocumentKey($this->payoutReference);
    }

    /**
     * The money strings' contract: gross = tax + net, bcmath-exact.
     */
    public function moneyIsConsistent(): bool
    {
        if (! $this->amountsAreWellFormed()) {
            return false;
        }

        $recomputed = bcadd(
            bcadd($this->taxAmount, '0.00', 2),
            bcadd($this->netAmount, '0.00', 2),
            2,
        );

        return bccomp($recomputed, bcadd($this->grossAmount, '0.00', 2), 2) === 0;
    }

    public function amountsAreWellFormed(): bool
    {
        foreach ([$this->grossAmount, $this->taxAmount, $this->netAmount] as $amount) {
            if (preg_match('/^\d+(\.\d{1,2})?$/', $amount) !== 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'document_key' => $this->documentKey(),
            'payout_reference' => $this->payoutReference,
            'claim_reference' => $this->claimReference,
            'batch_reference' => $this->batchReference,
            'gross_amount' => bcadd($this->grossAmount, '0.00', 2),
            'tax_amount' => bcadd($this->taxAmount, '0.00', 2),
            'net_amount' => bcadd($this->netAmount, '0.00', 2),
            'currency' => $this->currency->value,
            'claimant_user_id' => $this->claimantUserId,
            'completed_at' => $this->completedAt,
            'context' => $this->context,
        ];
    }
}
