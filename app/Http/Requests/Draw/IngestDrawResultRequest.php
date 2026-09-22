<?php

declare(strict_types=1);

namespace App\Http\Requests\Draw;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for controlled draw-result INGESTION.
 *
 * THE RULES THAT MUST NOT BE BYPASSED AT THIS ENDPOINT
 * - Ingestion is a one-payload-at-a-time, source-attributed step: someone
 *   from operations statement "GLO announced: first prize = 123456". This
 *   request's rules mirror the ingestion service's own canonicalization so
 *   malformed paper never leaves the HTTP layer.
 * - first_prize must be EXACTLY six numeric characters; leading zeros are
 *   preserved as data (lottery numbers are strings, not integers — a prize
 *   of 003412 is a different winner from 3412).
 * - bottom_two may be supplied or DERIVED, but if supplied it must be two
 *   numeric characters; the service will then also re-check that it matches
 *   the first prize's tail, per the GLO rule.
 * - NOTHING THE INGESTION SAYS BECOMES A PROMISE: this request's docblock
 *   state what the endpoint is not (a finalization path) is enforced by the
 *   entire draw pipeline. This is validation only; the confirm endpoint
 *   carries its own maker/checker separation at the operator layer.
 *
 * WHO MAY INGEST
 * This request returns authorize=false for non-operators so the 403 story
 * matches the draw console reality: ingestion is an administrative act
 * behind the admin middleware (the controller AND the route double-bound
 * this), not a public gesture.
 */
final class IngestDrawResultRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if ($user === null) {
            return false;
        }

        return (bool) ($user->isAdmin() || $user->isSuperAdmin());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // The canonical six-digit GLO announcement, leading zeros kept.
            'first_prize' => ['required', 'string', 'regex:/^\d{6}$/'],

            // Physical-ticket companion result. If present, it must shape as
            // two digits; agreement with first_prize is the service's check.
            'bottom_two' => ['nullable', 'string', 'regex:/^\d{2}$/'],

            // The operator-declared provenance feed tag the pipeline groups
            // results by (manual / feed / correction), with a closed
            // alphabet so HTML-shaped feed blobs are unable to reach
            // downstream canonical serialization.
            'source' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_\-]+$/'],

            // Optional auxiliary announcements (three-digit / last-two etc.)
            // that the ingestion canonicalizer distills from the first prize
            // — the structure is bounded rather than free-form JSON.
            'optional_prizes' => ['nullable', 'array', 'min:1'],
            'optional_prizes.*' => ['nullable', 'string', 'regex:/^\d{1,6}$/'],

            // Client-submitted values the server never consults. A client
            // asserting either of these has stepped outside its lane.
            'result_id' => ['prohibited'],
            'fingerprint' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'first_prize.regex' => 'The first prize must be exactly six numeric characters, leading zeros preserved.',
            'bottom_two.regex' => 'The bottom two must be exactly two numeric characters when supplied.',
            'source.regex' => 'The source tag may only carry letters, digits, dashes and underscores.',
            'result_id.prohibited' => 'The result identity is server-derived; the client may not assert it.',
            'fingerprint.prohibited' => 'The ingestion fingerprint is server-derived; the client may not assert it.',
        ];
    }

    /**
     * The operator payload the ingestion service receives, scrubbed and
     * canonical at this layer so the service sees a clean shape.
     *
     * @return array{first_prize: string, bottom_two?: string, optional_prizes?: array<int, string>}
     */
    public function resultPayload(): array
    {
        $payload = ['first_prize' => (string) $this->validated('first_prize')];

        $bottomTwo = $this->validated('bottom_two');

        if (is_string($bottomTwo) && $bottomTwo !== '') {
            $payload['bottom_two'] = $bottomTwo;
        }

        $optional = $this->validated('optional_prizes');

        if (is_array($optional) && count($optional) > 0) {
            $payload['optional_prizes'] = array_values(array_filter(
                $optional,
                static fn (mixed $item): bool => is_string($item) && $item !== '',
            ));
        }

        return $payload;
    }

    /**
     * The validated provenance feed tag.
     */
    public function resultSource(): string
    {
        return (string) $this->validated('source');
    }
}
