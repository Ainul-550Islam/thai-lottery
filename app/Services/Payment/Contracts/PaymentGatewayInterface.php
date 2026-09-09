<?php

declare(strict_types=1);

namespace App\Services\Payment\Contracts;

use App\DTOs\Payment\GatewayDepositResponse;
use App\DTOs\Payment\GatewayWithdrawalResponse;
use App\DTOs\Payment\WebhookPayload;
use App\Enums\Currency;
use App\Enums\GatewayIntegrationStatus;
use App\Models\Deposit;
use App\Models\Withdrawal;
use Illuminate\Http\Request;

/**
 * Standard contract for payment gateway providers.
 */
interface PaymentGatewayInterface
{
    /**
     * Unique identifier for this gateway (e.g. 'stripe', 'bkash', 'nagad', 'crypto').
     */
    public function name(): string;

    /**
     * Human-readable label.
     */
    public function label(): string;

    /**
     * Integration maturity and automation status.
     */
    public function status(): GatewayIntegrationStatus;

    /**
     * Whether this gateway is configured and enabled in environment.
     */
    public function isEnabled(): bool;

    /**
     * Whether this gateway supports automated deposit collection.
     */
    public function supportsDeposit(): bool;

    /**
     * Whether this gateway supports automated withdrawal payouts.
     */
    public function supportsWithdrawal(): bool;

    /**
     * Whether this gateway supports webhooks.
     */
    public function supportsWebhook(): bool;

    /**
     * Check if a specific currency is supported by this gateway.
     */
    public function supportsCurrency(Currency $currency): bool;

    /**
     * Initiate a deposit checkout/session with the provider.
     *
     * @param  array<string, mixed>  $options
     */
    public function initiateDeposit(Deposit $deposit, array $options = []): GatewayDepositResponse;

    /**
     * Initiate a withdrawal payout through the provider.
     *
     * @param  array<string, mixed>  $options
     */
    public function initiateWithdrawal(Withdrawal $withdrawal, array $options = []): GatewayWithdrawalResponse;

    /**
     * Cryptographically verify an inbound webhook signature.
     */
    public function verifyWebhookSignature(Request $request): bool;

    /**
     * Parse and normalize an inbound webhook payload.
     */
    public function parseWebhook(Request $request): WebhookPayload;
}
