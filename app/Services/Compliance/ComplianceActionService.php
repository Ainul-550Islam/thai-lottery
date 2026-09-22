<?php

declare(strict_types=1);

namespace App\Services\Compliance;

use App\DTOs\Compliance\ComplianceActionData;
use App\Enums\ComplianceActionType;
use App\Enums\ComplianceCaseStatus;
use App\Enums\WalletStatus;
use App\Exceptions\ComplianceActionException;
use App\Exceptions\ComplianceCaseException;
use App\Listeners\RecordComplianceActionAudit;
use App\Models\ComplianceAction;
use App\Models\ComplianceCase;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Finance\WalletService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Atomically-applied controlled compliance actions.
 *
 * THE LAW OF THIS LANE
 *   1. DETERMINISTIC ACTS — the act's identity is its sha-256 key
 *      over (case, type, reason, actor, evidence): re-delivering the
 *      act replays the row; a different act under the key is a fork.
 *   2. MONEY LINES ARE COMPOSED, NEVER COPIED — restricting a wallet
 *      means WalletService::lockWallet (its own guard clauses, its
 *      own transaction discipline); the lane holds no arithmetic.
 *   3. THE COURT LIFTS ONLY ITS OWN SEAL — a Release act may only
 *      release the Restrict/Hold act deterministically named by its
 *      evidence reference; trying to lift another case's hold is a
 *      RELEASE_HOLD_CONFLICT, refused by name.
 *   4. AUTHORITY — controlled acts are admin-only. Every act lands
 *      a fingerprint-deduplicated audit anchor in the same
 *      transaction (never raw identity-document contents).
 */
final class ComplianceActionService
{
    public function __construct(
        private readonly WalletService $wallets,
    ) {}

    /* ---------------------------------------------------- apply --- */

    /**
     * Apply a controlled act to a case atomically.
     *
     * @return array{action: ComplianceAction, replayed: bool}
     *
     * @throws ComplianceActionException|ComplianceCaseException
     */
    public function apply(ComplianceActionData $data): array
    {
        return DB::transaction(function () use ($data): array {
            /** @var ComplianceCase|null $case */
            $case = ComplianceCase::query()
                ->lockForUpdate()
                ->where('case_key', $data->caseKey)
                ->first();

            // LIVE-FILE GATE — with the Release exception: a resolving
            // release is the very act that permits a Resolved file to
            // seal, so Release (and only Release) remains admissible
            // while the file is Resolved-but-not-Closed. Everything
            // else touches live trajectories alone.
            $releaseAdmissible = $data->actionType === ComplianceActionType::Release
                && $case instanceof ComplianceCase
                && $case->status === ComplianceCaseStatus::Resolved;

            if (! $case instanceof ComplianceCase || (! $case->status->isLive() && ! $releaseAdmissible)) {
                throw ComplianceActionException::missingCase($data->caseKey);
            }

            /** @var User|null $actor */
            $actor = User::query()->find($data->actorUserId);

            if (! $actor instanceof User || ! $actor->isAdmin()) {
                throw ComplianceActionException::unauthorized($data->actorUserId);
            }

            /** @var ComplianceAction|null $existing */
            $existing = ComplianceAction::query()
                ->lockForUpdate()
                ->where('action_key', $data->actionKey())
                ->first();

            if ($existing instanceof ComplianceAction) {
                $factsMatch = (int) $existing->case_id === (int) $case->id
                    && $existing->action_type === $data->actionType
                    && (string) $existing->reason_code === $data->reasonCode
                    && (int) $existing->actor_user_id === $data->actorUserId
                    && (string) $existing->evidence_reference === $data->evidenceReference;

                if (! $factsMatch) {
                    throw ComplianceActionException::alreadyApplied($data->actionKey());
                }

                return ['action' => $existing, 'replayed' => true];
            }

            // --- COMPOSED SIDE-EFFECTS (wallet lanes own arithmetic) ---
            $walletFact = null;

            if ($data->actionType->touchesWallet() && $data->actionType !== ComplianceActionType::Release) {
                /** @var Wallet|null $wallet */
                $wallet = Wallet::query()->lockForUpdate()->where('user_id', (int) $case->subject_user_id)->first();

                if (! $wallet instanceof Wallet) {
                    throw ComplianceActionException::invalidAction('the subject holds no wallet to restrict');
                }

                $walletStatus = $wallet->status instanceof WalletStatus ? $wallet->status : WalletStatus::tryFrom((string) $wallet->status);

                if ($walletStatus === WalletStatus::Locked) {
                    $reason = (string) ($wallet->locked_reason ?? '');
                    $alreadyMine = ComplianceAction::query()
                        ->where('case_id', (int) $case->id)
                        ->whereIn('action_type', [ComplianceActionType::Restrict->value, ComplianceActionType::Hold->value])
                        ->where('is_released', false)
                        ->exists();

                    if (! $alreadyMine) {
                        throw ComplianceActionException::releaseHoldConflict(
                            (string) $case->case_key,
                            sprintf('the wallet is already locked under [%s] — this case may not take another case\'s seal silently', Str::limit($reason, 60, '')),
                        );
                    }
                } else {
                    $this->wallets->lockWallet($wallet, sprintf('case %s: %s (%s)', substr((string) $case->case_key, 0, 12), $data->reasonCode, $data->actionType->value));
                }

                $walletFact = sprintf('wallet:%d:locked:%s:%s', (int) $wallet->id, $data->actionType->value, $data->reasonCode);
            }

            if ($data->actionType === ComplianceActionType::Release) {
                $walletFact = $this->releaseWithin($case, $data);
            }

            if ($data->actionType === ComplianceActionType::Escalate) {
                app(ComplianceCaseService::class)->escalate($case, sprintf('Action [%s] escalates: %s', $data->reasonCode, $data->evidenceReference), 'action');
            }

            $row = new ComplianceAction;
            $row->fill([
                'action_key' => $data->actionKey(),
                'case_id' => (int) $case->id,
                'reason_code' => $data->reasonCode,
                'actor_user_id' => $data->actorUserId,
                'evidence_reference' => $data->evidenceReference,
                'wallet_fact' => $walletFact,
                'applied_at' => now(),
                'is_released' => false,
                'metadata' => [],
            ]);
            $row->action_type = $data->actionType;
            $row->save();

            RecordComplianceActionAudit::from($row, sprintf('%s on case %s... (%s)', $data->actionType->value, substr((string) $case->case_key, 0, 12), $data->reasonCode));

            return ['action' => $row, 'replayed' => false];
        });
    }

