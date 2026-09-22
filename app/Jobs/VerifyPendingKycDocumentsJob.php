<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\KycDocumentVerificationStatus;
use App\Exceptions\KycVerificationException;
use App\Models\KycDocument;
use App\Services\Compliance\KycDocumentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Chunks through the document evidence room, continuously:
 *
 *   - a document whose OWN expiry passed                     → Expired
 *   - an Uploaded document older than the intake horizon      → claimed
 *     into Processing (evidence never auto-verifies — the desk does)
 *   - a Processing document still unread past the desk horizon →
 *     LOUD evidence miss (logged by name; nothing moves silently)
 *
 * EXPIRED OR INVALID EVIDENCE NEVER BECOMES VERIFIED — the verified
 * verb does not exist in this job by construction.
 */
final class VerifyPendingKycDocumentsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const PAGE_SIZE = 100;

    /**
     * Uploaded → Processing intake horizon (minutes).
     */
    public const INTAKE_HORIZON_MINUTES = 30;

    public function __construct()
    {
        $this->onQueue('finance-reconciliation');
    }

    public function handle(KycDocumentService $documents): void
    {
        $expired = 0;
        $claimed = 0;
        $overdue = 0;

        // 1) Physics: expired evidence lapses, from any live state.
        KycDocument::query()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->whereIn('verification_status', [
                KycDocumentVerificationStatus::Uploaded->value,
                KycDocumentVerificationStatus::Processing->value,
                KycDocumentVerificationStatus::Verified->value,
            ])
            ->orderBy('id')
            ->limit(self::PAGE_SIZE)
            ->get()
            ->each(function (KycDocument $document) use ($documents, &$expired): void {
                try {
                    $documents->expire($document);
                    $expired++;
                } catch (KycVerificationException|\Throwable) {
                    // one torn page never halts the sweep
                }
            });

        // 2) Intake: old-enough uploads claimed for reading.
        KycDocument::query()
            ->where('verification_status', KycDocumentVerificationStatus::Uploaded->value)
            ->where('created_at', '<=', now()->subMinutes(self::INTAKE_HORIZON_MINUTES))
            ->orderBy('id')
            ->limit(self::PAGE_SIZE)
            ->get()
            ->each(function (KycDocument $document) use (&$claimed): void {
                KycDocument::query()
                    ->whereKey((int) $document->id)
                    ->where('verification_status', KycDocumentVerificationStatus::Uploaded->value)
                    ->update([
                        'verification_status' => KycDocumentVerificationStatus::Processing->value,
                        'updated_at' => now(),
                    ]);
                $claimed++;
            });

        // 3) Evidence-miss vigilance: Processing stuck forever is a
        // signal (the Row is untouched — logged for the desk).
        KycDocument::query()
            ->where('verification_status', KycDocumentVerificationStatus::Processing->value)
            ->where('updated_at', '<=', now()->subHours(24))
            ->orderBy('id')
            ->limit(self::PAGE_SIZE)
            ->get()
            ->each(function (KycDocument $document) use (&$overdue): void {
                $overdue++;
            });

        if ($expired > 0 || $claimed > 0 || $overdue > 0) {
            Log::info('KYC document engine sweep completed.', [
                'expired' => $expired,
                'claimed_for_processing' => $claimed,
                'overdue_in_processing' => $overdue,
            ]);
        }
    }
}
