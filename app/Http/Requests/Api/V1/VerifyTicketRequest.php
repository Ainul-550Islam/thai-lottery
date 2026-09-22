<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Structural validation for POST /api/v1/tickets/verify.
 *
 * ONE FIELD
 * The printed ticket number as a string. The strict shape check lives in the
 * verification service (it owns what a platform ticket number looks like);
 * here we only require the value to be present, short and free of control
 * characters, so the a failure is a 422 with a readable message rather than a
 * service exception.
 */
final class VerifyTicketRequest extends FormRequest
{
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
            'ticket_number' => [
                'required',
                'string',
                'min:4',
                'max:64',
                'regex:/^[A-Za-z0-9\-]+$/',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'ticket_number.regex' => 'The ticket number may contain letters, digits and hyphens only.',
        ];
    }

    public function ticketNumber(): string
    {
        return (string) $this->validated()['ticket_number'];
    }
}
