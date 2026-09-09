<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Enums\Currency;
use App\Enums\PaymentMethod;
use App\Models\Deposit;
use App\Models\User;
use App\Models\Wallet;
use App\Models\Withdrawal;
use App\Services\Finance\Money;
use App\Services\Payment\PaymentGatewayManager;
use App\Services\Payment\PaymentWebhookService;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

abstract class PaymentTestCase extends TestCase
{
    use DatabaseTruncation;

    protected const ALLOWED_DATABASES = ['thai_lottery_test', ':memory:'];

    protected const STRIPE_SECRET = 'sk_test_mock_secret_key_12345';
    protected const STRIPE_WEBHOOK_SECRET = 'whsec_mock_stripe_webhook_secret_67890';
    protected const BKASH_WEBHOOK_SECRET = 'mock_bkash_webhook_secret_12345';
    protected const NAGAD_WEBHOOK_SECRET = 'mock_nagad_webhook_secret_12345';
    protected const CRYPTO_WEBHOOK_SECRET = 'mock_crypto_webhook_secret_12345';

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertTestDatabaseOnly();
        $this->seedChartOfAccounts();
        $this->configureGatewaySecrets();
    }

    protected function seedChartOfAccounts(): void
    {
        (new LedgerAccountSeeder())->run();
    }

    protected function configureGatewaySecrets(): void
    {
        Config::set('payment.webhook.verify_signature', true);
        Config::set('payment.webhook.max_age_seconds', 300);

        Config::set('payment.gateways.stripe.enabled', true);
        Config::set('payment.gateways.stripe.secret', self::STRIPE_SECRET);
        Config::set('payment.gateways.stripe.webhook_secret', self::STRIPE_WEBHOOK_SECRET);

        Config::set('payment.gateways.bkash.enabled', true);
        Config::set('payment.gateways.bkash.app_key', 'bkash_app_key');
        Config::set('payment.gateways.bkash.app_secret', self::BKASH_WEBHOOK_SECRET);
        Config::set('payment.gateways.bkash.webhook_secret', self::BKASH_WEBHOOK_SECRET);

        Config::set('payment.gateways.nagad.enabled', true);
        Config::set('payment.gateways.nagad.merchant_id', 'nagad_merchant');
        Config::set('payment.gateways.nagad.app_secret', self::NAGAD_WEBHOOK_SECRET);
        Config::set('payment.gateways.nagad.webhook_secret', self::NAGAD_WEBHOOK_SECRET);

        Config::set('payment.gateways.crypto.enabled', true);
        Config::set('payment.gateways.crypto.webhook_secret', self::CRYPTO_WEBHOOK_SECRET);
    }

    /**
     * @return array{user: User, wallet: Wallet}
     */
    protected function createPlayer(string $balance = '500.00', Currency $currency = Currency::THB): array
    {
        $user = User::factory()->create();

        $wallet = Wallet::factory()
            ->for($user)
            ->withBalance($balance)
            ->create(['currency' => $currency]);

        return ['user' => $user, 'wallet' => $wallet];
    }

    protected function createPendingDeposit(
        Wallet $wallet,
        string $amount = '1000.00',
        PaymentMethod $method = PaymentMethod::Stripe,
    ): Deposit {
        $deposit = new Deposit();
        $deposit->fill([
            'reference_number' => 'DP-'.date('Ymd').'-'.strtoupper(bin2hex(random_bytes(4))),
            'user_id' => $wallet->user_id,
            'wallet_id' => $wallet->id,
            'method' => $method,
            'provider' => $method->value,
            'currency' => $wallet->currency,
            'amount' => $amount,
            'fee' => '0.00',
            'net_amount' => $amount,
            'metadata' => [],
        ]);
        $deposit->uuid = (string) \Illuminate\Support\Str::uuid();
        $deposit->status = \App\Enums\DepositStatus::Pending;
        $deposit->save();

        return $deposit;
    }

    protected function webhookService(): PaymentWebhookService
    {
        return app(PaymentWebhookService::class);
    }

    protected function gatewayManager(): PaymentGatewayManager
    {
        return app(PaymentGatewayManager::class);
    }

    protected function assertTestDatabaseOnly(): void
    {
        $database = (string) DB::connection()->getDatabaseName();
        $basename = basename($database);

        if (! in_array($database, static::ALLOWED_DATABASES, true)
            && ! in_array($basename, static::ALLOWED_DATABASES, true)) {
            $this->fail(sprintf(
                'ABORTED: this suite may only run against %s. The connection points at "%s".',
                implode(' or ', static::ALLOWED_DATABASES),
                $database,
            ));
        }
    }
}