    /* -------------------------------------------------- release --- */

    /**
     * THE COURT'S SEAL ONLY: release the still-unreleased Restrict/Hold
     * act on this case deterministically named by the release act's
     * evidence reference.
     *
     * @throws ComplianceActionException
     */
    private function releaseWithin(ComplianceCase $case, ComplianceActionData $data): string
    {
        /** @var ComplianceAction|null $target */
        $target = ComplianceAction::query()
            ->lockForUpdate()
            ->where('case_id', (int) $case->id)
            ->whereIn('action_type', [ComplianceActionType::Restrict->value, ComplianceActionType::Hold->value])
            ->where('is_released', false)
            ->where('evidence_reference', $data->evidenceReference)
            ->orderByDesc('id')
            ->first();

        if (! $target instanceof ComplianceAction) {
            throw ComplianceActionException::releaseHoldConflict(
                (string) $case->case_key,
                'no unreleased Restrict/Hold act on this case carries that evidence reference — the court lifts only its own seals',
            );
        }

        /** @var Wallet|null $wallet */
        $wallet = Wallet::query()->lockForUpdate()->where('user_id', (int) $case->subject_user_id)->first();

        if ($wallet instanceof Wallet) {
            $walletStatus = $wallet->status instanceof WalletStatus ? $wallet->status : WalletStatus::tryFrom((string) $wallet->status);

            if ($walletStatus === WalletStatus::Locked) {
                $this->wallets->unlockWallet($wallet);
            }
        }

        $target->is_released = true;
        $target->released_at = now();
        $target->release_note = Str::limit(sprintf('Released by #%d per [%s]', $data->actorUserId, $data->reasonCode), 255, '');
        $target->save();

        return sprintf('wallet:%d:unlocked:%s', $wallet instanceof Wallet ? (int) $wallet->id : 0, $data->reasonCode);
    }
}
