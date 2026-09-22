<?php

declare(strict_types=1);

namespace App\DTOs\Payment;

use App\Enums\PaymentProviderStatus;
use App\Exceptions\PaymentProviderException;

/**
 * Provider identity + configuration metadata.
 *
 * SECRETS NEVER ENTER: the DTO's surface carries codes, names, drivers
 * and non-secret config only — no credential field exists here, and
 * the validator REFUSES anything whose key smells like a secret, so a
 * secret can travel through neither identity nor logs via this object.
 */
final readonly class PaymentProviderData
{
    /** @param array<int, string> $supportedCurrencies */
    public function __construct(
        public string $code,
        public string $name,
        public string $driver,
        public PaymentProviderStatus $status,
        public array $supportedCurrencies,
        public array $nonSecrets,
    ) {
    }

    /**
     * @throws PaymentProviderException
     */
    public static function fromInput(
        string $code,
        string $name,
        string $driver,
        string|PaymentProviderStatus $status = PaymentProviderStatus::Pending,
        array $supportedCurrencies = [],
        array $nonSecrets = [],
    ): PaymentProviderData {
        $code = strtolower(trim($code));
        $name = trim($name);
        $driver = strtolower(trim($driver));

        $status = $status instanceof PaymentProviderStatus
            ? $status
            : PaymentProviderStatus::tryFrom(strtolower(trim($status)));

        if (! preg_match('/^[a-z0-9][a-z0-9\-_]{1,30}[a-z0-9]$/', $code) && ! preg_match('/^[a-z0-9]{2,32}$/', $code)) {
            throw PaymentProviderException::malformed('a provider code is 2-32 lowercase alphanumerics');
        }

        if ($name === '' || mb_strlen($name) > 64) {
            throw PaymentProviderException::malformed('a provider needs a human name of 1-64 characters');
        }

        if (! preg_match('/^[a-z0-9_]{2,32}$/', $driver)) {
            throw PaymentProviderException::malformed('a provider driver is a lowercase 2-32 character slug');
        }

        if (! $status instanceof PaymentProviderStatus) {
            throw PaymentProviderException::malformed('unknown provider lifecycle word');
        }

        $currencies = [];
        foreach ($supportedCurrencies as $currency) {
            $c = strtoupper(trim((string) $currency));

            if (! preg_match('/^[A-Z]{3}$/', $c)) {
                throw PaymentProviderException::malformed('supported currencies must be 3-letter codes');
            }

            $currencies[] = $c;
        }

        if ($currencies === []) {
            throw PaymentProviderException::malformed('a provider must serve at least one currency');
        }

        foreach ($nonSecrets as $key => $value) {
            if (preg_match('/secret|token|passw|api_?key|credential|signature/i', (string) $key)) {
                throw PaymentProviderException::secretForbidden((string) $key);
            }
        }

        return new self(
            code: $code,
            name: $name,
            driver: $driver,
            status: $status,
            supportedCurrencies: array_values(array_unique($currencies)),
            nonSecrets: $nonSecrets,
        );
    }

    /**
     * The provider's registry identity — the lowercase code IS the key;
     * the hash is used wherever a fixed-width anchor is needed.
     */
    public function providerKey(): string
    {
        return $this->code;
    }
}
