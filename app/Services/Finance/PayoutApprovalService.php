<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\DTOs\Finance\PayoutApprovalData;
use App\DTOs\Finance\PayoutRequestData;
use App\Enums\AuditAction;
use App\Enums\PayoutApprovalStatus;
use App\Enums\PayoutStatus;
use App\Enums\RiskLevel;
use App\Exceptions\PayoutApprovalException;
use App\Models\AuditLog;
use App\Models\Payout;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The maker/checker (four-eyes) lane a payout obligation must cross before
 * money is released against it.
 *
 * WHERE THE LANE LIVES
 * --------------------
 * On the payout row's `metadata` JSON under the {@see self::METADATA_KEY}
 * key. No separate table: the payout row IS the obligation anchor (amount,
 * currency, beneficiary, idempotent reference), and the approval lane never
 * needs rows the payout itself doesn't name. The row id doubles as the
 * request id — one payout, one request, one decision.
 *
 * THE FOUR-EYES INVARIANT
 * -----------------------
 * The person who REQUESTED a payout may never be the person who APPROVES
 * or REJECTS it. Enforced here, in code, at decision time — the database
 * cannot express "maker ≠ checker". {@see PayoutApprovalException} carries
 * the named refusal for every invariant the lane defends.
 *
 * STATES (PayoutApprovalStatus)
 *   Pending   — maker raised it, checker has not spoken
 *   Approved  — checker agreed; the batch lane (GeneratePayoutBatchJob)
 *               selects only payouts whose approval lane sits here
 *   Rejected  — checker refused (a stated reason is mandatory)
 *   Completed — the money actually moved (marked by the execution lane;
 *               this service never moves money itself)
 *
 * MONEY NEVER MOVES HERE
 * ----------------------
 * This service writes the HUMAN verdict only. Crediting, batching and
 * executing belong to the payout/batch lane; a reversal of that lane is a
 * separate concern still. The only coupling is the read-only question
 * {@see self::isApproved()} answers for the batch selector.
 *
 * IDEMPOTENCY
 * -----------
 * `request()` joins silently when the same request key already occupies the
 * lane (two makers racing the same obligation land on one record).
 * `decide()` replays are refused loudly — a second decision would silently
 * rewrite the audit of the first.
 */
class PayoutApprovalService
{
    /**
     * The metadata key under which the approval record lives on the payout.
     */
    public const METADATA_KEY = 'approval';

    /**
     * Submit a maker request for a payout obligation.
     *
     * Idempotent: the same prize reference + amount + currency + beneficiary
     * always derives the same request key (see PayoutRequestData::deriveRequestKey),
     * so a retry — network replay, double submit, queue re-delivery — returns
     * the existing record unchanged.
     *
     * A live pending lane occupied by a MATERIALLY DIFFERENT request is refused:
     * replacing a pending request silently would rewrite what the checker is
     * about to read.
     *
     * @return array<string, mixed>  The approval record as written.
     */
    public function request(Payout $payout, PayoutRequestData $data): array
    {
        $existing = $this->recordFor($payout);

        if (is_array($existing)) {
            $status = $this->statusOf($existing);

            // Same obligation, same request: join idempotently.
            if (($existing['request_key'] ?? null) === $data->requestKey) {
                return $existing;
            }

            if ($status === PayoutApprovalStatus::Pending) {
                throw new PayoutApprovalException(
                    sprintf(
                        'Payout #%d already carries a DIFFERENT pending request; amending a lane the checker may be reading is refused.',
                        (int) $payout->getKey(),
                    ),
                    PayoutApprovalException::CODE_NOT_PENDING,
                    [
                        'request_id' => (int) $payout->getKey(),
                        'current_status' => PayoutApprovalStatus::Pending->value,
                    ],
                );
            }

            throw PayoutApprovalException::notPending(
                (int) $payout->getKey(),
                $status?->value ?? 'unknown',
            );
        }

        // The request may only ever name the obligation the payout row itself
        // already carries. A maker asserting a different amount than the
        // payout's money is not making a request about this payout.
        if (bccomp($data->amount, (string) $payout->amount, 2) !== 0) {
            throw new PayoutApprovalException(
                sprintf(
                    'Payout #%d: request amount %s drifts from the payout obligation %s; the lane only ever carries the payout\'s own money.',
                    (int) $payout->getKey(),
                    $data->amount,
                    (string) $payout->amount,
                ),
                PayoutApprovalException::CODE_DECISION_SHAPE,
                [
                    'request_id' => (int) $payout->getKey(),
                    'requested_amount' => $data->amount,
                    'payout_amount' => (string) $payout->amount,
                ],
            );
        }

        $record = [
            'request_key' => $data->requestKey,
            'status' => PayoutApprovalStatus::Pending->value,
            'maker_user_id' => $data->requesterUserId,
            'checker_user_id' => null,
            'reason' => $data->reason,
            'decision_reason' => null,
            'requested_at' => Carbon::now()->toIso8601String(),
            'decided_at' => null,
            'completed_at' => null,
            'request_context' => $data->context,
        ];

        DB::transaction(function () use ($payout, $record, $data): void {
            $this->writeRecord($payout, $record);
            $this->recordAudit(
                $payout,
                sprintf('Payout requested by maker #%d (%s)', (int) $data->requesterUserId, $data->method->value),
                RiskLevel::Low,
            );
        });

        return $record;
    }

