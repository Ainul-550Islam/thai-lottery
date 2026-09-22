<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle of a PAYOUT BATCH — the manufactured group of approved payout
 * obligations that moves money in one scheduled execution run.
 *
 * WHAT ONE STATUS DESCRIBES
 * -------------------------
 * Where one batch stands between being created and being accounted for:
 * whether the transfers inside it are still queued, executing, done, or
 * dead. A batch is a pure book-keeping assembly: it carries no wallets,
 * holds no money, and its members keep their own per-payout statuses. The
 * batch status is therefore a PROJECTION of member outcomes — advanced by
 * the executor, never by hand-editing member rows.
 *
 * STATE MACHINE
 * -------------
 *
 *   ┌─────────┐ executor starts ┌────────────┐ all transfers settled ┌───────────┐
 *   │ Pending │ ──────────────▶ │ Processing │ ────────────────────▶ │ Completed │
 *   └────┬────┘                 └──────┬─────┘                       └───────────┘
 *        │                             │ executor dies w/o recovery ┌────────┐
 *        │ operator cancels            └──────────────────────────▶ │ Failed │
 *        │ pre-execution                                            └────────┘
 *        ▼
 *   ┌───────────┐
 *   │ Cancelled │
 *   └───────────┘
 *
 *   Pending    — created with its member list; no transfer has been
 *                attempted against any member. Operator may still Cancel.
 *   Processing — the executor claimed the batch and is settling members
 *                one by one under their own per-payout transaction
 *                boundaries. A second executor cannot claim it: the claim
 *                is the Pending→Processing flip, guarded atomically.
 *   Completed  — every member reached a terminal per-payout disposition
 *                (paid, replayed, or individually failed-and-compensated).
 *                Completed does NOT assert every member paid: it asserts
 *                every member was ACCOUNTED FOR with an outcome. The one-
 *                time PayoutBatchCompleted event fires crossing into here.
 *   Failed     — the executor's run died irrecoverably (thrown out of the
 *                per-member loop without a per-member compensation). The
 *                batch goes to operations; members keep their own truthful
 *                per-payout statuses, so a Failed batch never lies about
 *                money — only about the run.
 *   Cancelled  — killed pre-execution by an operator gesture. Members are
 *                released back to the approved-and-pending selection pool
 *                with their own statuses untouched. Terminal.
 *
 * EVENT CONTRACT
 *   The PayoutBatchCompleted event fires EXACTLY ONCE: the Processing →
 *   Completed transition. Retries of Failed batches that ultimately finish
 *   also resolve through Processing → Completed (the claim step Pending →
 *   Processing re-arms only from Failed).
 */
enum PayoutBatchStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Processing => 'Processing',
            self::Completed => 'Completed',
            self::Failed => 'Failed',
            self::Cancelled => 'Cancelled',
        };
    }

    public function isActionable(): bool
    {
        return match ($this) {
            self::Pending, self::Processing, self::Failed => true,
            self::Completed, self::Cancelled => false,
        };
    }

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Completed, self::Cancelled => true,
            self::Pending, self::Processing, self::Failed => false,
        };
    }

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Processing, self::Cancelled],
            self::Processing => [self::Completed, self::Failed],
            self::Failed => [self::Processing], // operator/executor re-arms the run
            self::Completed, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'amber',
            self::Processing => 'blue',
            self::Completed => 'green',
            self::Failed => 'red',
            self::Cancelled => 'gray',
        };
    }
}
