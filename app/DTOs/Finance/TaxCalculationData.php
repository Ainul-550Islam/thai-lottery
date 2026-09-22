<?php

declare(strict_types=1);

namespace App\DTOs\Finance;

use App\Enums\Currency;

/**
 * Immutable INPUT to a prize-tax calculation.
 *
 * WHAT ONE OBJECT NAMES
 * ---------------------
 * The full basis of one tax computation over one prize obligation: the
 * prize amount, the taxable amount (which may differ when part of the prize
 * is exempt), the currency, and the context the rule engine reads (payout
 * reference, payout method, claimant type, draw id). Everything is input
 * only — the ANSWER a computation produces (rate applied, tax withheld,
 * net paid) belongs to the calculation record the service stamps, not to
 * this object.
 *
 * WHY A DTO AND NOT SIX SCALARS
 * -----------------------------
 * Tax math is where silent float and silent unit conversions creep in.
 * Pinning the basis into one immutable shape keeps every calculator lane
 * (the service, the job, the replay validator) reading the same inputs and
 * deriving the same key.
 *
 * THE CALCULATION KEY
 * -------------------
 * sha256 over (payout reference + prize amount + taxable amount + currency)
 * — the calculation's identity: the same payout may only ever have one
 * calculation per basis. When the basis legitimately changes (a corrected
 * prize amount), the key changes too, and the old record stays as evidence.
 *
 * Decimals never go through float anywhere below.
 */
class TaxCalculationData
{
    /**
     * @param  string  $payoutReference  Reference number of the payout the
     *                                  tax is computed against — the anchor.
     * @param  string  $prizeAmount  Gross prize as a 2-decimal string, e.g.
     *                              '900.00'.
     * @param  string  $taxableAmount  Taxable base as a 2-decimal string.
     *                                MUST NOT exceed the gross: a base above
     *                                the prize would charge the player for
     *                                money they never won.
     * @param  array<string, mixed>  $context  Safe rule-engine context:
     *                                        payout method, claimant kind,
     *                                        draw id, jurisdiction hints.
     *                                        Amounts NEVER arrive here.
     */
    public function __construct(
        public readonly string $payoutReference,
        public readonly string $prizeAmount,
        public readonly string $taxableAmount,
        public readonly Currency $currency,
        public readonly array $context = [],
    ) {
    }

    /**
     * The deterministic identity of one calculation basis.
     */
    public static function deriveCalculationKey(
        string $payoutReference,
        string $prizeAmount,
        string $taxableAmount,
        Currency $currency,
    ): string {
        return hash('sha256', sprintf(
            'tax-calculation:%s:%s:%s:%s',
            $payoutReference,
            bcadd($prizeAmount, '0.00', 2),
            bcadd($taxableAmount, '0.00', 2),
            $currency->value,
        ));
    }

    public function calculationKey(): string
    {
        return self::deriveCalculationKey(
            $this->payoutReference,
            $this->prizeAmount,
            $this->taxableAmount,
            $this->currency,
        );
    }

    /**
     * Are both money strings well-formed decimals (≤ 2 places, no signs)?
     */
    public function amountsAreWellFormed(): bool
    {
        return preg_match('/^\d+(\.\d{1,2})?$/', $this->prizeAmount) === 1
            && preg_match('/^\d+(\.\d{1,2})?$/', $this->taxableAmount) === 1;
    }

    /**
     * The one structural arithmetic invariant anyone can check cheaply:
     * taxable may never exceed gross.
     */
    public function taxableWithinPrize(): bool
    {
        return bccomp(
            bcadd($this->taxableAmount, '0.00', 2),
            bcadd($this->prizeAmount, '0.00', 2),
            2,
        ) <= 0;
    }

    /**
     * A zero-taxable basis waives cleanly without any rate lookup.
     */
    public function taxableIsZero(): bool
    {
        return bccomp(bcadd($this->taxableAmount, '0.00', 2), '0.00', 2) === 0;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'calculation_key' => $this->calculationKey(),
            'payout_reference' => $this->payoutReference,
            'prize_amount' => bcadd($this->prizeAmount, '0.00', 2),
            'taxable_amount' => bcadd($this->taxableAmount, '0.00', 2),
            'currency' => $this->currency->value,
            'context' => $this->context,
        ];
    }
}
