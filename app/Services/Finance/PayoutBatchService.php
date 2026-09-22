<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\DTOs\Finance\PayoutBatchData;
use App\Enums\AuditAction;
use App\Enums\PayoutBatchStatus;
use App\Enums\PayoutStatus;
use App\Enums\RiskLevel;
use App\Events\PayoutBatchCompleted;
use App\Exceptions\PayoutBatchException;
use App\Models\AuditLog;
use App\Models\Payout;
use App\Models\PayoutBatch;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Manufactures and advances payout BATCHES: deterministic identity,
 * atomic creation, aggregate verification, lifecycle guards.
 *
 * THE DOCTRINE IN SHORT
 * ---------------------
 *   IDENTITY IS DERIVED. batch_key = sha256(sorted member references +
 *   currency). Creation of the same content twice is a replay and lands on
 *   the same row; the unique key makes races refuse each other at the
 *   engine. A differently-composed batch can never collide with this one.
 *
 *   THE AGGREGATE IS A CLAIM THAT IS PROVEN. The creator's declared
 *   aggregate must equal the bcmath sum of the member payouts' own amounts,
 *   recomputed at creation and again at completion. Mismatch stops the
 *   event: a batch must never be trusted to settle what it does not name.
 *
 *   MEMBERSHIP IS PROJECTED. Members carry metadata.batch stamps on their
 *   payout rows (batch_key + join stamp + outcome). The batch row tracks
 *   counts; the rows track membership. Two sources of truth would let one
 *   lie when the other is read.
 *
 *   THE LIFECYCLE IS THE ONLY WAY IN OR OUT. The state machine lives in
 *   PayoutBatchStatus; this service enforces it with stateForbids(). The
 *   PayoutBatchCompleted event fires crossing into Completed, at most once
 *   per batch — the flip itself is the guard.
 *
 *   MONEY NEVER MOVES HERE. The transfer lane (PayoutTransferService) moves
 *   money; this service maintains the book-keeping aggregate that runs it.
 */
class PayoutBatchService
{
    /**
     * The metadata lane each member payout carries.
     */
    public const MEMBER_METADATA_KEY = 'batch';

