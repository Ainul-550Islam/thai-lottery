<?php

declare(strict_types=1);

namespace App\Services\Prize;

use App\DTOs\Prize\PrizeDisbursementData;
use App\Enums\PayoutStatus;
use App\Enums\PrizeClaimStatus;
use App\Enums\PrizeDisbursementStatus;
use App\Listeners\RecordPrizeDisbursementAudit;
use App\Exceptions\PrizeDisbursementException;
use App\Models\Payout;
use App\Models\PrizeDisbursement;
use Illuminate\Support\Facades\DB;

/**
 * The settlement court: approved prize claims → reservation → final
 * disbursement, with exact bcmath conservation.
 *
 * THE INVARIANTS, IN REFUSAL ORDER
 *   1. PAYOUT TRUTH — the settlement fingerprint presented must equal
 *      what the payout's own row re-derives (ref + amount + currency);
 *      the caller describes the money, the court NEVER trusts the
 *      description.
 *   2. APPROVAL — the claim lane says Approved (else nobody gets money).
 *   3. ELIGIBILITY — the eligibility lane admits (fail-closed).
 *   4. CONSERVATION — Σ(live Reserved+Disbursed amounts) + new amount
 *      must NEVER exceed the payout's own amount; the law is bcmath
 *      from the first call, not a rounding-please-later.
 *   5. STATE — one-way (Pending→Reserved→Disbursed→Reversed/Failed);
 *      terminal rows stay dead.
 *   6. REPLAY — the same ask (deterministic key) re-serves; a divergent
 *      ask against the same payout+batch+amount forks and is refused.
 *
 * All settlement facts ALSO land on the durable scribe (the listener
 * lane) — reservation / disbursement / reversal / failure, with actor
 * note, amount, and settlement fingerprint.
 */
final class PrizeDisbursementService
{
    public function __construct(
        private readonly PrizeEligibilityService $eligibility,
        private readonly RecordPrizeDisbursementAudit $scribe,
    ) {
    }

    /* ------------------------------------------------- fingerprint --- */

    /**
     * The settlement fingerprint the payout's own row would answer with.
     */
    public static function expectedFingerprint(int $payoutId): ?string
    {
        /** @var Payout|null $payout */
        $payout = Payout::query()->find($payoutId);

        if (! $payout instanceof Payout) {
            return null;
        }

        return self::fingerprintFor($payout);
    }

    public static function fingerprintFor(Payout $payout): string
    {
        // Payout casts currency/amount through their enums/decimal casts
        // on the model; the fingerprint lane speaks plain strings.
        $currency = $payout->currency instanceof \BackedEnum
            ? (string) $payout->currency->value
            : (string) $payout->currency;

        return hash('sha256', sprintf(
            'prize-settlement:%s:%s:%s',
            (string) $payout->reference_number,
            self::moneyOf((string) $payout->amount),
            $currency,
        ));
    }

    /* --------------------------------------------------- reserve ---- */

    /**
     * @return array{disbursement: PrizeDisbursement, replayed: bool}
     *
     * @throws PrizeDisbursementException
     */
    public function reserve(PrizeDisbursementData $data, string $actorNote = 'service'): array
    {
        if (DB::transactionLevel() > 0) {
            return $this->reserveWithin($data, $actorNote);
        }

        return DB::transaction(fn (): array => $this->reserveWithin($data, $actorNote));
    }

