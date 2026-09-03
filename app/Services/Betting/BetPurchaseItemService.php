<?php

declare(strict_types=1);

namespace App\Services\Betting;

use App\DTOs\BetPurchaseContext;
use App\Exceptions\BetPurchaseException;
use App\Models\Bet;
use App\Models\BetItem;
use Illuminate\Support\Facades\DB;

/**
 * Creates the single BetItem line for a purchase.
 *
 * WHY EXACTLY ONE ITEM, EVEN FOR 3D TOD
 * A bet item is a CHARGE: it carries an amount, a payout rate and a potential payout,
 * and the Phase 1 schema enforces amount > 0 on it. One player selection is one charge.
 *
 * A 3D Tod selection on '123' is covered by six arrangements - 123, 132, 213, 231, 312,
 * 321 - and a Tod selection on '112' by three, and on '111' by one. Creating one item
 * per arrangement would create six charges of the stake, so a 10.00 Tod bet would cost
 * 60.00, reserve six times the risk capacity and carry six times the potential
 * liability. That is not what the player bought. So there is ONE item, at the FULL
 * stake, on the number the player chose; the arrangements are recorded in the item's
 * metadata so settlement can later match a result against any of them. Covering six
 * arrangements is a matching rule, not six purchases.
 *
 * WHY BetItem::create() IS NOT USED
 * is_winner and actual_payout are outside BetItem::$fillable in Phase 1, because a line
 * must not be able to arrive already flagged as a winner from an array that began as
 * request input. This class fills the fillable columns from the validated context and
 * leaves the settlement columns entirely alone, at their schema defaults - Phase 4.3
 * sells bets and does not settle them, so nothing here may touch a settlement column.
 *
 * WHY THE PAYOUT RATE IS CHECKED BEFORE IT IS WRITTEN
 * bet_items.payout_multiplier is an unsignedInteger column, so a fractional rate such
 * as 4.5 cannot be stored in it faithfully. Writing it would silently truncate, and a
 * truncated rate is a permanently wrong payout for that line. The rate is therefore
 * checked for storability first and a market whose rate does not fit is REFUSED, with
 * the exact rate reported. It is never rounded to fit: rounding a payout rate changes
 * what the house owes.
 */
final class BetPurchaseItemService
{
    public function __construct()
    {
    }

    /**
     * Persist the one item line for a validated purchase.
     *
     * @throws BetPurchaseException
     */
    public function create(Bet $bet, BetPurchaseContext $context): BetItem
    {
        $this->assertInsideTransaction('create a bet item');

        $betId = $bet->getKey();

        if (! is_numeric($betId)) {
            throw BetPurchaseException::invariantViolated(
                'the bet has no primary key, so no bet item can be attached to it',
            );
        }

        // Refused, not rounded. The exact rate is reported so the market definition can
        // be corrected rather than quietly approximated.
        if (! $context->calculation->multiplierFitsBetItemColumn()) {
            throw BetPurchaseException::multiplierNotStorable(
                $context->multiplier->value(),
                $context->marketKey,
                [
                    'bet_id' => (int) $betId,
                    'number' => $context->canonicalNumber(),
                    'column' => 'bet_items.payout_multiplier',
                ],
            );
        }

        $item = new BetItem();

        $item->fill([
            'bet_id' => (int) $betId,
            'number' => $context->canonicalNumber(),
            'position' => $context->position,
            'amount' => $context->stake->amount(),
            'payout_multiplier' => $context->multiplier->toBetItemColumn(),
            'potential_payout' => $context->potentialPayout->toString(),
            'metadata' => $this->metadataFor($context),
        ]);

        // is_winner and actual_payout are left untouched. Settlement is a later phase's
        // work and this phase writes nothing that pre-judges a result.

        $item->save();

        return $item;
    }

    /**
     * The item's metadata: what the line covers, and how the money was derived.
     *
     * @return array<string, mixed>
     */
    public function metadataFor(BetPurchaseContext $context): array
    {
        $metadata = [
            'market' => $context->marketKey,
            'bet_type' => $context->betType->value,
            'side' => $context->side->value,
            'selection_type' => $context->selectionType->value,
            'position' => $context->position,
            'stake' => $context->stake->amount(),
            'payout_multiplier' => $context->multiplier->value(),
            'potential_payout' => $context->potentialPayout->toString(),
        ];

        if ($context->isTod()) {
            // Recorded so settlement can match a drawn result against any arrangement of
            // the chosen digits. These are covered arrangements of ONE selection, and
            // they are explicitly not additional charges.
            $metadata['covered_numbers'] = $context->permutations;
            $metadata['covered_number_count'] = $context->permutationCount();
            $metadata['charges'] = 1;
        }

        return $metadata;
    }

    /**
     * The item lines already attached to a bet, used for replay reporting and for
     * asserting that a purchase created exactly one.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, BetItem>
     */
    public function itemsFor(Bet $bet)
    {
        return BetItem::query()
            ->where('bet_id', $bet->getKey())
            ->orderBy('id')
            ->get();
    }

    /**
     * @throws BetPurchaseException
     */
    private function assertInsideTransaction(string $operation): void
    {
        if (DB::transactionLevel() < 1) {
            throw BetPurchaseException::outsideTransaction($operation);
        }
    }
}
