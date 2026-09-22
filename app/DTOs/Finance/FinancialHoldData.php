<?php

declare(strict_types=1);

namespace App\DTOs\Finance;

/**
 * Financial-hold identity.
 *
 * THE GRAMMAR
 * - wallet_id: positive wallet handle.
 * - amount / currency: strictly positive decimal + 3-letter code.
 * - reason: REQUIRED, plain, honeyed words for a judge — what authority
 *   this hold claims (compliance review, KYC elevation, settlement
 *   staging). 8-255 chars.
 * - source_reference: the provenance token (claims, withdrawals id,
 *   police request id... anything the desk reviewed FROM).
 * - expires_at: optional horizon; an unheld-forever hold is lawful
 *   ONLY while the review queue is moving, so the horizon defaults to
 *   the review lane's own SLA reading, provided by the service.
 *
 * Then: evidence lives with the ACTS (release/expiry/conversion stamp
 * it onto the row), not with the place — the DTO is the payload at
 * placement time.
 */
final readonly class FinancialHoldData
{
    public function __construct(
        public int $walletId,
        public string $amount,
        public string $currency,
        public string $reason,
        public string $sourceReference,
        public ?string $expiresAt = null,
    ) {
    }

    /**
     * @throws \App\Exceptions\FinancialHoldException
     */
    public static function fromInput(
        int $walletId,
        string $amount,
        string $currency,
        string $reason,
        string $sourceReference,
        ?string $expiresAt = null,
    ): self {
        $amt = trim($amount);
        $cur = strtolower(trim($currency));
        $why = trim($reason);
        $src = strtoupper(trim($sourceReference));

        if ($walletId < 1) {
            throw \App\Exceptions\FinancialHoldException::malformed(
                'the wallet handle must be a positive integer',
            );
        }

        if (! preg_match('/^\d+(\.\d{1,2})?$/', $amt)) {
            throw \App\Exceptions\FinancialHoldException::malformed(
                'the amount must be a decimal string (money, never float)',
            );
        }

        if (extension_loaded('bcmath') ? bccomp($amt, '0', 2) !== 1 : ((float) $amt <= 0)) {
            throw \App\Exceptions\FinancialHoldException::malformed(
                'the amount must be strictly positive',
            );
        }

        if (! preg_match('/^[a-z]{3}$/', $cur)) {
            throw \App\Exceptions\FinancialHoldException::malformed(
                'the currency must be a 3-letter code',
            );
        }

        if (strlen($why) < 8 || strlen($why) > 255) {
            throw \App\Exceptions\FinancialHoldException::malformed(
                'the reason must be 8-255 characters (the judge deserves a whole sentence)',
            );
        }

        if (strlen($src) < 8 || strlen($src) > 64 || ! preg_match('/^[A-Z0-9:\-\._]+$/', $src)) {
            throw \App\Exceptions\FinancialHoldException::malformed(
                'the source reference must be a canonical 8-64 character token',
            );
        }

        $expiry = null;

        if ($expiresAt !== null) {
            $candidate = trim($expiresAt);

            try {
                $expiry = \Illuminate\Support\Carbon::parse($candidate)->utc()->toIso8601String();
            } catch (\Throwable) {
                throw \App\Exceptions\FinancialHoldException::malformed(
                    'the expiry, if carried, must be an ISO-8601 instant',
                );
            }
        }

        return new self(
            walletId: $walletId,
            amount: $amt,
            currency: strtoupper($cur),
            reason: $why,
            sourceReference: $src,
            expiresAt: $expiry,
        );
    }

    /**
     * Deterministic hold identity: (wallet, source, amount).
     */
    public function holdKey(): string
    {
        return hash('sha256', sprintf(
            'fin-hold:%d:%s:%s:%s',
            $this->walletId,
            $this->sourceReference,
            $this->amount,
            $this->currency,
        ));
    }
}
