<?php

declare(strict_types=1);

namespace Tests\Feature\Settlement;

use App\DTOs\BetPurchaseData;
use App\DTOs\BetPurchaseResult;
use App\DTOs\SettlementSelectionResult;
use App\DTOs\SettlementSimulationResult;
use App\Enums\BetType;
use App\Enums\Currency;
use App\Enums\DrawLifecycleState;
use App\Enums\DrawStatus;
use App\Enums\DrawType;
use App\Enums\LimitStatus;
use App\Models\Draw;
use App\Models\LedgerAccount;
use App\Models\NumberLimit;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Betting\BetPurchaseService;
use App\Services\Draw\DrawLifecycleService;
use App\Services\Draw\DrawResultPublicationService;
use App\Services\Draw\DrawSettlementSimulationService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Shared fixtures for the Phase 5.1 settlement suite.
 *
 * WHY THIS SUITE DOES NOT USE RefreshDatabase
 * RefreshDatabase wraps each test in an open transaction and rolls it back afterwards.
 * That is fatal here for the same two reasons it was in Phase 4.3. First, both the
 * purchase pipeline and DrawSettlementSimulationService assert that they OWN their
 * transaction, so they would refuse to run inside the test's wrapper. Second, an
 * uncommitted test transaction is invisible to other connections, so a concurrency
 * test could never observe a competing run's writes. DatabaseTruncation commits
 * normally and truncates between tests, so the transaction boundaries under test are
 * the real ones.
 *
 * WHY BETS ARE BOUGHT THROUGH THE REAL PIPELINE
 * Selections are created by the verified Phase 4.3 App\Services\Betting\
 * BetPurchaseService rather than inserted by hand. Hand-built rows could carry
 * metadata that the real system never writes, which would let a settlement bug pass.
 * Buying for real also means every test starts from a genuine wallet debit, so the
 * non-monetary assertions compare against the balance and ledger a real purchase
 * leaves behind.
 *
 * FAIL-SAFE DATABASE GUARD
 * Every test refuses to run unless the connection points at an explicit test
 * database.
 */
abstract class SettlementTestCase extends TestCase
{
    use DatabaseTruncation;

    /**
     * The only database names this suite will touch.
     */
    protected const ALLOWED_DATABASES = ['thai_lottery_test', ':memory:'];

    protected const ACCOUNT_SYSTEM_CASH = '1000';

    protected const ACCOUNT_PLAYER_LIABILITY = '2000';

    protected const ACCOUNT_BET_REVENUE = '4000';

    /**
     * A valid six digit first prize whose last three are 123 and last two are 23.
     */
    protected const FIRST_PRIZE = '456123';

