<?php

declare(strict_types=1);

namespace App\Services\Betting;

use App\DTOs\Betting\BetCancellationData;
use App\DTOs\Betting\BetCancellationResult;
use App\Enums\AuditAction;
use App\Enums\BetCancellationReason;
use App\Enums\BetStatus;
use App\Enums\Currency;
use App\Enums\FinancialReferenceType;
use App\Enums\FinancialTransactionType;
use App\Enums\RiskLevel;
use App\Enums\TicketStatus;
use App\Enums\WalletType;
use App\Exceptions\BetAlreadySettledException;
use App\Exceptions\BetCancellationException;
use App\Exceptions\BetCancellationWindowExpiredException;
use App\Models\AuditLog;
use App\Models\Bet;
use App\Models\BetItem;
use App\Models\Draw;
use App\Models\FinancialTransaction;
use App\Models\Ticket;
use App\Models\Wallet;
use App\Services\Finance\Money;
use App\Services\Finance\WalletService;
use App\Services\Risk\RiskAssessmentService;
use Illuminate\Database\DatabaseManager;

/**
 * Player-facing bet cancellation with a full, immediate refund.
 *
 * PLACE IN THE SYSTEM
 * DrawLifecycleService cancels DRAWS (operator flow). The real GLO distinction
 * between "the whole draw is off" and "I bought the wrong number, give me my
 * stake back before the draw" is served by THIS service: one bet, owner
 * initiated, inside a short window, refunded in full right away.
 *
 * THE MONEY LEG REUSES THE SAME PRIMITIVE AS DEPOSITS
 * FinancialTransactionService::execute (through WalletService::credit) is the
 * only audited wallet+ledger movement on this codebase. The refund is therefore
 * one atomic step: FinancialTransaction row, wallet credit and double-entry
 * posting commit together or not at all. The cancellation adds its own single
 * transaction around the marking of the bet, the risk release and that credit,
 * so a refunded-but-still-active bet is unrepresentable.
 *
 * REPLAYS
 * Cancelling an already-cancelled bet returns the recorded outcome with
 * replayed=true and never credits twice, regardless of client_key. The refund
 * itself carries a deterministic idempotency key (bet-refund-<padded id>), so
 * even a crash mid-retry cannot double-credit: the idempotency machinery
 * returns the existing FinancialTransaction.
 *
 * RISK RELEASE
 * A purchased bet reserved capacity on the draw's number limits at bet time;
 * cancelling hands that capacity back through RiskAssessmentService::releaseBet
 * with the same selections (numbers, stakes, multipliers) it reserved, inside
 * the same transaction. Another player can buy the number immediately.
 *
 * TICKET SYNC
 * When every bet on the ticket is cancelled the ticket moves to Cancelled with
 * its cancelled_at stamp; a partially cancelled ticket stays in its current
 * state.
 */
class BetCancellationService
{
    /**
     * Deterministic refund idempotency key. The finance idempotency machinery
     * requires keys of at least 16 characters everywhere, so the bet id is
     * zero-padded: every realistic id yields a key of length 22.
     */
    private const REFUND_KEY_FORMAT = 'bet-refund-%012d';

    public function __construct(
        private readonly DatabaseManager $database,
        private readonly WalletService $wallets,
        private readonly RiskAssessmentService $risk,
    ) {
    }