    /**
     * Record the checker's verdict on a payout request.
     *
     * THE DECISION IS SINGLE-SHOT. Makers may be wrong and re-submit later
     * (by working the process over); checkers may never speak twice — the
     * second verdict, whatever it says, is refused.
     *
     * Four-eyes: the checker must be a different person than the maker. The
     * service cannot see the caller's role matrix, so identity inequality is
     * the cheapest correct defence and the one that catches the frauds that
     * matter (a maker approving their own lane with an ops login).
     *
     * @return array<string, mixed>  The approval record after the decision.
     *
     * @throws PayoutApprovalException  On every lane invariant breach.
     */
    public function decide(Payout $payout, PayoutApprovalData $data): array
    {
        if (DB::transactionLevel() > 0) {
            throw PayoutApprovalException::alreadyRunning((int) DB::transactionLevel());
        }

        $record = $this->recordFor($payout);

        if (! is_array($record)) {
            throw PayoutApprovalException::requestNotFound((int) $payout->getKey());
        }

        $status = $this->statusOf($record);

        if (($record['decided_at'] ?? null) !== null || in_array($status, [PayoutApprovalStatus::Approved, PayoutApprovalStatus::Rejected, PayoutApprovalStatus::Completed], true)) {
            throw PayoutApprovalException::duplicateDecision((int) $payout->getKey());
        }

        if ($status !== PayoutApprovalStatus::Pending) {
            throw PayoutApprovalException::notPending(
                (int) $payout->getKey(),
                $status?->value ?? 'unknown',
            );
        }

        if ($data->actorUserId === (int) $record['maker_user_id']) {
            throw PayoutApprovalException::makerEqualsChecker((int) $payout->getKey(), $data->actorUserId);
        }

        if (! $data->decisionIsDecisive()) {
            throw PayoutApprovalException::decisionShapeInvalid((int) $payout->getKey(), $data->decision->value);
        }

        if ($data->isRejection() && ! $data->reasonIsStated()) {
            throw PayoutApprovalException::rejectionWithoutReason((int) $payout->getKey());
        }

        // Obligation drift at decision time: the payout's money moved since
        // the maker requested the lane. Decide nothing against a lane whose
        // anchor changed — the checker would be approving a different payout
        // than the one they reviewed.
        $derivedNow = PayoutRequestData::deriveRequestKey(
            (string) $payout->reference_number,
            (string) $payout->amount,
            $payout->currency,
            (int) $payout->user_id,
        );

        $requestKey = (string) $record['request_key'];

        if (! hash_equals($requestKey, $derivedNow)) {
            throw new PayoutApprovalException(
                sprintf(
                    'Payout approval request #%d: the obligation drifted since the maker raised the lane (request key no longer derives from the payout); no decision may anchor on it.',
                    (int) $payout->getKey(),
                ),
                PayoutApprovalException::CODE_DECISION_SHAPE,
                [
                    'request_id' => (int) $payout->getKey(),
                    'given_status' => $data->decision->value,
                ],
            );
        }

        $decided = array_merge($record, [
            'status' => $data->decision->value,
            'checker_user_id' => $data->actorUserId,
            'decision_reason' => $data->reason,
            'decided_at' => Carbon::now()->toIso8601String(),
            'decision_context' => $data->context,
        ]);

        DB::transaction(function () use ($payout, $decided, $data): void {
            $this->writeRecord($payout, $decided);
            $this->recordAudit(
                $payout,
                sprintf(
                    'Payout %s by checker #%d%s',
                    $data->decision === PayoutApprovalStatus::Approved ? 'approved' : 'rejected',
                    $data->actorUserId,
                    $data->isRejection() ? ' — reason: '.(string) $data->reason : '',
                ),
                $data->isApproval() ? RiskLevel::Medium : RiskLevel::Low,
            );
        });

        return $decided;
    }

