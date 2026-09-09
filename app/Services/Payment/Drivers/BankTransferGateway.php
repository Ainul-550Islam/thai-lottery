<?php

declare(strict_types=1);

namespace App\Services\Payment\Drivers;

use App\DTOs\Payment\GatewayDepositResponse;
use App\DTOs\Payment\GatewayWithdrawalResponse;
use App\DTOs\Payment\WebhookPayload;
use App\Enums\Currency;
use App\Enums\GatewayIntegrationStatus;
use App\Enums\WebhookEventType;
use App\Models\Deposit;
use App\Models\Withdrawal;
use Illuminate\Http\Request;

/**
 * Bank Transfer Gateway Driver (Manual operator review flow).
 */
class BankTransferGateway extends AbstractPaymentGateway
{
    public function name(): string
    {
        return 'bank_transfer';
    }

    public function label(): string
    {
        return 'Bank Transfer';
    }

    public function status(): GatewayIntegrationStatus
    {
        return GatewayIntegrationStatus::ConfiguredOnly;
    }

    public function supportsWebhook(): bool
    {
        return false;
    }

    public function supportsCurrency(Currency $currency): bool
    {
        return true;
    }

    public function initiateDeposit(Deposit $deposit, array $options = []): GatewayDepositResponse
    {
        return GatewayDepositResponse::manual(
            providerReference: 'BANK-SLIP-'.$deposit->reference_number,
            instructions: [
                'bank_name' => 'Bangkok Bank',
                'account_number' => '123-4-56789-0',
                'account_name' => 'Thai Lottery Official Co.',
                'reference' => $deposit->reference_number,
            ],
        );
    }

    public function initiateWithdrawal(Withdrawal $withdrawal, array $options = []): GatewayWithdrawalResponse
    {
        return GatewayWithdrawalResponse::pending(
            providerReference: 'BANK-WD-'.$withdrawal->reference_number,
            rawResponse: [],
            metadata: ['manual_bank_wire' => true],
        );
    }

    public function verifyWebhookSignature(Request $request): bool
    {
        return false;
    }

    public function parseWebhook(Request $request): WebhookPayload
    {
        return new WebhookPayload(
            gateway: $this->name(),
            eventId: 'manual_'.bin2hex(random_bytes(6)),
            eventType: WebhookEventType::Unknown,
            providerReference: null,
            internalReference: null,
            amount: '0.00',
            currency: Currency::THB,
            isSuccess: false,
        );
    }
}
