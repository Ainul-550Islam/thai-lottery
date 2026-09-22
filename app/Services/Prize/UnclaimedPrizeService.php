<?php

declare(strict_types=1);

namespace App\Services\Prize;

use App\DTOs\Prize\UnclaimedPrizeData;
use App\Enums\AuditAction;
use App\Enums\Currency;
use App\Enums\PayoutStatus;
use App\Enums\PrizeClaimStatus;
use App\Enums\RiskLevel;
use App\Enums\UnclaimedPrizeStatus;
use App\Models\AuditLog;
use App\Models\Payout;
use Illuminate\Support\Facades\DB;

/**
 * The sweeper of lapsed prize windows.
 *
 * GLO-parity: an official lottery prize is claimable only inside its window
 * (default 730 days after the draw). Money whose window ran out with no claim
 * must stop presenting as an obligation — nobody will ever collect it — and
 * it must do so VISIBLY: an Expired stamp on the claim record plus an audit
 * line that names ids and amounts. This service is the only writer of that
 * stamp, and the scheduler's ExpireUnclaimedPrizesJob is its only driver.
 *
 * WHAT IT EXPIRES, EXACTLY
 * - Payouts whose claim lives under UNDER-REVIEW / SUBMITTED / APPROVED and
 *   whose window_closes_at has passed → claim.status = Expired.
 * - Payouts created by settlement but with NO CLAIM AT ALL (auto-awaited
 *   claims whose player never showed up) are NOT expired here: their money
 *   is still owed to the bet owner — and an auto-awaited digital win never
 *   lapses on its own in this architecture. The claim RECORD is the prize's
 *   window; no claim means the prize has not yet asserted a window.
 *
 * Read scale is bounded: one query per expiry run, chunked so a large
 * unclaimed backlog writes in steady batches rather than one giant mutation.
 */
class UnclaimedPrizeService
{
    /**
     * The metadata lane the disposal view rides on.
     */
    public const UNCLAIMED_METADATA_KEY = 'unclaimed';

