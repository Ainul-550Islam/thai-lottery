<?php

use App\Jobs\CalculatePrizeTaxJob;
use App\Jobs\CloseExpiredClaimWindowsJob;
use App\Jobs\ExpireStalePaymentIntentsJob;
use App\Jobs\ExpireUnclaimedPrizesJob;
use App\Jobs\ExpireWalletReservationsJob;
use App\Jobs\GeneratePayoutBatchJob;
use App\Jobs\GeneratePayoutStatementJob;
use App\Jobs\ReassessAmlRiskJob;
use App\Jobs\ReconcilePaymentProviderJob;
use App\Jobs\ReconcileWalletLedgersJob;
use App\Jobs\ReleaseExpiredTicketReservationsJob;
use App\Jobs\ReviewFinancialHoldsJob;
use App\Jobs\ReviewOpenComplianceCasesJob;
use App\Jobs\SweepUnclaimedPrizesJob;
use App\Jobs\VerifyPendingKycDocumentsJob;
use App\Jobs\VerifyRetailTicketInventoryJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function (): void {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Scheduled lanes
|--------------------------------------------------------------------------
|
| Every recurring batch job the lane trees manufacture. Each job already
| carries its own concurrency guard (ShouldBeUnique keyed on the cadence),
| so the scheduler's cadence and the job's unique window are built to
| agree — never twice in the same window, never skipped silently.
|
| Money-critical lanes get names so `schedule:work` output and ops
| monitoring each call them by their business identity.
|
*/

// Batch generation: fans approved-and-pending payouts into executor
// batches. The job's uniqueness window is per minute — cadence matches it.
Schedule::job(new GeneratePayoutBatchJob)
    ->everyMinute()
    ->name('payout-batch-generation')
    ->withoutOverlapping()
    ->onOneServer();

// Tax computation: approved prizes still waiting for arithmetic.
// Uniqueness: per hour, matching the hourly cadence.
Schedule::job(new CalculatePrizeTaxJob)
    ->hourlyAt(15)
    ->name('prize-tax-computation')
    ->withoutOverlapping()
    ->onOneServer();

// Statement paper: completed payouts whose documents do not exist yet.
Schedule::job(new GeneratePayoutStatementJob)
    ->hourlyAt(40)
    ->name('payout-statement-generation')
    ->withoutOverlapping()
    ->onOneServer();

// Daily 03:xx ladder: expiry → window close → disposal sweep. Order
// matters and is preserved by the clock offsets: claims lapse first,
// then windows close, then lapsed prizes enroll into disposal.
Schedule::job(new ExpireUnclaimedPrizesJob)
    ->dailyAt('03:10')
    ->name('unclaimed-prize-expiry')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::job(new CloseExpiredClaimWindowsJob)
    ->dailyAt('03:20')
    ->name('claim-window-closure')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::job(new SweepUnclaimedPrizesJob)
    ->dailyAt('03:30')
    ->name('unclaimed-prize-disposal')
    ->withoutOverlapping()
    ->onOneServer();

// Retail inventory lanes (batch-8): stale reservations release on the
// quarter clock (they are time-bound reality, not a status), while the
// reconciler sweeps membership-invariant drift hourly, well inside any
// operating window.
Schedule::job(new ReleaseExpiredTicketReservationsJob)
    ->everyFifteenMinutes()
    ->name('retail-reservation-release')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::job(new VerifyRetailTicketInventoryJob)
    ->hourlyAt(10)
    ->name('retail-inventory-reconciliation')
    ->withoutOverlapping()
    ->onOneServer();

// Wallet-liability reconciliation ladder (batch-11): the reservation
// minesweep fires often (reservation horizons are short and time-bound
// reality, not a status); holds are swept within their horizon window;
// the full wallet-liability reconciler walks the pockets hourly, well
// inside any operating window. Each cadence agrees with the jobs'
// own on-queue identity.
Schedule::job(new ExpireWalletReservationsJob)
    ->everyTenMinutes()
    ->name('wallet-reservation-expiry')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::job(new ReviewFinancialHoldsJob)
    ->everyThirtyMinutes()
    ->name('financial-hold-review')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::job(new ReconcileWalletLedgersJob)
    ->hourlyAt(5)
    ->name('wallet-liability-reconciliation')
    ->withoutOverlapping()
    ->onOneServer();

// Payment-provider integrity ladder (batch-12): stale intents collapse
// only when NOTHING on the provider's side says the money happened;
// provider-vs-internal reconciliation walks the payments paper hourly.
Schedule::job(new ExpireStalePaymentIntentsJob)
    ->everyFifteenMinutes()
    ->name('payment-intent-expiry')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::job(new ReconcilePaymentProviderJob)
    ->hourlyAt(25)
    ->name('payment-provider-reconciliation')
    ->withoutOverlapping()
    ->onOneServer();

// Compliance integrity ladder (batch-13): documents lapse by physics,
// AML reassessment walks the users, the desk's own clock escalates
// stale files by the deterministic rule — each lane evidence-first.
Schedule::job(new VerifyPendingKycDocumentsJob)
    ->everyFifteenMinutes()
    ->name('kyc-document-engine')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::job(new ReassessAmlRiskJob)
    ->hourlyAt(45)
    ->name('aml-risk-reassessment')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::job(new ReviewOpenComplianceCasesJob)
    ->hourlyAt(55)
    ->name('compliance-desk-clock')
    ->withoutOverlapping()
    ->onOneServer();
