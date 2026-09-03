<?php

declare(strict_types=1);

namespace Tests\Feature\Settlement;

use App\Services\Draw\DrawSettlementSimulationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * PHASE 5.1 - requirement I points 21 to 24 and point 30.
 *
 * The single most important property of this phase: settlement is a SIMULATION. It
 * decides who would have won and what the configured multiplier would have paid, and it
 * records that decision as an audit result. It moves no money.
 *
 * These tests prove that behaviourally - by photographing every financial table before a
 * settlement and re-photographing it afterwards - and structurally, by reading the
 * source of the Phase 5.1 services.
 *
 * Point 21 - settlement does not mutate any wallet.
 * Point 22 - settlement does not mutate any ledger.
 * Point 23 - settlement creates no financial transaction.
 * Point 24 - settlement calls no payment gateway.
 * Point 30 - no payout amount can be supplied by a client.
 */
final class NonMonetarySettlementTest extends SettlementTestCase
{
    /**
     * Every table in the schema that represents real money or a real money obligation.
     * Nothing in Phase 5.1 may insert into, update from or delete from any of them.
     *
     * @var list<string>
     */
    private const FINANCIAL_TABLES = [
        'wallets',
        'financial_transactions',
        'ledger_accounts',
        'ledger_entries',
        'payouts',
        'deposits',
        'withdrawals',
        'payments',
        'agent_commissions',
    ];

    /**
     * The Phase 5.1 source files, as project relative paths.
     *
     * @var list<string>
     */
    private const PHASE_51_FILES = [
        'app/Enums/DrawLifecycleState.php',
        'app/Enums/SettlementSimulationStatus.php',
        'app/Exceptions/DrawLifecycleException.php',
        'app/Exceptions/DrawResultValidationException.php',
        'app/Exceptions/SettlementSimulationException.php',
        'app/DTOs/DrawResultData.php',
        'app/DTOs/SettlementSelectionResult.php',
        'app/DTOs/SettlementSimulationResult.php',
        'app/Services/Draw/DrawLifecycleService.php',
        'app/Services/Draw/DrawResultValidator.php',
        'app/Services/Draw/DrawResultPublicationService.php',
        'app/Services/Draw/SelectionSettlementResolver.php',
        'app/Services/Draw/DrawSettlementSimulationService.php',
    ];

    // -----------------------------------------------------------------------------
    // Point 21 - no wallet mutation
    // -----------------------------------------------------------------------------

    #[Test]
    public function point_21_a_large_simulated_win_does_not_credit_the_wallet(): void
    {
        $fixture = $this->fixture();
        $purchase = $this->purchase($fixture, '3d_direct', '123', '100.00');
        $this->publishResult($fixture['draw']);

        // Photograph every wallet column AFTER the purchase, so the only thing under test
        // is what settlement does.
        $before = $this->financeSnapshot();
        $walletBefore = DB::table('wallets')->where('id', $fixture['wallet']->getKey())->first();
        $this->assertNotNull($walletBefore);

        $simulation = $this->settlement()->settle((int) $fixture['draw']->getKey());

        // The simulation says this selection would have won 90000.00.
        $this->assertSame(1, $simulation->winningSelections);
        $this->assertSame('90000.00', $simulation->totalSimulatedPrize);

        // And not one satoshi of it reached the wallet.
        $walletAfter = DB::table('wallets')->where('id', $fixture['wallet']->getKey())->first();
        $this->assertNotNull($walletAfter);

        foreach (['balance', 'locked_balance', 'total_deposited', 'total_withdrawn', 'total_wagered', 'total_won'] as $column) {
            $this->assertSame(
                (string) $walletBefore->{$column},
                (string) $walletAfter->{$column},
                'Settlement changed wallets.'.$column.', which a simulation must never do.',
            );
        }

        $this->assertFinanceUnchanged($before, 'a winning simulated settlement');
        $this->assertSame(0, $simulation->context['wallets_touched'] ?? 0);
    }

