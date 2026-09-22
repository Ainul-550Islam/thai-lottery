<?php

declare(strict_types=1);

namespace App\Http\Requests\Withdrawal;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for a player withdrawal-cancellation gesture.
 *
 * THE STATE-TRANSITION PROTECTION THIS REQUEST UNDERLINES
 * A withdrawal's lifecycle is written by status, not by mood: only a
 * withdrawal that is still Pending may be retracted by its owner, because
 * at Pending the reservation exists but the house has not yet started the
 * approval/disbursement conversation. Every state after that (UnderReview,
 * Approved, Processing, Completed, and even the post-mortem states) is an
 * HTTP-refusal — the financial court owns those, full stop. The controller
 * re-derives that admissibility at execution time, atomically, under the
 * row lock; this request guarantees the INPUT SANITY half of the handshake:
 * a well-formed identifier plus at most a scrubbed cancellation reason.
 *
 * WHY A SEPARATE REQUEST CLASS
 * The grammar of "which identifiers may name a withdrawal" is shared with
 * the show surface, but each endpoint earns its own class so the audit, the
 * messages and the refusal vocabulary stay endpoint-specific.
 */
final class CancelWithdrawalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && $this->route('withdrawal') !== null;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'withdrawal' => [
                'required',
                'string',
                'max:64',
                'regex:/^[A-Za-z0-9\-\_]+$/',
            ],
            'reason' => ['nullable', 'string', 'max:255', 'not_regex:' . '/[<>]/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'withdrawal.regex' => 'The withdrawal identifier contains characters no reference can carry.',
            'reason.not_regex' => 'The cancellation reason may not contain markup characters.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'withdrawal' => (string) $this->route('withdrawal'),
        ]);
    }

    /**
     * The validated identifier spelling for the controller's single lookup.
     */
    public function identifier(): string
    {
        return (string) $this->validated('withdrawal');
    }

    /**
     * The optional reason, trimmed, or null.
     */
    public function reason(): ?string
    {
        $reason = $this->validated('reason');

        return is_string($reason) && trim($reason) !== '' ? trim($reason) : null;
    }
}
