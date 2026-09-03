<?php

declare(strict_types=1);

namespace App\Services\Betting;

use App\Enums\MarketResultType;
use App\Exceptions\MarketResultUnavailableException;
use App\Models\Draw;
use App\Models\DrawResult;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Reads the canonical winning value a market is settled against.
 *
 * WHAT THE EXISTING SCHEMA ACTUALLY OFFERS
 * Inspected before implementing:
 *
 *   draws            id, draw_number, status, betting_open_at, ... (no result value)
 *   draw_results     draw_id, first_prize (string), second_prize, third_prize,
 *                    consolation_prizes, all_numbers, metadata (JSON, cast to array),
 *                    published_at
 *   winning_numbers  draw_id, bet_type, number (string), tier, payout_multiplier
 *
 * There is NO bottom_two column anywhere. The three winning values are therefore
 * resolved as:
 *
 *   three digit top   substr(draw_results.first_prize, -3), as a string
 *   two digit top     substr(draw_results.first_prize, -2), as a string
 *   two digit bottom  draw_results.metadata[config('lottery.results.bottom_two_metadata_key')]
 *
 * The metadata path is the project's own declared abstraction: the key name is
 * already configured as 'bottom_two' in config('lottery.results.bottom_two_metadata_key').
 * No migration is created, no column is invented and no additional column is added.
 *
 * SCHEMA LIMITATION, REPORTED NOT PAPERED OVER
 * When the metadata key is absent, or holds anything other than two digit
 * characters, App\Exceptions\MarketResultUnavailableException is thrown with the
 * SCHEMA_LIMITATION code. No fake value, no zero, no empty string and no padded
 * integer is ever returned. bottomTwoAvailability() lets a report state the
 * situation for a specific draw without catching an exception.
 *
 * STRINGS ONLY
 * first_prize is cast to string by the model and is sliced with substr, so a first
 * prize of '100007' yields '007' for the three digit top and '07' for the two digit
 * top. Nothing is cast to int, so a leading zero can never be lost.
 *
 * DELIBERATE NON-RESPONSIBILITIES
 * - No match decision and no payout. Those belong to the match services and to
 *   App\Services\Betting\MarketPayoutService.
 * - No writes of any kind: no result is created, updated or published here, and no
 *   wallet, ledger, bet, ticket or payout row is touched.
 */
class MarketResultResolver
{
    public function __construct(
        private readonly ConfigRepository $config,
        private readonly MarketRuleResolver $rules,
    ) {}

    /**
     * The canonical winning value for a market in a draw, as a digit string.
     *
     * @throws MarketResultUnavailableException
     * @throws \App\Exceptions\InvalidMarketRuleException
     */
    public function resolve(int $drawId, string $marketKey): string
    {
        $rule = $this->rules->resolve($marketKey);

        return $this->resolveForType($drawId, $rule->resultType());
    }

    /**
     * The canonical winning value of a given result type, as a digit string.
     *
     * @throws MarketResultUnavailableException
     */
    public function resolveForType(int $drawId, MarketResultType $resultType): string
    {
        $result = $this->resultFor($drawId);

        return match ($resultType) {
            MarketResultType::ThreeDigitTop => $this->trailingDigitsOfFirstPrize($drawId, $result, 3),
            MarketResultType::TwoDigitTop => $this->trailingDigitsOfFirstPrize($drawId, $result, 2),
            MarketResultType::TwoDigitBottom => $this->bottomTwo($drawId, $result),
        };
    }

    /**
     * The winning value, or null when it cannot be resolved.
     *
     * For callers that want to report an absent result rather than handle a throw.
     */
    public function tryResolve(int $drawId, string $marketKey): ?string
    {
        try {
            return $this->resolve($drawId, $marketKey);
        } catch (\App\Exceptions\MarketRuleException) {
            return null;
        }
    }

    /**
     * Whether a market's winning value can be resolved for a draw.
     */
    public function canResolve(int $drawId, string $marketKey): bool
    {
        return $this->tryResolve($drawId, $marketKey) !== null;
    }

    /**
     * Every resolvable winning value of a draw, keyed by result type.
     *
     * Unresolvable types are reported as null rather than omitted, so a caller can
     * see that the bottom number is missing instead of guessing why.
     *
     * @return array<string, string|null>
     */
    public function allForDraw(int $drawId): array
    {
        $values = [];

        foreach (MarketResultType::cases() as $resultType) {
            try {
                $values[$resultType->value] = $this->resolveForType($drawId, $resultType);
            } catch (MarketResultUnavailableException) {
                $values[$resultType->value] = null;
            }
        }

        return $values;
    }

