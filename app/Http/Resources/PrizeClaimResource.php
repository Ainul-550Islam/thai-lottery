<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Payout;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Claim status / eligibility / result representation.
 *
 * SANITIZATION CONTRACT
 * The claim lane stores a few things that must never cross this boundary in
 * raw shape:
 * - the internal review completion stamps and the reviewer roster
 *   (which operator saw this, when — that is the court's business);
 * - audit-trail internals and failed-rule diagnostics (the enumeration of
 *   exactly which fraud rule fired is operational-security material);
 * - raw payouts-bet linkage internals beyond the business id print.
 *
 * What the response keeps: the three identifiers the player quotes (bet id,
 * payout reference where minted, payout id), the lifecycle stamps of the
 * claim itself, the money triple the player may know, and the window.
 *
 * This resource accepts TWO shapes of backing record: a payout row with a
 * claim lane on it (the canonical stored record), or a bare service RESULT
 * array (the composition PrizeClaimService returns). Both route through the
 * same output keys so screens never branch on representation.
 *
 * @mixin Payout
 */
final class PrizeClaimResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $record = $this->resource;

        // Service-result composition path: already a plain array keyed on
        // the service's resultRow fields.
        if (is_array($record)) {
            return [
                'bet_id' => (int) ($record['bet_id'] ?? 0),
                'payout_id' => isset($record['payout_id']) ? (int) $record['payout_id'] : null,
                'status' => isset($record['status']) ? (string) $record['status'] : null,
                'amount' => isset($record['amount']) ? (string) $record['amount'] : null,
                'currency' => isset($record['currency']) ? (string) $record['currency'] : null,
                'window_closes_at' => isset($record['window_closes_at']) ? (string) $record['window_closes_at'] : null,
                'claimed_at' => isset($record['claimed_at']) ? (string) $record['claimed_at'] : null,
                'no_op' => (bool) ($record['no_op'] ?? false),
                'payout_reference' => isset($record['payout_reference']) ? (string) $record['payout_reference'] : null,
                'payout_status' => isset($record['payout_status']) ? (string) $record['payout_status'] : null,
                'window_open' => isset($record['window_open']) ? (bool) $record['window_open'] : null,
            ];
        }

        /** @var Payout $payout */
        $payout = $record;

        $metadata = is_array($payout->metadata) ? $payout->metadata : [];
        $claim = is_array($metadata['claim'] ?? null) ? $metadata['claim'] : [];
        $window = is_array($metadata['claim_window'] ?? null) ? $metadata['claim_window'] : [];

        return [
            'bet_id' => $payout->bet_id !== null ? (int) $payout->bet_id : null,
            'payout_id' => (int) $payout->getKey(),
            'status' => isset($claim['status']) ? (string) $claim['status'] : null,
            'amount' => (string) $payout->amount,
            'currency' => $payout->currency?->value,
            'window_closes_at' => isset($claim['window_closes_at'])
                ? (string) $claim['window_closes_at']
                : (isset($window['closes_at']) ? (string) $window['closes_at'] : null),
            'claimed_at' => isset($claim['claimed_at']) ? (string) $claim['claimed_at'] : null,
            'no_op' => false,
            'payout_reference' => (string) $payout->reference_number,
            'payout_status' => $payout->status?->value,
            'window_open' => isset($window['status'])
                ? ((string) $window['status'] === 'open')
                : null,
        ];
    }
}