    #[Test]
    public function point_21b_a_losing_settlement_does_not_debit_the_wallet(): void
    {
        $fixture = $this->fixture();
        $this->purchase($fixture, '3d_direct', '789', '10.00');
        $this->publishResult($fixture['draw']);

        $before = $this->financeSnapshot();

        $simulation = $this->settlement()->settle((int) $fixture['draw']->getKey());

        $this->assertSame(0, $simulation->winningSelections);
        $this->assertSame('0.00', $simulation->totalSimulatedPrize);
        $this->assertFinanceUnchanged($before, 'a losing simulated settlement');
    }

    #[Test]
    public function point_21c_the_replay_of_a_settled_draw_does_not_touch_the_wallet(): void
    {
        $outcome = $this->settleOne('3d_direct', '123', self::FIRST_PRIZE, self::BOTTOM_TWO, '100.00');

        $before = $this->financeSnapshot();

        $replay = $this->settlement()->settle((int) $outcome['fixture']['draw']->getKey());

        $this->assertTrue($replay->alreadySettled);
        $this->assertTrue($replay->wroteNothing());
        $this->assertFinanceUnchanged($before, 'the idempotent replay of a settled draw');
    }

    #[Test]
    public function point_21d_settlement_never_locks_or_releases_a_balance(): void
    {
        $fixture = $this->fixture();
        $this->purchase($fixture, '2d_top', '23', '20.00');
        $this->publishResult($fixture['draw']);

        $lockedBefore = (string) DB::table('wallets')
            ->where('id', $fixture['wallet']->getKey())
            ->value('locked_balance');

        $this->settlement()->settle((int) $fixture['draw']->getKey());

        $this->assertSame(
            $lockedBefore,
            (string) DB::table('wallets')->where('id', $fixture['wallet']->getKey())->value('locked_balance'),
            'Settlement must not release or add a hold; holds belong to the Phase 4.3 purchase path.',
        );
    }

    // -----------------------------------------------------------------------------
    // Point 22 - no ledger mutation
    // -----------------------------------------------------------------------------

    #[Test]
    public function point_22_settlement_writes_no_ledger_entry(): void
    {
        $fixture = $this->fixture();
        $this->purchase($fixture, '3d_direct', '123', '50.00');
        $this->publishResult($fixture['draw']);

        $entriesBefore = DB::table('ledger_entries')->count();
        $accountsBefore = DB::table('ledger_accounts')->count();
        $balancesBefore = DB::table('ledger_accounts')->orderBy('id')->pluck('current_balance')->all();

        $this->settlement()->settle((int) $fixture['draw']->getKey());

        $this->assertSame(
            $entriesBefore,
            DB::table('ledger_entries')->count(),
            'A simulated settlement must not create a double entry ledger row.',
        );
        $this->assertSame($accountsBefore, DB::table('ledger_accounts')->count());
        $this->assertSame(
            $balancesBefore,
            DB::table('ledger_accounts')->orderBy('id')->pluck('current_balance')->all(),
            'No ledger account balance may move during a simulation.',
        );
    }

    #[Test]
    public function point_22b_the_settlement_service_does_not_reference_the_ledger(): void
    {
        $source = $this->sourceOf('app/Services/Draw/DrawSettlementSimulationService.php');

        foreach (['LedgerEntry', 'LedgerAccount', 'LedgerService', 'DoubleEntry'] as $symbol) {
            $this->assertStringNotContainsString(
                $symbol,
                $this->codeOnly($source),
                'The settlement service must not reference '.$symbol.'.',
            );
        }
    }

    // -----------------------------------------------------------------------------
    // Point 23 - no financial transaction
    // -----------------------------------------------------------------------------

    #[Test]
    public function point_23_settlement_creates_no_financial_transaction(): void
    {
        $fixture = $this->fixture();
        $purchase = $this->purchase($fixture, '3d_direct', '123', '100.00');
        $this->publishResult($fixture['draw']);

        $transactionsBefore = DB::table('financial_transactions')->count();
        $payoutsBefore = DB::table('payouts')->count();

        $this->settlement()->settle((int) $fixture['draw']->getKey());

        $this->assertSame(
            $transactionsBefore,
            DB::table('financial_transactions')->count(),
            'A simulation must not create a financial transaction.',
        );
        $this->assertSame(
            $payoutsBefore,
            DB::table('payouts')->count(),
            'A payouts row is a real money obligation and must never be created here.',
        );

        // payouts is the real money table. bets.payout_id is the link to it, and it must
        // stay NULL even for a bet the simulation marked as won.
        $this->assertNull(
            DB::table('bets')->where('id', $purchase->betId())->value('payout_id'),
            'bets.payout_id must stay NULL; a simulated win is not a payout.',
        );
        $this->assertSame(
            'won',
            (string) DB::table('bets')->where('id', $purchase->betId())->value('status'),
        );
    }

