<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle of a player's prize CLAIM — the assertion "this winning
 * bet/ticket belongs to me; pay me" — as opposed to the PAYOUT state machine,
 * which is the operations-side discharge of that assertion.
 *
 * A claim moves forwards only:
 *
 *   Submitted ──▶ UnderReview ──▶ Approved ──▶ Paid
 *                    │                │
 *                    ▼                ▼
 *                 Rejected        Expired ──(the claim, or the prize window, ran out)
 *
 * REJECTED and EXPIRED are terminal. APPROVED is not terminal because the
 * money has not moved yet; the payout being completed flips the claim to PAID
 * in the same transaction. EXPIRED exists (in addition to a rejected review)
 * because a prize that is never claimed inside its claim window must be
 * auditable as lapsed, not merely absent.
 */
enum PrizeClaimStatus: string
{
    case Submitted = 'submitted';
    case UnderReview = 'under_review';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Paid = 'paid';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Submitted => 'Submitted',
            self::UnderReview => 'Under Review',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
            self::Paid => 'Paid',
            self::Expired => 'Expired',
        };
    }

    /**
     * Whether this state makes the row immutable to the claim flow itself.
     * Terminal rows are only ever read (audits, reports) — never mutated by
     * claim processing.
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Paid, self::Rejected, self::Expired => true,
            self::Submitted, self::UnderReview, self::Approved => false,
        };
    }

    /**
     * Whether a claim in this state still occupies money of the player —
     * i.e. the prize is earmarked for this claimant and may not be claimed by
     * anyone else or expired by the scheduler.
     */
    public function locksPrize(): bool
    {
        return match ($this) {
            self::Submitted, self::UnderReview, self::Approved => true,
            self::Rejected, self::Paid, self::Expired => false,
        };
    }

    /**
     * The legal direct transitions out of this state, for guard checks.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Submitted => [self::UnderReview, self::Rejected, self::Expired],
            self::UnderReview => [self::Approved, self::Rejected, self::Expired],
            self::Approved => [self::Paid, self::Expired],
            self::Rejected, self::Paid, self::Expired => [],
        };
    }

    /**
     * Whether this state may transition into $target.
     */
    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    /**
     * Badge color for admin surfaces, in Filament vocabulary.
     */
    public function color(): string
    {
        return match ($this) {
            self::Submitted => 'blue',
            self::UnderReview => 'yellow',
            self::Approved => 'green',
            self::Paid => 'success',
            self::Rejected => 'danger',
            self::Expired => 'gray',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
