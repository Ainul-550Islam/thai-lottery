<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Structural validation for the permutation endpoints:
 *   POST /api/v1/bets/permutations/preview  (read-only quote)
 *   POST /api/v1/bets/permutations/purchase (executes via the bulk path)
 *
 * `number` is the base digit string and `stake` is PER ARRANGEMENT. The total
 * is always count × stake computed server-side with bcmul — there is no
 * `total` field, because a client-supplied total would be silently
 * authoritative over derived money, which this codebase never allows.
 */
final class PermutationRequest extends FormRequest
{
    private const NUMBER_PATTERN = '/^[0-9]{2,3}$/';
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
        return [
            'draw_id' => ['required', 'integer', 'min:1'],
            'market' => ['required', 'string', 'in:3d_direct,2d_top,2d_bottom'],
            'number' => ['required', 'string', 'regex:'.self::NUMBER_PATTERN],
            'stake' => ['required', 'string', 'regex:'.self::STAKE_PATTERN],
            'client_key' => [
                'required',
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
            'market.in' => 'Only exact-order markets (3d_direct, 2d_top, 2d_bottom) can be permuted. A tod bet already covers every arrangement; a run bet has one digit.',
            'number.regex' => 'The base number must be 2 or 3 digits, sent as a string so leading zeros survive.',
            'stake.regex' => 'The per-arrangement stake must be a decimal string with at most two decimal places.',
        ];
    }

    public function drawId(): int
    {
        return (int) $this->validated()['draw_id'];
    }

    public function marketKey(): string
    {
        return (string) $this->validated()['market'];
    }

    public function digits(): string
    {
        return (string) $this->validated()['number'];
    }

    public function stakePerArrangement(): string
    {
        return (string) $this->validated()['stake'];
    }

    public function clientKey(): string
    {
        return (string) $this->validated()['client_key'];
    }
}
