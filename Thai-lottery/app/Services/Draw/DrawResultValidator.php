<?php

declare(strict_types=1);

namespace App\Services\Draw;

use App\DTOs\DrawResultData;
use App\Enums\MarketResultType;
use App\Exceptions\DrawResultValidationException;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Validates an official draw result before anything stores it.
 *
 * The only producer of App\DTOs\DrawResultData, which has a private constructor. A
 * result therefore cannot reach the database or the settlement engine without
 * passing through here.
 *
 * WHAT IS CHECKED
 *
 * 1. FIRST PRIZE. Exactly config('lottery.results.first_prize_digits') digits, which
 *    is 6 in this project, and nothing but the ASCII digits 0-9. The width is READ
 *    from configuration and not hard coded, so an operator running a different first
 *    prize width changes the setting rather than the code.
 *
 * 2. BOTTOM TWO. Exactly 2 digits. Independently drawn and NEVER derived from the
 *    first prize. Two is not read from configuration because it is structural: the
 *    verified App\Services\Betting\MarketResultResolver::bottomTwo() enforces
 *    /^[0-9]{2}$/ when reading the value back, and MarketResultType::TwoDigitBottom
 *    ->digits() is 2. Accepting a different width on write would produce a result
 *    that the verified reader then refuses.
 *
 * 3. THE THREE DIGIT AND TWO DIGIT TOP VALUES are DERIVED, never accepted from a
 *    caller, by substr() on the validated first prize. A caller supplying them is
 *    refused by assertNoDerivedFieldsSupplied().
 *
 * LEADING ZEROES SURVIVE, BECAUSE NOTHING IS COERCED
 * Values arrive and stay as strings. '007123' is validated as '007123' and yields
 * last three '123'; '100007' yields last three '007' and last two '07'. A caller
 * passing the INTEGER 7123 is refused outright rather than padded, because guessing
 * how many leading zeroes an operator meant would be inventing an official result.
 *
 * NO FLOAT AND NO NUMERIC CASTS ANYWHERE
 * This class contains no (int), (float), (double), intval(), floatval(), round(),
 * number_format(), sprintf('%d') or arithmetic operator applied to a result value.
 * The only operations on the values are strlen(), substr(), preg_match(), trim(),
 * is_string() and string comparison. The one integer in the class is the configured
 * digit WIDTH, which is a count and never a lottery value.
 *
 * DELIBERATE NON-RESPONSIBILITIES
 * - No persistence: DrawResultPublicationService writes.
 * - No lifecycle decision: DrawLifecycleService decides whether publication is
 *   allowed at all.
 * - NO MONEY. No multiplier, no prize amount, no wallet, no ledger.
 */
class DrawResultValidator
{
    /**
     * The bottom two number is structurally two digits.
     */
    public const BOTTOM_TWO_DIGITS = 2;

    /**
     * Fallback first prize width, used only when configuration holds nothing usable.
     */
    public const DEFAULT_FIRST_PRIZE_DIGITS = 6;

    /**
     * Only ASCII digits. Anchored, so a sign, a space, a decimal point, a separator
     * or a Unicode digit is refused.
     */
    private const DIGITS_ONLY = '/^[0-9]+$/';

    /**
     * Result fields a caller may supply.
     *
     * @var list<string>
     */
    public const ACCEPTED_FIELDS = [
        'first_prize',
        'bottom_two',
        'second_prize',
        'third_prize',
        'consolation_prizes',
        'all_numbers',
    ];

    /**
     * Fields a caller may never supply when publishing.
     *
     * These are all SERVER DERIVED. A caller who could set them could choose the
     * winners or the prize money, which requirement H forbids outright.
     *
     * @var list<string>
     */
    public const REFUSED_FIELDS = [
        'last_three',
        'last_two',
        'first_prize_last_three',
        'first_prize_last_two',
        'winning_numbers',
        'winners',
        'is_winner',
        'payout_multiplier',
        'multiplier',
        'actual_payout',
        'total_payout',
        'total_winners',
        'house_profit',
        'prize_amount',
        'simulated_prize',
        'force',
        'override',
        'bypass',
        'skip_validation',
    ];

    public function __construct(private readonly ConfigRepository $config) {}

    /**
     * Validate a submitted official result.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws DrawResultValidationException
     */
    public function validate(array $input): DrawResultData
    {
        $this->assertNoRefusedFieldsSupplied($input);

        $firstPrize = $this->validateFirstPrize($input['first_prize'] ?? null);
        $bottomTwo = $this->validateBottomTwo($input['bottom_two'] ?? null);

        return DrawResultData::fromValidated(
            $firstPrize,
            $bottomTwo,
            $this->firstPrizeDigits(),
            $this->optionalPrizeColumns($input),
            [
                'validated_by' => static::class,
                'first_prize_digits_source' => 'lottery.results.first_prize_digits',
            ],
        );
    }

