<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\Withdrawal;

/**
 * The manual-review outcome of a withdrawal, expressed as one value.
 *
 * WHY THIS ENUM EXISTS AND WHAT IT IS NOT
 * ---------------------------------------
 * SCHEMA LIMITATION (reported, not patched): the audited `withdrawals` table has
 * NO `approval_status` column. What it does have is a real review trail:
 *
 *     reviewed_by  (FK users, nullable)
 *     reviewed_at  (timestamp, nullable)
 *     approved_at  (timestamp, nullable)
 *     rejected_at  (timestamp, nullable)
 *     rejection_reason (string, nullable)
 *
 * Because a separate approval field genuinely exists in the schema - as
 * timestamps rather than as a string - this enum is safe to create, but it is a
 * DERIVED READ MODEL, never a persisted column. Nothing in this codebase writes
 * an `approval_status` value to the database, and no query filters on one. Doing
 * either would be inventing database behaviour.
 *
 * Use fromWithdrawal() or fromReviewTrail() to compute the value from the real
 * columns. The result is for display, reporting and guard clauses only.
 *
 * This enum contains no money logic.
 */
enum WithdrawalApprovalStatus: string
{
    /** No reviewer has looked at the request yet. */
    case AwaitingReview = 'awaiting_review';

    /** A reviewer has taken the request but has not decided yet. */
    case InReview = 'in_review';

    /** approved_at is set: a human authorised the payout. */
    case Approved = 'approved';

    /** rejected_at is set: a human refused the payout. */
    case Rejected = 'rejected';

    /**
     * The request left the workflow without a review decision, e.g. the customer
     * cancelled it. Not a reviewer action, but it does end the review.
     */
    case NotRequired = 'not_required';

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
     * Derive the review outcome from a withdrawal's real columns.
     *
     * Reads only columns that exist in the audited schema. Performs no query and
     * writes nothing.
     */
    public static function fromWithdrawal(Withdrawal $withdrawal): self
    {
        return self::fromReviewTrail(
            $withdrawal->approved_at !== null,
            $withdrawal->rejected_at !== null,
            $withdrawal->reviewed_at !== null || $withdrawal->reviewed_by !== null,
            $withdrawal->status,
        );
    }

    /**
     * Derive the review outcome from raw flags.
     *
     * Rejection wins over approval: if both timestamps are somehow present, the
     * safe reading is that the payout must not go out.
     */
    public static function fromReviewTrail(
        bool $hasApprovedAt,
        bool $hasRejectedAt,
        bool $hasBeenPickedUp,
        ?WithdrawalStatus $status = null,
    ): self {
        if ($hasRejectedAt) {
            return self::Rejected;
        }

        if ($hasApprovedAt) {
            return self::Approved;
        }

        if ($status === WithdrawalStatus::Rejected) {
            return self::Rejected;
        }

        if ($status === WithdrawalStatus::Approved || $status === WithdrawalStatus::Processing || $status === WithdrawalStatus::Completed) {
            return self::Approved;
        }

        if ($status === WithdrawalStatus::Cancelled) {
            return self::NotRequired;
        }

        if ($hasBeenPickedUp || $status === WithdrawalStatus::UnderReview) {
            return self::InReview;
        }

        return self::AwaitingReview;
    }

    public function label(): string
    {
        return match ($this) {
            self::AwaitingReview => 'Awaiting Review',
            self::InReview => 'In Review',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
            self::NotRequired => 'Review Not Required',
        };
    }

    /**
     * Whether a reviewer has reached a decision.
     */
    public function isDecided(): bool
    {
        return in_array($this, [self::Approved, self::Rejected], true);
    }

    public function isApproved(): bool
    {
        return $this === self::Approved;
    }

    public function isRejected(): bool
    {
        return $this === self::Rejected;
    }

    /**
     * Whether the request is still waiting on a human.
     */
    public function isPending(): bool
    {
        return in_array($this, [self::AwaitingReview, self::InReview], true);
    }

    /**
     * Whether a payout may be settled given only the review outcome.
     *
     * This is one condition among several. The status machine, the currency check
     * and the reservation check are all still enforced separately.
     */
    public function permitsPayout(): bool
    {
        return $this === self::Approved;
    }

    public function color(): string
    {
        return match ($this) {
            self::AwaitingReview => 'yellow',
            self::InReview => 'blue',
            self::Approved => 'green',
            self::Rejected => 'red',
            self::NotRequired => 'gray',
        };
    }
}
