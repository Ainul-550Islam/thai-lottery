<?php

declare(strict_types=1);

namespace App\DTOs\Draw;

/**
 * The immutable identity of ONE publication row the board will remember.
 *
 * THE GRAMMAR:
 * - draw_id: positive integer handle.
 * - certification_key: the sha256 anchor of the certification this
 *   publication points at (64 lowercase hex) — publication never begins
 *   its own conversation without one.
 * - result_fingerprint: the certified fingerprint presented (64 hex);
 *   must equal the certification's own — checked by the court, never
 *   simply trusted.
 * - version: positive integer board-version; rotates MONOTONICALLY
 *   (version 1, then 2, …). The service enforces the +1 rule.
 * - published_at: ISO instant, not in the future.
 */
final readonly class DrawPublicationData
{
    public function __construct(
        public int $drawId,
        public string $certificationKey,
        public string $resultFingerprint,
        public int $version,
        public string $publishedAt,
    ) {
    }

    /**
     * @throws \App\Exceptions\DrawPublicationException
     */
    public static function fromInput(
        int $drawId,
        string $certificationKey,
        string $resultFingerprint,
        int $version,
        string $publishedAt,
    ): self {
        $ck = strtolower(trim($certificationKey));
        $fp = strtolower(trim($resultFingerprint));
        $at = trim($publishedAt);

        if ($drawId < 1) {
            throw \App\Exceptions\DrawPublicationException::malformed(
                'the draw handle must be a positive integer',
            );
        }

        if (! preg_match('/^[0-9a-f]{64}$/', $ck) || ! preg_match('/^[0-9a-f]{64}$/', $fp)) {
            throw \App\Exceptions\DrawPublicationException::malformed(
                'certification key and fingerprint must each be 64 lowercase hex characters',
            );
        }

        if ($version < 1) {
            throw \App\Exceptions\DrawPublicationException::malformed(
                'the version must be positive',
            );
        }

        try {
            $when = \Illuminate\Support\Carbon::parse($at)->utc();
        } catch (\Throwable) {
            throw \App\Exceptions\DrawPublicationException::malformed(
                'published_at must be an ISO-8601 instant',
            );
        }

        if ($when->isFuture()) {
            throw \App\Exceptions\DrawPublicationException::malformed(
                'published_at cannot lie in the future',
            );
        }

        return new self(
            drawId: $drawId,
            certificationKey: $ck,
            resultFingerprint: $fp,
            version: $version,
            publishedAt: $when->toIso8601String(),
        );
    }

    /**
     * The publication anchor, deterministic over (draw, version, fingerprint):
     * one row, one key, one board line.
     */
    public function publicationKey(): string
    {
        return hash('sha256', sprintf(
            'draw-pub:%d:%d:%s',
            $this->drawId,
            $this->version,
            $this->resultFingerprint,
        ));
    }
}
