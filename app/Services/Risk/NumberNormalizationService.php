<?php

declare(strict_types=1);

namespace App\Services\Risk;

use App\Enums\BetType;
use App\Exceptions\RiskConfigurationException;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Canonicalises a lottery number into the exact string form the database stores.
 *
 * LEADING ZEROS ARE DATA, NOT FORMATTING
 * '007' and '7' are different numbers in this domain. `number_limits.number` and
 * `bet_items.number` are both varchar(16), config('lottery.numbers') sets
 * store_as_string and zero_pad true, and App\Models\NumberLimit casts `number` to
 * string. Consequently this class never calls intval(), (int), (float), ltrim of
 * zeros, number_format, or any numeric cast on a lottery number. The only
 * transformations applied are: trim surrounding whitespace, and left-pad with '0'
 * to the configured digit count.
 *
 * SOURCE OF TRUTH FOR DIGIT COUNTS
 * config('lottery.types.<bet_type>.digits') is authoritative:
 *   2d 2 digits ('00'-'99'), 3d 3 digits ('000'-'999'),
 *   tod 3 digits ('000'-'999'), run 1 digit ('0'-'9')
 * Those values agree with config('lottery.markets') for all six sellable markets.
 *
 * KNOWN INCONSISTENCY — REPORTED, NOT SILENTLY FIXED
 * App\Enums\BetType::digits() returns 2 for Tod and 2 for Run, which contradicts
 * the configuration (3 and 1). BetType is outside the Phase 3.1 file list, so it
 * is NOT modified here. This service reads configuration and, when the two
 * disagree, throws RiskConfigurationException rather than picking a winner
 * quietly, because padding a 3-digit Tod number to 2 digits would corrupt it.
 * assertBetTypeDigitsAgreeWithConfig() performs that check explicitly.
 *
 * No guessing of unsupported lengths: a number whose length exceeds the configured
 * digit count is rejected, never truncated.
 */
class NumberNormalizationService
{
    /**
     * Digits only. No sign, no decimal point, no whitespace inside, no separators.
     */
    private const DIGITS_ONLY = '/^[0-9]+$/';

    /**
     * Hard ceiling from the varchar(16) columns that store a number.
     */
    private const COLUMN_LENGTH = 16;

    public function __construct(private readonly ConfigRepository $config)
    {
    }

    /**
     * Canonical zero-padded number for a bet type.
     *
     * '7' with 3d becomes '007'. '007' stays '007'. '099' stays '099'. '000' stays
     * '000' and is a perfectly valid number, never an empty or falsy value.
     *
     * @param  string  $number  raw submitted number
     *
     * @throws RiskConfigurationException when the number is malformed, empty, or
     *                                    longer than the bet type supports
     */
    public function normalize(string $number, BetType $betType): string
    {
        $digits = $this->digitsFor($betType);
        $raw = trim($number);

        if ($raw === '') {
            throw RiskConfigurationException::invalidNumber(
                'the number is empty',
                $number,
                $betType->value,
            );
        }

        if (preg_match(self::DIGITS_ONLY, $raw) !== 1) {
            throw RiskConfigurationException::invalidNumber(
                'the number must contain digits only',
                $raw,
                $betType->value,
            );
        }

        if (strlen($raw) > $digits) {
            throw RiskConfigurationException::invalidNumber(
                sprintf(
                    'the number has %d digits but %s accepts exactly %d; refusing to truncate it',
                    strlen($raw),
                    $betType->value,
                    $digits,
                ),
                $raw,
                $betType->value,
            );
        }

        $canonical = str_pad($raw, $digits, '0', STR_PAD_LEFT);

        $this->assertWithinConfiguredRange($canonical, $betType, $digits);

        return $canonical;
    }

    /**
     * Normalise without throwing, for callers that only want to test a candidate.
     */
    public function tryNormalize(string $number, BetType $betType): ?string
    {
        try {
            return $this->normalize($number, $betType);
        } catch (RiskConfigurationException) {
            return null;
        }
    }

