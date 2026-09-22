<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\DTOs\Betting\BetCancellationData;
use App\Enums\BetCancellationReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Structural validation for POST /api/v1/bets/{bet}/cancel.
 *
 * WHAT THIS CLASS IS FOR, AND WHAT IT IS NOT FOR
 * It validates the SHAPE of the request body: a plausibly-spelled reason and an
 * optional idempotency client key. Whether the bet may be cancelled — its
 * status, the draw state, the window — is BetCancellationService's authority and
 * is re-checked under the bet row lock regardless of what passes here.
 *
 * THE BET IDENTIFIER IS THE ROUTE PARAMETER
 * It is constrained at the route level already; this request only reads the body.
 */
final class CancelBetRequest extends FormRequest
{
    /**
     * A client key: printable, bounded — the same policy the purchase endpoint
     * enforces, read from the same configuration.
     */
    private const CLIENT_KEY_PATTERN = '/^[A-Za-z0-9._:-]+$/';

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => [
                'sometimes',
                'string',
                Rule::in(BetCancellationReason::values()),
            ],
            'client_key' => [
                'sometimes',
                'string',
                'min:'.(int) config('security.idempotency.min_key_length', 16),
                'max:'.(int) config('security.idempotency.max_key_length', 128),
                'regex:'.self::CLIENT_KEY_PATTERN,
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.in' => 'The reason must be one of: '.implode(', ', BetCancellationReason::values()).'.',
            'client_key.regex' => 'The client_key may contain letters, digits, dots, colons, hyphens and underscores only.',
        ];
    }

    /**
     * Refuse server-owned fields loudly rather than stripping them silently.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach (BetCancellationData::FORBIDDEN_CLIENT_KEYS as $key) {
                if ($this->has($key)) {
                    $validator->errors()->add($key, 'This field is decided by the server and must not be sent.');
                }
            }
        });
    }

    /**
     * The requested reason, or the platform default for a player-initiated cancel.
     */
    public function cancellationReason(): BetCancellationReason
    {
        $reason = $this->validated()['reason'] ?? null;

        return is_string($reason)
            ? (BetCancellationReason::tryFrom($reason) ?? BetCancellationReason::PlayerRequest)
            : BetCancellationReason::PlayerRequest;
    }

    /**
     * The client's request key, or null when none was supplied (cancellation of
     * an already-cancelled bet is naturally idempotent, so the key is optional).
     */
    public function clientKey(): ?string
    {
        $key = $this->validated()['client_key'] ?? null;

        return is_string($key) && $key !== '' ? $key : null;
    }
}
