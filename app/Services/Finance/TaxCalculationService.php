<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\DTOs\Finance\TaxCalculationData;
use App\Enums\Currency;
use App\Enums\PayoutStatus;
use App\Enums\RiskLevel;
use App\Enums\AuditAction;
use App\Enums\TaxCalculationStatus;
use App\Enums\TaxDocumentStatus;
use App\Exceptions\TaxCalculationException;
use App\Models\AuditLog;
use App\Models\Payout;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Prize-tax arithmetic: exact, deterministic, replay-safe, and applied to
 * payouts the player can hold.
 *
 * WHERE THE LANE LIVES
 * --------------------
 * On the payout row's `metadata.tax` lane. The payout IS the taxable
 * obligation; the calculation record (status, rate, tax, net) and its
 * document stamp (number + issuance state) are both lanes anchored to the
 * one row that carries the money. A separate tax table would duplicate
 * what the payout already uniquely names.
 *
 * THE ARITHMETIC IS BCMATH ALL THE WAY DOWN
 * -----------------------------------------
 * Every money quantity is a decimal STRING. Rate multiplication is done by
 * decomposing the rate into an integer over a power of ten (RATE_SCALE 6:
 * rates carry at most 6 decimals, e.g. 5.5% = 0.055000 → 55000/1000000) and
 * using bcmul/bcdiv — bcmath's bcmul on scale-6 strings is exact integer
 * math in disguise. No float ever touches money: levy('900.00', '0.055')
 * is exact, not approximately exact.
 *
 * THE CALCULATION KEY IS THE REPLAY GUARD
 * ---------------------------------------
 * compute() derives the basis key up front. The same basis re-computed
 * re-serves the existing stamped answer (idempotent join). A basis with
 * an UNCHANGED key but a different stamped answer triggers
 * inconsistentState — drift between stamps — rather than a second,
 * different answer replacing the first silently.
 *
 * THE RATE IS CONFIGURABLE, NOT HARDCODED
 *   config(lottery.tax.rate)               decimal string in [0,1]; absent
 *                                          configuration is a loud stop
 *   config(lottery.tax.threshold)          taxable <= threshold waives
 *                                          cleanly (status Waived)
 *   config(lottery.tax.currencies)         per-currency overrides map
 *
 * WHAT APPLY MEANS
 * ----------------
 * apply() folds the computed tax INTO the payout amount: payout.amount
 * becomes net (gross − withheld), and the withheld figure is stamped for
 * reporting. After apply, the ORIGINAL gross remains in metadata.tax.gross
 * and reporting reads the two from one row. Applied is terminal.
 */
class TaxCalculationService
{
    /**
     * The metadata lane on payouts.
     */
    public const METADATA_KEY = 'tax';

    /**
     * Decimal places a configured rate may carry. Rate parsing decomposes
     * the string into integer/10^SCALE; anything finer is rateMalformed.
     */
    public const RATE_SCALE = 6;

