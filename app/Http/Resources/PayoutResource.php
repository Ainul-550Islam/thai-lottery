<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Payout;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Sanitized public representation of a payout obligation.
 *
 * SANITIZATION CONTRACT
 * A payout carries several things that must NEVER cross the API boundary:
 * - internal ledger/financial-transaction identities,
 * - payment-gateway and provider references,
 * - batch executor internals, checksums, and the executor's claim tokens,
 * - fee breakdowns that disclose the house's economics,
 * - internal approval/claim metadata lanes in their raw shape.
 *
 * What the response keeps: the business reference number (the phrase the
 * player would quote to support), the status, the money TRIPLE a player can
 * legitimately know, the draw/ticket identifiers that make the payout
 * findable in the player's other views, and the lifecycle timestamps.
 *
 * SAFETY TECHNIQUE
 * This resource reads the model's own columns only — the metadata payloads
 * are reconstructed through a purpose-built allowlist, never spilled raw.
 * A `whenLoaded` is used for the draw relationship so the resource does not
 * force lazy loads behind the controller's back.
 *
 * @mixin Payout
 */
final class PayoutResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Payout $payout */
        $payout = $this->resource;

        $metadata = is_array($payout->metadata) ? $payout->metadata : [];

        // Surface only the two read-only, already-public facts stored in the
        // approval/claim lanes — everything else stays server-side. The
        // claim submission status and the review progress stamp are the two
        // phrases support would quote back to the player in the same words.
        $claimLane = is_array($metadata['claim'] ?? null) ? $metadata['claim'] : [];
        $approvalLane = is_array($metadata['approval'] ?? null) ? $metadata['approval'] : [];

        return [
            'reference_number' => (string) $payout->reference_number,
            'status' => $payout->status?->value,
            'amount' => (string) $payout->amount,
            'currency' => $payout->currency?->value,
            'draw_id' => $payout->draw_id !== null ? (int) $payout->draw_id : null,
            'bet_id' => $payout->bet_id !== null ? (int) $payout->bet_id : null,
            'ticket_id' => $payout->ticket_id !== null ? (int) $payout->ticket_id : null,
            'claim_status' => $this->stringOrNull($claimLane['status'] ?? null),
            'approval_status' => $this->stringOrNull($approvalLane['status'] ?? null),
            'processed_at' => $payout->processed_at?->toIso8601String(),
            'failed_at' => $payout->failed_at?->toIso8601String(),
            'created_at' => $payout->created_at?->toIso8601String(),
            'updated_at' => $payout->updated_at?->toIso8601String(),
            'draw' => new DrawResource($this->whenLoaded('draw')),
        ];
    }

    /**
     * A lane value, re-canonicalized to a plain string or collapsed to null.
     */
    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
