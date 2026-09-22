<?php

declare(strict_types=1);

namespace App\DTOs\Payment;

use App\Enums\PaymentDirection;
use App\Exceptions\PaymentIntentException;

/**
 * A deposit/withdrawal payment intent.
 *
 * IDENTITY: the user + wallet are the `WHO` (derived server-side at
 * the controller, never accepted from the client body), amount +
 * currency + direction + method are the `WHAT`, and the client-
 * supplied idempotency key + direction together form the `WHICH ONE`.
 * The deterministic intent key over all of it means a retried ask is
 * the SAME fact — and a same-key ask with different facts is a fork.
 */
final readonly class PaymentIntentData
{
    public function __construct(
        public int $userId,
        public int $walletId,
        public PaymentDirection $direction,
        public string $amount,
        public string $currency,
        public string $methodCode,
        public string $idempotencyKey,
        public ?string $expiresAt,
    ) {
    }

    /**
     * @throws PaymentIntentException
     */
    public static function fromInput(
        int $userId,
        int $walletId,
        string|PaymentDirection $direction,
        string $amount,
        string $currency,
        string $methodCode,
        string $idempotencyKey,
        ?string $expiresAt = null,
    ): PaymentIntentData {
        $amt = trim($amount);
        $cur = strtoupper(trim($currency));
        $method = strtolower(trim($methodCode));
        $key = trim($idempotencyKey);

        $direction = $direction instanceof PaymentDirection
            ? $direction
            : PaymentDirection::tryFrom(strtolower(trim($direction)));

        if ($userId < 1 || $walletId < 1) {
            throw PaymentIntentException::malformed('the intent must name a real user and wallet');
        }

        if (! $direction instanceof PaymentDirection) {
            throw PaymentIntentException::malformed('the direction must be deposit or withdrawal');
        }

        if (! preg_match('/^\d+(\.\d{1,2})?$/', $amt)) {
            throw PaymentIntentException::malformed('the amount must be a positive decimal string (money, never float)');
        }

        if (bccomp(self::moneyOf($amt), '0', 2) <= 0) {
            throw PaymentIntentException::malformed('the amount must be positive — a zero intent moves nothing');
        }

        if (! preg_match('/^[A-Z]{3}$/', $cur)) {
            throw PaymentIntentException::malformed('the currency must be a 3-letter code');
        }

        if (! preg_match('/^[a-z0-9_]{2,32}$/', $method)) {
            throw PaymentIntentException::malformed('the method must be a lowercase 2-32 character slug');
        }

        if (strlen($key) < 8 || strlen($key) > 96 || ! preg_match('/^[A-Za-z0-9:\-_.]+$/', $key)) {
            throw PaymentIntentException::malformed('the idempotency key must be a canonical 8-96 character token');
        }

        return new self(
            userId: $userId,
            walletId: $walletId,
            direction: $direction,
            amount: self::moneyOf($amt),
            currency: $cur,
            methodCode: $method,
            idempotencyKey: $key,
            expiresAt: $expiresAt,
        );
    }

    /**
     * The deterministic intent identity. Direction is salted in on
     * purpose: a deposit and a withdrawal with identical everything
     * else are different facts, forever.
     */
    public function intentKey(): string
    {
        return hash('sha256', sprintf(
            'pay-intent:%d:%d:%s:%s:%s:%s',
            $this->userId,
            $this->walletId,
            $this->direction->value,
            $this->amount,
            $this->currency,
            $this->idempotencyKey,
        ));
    }

    private static function moneyOf(string $amount): string
    {
        if (extension_loaded('bcmath')) {
            return bcadd($amount, '0', 2);
        }

        return number_format((float) $amount, 2, '.', '');
    }
}