    #[Test]
    public function point_23b_no_deposit_withdrawal_or_payment_row_is_created(): void
    {
        $before = $this->financeSnapshot();

        $this->settleOne('3d_direct', '123', self::FIRST_PRIZE, self::BOTTOM_TWO, '100.00');

        foreach (['deposits', 'withdrawals', 'payments', 'agent_commissions'] as $table) {
            $this->assertSame(
                $before[$table],
                DB::table($table)->count(),
                'Settlement inserted into '.$table.'.',
            );
        }
    }

    #[Test]
    public function point_23c_the_declared_write_set_is_exactly_what_is_written(): void
    {
        $audit = $this->settlement()->audit();

        $this->assertSame(['bet_items', 'bets', 'draws', 'audit_logs'], $audit['tables_written']);

        // Every financial table in the schema is named in the forbidden list.
        foreach (self::FINANCIAL_TABLES as $table) {
            if ($table === 'ledger_accounts') {
                // Named in the forbidden list under both of its schema names.
                $this->assertContains($table, DrawSettlementSimulationService::FORBIDDEN_TABLES);

                continue;
            }

            $this->assertContains(
                $table,
                DrawSettlementSimulationService::FORBIDDEN_TABLES,
                $table.' is a financial table and must be declared forbidden.',
            );
        }

        // And the two sets do not overlap.
        $this->assertSame(
            [],
            array_values(array_intersect($audit['tables_written'], DrawSettlementSimulationService::FORBIDDEN_TABLES)),
        );
    }

    #[Test]
    public function point_23d_the_row_counts_of_every_financial_table_are_stable_across_a_full_cycle(): void
    {
        $before = $this->financeSnapshot();

        $fixture = $this->fixture();
        $this->purchase($fixture, 'run_bottom', '4', '30.00');
        $this->publishResult($fixture['draw']);
        $this->settlement()->settle((int) $fixture['draw']->getKey());
        $this->settlement()->settle((int) $fixture['draw']->getKey());

        $after = $this->financeSnapshot();

        // The purchase legitimately moves the wallet, so only the INSERT counts of the
        // real money tables are compared here.
        foreach (['payouts', 'deposits', 'withdrawals', 'payments', 'agent_commissions'] as $table) {
            $this->assertSame(
                $before[$table],
                $after[$table],
                $table.' gained a row during publish plus settle plus replay.',
            );
        }
    }

    // -----------------------------------------------------------------------------
    // Point 24 - no payment gateway
    // -----------------------------------------------------------------------------

    #[Test]
    public function point_24_no_outbound_http_request_is_made_during_settlement(): void
    {
        // Any attempt to reach a gateway over HTTP would be recorded by the fake.
        Http::preventStrayRequests();
        Http::fake();

        $fixture = $this->fixture();
        $this->purchase($fixture, '3d_direct', '123', '100.00');
        $this->publishResult($fixture['draw']);
        $simulation = $this->settlement()->settle((int) $fixture['draw']->getKey());

        $this->assertSame(1, $simulation->winningSelections);
        Http::assertNothingSent();
    }

    #[Test]
    public function point_24b_the_phase_51_sources_reference_no_gateway_or_transport(): void
    {
        $forbidden = [
            'Http::',
            'GuzzleHttp',
            'curl_init',
            'curl_exec',
            'file_get_contents',
            'fsockopen',
            'stream_socket_client',
            'Stripe',
            'PayPal',
            'Omnipay',
            'Gateway',
            'Checkout',
        ];

        foreach (self::PHASE_51_FILES as $file) {
            $code = $this->codeOnly($this->sourceOf($file));

            foreach ($forbidden as $needle) {
                $this->assertStringNotContainsString(
                    $needle,
                    $code,
                    $file.' references '.$needle.', which suggests a payment or network path.',
                );
            }
        }
    }