    /**
     * Validate without throwing; null on any rejection.
     *
     * @param  array<string, mixed>  $input
     */
    public function tryValidate(array $input): ?DrawResultData
    {
        try {
            return $this->validate($input);
        } catch (DrawResultValidationException) {
            return null;
        }
    }

    /**
     * Validate a first prize and return it unchanged as a string.
     *
     * @throws DrawResultValidationException
     */
    public function validateFirstPrize(mixed $value): string
    {
        $expected = $this->firstPrizeDigits();

        if ($value === null || $value === '') {
            throw DrawResultValidationException::firstPrizeRequired([
                'expected_length' => $expected,
            ]);
        }

        if (! is_string($value)) {
            // An integer 7123 is REFUSED rather than padded to '007123'. Which
            // leading zeroes the operator meant is unknowable, and inventing them
            // would fabricate an official result.
            throw DrawResultValidationException::firstPrizeNotDigits(
                $this->describeNonString($value),
                [
                    'expected_length' => $expected,
                    'given_type' => get_debug_type($value),
                    'reason' => 'the first prize must be supplied as a string so leading zeroes are '
                        .'explicit; a numeric value is refused rather than zero padded',
                ],
            );
        }

        $raw = trim($value);

        if ($raw === '') {
            throw DrawResultValidationException::firstPrizeRequired([
                'expected_length' => $expected,
            ]);
        }

        if (preg_match(self::DIGITS_ONLY, $raw) !== 1) {
            throw DrawResultValidationException::firstPrizeNotDigits($raw, [
                'expected_length' => $expected,
            ]);
        }

        if (strlen($raw) !== $expected) {
            throw DrawResultValidationException::firstPrizeLength($raw, $expected);
        }

        return $raw;
    }

    /**
     * Validate a bottom two and return it unchanged as a string.
     *
     * @throws DrawResultValidationException
     */
    public function validateBottomTwo(mixed $value): string
    {
        if ($value === null || $value === '') {
            throw DrawResultValidationException::bottomTwoRequired([
                'expected_length' => self::BOTTOM_TWO_DIGITS,
                'metadata_key' => $this->bottomTwoMetadataKey(),
            ]);
        }

        if (! is_string($value)) {
            // An integer 7 is REFUSED, not padded to '07'. This matches the verified
            // MarketResultResolver, which describes a non-string metadata value and
            // refuses it rather than converting it.
            throw DrawResultValidationException::bottomTwoNotDigits(
                $this->describeNonString($value),
                [
                    'expected_length' => self::BOTTOM_TWO_DIGITS,
                    'given_type' => get_debug_type($value),
                    'reason' => 'the bottom two must be supplied as a string, so 07 is sent as "07" '
                        .'and never as the number 7',
                ],
            );
        }

        $raw = trim($value);

        if ($raw === '') {
            throw DrawResultValidationException::bottomTwoRequired([
                'expected_length' => self::BOTTOM_TWO_DIGITS,
            ]);
        }

        if (preg_match(self::DIGITS_ONLY, $raw) !== 1) {
            throw DrawResultValidationException::bottomTwoNotDigits($raw, [
                'expected_length' => self::BOTTOM_TWO_DIGITS,
            ]);
        }

        if (strlen($raw) !== self::BOTTOM_TWO_DIGITS) {
            throw DrawResultValidationException::bottomTwoLength($raw, self::BOTTOM_TWO_DIGITS);
        }

        return $raw;
    }

    /**
     * Whether a value would be accepted as a first prize.
     */
    public function firstPrizeIsValid(mixed $value): bool
    {
        try {
            $this->validateFirstPrize($value);

            return true;
        } catch (DrawResultValidationException) {
            return false;
        }
    }

    /**
     * Whether a value would be accepted as a bottom two.
     */
    public function bottomTwoIsValid(mixed $value): bool
    {
        try {
            $this->validateBottomTwo($value);

            return true;
        } catch (DrawResultValidationException) {
            return false;
        }
    }

    /**
     * Refuse when a caller supplied a server derived or override field.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws DrawResultValidationException
     */
    public function assertNoRefusedFieldsSupplied(array $input): void
    {
        foreach (self::REFUSED_FIELDS as $field) {
            if (array_key_exists($field, $input)) {
                throw DrawResultValidationException::unexpectedField($field, [
                    'accepted_fields' => implode(',', self::ACCEPTED_FIELDS),
                ]);
            }
        }
    }

