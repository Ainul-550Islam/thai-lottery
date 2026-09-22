<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\DTOs\Security\AuthenticationAttemptData;
use App\Enums\AuthenticationMethod;
use App\Enums\UserStatus;
use App\Exceptions\AuthenticationSecurityException;
use App\Http\Requests\Api\V1\LoginRequest;
use App\Http\Responses\ApiResponse;
use App\Http\Support\AuthAuditRecorder;
use App\Http\Support\BetPurchaseErrorMapper;
use App\Models\User;
use App\Services\Security\AuthenticationSecurityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * Issues, inspects and revokes API credentials.
 *
 * WHY THIS CONTROLLER WAS ADDED
 * Phase 4.4 put every API route behind `auth:sanctum` but shipped no way to obtain a
 * token, so the whole surface was unreachable from any client. This is the smallest
 * possible closure of that gap: three endpoints, built entirely on mechanisms the project
 * already had (Sanctum, `personal_access_tokens`, `HasApiTokens`, the `active` middleware,
 * the `audit_logs` table and the existing response envelope).
 *
 * THE UNIFORM-FAILURE RULE
 * `login` answers with exactly one error - `unauthenticated`, HTTP 401 - whether the
 * account does not exist, the password is wrong, or the account is suspended. Anything more
 * specific turns this endpoint into an account-enumeration oracle: an attacker could
 * discover which emails and usernames are registered without ever guessing a password.
 * The distinction is recorded in the audit trail, where operators can read it and an
 * attacker cannot.
 *
 * A password check runs even when no account matched, so the response time does not reveal
 * whether the identifier exists.
 *
 * WHAT THIS CONTROLLER DOES NOT DO
 * No registration, no password reset, no verification, no role assignment, no session
 * cookie and no refresh-token rotation. It never touches a wallet, a bet or the ledger.
 */
final class AuthController
{
    public function __construct(private readonly AuthAuditRecorder $audit) {}

    /**
     * POST /api/v1/auth/login — exchange credentials for a bearer token.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $identifier = $request->loginValue();

        // BATCH-15 central gate: the attempt is identified by hashed
        // identifier + IP + device context, gated BEFORE any credential
        // work, and recorded exactly once afterwards. The PUBLIC answer
        // never changes shape: the same 401 as a wrong password.
        $attempt = AuthenticationAttemptData::fromInput([
            'method' => AuthenticationMethod::Password,
            'identifier' => $identifier,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'device_fingerprint' => null,
        ]);

        $gate = app(AuthenticationSecurityService::class);

        try {
            $gate->assertAttemptAllowed($attempt);
        } catch (AuthenticationSecurityException) {
            // The desk code governs only the audit lane; outwardly the
            // account is simply 'not found' — refusal itself must not
            // confirm an identifier exists.
            return $this->refused();
        }

        $user = User::query()
            ->where($request->identifierIsEmail() ? 'email' : 'username', $identifier)
            ->first();

        if ($user === null) {
            // A dummy verification against a real bcrypt hash, so a request for an unknown
            // account costs the same time as one for a known account.
            Hash::check($request->passwordValue(), '$2y$12$H1t0eB4uKk3dTGqAgqR7ge0k1nBqvNqvZ0h1s2p3q4r5s6t7u8v9w');

            $this->audit->recordFailure($request, $identifier, 'no_such_account');
            $gate->record(AuthenticationAttemptData::fromInput([
                'method' => AuthenticationMethod::Password, 'identifier' => $identifier,
                'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
            ]), 'failed', 'no_such_account');
            $gate->loginFailed(null, AuthenticationAttemptData::fromInput([
                'method' => AuthenticationMethod::Password, 'identifier' => $identifier,
                'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
            ]));

            return $this->refused();
        }

        if (! Hash::check($request->passwordValue(), (string) $user->password)) {
            $this->audit->recordFailure($request, $identifier, 'bad_password', (int) $user->getKey());
            $failedAttempt = AuthenticationAttemptData::fromInput([
                'user_id' => (int) $user->getKey(), 'method' => AuthenticationMethod::Password,
                'identifier' => $identifier, 'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
            ]);
            $gate->record($failedAttempt, 'failed', 'bad_password');
            $gate->loginFailed((int) $user->getKey(), $failedAttempt);

            return $this->refused();
        }

        // A suspended, banned or unverified account is refused HERE rather than being given
        // a token that the `active` middleware would then reject on every later call. The
        // response is deliberately the same 401 as a wrong password.
        if ($user->status !== UserStatus::Active) {
            $this->audit->recordFailure($request, $identifier, 'inactive_account', (int) $user->getKey());

            return $this->refused();
        }

        $device = $request->deviceName();

        // One token per device name: issuing a second token for the same device would leave
        // an orphaned credential alive that the user cannot see or revoke.
        $user->tokens()->where('name', $device)->delete();

        $token = $user->createToken($device);

        // Login telemetry lives on the user row and is assigned explicitly - these columns
        // are deliberately not fillable.
        $user->last_login_at = now();
        $user->last_login_ip = $request->ip();
        $user->save();

        $this->audit->recordLogin($request, $user, $device);

        // The security lane records success + runs the deterministic
        // post-seat risk review (parks suspicious contexts, envelope
        // + audit anchors exactly-once). It never blocks the already
        // correctly-decided pronouncement: parking is desk-side only.
        $seatAttempt = AuthenticationAttemptData::fromInput([
            'user_id' => (int) $user->getKey(), 'method' => AuthenticationMethod::Password,
            'identifier' => $identifier, 'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
        ]);
        $gate->record($seatAttempt, 'succeeded');
        $gate->loginSucceeded($user, $seatAttempt);
        $gate->postSeatReview($user, $seatAttempt);

        $expiresInMinutes = config('sanctum.expiration');

        return ApiResponse::success(
            [
                'token' => $token->plainTextToken,
                'token_type' => 'Bearer',
                'expires_in_minutes' => $expiresInMinutes === null ? null : (int) $expiresInMinutes,
                'user' => $this->publicUser($user),
            ],
            'Authenticated.',
            201,
        );
    }

    /**
     * GET /api/v1/auth/me — the authenticated identity, and nothing else.
     *
     * No balance and no wallet id: a balance read belongs to the wallet surface, under the
     * wallet's own authorization, not to an identity endpoint.
     */
    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return ApiResponse::success(
            ['user' => $this->publicUser($user)],
            'OK',
        );
    }

    /**
     * POST /api/v1/auth/logout — revoke the token that made this request.
     *
     * Only the presented token is revoked, never all of them: signing out of a phone must
     * not sign the same person out of their other devices.
     */
    public function logout(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $token = $user->currentAccessToken();

        if ($token !== null && method_exists($token, 'delete')) {
            $token->delete();
        }

        $this->audit->recordLogout($request, $user);

        return ApiResponse::success([], 'Token revoked.');
    }

    private function refused(): JsonResponse
    {
        return ApiResponse::error(
            BetPurchaseErrorMapper::CODE_UNAUTHENTICATED,
            'The credentials provided are not valid.',
            401,
        );
    }

    /**
     * The whitelist of identity fields that may leave the server.
     *
     * Built field by field rather than from the model, so a column added to `users` in a
     * later phase is never disclosed by accident.
     *
     * @return array<string, mixed>
     */
    private function publicUser(User $user): array
    {
        return [
            'id' => (int) $user->getKey(),
            'name' => (string) $user->name,
            'username' => (string) $user->username,
            'email' => (string) $user->email,
            'status' => $user->status->value,
            'roles' => $user->getRoleNames()->values()->all(),
            'email_verified' => $user->email_verified_at !== null,
        ];
    }
}
