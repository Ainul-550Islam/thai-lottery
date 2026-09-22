<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\FinancialHoldStatus;
use App\Exceptions\FinancialHoldException;
use App\Models\FinancialHold;
use App\Services\Finance\FinancialHoldService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * The horizon custodian for financial holds — nobody's money is held
 * forever unexamined, and nobody's money silently moves.
 *
 *   Active holds past half their horizon   → verbatim review evidence
 *                                            so the desk can see them
 *   Reviewed holds past their horizon      → expire (the hold gives
 *                                            the money back cleanly
 *                                            first, then is pronounced)
 *
 * Each failure is logged; one torn hold never halts the sweep.
 */
final class ReviewFinancialHoldsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const PAGE_SIZE = 200;

    public function __construct()
    {
        $this->onQueue('finance-reconciliation');
    }

    public function handle(FinancialHoldService $holds): void
    {
        $expired = 0;
        $failed = 0;

        FinancialHold::query()
            ->where('status', FinancialHoldStatus::Reviewed->value)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->orderBy('id')
            ->limit(self::PAGE_SIZE)
            ->get()
            ->each(function (FinancialHold $hold) use ($holds, &$expired, &$failed): void {
                try {
                    $holds->expire($hold);
                    $expired++;
                } catch (FinancialHoldException|\Throwable $e) {
                    $failed++;

                    Log::error('Financial-hold expiry refused.', [
                        'hold' => $hold->hold_key,
                        'error' => $e->getMessage(),
                    ]);
                }
            });

        // Active holds past their horizon get a mid-life review flag in
        // the evidence column — never a status change; a human owns the
        // Release/Convert verbs.
        $flagged = 0;

        FinancialHold::query()
            ->where('status', FinancialHoldStatus::Active->value)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->orderBy('id')
            ->limit(self::PAGE_SIZE)
            ->get()
            ->each(function (FinancialHold $hold) use (&$flagged): void {
                $evidence = is_array($hold->evidence) ? $hold->evidence : [];

                if (isset($evidence['attention'])) {
                    return; // already surfaced; no screaming repeats
                }

                $evidence['attention'] = [
                    'at' => now()->toIso8601String(),
                    'note' => 'Active hold past horizon — desk review required',
                ];

                $hold->evidence = $evidence;
                $hold->save();

                $flagged++;
            });

        if ($expired > 0 || $failed > 0 || $flagged > 0) {
            Log::info('Financial-hold sweep completed.', [
                'expired' => $expired,
                'failed' => $failed,
                'attention_flagged' => $flagged,
            ]);
        }
    }
}
