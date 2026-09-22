<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Normalized vocabulary of WHY a provider transaction failed. Provider
 * dialects are mapped here ONCE so every lane (callbacks, webhooks,
 * reconciliation, ops dashboards) reads a single alphabet.
 */
enum PaymentFailureReason: string
{
    case ProviderRejected = 'provider_rejected';
    case Timeout = 'timeout';
    case SignatureInvalid = 'signature_invalid';
    case AmountMismatch = 'amount_mismatch';
    case CurrencyMismatch = 'currency_mismatch';
    case ReferenceMismatch = 'reference_mismatch';
    case Expired = 'expired';
    case Duplicate = 'duplicate';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::ProviderRejected => 'Provider rejected the transaction',
            self::Timeout => 'Provider timed out',
            self::SignatureInvalid => 'Signature invalid',
            self::AmountMismatch => 'Amount mismatch',
            self::CurrencyMismatch => 'Currency mismatch',
            self::ReferenceMismatch => 'Reference mismatch',
            self::Expired => 'Window expired',
            self::Duplicate => 'Duplicate evidence',
            self::Unknown => 'Unknown cause',
        };
    }

    /**
     * Normalize a provider-dialect failure word/code. Unknown words
     * deliberately map to Unknown — we refuse to invent certainty.
     */
    public static function fromProviderWord(?string $word): self
    {
        if ($word === null || trim($word) === '') {
            return self::Unknown;
        }

        return match (strtolower(trim($word))) {
            'provider_rejected', 'rejected', 'declined', 'insufficient_funds', 'card_declined', 'do_not_honor' => self::ProviderRejected,
            'timeout', 'timed_out', 'gateway_timeout', 'request_timeout' => self::Timeout,
            'signature_invalid', 'invalid_signature', 'bad_signature', 'authentication_failed' => self::SignatureInvalid,
            'amount_mismatch', 'incorrect_amount', 'amount_does_not_match' => self::AmountMismatch,
            'currency_mismatch', 'incorrect_currency', 'currency_not_supported' => self::CurrencyMismatch,
            'reference_mismatch', 'invalid_reference', 'unknown_reference' => self::ReferenceMismatch,
            'expired', 'session_expired', 'payment_expired', 'stale' => self::Expired,
            'duplicate', 'duplicate_event', 'already_processed', 'idempotent_replay' => self::Duplicate,
            default => self::Unknown,
        };
    }
}
