<?php

declare(strict_types=1);

namespace App\Http\Requests\Prize;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for the player prize-claim gesture.
 *
 * THE LINE BETWEEN THIS REQUEST AND THE SERVICE LAYER
 * This request validates SHAPE ONLY: what the claim references (bet id, or a
 * physical ticket's reference + verification code pair) and who is claiming
 * (always the authenticated principal, never a client-asserted user id).
 * The eligibility question — is the bet won, is the window open, is the
 * claimant the ticket's owner, is this a re-claim — is deliberately left
 * where the trees put it: inside PrizeClaimService and its window/ownership
 * companions. Duplicating even one eligibility check here would create two
 * places to disagree.
 *
 * TWO CLAIM CHANNELS, ONE ENDPOINT
 * - Digital lane: `bet_id` alone (the bet's registered owner asserts).
 * - Physical-ticket lane: `bet_id` + `ticket_number` + `verification_code`,
 *   where the ticket pair adds proof-of-possession for paper claims.
 * The pair must arrive TOGETHER or not at all — half a possession proof is
 * a schema error, refused here, never a partial claim in the service.
 */
final class CreatePrizeClaimRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'bet_id' => ['required', 'integer', 'min:1'],
            'ticket_number' => ['nullable', 'string', 'max:32', 'required_with:verification_code'],
            'verification_code' => ['nullable', 'string', 'max:64', 'required_with:ticket_number'],

            // The claimant is always the authenticated principal; any client
            // attempt to assert a different claimant is refused loudly.
            'claimant_user_id' => ['prohibited'],
            'user_id' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'bet_id.required' => 'A claim must reference the bet it asserts winnings against.',
            'ticket_number.required_with' => 'A physical-ticket claim needs both the ticket number and its verification code.',
            'verification_code.required_with' => 'A physical-ticket claim needs both the ticket number and its verification code.',
            'claimant_user_id.prohibited' => 'The claimant is always the authenticated account; it may not be asserted by the client.',
            'user_id.prohibited' => 'The claimant is always the authenticated account; it may not be asserted by the client.',
        ];
    }

    /**
     * The validated bet identifier the claim asserts against.
     */
    public function betId(): int
    {
        return (int) $this->validated('bet_id');
    }

    /**
     * Ticket-possession evidence, normalized (trimmed, null-collapsed).
     *
     * @return array{ticket_number: ?string, verification_code: ?string}
     */
    public function ticketEvidence(): array
    {
        $number = $this->validated('ticket_number');
        $code = $this->validated('verification_code');

        return [
            'ticket_number' => is_string($number) && trim($number) !== '' ? trim($number) : null,
            'verification_code' => is_string($code) && trim($code) !== '' ? trim($code) : null,
        ];
    }
}
