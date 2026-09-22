<?php

declare(strict_types=1);

namespace App\Services\Withdrawal;

use App\Enums\AuditAction;
use App\Enums\KycStatus;
use App\Enums\RiskLevel;
use App\Enums\WithdrawalStatus;
use App\Events\WithdrawalKycApproved;
use App\Exceptions\WithdrawalKycException;
use App\Models\AuditLog;
use App\Models\KycDocument;
use App\Models\Withdrawal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The money-out KYC GATE.
 *
 * WHAT THE GATE ANSWERS
 * ---------------------
 * "May THIS withdrawal's money leave the system to THIS identity?" — asked
 * at the pre-approval lane and re-asked before Processing (the irreversible
 * state). The gate never approves anything itself: it certifies identity
 * standing, and the withdrawal approval lane continues owning the idea of
 * "approved".
 *
 * THE TWO VERDICTS
 * ----------------
 *   PASS  — the requester's aggregate KYC standing is Verified (and the
 *           amount-at-or-above-threshold rule made verification mandatory;
 *           amounts BELOW the configured threshold pass by default and are
 *           honestly marked as such). On pass the gate stamps the
 *           withdrawal's kyc_gate lane. If the withdrawal had been
 *           detained (KycRequired), passing lifts the detention back to
 *           Pending for the human review to resume — and emits
 *           WithdrawalKycApproved exactly once per evidence identity.
 *
 *   DETAIN AND REFUSE — evidence missing / unverified / expired. The
 *           withdrawal moves into KycRequired (NO funds are reserved in
 *           that state by lifecycle semantics) so the request stays
 *           recoverable the moment valid evidence lands — while the gate
 *           tells the caller precisely WHICH certificate failed.
 *
 * CONFIGURATION
 * -------------
 *   finance.withdrawal.kyc_gate_enabled    master switch. When off,
 *                                          requiresGate() answers false
 *                                          everywhere; gate() answers a
 *                                          pass verdict without touching
 *                                          rows, and consumers that
 *                                          NEED the gate to exist (jobs
 *                                          gating on it) must refuse
 *                                          with gateDisabled.
 *   finance.withdrawal.kyc_gate_threshold  decimal string. At or above →
 *                                          gate mandatory.
 *
 * IDEMPOTENCY: a pass verdict already stamped for the same withdrawal +
 * anchor is a REPLAY: no row mutation, no event.
 */
class WithdrawalKycGateService
{
    /**
     * withdrawals.metadata lane.
     */
    public const METADATA_KEY = 'kyc_gate';

    public function isEnabled(): bool
    {
        return (bool) config('finance.withdrawal.kyc_gate_enabled', true);
    }

