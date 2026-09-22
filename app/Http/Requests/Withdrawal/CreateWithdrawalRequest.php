<?php

declare(strict_types=1);

namespace App\Http\Requests\Withdrawal;

use App\Enums\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for the player withdrawal-creation gesture.
 *
 * THE THREE-TRUTHS RULE THIS REQUEST ENFORCES
 * Any financial input the API accepts falls into one of three kinds:
 * something the server already knows and the client cannot dispute
 * (the player's balance), something the client asks for but the server
 * re-checks on its own (the requested amount), and something the client
 * supplies that is treated as a LABEL, never as a fact (the destination
 * details). This request validates the second and third kinds, and the
 * controller re-derives the first from the caller's wallet row — the
 * client-side balance and any KYC claim in the body are read into the
 * validator's dustbin, never into arithmetic.
 *
 * WHY NOT FormRequest->validate() INLINE IN THE CONTROLLER
 * The withdrawal surface has more than one endpoint, and amount/currency
 * shape rules belong to a single grammar, shared by every controller that
 * touches this money.
 */
final class CreateWithdrawalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            // Amount stays a STRING of digits with at most two fractional
            // digits; a client that ships an exponent, a currency symbol or
            // thousands separators gets a 422, not silent float arithmetic.
            // Canonicity beats convenience here because this number enters
            // bcmath minutes later.
            'amount' => ['required', 'string', 'regex:/^\d+(\.\d{1,2})?$/'],

            // Payment method is closed-vocabulary: only the enum's known
            // channels are dischargeable and the controller re-canonicalizes
            // the choice via PaymentMethod::from.
            'method' => ['required', 'string', 'in:'.implode(',', array_column(PaymentMethod::cases(), 'value'))],

            // Optional currency override; the default stays server-side
            // (THB, the GLO operating currency), not client-side.
            'currency' => ['nullable', 'string', 'in:THB,USD,BDT'],

            // Destination details are a free-form label blob at this layer;
            // the operator's disbursement court re-checks them. Tighten only
            // the shapes the serializer uses downstream.
            'payout_details' => ['nullable', 'array'],
            'payout_details.*' => ['nullable', 'string', 'max:256'],

            // Idempotency mirrors: body field OR header, never both
            // disagreeing. The header wins only when the body is absent.
            'idempotency_key' => ['nullable', 'string', 'min:16', 'max:128'],

            // Client-side claims the server expressly ignores — refusing
            // them loudly is kinder than silently dropping them when a
            // compromised client tries to assert "my balance says yes".
            'kyc_verified' => ['prohibited'],
            'balance' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'amount.regex' => 'The amount must be a decimal string with at most two fractional digits (no symbols, no exponents).',
            'method.in' => 'The requested withdrawal channel is not supported.',
            'kyc_verified.prohibited' => 'The client may not assert its own KYC status; it is derived server-side.',
            'balance.prohibited' => 'The client may not assert its own balance; it is derived server-side.',
        ];
    }

    /**
     * The validated canonical amount string (bcmath-ready).
     */
    public function amount(): string
    {
        return (string) $this->validated('amount');
    }

    /**
     * Optional payout_details as a scrubbed string→string map.
     *
     * @return array<string, string>
     */
    public function destination(): array
    {
        $details = $this->validated('payout_details');

        if (! is_array($details)) {
            return [];
        }

        $scrubbed = [];
        foreach ($details as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $scrubbed[$key] = trim($value);
            }
        }

        return $scrubbed;
    }
}
