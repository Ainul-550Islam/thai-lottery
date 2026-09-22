<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\DrawResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public shape of a published official draw result.
 *
 * @mixin DrawResult
 */
final class DrawResultResource extends JsonResource
{
    /**
     * Presentation shape, keyed by a string the caller marks at the resource
     * construction site.
     */
    private string $additionalResourceVisibility = 'authorized';

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var DrawResult $result */
        $result = $this->resource;

        $payload = [
            'id' => (int) $result->getKey(),
            'draw_id' => (int) $result->draw_id,
            'first_prize' => (string) $result->first_prize,
            'second_prize' => $result->second_prize,
            'third_prize' => $result->third_prize,
            'consolation_prizes' => $result->consolation_prizes,
            'all_numbers' => $result->all_numbers,
            'two_digit_bottom' => $result->metadata['bottom_two'] ?? $result->metadata['two_digit_bottom'] ?? null,
            'total_winners' => (int) $result->total_winners,
            'total_payout' => (string) $result->total_payout,
            'published_at' => $result->published_at?->toIso8601String(),
            'winning_numbers' => WinningNumberResource::collection($this->whenLoaded('winningNumbers')),
        ];

        $payload['publication'] = $this->publicationSummary($result, $request);

        return $payload;
    }

    /**
     * Return the PUBLIC-ONLY shape of the stored publication record (staged
     * ingestion without the scorecard, provenance summaries only, and the
     * maker/checker line in the coarsest human legible terms the publication
     * lane recorded). Marked `->forPublic()` by the controller. Cascades
     * over the full cultural, legal and class mirror rule for this model:
     * caller-marked instances NEVER see the authorized shape.
     */
    public function forPublic(): static
    {
        $this->additionalResourceVisibility = 'public';

        return $this;
    }

    /**
     * Which mirror shape this resource answered with, for tests and
     * delegation diagnostics only — never serialized.
     */
    public function visibility(): string
    {
        return $this->additionalResourceVisibility;
    }

    /**
     * @return array<string, mixed>
     */
    private function publicationSummary(DrawResult $result, Request $request): array
    {
        // The public side: draw id + status in print. Only confirmed/published
        // data. The authorized side additionally includes the lane
        // recordings that stay auditable (never personal notes, contents or
        // provenance chains in dot notation the public already shapes
        // toward the console's own admin resourcing layout layers wholly).
        if ($this->additionalResourceVisibility === 'public') {
            return [
                'shipped' => true,
                'shape' => 'public',
            ];
        }

        $metadata = is_array($result->metadata) ? $result->metadata : [];

        return [
            'shipped' => true,
            'shape' => 'authorized',
            'ingestion_fingerprint' => is_string($metadata['ingestion_fingerprint'] ?? null)
                ? (string) $metadata['ingestion_fingerprint']
                : null,
            'source' => is_string($metadata['source'] ?? null) ? (string) $metadata['source'] : null,
            'ingested_at' => is_string($metadata['ingested_at'] ?? null) ? (string) $metadata['ingested_at'] : null,
            'confirmed_at' => is_string($metadata['confirmed_at'] ?? null) ? (string) $metadata['confirmed_at'] : null,
        ];
    }

}
