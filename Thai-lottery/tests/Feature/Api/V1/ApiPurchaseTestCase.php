<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Enums\BetType;
use App\Enums\Currency;
use App\Enums\DrawStatus;
use App\Enums\DrawType;
use App\Enums\LimitStatus;
use App\Models\Draw;
use App\Models\LedgerAccount;
use App\Models\NumberLimit;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Shared fixtures for the Phase 4.4 API suites.
 *
 * WHY THIS DOES NOT USE RefreshDatabase
 * Identical reasoning to the Phase 4.3 suite, and it is not optional here: RefreshDatabase
 * wraps each test in an open transaction, and Phase 4.3's
 * BetPurchaseTransactionService::assertNotAlreadyInTransaction() throws the moment
 * DB::transactionLevel() is greater than zero. Every purchase in this suite would fail with
 * an invariant violation that has nothing to do with the API. DatabaseTruncation commits
 * normally and truncates between tests, so the transaction boundaries exercised here are
 * the real production ones.
 *
 * FAIL-SAFE DATABASE GUARD
 * Every test refuses to run unless the connection points at an explicitly named test
 * database. There is no code path in this suite that can mutate anything else.
 *
 * FIXTURES ARE BUILT THE SAME WAY AS PHASE 4.3
 * The chart of accounts is seeded here because DatabaseSeeder does not seed ledger accounts,
 * and Phase 2.1's posting requires codes 1000/2000/4000 to exist. Number-limit rows are
 * provisioned explicitly because Phase 3.1's NumberLimitLockService throws
 * RiskConfigurationException::missingLimit() when no row exists for the draw and number -
 * a missing fixture would surface as a risk configuration error and be mistaken for an API
 * bug. Counter columns are assigned as attributes rather than passed to create(), because
 * they are deliberately not fillable.
 */
abstract class ApiPurchaseTestCase extends TestCase
{
    use DatabaseTruncation;

    /**
     * The only database names these suites will touch.
     */
    protected const ALLOWED_DATABASES = ['thai_lottery_test', ':memory:'];

    protected const ACCOUNT_SYSTEM_CASH = '1000';

    protected const ACCOUNT_PLAYER_LIABILITY = '2000';

    protected const ACCOUNT_BET_REVENUE = '4000';

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertTestDatabaseOnly();
        $this->seedChartOfAccounts();
    }

    /**
     * A user with a funded wallet and an open draw.
     *
     * @return array{user: User, wallet: Wallet, draw: Draw}
     */
    protected function fixture(string $balance = '1000.00'): array
    {
        $user = User::factory()->create();

        $wallet = Wallet::factory()
            ->for($user)
            ->withBalance($balance)
            ->create();

        return [
            'user' => $user,
            'wallet' => $wallet,
            'draw' => $this->openDraw(),
        ];
    }

    protected function openDraw(): Draw
    {
        $draw = Draw::factory()->create([
            'type' => DrawType::ThreeD,
            'scheduled_at' => now()->addHour(),
        ]);

        $draw->status = DrawStatus::Open;
        $draw->betting_open_at = now()->subHour();
        $draw->betting_close_at = now()->addHour();
        $draw->opened_at = now()->subHour();
        $draw->save();

        return $draw;
    }

    protected function closedDraw(): Draw
    {
        $draw = Draw::factory()->create([
            'type' => DrawType::ThreeD,
            'scheduled_at' => now()->subMinutes(5),
        ]);

        $draw->status = DrawStatus::Closed;
        $draw->betting_open_at = now()->subHours(3);
        $draw->betting_close_at = now()->subHour();
        $draw->opened_at = now()->subHours(3);
        $draw->closed_at = now()->subHour();
        $draw->save();

        return $draw;
    }

    /**
     * The request body for a single-item purchase.
     *
     * Stake and number are strings throughout. A test that passed an int or a float here
     * would be testing a contract the API deliberately refuses.
     *
     * @return array<string, mixed>
     */
    protected function payload(
        Draw $draw,
        string $market,
        string $number,
        string $stake,
        string $clientKey,
    ): array {
        return [
            'draw_id' => (int) $draw->getKey(),
            'client_key' => $clientKey,
            'items' => [
                ['market' => $market, 'number' => $number, 'stake' => $stake],
            ],
        ];
    }

    /**
     * Provision the number-limit row Phase 3.1 requires for this market and number.
     */
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

        $this->limitRow(
            (int) $draw->getKey(),
            $betType,
            str_pad($rawNumber, $digits, '0', STR_PAD_LEFT),
        );
    }

    /**
     * Provision a limit row and then constrain it, to drive a rejection.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function tightenLimit(Draw $draw, BetType $betType, string $number, array $attributes): NumberLimit
    {
        $limit = $this->limitRow((int) $draw->getKey(), $betType, $number);

        foreach ($attributes as $column => $value) {
            $limit->{$column} = $value;
        }

        $limit->save();

        return $limit;
    }

    protected function limitRow(int $drawId, BetType $betType, string $number): NumberLimit
    {
        $limit = NumberLimit::query()
            ->where('draw_id', $drawId)
            ->where('bet_type', $betType->value)
            ->where('number', $number)
            ->first();

        if ($limit instanceof NumberLimit) {
            return $limit;
        }

        $limit = new NumberLimit();
        $limit->draw_id = $drawId;
        $limit->bet_type = $betType;
        $limit->number = $number;
        $limit->max_amount = '100000.00';
        $limit->current_amount = '0.00';
        $limit->maximum_payout_exposure = null;
        $limit->current_payout_exposure = '0.00';
        $limit->status = LimitStatus::Active;
        $limit->save();

        return $limit;
    }

    protected function seedChartOfAccounts(): void
    {
        $accounts = [
            [self::ACCOUNT_SYSTEM_CASH, 'System Cash', 'asset'],
            [self::ACCOUNT_PLAYER_LIABILITY, 'Player Liability', 'liability'],
            [self::ACCOUNT_BET_REVENUE, 'Bet Revenue', 'revenue'],
        ];

        foreach ($accounts as [$code, $name, $type]) {
            LedgerAccount::query()->firstOrCreate(
                ['code' => $code],
                [
                    'name' => $name,
                    'type' => $type,
                    'currency' => Currency::primary()->value,
                    'is_active' => true,
                ],
            );
        }
    }

    protected function key(string $seed): string
    {
        // Long enough to satisfy config('security.idempotency.min_key_length').
        return 'api-'.substr(hash('sha256', $seed), 0, 40);
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