    /**
     * A valid two digit bottom result.
     */
    protected const BOTTOM_TWO = '45';

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertTestDatabaseOnly();
        $this->seedChartOfAccounts();
    }

    // ---------------------------------------------------------------------------
    // Services
    // ---------------------------------------------------------------------------

    protected function lifecycle(): DrawLifecycleService
    {
        return app(DrawLifecycleService::class);
    }

    protected function publication(): DrawResultPublicationService
    {
        return app(DrawResultPublicationService::class);
    }

    protected function settlement(): DrawSettlementSimulationService
    {
        return app(DrawSettlementSimulationService::class);
    }

    // ---------------------------------------------------------------------------
    // Fixtures
    // ---------------------------------------------------------------------------

    /**
     * A player with a funded active wallet and an OPEN draw that accepts bets.
     *
     * @return array{user: User, wallet: Wallet, draw: Draw}
     */
    protected function fixture(string $balance = '100000.00'): array
    {
        $user = User::factory()->create();

        $wallet = Wallet::factory()
            ->for($user)
            ->withBalance($balance)
            ->create();

        $draw = Draw::factory()->create([
            'type' => DrawType::ThreeD,
            'scheduled_at' => now()->addHour(),
        ]);

        // Written directly because status and the lifecycle timestamps are outside
        // Draw::$fillable by design; the fixture provisions them the way an operator
        // would rather than weakening the model.
        $draw->status = DrawStatus::Open;
        $draw->betting_open_at = now()->subHour();
        $draw->betting_close_at = now()->addHour();
        $draw->opened_at = now()->subHour();
        $draw->save();

        return ['user' => $user, 'wallet' => $wallet, 'draw' => $draw];
    }

    /**
     * A draw parked in a given lifecycle state, with the matching timestamps.
     *
     * Built here rather than by adding factory states, because DrawFactory is
     * verified Phase 1 code and this phase does not modify it.
     */
    protected function drawInState(DrawLifecycleState $state): Draw
    {
        $draw = Draw::factory()->create([
            'type' => DrawType::ThreeD,
            'scheduled_at' => now()->addHour(),
        ]);

        $draw->status = $state->toDrawStatus();

        // The forward-only order of the lifecycle timestamps. A draw parked in a state
        // carries the stamps of every state it must have passed through, and none of
        // the later ones.
        $sequence = [
            [DrawLifecycleState::Open, 'opened_at', now()->subHours(3)],
            [DrawLifecycleState::Closed, 'closed_at', now()->subHours(2)],
            [DrawLifecycleState::ResultPending, 'drawn_at', now()->subHour()],
            [DrawLifecycleState::ResultPublished, 'result_published_at', now()->subMinutes(30)],
            [DrawLifecycleState::Settled, 'completed_at', now()->subMinutes(15)],
        ];

        // Draft and Cancelled sit outside that forward sequence: Draft precedes all of
        // it and Cancelled is reached by leaving it, so neither stamps any of these
        // columns. draws has no cancelled_at column, which the audit records.
        $inSequence = $state !== DrawLifecycleState::Draft && $state !== DrawLifecycleState::Cancelled;

        if ($inSequence) {
            foreach ($sequence as [$step, $column, $moment]) {
                $draw->{$column} = $moment;

                if ($step === $state) {
                    break;
                }
            }
        }

        if ($state === DrawLifecycleState::Open) {
            $draw->betting_open_at = now()->subHours(3);
            $draw->betting_close_at = now()->addHour();
        }

        $draw->save();

        return $draw->fresh() ?? $draw;
    }

    /**
     * Move an open draw forward to ResultPending through the real lifecycle service.
     */
    protected function advanceToResultPending(Draw $draw): Draw
    {
        $lifecycle = $this->lifecycle();

        $draw = $lifecycle->close($draw, ['stage' => 'test_fixture']);

        return $lifecycle->markResultPending($draw, ['stage' => 'test_fixture']);
    }

    /**
     * Publish a result on a draw, advancing it to ResultPending first.
     *
     * @return array{draw: Draw, result: \App\Models\DrawResult, data: \App\DTOs\DrawResultData}
     */
    protected function publishResult(
        Draw $draw,
        string $firstPrize = self::FIRST_PRIZE,
        string $bottomTwo = self::BOTTOM_TWO,
    ): array {
        $draw = $this->advanceToResultPending($draw);

        $published = $this->publication()->publish((int) $draw->getKey(), [
            'first_prize' => $firstPrize,
            'bottom_two' => $bottomTwo,
        ]);

        return [
            'draw' => $published['draw'],
            'result' => $published['result'],
            'data' => $published['data'],
        ];
    }

    /**
     * Buy one selection through the verified Phase 4.3 purchase pipeline.
     *
     * @param  array{user: User, wallet: Wallet, draw: Draw}  $fixture
     */
    protected function purchase(
        array $fixture,
        string $market,
        string $number,
        string $stake = '10.00',
        ?string $key = null,
    ): BetPurchaseResult {
        $this->ensureLimit($fixture['draw'], $market, $number);

        return app(BetPurchaseService::class)->purchase(new BetPurchaseData(
            userId: (int) $fixture['user']->getKey(),
            drawId: (int) $fixture['draw']->getKey(),
            marketKey: $market,
            rawNumber: $number,
            rawStake: $stake,
            idempotencyKey: $key ?? $this->key($market.'|'.$number.'|'.$stake),
        ));
    }

    /**
     * Buy one selection, publish a result, settle the draw, and return everything.
     *
     * The single most common shape in this suite: one market, one selection, one
     * drawn result, one settled record to assert on.
     *
     * @return array{
     *     fixture: array{user: User, wallet: Wallet, draw: Draw},
     *     purchase: BetPurchaseResult,
     *     simulation: SettlementSimulationResult,
     *     record: SettlementSelectionResult
     * }
     */
    protected function settleOne(
        string $market,
        string $selection,
        string $firstPrize = self::FIRST_PRIZE,
        string $bottomTwo = self::BOTTOM_TWO,
        string $stake = '10.00',
    ): array {
        $fixture = $this->fixture();
        $purchase = $this->purchase($fixture, $market, $selection, $stake);

        $this->publishResult($fixture['draw'], $firstPrize, $bottomTwo);

        $simulation = $this->settlement()->settle((int) $fixture['draw']->getKey());
        $records = $simulation->selections();

        if (count($records) !== 1) {
            $this->fail(sprintf(
                'Expected exactly one settled selection for %s/%s, got %d.',
                $market,
                $selection,
                count($records),
            ));
        }

        return [
            'fixture' => $fixture,
            'purchase' => $purchase,
            'simulation' => $simulation,
            'record' => $records[0],
        ];
    }

    /**
     * The configured multiplier of a market, read at assertion time.
     *
     * Read from configuration rather than written as a literal, so a test asserts
     * that the CONFIGURED rate was applied instead of restating a number that could
     * drift away from configuration.
     */
    protected function configuredMultiplier(string $market): string
    {
        return (string) config('lottery.markets.'.$market.'.payout_multiplier');
    }

    /**
     * stake x multiplier, computed with BCMath at the currency scale.
     */
    protected function expectedPrize(string $stake, string $market): string
    {
        return bcmul($stake, $this->configuredMultiplier($market), 2);
    }

    /**
     * Provision the number_limits row Phase 3.1 requires before a number can sell.
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

        $number = str_pad($rawNumber, $digits, '0', STR_PAD_LEFT);

        $limit = NumberLimit::query()
            ->where('draw_id', $draw->getKey())
            ->where('bet_type', $betType->value)
            ->where('number', $number)
            ->first();

        if ($limit instanceof NumberLimit) {
            return;
        }

        // The counters and the status are outside NumberLimit::$fillable by design, so
        // no payload can mass-assign a risk counter. Set explicitly here.
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

    /**
     * The Phase 2.1 posting service resolves accounts by code and refuses to invent
     * one, so the chart of accounts is seeded rather than assumed.
     */
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
        return 'phase51-'.substr(hash('sha256', $seed.'|'.spl_object_hash($this)), 0, 32);
    }

    // ---------------------------------------------------------------------------
    // Money guards
    // ---------------------------------------------------------------------------

    /**
     * Everything financial, as it stands right now.
     *
     * Read with the query builder so the snapshot reflects the ROWS rather than any
     * model state, and so a table this phase must never write can be watched without
     * importing its model.
     *
     * @return array<string, mixed>
     */
    protected function financeSnapshot(): array
    {
        return [
            'wallets' => DB::table('wallets')
                ->orderBy('id')
                ->get([
                    'id',
                    'balance',
                    'locked_balance',
                    'total_deposited',
                    'total_withdrawn',
                    'total_wagered',
                    // total_won is the column a real prize credit would move, so it is
                    // watched explicitly.
                    'total_won',
                ])
                ->map(static fn (object $row): array => (array) $row)
                ->all(),
            'financial_transactions' => (int) DB::table('financial_transactions')->count(),
            'ledger_entries' => (int) DB::table('ledger_entries')->count(),
            'payouts' => (int) DB::table('payouts')->count(),
            'deposits' => (int) DB::table('deposits')->count(),
            'withdrawals' => (int) DB::table('withdrawals')->count(),
            'payments' => (int) DB::table('payments')->count(),
            'agent_commissions' => (int) DB::table('agent_commissions')->count(),
        ];
    }

    /**
     * Assert nothing financial moved between two snapshots.
     *
     * @param  array<string, mixed>  $before
     */
    protected function assertFinanceUnchanged(array $before, string $because): void
    {
        $after = $this->financeSnapshot();

        $this->assertSame(
            $before['wallets'],
            $after['wallets'],
            'No wallet balance may change: '.$because,
        );
        $this->assertSame(
            $before['financial_transactions'],
            $after['financial_transactions'],
            'No financial transaction may be created: '.$because,
        );
        $this->assertSame(
            $before['ledger_entries'],
            $after['ledger_entries'],
            'No ledger entry may be written: '.$because,
        );
        $this->assertSame(
            $before['payouts'],
            $after['payouts'],
            'No payout may be created: '.$because,
        );

        foreach (['deposits', 'withdrawals', 'payments', 'agent_commissions'] as $table) {
            $this->assertSame(
                $before[$table],
                $after[$table],
                sprintf('No %s row may be created: %s', $table, $because),
            );
        }
    }

    // ---------------------------------------------------------------------------
    // Real multi-process concurrency
    // ---------------------------------------------------------------------------

    /**
     * Row locks only exist on a real server. SQLite in memory gives each connection
     * its own private database, so a concurrency claim measured there would be
     * meaningless.
     */
    protected function requiresRealConcurrency(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            $this->markTestSkipped(
                'Real multi-process concurrency requires a shared server with row locks; '
                .'SQLite in memory gives each process its own database.'
            );
        }
    }

    protected function settleCommand(int $drawId): string
    {
        return sprintf(
            '%s %s %d',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($this->settleProbeScript()),
            $drawId,
        );
    }

    /**
     * Write the settlement probe to the testing scratch directory and return its path.
     *
     * Generated at run time rather than shipped as a console command: a harness that
     * can settle a draw has no business being registered where an operator could
     * invoke it by accident, and it is never packaged.
     */
    protected function settleProbeScript(): string
    {
        $path = storage_path('framework/testing/phase51-settle-probe.php');

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }

        $source = <<<'PROBE'
