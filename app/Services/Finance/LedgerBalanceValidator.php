<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Enums\Currency;
use App\Enums\LedgerEntryType;
use App\Exceptions\FinancialException;
use App\Models\FinancialTransaction;

/**
 * Guards the fundamental accounting invariant: SUM(debits) === SUM(credits).
 *
 * Every posting is checked here BEFORE it is committed, so an unbalanced
 * transaction can never reach the database. The comparison is done with bcmath
 * on decimal strings at the currency scale; no float is involved, so two amounts
 * that look equal are equal.
 *
 * ENTRY SHAPE
 * A draft entry is an array. Only these keys are accepted, and unknown keys are
 * rejected rather than ignored, so a typo cannot silently drop a value:
 *
 *   ledger_account_id  int             required
 *   type               LedgerEntryType required (or the string 'debit'/'credit')
 *   amount             Money           required (or an exact decimal string)
 *   wallet_id          int|null        optional
 *   description        string|null     optional
 *   reference_type     string|null     optional
 *   reference_id       int|null        optional
 *   metadata           array|null      optional
 *
 * The validator performs no writes and no locking. It is deliberately usable on
 * a set of drafts that does not exist in the database yet.
 */
final class LedgerBalanceValidator
{
    private const ALLOWED_KEYS = [
        'ledger_account_id',
        'type',
        'amount',
        'wallet_id',
        'description',
        'reference_type',
        'reference_id',
        'metadata',
    ];

    /**
     * Validate a full posting and return the normalised entries.
     *
     * Checks, in order: the posting is not empty; each entry is structurally
     * valid; each amount is strictly positive and in the transaction currency;
     * both sides are represented; and the two sides sum to the same value.
     *
     * @param  array<int, array<string, mixed>>  $entries
     * @return list<array{
     *     ledger_account_id: int,
     *     type: LedgerEntryType,
     *     amount: Money,
     *     wallet_id: int|null,
     *     description: string|null,
     *     reference_type: string|null,
     *     reference_id: int|null,
     *     metadata: array<string, mixed>|null
     * }>
     *
     * @throws FinancialException
     */
    public function validate(array $entries, Currency $currency): array
    {
        Money::assertExactArithmeticIsAvailable();

        if ($entries === []) {
            throw FinancialException::withCode(
                'ledger_empty_posting',
                'A financial transaction must post at least one debit and one credit entry.',
                ['currency' => $currency->value],
            );
        }

        $normalised = [];

        foreach (array_values($entries) as $index => $entry) {
            $normalised[] = $this->normaliseEntry($entry, $currency, $index);
        }

        $this->assertBothSidesPresent($normalised);

        $totals = $this->totals($normalised, $currency);

        $this->assertBalanced($totals['debit'], $totals['credit']);

        return $normalised;
    }

    /**
     * Debit and credit totals for a set of normalised entries.
     *
     * @param  list<array{type: LedgerEntryType, amount: Money}>  $entries
     * @return array{debit: Money, credit: Money}
     *
     * @throws FinancialException
     */
    public function totals(array $entries, Currency $currency): array
    {
        $debit = Money::zero($currency);
        $credit = Money::zero($currency);

        foreach ($entries as $entry) {
            if ($entry['type'] === LedgerEntryType::Debit) {
                $debit = $debit->plus($entry['amount']);
            } else {
                $credit = $credit->plus($entry['amount']);
            }
        }

        return ['debit' => $debit, 'credit' => $credit];
    }

    /**
     * The two sides must agree exactly.
     *
     * config('finance.ledger.balance_tolerance') is honoured but ships as
     * '0.00', which means exact equality. A non-zero tolerance is a deliberate
     * accounting decision and must be configured explicitly; it is never assumed.
     *
     * @throws FinancialException
     */
    public function assertBalanced(Money $debitTotal, Money $creditTotal): void
    {
        $debitTotal->assertSameCurrency($creditTotal);

        $difference = $debitTotal->minus($creditTotal)->absolute();
        $tolerance = Money::of(
            (string) config('finance.ledger.balance_tolerance', '0.00'),
            $debitTotal->currency(),
        );

        if ($difference->isGreaterThan($tolerance)) {
            throw FinancialException::withCode(
                'ledger_unbalanced_posting',
                sprintf(
                    'Double-entry violation: debits total %s but credits total %s (difference %s %s).',
                    $debitTotal->toString(),
                    $creditTotal->toString(),
                    $debitTotal->currency()->value,
                    $difference->toString(),
                ),
                [
                    'currency' => $debitTotal->currency()->value,
                    'debit_total' => $debitTotal->toString(),
                    'credit_total' => $creditTotal->toString(),
                    'difference' => $difference->toString(),
                ],
            );
        }
    }

    /**
     * Verify a persisted transaction against its stored ledger entries.
     *
     * Used as a post-write assertion inside the posting transaction: if the rows
     * that actually reached the database do not balance, the surrounding
     * transaction is rolled back by the thrown exception.
     *
     * @throws FinancialException
     */
    public function assertTransactionBalanced(FinancialTransaction $transaction): void
    {
        Money::assertExactArithmeticIsAvailable();

        $currency = $transaction->currency;
        $debit = Money::zero($currency);
        $credit = Money::zero($currency);
        $count = 0;

        foreach ($transaction->ledgerEntries()->get() as $entry) {
            $count++;
            $amount = Money::fromDatabase((string) $entry->amount, $currency);

            if ($entry->isDebit()) {
                $debit = $debit->plus($amount);
            } else {
                $credit = $credit->plus($amount);
            }
        }

        if ($count === 0) {
            throw FinancialException::withCode(
                'ledger_transaction_has_no_entries',
                sprintf(
                    'Financial transaction %d has no ledger entries; a money movement '
                    .'without a double-entry posting is not permitted.',
                    (int) $transaction->getKey(),
                ),
                ['transaction_id' => (int) $transaction->getKey()],
            );
        }

        $this->assertBalanced($debit, $credit);
    }

