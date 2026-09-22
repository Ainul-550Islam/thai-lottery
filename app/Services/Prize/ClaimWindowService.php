<?php

declare(strict_types=1);

namespace App\Services\Prize;

use App\Enums\AuditAction;
use App\Enums\ClaimWindowStatus;
use App\Enums\RiskLevel;
use App\Exceptions\ClaimWindowExpiredException;
use App\Models\AuditLog;
use App\Models\Bet;
use App\Models\Payout;
use Illuminate\Support\Facades\DB;

/**
 * The writer and enforcer of prize CLAIM WINDOWS — the time slice in which a
 * winner may lawfully assert their prize.
 *
 * WHERE THE WINDOW LIVES
 * ----------------------
 * The window rides on the payout row, in metadata.claim_window, beside the
 * claim record itself: a prize window is born when somebody first touches the
 * prize (a claim submission registers Open), it ages purely by the clock,
 * and it is closed exactly once — by this service — either when the prize is
 * paid (Closed: nothing left to assert) or when the sweeper finds its
 * deadline passed (Closed: lapsed).
 *
 * HOW WINDOW STATE IS COMPUTED
 * ----------------------------
 * There is no "expired rows" query that requires manual refresh each second:
 * for a payout whose stored window status is Open, the CURRENT status is
 * derived status(SQL "Open" + window_closes_at ≤ now). The stored status is
 * only mutated on real transitions. CloseExpiredClaimWindowsJob walks this
 * derivation to stamp the transition exactly once — replay the same sweep
 * and nothing changes twice.
 *
 * WHY assertOpen THROWS
 * A claim against a closed/lapsed window is not a business rule failure the
 * caller decides how to render; the refusal is part of the sweep/window
 * contract, so it surfaces as ClaimWindowExpiredException from the window
 * service itself down to whoever (claim flow, validation flow) asked.
 */
class ClaimWindowService
{
    /**
     * The payout metadata key storing the window record.
     */
    public const METADATA_KEY = 'claim_window';

    /**
     * How many days after the win the window stays open. Config-backed
     * (lottery.claims.window_days); the GLO-parity default is 730.
     */
    public function windowDays(): int
    {
        $configured = config('lottery.claims.window_days');

        return is_numeric($configured) && (int) $configured >= 0
            ? (int) $configured
            : 730;
    }

    /**
     * Register an OPEN window on a payout, anchored at the bet's draw
     * completion day (falling back to won_at when draws predate that stamp).
     * Idempotent: registering where any window record exists leaves it alone
     * and returns the stored one verbatim — a prize never restarts its own
     * statute of limitations.
     *
     * @return array{status: string, window_closes_at: string, opened_at: string, closed_at: string|null}
     */
    public function register(Payout $payout, Bet $bet): array
    {
        $existing = $this->windowRecord($payout);

        if (is_array($existing)) {
            return [
                'status' => (string) $existing['status'],
                'window_closes_at' => (string) ($existing['window_closes_at'] ?? ''),
                'opened_at' => (string) ($existing['opened_at'] ?? ''),
                'closed_at' => isset($existing['closed_at']) && is_scalar($existing['closed_at']) ? (string) $existing['closed_at'] : null,
            ];
        }

        $anchor = $bet->draw?->completed_at ?? $bet->won_at;
        $closesAt = $anchor instanceof \Illuminate\Support\Carbon
            ? $anchor->copy()->addDays($this->windowDays())
            : now()->addDays($this->windowDays());

        $record = [
            'status' => ClaimWindowStatus::Open->value,
            'opened_at' => now()->toIso8601String(),
            'window_closes_at' => $closesAt->toIso8601String(),
            'closed_at' => null,
        ];

        $metadata = is_array($payout->metadata) ? $payout->metadata : [];
        $metadata[self::METADATA_KEY] = $record;

        $payout->metadata = $metadata;
        $payout->save();

        return $record;
    }

    /**
     * The CURRENT status of a payout's window: stored state collapsed against
     * the clock (Open-but-deadline-passed reads as Expired for every caller,
     * whether or not the sweep has stamped it yet).
     */
    public function statusFor(Payout $payout): ClaimWindowStatus
    {
        $record = $this->windowRecord($payout);

        if (! is_array($record)) {
            // No registered window yet means the prize has never been touched;
            // it presents as Open (claim flow will register it on first claim).
            return ClaimWindowStatus::Open;
        }

        $stored = is_string($record['status'] ?? null) ? $record['status'] : '';

        $status = ClaimWindowStatus::tryFrom($stored) ?? ClaimWindowStatus::Open;

        if ($status === ClaimWindowStatus::Open && $this->closesAtOf($record)->isPast()) {
            return ClaimWindowStatus::Expired;
        }

        return $status;
    }

    /**
     * Whether a claim may be submitted right now against this payout.
     */
    public function allowsClaim(Payout $payout): bool
    {
        return $this->statusFor($payout)->allowsClaim();
    }

    /**
     * The deadline timestamp, or null when no window exists.
     */
    public function closesAtFor(Payout $payout): ?\Illuminate\Support\Carbon
    {
        $record = $this->windowRecord($payout);

        return is_array($record) ? $this->closesAtOf($record) : null;
    }

