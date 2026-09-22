<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The coarse verdict of a ticket verification.
 *
 * Deliberately coarser than TicketStatus: verification is shown to people who
 * may hold a printed or shared ticket and are NOT the owner, so the verdict must
 * confirm existence and broad state without leaking amounts, stake breakdowns or
 * owner identity.
 *
 *   valid     -> the ticket exists, is confirmed and belongs to a live draw
 *   pending   -> the ticket exists but is not confirmed yet
 *   cancelled -> the ticket exists and was cancelled (stake refunded or refunded-as-part-of-amendment)
 *   expired   -> the ticket exists and lapsed before confirmation
 *   not_found -> no such ticket; indistinguishable from a malformed number
 */
enum TicketVerificationStatus: string
{
    case Valid = 'valid';
    case Pending = 'pending';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
    case NotFound = 'not_found';

    /*
     * Batch-8 additive cases: the QR/ticket verification RESULT vocabulary.
     * The original column described how a ticket of record stands; these
     * describe the verdict of a signed-QR verification gesture:
     *   Invalid           — the signature did not hold; the QR may be forged
     *   AlreadyUsed       — the QR was consumed by an earlier verification
     *   OwnershipMismatch — the QR is genuine but belongs to another holder
     * Valid remains the sole gate of admission into any value path.
     */
    case Invalid = 'invalid';
    case AlreadyUsed = 'already_used';
    case OwnershipMismatch = 'ownership_mismatch';

    public function label(): string
    {
        return match ($this) {
            self::Valid => 'Valid ticket',
            self::Pending => 'Awaiting confirmation',
            self::Cancelled => 'Cancelled ticket',
            self::Expired => 'Expired ticket',
            self::NotFound => 'Unknown ticket',
            self::Invalid => 'Invalid signature',
            self::AlreadyUsed => 'Credential already consumed',
            self::OwnershipMismatch => 'Held by a different principal',
        };
    }

    /**
     * Whether the verdict means the ticket currently carries bet value.
     *
     * Existing semantics preserved exactly: Valid or Pending is good
     * standing. The batch-8 adversarial QR cases are additive negatives —
     * they are never "standing" by definition.
     */
    public function isGoodStanding(): bool
    {
        return $this === self::Valid || $this === self::Pending;
    }

    /**
     * The QR-gate's absolute admission test: only a fully VERIFIED verdict
     * (never a could-be-pending one) admits the value-recovery lanes the
     * QR path feeds. Existing callers may keep isGoodStanding()'s wider
     * semantics; the QR service calls this intentionally narrower test.
     */
    public function admitsValuePath(): bool
    {
        return $this === self::Valid;
    }

    /**
     * Whether the verdict belongs to the adversarial QR lane the batch-8
     * service serves (the three negatives it may deliver).
     */
    public function isAdvisoryRefusal(): bool
    {
        return in_array($this, [self::Invalid, self::AlreadyUsed, self::OwnershipMismatch], true);
    }

    /**
     * Map a stored TicketStatus onto the public verification vocabulary.
     */
    public static function fromTicketStatus(TicketStatus $status): self
    {
        return match ($status) {
            TicketStatus::Confirmed => self::Valid,
            TicketStatus::Pending => self::Pending,
            TicketStatus::Cancelled => self::Cancelled,
            TicketStatus::Expired => self::Expired,
        };
    }
}
