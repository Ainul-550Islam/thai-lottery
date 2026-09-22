<?php

declare(strict_types=1);

namespace App\Services\Ticket;

use App\DTOs\Ticket\TicketOwnershipData;
use App\Enums\AuditAction;
use App\Enums\RiskLevel;
use App\Enums\TicketOwnershipStatus;
use App\Exceptions\TicketOwnershipException;
use App\Models\AuditLog;
use App\Models\Ticket;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The IDENTITY-BINDING lane: one ticket, one lawful owner, at a time,
 * proven by a fingerprint that replays cannot drift.
 *
 * THE CORE RULES
 * --------------
 *
 *   OWNERSHIP IS THE ROW. tickets.user_id is the ownership fact; this lane
 *   carries the lifecycle (Active/Locked/Claimed/Expired) on
 *   tickets.metadata.ownership plus the derived fingerprint any dependent
 *   lane re-proves cheaply.
 *
 *   TRANSFER IS ILLEGAL. Nobody moves a ticket FROM one identity TO
 *   another by mutating the binding: user_id is never rewritten. The
 *   lawful change of lawful ownership — gift, court order, death —
 *   arrives as a NEW ROW (a fresh ticket binding issued under its own
 *   audit trail), and this lane tombstones the old one. Mutating
 *   ownership in place is exactly what this class refuses on every path.
 *
 *   DUPLICATE OWNERSHIP IS REFUSED, NOT MERGED. A binding exists and names
 *   a different fingerprint → thrown, never renamed. A binding exists and
 *   names the same fingerprint idempotently → the same stamp re-serves
 *   (replays from claim-flight retries and operator re-calls are normal).
 *
 *   CUSTODY TRUMPS OWNER GESTURES. A Locked ticket is in a flight's
 *   custody (a live prize claim cites it; a batch's halt will release it).
 *   While Locked: no bind attempts, no claim marks, no expiry marks —
 *   except the flight's own unlock/claimed writes, which pass the
 *   custodian name through.
 *
 * LIFECYCLE LIVES IN THE ENUM. Every mutation asserts canTransitionTo.
 */
class TicketOwnershipService
{
    /**
     * The tickets.metadata lane key.
     */
    public const METADATA_KEY = 'ownership';

    /**
     * Bind a ticket to its owner's identity.
     *
     * Precondition read from the row, never from the caller's assertion:
     * the ticket's own user_id IS the rightful owner. Bind does nothing
     * but stamp the proven fingerprint lane. A caller naming an owner
     * different from the row's is either an outsider attempting capture
     * or a bug; both are refused identically with identityMismatch.
     *
     * @return array<string, mixed>  The binding stamp (fingerprint included).
     */
    public function bind(Ticket $ticket, int $assertedOwnerUserId): array
    {
        if (DB::transactionLevel() > 0) {
            throw new TicketOwnershipException(
                'Ticket ownership bindings own their transaction boundary.',
                TicketOwnershipException::CODE_IN_CUSTODY,
                ['ticket_reference' => (string) $ticket->ticket_number],
            );
        }

        return DB::transaction(function () use ($ticket, $assertedOwnerUserId): array {
            /** @var Ticket|null $locked */
            $locked = Ticket::query()->lockForUpdate()->find((int) $ticket->getKey());

            if (! $locked instanceof Ticket) {
                throw TicketOwnershipException::identityMismatch(
                    (string) $ticket->ticket_number,
                    $assertedOwnerUserId,
                    ['detail' => 'row absent'],
                );
            }

            if ((int) $locked->user_id !== $assertedOwnerUserId) {
                throw TicketOwnershipException::identityMismatch(
                    (string) $locked->ticket_number,
                    $assertedOwnerUserId,
                );
            }

            $stamp = $this->stampFor($locked);

            // An existing binding: replay when fingerprint agrees; refuse
            // any second concurrent owner; custody/terminal always forbid.
            if (is_array($stamp)) {
                $status = TicketOwnershipStatus::tryFrom((string) ($stamp['status'] ?? ''));

                if ($status !== null && $status->isTerminal()) {
                    throw TicketOwnershipException::terminalBinding(
                        (string) $locked->ticket_number,
                        $status->value,
                    );
                }

                if ($status === TicketOwnershipStatus::Locked) {
                    throw TicketOwnershipException::inCustody(
                        (string) $locked->ticket_number,
                        (string) ($stamp['custodian'] ?? 'unknown'),
                    );
                }

                if (($stamp['owner_user_id'] ?? null) === $assertedOwnerUserId) {
                    return $stamp; // replay onto the same binding
                }

                throw TicketOwnershipException::duplicateBinding(
                    (string) $locked->ticket_number,
                    ['existing_owner_user_id' => (int) ($stamp['owner_user_id'] ?? 0)],
                );
            }

            $boundAt = $locked->issued_at instanceof Carbon
                ? $locked->issued_at->toIso8601String()
                : Carbon::now()->toIso8601String();

            $data = new TicketOwnershipData(
                ticketReference: (string) $locked->ticket_number,
                ownerUserId: $assertedOwnerUserId,
                boundAt: $boundAt,
                context: ['source' => 'ticket-ownership-service'],
            );

            $stamp = [
                'status' => TicketOwnershipStatus::Active->value,
                'owner_user_id' => $assertedOwnerUserId,
                'fingerprint' => $data->fingerprint(),
                'bound_at' => $boundAt,
                'custodian' => null,
                'history' => [
                    ['event' => 'bound', 'at' => Carbon::now()->toIso8601String()],
                ],
            ];

            $this->writeStamp($locked, $stamp);

            $this->recordAudit($locked, sprintf(
                'Ticket ownership bound to user #%d (fingerprint %s).',
                $assertedOwnerUserId,
                substr($data->fingerprint(), 0, 16).'…',
            ), RiskLevel::Medium);

            return $stamp;
        });
    }

    /**
     * Take a ticket into flight custody (a prize claim cites it).
     * The ONLY way into Locked.
     *
     * @return array<string, mixed>
     */
    public function lock(Ticket $ticket, string $custodian): array
    {
        return $this->mutateLifecycle($ticket, TicketOwnershipStatus::Locked, $custodian, 'flight custody taken');
    }

    /**
     * Release a ticket from custody back to Active (the flight folded
     * with the prize un-paid).
     *
     * @return array<string, mixed>
     */
    public function unlock(Ticket $ticket, string $custodian): array
    {
        return $this->mutateLifecycle($ticket, TicketOwnershipStatus::Active, $custodian, 'flight custody released');
    }

    /**
     * Mark the ticket's prize claimed — the money moved. Terminal.
     *
     * @return array<string, mixed>
     */
    public function markClaimed(Ticket $ticket, string $custodian): array
    {
        return $this->mutateLifecycle($ticket, TicketOwnershipStatus::Claimed, $custodian, 'prize claimed');
    }

    /**
     * Tombstone the binding: the claim window lapsed un-claimed. The
     * identity stays visible forever for audit; value stops carrying.
     *
     * @return array<string, mixed>
     */
    public function markExpired(Ticket $ticket, string $custodian): array
    {
        return $this->mutateLifecycle($ticket, TicketOwnershipStatus::Expired, $custodian, 'claim window lapsed');
    }

    /**
     * Claim lanes call this BEFORE asserting against a ticket: the
     * presenter must be the bound owner, and the fingerprint must re-prove.
     */
    public function assertOwnership(Ticket $ticket, int $presentedUserId, ?string $custodian = null): void
    {
        $stamp = $this->stampFor($ticket);

        if (! is_array($stamp)) {
            throw TicketOwnershipException::identityMismatch(
                (string) $ticket->ticket_number,
                $presentedUserId,
                ['detail' => 'no binding exists'],
            );
        }

        if ($custodian !== null && ($stamp['status'] ?? null) === TicketOwnershipStatus::Locked->value) {
            throw TicketOwnershipException::inCustody(
                (string) $ticket->ticket_number,
                $custodian,
            );
        }

        $status = TicketOwnershipStatus::tryFrom((string) ($stamp['status'] ?? ''));

        if ($status !== null && $status->isTerminal()) {
            throw TicketOwnershipException::terminalBinding(
                (string) $ticket->ticket_number,
                $status->value,
            );
        }

        if ((int) ($stamp['owner_user_id'] ?? 0) !== $presentedUserId) {
            throw TicketOwnershipException::identityMismatch(
                (string) $ticket->ticket_number,
                $presentedUserId,
            );
        }

        // The fingerprint re-derivation must reproduce the stamp:
        // drift here means the row's basis mutated under a stamped lane.
        $expected = TicketOwnershipData::deriveFingerprint(
            (string) $ticket->ticket_number,
            (int) ($stamp['owner_user_id'] ?? 0),
            (string) ($stamp['bound_at'] ?? ''),
        );

        if (! hash_equals((string) ($stamp['fingerprint'] ?? ''), $expected)) {
            throw TicketOwnershipException::anchorMismatch((string) $ticket->ticket_number);
        }
    }

    /**
     * Read: the current lifecycle status of the binding, if any.
     */
    public function statusFor(Ticket $ticket): ?TicketOwnershipStatus
    {
        $stamp = $this->stampFor($ticket);

        return is_array($stamp) ? TicketOwnershipStatus::tryFrom((string) ($stamp['status'] ?? '')) : null;
    }

    /**
     * Read: the binding stamp as stored.
     *
     * @return array<string, mixed>|null
     */
    public function stampFor(Ticket $ticket): ?array
    {
        $metadata = is_array($ticket->metadata) ? $ticket->metadata : [];
        $stamp = $metadata[self::METADATA_KEY] ?? null;

        return is_array($stamp) ? $stamp : null;
    }

    /**
     * One lawful ownership-lifecycle step: row lock, custody/terminal
     * guards, enum assertion, fingerprint-preserving history-augmented
     * write, audit.
     *
     * @return array<string, mixed>
     */
    private function mutateLifecycle(Ticket $ticket, TicketOwnershipStatus $target, string $custodian, string $note): array
    {
        return DB::transaction(function () use ($ticket, $target, $custodian, $note): array {
            /** @var Ticket|null $locked */
            $locked = Ticket::query()->lockForUpdate()->find((int) $ticket->getKey());

            if (! $locked instanceof Ticket) {
                throw TicketOwnershipException::terminalBinding(
                    (string) $ticket->ticket_number,
                    'missing',
                );
            }

            $stamp = $this->stampFor($locked);

            if (! is_array($stamp)) {
                throw TicketOwnershipException::identityMismatch(
                    (string) $locked->ticket_number,
                    0,
                    ['detail' => 'no binding exists'],
                );
            }

            $current = TicketOwnershipStatus::tryFrom((string) ($stamp['status'] ?? ''));

            if ($current === null) {
                throw TicketOwnershipException::anchorMismatch((string) $locked->ticket_number);
            }

            if ($current->isTerminal()) {
                throw TicketOwnershipException::terminalBinding(
                    (string) $locked->ticket_number,
                    $current->value,
                );
            }

            if (! $current->canTransitionTo($target)) {
                throw TicketOwnershipException::inCustody(
                    (string) $locked->ticket_number,
                    $custodian,
                    ['current_status' => $current->value, 'attempted_status' => $target->value],
                );
            }

            $stamp = array_merge($stamp, [
                'status' => $target->value,
                'custodian' => $target === TicketOwnershipStatus::Active ? null : $custodian,
            ]);

            $stamp['history'] = array_merge(
                is_array($stamp['history'] ?? null) ? $stamp['history'] : [],
                [[
                    'event' => sprintf('%s → %s', $current->value, $target->value),
                    'note' => $note,
                    'custodian' => $custodian,
                    'at' => Carbon::now()->toIso8601String(),
                ]],
            );

            $this->writeStamp($locked, $stamp);

            $this->recordAudit($locked, sprintf(
                'Ticket ownership %s → %s (%s) by %s.',
                $current->value,
                $target->value,
                $note,
                $custodian,
            ), $target->isTerminal() ? RiskLevel::High : RiskLevel::Medium);

            return $stamp;
        });
    }

    /**
     * @param  array<string, mixed>  $stamp
     */
    private function writeStamp(Ticket $ticket, array $stamp): void
    {
        $metadata = is_array($ticket->metadata) ? $ticket->metadata : [];
        $metadata[self::METADATA_KEY] = $stamp;

        $ticket->metadata = $metadata;
        $ticket->save();
    }

    private function recordAudit(Ticket $ticket, string $description, RiskLevel $riskLevel): void
    {
        $log = new AuditLog();

        $log->fill([
            'user_id' => null,
            'action' => AuditAction::Update,
            'risk_level' => $riskLevel,
            'auditable_type' => Ticket::class,
            'auditable_id' => $ticket->getKey(),
            'description' => sprintf('Ticket [%s]: %s', $ticket->ticket_number, $description),
            'metadata' => [
                'ticket_id' => (int) $ticket->getKey(),
                'ticket_reference' => (string) $ticket->ticket_number,
                'lane' => self::METADATA_KEY,
            ],
        ]);

        $log->save();
    }
}
