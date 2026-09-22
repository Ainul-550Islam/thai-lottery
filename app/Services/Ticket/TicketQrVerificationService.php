<?php

declare(strict_types=1);

namespace App\Services\Ticket;

use App\DTOs\Ticket\TicketQrVerificationData;
use App\Enums\AuditAction;
use App\Enums\RiskLevel;
use App\Enums\TicketVerificationStatus;
use App\Exceptions\TicketQrVerificationException;
use App\Models\AuditLog;
use App\Models\Ticket;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Verifies signed ticket QR payloads.
 *
 * THE FOUR QUESTIONS THIS SERVICE ANSWERS (in asked order):
 *   1. GRAMMAR  — does the payload even parse past its own format
 *      (enforced upstream by TicketQrVerificationData; here we gate on
 *      the ticket existing).
 *   2. SIGNATURE — re-derive the HMAC from OUR OWN corroborated draw-
 *      lane facts + the payload's nonce; if the presented fingerprint
 *      isn't hash_equals-equal, the document is not our document.
 *   3. STATE    — what the ticket ROW says: voided/expired/cancelled
 *      paper answers with benign verdicts; only a live ticket may
 *      verify.
 *   4. REPLAY   — the nonce-lane anchor inside the ticket's own
 *      metadata records exactly which nonces this ticket has consumed;
 *      the second sight of the same credential is refused there.
 *
 * WHAT IT DELIBERATELY NEVER DOES
 * It never consults or duplicates TicketOwnershipService — the ownership
 * lane binds tickets to principals; this lane binds QRs to the ticket.
 * The two stay orthogonal: a verification verdict here proves nothing
 * about ownership (the ownership service judges that through its own
 * fingerprint); an ownership claim makes no statement about a QR's
 * currency.
 *
 * ALL ADVERSARIAL REFUSALS ARE EXCEPTIONS, ALL BENIGN NEGATIVES ARE
 * VERDICTS: a scanner attendant needs distinct vocabulary for "the paper
 * died" versus "the paper is forged".
 */
final class TicketQrVerificationService
{
    /**
     * Lane anchor: tickets.metadata['qr']['nonces'][sha256(nonce)] =
     * verification anchor {at, anchor}.
     */
    public const METADATA_KEY = 'qr';

    /**
     * Sign the QR payload. Keyed by the application's own secret:
     * HMAC is exactly "a shared-key checksum", which is the right shape
     * for a code that only ever needs "did the house write this".
     */
    public static function expectedFingerprint(
        TicketQrVerificationData $data,
        int $drawId,
        ?int $ticketProductId,
        string $appKey,
    ): string {
        return hash_hmac(
            'sha256',
            $data->signingInput($drawId, $ticketProductId),
            $appKey,
        );
    }

