<?php

declare(strict_types=1);

namespace App\Http\Requests\Payout;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for the payout detail lookup.
 *
 * The identifier itself arrives bound to the route ({payout}) and answers in
 * the totality of permitted spellings — the internal id, the UUID, or the
 * business reference number — and the controller is the component that owns
 * the three-way lookup. This request owns the access-scope side of the
 * handshake: the check that the caller is even permitted to ASK about this
 * particular obligation.
 *
 * WHAT BELONGS HERE
 * Input sanity only: the identifier must be present and shaped like one of
 * the three spellings. The authorization question (is this my payout, or am
 * I an operator?) is decided by the PayoutPolicy, which this request forces
 * into the handshake via authorize(); the request object is intentionally
 * empty of payout-domain logic so the policy stays the single source of
 * access truth.
 */
final class ShowPayoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        $payout = $this->route('payout');

        return $this->user() !== null && $payout !== null;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        // The identifier reaches the route as {payout}; for string-leniency
        // it is validated HERE from the route parameter so a garbage token is
        // refused with a plain 422 before any query builder sees it. Route
        // model binding is deliberately NOT used: the identifier is
        // dissociated (id | uuid | reference), and resolving it once inside
        // the controller keeps the lookup count exactly one.
        return [
            'payout' => [
                'required',
                'string',
                'max:64',
                'regex:/^[A-Za-z0-9\-\_]+$/',
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
        ];
    }

    /**
     * Project the route parameter into the input layer so the validator can
     * see it. The route token is read-only context — merged, never rewritten
     * from the request body (a client cannot gentleman-disagree with the URL it
     * actually called).
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'payout' => (string) $this->route('payout'),
        ]);
    }

    /**
     * The validated identifier, for the controller's single lookup pass.
     */
    public function identifier(): string
    {
        return (string) $this->validated('payout');
    }
}
