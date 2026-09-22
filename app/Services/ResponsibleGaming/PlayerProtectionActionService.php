<?php

declare(strict_types=1);

namespace App\Services\ResponsibleGaming;

use App\DTOs\ResponsibleGaming\PlayerProtectionActionData;
use App\DTOs\ResponsibleGaming\ResponsibleGamingLimitData;
use App\DTOs\ResponsibleGaming\SelfExclusionData;
use App\Enums\PlayerProtectionAction;
use App\Enums\ResponsibleGamingLimitType;
use App\Events\PlayerProtectionActionApplied;
use App\Exceptions\PlayerProtectionActionException;
use App\Listeners\RecordPlayerProtectionActionAudit;
use App\Models\PlayerProtectionAct;
use App\Models\PlayerProtectionCase;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Finance\WalletService;
use Illuminate\Support\Facades\DB;

/**
 * PlayerProtectionActionService — apply / release allowed protection
 * actions ATOMICALLY, by composing WITH the neighbouring gates —
 * WalletService (money), SelfExclusionService (the gate), the limit
 * service (ceilings) — never re-implementing their logic inline.
 *
 * - Dedup by deterministic action key: the same act replays free
 *   of arithmetic; a same-key/different-fact collision forks by name.
 * - Controlled acts are desk-acts (admin authority) — the player's
 *   own prayer is pronounced by the self-service gate lanes, not here.
 * - LockAccount is released by the counter-act of the same case with
 *   matching reason evidence; foreign wallet locks are named, never
 *   silently overridden nor claimed.
 */
final class PlayerProtectionActionService
{
    public function __construct(
        private readonly WalletService $wallets,
        private readonly SelfExclusionService $selfExclusions,
        private readonly ResponsibleGamingLimitService $limits,
        private readonly PlayerProtectionCaseService $cases,
        private readonly RecordPlayerProtectionActionAudit $audit,
    ) {}

    /**
     * APPLY the act: admin-acted, key-idempotent, composed gates
     * inside one transaction. Returns the population row for replay.
     *
     * @return array{action: PlayerProtectionAct, replayed: bool}
     */
    public function apply(PlayerProtectionActionData $data): array
    {
        return DB::transaction(function () use ($data): array {
            /** @var PlayerProtectionCase|null $case */
            $case = PlayerProtectionCase::query()->lockForUpdate()->where('case_key', $data->caseKey)->first();

            if (! $case instanceof PlayerProtectionCase || ! $case->status->isLive()) {
                throw PlayerProtectionActionException::missingCase($data->caseKey);
            }

            /** @var PlayerProtectionAct|null $existing */
            $existing = PlayerProtectionAct::query()->where('action_key', $data->actionKey())->first();

            if ($existing instanceof PlayerProtectionAct) {
                if ($existing->case_key !== $data->caseKey || $existing->action_type !== $data->actionType) {
                    throw PlayerProtectionActionException::alreadyApplied($data->actionKey());
                }

                return ['action' => $existing, 'replayed' => true];
            }

            /** @var User|null $actor */
            $actor = User::query()->find($data->actorUserId);

            if (! $actor instanceof User || ! $actor->hasRole('admin')) {
                throw PlayerProtectionActionException::unauthorized($data->actorUserId);
            }

            $act = new PlayerProtectionAct;
            $act->fill([
                'action_key' => $data->actionKey(),
                'case_key' => $data->caseKey,
                'action_type' => $data->actionType,
                'scope' => $data->scope,
                'actor_user_id' => $actor->id,
                'reason_code' => $data->reasonCode,
                'applied_at' => now(),
            ]);

            // The act composes ONLY ITS OWN gate; each lane enforces its
            // own law (money arithmetic never lives in this service).
            match ($data->actionType) {
                PlayerProtectionAction::LockAccount => $this->lockUserWallets($case, $act, $data),
                PlayerProtectionAction::SelfExclude => $this->pronounceSelfExclusion($case, $data),
                PlayerProtectionAction::CoolingOff => $this->pronounceCoolingOff($case, $data),
                PlayerProtectionAction::LowerLimit => $this->pronounceLowerLimit($case, $data),
                PlayerProtectionAction::SuspendMarketing => $this->suspendMarketing($case),
                PlayerProtectionAction::Review => null, // the fact of the act IS the review stamp
            };

            $act->save();
            $this->audit->from($act, sprintf('%s applied on case [%s]', $data->actionType->value, substr($data->caseKey, 0, 12)));

            event(new PlayerProtectionActionApplied($act, $case, now()->toIso8601String()));

            return ['action' => $act, 'replayed' => false];
        });
    }

