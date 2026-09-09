<?php

declare(strict_types=1);

namespace Tests\Feature\Agent;

use App\DTOs\Agent\AgentOnboardingData;
use App\DTOs\BetPurchaseData;
use App\DTOs\BetPurchaseResult;
use App\Enums\AgentStatus;
use App\Enums\BetType;
use App\Enums\Currency;
use App\Enums\DrawStatus;
use App\Enums\DrawType;
use App\Enums\LimitStatus;
use App\Models\Agent;
use App\Models\Draw;
use App\Models\LedgerAccount;
use App\Models\NumberLimit;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Agent\AgentCommissionAccrualService;
use App\Services\Agent\AgentCommissionReversalService;
use App\Services\Agent\AgentCommissionSettlementService;
use App\Services\Agent\AgentOnboardingService;
use App\Services\Agent\AgentReferralService;
use App\Services\Agent\AgentReportingService;
use App\Services\Agent\CommissionCalculationService;
use App\Services\Betting\BetPurchaseService;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

abstract class AgentTestCase extends TestCase
{
    use DatabaseTruncation;

    protected const ALLOWED_DATABASES = ['thai_lottery_test', ':memory:'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertTestDatabaseOnly();
        $this->seedChartOfAccounts();
        $this->configureAgentSettings();
    }

    protected function seedChartOfAccounts(): void
    {
        (new LedgerAccountSeeder())->run();
    }

    protected function configureAgentSettings(): void
    {
        Config::set('agent.enabled', true);
        Config::set('agent.commission_enabled', true);
        Config::set('agent.commission.mode', 'turnover');
        Config::set('agent.hierarchy.enabled', true);
        Config::set('agent.hierarchy.max_depth', 2);
        Config::set('agent.hierarchy.parent_commission_rate', '0.0100');
    }

    // ---------------------------------------------------------------------------
    // Services
    // ---------------------------------------------------------------------------

    protected function onboardingService(): AgentOnboardingService
    {
        return app(AgentOnboardingService::class);
    }

    protected function referralService(): AgentReferralService
    {
        return app(AgentReferralService::class);
    }

    protected function calculationService(): CommissionCalculationService
    {
        return app(CommissionCalculationService::class);
    }

    protected function accrualService(): AgentCommissionAccrualService
    {
        return app(AgentCommissionAccrualService::class);
    }

    protected function settlementService(): AgentCommissionSettlementService
    {
        return app(AgentCommissionSettlementService::class);
    }

    protected function reversalService(): AgentCommissionReversalService
    {
        return app(AgentCommissionReversalService::class);
    }

    protected function reportingService(): AgentReportingService
    {
        return app(AgentReportingService::class);
    }

    // ---------------------------------------------------------------------------
    // Fixtures
    // ---------------------------------------------------------------------------

    /**
     * Create an Agent with a backing user and active wallet.
     */
    protected function createAgent(
        string $commissionRate = '0.0500',
        AgentStatus $status = AgentStatus::Active,
        ?int $parentAgentId = null,
        Currency $currency = Currency::THB,
    ): Agent {
        $user = User::factory()->create();

        $wallet = Wallet::factory()
            ->for($user)
            ->withBalance('0.00')
            ->create(['currency' => $currency]);

        $data = new AgentOnboardingData(
            userId: (int) $user->getKey(),
            commissionRate: $commissionRate,
            parentAgentId: $parentAgentId,
            currency: $currency,
            status: $status,
        );

        return $this->onboardingService()->onboard($data);
    }

    /**
     * Create a player user and wallet, optionally referred by an agent.
     *
     * @return array{user: User, wallet: Wallet}
     */
    protected function createPlayer(
        string $balance = '10000.00',
        ?Agent $referredBy = null,
        Currency $currency = Currency::THB,
    ): array {
        $user = User::factory()->create();

        $wallet = Wallet::factory()
            ->for($user)
            ->withBalance($balance)
            ->create(['currency' => $currency]);

        if ($referredBy instanceof Agent) {
            $this->referralService()->attributeUser($user, $referredBy->agent_code);
            $user->refresh();
        }

        return ['user' => $user, 'wallet' => $wallet];
    }

    /**
     * Create an open draw accepting bets.
     */
    protected function createOpenDraw(DrawType $type = DrawType::ThreeD): Draw
    {
        $draw = Draw::factory()->create([
            'type' => $type,
            'scheduled_at' => now()->addHour(),
        ]);

        $draw->status = DrawStatus::Open;
        $draw->betting_open_at = now()->subHour();
        $draw->betting_close_at = now()->addHour();
        $draw->opened_at = now()->subHour();
        $draw->save();

        return $draw;
    }

    /**
     * Purchase a bet through the verified purchase pipeline.
     */
    protected function purchaseBet(
        User $user,
        Draw $draw,
        string $market = '3d_direct',
        string $number = '123',
        string $stake = '100.00',
        ?string $key = null,
    ): BetPurchaseResult {
        $this->ensureLimit($draw, $market, $number);

        return app(BetPurchaseService::class)->purchase(new BetPurchaseData(
            userId: (int) $user->getKey(),
            drawId: (int) $draw->getKey(),
            marketKey: $market,
            rawNumber: $number,
            rawStake: $stake,
            idempotencyKey: $key ?? sprintf('bet-agent-test-%s-%s', (string) $user->getKey(), bin2hex(random_bytes(8))),
        ));
    }

    protected function ensureLimit(Draw $draw, string $market, string $rawNumber): void
    {
        $definition = config('lottery.markets.'.$market);

        if (! is_array($definition)) {
            return;
        }

        $betType = BetType::tryFrom((string) ($definition['bet_type'] ?? ''));
        $digits = (int) ($definition['digits'] ?? 0);

        if ($betType === null || $digits < 1 || ! ctype_digit($rawNumber) || strlen($rawNumber) > $digits) {
            return;
        }

        $number = str_pad($rawNumber, $digits, '0', STR_PAD_LEFT);

        $limit = NumberLimit::query()
            ->where('draw_id', $draw->getKey())
            ->where('bet_type', $betType->value)
            ->where('number', $number)
            ->first();

        if ($limit instanceof NumberLimit) {
            return;
        }

        $limit = new NumberLimit();
        $limit->draw_id = $draw->getKey();
        $limit->bet_type = $betType;
        $limit->number = $number;
        $limit->max_amount = '1000000.00';
        $limit->current_amount = '0.00';
        $limit->maximum_payout_exposure = null;
        $limit->current_payout_exposure = '0.00';
        $limit->status = LimitStatus::Active;
        $limit->save();
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
