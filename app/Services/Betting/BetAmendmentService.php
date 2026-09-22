<?php

declare(strict_types=1);

namespace App\Services\Betting;

use App\DTOs\Betting\BetAmendmentData;
use App\DTOs\Betting\BetAmendmentResult;
use App\DTOs\Betting\BetCancellationData;
use App\Enums\BetAmendmentStatus;
use App\Enums\BetCancellationReason;
use App\Enums\BetStatus;
use App\Enums\Currency;
use App\Exceptions\BetAmendmentException;
use App\Exceptions\BetCancellationException;
use App\Exceptions\InvalidBetAmendmentException;
use App\Models\Bet;
use App\Models\BetAmendment;
use Illuminate\Support\Str;

/**
 * Bet amendment = refund-and-replace, composed out of the audited primitives.
 *
 * WHY NOT AN EDIT
 * A confirmed bet is ledger-backed history and is never edited in place. The
 * real GLO UBF "change my number" story is served here as: cancel the original
 * with a full immediate refund (BetCancellationService), then buy the
 * replacement through the completely ordinary public purchase pipeline
 * (BetPurchaseService). Both legs post to the ledger; the bet_amendments row
 * links the two bets and records the outcome for operators.
 *
 * ORDER = MONEY SAFETY
 * The refund commits BEFORE the replacement is attempted. A refused replacement
 * therefore leaves the player exactly whole: stake back in the wallet, original
 * bet cancelled, amendment row Failed with the refusal reason. There is no
 * order in which the player can be left holding neither bet nor stake.
 *
 * THE REPLACEMENT IS AN ORDINARY PURCHASE
 * Same draw gates, same KYC/limit checks, same odds snapshot, same risk
 * reservation, same ledger posting, same idempotency rules. An amendment cannot
 * buy anything a direct purchase could not — including number availability:
 * the cancel first releases the old number's capacity inside the drawn pool, so
 * "same number, bigger stake" works exactly like a fresh purchase would.
 *
 * IDEMPOTENCY
 * The client_key is REQUIRED (the AmendBetRequest enforces it): a retried
 * amendment must never buy the replacement twice. The row records the key; a
 * replay returns the stored outcome. Derived keys (client_key:cancel and
 * client_key:purchase semantics) make the two legs individually safe as well
 * (the purchase leg keys idempotency on the derived purchase key).
 */
class BetAmendmentService
{
    public function __construct(
        private readonly BetCancellationService $cancellations,
        private readonly BetPurchaseService $purchases,
    ) {
    }

