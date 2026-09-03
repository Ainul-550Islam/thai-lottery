<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Enums\Currency;
use App\Enums\DepositStatus;
use App\Enums\PaymentMethod;
use App\Enums\WalletStatus;
use App\Enums\WithdrawalStatus;
use App\Filament\Resources\DepositResource;
use App\Filament\Resources\DepositResource\DepositDecisionActions;
use App\Filament\Resources\DepositResource\Pages\ListDeposits;
use App\Filament\Resources\DepositResource\Pages\ViewDeposit;
use App\Filament\Resources\FinancialTransactionResource;
use App\Filament\Resources\FinancialTransactionResource\Pages\ListFinancialTransactions;
use App\Filament\Resources\LedgerAccountResource;
use App\Filament\Resources\LedgerAccountResource\Pages\ListLedgerAccounts;
use App\Filament\Resources\WalletResource;
use App\Filament\Resources\WalletResource\Pages\ListWallets;
use App\Filament\Resources\WalletResource\Pages\ViewWallet;
use App\Filament\Resources\WalletResource\WalletControlActions;
use App\Filament\Resources\WithdrawalResource;
use App\Filament\Resources\WithdrawalResource\Pages\ListWithdrawals;
use App\Filament\Resources\WithdrawalResource\Pages\ViewWithdrawal;
use App\Filament\Resources\WithdrawalResource\WithdrawalDecisionActions;
use App\Models\Deposit;
use App\Models\FinancialTransaction;
use App\Models\LedgerAccount;
use App\Models\User;
use App\Models\Wallet;
use App\Models\Withdrawal;
use App\Services\Finance\DepositApprovalService;
use App\Services\Finance\DepositCompletionService;
use App\Services\Finance\DepositService;
use App\Services\Finance\Money;
use App\Services\Finance\WalletService;
use App\Services\Finance\WithdrawalApprovalService;
use App\Services\Finance\WithdrawalService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The Finance navigation group: deposits, withdrawals, wallets, transactions, accounts.
 *
 * WHY THIS SUITE IS SHAPED AROUND REFUSALS
 * Every screen in this group can move real money, and the two failure modes that matter are
 * not formatting bugs:
 *
 *   1. A button offered in a state the domain would refuse. An operator who presses
 *      "Approve" on a deposit somebody else already approved must get the service's own
 *      message on a screen that is simply stale — not a 500, and certainly not a second
 *      approval. Those tests approve a record behind the mounted page's back and then press
 *      the button, which is exactly what two operators on one queue produce.
 *   2. A write path reachable by somebody who may only read. The auditor role holds
 *      'view transaction history', 'view financial reports' and 'reconcile ledger' and holds
 *      neither 'manage payouts' nor 'manage wallet', so it is the right probe: it must be
 *      able to read the ledger screens and must be offered no action anywhere.
 *
 * Fixtures are built through the real domain services (DepositService, WithdrawalService,
 * WalletService) rather than by writing statuses onto models, so every row under test is a
 * state the application can actually reach — including the ledger entries behind it, which
 * is what makes the chart-of-accounts assertions meaningful.
 *
 * WHAT IS DELIBERATELY NOT TESTED HERE
 * The finance rules themselves: hold arithmetic, idempotency replay, double-entry balance.
 * Those belong to the service suites, and asserting them again here would mean two places to
 * change when a rule changes. This suite asserts only what the panel is responsible for:
 * which buttons exist, who sees them, and that pressing one delegates.
 */
