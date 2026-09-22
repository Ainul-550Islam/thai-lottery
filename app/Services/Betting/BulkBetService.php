<?php

declare(strict_types=1);

namespace App\Services\Betting;

use App\Enums\BetPurchaseStatus;
use App\Enums\Currency;
use App\Exceptions\BetDomainException;
use App\Exceptions\BetPurchaseException;

/**
 * Multi-selection slip purchase — the GLO UBF "cart".
 *
 * DESIGN RULE
 * There is no new money path here. Each selection is purchased through the
 * ordinary public BetPurchaseService, sequentially, with a per-item derived
 * idempotency key. A bulk purchase is therefore financially indistinguishable
 * from N deliberate single purchases: same gates, same ledger, same risk
 * reservations. What this service ADDS is purely the slip semantics:
 *
 * PARTIAL SUCCESS IS FIRST-CLASS
 * Items refused by any gate are reported per item with the refusal reason;
 * items that pass commit on their own. A mid-slip failure never strands prior
 * items (each already committed as an independent purchase) and never blocks
 * later items automatically — exactly like placing several slips at the
 * counter.
 *
 * REPLAYS ARE PER ITEM AND PER SLIP
 * purchase(userId, drawId, selections, clientKey) derives each item's
 * idempotency key from the slip key deterministically. Resubmitting the same
 * slip yields outcome=replayed per item through the pipeline's own replay
 * path and charges nothing again. Two DIFFERENT slip keys containing the same
 * number are two real purchases (repeating a number is legitimate).
 *
 * @see BetPurchaseService for the single-bet rules every selection inherits.
 */
class BulkBetService
{
    public function __construct(
        private readonly BetPurchaseService $purchases,
        private readonly PayoutMultiplierService $multipliers,
    ) {
    }

    /**
     * Quote a slip: per-item computation + aggregate totals, no money.
     *
     * @param  list<\App\DTOs\Betting\BulkBetSelectionData>  $selections
     */
    public function quote(int $drawId, array $selections): \App\DTOs\Betting\BulkBetCalculationData
    {
        $this->assertSelectionCap($selections);

        $items = [];
        $refusals = [];
        $totalStake = '0.00';
        $totalPotentialPayout = '0.00';
        $currency = config('lottery.currencies.default', 'THB');

        foreach ($selections as $index => $selection) {
            $payload = [
                'index' => $index,
                'market' => $selection->marketKey,
                'number' => $selection->number,
                'stake' => $selection->stake,
            ];

            try {
                $multiplier = $this->multipliers->resolve($selection->marketKey);
                $payout = bcmul($selection->stake, $multiplier->value, 2);

                $payload['potential_payout'] = $payout;
                $totalStake = bcadd($totalStake, $selection->stake, 2);
                $totalPotentialPayout = bcadd($totalPotentialPayout, $payout, 2);
            } catch (\Throwable $e) {
                $payload['potential_payout'] = null;
                $refusals[] = [
                    'index' => $index,
                    'market' => $selection->marketKey,
                    'number' => $selection->number,
                    'reason' => $e->getMessage(),
                ];
            }

            $items[] = $payload;
        }

        return new \App\DTOs\Betting\BulkBetCalculationData(
            drawId: $drawId,
            items: $items,
            totalStake: $totalStake,
            totalPotentialPayout: $totalPotentialPayout,
            currency: is_string($currency) ? $currency : Currency::THB->value,
            refusals: $refusals,
        );
    }

    /**
     * Purchase a whole slip, per item isolation, report everything.
     *
     * @param  list<\App\DTOs\Betting\BulkBetSelectionData>  $selections
     * @return array<string, mixed>  the report consumed by BulkBetResource
     */
    public function purchase(int $userId, int $drawId, array $selections, string $clientKey): array
    {
        $this->assertSelectionCap($selections);

        $items = [];
        $purchased = 0;
        $replayed = 0;
        $refused = 0;
        $totalCharged = '0.00';

        foreach ($selections as $index => $selection) {
            $derivedKey = $this->itemKey($clientKey, $index, $selection);

            $record = [
                'index' => $index,
                'outcome' => 'refused',
                'selection' => $selection->identity(),
                'stake' => $selection->stake,
                'bet_id' => null,
                'bet_number' => null,
                'ticket_number' => null,
                'potential_payout' => null,
                'reason' => null,
                'idempotency_key' => $derivedKey,
            ];

            try {
                $result = $this->purchases->purchaseFromRequest(
                    $userId,
                    $selection->toPurchasePayload($drawId, $derivedKey),
                );

                $isReplay = $result->status === BetPurchaseStatus::Replayed;

                $record['outcome'] = $isReplay ? 'replayed' : 'purchased';
                $record['bet_id'] = (int) $result->bet->getKey();
                $record['bet_number'] = (string) $result->bet->bet_number;
                $record['ticket_number'] = (string) $result->ticket->ticket_number;
                $record['potential_payout'] = bcadd((string) $result->bet->potential_payout, '0', 2);

                if ($isReplay) {
                    $replayed++;
                } else {
                    $purchased++;
                    $totalCharged = bcadd($totalCharged, (string) $result->bet->stake_amount, 2);
                }
            } catch (BetDomainException | BetPurchaseException $e) {
                $refused++;
                $record['reason'] = $e->getMessage();
            } catch (\Throwable $e) {
                $refused++;
                $record['reason'] = $e->getMessage();
            }

            $items[] = $record;
        }

        $currency = config('lottery.currencies.default', 'THB');

        return [
            'draw_id' => $drawId,
            'requested' => count($selections),
            'purchased' => $purchased,
            'replayed' => $replayed,
            'refused' => $refused,
            'total_charged' => $totalCharged,
            'currency' => is_string($currency) ? $currency : Currency::THB->value,
            'items' => $items,
        ];
    }

    // ----------------------------------------------------------------------
    // Internals
    // ----------------------------------------------------------------------

    /**
     * @param  list<\App\DTOs\Betting\BulkBetSelectionData>  $selections
     */
    private function assertSelectionCap(array $selections): void
    {
        $cap = max(1, (int) config('lottery.bulk.max_items', 50));

        if (count($selections) > $cap) {
            throw BetPurchaseException::invariantViolated('bulk_selection_cap', [
                'submitted' => count($selections),
                'cap' => $cap,
            ]);
        }
    }

    /**
     * Deterministic per-item key: slip key + position + natural identity, so a
     * slip-level retry replays exactly item-for-item through the pipeline.
     * 64 chars matches the purchase idempotency_key storage width.
     */
    private function itemKey(string $clientKey, int $index, \App\DTOs\Betting\BulkBetSelectionData $selection): string
    {
        return substr(hash('sha256', implode('|', [
            'bulk-item',
            $clientKey,
            (string) $index,
            $selection->marketKey,
            $selection->number,
            $selection->stake,
        ])), 0, 64);
    }
}
