<?php

declare(strict_types=1);

namespace App\DTOs\Payment;

use App\Enums\PaymentMethodStatus;
use App\Exceptions\PaymentProviderException;

/**
 * Customer-facing payment method identity: provider, method code and
 * currency AS A TRIPLE, bounded by hard decimal limits. The key is
 * deterministic over the triple — the same offer re-registered is the
 * same fact (replay), a different offer under the same triple key can
 * never exist twice because the table's (provider, method, currency)
 * unique index agrees with it.
 */
final readonly class PaymentMethodData
{
    public function __construct(
        public string $providerCode,
        public string $methodCode,
        public string $currency,
        public string $minAmount,
        public string $maxAmount,
        public int $feeBps,
        public PaymentMethodStatus $status,
    ) {
    }

    /**
     * @throws PaymentProviderException
     */
    public static function fromInput(
        string $providerCode,
        string $methodCode,
        string $currency,
        string $minAmount,
        string $maxAmount,
        int $feeBps = 0,
        string|PaymentMethodStatus $status = PaymentMethodStatus::Pending,
    ): PaymentMethodData {
        $providerCode = strtolower(trim($providerCode));
        $methodCode = strtolower(trim($methodCode));
        $currency = strtoupper(trim($currency));
        $min = trim($minAmount);
        $max = trim($maxAmount);

        $status = $status instanceof PaymentMethodStatus
            ? $status
            : PaymentMethodStatus::tryFrom(strtolower(trim($status)));

        if (! preg_match('/^[a-z0-9][a-z0-9\-_]{1,30}[a-z0-9]$/', $providerCode) && ! preg_match('/^[a-z0-9]{2,32}$/', $providerCode)) {
            throw PaymentProviderException::malformed('a provider code is 2-32 lowercase alphanumerics');
        }

        if (! preg_match('/^[a-z0-9_]{2,32}$/', $methodCode)) {
            throw PaymentProviderException::malformed('a method code is a lowercase 2-32 character slug');
        }

        if (! preg_match('/^[A-Z]{3}$/', $currency)) {
            throw PaymentProviderException::malformed('a method currency must be a 3-letter code');
        }

        if (! preg_match('/^\d+(\.\d{1,2})?$/', $min) || ! preg_match('/^\d+(\.\d{1,2})?$/', $max)) {
            throw PaymentProviderException::malformed('method limits are positive decimal strings (money, never float)');
        }

        if (bccomp(self::moneyOf($min), self::moneyOf($max), 2) > 0) {
            throw PaymentProviderException::malformed('method minimum must not exceed its maximum');
        }

        if ($feeBps < 0 || $feeBps > 10_000) {
            throw PaymentProviderException::malformed('the fee basis points must sit in [0, 10000]');
        }

        if (! $status instanceof PaymentMethodStatus) {
            throw PaymentProviderException::malformed('unknown method lifecycle word');
        }

        return new self(
            providerCode: $providerCode,
            methodCode: $methodCode,
            currency: $currency,
            minAmount: self::moneyOf($min),
            maxAmount: self::moneyOf($max),
            feeBps: $feeBps,
            status: $status,
        );
    }

    /**
     * The triple-anchor identity of the offer: deterministic, so a
     * config re-import is a replay, never a duplicate.
     */
    public function methodKey(): string
    {
        return hash('sha256', sprintf('pay-method:%s:%s:%s', $this->providerCode, $this->methodCode, $this->currency));
    }

    private static function moneyOf(string $amount): string
    {
        if (extension_loaded('bcmath')) {
            return bcadd($amount, '0', 2);
        }

        return number_format((float) $amount, 2, '.', '');
    }
}