    /**
     * @return array{disbursement: PrizeDisbursement, replayed: bool}
     *
     * @throws PrizeDisbursementException
     */
    private function reserveWithin(PrizeDisbursementData $data, string $actorNote): array
    {
        // REPLAY first, so a re-ask never re-writes anything — but a
        // replay is only a replay when THE FACTS agree too. A presented
        // settlement fingerprint that disagrees with the seated row is a
        // fork with criminal grammar, never a "thank you again".
        $existing = PrizeDisbursement::query()
            ->lockForUpdate()
            ->where('disbursement_key', $data->disbursementKey())
            ->first();

        if ($existing instanceof PrizeDisbursement) {
            if (! hash_equals((string) $existing->settlement_fingerprint, $data->settlementFingerprint)) {
                throw PrizeDisbursementException::payoutMismatch(
                    (int) $existing->payout_id,
                    (string) $existing->settlement_fingerprint,
                    $data->settlementFingerprint,
                );
            }

            if ($existing->status->isTerminal()) {
                throw PrizeDisbursementException::duplicate($data->disbursementKey());
            }

            return ['disbursement' => $existing, 'replayed' => true];
        }

        /** @var Payout|null $payout */
        $payout = Payout::query()->lockForUpdate()->find($data->payoutId);

        if (! $payout instanceof Payout) {
            throw PrizeDisbursementException::notFound('payout:'.$data->payoutId);
        }

        // TRUTH.
        if (! hash_equals(self::fingerprintFor($payout), $data->settlementFingerprint)) {
            throw PrizeDisbursementException::payoutMismatch(
                (int) $payout->getKey(),
                'payout row facts',
                'presented fingerprint',
            );
        }

        // APPROVAL: the claim lane's own metadata, authoritative here.
        $claimStatus = (string) data_get($payout->metadata, 'claim.status', 'none');

        if ($claimStatus !== PrizeClaimStatus::Approved->value) {
            throw PrizeDisbursementException::unapprovedClaim((int) $payout->getKey(), $claimStatus);
        }

        // ELIGIBILITY: fail-closed lane.
        try {
            $this->eligibility->assertAdmits((int) $payout->getKey());
        } catch (\App\Exceptions\PrizeEligibilityException $e) {
            throw PrizeDisbursementException::ineligible((int) $payout->getKey(), (string) $e->errorCode());
        }

        // CONSERVATION (bcmath all the way).
        $reservedTotal = $this->ledgersTotal((int) $payout->getKey());
        $newTotal = bcadd($reservedTotal, self::moneyOf($data->amount), 2);

        if (bccomp($newTotal, self::moneyOf((string) $payout->amount), 2) === 1) {
            $available = bcsub(self::moneyOf((string) $payout->amount), $reservedTotal, 2);

            throw PrizeDisbursementException::reservationConflict(
                (int) $payout->getKey(),
                self::moneyOf($data->amount),
                $available,
            );
        }

        $row = new PrizeDisbursement();
        $row->fill([
            'disbursement_key' => $data->disbursementKey(),
            'payout_id' => (int) $payout->getKey(),
            'payout_batch_id' => $data->batchId,
            'amount' => self::moneyOf($data->amount),
            'currency' => $data->currency,
            'settlement_fingerprint' => $data->settlementFingerprint,
            'reserved_at' => now(),
            'metadata' => [],
        ]);
        $row->status = PrizeDisbursementStatus::Reserved;
        $row->save();

        $this->scribe->from(
            payout: $payout,
            act: 'reserved',
            amount: self::moneyOf($data->amount),
            fingerprint: $data->settlementFingerprint,
            actorNote: $actorNote,
            disbursementId: (int) $row->getKey(),
        );

        return ['disbursement' => $row, 'replayed' => false];
    }

    /* -------------------------------------------------- disburse ---- */

    /**
     * Finalize: Reserved → Disbursed, AND the payout row rotates into
     * the completed domain in the same transaction (the payout model's
     * own lifecycle is never written half-way).
     *
     * @throws PrizeDisbursementException
     */
    public function disburse(PrizeDisbursement $disbursement, string $actorNote = 'service'): PrizeDisbursement
    {
        return DB::transaction(function () use ($disbursement, $actorNote): PrizeDisbursement {
            /** @var PrizeDisbursement|null $locked */
            $locked = PrizeDisbursement::query()->lockForUpdate()->find((int) $disbursement->getKey());

            if (! $locked instanceof PrizeDisbursement) {
                throw PrizeDisbursementException::notFound((string) $disbursement->disbursement_key);
            }

            if ($locked->status === PrizeDisbursementStatus::Disbursed) {
                return $locked; // replay
            }

            if (!$locked->status->canTransitionTo(PrizeDisbursementStatus::Disbursed)) {
                if ($locked->status->isTerminal()) {
                    throw PrizeDisbursementException::reversalViolation((string) $locked->disbursement_key, $locked->status->value);
                }

                throw PrizeDisbursementException::notFound((string) $locked->disbursement_key.' @ '.$locked->status->value);
            }

            // The reserved amount was already held at reservation time —
            // conservation here is the PAYOUT side: disbursed total per
            // payout never exceeds the payout's own money.
            /** @var Payout|null $payout */
            $payout = Payout::query()->lockForUpdate()->find((int) $locked->payout_id);

            if (! $payout instanceof Payout) {
                throw PrizeDisbursementException::notFound('payout:'.$locked->payout_id);
            }

            $locked->status = PrizeDisbursementStatus::Disbursed;
            $locked->disbursed_at = now();
            $locked->save();

            $payout->status = PayoutStatus::Completed;
            $payout->processed_at = now();
            $payout->save();

            $this->scribe->from(
                payout: $payout,
                act: 'disbursed',
                amount: self::moneyOf((string) $locked->amount),
                fingerprint: (string) $locked->settlement_fingerprint,
                actorNote: $actorNote,
                disbursementId: (int) $locked->getKey(),
            );

            return $locked;
        });
    }