    /**
     * The configured first prize width, in digits.
     *
     * Read from config('lottery.results.first_prize_digits'). A missing, non-integer
     * or non-positive setting falls back to DEFAULT_FIRST_PRIZE_DIGITS rather than
     * accepting an arbitrary width, and no configuration file is written by this
     * class.
     */
    public function firstPrizeDigits(): int
    {
        $configured = $this->config->get('lottery.results.first_prize_digits');

        if (is_int($configured) && $configured >= 3) {
            // At least 3, because the three digit top is a substr of the first prize
            // and a shorter first prize could not supply it.
            return $configured;
        }

        return self::DEFAULT_FIRST_PRIZE_DIGITS;
    }

    /**
     * The metadata key that carries the bottom two.
     *
     * Identical resolution to the verified MarketResultResolver, so the write path
     * and the read path cannot disagree about where the value lives.
     */
    public function bottomTwoMetadataKey(): string
    {
        $key = $this->config->get('lottery.results.bottom_two_metadata_key');

        return is_string($key) && $key !== '' ? $key : 'bottom_two';
    }

    /**
     * The validation rules in report form.
     *
     * @return array<string, mixed>
     */
    public function audit(): array
    {
        return [
            'first_prize' => [
                'digits' => $this->firstPrizeDigits(),
                'digits_source' => 'config(lottery.results.first_prize_digits)',
                'pattern' => self::DIGITS_ONLY,
                'stored_in' => 'draw_results.first_prize varchar(16), cast to string',
                'numeric_input_accepted' => false,
            ],
            'bottom_two' => [
                'digits' => self::BOTTOM_TWO_DIGITS,
                'digits_source' => 'structural: MarketResultType::TwoDigitBottom->digits()',
                'pattern' => self::DIGITS_ONLY,
                'stored_in' => sprintf(
                    'draw_results.metadata JSON key "%s"; there is NO bottom_two column and none was created',
                    $this->bottomTwoMetadataKey(),
                ),
                'derived_from_first_prize' => false,
                'numeric_input_accepted' => false,
            ],
            'derived_values' => [
                MarketResultType::ThreeDigitTop->value => 'substr(first_prize, -3), server derived',
                MarketResultType::TwoDigitTop->value => 'substr(first_prize, -2), server derived',
                MarketResultType::TwoDigitBottom->value => 'the supplied bottom two, not derived',
            ],
            'accepted_fields' => self::ACCEPTED_FIELDS,
            'refused_fields' => self::REFUSED_FIELDS,
            'guarantees' => $this->guarantees(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function guarantees(): array
    {
        return [
            'strings_only' => 'Result values are validated and returned as strings. There is no (int), '
                .'(float), (double), intval(), floatval(), round() or number_format() in this class.',
            'leading_zeroes_preserved' => 'A first prize of "007123" stays "007123" and a bottom two of '
                .'"07" stays "07". Nothing is trimmed of zeroes and nothing is re-padded.',
            'numeric_input_refused' => 'A numeric first prize or bottom two is refused rather than zero '
                .'padded, because the intended number of leading zeroes cannot be known.',
            'bottom_two_independent' => 'The bottom two is never derived from the first prize. They may '
                .'coincide by chance and that is reported, not corrected.',
            'derived_fields_refused' => 'A caller cannot supply the three digit top, the two digit top, '
                .'winning numbers, multipliers, winner flags or any prize amount.',
            'no_override' => 'force, override, bypass and skip_validation are refused as input fields.',
            'width_from_config' => 'The first prize width is read from '
                .'config(lottery.results.first_prize_digits) and is not hard coded.',
            'no_money' => 'No multiplier, prize amount, wallet, ledger, financial transaction or payout '
                .'is referenced anywhere in this class.',
        ];
    }

    /**
     * Optional official prize fields, keyed by the draw_results column they belong to.
     *
     * Only columns that actually exist on draw_results and are listed in
     * config('lottery.results.optional_fields') are carried through. An unknown key
     * is ignored rather than written, so no column is ever invented.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function optionalPrizeColumns(array $input): array
    {
        $configured = $this->config->get('lottery.results.optional_fields');
        $allowed = is_array($configured) ? $configured : [];

        $columns = [];

        foreach ($allowed as $field) {
            if (! is_string($field) || ! array_key_exists($field, $input)) {
                continue;
            }

            $columns[$field] = $input[$field];
        }

        return $columns;
    }

    /**
     * A safe printable description of a non-string submitted value.
     *
     * Deliberately does NOT render an integer as a candidate result: 7 is described
     * as "integer 7" and refused, never turned into '07' or '000007'.
     */
    private function describeNonString(mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'boolean true' : 'boolean false',
            is_int($value) => 'integer '.$value,
            is_float($value) => 'non-integer number',
            is_array($value) => 'array',
            $value === null => 'null',
            default => 'unsupported value type',
        };
    }
}
