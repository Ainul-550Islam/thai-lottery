<?php

declare(strict_types=1);

namespace App\Services\Lottery;

use App\Models\DrawResult;
use App\Models\WinningNumber;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Maps a draw's recorded result onto the GLO official prize ladder.
 *
 * WHY THIS EXISTS INSTEAD OF READING COLUMNS DIRECTLY
 * The draw_results / winning_numbers schema was designed for the online-operator
 * markets (first prize plus a metadata-carried bottom two). The GLO's official
 * ladder has more tiers — fourth, fifth, front three, last three, last two, and the
 * derived adjacent numbers — which have no dedicated columns. This service is the
 * single reader that knows where each tier lives:
 *
 *   first          draw_results.first_prize
 *   second         draw_results.second_prize (json array)
 *   third          draw_results.third_prize  (json array)
 *   fourth/fifth   draw_results.metadata JSON under config('glo.tiers.metadata_key')
 *   front/last 3/2 draw_results.metadata JSON under the same key
 *   adjacent       derived from first prize, +/- 1, as digit strings
 *
 * The metadata key is configurable (config('glo.tiers.metadata_key'), default 'glo'),
 * so a different operator record layout is a configuration change, not a code change.
 *
 * PURE READER
 * No write, no lifecycle change, no money. Every number is returned as a string so
 * leading zeroes survive.
 */
class GloResultService
{
    public function __construct(private readonly ConfigRepository $config) {}

    /**
     * All recorded numbers for a draw, keyed by GloPrizeTier backing value plus
     * 'first_prize'. Empty arrays signal "not recorded", never an error.
     *
     * @return array<string, mixed>
     */
    public function recordedForDraw(int $drawId): array
    {
        $result = DrawResult::query()->where('draw_id', $drawId)->first();

        if ($result === null) {
            return $this->emptyPayload($drawId, false);
        }

        $metadata = is_array($result->metadata) ? $result->metadata : [];
        $tierKey = $this->metadataKey();
        $tiers = is_array($metadata[$tierKey] ?? null) ? $metadata[$tierKey] : [];

        $firstPrize = (string) $result->first_prize;

        $payload = [
            'draw_id' => $drawId,
            'has_result' => true,
            'first_prize' => $firstPrize,
            'first' => $firstPrize !== '' ? [$firstPrize] : [],
            'second' => $this->stringList($result->second_prize),
            'third' => $this->stringList($result->third_prize),
            'fourth' => $this->tierList($tiers, ['fourth', 'fourth_prize', 'fourth_numbers']),
            'fifth' => $this->tierList($tiers, ['fifth', 'fifth_prize', 'fifth_numbers']),
            'front_three' => $this->tierList($tiers, ['front_three', 'front_3', 'three_front']),
            'last_three' => $this->tierList($tiers, ['last_three', 'last_3', 'three_back']),
            'last_two' => $this->tierList($tiers, ['last_two', 'last_2', 'two_back']),
            'adjacent_first' => $firstPrize !== '' ? $this->adjacentOf($firstPrize) : [],
        ];

        // A recorded winning_numbers row augments the ladder with any tier that the
        // publication flow wrote, so a market-derived tier is visible too even when
        // the metadata structure is absent.
        foreach ($this->winningRows($drawId) as $row) {
            $tier = (string) ($row['prize_tier'] ?? '');
            $number = (string) ($row['number'] ?? '');

            if ($tier === '' || $number === '') {
                continue;
            }

            $payload[$tier] = array_values(array_unique(array_merge(
                (array) ($payload[$tier] ?? []),
                [$number],
            )));
        }

        return $payload;
    }

    /**
     * Whether a draw has a recorded result at all.
     */
    public function hasResult(int $drawId): bool
    {
        return DrawResult::query()->where('draw_id', $drawId)->exists();
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyPayload(int $drawId, bool $hasResult): array
    {
        return [
            'draw_id' => $drawId,
            'has_result' => $hasResult,
            'first_prize' => '',
            'second' => [],
            'third' => [],
            'fourth' => [],
            'fifth' => [],
            'front_three' => [],
            'last_three' => [],
            'last_two' => [],
            'adjacent_first' => [],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function winningRows(int $drawId): array
    {
        return WinningNumber::query()
            ->where('draw_id', $drawId)
            ->get(['prize_tier', 'number'])
            ->map(static fn (WinningNumber $row): array => [
                'prize_tier' => $row->prize_tier,
                'number' => $row->number,
            ])
            ->all();
    }

    /**
     * Read a tier list from the metadata payload under any of several keys.
     *
     * @param  array<string, mixed>  $tiers
     * @param  list<string>  $keys
     * @return list<string>
     */
    private function tierList(array $tiers, array $keys): array
    {
        foreach ($keys as $key) {
            if (isset($tiers[$key])) {
                return $this->stringList($tiers[$key]);
            }
        }

        return [];
    }

    /**
     * Normalise a json-array, string or comma-joined value into a list of digit strings.
     *
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (is_string($value)) {
            $value = [$value];
        }

        if (! is_array($value)) {
            return [];
        }

        $list = [];

        foreach ($value as $item) {
            if (is_string($item)) {
                $list[] = $item;
            } elseif (is_int($item)) {
                $list[] = (string) $item;
            }
        }

        return $list;
    }

    /**
     * The metadata key under which the extra GLO tiers are recorded.
     */
    public function metadataKey(): string
    {
        $key = $this->config->get('glo.tiers.metadata_key');

        return is_string($key) && $key !== '' ? $key : 'glo';
    }

    /**
     * The two neighbours of a 6-digit first prize, as digit strings.
     *
     * @return list<string>
     */
    private function adjacentOf(string $firstPrize): array
    {
        $width = 6;
        // Scale 0 explicit — see GloTicketChecker::adjacentOf for why.
        $below = bcsub($firstPrize, '1', 0);
        $above = bcadd($firstPrize, '1', 0);
        $neighbours = [];

        if (bccomp($below, '0') >= 0) {
            $neighbours[] = sprintf('%0'.$width.'s', $below);
        }

        if (bccomp($above, bcpow('10', '6', 0)) < 0) {
            $neighbours[] = sprintf('%0'.$width.'s', $above);
        }

        return $neighbours;
    }
}
