<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\DTOs\Betting\BetAmendmentData;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Structural validation for POST /api/v1/bets/{bet}/amend.
 *
 * SAME FIDELITY RULES AS THE PURCHASE REQUEST
 * - `number` must be a STRING of digits: leading zeros are significant and a
 *   JSON number type has already destroyed them before PHP sees the payload.
 * - `stake` must be a decimal STRING with at most two fraction digits: a JSON
 *   float is a binary approximation before it reaches bcmath.
 * Both fields are optional individually, but at least one must be present —
 * checked here structurally; "does the amendment actually change anything" is
 * the service's semantic check (InvalidBetAmendmentException).
 *
 * THE MARKET IS NOT AMENDABLE
 * There is no `market` field. Replacing a 2d_top bet with a 3d_direct bet is a
 * different product (different digit width, multiplier architecture and risk
 * counters), which is a cancel-plus-new-purchase, not an amendment.
 */
final class AmendBetRequest extends FormRequest
{
    private const NUMBER_PATTERN = '/^[0-9]{1,3}$/';
    private const STAKE_PATTERN = '/^[0-9]{1,12}(\.[0-9]{1,2})?$/';
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
        $minKey = (int) config('security.idempotency.min_key_length', 16);
        $maxKey = (int) config('security.idempotency.max_key_length', 128);

        return [
            'number' => ['sometimes', 'string', 'regex:'.self::NUMBER_PATTERN],
            'stake' => ['sometimes', 'string', 'regex:'.self::STAKE_PATTERN],
            'client_key' => [
                'required',
                'string',
                'min:'.$minKey,
                'max:'.$maxKey,
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
            'number.regex' => 'The number may contain digits only, sent as a string so leading zeros survive. Send "007", not 7.',
            'stake.regex' => 'The stake must be a decimal string with at most two decimal places. Send "10.55", not 10.55.',
            'client_key.required' => 'A client_key is required so a retried amendment cannot buy the replacement twice.',
            'client_key.regex' => 'The client_key may contain letters, digits, dots, colons, hyphens and underscores only.',
        ];
    }

    /**
     * At least one change, and no server-owned fields.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $validated = $this->validated();

            $hasNumber = isset($validated['number']) && $validated['number'] !== '';
            $hasStake = isset($validated['stake']) && $validated['stake'] !== '';

            if (! $hasNumber && ! $hasStake) {
                $validator->errors()->add('number', 'An amendment must change the number, the stake, or both.');
            }

            foreach (BetAmendmentData::FORBIDDEN_CLIENT_KEYS as $key) {
                if ($this->has($key)) {
                    $validator->errors()->add($key, 'This field is decided by the server and must not be sent.');
                }
            }
        });
    }

    public function newNumber(): ?string
    {
        $value = $this->validated()['number'] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function newStake(): ?string
    {
        $value = $this->validated()['stake'] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function clientKey(): string
    {
        return (string) $this->validated()['client_key'];
    }
}