    /**
     * True when the input is a valid number for the bet type.
     */
    public function isValid(string $number, BetType $betType): bool
    {
        return $this->tryNormalize($number, $betType) !== null;
    }

    /**
     * Normalise a list of numbers, preserving order and keeping duplicates.
     *
     * Duplicates are NOT removed: two selections of the same number are two
     * separate liabilities and the exposure engine must see both.
     *
     * @param  iterable<string>  $numbers
     * @return list<string>
     *
     * @throws RiskConfigurationException
     */
    public function normalizeAll(iterable $numbers, BetType $betType): array
    {
        $canonical = [];

        foreach ($numbers as $number) {
            $canonical[] = $this->normalize($number, $betType);
        }

        return $canonical;
    }

    /**
     * Digit count for a bet type, from configuration.
     *
     * @throws RiskConfigurationException when the type is absent, disabled, or has
     *                                    an unusable digit count
     */
    public function digitsFor(BetType $betType): int
    {
        $definition = $this->typeDefinition($betType);

        if (! array_key_exists('digits', $definition)) {
            throw RiskConfigurationException::missingKey(
                sprintf('lottery.types.%s.digits', $betType->value),
            );
        }

        $digits = $definition['digits'];

        if (! is_int($digits) || $digits < 1 || $digits > self::COLUMN_LENGTH) {
            throw RiskConfigurationException::invalidKey(
                sprintf('lottery.types.%s.digits', $betType->value),
                sprintf('the digit count must be an integer between 1 and %d', self::COLUMN_LENGTH),
                is_int($digits) || is_string($digits) ? $digits : null,
            );
        }

        return $digits;
    }

    /**
     * Whether the bet type is currently sellable per configuration.
     */
    public function isBetTypeEnabled(BetType $betType): bool
    {
        $definition = $this->typeDefinition($betType);

        return ($definition['enabled'] ?? false) === true;
    }

    /**
     * Reject a bet type that configuration has switched off.
     *
     * @throws RiskConfigurationException
     */
    public function assertBetTypeEnabled(BetType $betType): void
    {
        if (! $this->isBetTypeEnabled($betType)) {
            throw RiskConfigurationException::invalidKey(
                sprintf('lottery.types.%s.enabled', $betType->value),
                'this bet type is disabled and cannot be evaluated for risk',
                $betType->value,
            );
        }
    }

    /**
     * The lowest and highest canonical numbers for a bet type.
     *
     * @return array{min: string, max: string}
     *
     * @throws RiskConfigurationException
     */
    public function range(BetType $betType): array
    {
        $definition = $this->typeDefinition($betType);
        $digits = $this->digitsFor($betType);

        $min = $definition['min_number'] ?? null;
        $max = $definition['max_number'] ?? null;

        if (! is_string($min) || ! is_string($max)) {
            throw RiskConfigurationException::invalidKey(
                sprintf('lottery.types.%s.min_number', $betType->value),
                'the configured range must be a pair of zero-padded strings',
            );
        }

        if (strlen($min) !== $digits || strlen($max) !== $digits) {
            throw RiskConfigurationException::invalidKey(
                sprintf('lottery.types.%s.min_number', $betType->value),
                sprintf('the configured range must be %d digits wide to match the digit count', $digits),
            );
        }

        return ['min' => $min, 'max' => $max];
    }

    /**
     * Compare two canonical numbers as strings of equal length.
     *
     * Equal-length digit strings compare correctly with strcmp, which keeps the
     * comparison free of any numeric cast. Unequal lengths are a caller error:
     * both operands must already be canonical.
     *
     * @return int negative, zero or positive like strcmp
     *
     * @throws RiskConfigurationException
     */
    public function compare(string $a, string $b): int
    {
        if (strlen($a) !== strlen($b)) {
            throw RiskConfigurationException::invalidNumber(
                'two numbers of different digit widths cannot be compared',
                $a,
            );
        }

        return strcmp($a, $b);
    }