    /**
     * Structural and monetary validation of a single draft entry.
     *
     * @param  array<string, mixed>  $entry
     * @return array{
     *     ledger_account_id: int,
     *     type: LedgerEntryType,
     *     amount: Money,
     *     wallet_id: int|null,
     *     description: string|null,
     *     reference_type: string|null,
     *     reference_id: int|null,
     *     metadata: array<string, mixed>|null
     * }
     *
     * @throws FinancialException
     */
    private function normaliseEntry(array $entry, Currency $currency, int $index): array
    {
        $unknown = array_diff(array_keys($entry), self::ALLOWED_KEYS);

        if ($unknown !== []) {
            throw FinancialException::withCode(
                'ledger_entry_unknown_keys',
                sprintf(
                    'Ledger entry #%d contains unsupported key(s): %s.',
                    $index,
                    implode(', ', $unknown),
                ),
                ['entry_index' => $index],
            );
        }

        $accountId = $entry['ledger_account_id'] ?? null;

        if (! is_int($accountId) || $accountId <= 0) {
            throw FinancialException::withCode(
                'ledger_entry_invalid_account',
                sprintf('Ledger entry #%d must reference a valid ledger account id.', $index),
                ['entry_index' => $index],
            );
        }

        $type = $entry['type'] ?? null;

        if (is_string($type)) {
            $type = LedgerEntryType::tryFrom($type);
        }

        if (! $type instanceof LedgerEntryType) {
            throw FinancialException::withCode(
                'ledger_entry_invalid_type',
                sprintf('Ledger entry #%d must be either a debit or a credit.', $index),
                ['entry_index' => $index],
            );
        }

        $amount = $entry['amount'] ?? null;

        if (is_string($amount) || is_int($amount)) {
            $amount = Money::of($amount, $currency);
        }

        if (! $amount instanceof Money) {
            throw FinancialException::withCode(
                'ledger_entry_invalid_amount',
                sprintf(
                    'Ledger entry #%d must carry a Money amount or an exact decimal string.',
                    $index,
                ),
                ['entry_index' => $index],
            );
        }

        if ($amount->currency() !== $currency) {
            throw FinancialException::withCode(
                'ledger_entry_currency_mismatch',
                sprintf(
                    'Ledger entry #%d is denominated in %s but the transaction is in %s.',
                    $index,
                    $amount->currency()->value,
                    $currency->value,
                ),
                [
                    'entry_index' => $index,
                    'entry_currency' => $amount->currency()->value,
                    'transaction_currency' => $currency->value,
                ],
            );
        }

        // The direction of a ledger entry is carried by `type`, never by the
        // sign of the amount, and the ledger_entries table has a CHECK
        // constraint requiring amount > 0.
        $amount->assertPositive(sprintf('amount of ledger entry #%d', $index));

        $walletId = $entry['wallet_id'] ?? null;
        $referenceId = $entry['reference_id'] ?? null;
        $description = $entry['description'] ?? null;
        $referenceType = $entry['reference_type'] ?? null;
        $metadata = $entry['metadata'] ?? null;

        if ($walletId !== null && (! is_int($walletId) || $walletId <= 0)) {
            throw FinancialException::withCode(
                'ledger_entry_invalid_wallet',
                sprintf('Ledger entry #%d has an invalid wallet id.', $index),
                ['entry_index' => $index],
            );
        }

        if ($referenceId !== null && (! is_int($referenceId) || $referenceId <= 0)) {
            throw FinancialException::withCode(
                'ledger_entry_invalid_reference',
                sprintf('Ledger entry #%d has an invalid reference id.', $index),
                ['entry_index' => $index],
            );
        }

        if ($description !== null && ! is_string($description)) {
            throw FinancialException::withCode(
                'ledger_entry_invalid_description',
                sprintf('Ledger entry #%d has a non-string description.', $index),
                ['entry_index' => $index],
            );
        }

        if ($referenceType !== null && ! is_string($referenceType)) {
            throw FinancialException::withCode(
                'ledger_entry_invalid_reference_type',
                sprintf('Ledger entry #%d has a non-string reference type.', $index),
                ['entry_index' => $index],
            );
        }

        if ($metadata !== null && ! is_array($metadata)) {
            throw FinancialException::withCode(
                'ledger_entry_invalid_metadata',
                sprintf('Ledger entry #%d has non-array metadata.', $index),
                ['entry_index' => $index],
            );
        }

        /** @var array<string, mixed>|null $metadata */
        return [
            'ledger_account_id' => $accountId,
            'type' => $type,
            'amount' => $amount,
            'wallet_id' => $walletId,
            'description' => $description,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'metadata' => $metadata,
        ];
    }

    /**
     * A posting with only debits or only credits is not double entry, even when
     * the totals happen to be zero.
     *
     * @param  list<array{type: LedgerEntryType, amount: Money}>  $entries
     *
     * @throws FinancialException
     */
    private function assertBothSidesPresent(array $entries): void
    {
        $hasDebit = false;
        $hasCredit = false;

        foreach ($entries as $entry) {
            if ($entry['type'] === LedgerEntryType::Debit) {
                $hasDebit = true;
            } else {
                $hasCredit = true;
            }
        }

        if (! $hasDebit || ! $hasCredit) {
            throw FinancialException::withCode(
                'ledger_single_sided_posting',
                'A double-entry posting requires at least one debit entry and at least one credit entry.',
                ['has_debit' => $hasDebit, 'has_credit' => $hasCredit],
            );
        }
    }
}