    /**
     * Mark an approved lane Completed — the money actually moved. Called by
     * the execution lane (batch settlement), never on a human gesture.
     *
     * @return array<string, mixed>
     */
    public function complete(Payout $payout, ?int $executorUserId = null): array
    {
        $record = $this->recordFor($payout);

        if (! is_array($record)) {
            throw PayoutApprovalException::requestNotFound((int) $payout->getKey());
        }

        $status = $this->statusOf($record);

        if ($status === PayoutApprovalStatus::Completed) {
            return $record; // idempotent: execution lane retries are replays
        }

        if ($status !== PayoutApprovalStatus::Approved || ! $status?->canTransitionTo(PayoutApprovalStatus::Completed)) {
            throw PayoutApprovalException::notPending(
                (int) $payout->getKey(),
                $status?->value ?? 'unknown',
            );
        }

        $completed = array_merge($record, [
            'status' => PayoutApprovalStatus::Completed->value,
            'completed_at' => Carbon::now()->toIso8601String(),
            'executed_by_user_id' => $executorUserId,
        ]);

        DB::transaction(function () use ($payout, $completed): void {
            $this->writeRecord($payout, $completed);
            $this->recordAudit($payout, 'Payout approval lane completed (money released)', RiskLevel::Low);
        });

        return $completed;
    }

    /**
     * The lane's current status for a payout, or null when no request ever
     * named it. Never trust the stamped string — derive the enum here.
     */
    public function statusFor(Payout $payout): ?PayoutApprovalStatus
    {
        $record = $this->recordFor($payout);

        return is_array($record) ? $this->statusOf($record) : null;
    }

    /**
     * The full lane record for screens/audits, or null when never opened.
     *
     * @return array<string, mixed>|null
     */
    public function recordFor(Payout $payout): ?array
    {
        $metadata = is_array($payout->metadata) ? $payout->metadata : [];
        $record = $metadata[self::METADATA_KEY] ?? null;

        return is_array($record) ? $record : null;
    }

    /**
     * The question the batch selector asks: may money move against this
     * payout? Only a checker-Approved lane answers yes.
     */
    public function isApproved(Payout $payout): bool
    {
        return $this->statusFor($payout) === PayoutApprovalStatus::Approved;
    }

