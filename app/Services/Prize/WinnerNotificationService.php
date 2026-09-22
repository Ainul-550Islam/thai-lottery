<?php

declare(strict_types=1);

namespace App\Services\Prize;

use App\DTOs\Prize\WinnerNotificationData;
use App\Enums\AuditAction;
use App\Enums\RiskLevel;
use App\Enums\WinnerNotificationStatus;
use App\Exceptions\WinnerNotificationException;
use App\Models\AuditLog;
use App\Models\Payout;
use App\Models\WinnerNotification;
use Illuminate\Support\Facades\DB;

/**
 * Winner notifications: deterministic dedupe, honest delivery, scrubbed
 * metadata.
 *
 * THE INVARIANTS
 *   1. AUTHORITY — the claimant is derived from the payout row, never
 *      from the payload. A caller can only ever name WHICH payout.
 *   2. DEDUPE — one row per (claimant, payout, channel, dedupe-key);
 *      the same ask re-serves (replay), and nothing ever double-sends.
 *   3. VOICE — send is an ACT, subject to the state machine; Sent and
 *      Acknowledged rows admit no further dispatch attempts (the
 *      duplicate-suppression window is strictly closed by them).
 *   4. HYGIENE — address lines never touch this system's audit rows;
 *      so a winner's details stay where the account holds them.
 *
 * Transport today is the log lane (the queue driver). The lane stays
 * honest: provider fact patterns live in metadata as 'transport:log'.
 */
final class WinnerNotificationService
{
    /* ---------------------------------------------------- queue ----- */

    /**
     * @return array{notification: WinnerNotification, replayed: bool}
     *
     * @throws WinnerNotificationException
     */
    public function queue(WinnerNotificationData $data): array
    {
        if (DB::transactionLevel() > 0) {
            return $this->queueWithin($data);
        }

        return DB::transaction(fn (): array => $this->queueWithin($data));
    }

    /**
     * @return array{notification: WinnerNotification, replayed: bool}
     *
     * @throws WinnerNotificationException
     */
    private function queueWithin(WinnerNotificationData $data): array
    {
        /** @var Payout|null $payout */
        $payout = Payout::query()->lockForUpdate()->find($data->payoutId);

        if (! $payout instanceof Payout) {
            throw WinnerNotificationException::notFound('payout:'.$data->payoutId);
        }

        $claimantId = (int) $payout->user_id;

        if ($claimantId < 1) {
            throw WinnerNotificationException::invalidRecipient($data->payoutId);
        }

        // THE SECURITY RULE: the address belongs to the account, not the
        // request. This service never writes it down, never reads it.
        $principal = \App\Models\User::query()->find($claimantId);

        if (! $principal) {
            throw WinnerNotificationException::invalidRecipient($data->payoutId);
        }

        $notificationKey = $data->notificationKey($claimantId);

        $existing = WinnerNotification::query()
            ->lockForUpdate()
            ->where('notification_key', $notificationKey)
            ->first();

        if ($existing instanceof WinnerNotification) {
            return ['notification' => $existing, 'replayed' => true];
        }

        $row = new WinnerNotification();
        $row->fill([
            'notification_key' => $notificationKey,
            'payout_id' => (int) $payout->getKey(),
            'user_id' => $claimantId,
            'channel' => $data->channel,
            'draw_reference' => $data->drawReference,
            'result_version' => $data->resultVersion,
            'dedupe_key' => $data->dedupeKey,
            'queued_at' => now(),
            'metadata' => ['transport' => 'log'],
        ]);
        $row->status = WinnerNotificationStatus::Queued;
        $row->save();

        $this->recordAudit($row, sprintf(
            'Queued [%s] notification against payout #%d (draw [%s] v%d)',
            $data->channel,
            (int) $payout->getKey(),
            $data->drawReference,
            $data->resultVersion,
        ), RiskLevel::Medium);

        return ['notification' => $row, 'replayed' => false];
    }

    /* ----------------------------------------------------- send ----- */

