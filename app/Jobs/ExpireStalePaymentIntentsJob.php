<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\PaymentFailureReason;
use App\Enums\PaymentStatus;
use App\Enums\PaymentTransactionStatus;
use App\Models\Payment;
use App\Models\PaymentIntent;
use App\Services\Payment\PaymentIntentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Abandoned-intent collapse.
 *
 * An intent may lapse ONLY when no provider-side SUCCESS evidence
 * exists for it (the linked payment paper is absent or never reached a
 * success pronunciation — an intent with any success evidence is a
 * fact that happened, not an abandoned one). Money never moves here:
 * expire means the intend DIES, nothing about the wallet changes.
 */
final class ExpireStalePaymentIntentsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const PAGE_SIZE = 200;

    public function __construct()
    {
        $this->onQueue('finance-reconciliation');
    }

    public function handle(PaymentIntentService $intents): void
    {
        $expired = 0;
        $skippedWithEvidence = 0;

        PaymentIntent::query()
            ->whereIn('status', [
                PaymentTransactionStatus::Initiated->value,
                PaymentTransactionStatus::Pending->value,
                PaymentTransactionStatus::Processing->value,
            ])
            ->where('expires_at', '<=', now())
            ->orderBy('id')
            ->limit(self::PAGE_SIZE)
            ->get()
            ->each(function (PaymentIntent $intent) use (&$expired, &$skippedWithEvidence): void {
                // THE EVIDENCE CHECK: a linked payment carrying ANY
                // success/refund pronunciation means the intent's money
                // story happened — never collapse a fact.
                if ($intent->payment_id !== null) {
                    /** @var Payment|null $payment */
                    $payment = Payment::query()->find((int) $intent->payment_id);

                    if ($payment instanceof Payment) {
                        $status = $payment->status instanceof PaymentStatus
                            ? $payment->status
                            : PaymentStatus::tryFrom((string) $payment->status);

                        if ($status !== null && $status->isSuccessful()) {
                            $skippedWithEvidence++;

                            return;
                        }
                    }
                }

                if (! $intent->status->canTransitionTo(PaymentTransactionStatus::Failed)) {
                    return;
                }

                $intent->status = PaymentTransactionStatus::Failed;
                $intent->failure_reason = PaymentFailureReason::Expired;
                $intent->failed_at = now();
                $intent->save();

                $expired++;
            });

        if ($expired > 0 || $skippedWithEvidence > 0) {
            Log::info('Stale payment-intent sweep completed.', [
                'expired' => $expired,
                'skipped_with_success_evidence' => $skippedWithEvidence,
            ]);
        }
    }
}