    /**
     * Is the lane still waiting for a checker to speak?
     */
    public function isPending(Payout $payout): bool
    {
        return $this->statusFor($payout) === PayoutApprovalStatus::Pending;
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function statusOf(array $record): ?PayoutApprovalStatus
    {
        $status = $record['status'] ?? null;

        return is_string($status) ? PayoutApprovalStatus::tryFrom($status) : null;
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function writeRecord(Payout $payout, array $record): void
    {
        $metadata = is_array($payout->metadata) ? $payout->metadata : [];
        $metadata[self::METADATA_KEY] = $record;

        $payout->metadata = $metadata;
        $payout->save();
    }

    /**
     * Every lane mutation leaves an audit row: WHO did WHAT to WHICH payout.
     * Descriptions carry the money (amount + currency) because the audit is
     * read before the ledger when something smells.
     */
    private function recordAudit(Payout $payout, string $description, RiskLevel $riskLevel): void
    {
        $log = new AuditLog();

        $log->fill([
            'user_id' => null,
            'action' => AuditAction::Payout,
            'risk_level' => $riskLevel,
            'auditable_type' => Payout::class,
            'auditable_id' => $payout->getKey(),
            'description' => sprintf(
                '%s on payout #%d (%s %s)',
                $description,
                (int) $payout->getKey(),
                (string) $payout->amount,
                $payout->currency->value,
            ),
            'metadata' => [
                'payout_id' => (int) $payout->getKey(),
                'payout_reference' => (string) $payout->reference_number,
                'amount' => (string) $payout->amount,
                'currency' => $payout->currency->value,
                'lane' => self::METADATA_KEY,
            ],
        ]);

        $log->save();
    }

    /**
     * Record a player's cancellation petition against an at-rest payout.
     *
     * WHAT THIS IS
     * The player-facing HTTP surface may never flip an obligation's status —
     * that is an operator-court act — but the player may SAY they no longer
     * want the obligation discharged. This lane writes that statement onto
     * the approval lane as an auditable petition, replayable and extant
     * strictly alongside the obligation's still-pending state.
     *
     * ADMITTANCE (defence in depth, mirroring the HTTP policy)
     * - the payout must be Pending: anything further along has already
     *   started moving (or finished) and is outside the player's reach.
     * - no LIVE approval request may occupy the lane: an in-flight maker↔
     *   checker conversation would race the petition for the outcome.
     *
     * IDEMPOTENCE
     * The petition carries a key anchored on (payout id, petitioner id);
     * submitting the same petition twice re-serves the existing stamp
     * without minting a duplicate row or a duplicate audit entry.
     *
     * @throws PayoutApprovalException
     */
    public function recordCancellationPetition(Payout $payout, int $petitionerUserId, ?string $reason): array
    {
        $locked = Payout::query()->lockForUpdate()->find((int) $payout->getKey());

        if (! $locked instanceof Payout) {
            throw PayoutApprovalException::requestNotFound((int) $payout->getKey());
        }

        if ($locked->status !== PayoutStatus::Pending) {
            throw PayoutApprovalException::cancellationRefused(
                (int) $locked->getKey(),
                $locked->status->value,
                'only an at-rest (pending) obligation accepts a cancellation petition',
            );
        }

        if ((int) $locked->user_id !== $petitionerUserId) {
            throw PayoutApprovalException::cancellationRefused(
                (int) $locked->getKey(),
                'foreign',
                'a petitioner may only petition their own obligation',
            );
        }

        $lane = $this->recordFor($locked) ?? [];

        // A live request outranks the petition: the court is already talking
        // about this obligation and must finish first.
        if (($lane['status'] ?? null) === 'pending' && ($lane['decision_reason'] ?? null) === null
            && is_string($lane['request_key'] ?? null)) {
            throw PayoutApprovalException::cancellationRefused(
                (int) $locked->getKey(),
                'approval_live',
                'an approval request is already in flight for this obligation',
            );
        }

        $petitionKey = hash('sha256', sprintf('payout-petition:%d:%d', (int) $locked->getKey(), $petitionerUserId));

        $existing = is_array($lane['cancellation_petition'] ?? null) ? $lane['cancellation_petition'] : null;

        if (is_array($existing) && ($existing['petition_key'] ?? null) === $petitionKey) {
            // Replay: re-serve, do not duplicate.
            return $existing;
        }

        $petition = [
            'petition_key' => $petitionKey,
            'petitioned_by_user_id' => $petitionerUserId,
            'reason' => $reason,
            'petitioned_at' => Carbon::now()->toIso8601String(),
            'outcome' => 'pending',
        ];

        $metadata = is_array($locked->metadata) ? $locked->metadata : [];
        $record = is_array($metadata[self::METADATA_KEY] ?? null) ? $metadata[self::METADATA_KEY] : [];
        $record['cancellation_petition'] = $petition;
        $metadata[self::METADATA_KEY] = $record;
        $locked->metadata = $metadata;
        $locked->save();

        $this->recordAudit(
            $locked,
            sprintf(
                'Cancellation petition recorded by player #%d%s',
                $petitionerUserId,
                $reason !== null ? sprintf(' (reason: %s)', $reason) : '',
            ),
            RiskLevel::Medium,
        );

        return $petition;
    }
}