    /**
     * Apply an amendment: cancel+refund the original, then purchase the replacement.
     *
     * @throws BetAmendmentException
     * @throws InvalidBetAmendmentException
     * @throws BetCancellationException
     */
    public function amend(BetAmendmentData $data): BetAmendmentResult
    {
        $this->assertEnabled();

        if (! $data->changesSomething()) {
            throw InvalidBetAmendmentException::nothingToChange(0, ['bet' => $data->betIdentifier]);
        }

        // Whole-amendment replay: the same client key returns the stored row.
        /** @var BetAmendment|null $existing */
        $existing = BetAmendment::query()->where('idempotency_key', $data->clientKey)->first();
        if ($existing !== null) {
            return $this->resultFromRow($existing);
        }

        $bet = $this->resolveBet($data);
        $this->assertAmendable($bet);

        $market = $this->marketOf($bet);
        $newNumber = $data->newNumber ?? $this->firstNumberOf($bet);
        $newStake = $data->newStake ?? bcadd((string) $bet->stake_amount, '0', 2);

        $amendment = BetAmendment::query()->create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $data->userId,
            'bet_id' => (int) $bet->getKey(),
            'status' => BetAmendmentStatus::Pending,
            'currency' => $this->currencyValue($bet),
            'old_number' => $this->firstNumberOf($bet),
            'old_stake' => bcadd((string) $bet->stake_amount, '0', 2),
            'new_number' => $newNumber,
            'new_stake' => $newStake,
            'refunded_amount' => '0.00',
            'idempotency_key' => $data->clientKey,
            'metadata' => array_filter([
                'market' => $market,
                'channel' => $data->metadata['channel'] ?? null,
                'ignored_client_fields' => $data->ignoredClientFields !== [] ? $data->ignoredClientFields : null,
            ]),
        ]);

        // ---- Leg 1: cancel + refund the original ---------------------------
        try {
            $cancellation = $this->cancellations->cancel(new BetCancellationData(
                userId: $data->userId,
                betIdentifier: (string) $bet->getKey(),
                reason: BetCancellationReason::Amendment,
                clientKey: $data->clientKey . ':cancel',
            ));
        } catch (BetCancellationException $e) {
            $this->finalizeFailure($amendment, 'cancel_failed: ' . $e->getMessage());

            throw BetAmendmentException::notAmendable((int) $bet->getKey(), $e->getMessage());
        }

        $amendment->forceFill(['refunded_amount' => $cancellation->refundAmount]);
        $amendment->save();

        // ---- Leg 2: an ordinary purchase of the replacement ----------------
        try {
            $purchase = $this->purchases->purchaseFromRequest($data->userId, [
                'draw_id' => (int) $bet->draw_id,
                'market' => $market,
                'number' => $newNumber,
                'stake' => $newStake,
                'idempotency_key' => substr($data->clientKey . ':purchase', 0, 64),
            ]);
        } catch (\Throwable $e) {
            // The refund already committed: the player is whole. Record the
            // terminal failure on the row; never compensate the refund.
            $this->finalizeFailure($amendment, 'purchase_refused: ' . $e->getMessage());

            throw BetAmendmentException::replacementRefused((int) $amendment->getKey(), $e->getMessage());
        }

        $amendment->forceFill([
            'replacement_bet_id' => (int) $purchase->bet->getKey(),
            'status' => BetAmendmentStatus::Applied,
            'charged_amount' => bcadd((string) $purchase->bet->stake_amount, '0', 2),
            'applied_at' => now(),
        ]);
        $amendment->save();

        return $this->resultFromRow($amendment);
    }

    /**
     * Backfill stored Expired on pending rows past their expiry moment. Rows
     * only; both money legs either committed or never ran.
     *
     * @return int rows updated
     */
    public function sweepExpired(): int
    {
        return BetAmendment::query()
            ->expiredPending()
            ->update(['status' => BetAmendmentStatus::Expired->value]);
    }

    // ----------------------------------------------------------------------
    // Internals
    // ----------------------------------------------------------------------

    private function resolveBet(BetAmendmentData $data): Bet
    {
        $identifier = $data->betIdentifier;

        $query = Bet::query()->where('user_id', $data->userId);
        $query->where(static function ($q) use ($identifier): void {
            if (ctype_digit($identifier)) {
                $q->where('id', (int) $identifier);
            } else {
                $q->where('bet_number', $identifier)->orWhere('uuid', $identifier);
            }
        });

        /** @var Bet|null $bet */
        $bet = $query->first();

        if ($bet === null) {
            throw BetAmendmentException::betUnavailable($identifier);
        }

        return $bet;
    }

    private function assertAmendable(Bet $bet): void
    {
        if ($bet->status === BetStatus::Cancelled) {
            throw BetAmendmentException::notAmendable((int) $bet->getKey(), 'the bet is already cancelled');
        }

        if (! $bet->status->canCancel()) {
            throw BetAmendmentException::notAmendable((int) $bet->getKey(), 'the bet is already ' . $bet->status->value);
        }

        $window = (int) config('lottery.amendment.window_minutes', 60);
        if ($window > 0) {
            $placedAt = $bet->placed_at ?? $bet->created_at;
            if ($placedAt === null || ! $placedAt->copy()->addMinutes($window)->isFuture()) {
                throw BetAmendmentException::windowExpired((int) $bet->getKey(), $window);
            }
        }
    }

    private function assertEnabled(): void
    {
        if (! (bool) config('lottery.amendment.enabled', true)) {
            throw BetAmendmentException::disabled();
        }
    }

    /**
     * The purchase pipeline persists the market under bets.metadata.market.
     */
    private function marketOf(Bet $bet): string
    {
        $market = $bet->metadata['market'] ?? null;

        if (! is_string($market) || $market === '') {
            throw BetAmendmentException::notAmendable((int) $bet->getKey(), 'the original market could not be determined');
        }

        return $market;
    }

    private function firstNumberOf(Bet $bet): string
    {
        /** @var \App\Models\BetItem|null $item */
        $item = $bet->items()->orderBy('id')->first();

        return $item !== null ? (string) $item->number : '';
    }

    private function currencyValue(Bet $bet): string
    {
        return $bet->currency instanceof Currency ? $bet->currency->value : (string) $bet->currency;
    }

    private function finalizeFailure(BetAmendment $amendment, string $reason): void
    {
        $amendment->forceFill([
            'status' => BetAmendmentStatus::Failed,
            'failure_reason' => Str::limit($reason, 250, ''),
        ]);
        $amendment->save();
    }

    private function resultFromRow(BetAmendment $amendment): BetAmendmentResult
    {
        return new BetAmendmentResult(
            amendment: $amendment,
            originalBet: $amendment->bet()->firstOrFail(),
            replacementBet: $amendment->replacement_bet_id !== null
                ? $amendment->replacementBet()->first()
                : null,
            status: $amendment->status,
            refundedAmount: bcadd((string) $amendment->refunded_amount, '0', 2),
            chargedAmount: $amendment->charged_amount !== null
                ? bcadd((string) $amendment->charged_amount, '0', 2)
                : null,
            currency: $amendment->currency instanceof Currency
                ? $amendment->currency->value
                : (string) $amendment->currency,
            failureReason: $amendment->failure_reason,
        );
    }
}
