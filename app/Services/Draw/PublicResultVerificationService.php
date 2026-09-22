<?php

declare(strict_types=1);

namespace App\Services\Draw;

use App\DTOs\Draw\ResultVerificationData;
use App\Models\Draw;

/**
 * The public's answerning voice on result authenticity.
 *
 * WHAT IT ANSWERS (and what it never does)
 * Given a caller's draw reference + their claimed result fingerprint, it
 * consults the BOARD (the draw_publications lane) and pronounces one of:
 *
 *   - 'VERIFIED'  the claimed fingerprint IS the live publication's own.
 *   - 'STALE'     it matches an older (retracted/superseded) board row —
 *                 real once, no longer the board's answer.
 *   - 'UNKNOWN'   neither draw nor board carries that fingerprint.
 *
 * Two principles make this a PUBLIC surface:
 *   - it never throws for bad facts (a reader who cannot trust the paper
 *     is told precisely how untrusted it is, by vocabulary);
 *   - it never writes (read-only by reservation — the nonce is caller
 *     grammar, not ledger state).
 *
 * The acceptance test is hash_equals against everything the board knows:
 * constant-time against crafted equality games, honest in both directions.
 */
final class PublicResultVerificationService
{
    public function __construct(
        private readonly DrawPublicationService $publications,
    ) {
    }

    /**
     * @return array{verdict: string, draw_reference: string, live_fingerprint: ?string, published_at: ?string, verified_at: string, status: ?string}
     */
    public function verify(ResultVerificationData $data): array
    {
        // A verification of the PRESENT only embeds the past: answer
        // straight from the board, with strike-through known history.
        /** @var Draw|null $draw */
        $draw = Draw::query()
            ->where('draw_number', $data->drawReference)
            ->first();

        if (! $draw instanceof Draw) {
            return [
                'verdict' => 'UNKNOWN',
                'draw_reference' => $data->drawReference,
                'live_fingerprint' => null,
                'published_at' => null,
                'verified_at' => now()->toIso8601String(),
                'status' => null,
            ];
        }

        $drawId = (int) $draw->getKey();

        $live = $this->publications->liveFor($drawId);

        if ($live !== null && hash_equals((string) $live->result_fingerprint, $data->resultFingerprint)) {
            return [
                'verdict' => 'VERIFIED',
                'draw_reference' => $data->drawReference,
                'live_fingerprint' => (string) $live->result_fingerprint,
                'published_at' => $live->published_at?->toIso8601String(),
                'verified_at' => now()->toIso8601String(),
                'status' => $live->status->value,
            ];
        }

        // Not the live answer — but it may be HISTORY: a real fingerprint
        // this board once carried, then withdrew or superseded.
        $historical = \App\Models\DrawPublication::query()
            ->where('draw_id', $drawId)
            ->where('result_fingerprint', $data->resultFingerprint)
            ->latest('version')
            ->first();

        if ($historical !== null) {
            return [
                'verdict' => 'STALE',
                'draw_reference' => $data->drawReference,
                'live_fingerprint' => $live !== null ? (string) $live->result_fingerprint : null,
                'published_at' => $historical->published_at?->toIso8601String(),
                'verified_at' => now()->toIso8601String(),
                'status' => $historical->status->value,
            ];
        }

        return [
            'verdict' => 'UNKNOWN',
            'draw_reference' => $data->drawReference,
            'live_fingerprint' => $live !== null ? (string) $live->result_fingerprint : null,
            'published_at' => null,
            'verified_at' => now()->toIso8601String(),
            'status' => null,
        ];
    }

    /**
     * The attestation material the public may re-derive CHECKSUMS from:
     * the certified fingerprint + the numbers behind it, only when live.
     *
     * @return array{fingerprint: string, winning_numbers: array<int, array{bet_type: string, prize_tier: ?string, position: ?string, number: string}>}|null
     */
    public function attestationFor(string $drawReference): ?array
    {
        /** @var Draw|null $draw */
        $draw = Draw::query()->where('draw_number', $drawReference)->first();

        if (! $draw instanceof Draw) {
            return null;
        }

        $live = $this->publications->liveFor((int) $draw->getKey());

        if ($live === null) {
            return null;
        }

        $fingerprint = \App\Services\Draw\DrawCertificationService::fingerprintFor((int) $draw->getKey());

        if ($fingerprint === null || ! hash_equals($fingerprint, (string) $live->result_fingerprint)) {
            // The ledger's current numbers no longer sign into the live
            // board line — the service answers honestly: no attestation.
            return null;
        }

        return [
            'fingerprint' => $fingerprint,
            'winning_numbers' => DrawCertificationService::winningNumbersSnapshot((int) $draw->getKey()),
        ];
    }
}