    /**
     * Cancel a bet and refund its stake in full.
     *
     * @throws BetCancellationException
     * @throws BetAlreadySettledException
     * @throws BetCancellationWindowExpiredException
     */
    public function cancel(BetCancellationData $data): BetCancellationResult
    {
        $this->assertEnabled();

        $bet = $this->resolveBet($data);
        $currency = $this->resolveCurrency($bet);

        // ---- Replay: already cancelled returns the recorded outcome --------
        if ($bet->status === BetStatus::Cancelled) {
            return $this->replayResult($bet, $currency);
        }

        // ---- Gates ----------------------------------------------------------
        $this->assertNotSettled($bet);
        $this->assertCancellableStatus($bet);
        $this->assertWithinWindow($bet);
        $this->assertDrawAcceptsCancellation($bet);

        /** @var list<BetItem> $items */
        $items = $bet->items()->get()->all();

        $result = null;

        $this->database->transaction(function () use (&$result, $bet, $items, $data, $currency): void {
            // 1. Mark the bet cancelled. Status columns are not fillable by
            //    design, so forceFill is required and intentional here.
            $bet->forceFill([
                'status' => BetStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_reason' => $data->reason->value,
            ]);
            $bet->save();

            // 2. Hand the reserved capacity back so the numbers can sell again.
            $this->risk->releaseBet(
                (int) $bet->draw_id,
                $bet->type,
                array_map(
                    static fn (BetItem $item): array => [
                        'number' => (string) $item->number,
                        'stake' => bcadd((string) $item->amount, '0', 2),
                        'multiplier' => (string) $item->payout_multiplier,
                    ],
                    $items,
                ),
            );

            // 3. Refund the stake through the audited wallet+ledger primitive.
            $wallet = $this->walletFor((int) $bet->user_id, $currency);
            $refund = $this->wallets->credit(
                $wallet,
                Money::of(bcadd((string) $bet->stake_amount, '0', 2), $currency),
                FinancialTransactionType::BetRefund,
                $this->refundKeyFor((int) $bet->getKey()),
                [
                    'description' => sprintf('Refund for cancelled bet %s', (string) $bet->bet_number),
                    'reference_type' => FinancialReferenceType::Bet->value,
                    'reference_id' => (int) $bet->getKey(),
                ],
            );

            // 4. Keep the ticket coherent: cancelled only when nothing on it
            //    remains standing.
            $ticket = $this->syncTicket($bet);

            // 5. Audit trail, same shape as the other finance-facing flows.
            $this->recordAudit($bet, $data->reason, $refund);

            $result = new BetCancellationResult(
                bet: $bet->refresh(),
                ticket: $ticket,
                refund: $refund,
                reason: $data->reason,
                refundAmount: bcadd((string) $refund->amount, '0', 2),
                currency: $currency->value,
                replayed: false,
            );
        }, 3);

        /** @var BetCancellationResult $result */
        return $result;
    }

    // ----------------------------------------------------------------------
    // Gates
    // ----------------------------------------------------------------------

    private function resolveBet(BetCancellationData $data): Bet
    {
        $query = Bet::query()->where('user_id', $data->userId);

        $identifier = $data->betIdentifier;
        $query->where(static function ($q) use ($identifier): void {
            if (ctype_digit($identifier)) {
                $q->where('id', (int) $identifier);
            } else {
                $q->where('bet_number', $identifier)->orWhere('uuid', $identifier);
            }
        });

        /** @var Bet|null $bet */
        $bet = $query->first();

        // Enumeration-safe: wrong owner, unknown number and unknown id are the
        // same refusal, so the endpoint never confirms a bet exists.
        if ($bet === null) {
            throw BetCancellationException::betUnavailable($identifier);
        }

        return $bet;
    }

    private function assertEnabled(): void
    {
        if (! (bool) config('lottery.cancellation.enabled', true)) {
            throw BetCancellationException::disabled();
        }
    }

    private function assertNotSettled(Bet $bet): void
    {
        if (in_array($bet->status, [BetStatus::Won, BetStatus::Lost, BetStatus::Refunded], true)) {
            throw BetAlreadySettledException::forBet((int) $bet->getKey(), $bet->status->value);
        }
    }

    private function assertCancellableStatus(Bet $bet): void
    {
        if (! $bet->status->canCancel()) {
            throw BetCancellationException::notCancellable($bet->status->value, ['bet_id' => $bet->getKey()]);
        }
    }

    private function assertWithinWindow(Bet $bet): void
    {
        $window = (int) config('lottery.cancellation.window_minutes', 15);
        if ($window <= 0) {
            return; // Window disabled by configuration.
        }

        $placedAt = $bet->placed_at ?? $bet->created_at;
        if ($placedAt === null || ! $placedAt->copy()->addMinutes($window)->isFuture()) {
            throw BetCancellationWindowExpiredException::forBet(
                (int) $bet->getKey(),
                $placedAt?->toDateTimeString() ?? 'unknown',
                $window,
            );
        }
    }