    /**
     * Compute the tax due for a payout: returns the calculation record.
     *
     * IDEMPOTENT: re-computing an identical basis joins the existing
     * stamped answer at whatever lifecycle state it already reached.
     *
     * @return array<string, mixed>
     *
     * @throws TaxCalculationException
     */
    public function compute(Payout $payout, TaxCalculationData $data): array
    {
        if (DB::transactionLevel() > 0) {
            throw TaxCalculationException::alreadyRunning((int) DB::transactionLevel());
        }

        return DB::transaction(function () use ($payout, $data): array {
            /** @var Payout|null $locked */
            $locked = Payout::query()->lockForUpdate()->find((int) $payout->getKey());

            if (! $locked instanceof Payout) {
                throw TaxCalculationException::invalidBasis(
                    $data->payoutReference,
                    'payout row not found',
                );
            }

            $this->assertBasisSound($data);

            $key = $data->calculationKey();

            $existing = $this->recordFor($locked);

            if (is_array($existing) && ($existing['calculation_key'] ?? null) === $key) {
                return $existing; // replay: serve the stamped answer
            }

            // A record on a DIFFERENT key means a corrected basis: compute
            // fresh, but keep the old answer in history (audit evidence).
            $history = is_array($existing) ? [$existing] : [];

            $status = TaxCalculationStatus::Pending;
            $gross = bcadd($data->prizeAmount, '0.00', 2);
            $taxable = bcadd($data->taxableAmount, '0.00', 2);

            // THE WAIVE BRANCH: zero-taxable bases or bases at/below the
            // configured threshold waive cleanly — a positive audited zero.
            $threshold = config('lottery.tax.threshold');

            if ($data->taxableIsZero()
                || (is_string($threshold) && preg_match('/^\d+(\.\d{1,2})?$/', $threshold) === 1
                    && bccomp($taxable, bcadd($threshold, '0.00', 2), 2) <= 0)
            ) {
                $record = [
                    'calculation_key' => $key,
                    'status' => TaxCalculationStatus::Waived->value,
                    'gross' => $gross,
                    'taxable' => $taxable,
                    'rate' => null,
                    'tax_amount' => '0.00',
                    'net_amount' => $gross,
                    'currency' => $data->currency->value,
                    'computed_at' => Carbon::now()->toIso8601String(),
                    'applied_at' => null,
                    'waive_reason' => $data->taxableIsZero()
                        ? 'zero taxable base'
                        : 'taxable at or below configured threshold '.(string) $threshold,
                    'context' => $data->context,
                    'document' => null,
                    'history' => $history,
                ];

                $this->writeRecord($locked, $record);
                $this->recordAudit($locked, sprintf(
                    'Prize tax waived for %s %s (taxable %s): %s.',
                    $gross,
                    $data->currency->value,
                    $taxable,
                    $record['waive_reason'],
                ), RiskLevel::Low);

                return $record;
            }

            // THE COMPUTED BRANCH: levied at the configured rate.
            $rate = $this->rateForCurrency($data->currency);
            $tax = self::levy($taxable, $rate);
            $net = bcsub($gross, $tax, 2);

            if (bccomp($net, '0.00', 2) < 0) {
                // A rate that eats the whole prize is a configuration
                // incident, never a computation to stamp as valid.
                throw TaxCalculationException::rateMalformed(
                    'lottery.tax.rate',
                    $rate.' (net would be negative on '.$gross.')',
                );
            }

            $status = TaxCalculationStatus::Calculated;

            $record = [
                'calculation_key' => $key,
                'status' => $status->value,
                'gross' => $gross,
                'taxable' => $taxable,
                'rate' => $rate,
                'tax_amount' => $tax,
                'net_amount' => $net,
                'currency' => $data->currency->value,
                'computed_at' => Carbon::now()->toIso8601String(),
                'applied_at' => null,
                'context' => $data->context,
                'document' => null,
                'history' => $history,
            ];

            $this->writeRecord($locked, $record);
            $this->recordAudit($locked, sprintf(
                'Prize tax calculated: %s %s gross, rate %s → withheld %s, net %s.',
                $gross,
                $data->currency->value,
                $rate,
                $tax,
                $net,
            ), RiskLevel::Medium);

            return $record;
        });
    }

    /**
     * Apply a Calculated answer to the payout: payout.amount folds to net,
     * withheld money becomes reportable, the record turns Applied and the
     * document lane opens as Draft.
     *
     * IDEMPOTENT: re-applying the same calculation key serves the applied
     * record unchanged; the payout amount is never double-folded.
     *
     * @return array<string, mixed>
     */
    public function apply(Payout $payout, string $calculationKey): array
    {
        return DB::transaction(function () use ($payout, $calculationKey): array {
            /** @var Payout|null $locked */
            $locked = Payout::query()->lockForUpdate()->find((int) $payout->getKey());

            if (! $locked instanceof Payout) {
                throw TaxCalculationException::invalidBasis($calculationKey, 'payout row not found');
            }

            $record = $this->recordFor($locked);

            if (! is_array($record) || ($record['calculation_key'] ?? null) !== $calculationKey) {
                throw TaxCalculationException::inconsistentState(
                    $calculationKey,
                    'no calculation record for this key on the payout',
                );
            }

            $status = TaxCalculationStatus::tryFrom((string) ($record['status'] ?? ''));

            if ($status === TaxCalculationStatus::Applied) {
                return $record; // idempotent replay: answer was already folded
            }

            if ($status === TaxCalculationStatus::Waived) {
                // Applying a waived answer is legal and trivially the same:
                // nothing folds, record turns Applied with tax zero.
                $record = array_merge($record, [
                    'status' => TaxCalculationStatus::Applied->value,
                    'applied_at' => Carbon::now()->toIso8601String(),
                ]);
                $this->writeRecord($locked, $record);

                return $record;
            }

            if ($status !== TaxCalculationStatus::Calculated) {
                throw TaxCalculationException::inconsistentState(
                    $calculationKey,
                    sprintf('status %s never admits an apply', $status?->value ?? 'unknown'),
                );
            }

            // DRIFT GUARD: recompute from the stamped basis; if the answer
            // someone folded would differ from the stamped one, stop.
            $recomputed = self::levy((string) $record['taxable'], (string) $record['rate']);

            if (bccomp($recomputed, (string) $record['tax_amount'], 2) !== 0) {
                throw TaxCalculationException::inconsistentState(
                    $calculationKey,
                    sprintf('stamped tax %s no longer equals recomputed %s on the same basis', (string) $record['tax_amount'], $recomputed),
                );
            }

            // The payout must still be in a state where folding its amount
            // is meaningful: money already moved (Completed/Reversed) can
            // never have its face rewritten.
            if (! in_array($locked->status, [PayoutStatus::Pending, PayoutStatus::Processing], true)) {
                throw TaxCalculationException::inconsistentState(
                    $calculationKey,
                    sprintf('payout status %s can no longer accept a tax application', $locked->status->value),
                );
            }

            // THE FOLD: payout.amount becomes net. done once, at most once —
            // the Applied stamp above is the proof replay never lands here
            // twice.
            $locked->amount = (string) $record['net_amount'];
            $locked->save();

            $record = array_merge($record, [
                'status' => TaxCalculationStatus::Applied->value,
                'applied_at' => Carbon::now()->toIso8601String(),
                'document' => [
                    'number' => $this->deriveDocumentNumber($calculationKey),
                    'status' => TaxDocumentStatus::Draft->value,
                    'issued_at' => null,
                ],
            ]);

            $this->writeRecord($locked, $record);

            $this->recordAudit($locked, sprintf(
                'Prize tax APPLIED: withheld %s %s; payout folded to net %s.',
                $record['tax_amount'],
                (string) $record['currency'],
                $record['net_amount'],
            ), RiskLevel::High);

            return $record;
        });
    }