    /**
     * Create a batch over the named member payouts.
     *
     * IDEMPOTENT BY IDENTITY: a replay with identical content returns the
     * existing batch unchanged. A second attempt that found the same key
     * but a DIFFERENT composition is impossible by derivation — the key is
     * the composition.
     *
     * Every named payout MUST be approved-and-pending (the selection
     * predicate the whole pipeline shares): the batch lane refuses to plan
     * money that never crossed four-eyes.
     *
     * @return array{batch: PayoutBatch, created: bool}
     *
     * @throws PayoutBatchException
     */
    public function create(PayoutBatchData $data): array
    {
        if (DB::transactionLevel() > 0) {
            throw PayoutBatchException::alreadyRunning((int) DB::transactionLevel());
        }

        if (! $data->hasMembers()) {
            throw PayoutBatchException::emptyMemberList($data->batchKey());
        }

        if (! $data->aggregateIsWellFormed()) {
            throw new PayoutBatchException(
                sprintf('Payout batch [%s]: aggregate amount [%s] is not a well-formed 2-decimal string.', $data->batchKey(), $data->aggregateAmount),
                PayoutBatchException::CODE_AGGREGATE_MISMATCH,
                ['batch_key' => $data->batchKey(), 'claimed' => $data->aggregateAmount, 'actual' => 'malformed'],
            );
        }

        return DB::transaction(function () use ($data): array {
            // Replay first: identical content on a re-run returns the row.
            $existing = PayoutBatch::query()->lockForUpdate()
                ->where('batch_key', $data->batchKey())
                ->first();

            if ($existing instanceof PayoutBatch) {
                return ['batch' => $existing, 'created' => false];
            }

            $members = Payout::query()->lockForUpdate()
                ->whereIn('reference_number', $data->canonicalReferences())
                ->orderBy('id')
                ->get();

            if ($members->count() !== count($data->canonicalReferences())) {
                throw new PayoutBatchException(
                    sprintf(
                        'Payout batch [%s]: %d of %d named payouts resolved to rows; the batch cannot plan money it cannot see.',
                        $data->batchKey(),
                        $members->count(),
                        count($data->canonicalReferences()),
                    ),
                    PayoutBatchException::CODE_NOT_FOUND,
                    ['batch_key' => $data->batchKey()],
                );
            }

            // Selection predicate: approved+pending, the same gate the
            // batch-selector job applies. Every member must prove it.
            foreach ($members as $member) {
                if ($member->status !== PayoutStatus::Pending) {
                    throw new PayoutBatchException(
                        sprintf(
                            'Payout batch [%s]: member payout [%s] is %s, not pending; the batch may only plan approved-and-pending money.',
                            $data->batchKey(),
                            (string) $member->reference_number,
                            $member->status->value,
                        ),
                        PayoutBatchException::CODE_STATE_FORBIDS,
                        ['batch_key' => $data->batchKey()],
                    );
                }
            }

            // AGGREGATE VERIFICATION: claimed total must equal the sum of
            // the rows themselves — bcmath exact, never float.
            $sum = '0.00';

            foreach ($members as $member) {
                $sum = bcadd($sum, (string) $member->amount, 2);
            }

            if (bccomp($sum, $data->canonicalAggregate(), 2) !== 0) {
                throw PayoutBatchException::aggregateMismatch(
                    $data->batchKey(),
                    $data->canonicalAggregate(),
                    $sum,
                );
            }

            $batch = new PayoutBatch();
            $batch->batch_key = $data->batchKey();
            $batch->status = PayoutBatchStatus::Pending;
            $batch->currency = $data->currency;
            $batch->member_count = $members->count();
            $batch->aggregate_amount = $data->canonicalAggregate();
            $batch->note = $data->note;
            $batch->metadata = ['context' => $data->context, 'created_via' => 'payout-batch-service'];
            $batch->save();

            // Project membership onto the members.
            foreach ($members as $member) {
                $metadata = is_array($member->metadata) ? $member->metadata : [];
                $metadata[self::MEMBER_METADATA_KEY] = [
                    'batch_key' => $data->batchKey(),
                    'joined_at' => Carbon::now()->toIso8601String(),
                    'outcome' => 'queued',
                ];
                $member->metadata = $metadata;
                $member->save();
            }

            $this->recordAudit($batch, sprintf(
                'Payout batch created: %d member(s), aggregate %s %s.',
                $members->count(),
                $data->canonicalAggregate(),
                $data->currency->value,
            ), RiskLevel::Medium);

            return ['batch' => $batch, 'created' => true];
        });
    }

    /**
     * Claim a batch for execution: the one atomic Pending/Failed →
     * Processing flip an executor performs. The second claimant loses.
     */
    public function claimForExecution(PayoutBatch $batch): PayoutBatch
    {
        return DB::transaction(function () use ($batch): PayoutBatch {
            /** @var PayoutBatch|null $locked */
            $locked = PayoutBatch::query()->lockForUpdate()->find((int) $batch->getKey());

            if (! $locked instanceof PayoutBatch) {
                throw PayoutBatchException::notFound($batch->batch_key);
            }

            if (! $locked->isClaimable()) {
                throw PayoutBatchException::transitionRace($locked->batch_key);
            }

            $locked->status = PayoutBatchStatus::Processing;
            $locked->claimed_at = Carbon::now();
            $locked->failure_reason = null;
            $locked->save();

            return $locked;
        });
    }

