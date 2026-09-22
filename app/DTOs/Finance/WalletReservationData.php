<?php

declare(strict_types=1);

namespace App\DTOs\Finance;

/**
 * Money-reservation identity.
 *
 * THE GRAMMAR
 * - wallet_id: positive wallet handle.
 * - reference: non-empty canonical token for the cause (the bet /
 *   withdrawal / service handle — 8-64 chars; the reservation never
 *   lives without a cause).
 * - amount / currency: decimal string + 3-letter code; strictly positive.
 * - purpose: one of the LedgerEntryPurpose vocabulary — the spend the
 *   reservation is staging for.
 * - expires_at: optional ISO instant (the sweep horizon).
 *
 * The reservation key is DETERMINISTIC over (wallet, reference, amount):
 * one question asked twice is the same reservation; the same question
 * with different money is a different one.
 */
final readonly class WalletReservationData
{
    public function __construct(
        public int $walletId,
        public string $reference,
        public string $amount,
        public string $currency,
        public \App\Enums\LedgerEntryPurpose $purpose,
        public ?string $expiresAt = null,
    ) {
    }

    /**
     * @throws \App\Exceptions\WalletReservationException
     */
    public static function fromInput(
        int $walletId,
        string $reference,
        string $amount,
        string $currency,
        \App\Enums\LedgerEntryPurpose $purpose,
        ?string $expiresAt = null,
    ): self {
        $ref = strtoupper(trim($reference));
        $amt = trim($amount);
        $cur = strtolower(trim($currency));

        if ($walletId < 1) {
            throw \App\Exceptions\WalletReservationException::malformed(
                'the wallet handle must be a positive integer',
            );
        }

        if (strlen($ref) < 8 || strlen($ref) > 64 || ! preg_match('/^[A-Z0-9:\-\._]+$/', $ref)) {
            throw \App\Exceptions\WalletReservationException::malformed(
                'the reference must be a canonical 8-64 character token',
            );
        }

        if (! preg_match('/^\d+(\.\d{1,2})?$/', $amt)) {
            throw \App\Exceptions\WalletReservationException::malformed(
                'the amount must be a decimal string (money, never float)',
            );
        }

        if (extension_loaded('bcmath') ? bccomp($amt, '0', 2) !== 1 : ((float) $amt <= 0)) {
            throw \App\Exceptions\WalletReservationException::malformed(
                'the amount must be strictly positive',
            );
        }

        if (! preg_match('/^[a-z]{3}$/', $cur)) {
            throw \App\Exceptions\WalletReservationException::malformed(
                'the currency must be a 3-letter code',
            );
        }

        $expiry = null;

        if ($expiresAt !== null) {
            $candidate = trim($expiresAt);

            try {
                $expiry = \Illuminate\Support\Carbon::parse($candidate)->utc()->toIso8601String();
            } catch (\Throwable) {
                throw \App\Exceptions\WalletReservationException::malformed(
                    'the expiry, if carried, must be an ISO-8601 instant',
                );
            }
        }

        return new self(
            walletId: $walletId,
            reference: $ref,
            amount: $amt,
            currency: strtoupper($cur),
            purpose: $purpose,
            expiresAt: $expiry,
        );
    }

    /**
     * Deterministic reservation identity: (wallet, reference, amount).
     */
    public function reservationKey(): string
    {
        return hash('sha256', sprintf(
            'wallet-resv:%d:%s:%s:%s',
            $this->walletId,
            $this->reference,
            $this->amount,
            $this->currency,
        ));
    }
}