    /**
     * The whole adjudication.
     *
     * @return array{verdict: TicketVerificationStatus, ticket: ?Ticket, ticket_reference: string, verified_at: string, anchor: ?string}
     *
     * @throws TicketQrVerificationException on adversarial grounds only.
     */
    public function verify(TicketQrVerificationData $data): array
    {
        return DB::transaction(function () use ($data): array {
            /** @var Ticket|null $locked */
            $locked = Ticket::query()
                ->lockForUpdate()
                ->where('ticket_number', $data->ticketReference)
                ->first();

            if (! $locked instanceof Ticket) {
                throw TicketQrVerificationException::notFound($data->ticketReference);
            }

            // The draw the ticket actually plays against is a ledger fact —
            // the payload's claimed draw id is corroborated against it, and
            // U never trusted to speech.
            $drawId = (int) $locked->draw_id;

            if ($data->drawId !== null && $data->drawId !== $drawId) {
                throw TicketQrVerificationException::invalidSignature(
                    $data->ticketReference,
                );
            }

            // The product lane context, when the ticket's metadata carries
            // one, must agree with the payload's declared product as well.
            $metadata = is_array($locked->metadata) ? $locked->metadata : [];
            $storedProductId = is_array($metadata['product'] ?? null)
                ? (int) ($metadata['product']['ticket_product_id'] ?? 0)
                : null;

            if ($data->ticketProductId !== null
                && $storedProductId !== null
                && $data->ticketProductId !== $storedProductId) {
                throw TicketQrVerificationException::invalidSignature(
                    $data->ticketReference,
                );
            }

            $productId = $data->ticketProductId ?? $storedProductId;

            // SIGNATURE: re-derive from OUR facts, compare by hash_equals.
            $expected = self::expectedFingerprint(
                $data,
                $drawId,
                $productId,
                (string) config('app.key'),
            );

            if (! hash_equals($expected, $data->fingerprint)) {
                throw TicketQrVerificationException::invalidSignature(
                    $data->ticketReference,
                );
            }

            // EXPIRY: only credentials already PROVEN to be ours reach this
            // question — a forged document can't enumerate anything by
            // asking "was this one still alive". An expired QR refuses as
            // its own pronounced CODE_EXPIRED ground, outranks the replay
            // question (time-travel beats context-travel) and consumes
            // no nonce on its way out.
            if ($data->expiresAt !== null
                && Carbon::now()->greaterThanOrEqualTo(Carbon::parse($data->expiresAt))) {
                throw TicketQrVerificationException::expired(
                    $data->ticketReference,
                    $data->expiresAt,
                );
            }

            // Replay adjudication BEFORE any state verdict: an already-
            // consumed credential is refused even when the ticket itself
            // has since died — the refusal belongs to the credential, not
            // the paper.
            $nonceLane = $metadata[self::METADATA_KEY] ?? null;
            $nonceKey = hash('sha256', $data->nonce);

            if (is_array($nonceLane) && is_array($nonceLane['nonces'] ?? null)
                && array_key_exists($nonceKey, (array) $nonceLane['nonces'])) {
                throw TicketQrVerificationException::alreadyUsed($data->ticketReference);
            }

            // STATE: benign negatives answer as verdicts, never exceptions.
            $ticketStatus = $locked->status;
            $verdict = $ticketStatus !== null ? TicketVerificationStatus::fromTicketStatus($ticketStatus) : TicketVerificationStatus::Invalid;

            if ($verdict === TicketVerificationStatus::Cancelled
                || $verdict === TicketVerificationStatus::Expired) {
                return [
                    'verdict' => $verdict,
                    'ticket' => $locked,
                    'ticket_reference' => $data->ticketReference,
                    'verified_at' => Carbon::now()->toIso8601String(),
                    'anchor' => null,
                ];
            }

            // OWNERSHIP corroboration, when a caller presents a principal:
            // the presenting user must BE the ticket's ledger owner.
            $presentedUserId = $data->context['presented_user_id'] ?? null;

            if (is_int($presentedUserId) && (int) $locked->user_id !== $presentedUserId) {
                throw TicketQrVerificationException::ownershipMismatch($data->ticketReference);
            }

            // ALL GREEN: mark the nonce consumed, anchored to the verified
            // moment; write one audit row; answer.
            $anchor = hash('sha256', sprintf('qr-used:%s:%s', $data->ticketReference, $data->nonce));

            $lane = is_array($nonceLane) ? $nonceLane : ['nonces' => []];
            $lane['nonces'][$nonceKey] = [
                'anchor' => $anchor,
                'at' => Carbon::now()->toIso8601String(),
            ];
            $metadata[self::METADATA_KEY] = $lane;
            $locked->metadata = $metadata;
            $locked->save();

            $this->recordAudit($locked, sprintf(
                'QR verified; verdict %s; anchor %s...',
                $verdict->value,
                substr($anchor, 0, 12),
            ), RiskLevel::Low);

            return [
                'verdict' => $verdict,
                'ticket' => $locked,
                'ticket_reference' => $data->ticketReference,
                'verified_at' => Carbon::now()->toIso8601String(),
                'anchor' => $anchor,
            ];
        });
    }

    /**
     * Generate a signed QR payload for a ticket (writes nothing — it is an
     * attestation; the attentive boundary keeps it separate from
     * verification so a minted QR never consumes anything itself).
     *
     * @return array{ticket_reference: string, fingerprint: string, nonce: string, draw_id: int, ticket_product_id: ?int, expires_at: ?string}
     */
    public function attest(Ticket $ticket, ?string $nonceOverride = null, ?string $expiresAtOverride = null): array
    {
        $nonce = $nonceOverride ?? bin2hex(random_bytes(16));

        $drawId = (int) $ticket->draw_id;
        $metadata = is_array($ticket->metadata) ? $ticket->metadata : [];
        $productId = is_array($metadata['product'] ?? null)
            ? ((int) ($metadata['product']['ticket_product_id'] ?? 0) ?: null)
            : null;

        $data = TicketQrVerificationData::fromPayload(
            ticketReference: (string) $ticket->ticket_number,
            fingerprint: str_repeat('0', 64), // placeholder; the real signature is derived below
            nonce: $nonce,
            drawId: $drawId,
            ticketProductId: $productId,
            expiresAt: $expiresAtOverride,
        );

        $fingerprint = self::expectedFingerprint(
            $data,
            $drawId,
            $productId,
            (string) config('app.key'),
        );

        return [
            'ticket_reference' => $data->ticketReference,
            'fingerprint' => $fingerprint,
            'nonce' => $data->nonce,
            'draw_id' => $drawId,
            'ticket_product_id' => $productId,
            'expires_at' => $data->expiresAt,
        ];
    }

    /**
     * One scrubbed audit row per consumption decision; adversarial grounds
     * are already pronounced as exceptions upstream of this method and
     * logged at Log::warning instead (never silent).
     */
    private function recordAudit(Ticket $ticket, string $description, RiskLevel $riskLevel): void
    {
        $log = new AuditLog();

        $log->fill([
            'user_id' => null,
            'action' => AuditAction::Update,
            'risk_level' => $riskLevel,
            'auditable_type' => Ticket::class,
            'auditable_id' => (int) $ticket->getKey(),
            'description' => $description,
            'metadata' => [
                'ticket_id' => (int) $ticket->getKey(),
                'ticket_number' => (string) $ticket->ticket_number,
                'lane' => self::METADATA_KEY,
            ],
        ]);

        $log->save();
    }
}