    /**
     * Record one member outcome against the running batch. Called by the
     * executor after each member's own transaction commits; never inside
     * the member's money boundary.
     *
     * @param  'paid'|'failed'|'replayed'  $outcome
     */
    public function recordMemberOutcome(PayoutBatch $batch, string $payoutReference, string $outcome, string $amount): PayoutBatch
    {
        return DB::transaction(function () use ($batch, $payoutReference, $outcome, $amount): PayoutBatch {
            /** @var PayoutBatch $locked */
            $locked = PayoutBatch::query()->lockForUpdate()->findOrFail((int) $batch->getKey());

            $locked->processed_count = (int) $locked->processed_count + 1;

            match ($outcome) {
                'paid' => $locked->paid_count = (int) $locked->paid_count + 1,
                'failed' => $locked->failed_count = (int) $locked->failed_count + 1,
                'replayed' => $locked->replayed_count = (int) $locked->replayed_count + 1,
                default => throw new \InvalidArgumentException(sprintf('Unknown member outcome [%s].', $outcome)),
            };

            if ($outcome === 'paid') {
                $locked->paid_amount = bcadd((string) $locked->paid_amount, $amount, 2);
            }

            $locked->save();

            // Project the outcome onto the member.
            $member = Payout::query()
                ->where('reference_number', $payoutReference)
                ->first();

            if ($member instanceof Payout) {
                $metadata = is_array($member->metadata) ? $member->metadata : [];
                $stamp = is_array($metadata[self::MEMBER_METADATA_KEY] ?? null) ? $metadata[self::MEMBER_METADATA_KEY] : [];
                $stamp['outcome'] = $outcome;
                $stamp['outcome_at'] = Carbon::now()->toIso8601String();
                $metadata[self::MEMBER_METADATA_KEY] = $stamp;
                $member->metadata = $metadata;
                $member->save();
            }

            return $locked;
        });
    }

    /**
     * Settle a running batch: Completed when every member has an outcome
     * and the re-verified aggregate still holds; Failed when the executor
     * left unaccountable members behind.
     *
     * The Completed transition fires PayoutBatchCompleted exactly once —
     * the flip is a one-directional write and the event lives inside it.
     *
     * @return array{batch: PayoutBatch, completed: bool}
     */
    public function settle(PayoutBatch $batch, ?string $failureReason = null): array
    {
        if (DB::transactionLevel() > 0) {
            throw PayoutBatchException::alreadyRunning((int) DB::transactionLevel());
        }

        return DB::transaction(function () use ($batch, $failureReason): array {
            /** @var PayoutBatch|null $locked */
            $locked = PayoutBatch::query()->lockForUpdate()->find((int) $batch->getKey());

            if (! $locked instanceof PayoutBatch) {
                throw PayoutBatchException::notFound($batch->batch_key);
            }

            $status = $locked->status;

            if ($status === PayoutBatchStatus::Completed) {
                return ['batch' => $locked, 'completed' => false]; // replay: event already fired
            }

            if ($status !== PayoutBatchStatus::Processing) {
                throw PayoutBatchException::stateForbids(
                    $locked->batch_key,
                    $status->value,
                    $failureReason === null ? PayoutBatchStatus::Completed->value : PayoutBatchStatus::Failed->value,
                );
            }

            if ($failureReason !== null) {
                $locked->status = PayoutBatchStatus::Failed;
                $locked->failed_at = Carbon::now();
                $locked->failure_reason = $failureReason;
                $locked->save();

                $this->recordAudit($locked, sprintf('Payout batch failed: %s.', $failureReason), RiskLevel::High);

                return ['batch' => $locked, 'completed' => false];
            }

            // Final aggregate re-verification: paid money must never exceed
            // the planned aggregate, and every member must have an outcome.
            if ((int) $locked->processed_count < (int) $locked->member_count) {
                throw PayoutBatchException::stateForbids(
                    $locked->batch_key,
                    PayoutBatchStatus::Processing->value,
                    PayoutBatchStatus::Completed->value.' while '.((int) $locked->member_count - (int) $locked->processed_count).' member(s) unaccounted',
                );
            }

            if (bccomp((string) $locked->paid_amount, (string) $locked->aggregate_amount, 2) > 0) {
                throw new PayoutBatchException(
                    sprintf(
                        'Payout batch [%s]: paid amount %s exceeds the planned aggregate %s; the batch can never claim to have paid more than it was approved to.',
                        $locked->batch_key,
                        (string) $locked->paid_amount,
                        (string) $locked->aggregate_amount,
                    ),
                    PayoutBatchException::CODE_AGGREGATE_MISMATCH,
                    ['batch_key' => $locked->batch_key],
                );
            }

            $locked->status = PayoutBatchStatus::Completed;
            $locked->completed_at = Carbon::now();
            $locked->save();

            // The one-time event. It fires inside the flip's own commit, so
            // there is no world where the batch is Completed without the
            // event-named paper trail.
            event(new PayoutBatchCompleted(
                batch: $locked,
                memberCount: (int) $locked->member_count,
                paidCount: (int) $locked->paid_count,
                failedCount: (int) $locked->failed_count,
                replayedCount: (int) $locked->replayed_count,
                paidAmount: (string) $locked->paid_amount,
            ));

            $this->recordAudit($locked, sprintf(
                'Payout batch completed: %d paid, %d failed, %d replayed; paid %s %s of planned %s.',
                $locked->paid_count,
                $locked->failed_count,
                $locked->replayed_count,
                (string) $locked->paid_amount,
                $locked->currency->value,
                (string) $locked->aggregate_amount,
            ), RiskLevel::Medium);

            return ['batch' => $locked, 'completed' => true];
        });
    }

