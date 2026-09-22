<?php

declare(strict_types=1);

namespace App\Http\Requests\Draw;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for the CHECKER-side confirmation payload of a draw result.
 *
 * WHAT MAKES A CONFIRMATION "VALID"
 * It names the draw it speaks about, it declares EXACTLY which first prize /
 * bottom-two pairing the checker claims they saw — and it carries the
 * confirmation context the maker/checker court needs: the checker supplies a
 * reason-by-rule (`notes`) so every confirmation carries its own
 * documentary, and the checker identity stamp comes from the authenticated
 * operator's session, never from the body. A confirmation whose payload
 * disputes the ingestion lane is refused upstream (and stays a checked-again
 * no-op inside the confirmation service's re-assert flow, never a fork).
 *
 * WHAT THIS REQUEST KEEPS OUT
 * Client-submitted broker identity fingerprints and self-published
 * confirmation metadata that the server derives for itself.
 */
final class ConfirmDrawResultRequest extends FormRequest
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
            'first_prize' => ['required', 'string', 'regex:/^\d{6}$/'],
            'bottom_two' => ['nullable', 'string', 'regex:/^\d{2}$/'],

            // The checker MUST attest with a note: confirmation without
            // context is confirmation without a paper trail.
            'notes' => ['required', 'string', 'min:5', 'max:500'],

            // Assertions the server derives for itself.
            'checker_user_id' => ['prohibited'],
            'operator_id' => ['prohibited'],
            'confirmed_at' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'first_prize.regex' => 'The confirmed first prize must be exactly six numeric characters.',
            'bottom_two.regex' => 'The confirmed bottom two must be exactly two numeric characters when supplied.',
            'notes.required' => 'A checker must attach the confirmation notes that justify the check.',
            'notes.min' => 'Confirmation notes must say more than nothing.',
            'checker_user_id.prohibited' => 'The checking operator is derived from the authenticated session.',
            'operator_id.prohibited' => 'The checking operator is derived from the authenticated session.',
            'confirmed_at.prohibited' => 'The confirmation timestamp is server-derived.',
        ];
    }

    /**
     * The claimed result the checker's payload asserts it saw.
     *
     * @return array{first_prize: string, bottom_two?: string}
     */
    public function claimedResult(): array
    {
        $claimed = ['first_prize' => (string) $this->validated('first_prize')];

        $bottomTwo = $this->validated('bottom_two');

        if (is_string($bottomTwo) && $bottomTwo !== '') {
            $claimed['bottom_two'] = $bottomTwo;
        }

        return $claimed;
    }

    /**
     * The documentary: the checker's notes, trimmed and scrubbed of
     * surrounding whitespace.
     */
    public function confirmationNotes(): string
    {
        return trim((string) $this->validated('notes'));
    }
}
