<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

/**
 * The project test case.
 *
 * WHY THIS CLASS NOW DOES SOMETHING
 * -----------------------------------------------------------------------------
 * The Phase 4.3 / 4.4 / 5.1 suites deliberately do NOT use RefreshDatabase:
 * a purchase asserts it is not already inside a transaction, so a suite-wide
 * wrapping transaction would break the very invariant it is testing. They use
 * DatabaseTruncation instead, which commits normally and truncates between
 * tests.
 *
 * DatabaseTruncation, however, only truncates - it never migrates. Every one of
 * those suites therefore assumed an externally prepared, already-migrated test
 * database, which is exactly how they were run during development
 * (`php artisan migrate` against MariaDB `thai_lottery_test`, with DB_* exported
 * in the shell). On a clean checkout, `php artisan test` used the phpunit.xml
 * defaults instead, hit an empty database, and reported ~211 errors of the form
 * "no such table: ledger_accounts" - failures with no relation to any business
 * rule.
 *
 * This override closes that gap in the only place that can see it: the schema is
 * prepared once per PHPUnit process, BEFORE the traits run, so truncation and
 * every fixture find the tables they expect.
 *
 * WHAT IT WILL NOT DO
 * It never drops or recreates a database, and it never runs against a database
 * whose name is not an explicitly allow-listed test name. `migrate` is
 * idempotent, so on the developer's already-migrated MariaDB test database this
 * is a no-op ("Nothing to migrate") and the historical workflow is unchanged.
 */
abstract class TestCase extends BaseTestCase
{
    /**
     * The database names this bootstrap is allowed to migrate, matched against
     * the connection's database name and against its basename so that a
     * file-backed SQLite path such as `database/thai_lottery_test` is accepted
     * while a production database name never is.
     *
     * @var list<string>
     */
    private const MIGRATABLE_DATABASES = ['thai_lottery_test', ':memory:'];

    /**
     * Guarded per process, not per test: the schema survives the application
     * refresh that happens between tests for every driver except in-memory
     * SQLite, and for in-memory SQLite the check below is re-evaluated because
     * the table genuinely disappears with the connection.
     */
    private static bool $schemaPrepared = false;

    public function createApplication(): Application
    {
        $app = parent::createApplication();

        $this->prepareTestSchema($app);

        return $app;
    }

    private function prepareTestSchema(Application $app): void
    {
        $connection = $app->make('db')->connection();
        $database = (string) $connection->getDatabaseName();

        if (! in_array($database, self::MIGRATABLE_DATABASES, true)
            && ! in_array(basename($database), self::MIGRATABLE_DATABASES, true)) {
            // Not a recognised test database. Do nothing at all: the individual
            // suites carry their own fail-safe guard and will abort themselves.
            return;
        }

        if ($database === ':memory:') {
            // A fresh in-memory database exists per connection, so the cached
            // flag must not be trusted here.
            self::$schemaPrepared = false;
        }

        if (self::$schemaPrepared) {
            return;
        }

        if ($connection->getDriverName() === 'sqlite' && $database !== ':memory:') {
            $path = $connection->getConfig('database');

            if (is_string($path) && $path !== '' && ! file_exists($path)) {
                @touch($path);
            }
        }

        if (! $connection->getSchemaBuilder()->hasTable('users')
            || ! $connection->getSchemaBuilder()->hasTable('ledger_accounts')) {
            $app->make(Kernel::class)->call('migrate', ['--force' => true, '--no-interaction' => true]);
        }

        self::$schemaPrepared = true;
    }

    /**
     * Assert that a money column read with the RAW query builder holds an exact value.
     *
     * WHY THIS EXISTS
     * A value read through Eloquent carries the model's `decimal:2` cast, so it always
     * reads '9000.00'. A value read with DB::table(...)->value(...) bypasses the cast and
     * comes back in the driver's own spelling: MySQL/MariaDB return the decimal(20,2)
     * column as '9000.00', while SQLite's NUMERIC affinity returns '9000'. Both hold the
     * identical amount, so assertSame('9000.00', ...) was asserting the DRIVER's
     * formatting rather than the project's money handling, and eight settlement tests
     * failed on the very database phpunit.xml configures by default.
     *
     * The comparison is made with bccomp at scale 2 - the project's own money rule.
     */
    protected function assertStoredMoneySame(string $expected, mixed $actual, string $message = ''): void
    {
        $raw = is_string($actual) || is_numeric($actual) ? (string) $actual : '';

        $this->assertMatchesRegularExpression(
            '/^-?[0-9]+(\.[0-9]+)?$/',
            $raw,
            $message !== '' ? $message : 'A stored money column must read back as a plain decimal string.',
        );

        $this->assertSame(
            0,
            bccomp($expected, $raw, 2),
            $message !== '' ? $message : sprintf('Stored money must equal %s exactly; the column holds "%s".', $expected, $raw),
        );
    }
}