    /**
     * A precise statement about the bottom two number of one draw.
     *
     * @return array{draw_id: int, column_exists: bool, metadata_key: string, available: bool, value: string|null, reason: string}
     */
    public function bottomTwoAvailability(int $drawId): array
    {
        $metadataKey = $this->bottomTwoMetadataKey();

        try {
            $value = $this->resolveForType($drawId, MarketResultType::TwoDigitBottom);

            return [
                'draw_id' => $drawId,
                'column_exists' => false,
                'metadata_key' => $metadataKey,
                'available' => true,
                'value' => $value,
                'reason' => 'resolved from draw_results.metadata',
            ];
        } catch (MarketResultUnavailableException $exception) {
            return [
                'draw_id' => $drawId,
                'column_exists' => false,
                'metadata_key' => $metadataKey,
                'available' => false,
                'value' => null,
                'reason' => $exception->getMessage(),
            ];
        }
    }

    /**
     * Where each result type is read from, for the change report.
     *
     * @return array<string, array{result_type: string, digits: int, storage: string, dedicated_column: bool}>
     */
    public function describeSources(): array
    {
        $sources = [];

        foreach (MarketResultType::cases() as $resultType) {
            $sources[$resultType->value] = [
                'result_type' => $resultType->value,
                'digits' => $resultType->digits(),
                'storage' => $resultType->storageDescription(),
                'dedicated_column' => ! $resultType->isMetadataBacked(),
            ];
        }

        return $sources;
    }

    /**
     * Guarantees this resolver makes, for the change report.
     *
     * @return array<string, string>
     */
    public function guarantees(): array
    {
        return [
            'no_fake_value' => 'An absent or malformed result throws MarketResultUnavailableException; no '
                .'default, zero or padded value is ever returned.',
            'no_migration' => 'No column is created and no schema change is made; the bottom number is read '
                .'from the metadata key the project already declares.',
            'strings_only' => 'first_prize is sliced with substr as a string, so 100007 yields 007 and 07.',
            'read_only' => 'No row is created, updated, published or deleted here.',
        ];
    }

    /**
     * The draw_results row of a draw, required to exist and, when configuration
     * demands it, to be published.
     *
     * @throws MarketResultUnavailableException
     */
    private function resultFor(int $drawId): DrawResult
    {
        $draw = Draw::query()->whereKey($drawId)->first();

        if (! $draw instanceof Draw) {
            throw MarketResultUnavailableException::drawNotFound($drawId);
        }

        $result = $draw->result;

        if (! $result instanceof DrawResult) {
            throw MarketResultUnavailableException::noResult($drawId);
        }

        if ($this->requiresPublishedResult() && ! $result->isPublished()) {
            throw MarketResultUnavailableException::notPublished($drawId);
        }

        return $result;
    }

    /**
     * The trailing digits of the first prize, as a string.
     *
     * @throws MarketResultUnavailableException
     */
    private function trailingDigitsOfFirstPrize(int $drawId, DrawResult $result, int $digits): string
    {
        $firstPrize = $result->first_prize;

        if (! is_string($firstPrize) || $firstPrize === '') {
            throw MarketResultUnavailableException::firstPrizeMissing($drawId);
        }

        if (preg_match('/^[0-9]+$/', $firstPrize) !== 1 || strlen($firstPrize) < $digits) {
            throw MarketResultUnavailableException::firstPrizeUnusable($drawId, $firstPrize, $digits);
        }

        return substr($firstPrize, -$digits);
    }

    /**
     * The two digit bottom number, read from the metadata JSON.
     *
     * @throws MarketResultUnavailableException
     */
    private function bottomTwo(int $drawId, DrawResult $result): string
    {
        $metadataKey = $this->bottomTwoMetadataKey();
        $metadata = $result->metadata;

        if (! is_array($metadata) || ! array_key_exists($metadataKey, $metadata)) {
            throw MarketResultUnavailableException::bottomTwoMissing($drawId, $metadataKey);
        }

        $value = $metadata[$metadataKey];

        if (! is_string($value)) {
            throw MarketResultUnavailableException::bottomTwoMalformed(
                $drawId,
                $metadataKey,
                $this->describeValue($value),
            );
        }

        if (preg_match('/^[0-9]{2}$/', $value) !== 1) {
            throw MarketResultUnavailableException::bottomTwoMalformed($drawId, $metadataKey, $value);
        }

        return $value;
    }

    /**
     * The configured metadata key that carries the bottom number.
     */
    private function bottomTwoMetadataKey(): string
    {
        $key = $this->config->get('lottery.results.bottom_two_metadata_key');

        return is_string($key) && $key !== '' ? $key : 'bottom_two';
    }

    /**
     * Whether an unpublished result may be read.
     */
    private function requiresPublishedResult(): bool
    {
        return $this->config->get('lottery.payouts.require_published_result') === true;
    }

    /**
     * A safe printable description of a non-string metadata value.
     *
     * Deliberately does NOT convert the value into a candidate result: an integer
     * 7 in the JSON is described as "integer 7" and refused, never padded to '07'
     * and treated as authoritative.
     */
    private function describeValue(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'boolean true' : 'boolean false',
            is_int($value) => 'integer '.$value,
            is_float($value) => 'non-integer number',
            is_array($value) => 'array',
            default => 'unsupported value type',
        };
    }
}
