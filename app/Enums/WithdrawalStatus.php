<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle of a withdrawal request.
 *
 * WHAT WAS PRESERVED FROM THE AUDITED FOUNDATION
 * ----------------------------------------------
 * All eight original cases and their backing values are unchanged, and every
 * original method (label, isFinal, isSuccessful, canApprove, canReject,
 * canCancel, color) keeps its original meaning. App\Models\Withdrawal casts
 * `withdrawals.status` to this enum and its scopePendingReview() and
 * scopeCompleted() depend on those values, so nothing was renamed.
 *
 * WHAT WAS ADDED FOR THE PHASE 2.2 WORKFLOW
 * An explicit transition map plus the predicates the workflow services need:
 * which states hold reserved funds, which may still be completed, and which are
 * terminal. No case needed to be added - the audited enum already covers the
 * whole pending -> under_review -> approved -> processing -> completed pipeline.
 *
 * WHAT WAS ADDED FOR THE KYC MONEY-OUT GATE (BATCH 6)
 * The KycRequired case: a pending/under-review withdrawal that discovered an
 * identity-evidence gap is detained there until the gate passes it (or refuses
 * it). Money semantics: NO funds are reserved in kyc_required, exactly like
 * pending/under_review - a hold before approval would lock money against a
 * request that may never pass. Entry is an addition only; every audited case,
 * value and method above keeps its original behaviour.
 *
 * MONEY MEANING OF EACH STATE
 * pending / under_review : no funds reserved yet.
 * approved / processing   : the amount is held in wallets.locked_balance.
 * completed               : the hold was consumed and the balance was debited.
 * rejected / cancelled    : the hold, if any, was released back to available.
 * failed                  : the payout attempt failed; the hold was released.
 *
 * This enum contains no money logic. It only describes states.
 */
enum WithdrawalStatus: string
{
    case Pending = 'pending';
    case UnderReview = 'under_review';
    case KycRequired = 'kyc_required';
    case Approved = 'approved';
    case Processing = 'processing';
    case Completed = 'completed';
    case Rejected = 'rejected';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }

    /**
     * The single state that means "the money left the wallet".
     */
    public static function completedCase(): self
    {
        return self::Completed;
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::UnderReview => 'Under Review',
            self::KycRequired => 'KYC Required',
            self::Approved => 'Approved',
            self::Processing => 'Processing',
            self::Completed => 'Completed',
            self::Rejected => 'Rejected',
            self::Failed => 'Failed',
            self::Cancelled => 'Cancelled',
        };
    }

    public function isFinal(): bool
    {
        return in_array($this, [
            self::Completed,
            self::Rejected,
            self::Failed,
            self::Cancelled,
        ], true);
    }

    /**
     * Alias of isFinal() using the state-machine vocabulary.
     */
    public function isTerminal(): bool
    {
        return $this->isFinal();
    }

    public function isSuccessful(): bool
    {
        return $this === self::Completed;
    }

    public function isOpen(): bool
    {
        return ! $this->isFinal();
    }

    /**
     * The states a withdrawal may legally move to from here.
     *
     * No transition ever returns to an earlier state and no terminal state has a
     * successor, so money can never be un-spent by editing a status.
     *
     * @return array<int, self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::UnderReview, self::KycRequired, self::Approved, self::Rejected, self::Cancelled, self::Failed],
            self::UnderReview => [self::KycRequired, self::Approved, self::Rejected, self::Cancelled, self::Failed],
            self::KycRequired => [self::Approved, self::Rejected, self::Cancelled, self::Failed],
            self::Approved => [self::Processing, self::Completed, self::Rejected, self::Cancelled, self::Failed],
            self::Processing => [self::Completed, self::Failed],
            self::Completed, self::Rejected, self::Failed, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    public function canApprove(): bool
    {
        return in_array($this, [self::Pending, self::UnderReview], true);
    }

    public function canReject(): bool
    {
        return in_array($this, [self::Pending, self::UnderReview, self::KycRequired, self::Approved], true);
    }

    public function canCancel(): bool
    {
        return in_array($this, [self::Pending, self::UnderReview, self::KycRequired, self::Approved], true);
    }

    /**
     * Whether the payout may be settled from here.
     *
     * Only an approved or already-processing request qualifies: the funds must be
     * reserved before they can be consumed.
     */
    public function canComplete(): bool
    {
        return in_array($this, [self::Approved, self::Processing], true);
    }

    /**
     * Whether a request in this state is expected to be holding reserved funds.
     *
     * Used by the release path to decide whether there is a hold to give back.
     */
    public function holdsReservedFunds(): bool
    {
        return in_array($this, [self::Approved, self::Processing], true);
    }

    /**
     * Whether reaching this state means the wallet balance was reduced.
     */
    public function impliesWalletDebit(): bool
    {
        return $this === self::Completed;
    }

    /**
     * Whether reaching this state means any reservation must be given back.
     */
    public function requiresHoldRelease(): bool
    {
        return in_array($this, [self::Rejected, self::Failed, self::Cancelled], true);
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'yellow',
            self::UnderReview => 'blue',
            self::KycRequired => 'orange',
            self::Approved => 'blue',
            self::Processing => 'blue',
            self::Completed => 'green',
            self::Rejected => 'red',
            self::Failed => 'red',
            self::Cancelled => 'gray',
        };
    }
}
