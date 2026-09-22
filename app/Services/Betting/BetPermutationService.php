<?php

declare(strict_types=1);

namespace App\Services\Betting;

use App\DTOs\Betting\PermutationRequestData;
use App\DTOs\Betting\PermutationResultData;
use App\Enums\Currency;
use App\Exceptions\BetPurchaseException;

/**
 * Permutation ("tod"/กลับเลข) expansion and purchase.
 *
 * WHAT IT IS
 * A player who wants number 127 "in every order" is asking for six selections:
 * 127, 172, 217, 271, 712, 721. This service expands the base digits into the
 * unique arrangements and delegates the purchase to BulkBetService, so every
 * arrangement is an ordinary bet through the ordinary pipeline (the request
 * validator already restricts permutation to exact-order markets: a tod bet
 * already covers every arrangement itself, and a run bet has one digit).
 *
 * CANONICAL ORDERING (stable, documentation-grade)
 * The expansion is the set of DISTINCT arrangements of the source digits,
 * produced by a backtracking walk that skips a digit already used at the same
 * depth, then sorted ascending with a plain string compare. Repetition-free
 * sources of length n yield n! entries; repeated digits yield fewer ("112"
 * yields 112, 121, 211). Ordering is deterministic across runs, servers and
 * PHP versions, which is what slip-level idempotency relies on: retry the same
 * permutation and each item key resolves to the same purchase.
 */
class BetPermutationService
{
    public function __construct(
        private readonly PayoutMultiplierService $multipliers,
        private readonly BulkBetService $bulk,
    ) {
    }

    /**
     * Preview the expansion and the totals WITHOUT moving money.
     */
    public function preview(PermutationRequestData $data): PermutationResultData
    {
        $arrangements = $this->expand($data->digits);

        $multiplier = $this->multipliers->resolve($data->marketKey);
        $payoutPerWin = bcmul($data->stakePerArrangement, $multiplier->value, 2);
        $count = count($arrangements);

        $currency = config('lottery.currencies.default', 'THB');

        return new PermutationResultData(
            drawId: $data->drawId,
            marketKey: $data->marketKey,
            baseNumber: $data->digits,
            arrangements: $arrangements,
            arrangementCount: $count,
            stakePerArrangement: bcadd($data->stakePerArrangement, '0', 2),
            totalStake: bcmul($data->stakePerArrangement, (string) $count, 2),
            potentialPayoutPerWin: $payoutPerWin,
            maxPotentialPayout: bcmul($payoutPerWin, (string) $count, 2),
            currency: is_string($currency) ? $currency : Currency::THB->value,
        );
    }

    /**
     * Commit: expand, then purchase every arrangement as one slip.
     *
     * @return array<string, mixed>  the BulkBetService report
     */
    public function purchase(PermutationRequestData $data): array
    {
        $result = $this->preview($data);

        return $this->bulk->purchase(
            $data->userId,
            $data->drawId,
            $result->toSelections(),
            $data->clientKey,
        );
    }

    /**
     * The unique ascending arrangements of a digit string.
     *
     * @return list<string>
     *
     * @throws BetPurchaseException
     */
    public function expand(string $digits): array
    {
        if ($digits === '' || ! ctype_digit($digits)) {
            throw BetPurchaseException::invariantViolated('permutation_source_not_digits', [
                'source' => $digits,
            ]);
        }

        $arrangements = $this->uniquePermutations($digits);

        $cap = max(1, (int) config('lottery.bulk.max_items', 50));
        if (count($arrangements) > $cap) {
            throw BetPurchaseException::invariantViolated('permutation_count_above_cap', [
                'arrangements' => count($arrangements),
                'cap' => $cap,
            ]);
        }

        sort($arrangements, SORT_STRING);

        return array_values($arrangements);
    }

    /**
     * All distinct arrangements of the digit string (unsorted).
     *
     * @return list<string>
     */
    private function uniquePermutations(string $digits): array
    {
        $chars = str_split($digits);
        $count = count($chars);
        $result = [];
        $used = array_fill(0, $count, false);

        $walk = function (string $prefix) use (&$walk, &$result, &$used, $chars, $count): void {
            if (strlen($prefix) === $count) {
                $result[] = $prefix;

                return;
            }

            $seenAtThisDepth = [];
            for ($i = 0; $i < $count; $i++) {
                if ($used[$i]) {
                    continue;
                }

                $digit = $chars[$i];
                if (isset($seenAtThisDepth[$digit])) {
                    continue; // Same digit twice at one depth yields duplicates.
                }
                $seenAtThisDepth[$digit] = true;

                $used[$i] = true;
                $walk($prefix . $digit);
                $used[$i] = false;
            }
        };

        $walk('');

        return $result;
    }
}
