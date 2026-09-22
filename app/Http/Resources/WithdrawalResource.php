<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Withdrawal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Safe public representation of a withdrawal.
 *
 * WHAT THIS RESOURCE EXPOSES (player-facing, by design)
 * The identifiers the player can quote, the permanent lifecycle stamps, and
 * the amnesty case of every money figure the player agreed to when they made
 * the request.
 *
 * WHAT IT HIDES — AND WHY
 * - `provider` / `provider_reference`: the payment-gateway identity the house
 *   holds on this withdrawal. Leaking it discloses internal provider topology
 *   and would let bad actors correlate channel usage; it only exists for the
 *   operator console.
 * - Internal KYC-gate verdict internals (transaction anchor fragments,
 *   evidence document IDs): the output shape exposes only the
 *   player-legible DISTILLATE — whether a KYC detention is in effect and
 *   when it started. Everything else stays in the metadata lane, where the
 *   WithdrawalKycGateService already keeps it on the player's behalf.
 *
 * @mixin Withdrawal
 */
final class WithdrawalResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Withdrawal $withdrawal */
        $withdrawal = $this->resource;

        $metadata = is_array($withdrawal->metadata) ? $withdrawal->metadata : [];
        $kycLane = is_array($metadata['kyc_gate'] ?? null) ? $metadata['kyc_gate'] : [];

        return [
            'id' => (int) $withdrawal->getKey(),
            'uuid' => $withdrawal->uuid,
            'reference_number' => (string) $withdrawal->reference_number,
            'status' => $withdrawal->status?->value,
            'amount' => (string) $withdrawal->amount,
            'fee' => (string) $withdrawal->fee,
            'net_amount' => (string) $withdrawal->net_amount,
            'currency' => $withdrawal->currency?->value,
            'method' => $withdrawal->method?->value,
            'requested_at' => $withdrawal->requested_at?->toIso8601String(),
            'approved_at' => $withdrawal->approved_at?->toIso8601String(),
            'completed_at' => $withdrawal->completed_at?->toIso8601String(),
            'rejected_at' => $withdrawal->rejected_at?->toIso8601String(),
            'rejection_reason' => $withdrawal->rejection_reason,
            'kyc_detained' => (bool) ($kycLane['detained'] ?? false),
            'kyc_detained_reason' => ((bool) ($kycLane['detained'] ?? false))
                ? (is_string($kycLane['detention_reason'] ?? null) ? (string) $kycLane['detention_reason'] : null)
                : null,
            'created_at' => $withdrawal->created_at?->toIso8601String(),
        ];
    }
}