    /**
     * True when $needle appears in $haystack, comparing as strings.
     *
     * config('risk.blocked_numbers.compare_as_string') is true, and this is the
     * method that honours it: an in_array with loose comparison would make '007'
     * equal to '7' and to 7, so a strict string comparison is used instead.
     *
     * @param  iterable<mixed>  $haystack
     */
    public function containsNumber(iterable $haystack, string $needle): bool
    {
        foreach ($haystack as $candidate) {
            if (is_string($candidate) && $candidate === $needle) {
                return true;
            }

            // A configuration file edited by hand may hold 7 instead of '007'.
            // It is matched only after being padded to the needle's width, so the
            // canonical string form still governs the comparison.
            if (is_int($candidate)
                && str_pad((string) $candidate, strlen($needle), '0', STR_PAD_LEFT) === $needle) {
                return true;
            }
        }

        return false;
    }

    /**
     * Verify that App\Enums\BetType::digits() agrees with configuration.
     *
     * Reports the known Tod and Run discrepancy instead of resolving it silently.
     * Callers that need a hard guarantee call this before normalising; the engine
     * calls it once per assessment so drift surfaces as a refusal, not as a
     * corrupted number.
     *
     * @throws RiskConfigurationException on disagreement
     */
    public function assertBetTypeDigitsAgreeWithConfig(BetType $betType): void
    {
        $configured = $this->digitsFor($betType);
        $enumValue = $betType->digits();

        if ($configured !== $enumValue) {
            throw RiskConfigurationException::invalidKey(
                sprintf('lottery.types.%s.digits', $betType->value),
                sprintf(
                    'configuration says %d digits but App\Enums\BetType::digits() says %d; '
                    .'the two must agree before a number can be canonicalised safely',
                    $configured,
                    $enumValue,
                ),
                $configured,
            );
        }
    }

    /**
     * Bet types whose enum digit count disagrees with configuration.
     *
     * Used by the risk report to state the inconsistency precisely rather than in
     * prose.
     *
     * @return array<string, array{config: int, enum: int}>
     */
    public function digitCountDisagreements(): array
    {
        $disagreements = [];

        foreach (BetType::cases() as $betType) {
            try {
                $configured = $this->digitsFor($betType);
            } catch (RiskConfigurationException) {
                continue;
            }

            if ($configured !== $betType->digits()) {
                $disagreements[$betType->value] = [
                    'config' => $configured,
                    'enum' => $betType->digits(),
                ];
            }
        }

        return $disagreements;
    }

    /**
     * The configured definition block for a bet type.
     *
     * @return array<string, mixed>
     *
     * @throws RiskConfigurationException when the block is absent
     */
    private function typeDefinition(BetType $betType): array
    {
        $definition = $this->config->get(sprintf('lottery.types.%s', $betType->value));

        if (! is_array($definition) || $definition === []) {
            throw RiskConfigurationException::missingKey(
                sprintf('lottery.types.%s', $betType->value),
            );
        }

        /** @var array<string, mixed> $definition */
        return $definition;
    }

    /**
     * Confirm a canonical number sits inside the configured inclusive range.
     *
     * With equal digit widths this is a plain string comparison, so '000' and
     * '099' are handled without ever becoming integers.
     *
     * @throws RiskConfigurationException
     */
    private function assertWithinConfiguredRange(string $canonical, BetType $betType, int $digits): void
    {
        $range = $this->range($betType);

        if (strcmp($canonical, $range['min']) < 0 || strcmp($canonical, $range['max']) > 0) {
            throw RiskConfigurationException::invalidNumber(
                sprintf(
                    'the number is outside the configured range %s-%s for %s',
                    $range['min'],
                    $range['max'],
                    $betType->value,
                ),
                $canonical,
                $betType->value,
            );
        }

        if ($digits > self::COLUMN_LENGTH) {
            throw RiskConfigurationException::invalidNumber(
                'the configured digit count exceeds the width of the number column',
                $canonical,
                $betType->value,
            );
        }
    }
}
