<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Provider-side transaction lifecycle, normalized to the house's
 * vocabulary. Providers speak a noisy dialect; this enum is the
 * single voice every payment lane is REQUIRED to speak internally.
 *
 *   Initiated  — the intent exists; the provider hasn't confirmed sight
 *   Pending    — the provider acknowledged; awaiting settlement
 *   Processing — the provider reports funds in motion
 *   Succeeded  — terminal success
 *   Failed     — terminal failure (reason rides the evidence)
 *   Reversed   — a succeeded act undone afterwards (chargeback/refund)
 *
 * Succeeded may become Reversed ONLY — a reversal is an act AFTER
 * finality, and everything else about it is sealed.
 */
enum PaymentTransactionStatus: string
{
    case Initiated = 'initiated';
    case Pending = 'pending';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Reversed = 'reversed';

    public function label(): string
    {
        return match ($this) {
            self::Initiated => 'Initiated',
            self::Pending => 'Pending',
            self::Processing => 'Processing',
            self::Succeeded => 'Succeeded',
            self::Failed => 'Failed',
            self::Reversed => 'Reversed',
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::Initiated => in_array($next, [self::Pending, self::Processing, self::Succeeded, self::Failed], true),
            self::Pending => in_array($next, [self::Processing, self::Succeeded, self::Failed], true),
            self::Processing => in_array($next, [self::Succeeded, self::Failed], true),
            self::Succeeded => $next === self::Reversed,
            self::Failed, self::Reversed => false,
        };
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::Succeeded, self::Failed, self::Reversed], true);
    }

    public function countsAsSuccessEvidence(): bool
    {
        return in_array($this, [self::Succeeded, self::Reversed], true);
    }

    /**
     * Map the INTERNAL Payment aggregate status (legacy lane) onto the
     * provider-transaction vocabulary, for reconciliation lanes.
     */
    public static function fromInternalPaymentStatus(PaymentStatus $internal): self
    {
        return match ($internal) {
            PaymentStatus::Pending => self::Pending,
            PaymentStatus::Authorized => self::Processing,
            PaymentStatus::Captured => self::Succeeded,
            PaymentStatus::Failed, PaymentStatus::Cancelled => self::Failed,
            PaymentStatus::Refunded, PaymentStatus::PartiallyRefunded => self::Reversed,
            PaymentStatus::Disputed => self::Reversed,
        };
    }

    /**
     * Best-effort normalization of a provider-dialect status word.
     * Unknown words return null so the caller refuses LOUDLY rather
     * than guessing.
     */
    public static function fromProviderWord(string $word): ?self
    {
        return match (strtolower(trim($word))) {
            'initiated', 'created', 'new' => self::Initiated,
            'pending', 'awaiting', 'waiting', 'requires_payment_method' => self::Pending,
            'processing', 'in_progress', 'authorized', 'authorizing', 'requires_capture' => self::Processing,
            'succeeded', 'success', 'completed', 'captured', 'paid', 'complete' => self::Succeeded,
            'failed', 'failure', 'declined', 'rejected', 'cancelled', 'canceled', 'expired', 'voided' => self::Failed,
            'reversed', 'refunded', 'chargeback', 'charged_back' => self::Reversed,
            default => null,
        };
    }
}