<?php

declare(strict_types=1);

// Temporary Phase 5.1 concurrency harness. Generated by the test suite; not shipped.

$root = dirname(__DIR__, 3);

require $root.'/vendor/autoload.php';

$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

[$self, $drawId, $barrier] = array_pad($argv, 3, null);

// Wait on the barrier so both processes attempt settlement at the same instant.
if (is_string($barrier)) {
    $deadline = microtime(true) + 20.0;

    while (! file_exists($barrier) && microtime(true) < $deadline) {
        usleep(1000);
    }
}

try {
    $result = $app->make(App\Services\Draw\DrawSettlementSimulationService::class)
        ->settle((int) $drawId);

    echo json_encode([
        'outcome' => $result->alreadySettled ? 'already_settled' : 'settled',
        'selections_written' => $result->selectionsWritten,
        'winning_selections' => $result->winningSelections,
        'total_simulated_prize' => $result->totalSimulatedPrize,
        'fingerprint' => $result->idempotencyFingerprint(),
    ]);

    exit(0);
} catch (Throwable $exception) {
    echo json_encode([
        'outcome' => 'refused',
        'exception' => $exception::class,
        'message' => $exception->getMessage(),
    ]);

    exit(0);
}
PROBE;

        file_put_contents($path, $source);

        return $path;
    }

    /**
     * Run commands in genuinely separate OS processes, started together.
     *
     * Two sequential in-process calls could never detect a missing row lock, because a
     * single process cannot contend with itself. Separate processes hold separate
     * connections and separate transactions, which is the only arrangement in which
     * SELECT ... FOR UPDATE is actually exercised.
     *
     * @param  list<string>  $commands
     * @return list<array<string, mixed>>
     */
    protected function runConcurrently(array $commands): array
    {
        $processes = [];
        $pipes = [];

        // A shared barrier file makes the children start at the same moment rather
        // than in the order they happened to boot.
        $barrier = storage_path('framework/testing/phase51-barrier-'.bin2hex(random_bytes(6)));
        @unlink($barrier);

        foreach ($commands as $index => $command) {
            $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
            $process = proc_open(
                $command.' '.escapeshellarg($barrier),
                $descriptors,
                $processPipes,
                base_path(),
            );

            if (! is_resource($process)) {
                $this->fail('A concurrency probe process could not be started.');
            }

            $processes[$index] = $process;
            $pipes[$index] = $processPipes;
        }

        // Release the barrier once both children are up.
        usleep(400000);
        file_put_contents($barrier, 'go');

        $outcomes = [];

        foreach ($processes as $index => $process) {
            $stdout = (string) stream_get_contents($pipes[$index][1]);
            $stderr = (string) stream_get_contents($pipes[$index][2]);
            fclose($pipes[$index][1]);
            fclose($pipes[$index][2]);
            $exit = proc_close($process);

            $decoded = json_decode(trim($stdout), true);

            $outcomes[] = is_array($decoded)
                ? $decoded
                : ['outcome' => 'unparsable', 'exit' => $exit, 'stdout' => $stdout, 'stderr' => $stderr];
        }

        @unlink($barrier);

        return $outcomes;
    }

    /**
     * @param  list<array<string, mixed>>  $outcomes
     */
    protected function countOutcome(array $outcomes, string $outcome): int
    {
        $count = 0;

        foreach ($outcomes as $entry) {
            if (($entry['outcome'] ?? null) === $outcome) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param  list<array<string, mixed>>  $outcomes
     */
    protected function describe(array $outcomes): string
    {
        return 'Settlement concurrency probe outcomes: '.json_encode($outcomes);
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
