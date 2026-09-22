<?php

declare(strict_types=1);

namespace App\DTOs\Draw;

use App\Enums\ResultSourceType;

/**
 * The immutable payload of one certification conversation.
 *
 * THE GRAMMAR — certification refuses to begin until it all parses:
 * - draw_id: positive integer handle to the draw under judgment.
 * - result_fingerprint: exactly 64 lowercase hex (sha256). It must equal
 *   what the SERVICE re-derives over the draw's own winning-numbers —
 *   never trusted on presentation, only after corroboration.
 * - certifier_reference: non-empty canonical handle of the party signing
 *   (operator document id, internal consulter id). Never free text about
 *   the person; a reference to the person.
 * - certified_at: ISO-8601 instant; cannot be in the future.
 * - source: one of ResultSourceType (provenance travels with the lane).
 *
 * THE FINGERPRINT FORMULA (public knowledge by design):
 * sha256('draw-result:' . draw_id . '|' . canonical(winning_numbers))
 * where canonical = rows sorted by (bet_type, prize_tier, position,
 * number) rendered as 'bet_type:prize_tier:position:number' joined '|'.
 * Deterministic regardless of the order rows were ingested in.
 */
final readonly class DrawCertificationData
{
    public function __construct(
        public int $drawId,
        public string $resultFingerprint,
        public string $certifierReference,
        public string $certifiedAt,
        public ResultSourceType $source,
    ) {
    }

    /**
     * @throws \App\Exceptions\DrawCertificationException
     */
    public static function fromInput(
        int $drawId,
        string $resultFingerprint,
        string $certifierReference,
        string $certifiedAt,
        ResultSourceType $source,
    ): self {
        $fp = strtolower(trim($resultFingerprint));
        $certifier = strtoupper(trim($certifierReference));
        $at = trim($certifiedAt);

        if ($drawId < 1) {
            throw \App\Exceptions\DrawCertificationException::malformed(
                'the draw handle must be a positive integer',
            );
        }

        if (! preg_match('/^[0-9a-f]{64}$/', $fp)) {
            throw \App\Exceptions\DrawCertificationException::malformed(
                'the result fingerprint must be exactly 64 lowercase hex characters',
            );
        }

        if ($certifier === '' || strlen($certifier) > 64) {
            throw \App\Exceptions\DrawCertificationException::malformed(
                'the certifier reference must be 1-64 characters',
            );
        }

        try {
            $when = \Illuminate\Support\Carbon::parse($at)->utc();
        } catch (\Throwable) {
            throw \App\Exceptions\DrawCertificationException::malformed(
                'certified_at must be an ISO-8601 instant',
            );
        }

        if ($when->isFuture()) {
            throw \App\Exceptions\DrawCertificationException::malformed(
                'certified_at cannot lie in the future',
            );
        }

        return new self(
            drawId: $drawId,
            resultFingerprint: $fp,
            certifierReference: $certifier,
            certifiedAt: $when->toIso8601String(),
            source: $source,
        );
    }

    /**
     * Derive the canonical fingerprint the result claims, FROM the draw's
     * own ingested/confirmed winning numbers. The same formula is also
     * exposed to the public verification surface so an external checker
     * can derive it against materials the house publishes.
     *
     * @param  array<int, array{bet_type: string, prize_tier: ?string, position: ?string, number: string}>  $rows
     */
    public static function canonicalFingerprint(int $drawId, array $rows): string
    {
        $rendered = [];

        foreach ($rows as $row) {
            $rendered[] = implode(':', [
                strtolower(trim((string) ($row['bet_type'] ?? ''))),
                strtolower(trim((string) ($row['prize_tier'] ?? ''))),
                strtolower(trim((string) ($row['position'] ?? ''))),
                trim((string) ($row['number'] ?? '')),
            ]);
        }

        sort($rendered);

        return hash('sha256', 'draw-result:'.$drawId.'|'.implode('|', $rendered));
    }

    /**
     * Deterministic certification identity: one paper set, one key.
     */
    public function certificationKey(): string
    {
        return hash('sha256', sprintf(
            'draw-cert:%d:%s:%s',
            $this->drawId,
            $this->resultFingerprint,
            $this->certifierReference,
        ));
    }

    /**
     * @return array{draw_id: int, result_fingerprint: string, certifier_reference: string, certified_at: string, source: string}
     */
    public function toArray(): array
    {
        return [
            'draw_id' => $this->drawId,
            'result_fingerprint' => $this->resultFingerprint,
            'certifier_reference' => $this->certifierReference,
            'certified_at' => $this->certifiedAt,
            'source' => $this->source->value,
        ];
    }
}