    #[Test]
    public function point_24c_the_phase_51_sources_import_no_financial_class(): void
    {
        $forbiddenImports = [
            'App\\Models\\Wallet',
            'App\\Models\\FinancialTransaction',
            'App\\Models\\LedgerEntry',
            'App\\Models\\LedgerAccount',
            'App\\Models\\Payout',
            'App\\Models\\Deposit',
            'App\\Models\\Withdrawal',
            'App\\Models\\Payment',
            'App\\Models\\AgentCommission',
            'App\\Services\\Wallet',
            'App\\Services\\Finance',
            'App\\Services\\Payment',
        ];

        foreach (self::PHASE_51_FILES as $file) {
            $source = $this->sourceOf($file);

            foreach ($forbiddenImports as $import) {
                $this->assertStringNotContainsString(
                    'use '.$import,
                    $source,
                    $file.' imports '.$import.'; Phase 5.1 must not depend on the money domain.',
                );
            }
        }
    }

    #[Test]
    public function point_24d_no_queued_job_or_event_can_carry_the_settlement_into_the_money_domain(): void
    {
        foreach (self::PHASE_51_FILES as $file) {
            $code = $this->codeOnly($this->sourceOf($file));

            foreach (['dispatch(', 'Bus::', 'Queue::', 'event(', 'Event::dispatch'] as $needle) {
                $this->assertStringNotContainsString(
                    $needle,
                    $code,
                    $file.' dispatches '.$needle.'; Phase 5.1 writes its own rows and hands off to nothing.',
                );
            }
        }
    }

    // -----------------------------------------------------------------------------
    // Point 30 - no client controlled payout amount
    // -----------------------------------------------------------------------------

    #[Test]
    public function point_30_settle_accepts_a_draw_id_and_nothing_else(): void
    {
        $method = new ReflectionMethod(DrawSettlementSimulationService::class, 'settle');

        $this->assertCount(
            1,
            $method->getParameters(),
            'settle() must take exactly one parameter, so there is nothing for a client to inject.',
        );

        $parameter = $method->getParameters()[0];
        $this->assertSame('drawId', $parameter->getName());

        $type = $parameter->getType();
        $this->assertInstanceOf(ReflectionNamedType::class, $type);
        $this->assertSame('int', $type->getName());
        $this->assertFalse($type->allowsNull());
        $this->assertFalse($parameter->isOptional());
        $this->assertFalse($parameter->isVariadic(), 'A variadic tail would be an injection point.');
    }

    #[Test]
    public function point_30b_no_public_method_of_the_settlement_service_accepts_an_amount(): void
    {
        $reflection = new ReflectionClass(DrawSettlementSimulationService::class);
        $suspicious = ['amount', 'payout', 'prize', 'multiplier', 'winner', 'force', 'override', 'skip', 'bypass'];

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getDeclaringClass()->getName() !== DrawSettlementSimulationService::class) {
                continue;
            }

            if ($method->isConstructor()) {
                continue;
            }

