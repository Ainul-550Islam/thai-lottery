<?php

declare(strict_types=1);

namespace App\Http\Requests\Payout;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for a payout-cancellation petition.
 *
 * Two absolute boundaries govern what "cancel a payout" may mean here:
 *
 *  1. A payout is an OBLIGATION the house owes the player (a settled win).
 *     Cancelling it forfeits money into the void — so a bare DELETE against a
 *     completed or in-flight payout is refused BY STATE, not by authorization
 *     alone. Only a payout whose obligation has not yet started moving
 *     (Pending, canProcess) is even candidate for a cancellation petition.
 *
 *  2. HTTP stays within the architecture's single-money-mutation rule: the
 *     controller performs the status guard through the PayoutPolicy and, when
 *     the request survives, stamps a cancellation petition onto the payout's
 *     approval lane (metadata) — auditable, reviewable, and explicitly NOT a
 *     money mutation. Marking an obligation cancelled is an operator act in a
 *     financial-court step, never an HTTP gesture.
 *
 * This request owns: identifier shape (same spellings as the read endpoint)
 * plus an optional operator-facing reason string the audit trail benefits
 * from. Everything else is policy + lane.
 */
final class CancelPayoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && $this->route('payout') !== null;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'payout' => [
                'required',
                'string',
                'max:64',
                'regex:/^[A-Za-z0-9\-\_]+$/',
            ],
            'reason' => [
                'nullable',
                'string',
                'max:255',
                'not_regex:' . '/[<>]/', // no markup in an audit-bound string
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'payout.regex' => 'The payout identifier contains characters no payout reference can carry.',
            'reason.not_regex' => 'The cancellation reason may not contain markup characters.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'payout' => (string) $this->route('payout'),
        ]);
    }

    /**
     * The validated payout identifier.
     */
    public function identifier(): string
    {
        return (string) $this->validated('payout');
    }

    /**
     * The optional operator-supplied reason, scrubbed of surrounding
     * whitespace and preserved verbatim otherwise (audit-bound, no markup).
     */
    public function reason(): ?string
    {
        $reason = $this->validated('reason');

        return is_string($reason) && trim($reason) !== '' ? trim($reason) : null;
    }
}
