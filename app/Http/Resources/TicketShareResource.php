<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\DTOs\Betting\TicketShareResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The public shape of a created ticket share link.
 *
 * THE RAW TOKEN APPEARS EXACTLY ONCE — HERE
 * This resource is the single place the bearer token crosses the wire. It is
 * not stored, not logged, and a later "list my shares" endpoint (when one
 * exists) must serve share rows WITHOUT it. Treat the `token` field as
 * write-only memory: the client saves it or it is gone.
 *
 * @mixin TicketShareResult
 */
final class TicketShareResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var TicketShareResult $result */
        $result = $this->resource;

        return [
            'share_id' => (int) $result->share->getKey(),
            'share_uuid' => $result->share->uuid,
            'ticket_id' => (int) $result->share->ticket_id,
            'status' => $result->share->status->value,
            'token' => $result->rawToken,
            'url' => $result->shareUrl,
            'expires_at' => $result->expiresAt(),
            'created_at' => $result->share->created_at?->toIso8601String(),
        ];
    }
}
