<?php

declare(strict_types=1);

namespace App\Services\Payment\Drivers;

use App\Enums\Currency;
use App\Enums\GatewayIntegrationStatus;
use App\Services\Payment\Contracts\PaymentGatewayInterface;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Request;

/**
 * Base payment gateway driver providing common configuration and validation routines.
 */
abstract class AbstractPaymentGateway implements PaymentGatewayInterface
{
    public function __construct(
        protected readonly ConfigRepository $config,
    ) {
    }

    abstract public function name(): string;

    abstract public function label(): string;

    public function status(): GatewayIntegrationStatus
    {
        return GatewayIntegrationStatus::FullyImplemented;
    }

    public function isEnabled(): bool
    {
        return (bool) $this->config->get("payment.gateways.{$this->name()}.enabled", false);
    }

    public function supportsDeposit(): bool
    {
        return (bool) $this->config->get("payment.gateways.{$this->name()}.supports_deposit", true);
    }

    public function supportsWithdrawal(): bool
    {
        return (bool) $this->config->get("payment.gateways.{$this->name()}.supports_withdrawal", false);
    }

    public function supportsWebhook(): bool
    {
        return true;
    }

    public function supportsCurrency(Currency $currency): bool
    {
        $supported = (array) $this->config->get("payment.gateways.{$this->name()}.supported_currencies", ['THB']);

        return in_array($currency->value, $supported, true);
    }

    /**
     * Resolve webhook secret for signature verification.
     */
    protected function webhookSecret(): ?string
    {
        $secret = $this->config->get("payment.gateways.{$this->name()}.webhook_secret");

        return is_string($secret) && trim($secret) !== '' ? trim($secret) : null;
    }

    /**
     * Resolve signature header name.
     */
    protected function signatureHeader(): string
    {
        return (string) $this->config->get("payment.gateways.{$this->name()}.signature_header", 'X-Signature');
    }

    /**
     * Standard HMAC-SHA256 signature verification.
     */
    protected function verifyHmacSha256(Request $request, ?string $secret = null, ?string $headerName = null): bool
    {
        $secret = $secret ?? $this->webhookSecret();
        $headerName = $headerName ?? $this->signatureHeader();

        if (empty($secret)) {
            return false;
        }

        $provided = (string) $request->header($headerName, '');

        if ($provided === '') {
            return false;
        }

        $expected = hash_hmac('sha256', $request->getContent(), $secret);

        return hash_equals($expected, $provided);
    }

    /**
     * Build callback URL for successful return.
     */
    protected function successUrl(): string
    {
        $path = (string) $this->config->get('payment.callback.success_url', '/payment/success');

        return url($path);
    }

    /**
     * Build callback URL for failed/cancelled return.
     */
    protected function failureUrl(): string
    {
        $path = (string) $this->config->get('payment.callback.failure_url', '/payment/failure');

        return url($path);
    }
}