    /**
     * Attempt dispatch of a Queued row — honest about refusal, never a
     * second send behind a Sent lane.
     *
     * @return array{notification: WinnerNotification, sent: bool}
     *
     * @throws WinnerNotificationException
     */
    public function dispatch(WinnerNotification $notification): array
    {
        return DB::transaction(function () use ($notification): array {
            /** @var WinnerNotification|null $locked */
            $locked = WinnerNotification::query()->lockForUpdate()->find((int) $notification->getKey());

            if (! $locked instanceof WinnerNotification) {
                throw WinnerNotificationException::notFound((string) $notification->notification_key);
            }

            if ($locked->status === WinnerNotificationStatus::Sent
                || $locked->status === WinnerNotificationStatus::Acknowledged) {
                // The duplicate-suppression window is CLOSED. Refuse.
                throw WinnerNotificationException::duplicate((string) $locked->notification_key);
            }

            if (!$locked->status->canTransitionTo(WinnerNotificationStatus::Sent)) {
                throw WinnerNotificationException::notFound((string) $locked->notification_key.' @ '.$locked->status->value);
            }

            $locked->status = WinnerNotificationStatus::Sent;
            $locked->sent_at = now();
            $locked->attempt_count = (int) $locked->attempt_count + 1;
            $locked->save();

            $this->recordAudit($locked, sprintf('Dispatched via [%s] (attempt %d)', $locked->channel, (int) $locked->attempt_count), RiskLevel::Low);

            return ['notification' => $locked, 'sent' => true];
        });
    }

    /**
     * Pronounce a dispatch attempt failed (provider refused/timed out).
     *
     * @throws WinnerNotificationException
     */
    public function pronounceFailed(WinnerNotification $notification, string $reason): WinnerNotification
    {
        return DB::transaction(function () use ($notification, $reason): WinnerNotification {
            /** @var WinnerNotification|null $locked */
            $locked = WinnerNotification::query()->lockForUpdate()->find((int) $notification->getKey());

            if (! $locked instanceof WinnerNotification) {
                throw WinnerNotificationException::notFound((string) $notification->notification_key);
            }

            if ($locked->status === WinnerNotificationStatus::Failed
                && \Illuminate\Support\Str::contains((string) $locked->failure_reason, $reason)) {
                return $locked; // same failure, pronounced once
            }

            if (!$locked->status->canTransitionTo(WinnerNotificationStatus::Failed)) {
                throw WinnerNotificationException::providerRejected($locked->channel, 'state '.$locked->status->value.' admits no failure');
            }

            $locked->status = WinnerNotificationStatus::Failed;
            $locked->failed_at = now();
            $locked->failure_reason = \Illuminate\Support\Str::limit(trim($reason), 255, '');
            $locked->save();

            $this->recordAudit($locked, sprintf('Delivery failed (%s)', $locked->failure_reason), RiskLevel::Medium);

            return $locked;
        });
    }

    /**
     * The claimant touched the prize surface — pronounce.
     *
     * @throws WinnerNotificationException
     */
    public function acknowledge(WinnerNotification $notification): WinnerNotification
    {
        return DB::transaction(function () use ($notification): WinnerNotification {
            /** @var WinnerNotification|null $locked */
            $locked = WinnerNotification::query()->lockForUpdate()->find((int) $notification->getKey());

            if (! $locked instanceof WinnerNotification) {
                throw WinnerNotificationException::notFound((string) $notification->notification_key);
            }

            if ($locked->status === WinnerNotificationStatus::Acknowledged) {
                return $locked;
            }

            if (!$locked->status->canTransitionTo(WinnerNotificationStatus::Acknowledged)) {
                throw WinnerNotificationException::notFound((string) $locked->notification_key.' @ '.$locked->status->value);
            }

            $locked->status = WinnerNotificationStatus::Acknowledged;
            $locked->acknowledged_at = now();
            $locked->save();

            $this->recordAudit($locked, 'Acknowledged by the claimant', RiskLevel::Low);

            return $locked;
        });
    }

    /* ------------------------------------------------ pending ------- */

    /**
     * Queued rows owed a dispatch attempt (job helper — status-filtered).
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, WinnerNotification>
     */
    public function pendingDispatch(int $limit = 100): iterable
    {
        return WinnerNotification::query()
            ->where('status', WinnerNotificationStatus::Queued->value)
            ->where('queued_at', '<=', now())
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    /* --------------------------------------------------- internals --- */

    private function recordAudit(WinnerNotification $notification, string $description, RiskLevel $riskLevel): void
    {
        $log = new AuditLog();

        $log->fill([
            'user_id' => null,
            'action' => AuditAction::Update,
            'risk_level' => $riskLevel,
            'auditable_type' => WinnerNotification::class,
            'auditable_id' => (int) $notification->getKey(),
            'description' => sprintf('%s (notif %s...)' , $description, substr((string) $notification->notification_key, 0, 12)),
            'metadata' => [
                'notification_key' => substr((string) $notification->notification_key, 0, 12),
                'payout_id' => (int) $notification->payout_id,
                'user_id' => (int) $notification->user_id,
                'channel' => (string) $notification->channel,
                'draw_reference' => (string) $notification->draw_reference,
                'result_version' => (int) $notification->result_version,
                'lane' => 'winner-notification',
            ],
        ]);

        $log->save();
    }
}
