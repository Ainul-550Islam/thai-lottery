<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Enums\PaymentMethod;
use App\Exceptions\FinancialException;
use App\Services\Payment\Contracts\PaymentGatewayInterface;
use App\Services\Payment\Drivers\BankTransferGateway;
use App\Services\Payment\Drivers\BkashGateway;
use App\Services\Payment\Drivers\CryptoGateway;
use App\Services\Payment\Drivers\NagadGateway;
use App\Services\Payment\Drivers\StripeGateway;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * Gateway factory and driver manager.
 */
class PaymentGatewayManager
{
    /**
     * @var array<string, PaymentGatewayInterface>
     */
    private array $drivers = [];

    public function __construct(
        private readonly Container $container,
        private readonly ConfigRepository $config,
    ) {
    }

    /**
     * Resolve a gateway driver by string name or PaymentMethod enum.
     *
     * @throws FinancialException
     */
    public function driver(string|PaymentMethod $name): PaymentGatewayInterface
    {
        $key = $name instanceof PaymentMethod ? $name->value : strtolower(trim($name));

        if (isset($this->drivers[$key])) {
            return $this->drivers[$key];
        }

        $driver = match ($key) {
            'stripe' => $this->container->make(StripeGateway::class),
            'bkash' => $this->container->make(BkashGateway::class),
            'nagad' => $this->container->make(NagadGateway::class),
            'crypto' => $this->container->make(CryptoGateway::class),
            'bank_transfer', 'manual' => $this->container->make(BankTransferGateway::class),
            default => throw FinancialException::withCode(
                'unsupported_payment_gateway',
                sprintf('Payment gateway driver [%s] is not supported.', $key),
                ['gateway' => $key],
            ),
        };

        $this->drivers[$key] = $driver;

        return $driver;
    }

    /**
     * Resolve gateway for a payment method.
     */
    public function forMethod(PaymentMethod $method): PaymentGatewayInterface
    {
        return $this->driver($method->value);
    }

    /**
     * Check if a gateway driver exists.
     */
    public function hasDriver(string $name): bool
    {
        $key = strtolower(trim($name));

        return in_array($key, ['stripe', 'bkash', 'nagad', 'crypto', 'bank_transfer', 'manual'], true);
    }
}
