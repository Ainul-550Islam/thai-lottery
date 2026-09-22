<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\WalletReservationStatus;
use App\Exceptions\WalletReservationException;
use App\Models\WalletReservation;
use App\Services\Finance\WalletReservationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * The minesweep: pulls every wallet reservation past its horizon
 * (Pending rows = stamp only, Reserved rows = mechanically release the
 * staged money first, then stamp Expired). Money named against a
 * pocket is never allowed to linger as a ghost age.
 */
final class ExpireWalletReservationsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const PAGE_SIZE = 200;

    public function __construct()
    {
        $this->onQueue('finance-reconciliation');
    }

    public function handle(WalletReservationService $reservations): void
    {
        $expired = 0;
        $failed = 0;

        WalletReservation::query()
            ->whereIn('status', [
                WalletReservationStatus::Pending->value,
                WalletReservationStatus::Reserved->value,
            ])
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->orderBy('id')
            ->limit(self::PAGE_SIZE)
            ->get()
            ->each(function (WalletReservation $reservation) use ($reservations, &$expired, &$failed): void {
                try {
                    $reservations->expire($reservation);
                    $expired++;
                } catch (WalletReservationException|\Throwable $e) {
                    // A single torn page never halts the sweep; the next
                    // run will revisit it.
                    $failed++;

                    Log::error('Wallet-reservation expiry refused.', [
                        'reservation' => $reservation->reservation_key,
                        'error' => $e->getMessage(),
                    ]);
                }
            });

        if ($expired > 0 || $failed > 0) {
            Log::info('Wallet-reservation sweep completed.', [
                'expired' => $expired,
                'failed' => $failed,
            ]);
        }
    }
}