final class FinanceResourcesTest extends TestCase
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

        // DatabaseTruncation empties the permission tables but leaves Spatie's cache
        // pointing at the ids it just deleted, which makes the seeder fail on a foreign key
        // rather than on anything to do with this suite. Forget it before seeding.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->seed(RolePermissionSeeder::class);
        $this->seedChartOfAccounts();
        LedgerAccountResource::forgetEntrySums();
    }

    // -------------------------------------------------------------------------
    // Fixtures, built through the domain.
    // -------------------------------------------------------------------------

    private function operator(string $role = 'admin'): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user->fresh();
    }

    /**
     * The accounts every posting in this suite needs. LedgerPostingService refuses to invent
     * one, on purpose, so a missing account is a hard failure rather than a silent absorption
     * of money — which means the fixture has to seed them explicitly.
     */
    private function seedChartOfAccounts(): void
    {
        $accounts = [
            [WalletService::ACCOUNT_SYSTEM_CASH, 'System Cash', 'asset'],
            [WalletService::ACCOUNT_WITHDRAWAL_CLEARING, 'Withdrawal Clearing', 'asset'],
            [WalletService::ACCOUNT_PLAYER_LIABILITY, 'Player Liability', 'liability'],
            [WalletService::ACCOUNT_ADJUSTMENT_EQUITY, 'Adjustment Equity', 'equity'],
            [WalletService::ACCOUNT_BET_REVENUE, 'Bet Revenue', 'revenue'],
        ];

        foreach ($accounts as [$code, $name, $type]) {
            LedgerAccount::query()->firstOrCreate(
                ['code' => $code],
                [
                    'name' => $name,
                    'type' => $type,
                    'currency' => Currency::THB->value,
                    'is_active' => true,
                ],
            );
        }
    }

    private function wallet(string $balance = '5000.00'): Wallet
    {
        return Wallet::factory()->withBalance($balance)->create();
    }

    private function money(string $amount): Money
    {
        return Money::of($amount, Currency::THB);
    }

    private function pendingDeposit(?Wallet $wallet = null, string $amount = '500.00'): Deposit
    {
        return app(DepositService::class)->request(
            $wallet ?? $this->wallet(),
            $this->money($amount),
            PaymentMethod::Manual,
            'panel-test-deposit-'.uniqid('', true),
        );
    }

    private function approvedDeposit(?Wallet $wallet = null): Deposit
    {
        $deposit = $this->pendingDeposit($wallet);

        return app(DepositApprovalService::class)->approve($deposit, null, 'fixture')['deposit'];
    }

    private function confirmedDeposit(?Wallet $wallet = null): Deposit
    {
        $deposit = $this->approvedDeposit($wallet);

        return app(DepositCompletionService::class)->complete($deposit)['deposit'];
    }

    private function pendingWithdrawal(?Wallet $wallet = null, string $amount = '200.00'): Withdrawal
    {
        return app(WithdrawalService::class)->request(
            $wallet ?? $this->wallet(),
            $this->money($amount),
            PaymentMethod::Manual,
            'panel-test-withdrawal-'.uniqid('', true),
        );
    }

    private function approvedWithdrawal(?Wallet $wallet = null): Withdrawal
    {
        $withdrawal = $this->pendingWithdrawal($wallet);

        return app(WithdrawalApprovalService::class)->approve($withdrawal, null, 'fixture')['withdrawal'];
    }

    // -------------------------------------------------------------------------
    // DepositResource
    // -------------------------------------------------------------------------

    public function test_deposit_list_and_view_render_for_an_admin(): void
    {
        $deposit = $this->pendingDeposit();
        $admin = $this->operator();

        $this->actingAs($admin)
            ->get(DepositResource::getUrl('index'))
            ->assertSuccessful()
            ->assertSee($deposit->reference_number);

        $this->actingAs($admin)
            ->get(DepositResource::getUrl('view', ['record' => $deposit]))
            ->assertSuccessful()
            ->assertSee($deposit->reference_number);
    }

    public function test_deposit_resource_is_never_creatable_editable_or_deletable(): void
    {
        $deposit = $this->pendingDeposit();

        Auth::login($this->operator('super-admin'));

        $this->assertFalse(DepositResource::canCreate());
        $this->assertFalse(DepositResource::canEdit($deposit));
        $this->assertFalse(DepositResource::canDelete($deposit));
        $this->assertFalse(DepositResource::canDeleteAny());
        $this->assertArrayNotHasKey('create', DepositResource::getPages());
        $this->assertArrayNotHasKey('edit', DepositResource::getPages());
    }

    public function test_player_cannot_reach_the_deposit_resource(): void
    {
        $this->actingAs($this->operator('player'))
            ->get(DepositResource::getUrl('index'))
            ->assertForbidden();
    }

    public function test_auditor_may_read_deposits_but_is_offered_no_decision(): void
    {
        $deposit = $this->pendingDeposit();
        $auditor = $this->operator('auditor');

        $this->actingAs($auditor)
            ->get(DepositResource::getUrl('index'))
            ->assertSuccessful();

        Auth::login($auditor);

        foreach (DepositDecisionActions::forTable() as $action) {
            $action->record($deposit);

            $this->assertFalse(
                $action->isVisible(),
                "An auditor was offered the deposit [{$action->getName()}] action.",
            );
        }
    }

    public function test_deposit_approve_is_offered_only_while_the_domain_would_accept_it(): void
    {
        $pending = $this->pendingDeposit();
        $confirmed = $this->confirmedDeposit();

        Livewire::actingAs($this->operator())
            ->test(ListDeposits::class, ['activeTab' => 'all'])
            ->assertTableActionVisible('approve', record: $pending)
            ->assertTableActionHidden('approve', record: $confirmed);
    }

    public function test_deposit_complete_is_offered_only_after_approval(): void
    {
        $pending = $this->pendingDeposit();
        $approved = $this->approvedDeposit();

        Livewire::actingAs($this->operator())
            ->test(ListDeposits::class, ['activeTab' => 'all'])
            ->assertTableActionHidden('complete', record: $pending)
            ->assertTableActionVisible('complete', record: $approved);
    }

    public function test_approving_a_deposit_delegates_to_the_service(): void
    {
        $deposit = $this->pendingDeposit();

        Livewire::actingAs($this->operator())
            ->test(ListDeposits::class, ['activeTab' => 'all'])
            ->callTableAction('approve', record: $deposit, data: ['note' => 'statement line 44 checked'])
            ->assertHasNoTableActionErrors();

        $fresh = $deposit->fresh();

        $this->assertSame(DepositStatus::Approved, $fresh->status);
        $this->assertNull($fresh->financial_transaction_id, 'Approval must not credit anything.');
        $this->assertSame('approved', app(DepositApprovalService::class)->decisionTrail($fresh)['decision'] ?? null);
    }

    public function test_rejecting_a_deposit_requires_a_reason(): void
    {
        $deposit = $this->pendingDeposit();

        Livewire::actingAs($this->operator())
            ->test(ViewDeposit::class, ['record' => $deposit->getKey()])
            ->callAction('reject', data: ['reason' => ''])
            ->assertHasActionErrors(['reason']);

        $this->assertSame(DepositStatus::Pending, $deposit->fresh()->status);
    }

    public function test_rejecting_a_deposit_records_the_reason_through_the_service(): void
    {
        $deposit = $this->pendingDeposit();

        Livewire::actingAs($this->operator())
            ->test(ViewDeposit::class, ['record' => $deposit->getKey()])
            ->callAction('reject', data: ['reason' => 'no matching payment on the provider statement'])
            ->assertHasNoActionErrors();

        $fresh = $deposit->fresh();

        $this->assertSame(DepositStatus::Rejected, $fresh->status);
        $this->assertSame('no matching payment on the provider statement', $fresh->failure_reason);
    }

    public function test_completing_a_deposit_credits_the_wallet_through_the_service(): void
    {
        $wallet = $this->wallet('0.00');
        $deposit = $this->approvedDeposit($wallet);

        Livewire::actingAs($this->operator())
            ->test(ViewDeposit::class, ['record' => $deposit->getKey()])
            ->callAction('complete')
            ->assertHasNoActionErrors();

        $fresh = $deposit->fresh();

        $this->assertSame(DepositStatus::Confirmed, $fresh->status);
        $this->assertNotNull($fresh->financial_transaction_id, 'Completion posted no financial transaction.');
        $this->assertSame('500.00', (string) $wallet->fresh()->balance);
    }

    public function test_approving_an_already_approved_deposit_surfaces_the_refusal_not_a_500(): void
    {
        $deposit = $this->pendingDeposit();

        // The screen is mounted while the deposit is still pending, so the button is
        // legitimately on offer...
        $page = Livewire::actingAs($this->operator())
            ->test(ViewDeposit::class, ['record' => $deposit->getKey()]);

        // ...and another operator decides it in the meantime.
        app(DepositApprovalService::class)->approve($deposit->fresh(), null, 'the other operator');

        $page->callAction('approve', data: ['note' => 'second approval attempt'])
            ->assertHasNoActionErrors()
            ->assertNotified();

        $fresh = $deposit->fresh();

        $this->assertSame(DepositStatus::Approved, $fresh->status, 'A stale screen must not re-approve.');
        $this->assertSame('the other operator', app(DepositApprovalService::class)->decisionTrail($fresh)['note'] ?? null);
    }

    public function test_completing_an_already_credited_deposit_reports_the_replay_and_credits_nothing(): void
    {
        $wallet = $this->wallet('0.00');
        $deposit = $this->approvedDeposit($wallet);

        $page = Livewire::actingAs($this->operator())
            ->test(ViewDeposit::class, ['record' => $deposit->getKey()]);

        app(DepositCompletionService::class)->complete($deposit->fresh());

        $page->callAction('complete')
            ->assertHasNoActionErrors()
            ->assertNotified();

        $this->assertSame('500.00', (string) $wallet->fresh()->balance, 'The deposit was credited twice.');
        $this->assertSame(1, FinancialTransaction::query()->where('wallet_id', $wallet->getKey())->count());
    }

    // -------------------------------------------------------------------------
    // WithdrawalResource
    // -------------------------------------------------------------------------

    public function test_withdrawal_list_and_view_render_for_an_admin(): void
    {
        $withdrawal = $this->pendingWithdrawal();
        $admin = $this->operator();

        $this->actingAs($admin)
            ->get(WithdrawalResource::getUrl('index'))
            ->assertSuccessful()
            ->assertSee($withdrawal->reference_number);

        $this->actingAs($admin)
            ->get(WithdrawalResource::getUrl('view', ['record' => $withdrawal]))
            ->assertSuccessful()
            ->assertSee($withdrawal->reference_number);
    }

    /**
     * PendingApprovalsWidget links straight into this resource by class name and route, so
     * the registration is load-bearing for the dashboard, not just for this screen.
     */
    public function test_the_dashboard_widget_route_contract_holds(): void
    {
        $withdrawal = $this->pendingWithdrawal();

        Auth::login($this->operator());

        $this->assertSame(
            \App\Filament\Resources\WithdrawalResource::class,
            WithdrawalResource::class,
        );
        $this->assertArrayHasKey('index', WithdrawalResource::getPages());
        $this->assertArrayHasKey('view', WithdrawalResource::getPages());
        $this->assertIsString(WithdrawalResource::getUrl('view', ['record' => $withdrawal]));
    }

    public function test_withdrawal_resource_is_never_creatable_editable_or_deletable(): void
    {
        $withdrawal = $this->pendingWithdrawal();

        Auth::login($this->operator('super-admin'));

        $this->assertFalse(WithdrawalResource::canCreate());
        $this->assertFalse(WithdrawalResource::canEdit($withdrawal));
        $this->assertFalse(WithdrawalResource::canDelete($withdrawal));
        $this->assertFalse(WithdrawalResource::canDeleteAny());
    }

    public function test_player_cannot_reach_the_withdrawal_resource(): void
    {
        $this->actingAs($this->operator('player'))
            ->get(WithdrawalResource::getUrl('index'))
            ->assertForbidden();
    }

    public function test_auditor_may_read_withdrawals_but_is_offered_no_decision(): void
    {
        $withdrawal = $this->pendingWithdrawal();
        $auditor = $this->operator('auditor');

        $this->actingAs($auditor)
            ->get(WithdrawalResource::getUrl('index'))
            ->assertSuccessful();

        Auth::login($auditor);

        foreach (WithdrawalDecisionActions::forTable() as $action) {
            $action->record($withdrawal);

            $this->assertFalse(
                $action->isVisible(),
                "An auditor was offered the withdrawal [{$action->getName()}] action.",
            );
        }
    }

    public function test_withdrawal_buttons_track_the_state_machine(): void
    {
        $pending = $this->pendingWithdrawal();
        $approved = $this->approvedWithdrawal();

        Livewire::actingAs($this->operator())
            ->test(ListWithdrawals::class, ['activeTab' => 'all'])
            ->assertTableActionVisible('approve', record: $pending)
            ->assertTableActionHidden('approve', record: $approved)
            ->assertTableActionHidden('markProcessing', record: $pending)
            ->assertTableActionVisible('markProcessing', record: $approved)
            ->assertTableActionHidden('complete', record: $pending)
            ->assertTableActionVisible('complete', record: $approved);
    }

    public function test_approving_a_withdrawal_reserves_the_funds_through_the_service(): void
    {
        $wallet = $this->wallet('1000.00');
        $withdrawal = $this->pendingWithdrawal($wallet);

        Livewire::actingAs($this->operator())
            ->test(ListWithdrawals::class, ['activeTab' => 'all'])
            ->callTableAction('approve', record: $withdrawal, data: ['note' => 'KYC on file'])
            ->assertHasNoTableActionErrors();

        $fresh = $withdrawal->fresh();
        $freshWallet = $wallet->fresh();

        $this->assertSame(WithdrawalStatus::Approved, $fresh->status);
        $this->assertSame('200.00', (string) $freshWallet->locked_balance);
        $this->assertSame('1000.00', (string) $freshWallet->balance, 'Approval must not move the total balance.');
        $this->assertSame('800.00', app(WalletService::class)->availableBalance($freshWallet)->amount());
    }

    public function test_rejecting_a_withdrawal_requires_a_reason(): void
    {
        $withdrawal = $this->pendingWithdrawal();

        Livewire::actingAs($this->operator())
            ->test(ViewWithdrawal::class, ['record' => $withdrawal->getKey()])
            ->callAction('reject', data: ['reason' => ''])
            ->assertHasActionErrors(['reason']);

        $this->assertSame(WithdrawalStatus::Pending, $withdrawal->fresh()->status);
    }

    public function test_rejecting_a_withdrawal_releases_the_reservation(): void
    {
        $wallet = $this->wallet('1000.00');
        $withdrawal = $this->approvedWithdrawal($wallet);

        $this->assertSame('200.00', (string) $wallet->fresh()->locked_balance);

        Livewire::actingAs($this->operator())
            ->test(ViewWithdrawal::class, ['record' => $withdrawal->getKey()])
            ->callAction('reject', data: ['reason' => 'beneficiary name does not match the account holder'])
            ->assertHasNoActionErrors();

        $this->assertSame(WithdrawalStatus::Rejected, $withdrawal->fresh()->status);
        $this->assertSame('0.00', (string) $wallet->fresh()->locked_balance);
    }

    public function test_completing_a_withdrawal_debits_the_wallet_through_the_service(): void
    {
        $wallet = $this->wallet('1000.00');
        $withdrawal = $this->approvedWithdrawal($wallet);

        Livewire::actingAs($this->operator())
            ->test(ViewWithdrawal::class, ['record' => $withdrawal->getKey()])
            ->callAction('complete')
            ->assertHasNoActionErrors();

        $fresh = $withdrawal->fresh();

        $this->assertSame(WithdrawalStatus::Completed, $fresh->status);
        $this->assertNotNull($fresh->financial_transaction_id);
        $this->assertSame('800.00', (string) $wallet->fresh()->balance);
    }

    public function test_marking_a_withdrawal_failed_requires_a_reason_and_releases_the_hold(): void
    {
        $wallet = $this->wallet('1000.00');
        $withdrawal = $this->approvedWithdrawal($wallet);

        Livewire::actingAs($this->operator())
            ->test(ViewWithdrawal::class, ['record' => $withdrawal->getKey()])
            ->callAction('markFailed', data: ['reason' => ''])
            ->assertHasActionErrors(['reason']);

        $this->assertSame(WithdrawalStatus::Approved, $withdrawal->fresh()->status);

        Livewire::actingAs($this->operator())
            ->test(ViewWithdrawal::class, ['record' => $withdrawal->getKey()])
            ->callAction('markFailed', data: ['reason' => 'the payout channel returned account_closed'])
            ->assertHasNoActionErrors();

        $this->assertSame(WithdrawalStatus::Failed, $withdrawal->fresh()->status);
        $this->assertSame('0.00', (string) $wallet->fresh()->locked_balance);
        $this->assertSame('1000.00', (string) $wallet->fresh()->balance, 'A failed payout must not debit anything.');
    }

    public function test_approving_an_already_approved_withdrawal_surfaces_the_refusal_not_a_500(): void
    {
        $wallet = $this->wallet('1000.00');
        $withdrawal = $this->pendingWithdrawal($wallet);

        $page = Livewire::actingAs($this->operator())
            ->test(ViewWithdrawal::class, ['record' => $withdrawal->getKey()]);

        app(WithdrawalApprovalService::class)->approve($withdrawal->fresh(), null, 'the other operator');

        $page->callAction('approve', data: ['note' => 'second approval attempt'])
            ->assertHasNoActionErrors()
            ->assertNotified();

        $this->assertSame(WithdrawalStatus::Approved, $withdrawal->fresh()->status);
        $this->assertSame(
            '200.00',
            (string) $wallet->fresh()->locked_balance,
            'A stale screen reserved the amount twice.',
        );
    }

    // -------------------------------------------------------------------------
    // WalletResource
    // -------------------------------------------------------------------------

    public function test_wallet_list_and_view_render_for_an_admin(): void
    {
        $wallet = $this->wallet('1234.50');
        $admin = $this->operator();

        $this->actingAs($admin)
            ->get(WalletResource::getUrl('index'))
            ->assertSuccessful()
            ->assertSee('1,234.50');

        $this->actingAs($admin)
            ->get(WalletResource::getUrl('view', ['record' => $wallet]))
            ->assertSuccessful()
            ->assertSee('1,234.50');
    }

    public function test_wallet_resource_is_never_creatable_editable_or_deletable(): void
    {
        $wallet = $this->wallet();

        Auth::login($this->operator('super-admin'));

        $this->assertFalse(WalletResource::canCreate());
        $this->assertFalse(WalletResource::canEdit($wallet));
        $this->assertFalse(WalletResource::canDelete($wallet));
        $this->assertFalse(WalletResource::canDeleteAny());
    }

    public function test_player_cannot_reach_the_wallet_resource(): void
    {
        $this->actingAs($this->operator('player'))
            ->get(WalletResource::getUrl('index'))
            ->assertForbidden();
    }

    public function test_auditor_may_read_wallets_but_is_offered_no_control(): void
    {
        $wallet = $this->wallet();
        $auditor = $this->operator('auditor');

        $this->actingAs($auditor)
            ->get(WalletResource::getUrl('index'))
            ->assertSuccessful();

        Auth::login($auditor);

        foreach (WalletControlActions::forTable() as $action) {
            $action->record($wallet);

            $this->assertFalse(
                $action->isVisible(),
                "An auditor was offered the wallet [{$action->getName()}] action.",
            );
        }
    }

    public function test_lock_and_unlock_are_offered_in_opposite_states(): void
    {
        $active = $this->wallet();
        $locked = app(WalletService::class)->lockWallet($this->wallet(), 'fixture lock');

        Livewire::actingAs($this->operator())
            ->test(ListWallets::class, ['activeTab' => 'all'])
            ->assertTableActionVisible('lockWallet', record: $active)
            ->assertTableActionHidden('unlockWallet', record: $active)
            ->assertTableActionHidden('lockWallet', record: $locked)
            ->assertTableActionVisible('unlockWallet', record: $locked);
    }

    public function test_locking_a_wallet_requires_a_reason_and_delegates(): void
    {
        $wallet = $this->wallet();

        Livewire::actingAs($this->operator())
            ->test(ViewWallet::class, ['record' => $wallet->getKey()])
            ->callAction('lockWallet', data: ['reason' => ''])
            ->assertHasActionErrors(['reason']);

        $this->assertSame(WalletStatus::Active, $wallet->fresh()->status);

        Livewire::actingAs($this->operator())
            ->test(ViewWallet::class, ['record' => $wallet->getKey()])
            ->callAction('lockWallet', data: ['reason' => 'compliance hold pending document review'])
            ->assertHasNoActionErrors();

        $fresh = $wallet->fresh();

        $this->assertSame(WalletStatus::Locked, $fresh->status);
        $this->assertSame('compliance hold pending document review', $fresh->locked_reason);
    }

    public function test_unlocking_a_wallet_that_is_not_locked_surfaces_the_refusal_not_a_500(): void
    {
        $wallet = app(WalletService::class)->lockWallet($this->wallet(), 'fixture lock');

        $page = Livewire::actingAs($this->operator())
            ->test(ViewWallet::class, ['record' => $wallet->getKey()]);

        app(WalletService::class)->unlockWallet($wallet->fresh());

        $page->callAction('unlockWallet')
            ->assertHasNoActionErrors()
            ->assertNotified();

        $this->assertSame(WalletStatus::Active, $wallet->fresh()->status);
    }

    public function test_adjustment_rejects_anything_that_is_not_a_plain_decimal_string(): void
    {
        $wallet = $this->wallet('100.00');

        foreach (['1e3', 'abc', '10.005', '-50', '1,000', ' 50 ', ''] as $bad) {
            Livewire::actingAs($this->operator())
                ->test(ViewWallet::class, ['record' => $wallet->getKey()])
                ->callAction('adjustBalance', data: [
                    'direction' => 'credit',
                    'amount' => $bad,
                    'reason' => 'testing the amount guard',
                ])
                ->assertHasActionErrors(['amount']);
        }

        $this->assertSame('100.00', (string) $wallet->fresh()->balance);
        $this->assertSame(0, FinancialTransaction::query()->count());
    }

    public function test_adjustment_requires_a_reason(): void
    {
        $wallet = $this->wallet('100.00');

        Livewire::actingAs($this->operator())
            ->test(ViewWallet::class, ['record' => $wallet->getKey()])
            ->callAction('adjustBalance', data: [
                'direction' => 'credit',
                'amount' => '50.00',
                'reason' => '',
            ])
            ->assertHasActionErrors(['reason']);

        $this->assertSame('100.00', (string) $wallet->fresh()->balance);
    }

    public function test_crediting_a_wallet_posts_through_the_wallet_service(): void
    {
        $wallet = $this->wallet('100.00');

        Livewire::actingAs($this->operator())
            ->test(ViewWallet::class, ['record' => $wallet->getKey()])
            ->callAction('adjustBalance', data: [
                'direction' => 'credit',
                'amount' => '25.50',
                'reason' => 'goodwill credit agreed with support',
            ])
            ->assertHasNoActionErrors();

        $this->assertSame('125.50', (string) $wallet->fresh()->balance);

        $transaction = FinancialTransaction::query()->where('wallet_id', $wallet->getKey())->sole();

        $this->assertSame('25.50', (string) $transaction->amount);
        $this->assertSame(2, $transaction->ledgerEntries()->count(), 'An adjustment must post a balanced pair.');
    }

    public function test_debiting_more_than_the_balance_surfaces_the_refusal_not_a_500(): void
    {
        $wallet = $this->wallet('10.00');

        Livewire::actingAs($this->operator())
            ->test(ViewWallet::class, ['record' => $wallet->getKey()])
            ->callAction('adjustBalance', data: [
                'direction' => 'debit',
                'amount' => '5000.00',
                'reason' => 'attempting an impossible correction',
            ])
            ->assertHasNoActionErrors()
            ->assertNotified();

        $this->assertSame('10.00', (string) $wallet->fresh()->balance);
        $this->assertSame(0, FinancialTransaction::query()->count());
    }

    public function test_adjustment_is_gated_behind_manage_wallet(): void
    {
        $wallet = $this->wallet();

        Auth::login($this->operator('auditor'));

        $adjust = collect(WalletControlActions::forTable())
            ->first(fn ($action): bool => $action->getName() === 'adjustBalance');

        $adjust->record($wallet);

        $this->assertNotNull($adjust);
        $this->assertFalse($adjust->isVisible());

        Auth::login($this->operator('admin'));

        $adjustForAdmin = collect(WalletControlActions::forTable())
            ->first(fn ($action): bool => $action->getName() === 'adjustBalance');

        $adjustForAdmin->record($wallet);

        $this->assertTrue($adjustForAdmin->isVisible());
    }

    public function test_available_balance_is_the_services_own_figure(): void
    {
        $wallet = $this->wallet('1000.00');
        $this->approvedWithdrawal($wallet);

        $this->actingAs($this->operator())
            ->get(WalletResource::getUrl('view', ['record' => $wallet]))
            ->assertSuccessful()
            ->assertSee('800.00');
    }

    // -------------------------------------------------------------------------
    // FinancialTransactionResource — read-only ledger history
    // -------------------------------------------------------------------------

    public function test_transaction_list_and_view_render_for_an_admin(): void
    {
        $wallet = $this->wallet('0.00');
        $this->confirmedDeposit($wallet);

        $transaction = FinancialTransaction::query()->sole();
        $admin = $this->operator();

        $this->actingAs($admin)
            ->get(FinancialTransactionResource::getUrl('index'))
            ->assertSuccessful()
            ->assertSee($transaction->reference_number);

        $this->actingAs($admin)
            ->get(FinancialTransactionResource::getUrl('view', ['record' => $transaction]))
            ->assertSuccessful()
            ->assertSee($transaction->reference_number);
    }

    public function test_an_auditor_can_read_transaction_history(): void
    {
        $wallet = $this->wallet('0.00');
        $this->confirmedDeposit($wallet);

        $transaction = FinancialTransaction::query()->sole();
        $auditor = $this->operator('auditor');

        $this->actingAs($auditor)
            ->get(FinancialTransactionResource::getUrl('index'))
            ->assertSuccessful()
            ->assertSee($transaction->reference_number);

        $this->actingAs($auditor)
            ->get(FinancialTransactionResource::getUrl('view', ['record' => $transaction]))
            ->assertSuccessful();
    }

    public function test_player_cannot_reach_transaction_history(): void
    {
        $this->actingAs($this->operator('player'))
            ->get(FinancialTransactionResource::getUrl('index'))
            ->assertForbidden();
    }

    public function test_transaction_resource_offers_no_write_path_to_anyone(): void
    {
        $wallet = $this->wallet('0.00');
        $this->confirmedDeposit($wallet);

        $transaction = FinancialTransaction::query()->sole();

        Auth::login($this->operator('super-admin'));

        $this->assertFalse(FinancialTransactionResource::canCreate());
        $this->assertFalse(FinancialTransactionResource::canEdit($transaction));
        $this->assertFalse(FinancialTransactionResource::canDelete($transaction));
        $this->assertFalse(FinancialTransactionResource::canDeleteAny());
        $this->assertArrayNotHasKey('create', FinancialTransactionResource::getPages());
        $this->assertArrayNotHasKey('edit', FinancialTransactionResource::getPages());

        $table = Livewire::test(ListFinancialTransactions::class)->instance()->getTable();

        $this->assertSame(
            ['view'],
            array_values(array_map(
                fn ($action): string => $action->getName(),
                $table->getActions(),
            )),
            'The ledger history screen must offer only the view action.',
        );
        $this->assertSame([], $table->getBulkActions());
    }

    public function test_an_auditor_is_offered_no_action_on_transaction_history(): void
    {
        $wallet = $this->wallet('0.00');
        $this->confirmedDeposit($wallet);

        $page = Livewire::actingAs($this->operator('auditor'))->test(ListFinancialTransactions::class);

        $this->assertSame([], $page->instance()->getCachedHeaderActions());
        $this->assertSame([], $page->instance()->getTable()->getBulkActions());
    }

    // -------------------------------------------------------------------------
    // LedgerAccountResource — read-only chart of accounts
    // -------------------------------------------------------------------------

    public function test_chart_of_accounts_renders_for_an_admin_and_an_auditor(): void
    {
        foreach (['admin', 'auditor'] as $role) {
            $this->actingAs($this->operator($role))
                ->get(LedgerAccountResource::getUrl('index'))
                ->assertSuccessful()
                ->assertSee('Player Liability');
        }
    }

    public function test_player_cannot_reach_the_chart_of_accounts(): void
    {
        $this->actingAs($this->operator('player'))
            ->get(LedgerAccountResource::getUrl('index'))
            ->assertForbidden();
    }

    public function test_chart_of_accounts_offers_no_write_path_and_no_actions(): void
    {
        $account = LedgerAccount::query()->where('code', WalletService::ACCOUNT_PLAYER_LIABILITY)->sole();

        Auth::login($this->operator('super-admin'));

        $this->assertFalse(LedgerAccountResource::canCreate());
        $this->assertFalse(LedgerAccountResource::canEdit($account));
        $this->assertFalse(LedgerAccountResource::canDelete($account));
        $this->assertFalse(LedgerAccountResource::canDeleteAny());
        $this->assertSame(['index'], array_keys(LedgerAccountResource::getPages()));

        $page = Livewire::test(ListLedgerAccounts::class);

        $this->assertSame([], $page->instance()->getTable()->getActions());
        $this->assertSame([], $page->instance()->getTable()->getBulkActions());
        $this->assertSame([], $page->instance()->getCachedHeaderActions());
    }

    public function test_account_balances_are_computed_from_the_entries_with_bcmath(): void
    {
        $wallet = $this->wallet('0.00');
        $this->confirmedDeposit($wallet); // 500.00 in through system cash

        LedgerAccountResource::forgetEntrySums();

        $liability = LedgerAccount::query()->where('code', WalletService::ACCOUNT_PLAYER_LIABILITY)->sole();
        $cash = LedgerAccount::query()->where('code', WalletService::ACCOUNT_SYSTEM_CASH)->sole();
        $untouched = LedgerAccount::query()->where('code', WalletService::ACCOUNT_BET_REVENUE)->sole();

        // Player liability is credit-normal: the platform owes the player 500 more.
        $this->assertSame('500.00', LedgerAccountResource::computedBalance($liability));

        // System cash is debit-normal: 500 more of it exists.
        $this->assertSame('500.00', LedgerAccountResource::computedBalance($cash));

        // An account with no entries reports its opening balance, not an error.
        $this->assertSame('0.00', LedgerAccountResource::computedBalance($untouched));

        $this->assertIsString(LedgerAccountResource::sideTotal($liability, 'credit'));
        $this->assertSame('500.00', LedgerAccountResource::sideTotal($liability, 'credit'));
        $this->assertSame('0.00', LedgerAccountResource::sideTotal($liability, 'debit'));
    }

    /**
     * The whole point of memoising the aggregate: a page of accounts must cost one grouped
     * query, not one per row.
     */
    public function test_balances_cost_one_aggregate_query_for_the_whole_page(): void
    {
        $wallet = $this->wallet('0.00');
        $this->confirmedDeposit($wallet);

        LedgerAccountResource::forgetEntrySums();

        $queries = 0;
        \DB::listen(function ($query) use (&$queries): void {
            if (str_contains(strtolower($query->sql), 'from "ledger_entries"')) {
                $queries++;
            }
        });

        foreach (LedgerAccount::query()->orderBy('code')->get() as $account) {
            LedgerAccountResource::computedBalance($account);
        }

        $this->assertSame(1, $queries, 'Balances were computed with more than one ledger query.');
    }
}