    /**
     * Run one expiry sweep. Returns every expired claim with ids and amounts
     * for the caller to report or alert on.
     *
     * @return array{
     *     expired_count: int,
     *     total_expired_amount: string,
     *     payouts: list<array{payout_id: int, amount: string, currency: string, window_closed_at: string}>,
     *     executed_at: string
     * }
     */
    public function expireLapsedClaims(?int $chunkSize = null): array
    {
        $chunkSize ??= 200;

        $expired = [];
        $total = '0.00';
        $now = now()->toIso8601String();

        $lockStatuses = [
            PrizeClaimStatus::Submitted->value,
            PrizeClaimStatus::UnderReview->value,
            PrizeClaimStatus::Approved->value,
        ];

        Payout::query()
            ->whereNotNull('metadata')
            ->whereNull('deleted_at')
            ->whereIn(DB::raw("json_extract(metadata, '$.claim.status')"), $lockStatuses)
            ->whereRaw("json_extract(metadata, '$.claim.window_closes_at') < ?", [$now])
            ->whereRaw("json_extract(metadata, '$.claim.window_closes_at') IS NOT NULL")
            ->orderBy('id')
            ->chunkById($chunkSize, function ($payouts) use (&$expired, &$total, $now): bool {
                foreach ($payouts as $payout) {
                    $claim = is_array($payout->metadata['claim'] ?? null)
                        ? $payout->metadata['claim']
                        : [];

                    $claim['status'] = PrizeClaimStatus::Expired->value;
                    $claim['expired_at'] = $now;

                    // + the DISPOSAL lane registers in the same one-write:
                    // the prize enters as UnclaimedPrizeStatus::Expired,
                    // anchored by a disposal key derived from facts that
                    // cannot drift between runs (reference + amount +
                    // currency + the window edge the claim already carried).
                    $disposalKey = UnclaimedPrizeData::deriveDisposalKey(
                        (string) $payout->reference_number,
                        (string) $payout->amount,
                        $payout->currency,
                        (string) ($claim['window_closes_at'] ?? ''),
                    );

                    $metadata = is_array($payout->metadata) ? $payout->metadata : [];
                    $metadata['claim'] = $claim;
                    $metadata[self::UNCLAIMED_METADATA_KEY] = [
                        'status' => UnclaimedPrizeStatus::Expired->value,
                        'disposal_key' => $disposalKey,
                        'expired_at' => $now,
                        'swept_at' => null,
                    ];

                    $payout->metadata = $metadata;
                    $payout->save();

                    $total = bcadd($total, (string) $payout->amount, 2);

                    $expired[] = [
                        'payout_id' => (int) $payout->getKey(),
                        'amount' => (string) $payout->amount,
                        'currency' => $payout->currency->value,
                        'window_closed_at' => (string) ($claim['window_closes_at'] ?? ''),
                    ];

                    $this->recordAudit($payout, $claim);
                }

                return true;
            });

        return [
            'expired_count' => count($expired),
            'total_expired_amount' => $total,
            'payouts' => $expired,
            'executed_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Sweeps ALREADY-EXPIRED unclaimed prizes into the disposal ledger:
     * UnclaimedPrizeStatus Expired → Swept.
     *
     * IDEMPOTENT BY SELECTION: the selector matches only lane records still
     * at Expired; a rerun over the same rows matches nothing. The disposal
     * key anchored at expiry time rides with the record, so a sweep carried
     * out twice — scheduler retry, ops rerun — lands on one lane per prize.
     *
     * REPLAY-SAFE PER ROW: each row's stamp is its own write; one failing
     * row never un-sweeps another, and the exception aborts the chunk only.
     *
     * @return array{
     *     swept_count: int,
     *     total_swept_amount: string,
     *     disposal_keys: list<string>,
     *     executed_at: string
     * }
     */
    public function sweepExpired(?int $chunkSize = null): array
    {
        $chunkSize ??= 200;
        $swept = 0;
        $total = '0.00';
        $keys = [];
        $now = now()->toIso8601String();

        Payout::query()
            ->whereNotNull('metadata')
            ->whereNull('deleted_at')
            ->whereRaw("json_extract(metadata, '$.unclaimed.status') = ?", [UnclaimedPrizeStatus::Expired->value])
            ->orderBy('id')
            ->chunkById($chunkSize, function ($payouts) use (&$swept, &$total, &$keys, $now): bool {
                foreach ($payouts as $payout) {
                    $metadata = is_array($payout->metadata) ? $payout->metadata : [];
                    $lane = is_array($metadata[self::UNCLAIMED_METADATA_KEY] ?? null)
                        ? $metadata[self::UNCLAIMED_METADATA_KEY]
                        : [];

                    $lane['status'] = UnclaimedPrizeStatus::Swept->value;
                    $lane['swept_at'] = $now;
                    $metadata[self::UNCLAIMED_METADATA_KEY] = $lane;

                    $payout->metadata = $metadata;
                    $payout->save();

                    $swept++;
                    $total = bcadd($total, (string) $payout->amount, 2);
                    $keys[] = (string) ($lane['disposal_key'] ?? '');

                    $log = new AuditLog();
                    $log->fill([
                        'user_id' => null,
                        'action' => AuditAction::Update,
                        'risk_level' => RiskLevel::Medium,
                        'auditable_type' => Payout::class,
                        'auditable_id' => $payout->getKey(),
                        'description' => sprintf(
                            'Unclaimed prize of %s %s on payout #%d SWEPT into the disposal ledger (disposal key %s).',
                            (string) $payout->amount,
                            $payout->currency->value,
                            (int) $payout->getKey(),
                            (string) ($lane['disposal_key'] ?? ''),
                        ),
                        'metadata' => [
                            'payout_id' => (int) $payout->getKey(),
                            'payout_reference' => (string) $payout->reference_number,
                            'disposal_key' => (string) ($lane['disposal_key'] ?? ''),
                            'action' => 'unclaimed_prize_swept',
                        ],
                    ]);
                    $log->save();
                }

                return true;
            });

        return [
            'swept_count' => $swept,
            'total_swept_amount' => $total,
            'disposal_keys' => $keys,
            'executed_at' => now()->toIso8601String(),
        ];
    }

    /**
     * The disposal-lane record for a payout, or null when the payout never
     * entered the disposal view.
     *
     * @return array<string, mixed>|null
     */
    public function unclaimedRecordFor(Payout $payout): ?array
    {
        $metadata = is_array($payout->metadata) ? $payout->metadata : [];
        $record = $metadata[self::UNCLAIMED_METADATA_KEY] ?? null;

        return is_array($record) ? $record : null;
    }

    /**
     * Where a payout sits on the disposal ladder (null: not enrolled).
     */
    public function disposalStatusFor(Payout $payout): ?UnclaimedPrizeStatus
    {
        $record = $this->unclaimedRecordFor($payout);

        return is_array($record) ? UnclaimedPrizeStatus::tryFrom((string) ($record['status'] ?? '')) : null;
    }

    /**
     * Count of claims currently eligible for expiry — the number a monitoring
     * dashboard or dry-run needs BEFORE deciding to run the sweep.
     */
    public function lapsedClaimCount(): int
    {
        $now = now()->toIso8601String();

        return Payout::query()
            ->whereNotNull('metadata')
            ->whereNull('deleted_at')
            ->whereIn(DB::raw("json_extract(metadata, '$.claim.status')"), [
                PrizeClaimStatus::Submitted->value,
                PrizeClaimStatus::UnderReview->value,
                PrizeClaimStatus::Approved->value,
            ])
            ->whereRaw("json_extract(metadata, '$.claim.window_closes_at') < ?", [$now])
            ->count();
    }

    /**
     * Total prize money currently in claims that have not yet lapsed — the
     * "obligations pending claim" figure for finance.
     */
    public function pendingClaimsTotal(?string $currency = null): string
    {
        $query = Payout::query()
            ->whereNotNull('metadata')
            ->whereNull('deleted_at')
            ->whereIn(DB::raw("json_extract(metadata, '$.claim.status')"), [
                PrizeClaimStatus::Submitted->value,
                PrizeClaimStatus::UnderReview->value,
                PrizeClaimStatus::Approved->value,
            ]);

        if ($currency !== null) {
            $query->where('currency', $currency);
        }

        $total = '0.00';

        foreach ($query->select('amount')->cursor() as $row) {
            $total = bcadd($total, (string) $row->amount, 2);
        }

        return $total;
    }

    /**
     * Every expiry writes its own audit line: which payout lapsed, with how
     * much, from which window edge. High risk because expiring wrongly could
     * void money no one has paid back.
     *
     * @param  array<string, mixed>  $claim
     */
    private function recordAudit(Payout $payout, array $claim): void
    {
        $log = new AuditLog();

        $log->fill([
            'user_id' => null,
            'action' => AuditAction::Update,
            'risk_level' => RiskLevel::High,
            'auditable_type' => Payout::class,
            'auditable_id' => $payout->getKey(),
            'description' => sprintf(
                'Prize claim of %s %s on payout #%d EXPIRED: the claim window closed at %s with no completed claim.',
                (string) $payout->amount,
                $payout->currency->value,
                (int) $payout->getKey(),
                (string) ($claim['window_closes_at'] ?? ''),
            ),
            'metadata' => [
                'payout_id' => (int) $payout->getKey(),
                'payout_reference' => (string) $payout->reference_number,
                'amount' => (string) $payout->amount,
                'currency' => $payout->currency->value,
                'claim_status' => PrizeClaimStatus::Expired->value,
                'window_closes_at' => (string) ($claim['window_closes_at'] ?? ''),
            ],
        ]);

        $log->save();
    }
}
