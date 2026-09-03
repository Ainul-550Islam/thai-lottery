<?php

declare(strict_types=1);

namespace App\Http\Support;

use App\DTOs\BetPurchaseResult;
use App\Enums\AuditAction;
use App\Enums\RiskLevel;
use App\Models\AuditLog;
use App\Models\Bet;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Records purchase attempts to the existing audit trail and the application log.
 *
 * WHY IT USES EXISTING INFRASTRUCTURE
 * The project already has an append-only `audit_logs` table, an AuditAction enum with a
 * `place_bet` case, a RiskLevel enum and an `audit` section in config/security.php that
 * declares whether auditing is enabled, which fields are sensitive and what the redaction
 * placeholder is. Phase 4.4 uses all of it. No new logging framework, no new table, no new
 * channel and no new config section were created.
 *
 * WHY IT NEVER THROWS
 * Auditing is observability, not the transaction. If the audit write fails - the table is
 * locked, the disk is full - the player's purchase has already been committed and it would
 * be indefensible to turn that into an error response, or worse, to make the caller think
 * the purchase failed when their wallet was already debited. Every failure here is
 * swallowed and reported to the log instead.
 *
 * WHY IT IS CALLED AFTER THE PURCHASE, NEVER INSIDE IT
 * The purchase owns its own transaction and Phase 4.3 refuses to run inside one it does
 * not own. Writing an audit row inside that transaction would also mean a rolled-back
 * purchase erased its own evidence - the failure record would vanish with the failure.
 * Recording from the controller, outside the transaction, keeps the trail intact for
 * exactly the attempts that matter most.
 *
 * WHAT IS NEVER RECORDED
 * No password, no token, no bearer header, no cookie, no session id, no payment detail and
 * no wallet balance. The recorded payload is built from a small explicit list of safe
 * fields; the raw request is never dumped. Sensitive keys named in
 * config('security.audit.sensitive_fields') are redacted from the request snapshot as a
 * second line of defence, using the project's declared placeholder.
 */
final class BetPurchaseAuditRecorder
{
    /**
     * Record a successful purchase.
     *
     * @param  array<string, mixed>  $requestContext
     */
    public function recordSuccess(
        Request $request,
        BetPurchaseResult $result,
        string $clientKey,
        array $requestContext = [],
    ): void {
        $payload = [
            'outcome' => $result->isReplay() ? 'replayed' : 'purchased',
            'bet_id' => $result->betId(),
            'bet_number' => $result->betNumber(),
            'ticket_id' => $result->ticketId(),
            'draw_id' => (int) $result->bet->draw_id,
            'currency' => $result->stake->currency()->value,
            'stake' => $result->stake->amount(),
            'client_key' => $clientKey,
        ];

        $this->write(
            $request,
            AuditAction::PlaceBet,
            RiskLevel::Low,
            sprintf(
                'Bet purchase %s via API for draw %d.',
                $result->isReplay() ? 'replayed' : 'completed',
                (int) $result->bet->draw_id,
            ),
            $payload + $this->safeRequestSnapshot($requestContext),
            Bet::class,
            $result->betId(),
        );

        Log::info('bet_purchase.api.success', $payload + [
            'request_id' => $this->requestId($request),
            'user_id' => $request->user()?->getAuthIdentifier(),
        ]);
    }

