<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\DTOs\Betting\TicketVerificationResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The public shape of a ticket verification verdict.
 *
 * COARSE BY DEFAULT — owner money detail only appears when the verification ran
 * in the owner's authenticated scope AND the ticket is theirs; the service
 * attaches ownerDetail under exactly that condition and this resource forwards
 * the structure as-is.
 *
 * @mixin TicketVerificationResult
 */
final class TicketVerificationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var TicketVerificationResult $result */
        $result = $this->resource;

        return $result->toArray();
    }
}