    /**
     * Does the gate subject this withdrawal to mandatory verification?
     */
    public function isMandatoryFor(Withdrawal $withdrawal): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }

        $threshold = config('finance.withdrawal.kyc_gate_threshold');

        if (! is_string($threshold) && ! is_numeric($threshold)) {
            return true; // unconfigured floor: treat as zero — always mandatory
        }

        return bccomp(bcadd((string) $withdrawal->amount, '0.00', 2), bcadd((string) $threshold, '0.00', 2), 2) >= 0;
    }

    /**
     * Whether the approval lane should route this withdrawal through gate().
     */
    public function requiresGate(Withdrawal $withdrawal): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }

        $status = $withdrawal->status;

        if (! in_array($status, [WithdrawalStatus::Pending, WithdrawalStatus::UnderReview, WithdrawalStatus::KycRequired, WithdrawalStatus::Approved], true)) {
            return false;
        }

        return $this->isMandatoryFor($withdrawal);
    }

    /**
     * Run the gate over a withdrawal.
     *
     * @return array{verdict: string, anchor: string|null, detention: bool}
     *
     * @throws WithdrawalKycException  In every detention or misuse case,
     *                                 except replays answered by shape.
     */
    public function gate(Withdrawal $withdrawal): array
    {
        if (DB::transactionLevel() > 0) {
            throw new WithdrawalKycException(
                'Withdrawal KYC gating owns its transaction boundary.',
                WithdrawalKycException::CODE_STATE_FORBIDS,
                ['transaction_level' => DB::transactionLevel()],
            );
        }

        // First pass: commit the row work inside the transaction. The DETAINED
        // branch decidedly THROWS at the caller — but an exception inside the
        // closure would roll the freshly-stamped detention right back out, so
        // the outcome is produced HERE and only escalated AFTER the commit.
        $outcome = DB::transaction(function () use ($withdrawal): array {
            /** @var Withdrawal|null $locked */
            $locked = Withdrawal::query()->lockForUpdate()->find((int) $withdrawal->getKey());

            if (! $locked instanceof Withdrawal) {
                throw WithdrawalKycException::evidenceMissing(0, ['detail' => 'withdrawal row absent']);
            }

            $status = $locked->status;

            // The gate admits the full pre-irreversible ladder: the two
            // pre-approval review states, its own detention state, and
            // APPROVED — the cliff edge, the exact moment the approval-to-
            // processing gap needs re-verification across.
            if (! in_array($status, [WithdrawalStatus::Pending, WithdrawalStatus::UnderReview, WithdrawalStatus::KycRequired, WithdrawalStatus::Approved], true)) {
                throw WithdrawalKycException::stateForbids(
                    (string) $locked->reference_number,
                    $status->value,
                );
            }

            // BELOW THRESHOLD: the gate passes by configuration, and says
            // so honestly — a full audit trail of all gate decisions.
            if (! $this->isMandatoryFor($locked)) {
                $verdict = $this->recordFor($locked);

                if (! is_array($verdict) || ($verdict['verdict'] ?? null) !== 'below_threshold') {
                    $this->writeVerdict($locked, [
                        'verdict' => 'below_threshold',
                        'anchor' => null,
                        'detained' => false,
                        'evidence_document_id' => null,
                        'at' => Carbon::now()->toIso8601String(),
                    ]);

                    $this->recordAudit($locked, 'KYC gate: below the verification threshold; passed by configuration.', RiskLevel::Low);
                }

                return ['verdict' => 'below_threshold', 'anchor' => null, 'detention' => false];
            }

            // THE MANDATORY LANES: evidence standing decides.
            $standing = $this->kycStandingFor((int) $locked->user_id);

            if ($standing['state'] === 'verified' && $standing['document'] instanceof KycDocument) {
                $anchor = $this->deriveAnchor($locked, $standing['document']);

                $existing = $this->recordFor($locked);

                if (is_array($existing)
                    && ($existing['verdict'] ?? null) === 'passed'
                    && ($existing['anchor'] ?? null) === $anchor
                ) {
                    return ['verdict' => 'passed', 'anchor' => $anchor, 'detention' => false]; // replay
                }

                $wasDetained = $status === WithdrawalStatus::KycRequired;

                $this->writeVerdict($locked, [
                    'verdict' => 'passed',
                    'anchor' => $anchor,
                    'detained' => false,
                    'evidence_document_id' => (int) $standing['document']->getKey(),
                    'at' => Carbon::now()->toIso8601String(),
                ]);

                // LIFT THE DETENTION: back to Pending for the human lane;
                // the gate never prepends itself as "approved". An APPROVED
                // withdrawal passing the cliff edge stays Approved — the
                // processing flip is the caller's next act, not the gate's.
                if ($wasDetained) {
                    $locked->status = WithdrawalStatus::Pending;
                    $locked->save();
                }

                // The event fires exactly once per (withdrawal, evidence
                // anchor): a pass under a NEW verification document gets a
                // fresh anchor and a fresh audit, as the law would want.
                event(new WithdrawalKycApproved(
                    withdrawal: $locked,
                    userId: (int) $locked->user_id,
                    evidenceDocumentId: (int) $standing['document']->getKey(),
                    anchor: $anchor,
                    wasDetained: $wasDetained,
                ));

                return ['verdict' => 'passed', 'anchor' => $anchor, 'detention' => false];
            }

            // DETENTION: refuse, and stand the withdrawal at KycRequired.
            $reason = match ($standing['state']) {
                'missing' => 'no identity evidence on file',
                'unverified' => sprintf('identity evidence unverified (latest at status %s)', (string) ($standing['document_status'] ?? 'unknown')),
                'expired' => 'identity evidence has expired',
                default => 'inadmissible identity standing',
            };

            // DETENTION. From the pre-approval states the withdrawal stands
            // detained at KycRequired. From APPROVED it stays Approved: the
            // reservation must NOT be silently released by the gate (that
            // is the operator's explicit reject gesture), and the lifecycle
            // may never step backwards — the caller blocking the transition
            // IS the detention on the cliff edge.
            if ($status !== WithdrawalStatus::KycRequired && $status !== WithdrawalStatus::Approved) {
                $locked->status = WithdrawalStatus::KycRequired;
                $locked->save();
            }

            $this->writeVerdict($locked, [
                'verdict' => 'detained',
                'anchor' => null,
                'detained' => true,
                'detention_reason' => $reason,
                'evidence_document_id' => $standing['document'] instanceof KycDocument ? (int) $standing['document']->getKey() : null,
                'at' => Carbon::now()->toIso8601String(),
            ]);

            $this->recordAudit($locked, sprintf('KYC gate DETAINED the withdrawal: %s (small-table risk rises; funds unreserved).', $reason), RiskLevel::High);

            // The transaction commits the detention ABOVE; the exception is
            // thrown outside it below.
            return [
                'outcome' => 'detained',
                'standing' => $standing,
                'user_id' => (int) $locked->user_id,
                'reason' => $reason,
            ];
        });

        // AFTER COMMIT: a detention escalates into the caller-facing refusal.
        if (($outcome['outcome'] ?? null) === 'detained') {
            $standing = $outcome['standing'];

            throw match ($standing['state']) {
                'missing' => WithdrawalKycException::evidenceMissing((int) $outcome['user_id']),
                'unverified' => WithdrawalKycException::evidenceUnverified(
                    (int) $outcome['user_id'],
                    (string) ($standing['document_status'] ?? 'unknown'),
                ),
                'expired' => WithdrawalKycException::evidenceExpired(
                    (int) $outcome['user_id'],
                    $standing['document'] instanceof KycDocument
                        ? ($standing['document']->updated_at?->toIso8601String() ?? 'unknown')
                        : 'unknown',
                ),
                default => WithdrawalKycException::identityMismatch(
                    (int) $outcome['user_id'],
                    (string) $outcome['reason'],
                ),
            };
        }

        return $outcome;
    }

    /**
     * The gate's re-check entry for VerifyWithdrawalKycJob: re-run the
     * verdict pipeline over the current evidence state. The job asks the
     * question WITHOUT the misuse throws — everything's framed as boolean.
     *
     * @return array{verdict: string, anchor: string|null, detention: bool}
     */
    public function recheck(int $withdrawalId): array
    {
        $withdrawal = Withdrawal::query()->find($withdrawalId);

        if (! $withdrawal instanceof Withdrawal) {
            return ['verdict' => 'missing', 'anchor' => null, 'detention' => false];
        }

        try {
            return $this->gate($withdrawal);
        } catch (WithdrawalKycException) {
            return ['verdict' => 'detained', 'anchor' => null, 'detention' => true];
        }
    }

    /**
     * The requester's aggregate KYC standing from kyc_documents.
     *
     * VERIFIED stands on the LATEST VERIFIED document (verified_at set,
     * status verified, and — when a status lane marks it — not expired).
     * Missing → 'missing'; everything else reads off the newest document's
     * status lane: 'unverified' or 'expired'.
     *
     * @return array{state: string, document: ?KycDocument, document_status: ?string}
     */
    public function kycStandingFor(int $userId): array
    {
        $document = KycDocument::query()
            ->where('user_id', $userId)
            ->orderByDesc('id')
            ->first();

        if (! $document instanceof KycDocument) {
            return ['state' => 'missing', 'document' => null, 'document_status' => null];
        }

        $verifiedDoc = KycDocument::query()
            ->where('user_id', $userId)
            ->whereNotNull('verified_at')
            ->orderByDesc('verified_at')
            ->first();

        if ($verifiedDoc instanceof KycDocument) {
            $docStatus = is_string($verifiedDoc->status)
                ? $verifiedDoc->status
                : (string) ($verifiedDoc->status->value ?? '');

            if ($docStatus !== KycStatus::Expired->value && $docStatus !== KycStatus::Rejected->value) {
                return ['state' => 'verified', 'document' => $verifiedDoc, 'document_status' => $docStatus];
            }
        }

        $status = is_string($document->status)
            ? $document->status
            : (string) ($document->status->value ?? '');

        if ($status === KycStatus::Expired->value) {
            return ['state' => 'expired', 'document' => $document, 'document_status' => $status];
        }

        return ['state' => 'unverified', 'document' => $document, 'document_status' => $status];
    }

    /**
     * anchor = sha256(withdrawal reference + amount + currency + doc id +
     * doc verified_at). A freshly verified document yields a fresh anchor,
     * so every evidence generation carries its own pass paper.
     */
    public function deriveAnchor(Withdrawal $withdrawal, KycDocument $document): string
    {
        return hash('sha256', sprintf(
            'withdrawal-kyc:%s:%s:%s:%d:%s',
            (string) $withdrawal->reference_number,
            bcadd((string) $withdrawal->amount, '0.00', 2),
            $withdrawal->currency->value,
            (int) $document->getKey(),
            $document->verified_at instanceof Carbon ? $document->verified_at->toIso8601String() : '',
        ));
    }

    /**
     * The stamped gate record, or null.
     *
     * @return array<string, mixed>|null
     */
    public function recordFor(Withdrawal $withdrawal): ?array
    {
        $metadata = is_array($withdrawal->metadata) ? $withdrawal->metadata : [];
        $record = $metadata[self::METADATA_KEY] ?? null;

        return is_array($record) ? $record : null;
    }

    /**
     * @param  array<string, mixed>  $verdict
     */
    private function writeVerdict(Withdrawal $withdrawal, array $verdict): void
    {
        $metadata = is_array($withdrawal->metadata) ? $withdrawal->metadata : [];
        $metadata[self::METADATA_KEY] = $verdict;

        $withdrawal->metadata = $metadata;
        $withdrawal->save();
    }

    private function recordAudit(Withdrawal $withdrawal, string $description, RiskLevel $riskLevel): void
    {
        $log = new AuditLog();

        $log->fill([
            'user_id' => null,
            'action' => AuditAction::Withdraw,
            'risk_level' => $riskLevel,
            'auditable_type' => Withdrawal::class,
            'auditable_id' => $withdrawal->getKey(),
            'description' => sprintf('Withdrawal [%s]: %s', $withdrawal->reference_number, $description),
            'metadata' => [
                'withdrawal_id' => (int) $withdrawal->getKey(),
                'withdrawal_reference' => (string) $withdrawal->reference_number,
                'lane' => self::METADATA_KEY,
            ],
        ]);

        $log->save();
    }
}