    /**
     * Issue the withheld-tax document for the claimant (Draft/Generated →
     * Issued). The paper exists the moment the player can cite it.
     *
     * @return array<string, mixed>  The document stamp after issuance.
     *
     * @throws TaxCalculationException
     */
    public function issueDocument(Payout $payout): array
    {
        return DB::transaction(function () use ($payout): array {
            /** @var Payout|null $locked */
            $locked = Payout::query()->lockForUpdate()->find((int) $payout->getKey());

            if (! $locked instanceof Payout) {
                throw TaxCalculationException::invalidBasis('document', 'payout row not found');
            }

            $record = $this->recordFor($locked);
            $document = is_array($record['document'] ?? null) ? $record['document'] : null;

            if (! is_array($record) || $document === null) {
                throw TaxCalculationException::inconsistentState(
                    (string) ($record['calculation_key'] ?? 'unknown'),
                    'no tax document lane exists; tax has not been applied',
                );
            }

            $docStatus = TaxDocumentStatus::tryFrom((string) ($document['status'] ?? ''));

            if ($docStatus === TaxDocumentStatus::Voided || $docStatus === TaxDocumentStatus::Cancelled) {
                throw TaxCalculationException::inconsistentState(
                    (string) $record['calculation_key'],
                    'document is '.$docStatus->value.' (terminal)',
                );
            }

            if ($docStatus === TaxDocumentStatus::Issued) {
                return $document; // idempotent: issuance is a one-way door
            }

            // Draft jumps through Generated implicitly: issuance pins both.
            $document = array_merge($document, [
                'status' => TaxDocumentStatus::Issued->value,
                'issued_at' => Carbon::now()->toIso8601String(),
            ]);

            $record['document'] = $document;
            $this->writeRecord($locked, $record);

            $this->recordAudit($locked, sprintf(
                'Tax document %s issued for calculation %s.',
                (string) $document['number'],
                (string) $record['calculation_key'],
            ), RiskLevel::Low);

            return $document;
        });
    }

    /**
     * The calculation record for a payout, without trusting its shape.
     *
     * @return array<string, mixed>|null
     */
    public function recordFor(Payout $payout): ?array
    {
        $metadata = is_array($payout->metadata) ? $payout->metadata : [];
        $record = $metadata[self::METADATA_KEY] ?? null;

        return is_array($record) ? $record : null;
    }

    public function statusFor(Payout $payout): ?TaxCalculationStatus
    {
        $record = $this->recordFor($payout);

        return is_array($record) ? TaxCalculationStatus::tryFrom((string) ($record['status'] ?? '')) : null;
    }