    /**
     * Record a refused or failed purchase.
     *
     * The mapped API error code is recorded rather than the exception message, for the
     * same reason the API does not return the message: domain messages name wallets and
     * balances. The exception CLASS is recorded because it is a type name, not data, and it
     * is what an operator needs in order to find the failure in the code.
     *
     * @param  array<string, mixed>  $requestContext
     */
    public function recordFailure(
        Request $request,
        Throwable $exception,
        string $errorCode,
        int $status,
        string $clientKey,
        array $requestContext = [],
    ): void {
        $payload = [
            'outcome' => 'refused',
            'error_code' => $errorCode,
            'http_status' => $status,
            'exception' => $exception::class,
            'client_key' => $clientKey,
        ];

        $this->write(
            $request,
            AuditAction::PlaceBet,
            $status >= 500 ? RiskLevel::High : RiskLevel::Medium,
            sprintf('Bet purchase refused via API: %s.', $errorCode),
            $payload + $this->safeRequestSnapshot($requestContext),
            null,
            null,
        );

        // A 5xx is a platform fault and belongs at error level with the throwable
        // attached so the trace reaches the log - where operators can see it - while never
        // reaching the client. A 4xx is an ordinary refusal and is recorded at info level
        // without a trace, so normal player mistakes do not fill the error log.
        if ($status >= 500) {
            Log::error('bet_purchase.api.failed', $payload + [
                'request_id' => $this->requestId($request),
                'user_id' => $request->user()?->getAuthIdentifier(),
                'exception' => $exception,
            ]);

            return;
        }

        Log::info('bet_purchase.api.refused', $payload + [
            'request_id' => $this->requestId($request),
            'user_id' => $request->user()?->getAuthIdentifier(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function write(
        Request $request,
        AuditAction $action,
        RiskLevel $level,
        string $description,
        array $payload,
        ?string $auditableType,
        ?int $auditableId,
    ): void {
        if (! (bool) config('security.audit.enabled', true)) {
            return;
        }

        try {
            // AuditLog is not a financial entity: it holds no balance, no amount that
            // settles and no ledger reference, and the audit_logs table is append-only by
            // policy. Creating a row here therefore does not touch the money path at all.
            AuditLog::create([
                'user_id' => $request->user()?->getAuthIdentifier(),
                'action' => $action->value,
                'risk_level' => $level->value,
                'auditable_type' => $auditableType,
                'auditable_id' => $auditableId,
                'description' => $description,
                'new_values' => $payload,
                'ip_address' => (bool) config('security.audit.log_ip_address', true)
                    ? $request->ip()
                    : null,
                'user_agent' => (bool) config('security.audit.log_user_agent', true)
                    ? substr((string) $request->userAgent(), 0, 500)
                    : null,
                'url' => (bool) config('security.audit.log_request_url', true)
                    ? $request->fullUrl()
                    : null,
                'method' => $request->method(),
                'request_id' => $this->requestId($request),
                'metadata' => null,
            ]);
        } catch (Throwable $failure) {
            Log::warning('bet_purchase.api.audit_write_failed', [
                'reason' => $failure::class,
                'request_id' => $this->requestId($request),
            ]);
        }
    }

    /**
     * A small, explicitly redacted snapshot of the request context.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function safeRequestSnapshot(array $context): array
    {
        /** @var list<string> $sensitive */
        $sensitive = (array) config('security.audit.sensitive_fields', []);
        $placeholder = (string) config('security.audit.redaction_placeholder', '[redacted]');

        $snapshot = [];

        foreach ($context as $key => $value) {
            if (in_array($key, $sensitive, true)) {
                $snapshot[$key] = $placeholder;

                continue;
            }

            if (is_scalar($value) || $value === null || is_array($value)) {
                $snapshot[$key] = $value;
            }
        }

        return $snapshot;
    }

    /**
     * A correlation id for this request.
     *
     * A caller-supplied X-Request-Id is honoured so a purchase can be traced across a
     * gateway, but it is length-capped and stripped of anything but safe characters, since
     * it is attacker-controlled and lands in the audit trail.
     */
    private function requestId(Request $request): string
    {
        $supplied = $request->header('X-Request-Id');

        if (is_string($supplied) && $supplied !== '') {
            $clean = (string) preg_replace('/[^A-Za-z0-9._:-]/', '', $supplied);

            if ($clean !== '') {
                return substr($clean, 0, 64);
            }
        }

        return bin2hex(random_bytes(16));
    }
}