    /**
     * RELEASE a durable restriction of this case: the counter-act is
     * pronounced against the SAME reason evidence, free-float
     * releases refused by name; only the court lifts its own seal.
     */
    public function release(PlayerProtectionActionData $data): PlayerProtectionAct
    {
        if (! $data->isReleasePronunciation()) {
            throw PlayerProtectionActionException::invalidAction('A release act must be pronounced with a RELEASE: reason');
        }

        // THE EVIDENCE THE RELEASE LIFTS: an unreleased durable act of
        // this case whose reason code is mirrored under the RELEASE:
        // prefix (or, for FR-33 evidence-reference releases, named by
        // the trailing token after the RELEASE prefix).
        $evidence = trim(substr($data->reasonCode, strlen('RELEASE:')));

        return DB::transaction(function () use ($data, $evidence): PlayerProtectionAct {
            /** @var PlayerProtectionCase|null $case */
            $case = PlayerProtectionCase::query()->lockForUpdate()->where('case_key', $data->caseKey)->first();

            if (! $case instanceof PlayerProtectionCase) {
                throw PlayerProtectionActionException::missingCase($data->caseKey);
            }

            /** @var PlayerProtectionAct|null $target */
            $target = PlayerProtectionAct::query()
                ->where('case_key', $data->caseKey)
                ->where('is_released', false)
                ->get()
                ->first(fn (PlayerProtectionAct $a) => $a->action_type->isDurableRestriction()
                    && ($a->reason_code === $evidence || $a->reason_code === $data->reasonCode));

            if (! $target instanceof PlayerProtectionAct) {
                throw PlayerProtectionActionException::releaseConflict($data->caseKey, 'no matching unreleased durable act');
            }

            /** @var User|null $actor */
            $actor = User::query()->find($data->actorUserId);

            if (! $actor instanceof User || ! $actor->hasRole('admin')) {
                throw PlayerProtectionActionException::unauthorized($data->actorUserId);
            }

            $target->is_released = true;
            $target->released_at = now();
            $target->released_by = $actor->id;
            $target->save();

            if ($target->action_type === PlayerProtectionAction::LockAccount && $target->wallet_id !== null) {
                /** @var Wallet|null $wallet */
                $wallet = Wallet::query()->lockForUpdate()->find($target->wallet_id);

                if ($wallet instanceof Wallet) {
                    $this->wallets->unlockWallet($wallet);
                }
            }

            $this->audit->from($target, sprintf('restriction released on case [%s]', substr($data->caseKey, 0, 12)));

            return $target->refresh();
        });
    }

    private function lockUserWallets(PlayerProtectionCase $case, PlayerProtectionAct $act, PlayerProtectionActionData $data): void
    {
        /** @var Wallet|null $wallet */
        $wallet = Wallet::query()->where('user_id', $case->user_id)->lockForUpdate()->first();

        if (! $wallet instanceof Wallet) {
            return; // the stamp alone stands; no wallet to compose
        }

        if ($wallet->status->value === 'locked') {
            // The gate is foreign if another act's seal stands here.
            $mine = PlayerProtectionAct::query()
                ->where('case_key', $case->case_key)
                ->where('action_type', PlayerProtectionAction::LockAccount->value)
                ->where('is_released', false)
                ->exists();

            if (! $mine) {
                throw PlayerProtectionActionException::foreignLockConflict($case->case_key, (int) $wallet->id);
            }

            return;
        }

        $this->wallets->lockWallet($wallet, sprintf('protection case %s: %s', substr($case->case_key, 0, 12), $data->reasonCode));
        $act->wallet_id = $wallet->id;
        $act->wallet_fact = $wallet->status->value;
    }

    private function pronounceSelfExclusion(PlayerProtectionCase $case, PlayerProtectionActionData $data): void
    {
        $moment = now();
        $this->selfExclusions->activate($this->selfExclusions->request(SelfExclusionData::fromInput([
            'user_id' => $case->user_id,
            'reason_code' => 'PROTECTION_PROTECTION_'.strtoupper(substr($case->case_key, 0, 16)),
            'scope' => 'account',
            'effective_at' => $moment,
            'ends_at' => $moment->copy()->addDays(30),
        ]))->id);
    }

    private function pronounceCoolingOff(PlayerProtectionCase $case, PlayerProtectionActionData $data): void
    {
        $moment = now();
        $this->selfExclusions->activate($this->selfExclusions->request(SelfExclusionData::fromInput([
            'user_id' => $case->user_id,
            'reason_code' => 'COOLING_OFF_'.strtoupper(substr($case->case_key, 0, 16)),
            'scope' => 'account',
            'effective_at' => $moment,
            'ends_at' => $moment->copy()->addHours(PlayerProtectionAction::CoolingOff->defaultHorizonHours() ?? 24),
        ]))->id);
    }

    private function pronounceLowerLimit(PlayerProtectionCase $case, PlayerProtectionActionData $data): void
    {
        $this->limits->pronounce(ResponsibleGamingLimitData::fromInput([
            'user_id' => $case->user_id,
            'limit_type' => ResponsibleGamingLimitType::DailyDeposit,
            'amount' => '50.00',
            'currency' => 'THB',
        ]));
    }

    private function suspendMarketing(PlayerProtectionCase $case): void
    {
        /** @var User|null $user */
        $user = User::query()->find($case->user_id);

        if ($user instanceof User) {
            // The suspension lands on the player preferences lane —
            // the marketing dispatcher reads it from there.
            $preferences = (array) ($user->preferences ?? []);
            $preferences['marketing_suspended'] = true;
            $user->preferences = $preferences;
            $user->save();
        }
    }
}
