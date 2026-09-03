<?php

declare(strict_types=1);

namespace Tests\Feature\Seeders;

use App\Models\LedgerAccount;
use App\Services\Finance\LedgerPostingService;
use App\Services\Finance\WalletService;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Tests\TestCase;

/**
 * The chart of accounts must contain every code the application posts to.
 *
 * WHY THIS EXISTS
 * This is a contract test between two files that have no compile-time link: WalletService
 * declares eight account codes as constants, and LedgerPostingService::resolveAccount()
 * throws if a row with that code is absent. Before LedgerAccountSeeder existed, that
 * contract was satisfied by nobody, and a fresh install failed on its first deposit.
 *
 * The important assertion is the loop over WalletService's own constants via reflection
 * rather than a hand-written list of eight strings. A hand-written list would pass forever
 * after someone added a ninth account code to WalletService and forgot the seeder — which
 * is exactly the failure this test is here to catch.
 */
final class LedgerAccountSeederTest extends TestCase
{
    use DatabaseTruncation;

    /** @var list<string> */
    private const ALLOWED_DATABASES = ['thai_lottery_test', ':memory:'];

    protected function setUp(): void
    {
        parent::setUp();

        $database = \DB::connection()->getDatabaseName();

        $this->assertContains(
            basename((string) $database),
            self::ALLOWED_DATABASES,
            'Refusing to run destructive tests against database: '.$database,
        );
    }

    /**
     * Every ACCOUNT_* constant WalletService declares, read from the class itself.
     *
     * @return list<array{string, string}>
     */
    private function declaredAccountCodes(): array
    {
        $constants = (new \ReflectionClass(WalletService::class))->getConstants();

        $codes = [];

        foreach ($constants as $name => $value) {
            if (str_starts_with($name, 'ACCOUNT_') && is_string($value)) {
                $codes[] = [$name, $value];
            }
        }

        return $codes;
    }

    public function test_wallet_service_declares_account_codes_at_all(): void
    {
        $this->assertNotEmpty(
            $this->declaredAccountCodes(),
            'WalletService declares no ACCOUNT_* constants; this test can no longer detect drift.',
        );
    }

    public function test_the_seeder_creates_a_row_for_every_declared_account_code(): void
    {
        $this->seed(LedgerAccountSeeder::class);

        foreach ($this->declaredAccountCodes() as [$name, $code]) {
            $account = LedgerAccount::query()->where('code', $code)->first();

            $this->assertNotNull(
                $account,
                "WalletService::{$name} is '{$code}', but LedgerAccountSeeder creates no such account. "
                .'A deposit or payout that posts to it will fail with ledger_account_missing.',
            );

            $this->assertNotSame('', trim((string) $account->name));
            $this->assertTrue((bool) $account->is_active);
        }
    }

    public function test_every_seeded_account_is_resolvable_by_the_posting_service(): void
    {
        $this->seed(LedgerAccountSeeder::class);

        $posting = app(LedgerPostingService::class);

        foreach ($this->declaredAccountCodes() as [$name, $code]) {
            $resolved = $posting->resolveAccount($code);

            $this->assertSame(
                $code,
                $resolved->code,
                "LedgerPostingService could not resolve WalletService::{$name}.",
            );
        }
    }

    public function test_the_posting_service_still_refuses_an_unknown_code(): void
    {
        $this->seed(LedgerAccountSeeder::class);

        $this->expectException(\App\Exceptions\FinancialException::class);

        app(LedgerPostingService::class)->resolveAccount('9999-not-an-account');
    }

    public function test_the_seeder_is_idempotent(): void
    {
        $this->seed(LedgerAccountSeeder::class);
        $first = LedgerAccount::query()->count();

        $this->seed(LedgerAccountSeeder::class);
        $second = LedgerAccount::query()->count();

        $this->assertSame($first, $second, 'Re-seeding the chart of accounts duplicated rows.');
        $this->assertSame(count($this->declaredAccountCodes()), $second);
    }

    public function test_re_seeding_does_not_reset_a_posted_balance(): void
    {
        $this->seed(LedgerAccountSeeder::class);

        $account = LedgerAccount::query()->where('code', WalletService::ACCOUNT_PLAYER_LIABILITY)->firstOrFail();

        // Simulate a ledger that has been in use: the balance is posting-derived state.
        $account->forceFill(['current_balance' => '12345.67'])->save();

        $this->seed(LedgerAccountSeeder::class);

        $this->assertSame(
            '12345.67',
            (string) $account->fresh()->current_balance,
            'Re-seeding the chart of accounts overwrote a live posted balance.',
        );
    }

    public function test_the_default_database_seeder_includes_the_chart_of_accounts(): void
    {
        $this->seed(\Database\Seeders\DatabaseSeeder::class);

        $this->assertSame(
            count($this->declaredAccountCodes()),
            LedgerAccount::query()->count(),
            'DatabaseSeeder does not seed the chart of accounts, so a fresh install cannot post.',
        );
    }
}
