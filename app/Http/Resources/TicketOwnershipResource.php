<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Ticket;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Safe identity-bound ticket representation.
 *
 * THE AUTHORIZED-PRINCIPAL-ONLY RULE
 * This resource is reached exclusively from ownership queries the
 * controller has already authorized (the ticket's owner, or an operator in
 * the custody lane). The output consequently may show the binding,
 * custody state, lifecycle stamps and the draw/money anchors — the paper a
 * player needs to prove what they hold — without ever disclosing ANOTHER
 * principal's holdings.
 *
 * WHAT IT NEVER CARRIES
 * The raw fingerprint's hash material, the verification lane internals of
 * the ticket row, operator audit notes about availability scans, and any
 * serialized metadata that would let a hostile caller replay the binding
 * protocol. The fingerprint exposure is limited to its presence/absence
 * marker (so the UI can show "bound — verified" without revealing the
 * verification substrate).
 *
 * @mixin Ticket
 */
final class TicketOwnershipResource extends JsonResource
{
    public function __construct($resource, private readonly ?array $stamp = null)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Ticket $ticket */
        $ticket = $this->resource;

        $metadata = is_array($ticket->metadata) ? $ticket->metadata : [];
        $lane = is_array($this->stamp) ? $this->stamp : (is_array($metadata['ownership'] ?? null) ? $metadata['ownership'] : []);

        // The lane's own lifecycle stamps: history is an array of events,
        // newest last; the tail is the only one the screen needs ("as of
        // when does this custody state hold").
        $history = is_array($lane['history'] ?? null) ? $lane['history'] : [];
        $latest = $history === [] ? null : $history[array_key_last($history)];

        return [
            'id' => (int) $ticket->getKey(),
            'ticket_number' => (string) $ticket->ticket_number,
            'draw_id' => $ticket->draw_id !== null ? (int) $ticket->draw_id : null,
            'status' => $ticket->status?->value,
            'currency' => $ticket->currency?->value,
            'total_amount' => (string) $ticket->total_amount,
            'ownership_status' => isset($lane['status']) ? (string) $lane['status'] : null,
            'owner_user_id' => isset($lane['owner_user_id']) ? (int) $lane['owner_user_id'] : null,
            'bound_at' => isset($lane['bound_at']) ? (string) $lane['bound_at'] : null,
            'custodian' => isset($lane['custodian']) && is_string($lane['custodian']) ? (string) $lane['custodian'] : null,
            'latest_event_at' => is_array($latest) && isset($latest['at']) ? (string) $latest['at'] : null,
            'fingerprint_present' => isset($lane['fingerprint']) && is_string($lane['fingerprint']) && $lane['fingerprint'] !== '',
        ];
    }
}