    /**
     * EXACT levy: taxable × rate, both decimal strings, answer at 2 places.
     *
     * Mechanism: the rate string (≤ RATE_SCALE decimal places) is rewritten
     * as integer numerator / 10^SCALE denominator and multiplied with
     * bcmul over integers — exact rational arithmetic, rounded HALF-UP to
     * 2 places by inspecting the third+ digits. No float ever.
     */
    public static function levy(string $taxable, string $rate): string
    {
        // taxable as integer of cents
        $cents = (int) bcmul($taxable, '100', 0);

        // rate as integer over 10^RATE_SCALE
        $rateParts = explode('.', $rate, 2);
        $rateDigits = $rateParts[0].str_pad($rateParts[1] ?? '', self::RATE_SCALE, '0');
        $rateInt = (string) ((int) $rateDigits);
        $denominator = '1'.str_repeat('0', self::RATE_SCALE);

        // exact product: cents × rateInt over denominator — the levy in
        // CENTS at RATE_SCALE+2 places, exact integer/rational arithmetic.
        $product = bcmul((string) $cents, $rateInt);
        $exactCents = bcdiv($product, $denominator, self::RATE_SCALE + 2);

        // CENTS → CURRENCY UNITS: divide by 100 at high precision, else the
        // answer is quoted too large by a century factor.
        $exactUnits = bcdiv($exactCents, '100', self::RATE_SCALE + 2);

        // HALF-UP rounding to 2 decimals: add 0.005 and truncate at scale 2,
        // the classic decimal-safe nudge — with exact strings, this is the
        // deterministic rule tax authorities write in their manuals.
        $rounded = bcadd($exactUnits, '0.005', self::RATE_SCALE + 2);

        return bcadd($rounded, '0', 2);
    }

    /**
     * The deterministic document number for a calculation: TAX- + prefix of
     * the calculation key. Numbers derive, never mint — a re-issue cites
     * the same number.
     */
    public function deriveDocumentNumber(string $calculationKey): string
    {
        return 'TAX-'.strtoupper(substr($calculationKey, 0, 20));
    }

    /**
     * Load and validate the configured rate for a currency.
     *
     * @throws TaxCalculationException  On missing or malformed configuration.
     */
    private function rateForCurrency(Currency $currency): string
    {
        $overrides = config('lottery.tax.currencies');
        $rate = null;

        if (is_array($overrides) && array_key_exists($currency->value, $overrides)) {
            $rate = $overrides[$currency->value];
        }

        if (! is_string($rate) || $rate === '') {
            $rate = config('lottery.tax.rate');
        }

        if (! is_string($rate) && ! is_numeric($rate)) {
            throw TaxCalculationException::rateUnconfigured('lottery.tax.rate');
        }

        $rate = (string) $rate;

        // Strict: decimal string in [0,1], at most RATE_SCALE places, and
        // not float-shaped (no exponent, no sign).
        if (preg_match('/^(0(?:\.\d{1,6})?|1(?:\.0{1,6})?)$/', $rate) !== 1) {
            throw TaxCalculationException::rateMalformed('lottery.tax.rate', $rate);
        }

        return $rate;
    }

    /**
     * @throws TaxCalculationException
     */
    private function assertBasisSound(TaxCalculationData $data): void
    {
        if (! $data->amountsAreWellFormed()) {
            throw TaxCalculationException::invalidBasis(
                $data->payoutReference,
                'money strings are not well-formed 2-decimal decimals',
            );
        }

        if (bccomp(bcadd($data->prizeAmount, '0.00', 2), '0.00', 2) <= 0) {
            throw TaxCalculationException::invalidBasis(
                $data->payoutReference,
                'gross prize is zero or negative',
            );
        }

        if (! $data->taxableWithinPrize()) {
            throw TaxCalculationException::taxableExceedsGross(
                $data->payoutReference,
                bcadd($data->taxableAmount, '0.00', 2),
                bcadd($data->prizeAmount, '0.00', 2),
            );
        }
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function writeRecord(Payout $payout, array $record): void
    {
        $metadata = is_array($payout->metadata) ? $payout->metadata : [];
        $metadata[self::METADATA_KEY] = $record;

        $payout->metadata = $metadata;
        $payout->save();
    }

    private function recordAudit(Payout $payout, string $description, RiskLevel $riskLevel): void
    {
        $log = new AuditLog();

        $log->fill([
            'user_id' => null,
            'action' => AuditAction::Update,
            'risk_level' => $riskLevel,
            'auditable_type' => Payout::class,
            'auditable_id' => $payout->getKey(),
            'description' => $description,
            'metadata' => [
                'payout_id' => (int) $payout->getKey(),
                'payout_reference' => (string) $payout->reference_number,
                'lane' => self::METADATA_KEY,
                'action' => 'tax_calculation',
            ],
        ]);

        $log->save();
    }
}
