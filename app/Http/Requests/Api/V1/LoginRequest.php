<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a token-issue request.
 *
 * WHY THIS EXISTS
 * Every route added in Phase 4.4 sits behind `auth:sanctum`, and the project shipped no
 * endpoint that could issue a token. The API was therefore unreachable by any client: the
 * only way to obtain a credential was `php artisan tinker` on the server. This request,
 * with AuthController, closes that gap using the mechanism the project already installed -
 * Sanctum personal access tokens, the existing `personal_access_tokens` migration and the
 * `HasApiTokens` trait already present on App\Models\User.
 *
 * WHY `login` IS NOT AN EMAIL FIELD
 * `users` carries BOTH a unique `email` and a unique `username`, so restricting sign-in to
 * one of them would make the other column unusable for its only obvious purpose. The field
 * is validated as a bounded string and resolved against whichever column it looks like -
 * never interpolated into SQL.
 *
 * WHAT IS DELIBERATELY NOT HERE
 * No registration, no password reset, no email/phone verification and no OTP. Those need
 * mail/SMS transport and a verification flow that this project has not built yet, and a
 * stub would imply a contract it cannot honour. Accounts are created by seeding or by the
 * admin phase.
 */
final class LoginRequest extends FormRequest
{
    /**
     * The route is public by design - it is how a caller becomes authenticated - so the
     * gate is the rate limiter and the credential check, not an ability check.
     */
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
            // Bounded length so a hostile payload cannot be used to make the hasher or the
            // query builder do unbounded work.
            'login' => ['required', 'string', 'min:3', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'max:255'],
            // A human-readable label for the issued token, so a user can later tell one
            // device from another. Never used in a query, only stored on the token row.
            'device_name' => ['sometimes', 'string', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'login.required' => 'An email address or username is required.',
            'password.required' => 'A password is required.',
        ];
    }

    public function loginValue(): string
    {
        return trim((string) $this->input('login'));
    }

    public function passwordValue(): string
    {
        return (string) $this->input('password');
    }

    public function deviceName(): string
    {
        $name = trim((string) $this->input('device_name', ''));

        return $name === '' ? 'api' : $name;
    }

    /**
     * True when the submitted identifier is an email address, which decides WHICH unique
     * column is queried. Both columns are unique, so exactly one row can ever match.
     */
    public function identifierIsEmail(): bool
    {
        return filter_var($this->loginValue(), FILTER_VALIDATE_EMAIL) !== false;
    }
}