    private function assertDrawAcceptsCancellation(Bet $bet): void
    {
        /** @var Draw $draw */
        $draw = $bet->draw()->firstOrFail();

        if (! $draw->canAcceptBets()) {
            throw BetCancellationException::drawUnavailable((int) $draw->getKey(), 'the draw no longer accepts changes');
        }
    }

    // ----------------------------------------------------------------------
    // Internals
    // ----------------------------------------------------------------------

    private function replayResult(Bet $bet, Currency $currency): BetCancellationResult
    {
        $refund = FinancialTransaction::query()
            ->where('type', FinancialTransactionType::BetRefund->value)
            ->where('reference_type', FinancialReferenceType::Bet->value)
            ->where('reference_id', (int) $bet->getKey())
            ->orderByDesc('id')
            ->first();

        if ($refund === null) {
            // A cancelled bet without its refund row is an operator-level
            // anomaly; it is surfaced, never silently re-credited.
            throw BetCancellationException::alreadyCancelled((int) $bet->getKey(), [
                'anomaly' => 'cancelled_without_refund',
            ]);
        }

        return new BetCancellationResult(
            bet: $bet,
            ticket: $bet->ticket()->first(),
            refund: $refund,
            reason: BetCancellationReason::tryFrom((string) $bet->cancelled_reason) ?? BetCancellationReason::PlayerRequest,
            refundAmount: bcadd((string) $refund->amount, '0', 2),
            currency: $currency->value,
            replayed: true,
        );
    }

    /**
     * Cancel the owning ticket only when every bet on it is now cancelled.
     * Partial tickets stay in their current status.
     */
    private function syncTicket(Bet $bet): ?Ticket
    {
        /** @var Ticket|null $ticket */
        $ticket = $bet->ticket()->first();
        if ($ticket === null || $ticket->status === TicketStatus::Cancelled) {
            return $ticket;
        }

        $hasStandingBets = $ticket->bets()
            ->where('status', '!=', BetStatus::Cancelled->value)
            ->exists();

        if (! $hasStandingBets) {
            $ticket->forceFill([
                'status' => TicketStatus::Cancelled,
                'cancelled_at' => now(),
            ]);
            $ticket->save();
        }

        return $ticket;
    }

    private function walletFor(int $userId, Currency $currency): Wallet
    {
        /** @var Wallet|null $wallet */
        $wallet = Wallet::query()
            ->where('user_id', $userId)
            ->where('type', WalletType::Primary->value)
            ->where('currency', $currency->value)
            ->first();

        if ($wallet === null) {
            throw BetCancellationException::betUnavailable((string) $userId, ['anomaly' => 'primary_wallet_missing']);
        }

        return $wallet;
    }

    private function resolveCurrency(Bet $bet): Currency
    {
        return $bet->currency instanceof Currency
            ? $bet->currency
            : (Currency::tryFrom((string) $bet->currency) ?? Currency::THB);
    }

    private function recordAudit(Bet $bet, BetCancellationReason $reason, FinancialTransaction $refund): void
    {
        $log = new AuditLog();
        $log->fill([
            'user_id' => (int) $bet->user_id,
            'action' => AuditAction::CancelBet,
            'risk_level' => RiskLevel::Low,
            'auditable_type' => Bet::class,
            'auditable_id' => (int) $bet->getKey(),
            'description' => sprintf(
                'Cancelled bet %s (%s); refunded %s %s via %s.',
                (string) $bet->bet_number,
                $reason->value,
                bcadd((string) $bet->stake_amount, '0', 2),
                (string) ($bet->currency instanceof Currency ? $bet->currency->value : $bet->currency),
                (string) $refund->reference_number,
            ),
            'metadata' => [
                'bet_id' => (int) $bet->getKey(),
                'bet_number' => (string) $bet->bet_number,
                'draw_id' => (int) $bet->draw_id,
                'reason' => $reason->value,
                'refund_transaction_id' => (int) $refund->getKey(),
            ],
        ]);
        $log->save();
    }

    private function refundKeyFor(int $betId): string
    {
        return sprintf(self::REFUND_KEY_FORMAT, $betId);
    }
}