    /* --------------------------------------------------- reverse ----- */

    /**
     * Operator-voiced reversal of a DISBURSED row — always pronounced.
     *
     * @throws PrizeDisbursementException
     */
    public function reverse(PrizeDisbursement $disbursement, string $reason, string $actorNote = 'service'): PrizeDisbursement
    {
        return DB::transaction(function () use ($disbursement, $reason, $actorNote): PrizeDisbursement {
            /** @var PrizeDisbursement|null $locked */
            $locked = PrizeDisbursement::query()->lockForUpdate()->find((int) $disbursement->getKey());

            if (! $locked instanceof PrizeDisbursement) {
                throw PrizeDisbursementException::notFound((string) $disbursement->disbursement_key);
            }

            if ($locked->status === PrizeDisbursementStatus::Reversed) {
                return $locked;
            }

            if (!$locked->status->canTransitionTo(PrizeDisbursementStatus::Reversed)) {
                throw PrizeDisbursementException::reversalViolation((string) $locked->disbursement_key, $locked->status->value);
            }

            /** @var Payout|null $payout */
            $payout = Payout::query()->lockForUpdate()->find((int) $locked->payout_id);

            $locked->status = PrizeDisbursementStatus::Reversed;
            $locked->reversed_at = now();
            $locked->reversal_reason = \Illuminate\Support\Str::limit(trim($reason), 255, '');
            $locked->save();

            if ($payout instanceof Payout) {
                $payout->status = PayoutStatus::Reversed;
                $payout->save();
            }

            $this->scribe->from(
                payout: $payout,
                act: 'reversed',
                amount: self::moneyOf((string) $locked->amount),
                fingerprint: (string) $locked->settlement_fingerprint,
                actorNote: sprintf('%s (%s)', $actorNote, $locked->reversal_reason),
                disbursementId: (int) $locked->getKey(),
            );

            return $locked;
        });
    }

    /**
     * Pronounce a row Failed — the attempt died upstream.
     *
     * @throws PrizeDisbursementException
     */
    public function pronounceFailed(PrizeDisbursement $disbursement, string $reason, string $actorNote = 'service'): PrizeDisbursement
    {
        return DB::transaction(function () use ($disbursement, $reason, $actorNote): PrizeDisbursement {
            /** @var PrizeDisbursement|null $locked */
            $locked = PrizeDisbursement::query()->lockForUpdate()->find((int) $disbursement->getKey());

            if (! $locked instanceof PrizeDisbursement) {
                throw PrizeDisbursementException::notFound((string) $disbursement->disbursement_key);
            }

            if ($locked->status === PrizeDisbursementStatus::Failed
                && \Illuminate\Support\Str::contains((string) $locked->failure_reason, $reason)) {
                return $locked;
            }

            if (!$locked->status->canTransitionTo(PrizeDisbursementStatus::Failed)) {
                throw PrizeDisbursementException::reversalViolation((string) $locked->disbursement_key, $locked->status->value);
            }

            $locked->status = PrizeDisbursementStatus::Failed;
            $locked->failed_at = now();
            $locked->failure_reason = \Illuminate\Support\Str::limit(trim($reason), 255, '');
            $locked->save();

            /** @var Payout|null $payout */
            $payout = Payout::query()->find((int) $locked->payout_id);

            $this->scribe->from(
                payout: $payout,
                act: 'failed',
                amount: self::moneyOf((string) $locked->amount),
                fingerprint: (string) $locked->settlement_fingerprint,
                actorNote: sprintf('%s (%s)', $actorNote, $locked->failure_reason),
                disbursementId: (int) $locked->getKey(),
            );

            return $locked;
        });
    }

    /* ------------------------------------------------- internals ----- */

    /**
     * Σ(live Reserved+Disbursed) for a payout — the held ledger, bcmath.
     */
    public function ledgersTotal(int $payoutId): string
    {
        $total = \Illuminate\Support\Facades\DB::selectOne(
            'SELECT COALESCE(SUM(amount), 0) AS total FROM prize_disbursements WHERE payout_id = ? AND status IN (?, ?)',
            [$payoutId, PrizeDisbursementStatus::Reserved->value, PrizeDisbursementStatus::Disbursed->value],
        );

        $raw = is_object($total) ? (string) ($total->total ?? '0') : (string) $total;

        return str_contains($raw, '.') ? rtrim(rtrim($raw, '0'), '.') === '' ? '0' : rtrim(rtrim($raw, '0'), '.') : $raw;
    }

    public static function moneyOf(string $amount): string
    {
        if (extension_loaded('bcmath')) {
            return bcadd($amount, '0', 2);
        }

        return number_format((float) $amount, 2, '.', '');
    }
}