    /**
     * HARD enforcement, per the summary above: a claim against a window that
     * is not Open-living is refused loudly, named by where it lives (expired
     * by time vs stamped closed already).
     *
     * @throws ClaimWindowExpiredException
     */
    public function assertOpen(Payout $payout, int $betId): void
    {
        $status = $this->statusFor($payout);

        if ($status === ClaimWindowStatus::Open) {
            return;
        }

        $record = $this->windowRecord($payout) ?? [];

        if ($status === ClaimWindowStatus::Closed && isset($record['closed_at']) && is_scalar($record['closed_at'])) {
            throw ClaimWindowExpiredException::alreadyClosed(
                $betId,
                (string) $record['closed_at'],
                ['payout_id' => (int) $payout->getKey()],
            );
        }

        throw ClaimWindowExpiredException::expired(
            $betId,
            (string) ($record['window_closes_at'] ?? ''),
            ['payout_id' => (int) $payout->getKey()],
        );
    }

    /**
     * Mark a payout's window CLOSED because the prize was paid inside it —
     * the winning road of closure. Called by the payout completion lane (or
     * the payout batch job once the payout completes), never by sweeping.
     *
     * @throws ClaimWindowExpiredException when the window was even pseudo--
     * closed already (a second payment closure is itself an anomaly).
     */
    public function closeAsPaid(Payout $payout): void
    {
        $status = $this->statusFor($payout);

        if ($status !== ClaimWindowStatus::Open) {
            $record = $this->windowRecord($payout) ?? [];

            if ($status === ClaimWindowStatus::Closed && isset($record['closed_at']) && is_scalar($record['closed_at'])) {
                throw ClaimWindowExpiredException::alreadyClosed(
                    $payout->bet_id !== null ? (int) $payout->bet_id : 0,
                    (string) $record['closed_at'],
                    ['payout_id' => (int) $payout->getKey()],
                );
            }

            throw ClaimWindowExpiredException::expired(
                $payout->bet_id !== null ? (int) $payout->bet_id : 0,
                (string) ($record['window_closes_at'] ?? ''),
                ['payout_id' => (int) $payout->getKey()],
            );
        }

        $stamp = $this->windowRecord($payout) ?? [];
        $stamp['status'] = ClaimWindowStatus::Closed->value;
        $stamp['closed_at'] = now()->toIso8601String();
        $stamp['closed_via'] = 'paid';

        $this->writeRecord($payout, $stamp);

        $this->recordAudit($payout, 'Window closed (prize paid within window)', RiskLevel::Low);
    }

    /**
     * The closing sweep: walk every registered window whose stored state is
     * Open and whose deadline has passed, stamp it Expired → (in the same
     * write) Closed — the ledger face-turn is one idempotent mutation. The
     * two-step-into-one write is what makes a second sweep a no-op for the
     * same rows.
     *
     * @return array{closed_count: int, total_lapsed_amount: string}
     */
    public function closeExpired(?int $chunkSize = null): array
    {
        $chunkSize ??= 200;
        $now = now()->toIso8601String();

        $closed = 0;
        $total = '0.00';

        Payout::query()
            ->whereNotNull('metadata')
            ->whereNull('deleted_at')
            ->whereRaw("json_extract(metadata, '$.claim_window.status') = ?", [ClaimWindowStatus::Open->value])
            ->whereRaw("json_extract(metadata, '$.claim_window.window_closes_at') < ?", [$now])
            ->orderBy('id')
            ->chunkById($chunkSize, function ($payouts) use (&$closed, &$total): bool {
                foreach ($payouts as $payout) {
                    $record = $this->windowRecord($payout) ?? [];

                    $record['status'] = ClaimWindowStatus::Closed->value;
                    $record['closed_at'] = now()->toIso8601String();
                    $record['closed_via'] = 'expired';

                    $this->writeRecord($payout, $record);

                    $closed++;
                    $total = bcadd($total, (string) $payout->amount, 2);

                    $this->recordAudit($payout, sprintf(
                        'Window closed: deadline %s passed with no payment',
                        (string) ($record['window_closes_at'] ?? ''),
                    ), RiskLevel::High);
                }

                return true;
            });

        return [
            'closed_count' => $closed,
            'total_lapsed_amount' => $total,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function windowRecord(Payout $payout): ?array
    {
        $metadata = is_array($payout->metadata) ? $payout->metadata : [];
        $record = $metadata[self::METADATA_KEY] ?? null;

        return is_array($record) ? $record : null;
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function closesAtOf(array $record): \Illuminate\Support\Carbon
    {
        $raw = $record['window_closes_at'] ?? null;

        if (is_string($raw) && $raw !== '') {
            return \Illuminate\Support\Carbon::parse($raw);
        }

        // No stored edge: the window never closes (should not happen for a
        // properly registered record — register() always pins an edge).
        return now()->addYears(100);
    }

    /**
     * @param  array<string, mixed>  $stamp
     */
    private function writeRecord(Payout $payout, array $stamp): void
    {
        $metadata = is_array($payout->metadata) ? $payout->metadata : [];
        $metadata[self::METADATA_KEY] = $stamp;

        $payout->metadata = $metadata;
        $payout->save();
    }

    private function recordAudit(Payout $payout, string $description, RiskLevel $riskLevel): void
    {
        $log = new AuditLog();

        $log->fill([
            'user_id' => null,
            'action' => AuditAction::Update,
            'risk_level' => $riskLevel,
            'auditable_type' => Payout::class,
            'auditable_id' => $payout->getKey(),
            'description' => sprintf(
                'Prize claim window %s on payout #%d (%s %s)',
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
                'action' => 'claim_window_closed',
            ],
        ]);

        $log->save();
    }
}
