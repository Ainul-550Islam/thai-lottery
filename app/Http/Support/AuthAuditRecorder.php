<?php

declare(strict_types=1);

namespace App\Http\Support;

use App\Enums\AuditAction;
use App\Enums\RiskLevel;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Records sign-in, sign-out and failed sign-in attempts to the existing audit trail.
 *
 * WHY IT USES EXISTING INFRASTRUCTURE
 * The append-only `audit_logs` table already exists, `App\Enums\AuditAction` already has
 * `login`, `logout` and `login_failed` cases that nothing was writing, and
 * config/security.php already declares whether auditing is enabled. This class writes those
 * cases; it introduces no new table, enum case, channel or config key.
 *
 * WHY IT NEVER THROWS
 * Same rule as BetPurchaseAuditRecorder: auditing is observability. A token has either been
 * issued or refused by the time this runs, and an audit failure must not change that
 * outcome or turn a successful sign-in into a 500.
 *
 * WHAT IS NEVER RECORDED
 * No password, no plaintext token, no token hash, no Authorization header and no cookie.
 * A failed attempt records the SUBMITTED IDENTIFIER only, because an audit trail that
 * cannot say which account was targeted cannot be used to investigate credential stuffing;
 * it is truncated and never the password.
 */
final class AuthAuditRecorder
{
    public function recordLogin(Request $request, User $user, string $deviceName): void
    {
        $this->write(
            $request,
            AuditAction::Login,
            RiskLevel::Low,
            'API token issued.',
            $user->getKey(),
            ['device_name' => $deviceName],
        );
    }

    public function recordLogout(Request $request, User $user): void
    {
        $this->write(
            $request,
            AuditAction::Logout,
            RiskLevel::Low,
            'API token revoked.',
            $user->getKey(),
            [],
        );
    }

    /**
     * @param  string  $reason  a fixed internal label ('no_such_account', 'bad_password',
     *                          'inactive_account') - never a message shown to the caller,
     *                          because the response must not reveal which of the three it was
     */
    public function recordFailure(Request $request, string $identifier, string $reason, ?int $userId = null): void
    {
        $this->write(
            $request,
            AuditAction::LoginFailed,
            RiskLevel::Medium,
            'API token request refused.',
            $userId,
            [
                'identifier' => mb_substr($identifier, 0, 255),
                'reason' => $reason,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function write(
        Request $request,
        AuditAction $action,
        RiskLevel $risk,
        string $description,
        ?int $userId,
        array $metadata,
    ): void {
        if (config('security.audit.enabled', true) !== true) {
            return;
        }

        try {
            AuditLog::query()->create([
                'user_id' => $userId,
                'action' => $action->value,
                'risk_level' => $risk->value,
                'description' => $description,
                'ip_address' => $request->ip(),
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 1000),
                'url' => $request->fullUrl(),
                'method' => $request->method(),
                'metadata' => $metadata,
            ]);
        } catch (Throwable $exception) {
            Log::warning('auth.audit_write_failed', [
                'action' => $action->value,
                'exception' => $exception->getMessage(),
            ]);
        }
    }
}
