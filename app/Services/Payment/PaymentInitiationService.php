<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\DTOs\Payment\GatewayDepositResponse;
use App\Enums\DepositStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Exceptions\DepositException;
use App\Exceptions\FinancialException;
use App\Models\Deposit;
use App\Models\Payment;
use App\Models\Wallet;
use App\Services\Finance\DepositService;
use App\Services\Finance\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Service orchestrating deposit initiation across payment gateway providers.
 */
class PaymentInitiationService
{
    public function __construct(
        private readonly DepositService $depositService,
        private readonly PaymentGatewayManager $gateways,
    ) {
    }

    /**
     * Initiate a deposit with a payment gateway.
     *
     * @param  array<string, mixed>  $options
     * @return array{deposit: Deposit, payment: Payment, gateway_response: GatewayDepositResponse}
     *
     * @throws DepositException
     * @throws FinancialException
     */
    public function initiateDeposit(
        Wallet $wallet,
        Money $amount,
        PaymentMethod $method,
        ?string $idempotencyKey = null,
        array $options = [],
    ): array {
        return DB::transaction(function () use ($wallet, $amount, $method, $idempotencyKey, $options): array {
            // 1. Create Deposit intent record (pending, credits nothing)
            $deposit = $this->depositService->request(
                wallet: $wallet,
                amount: $amount,
                method: $method,
                idempotencyKey: $idempotencyKey,
                options: $options,
            );

            // 2. Resolve Gateway Driver
            $driver = $this->gateways->forMethod($method);

            if (! $driver->supportsCurrency($amount->currency())) {
                throw FinancialException::withCode(
                    'unsupported_gateway_currency',
                    sprintf('Gateway [%s] does not support currency [%s].', $driver->name(), $amount->currency()->value),
                    ['gateway' => $driver->name(), 'currency' => $amount->currency()->value],
                );
            }

            // 3. Request checkout session / invoice from provider
            $gatewayResponse = $driver->initiateDeposit($deposit, $options);

            // 4. Create or update Payment aggregate
            $payment = Payment::query()
                ->where('payable_type', Deposit::class)
                ->where('payable_id', $deposit->getKey())
                ->first();

            if (! $payment instanceof Payment) {
                $payment = new Payment();
                $payment->fill([
                    'reference_number' => 'PAY-'.strtoupper(bin2hex(random_bytes(8))),
                    'user_id' => (int) $wallet->user_id,
                    'payable_type' => Deposit::class,
                    'payable_id' => $deposit->getKey(),
                    'method' => $method,
                    'status' => PaymentStatus::Pending,
                    'currency' => $amount->currency(),
                    'amount' => $amount->toString(),
                    'fee' => (string) $deposit->fee,
                    'gateway' => $driver->name(),
                    'gateway_reference' => $gatewayResponse->providerReference,
                    'gateway_response' => $gatewayResponse->rawResponse,
                    'metadata' => [
                        'deposit_reference' => $deposit->reference_number,
                        'redirect_url' => $gatewayResponse->redirectUrl,
                    ],
                ]);
                $payment->save();
            }

            // 5. Update Deposit with provider details
            if ($gatewayResponse->providerReference !== null) {
                $deposit->provider = $driver->name();
                $deposit->provider_reference = $gatewayResponse->providerReference;
                $metadata = is_array($deposit->metadata) ? $deposit->metadata : [];
                $metadata['redirect_url'] = $gatewayResponse->redirectUrl;
                $metadata['client_secret'] = $gatewayResponse->clientSecret;
                $metadata['payment_id'] = (int) $payment->getKey();
                $deposit->metadata = $metadata;
                $deposit->save();
            }

            return [
                'deposit' => $deposit,
                'payment' => $payment,
                'gateway_response' => $gatewayResponse,
            ];
        });
    }
}