    /**
     * Cancel a pending batch (operator gesture). Members release back to
     * the selection pool with their own payout statuses untouched.
     */
    public function cancel(PayoutBatch $batch, string $reason, ?int $operatorUserId = null): PayoutBatch
    {
        return DB::transaction(function () use ($batch, $reason, $operatorUserId): PayoutBatch {
            /** @var PayoutBatch|null $locked */
            $locked = PayoutBatch::query()->lockForUpdate()->find((int) $batch->getKey());

            if (! $locked instanceof PayoutBatch) {
                throw PayoutBatchException::notFound($batch->batch_key);
            }

            if (! $locked->status->canTransitionTo(PayoutBatchStatus::Cancelled)) {
                throw PayoutBatchException::stateForbids(
                    $locked->batch_key,
                    $locked->status->value,
                    PayoutBatchStatus::Cancelled->value,
                );
            }

            $locked->status = PayoutBatchStatus::Cancelled;
            $locked->cancelled_at = Carbon::now();
            $locked->failure_reason = $reason;
            $locked->save();

            // Release the members' batch stamps.
            foreach ($locked->memberPayouts() as $member) {
                $metadata = is_array($member->metadata) ? $member->metadata : [];
                unset($metadata[self::MEMBER_METADATA_KEY]);
                $member->metadata = $metadata;
                $member->save();
            }

            $this->recordAudit($locked, sprintf(
                'Payout batch cancelled%s: %s.',
                $operatorUserId !== null ? sprintf(' by operator #%d', $operatorUserId) : '',
                $reason,
            ), RiskLevel::Medium);

            return $locked;
        });
    }

    /**
     * Look up a batch by its deterministic key, or null.
     */
    public function findByKey(string $batchKey): ?PayoutBatch
    {
        return PayoutBatch::query()->where('batch_key', $batchKey)->first();
    }

    /**
     * The batch's status, re-derived from the row (never trust a transported
     * status string).
     */
    public function statusOf(PayoutBatch $batch): PayoutBatchStatus
    {
        /** @var PayoutBatch $fresh */
        $fresh = PayoutBatch::query()->findOrFail((int) $batch->getKey());

        return $fresh->status;
    }

    private function recordAudit(PayoutBatch $batch, string $description, RiskLevel $riskLevel): void
    {
        $log = new AuditLog();

        $log->fill([
            'user_id' => null,
            'action' => AuditAction::Payout,
            'risk_level' => $riskLevel,
            'auditable_type' => PayoutBatch::class,
            'auditable_id' => $batch->getKey(),
            'description' => $description,
            'metadata' => [
                'batch_key' => $batch->batch_key,
                'batch_status' => $batch->status->value,
                'aggregate_amount' => (string) $batch->aggregate_amount,
                'currency' => $batch->currency->value,
                'lane' => 'payout_batch',
            ],
        ]);

        $log->save();
    }
}