            foreach ($method->getParameters() as $parameter) {
                $name = strtolower($parameter->getName());

                foreach ($suspicious as $needle) {
                    $this->assertStringNotContainsString(
                        $needle,
                        $name,
                        $method->getName().'() accepts $'.$parameter->getName().', which a caller could use to '
                        .'dictate an outcome.',
                    );
                }
            }
        }
    }

    #[Test]
    public function point_30c_the_stored_prize_is_the_configured_product_and_not_anything_a_caller_asked_for(): void
    {
        $fixture = $this->fixture();
        $purchase = $this->purchase($fixture, '3d_direct', '123', '10.00');

        // Plant a hostile value on the row before settlement. The purchase path does not
        // set actual_payout, so this simulates a tampered or stale row.
        DB::table('bet_items')->where('id', $purchase->betItemId())->update([
            'actual_payout' => '99999.99',
            'payout_multiplier' => 5000,
        ]);

        $this->publishResult($fixture['draw']);
        $simulation = $this->settlement()->settle((int) $fixture['draw']->getKey());

        $record = $simulation->forBetItem($purchase->betItemId());
        $this->assertNotNull($record);

        // The planted numbers are gone, replaced by the configured computation.
        $expected = bcmul('10.00', $this->configuredMultiplier('3d_direct'), 2);
        $this->assertSame($expected, $record->simulatedPrize());
        $this->assertStoredMoneySame(
            $expected,
            DB::table('bet_items')->where('id', $purchase->betItemId())->value('actual_payout'),
        );
        $this->assertSame(
            (int) $this->configuredMultiplier('3d_direct'),
            (int) DB::table('bet_items')->where('id', $purchase->betItemId())->value('payout_multiplier'),
        );
        $this->assertNotSame('99999.99', $record->simulatedPrize());
    }

    #[Test]
    public function point_30d_a_tampered_stored_multiplier_cannot_change_the_win_decision(): void
    {
        $fixture = $this->fixture();
        $purchase = $this->purchase($fixture, '3d_direct', '789', '10.00');

        DB::table('bet_items')->where('id', $purchase->betItemId())->update([
            'is_winner' => true,
            'actual_payout' => '123456.78',
        ]);

        $this->publishResult($fixture['draw']);
        $simulation = $this->settlement()->settle((int) $fixture['draw']->getKey());

        // 789 does not match the drawn 123, and a pre-set winner flag does not make it.
        $this->assertSame(0, $simulation->winningSelections);
        $this->assertSame('0.00', $simulation->totalSimulatedPrize);
        $this->assertSame(
            0,
            (int) DB::table('bet_items')->where('id', $purchase->betItemId())->value('is_winner'),
            'The decision comes from the verified match services, not from the stored flag.',
        );
        $this->assertStoredMoneySame(
            '0.00',
            DB::table('bet_items')->where('id', $purchase->betItemId())->value('actual_payout'),
        );
    }

    #[Test]
    public function point_30e_the_simulation_mode_is_a_constant_with_no_configuration_switch(): void
    {
        $this->assertSame('simulation', DrawSettlementSimulationService::MODE);

        $reflection = new ReflectionClass(DrawSettlementSimulationService::class);
        $constant = $reflection->getReflectionConstant('MODE');
        $this->assertNotFalse($constant);
        $this->assertTrue($constant->isPublic());

        $audit = $this->settlement()->audit();
        $this->assertSame('simulation', $audit['mode']);
        $this->assertTrue($audit['mode_is_constant']);
        $this->assertNull($audit['mode_config_key'], 'There must be no configuration key that changes the mode.');

        // And no environment variable or config lookup decides it.
        $code = $this->codeOnly($this->sourceOf('app/Services/Draw/DrawSettlementSimulationService.php'));
        $this->assertStringNotContainsString('env(', $code);
        $this->assertSame(
            1,
            substr_count($code, "const MODE = 'simulation';"),
            'MODE must be declared exactly once, as a literal constant.',
        );
        $this->assertSame(
            1,
            substr_count($code, 'MODE ='),
            'MODE must never be reassigned.',
        );
    }

    #[Test]
    public function point_30f_the_simulation_result_declares_its_non_monetary_guarantees(): void
    {
        $outcome = $this->settleOne('3d_direct', '123', self::FIRST_PRIZE, self::BOTTOM_TWO, '100.00');
        $array = $outcome['simulation']->toArray();

        $this->assertArrayHasKey('non_monetary_guarantees', $array);
        $this->assertNotSame([], $array['non_monetary_guarantees']);

        foreach ($array['non_monetary_guarantees'] as $key => $value) {
            $this->assertFalse($value, 'non_monetary_guarantees.'.$key.' must be false.');
        }

        $this->assertSame('simulation', $array['mode']);
        $this->assertTrue($outcome['simulation']->isSimulation());
    }

    // -----------------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------------

    /**
     * Read a Phase 5.1 source file.
     */
    private function sourceOf(string $projectRelativePath): string
    {
        $path = base_path($projectRelativePath);
        $this->assertFileExists($path);

        $source = file_get_contents($path);
        $this->assertIsString($source);

        return $source;
    }

    /**
     * Strip comments and doc blocks so that a prose mention of a forbidden concept in an
     * explanatory comment is not read as an actual call to it.
     */
    private function codeOnly(string $source): string
    {
        $kept = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $kept .= is_array($token) ? $token[1] : $token;
        }

        return $kept;
    }
}
