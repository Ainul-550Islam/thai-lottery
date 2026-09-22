<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\DTOs\Payment\PaymentWebhookData;
use App\Exceptions\PaymentWebhookException;

/**
 * The doorman of provider evidence. Before ANY byte of a webhook may
 * touch money-adjacent state, this lane checks, in order:
 *
 *   1. SIGNATURE — an HMAC-SHA-256 over the canonical payload bytes
 *      keyed by the provider's webhook secret (env/config ONLY — it
 *      never enters DTOs, rows, or logs). Comparison is hash_equals,
 *      never ==. A missing secret is FAIL-CLOSED: without a key the
 *      door stays shut, never "conveniently open".
 *   2. REPLAY WINDOW — a provider-clock timestamp inside the envelope
 *      must sit within ±REPLAY_WINDOW_SECONDS of wall-clock; old
 *      envelopes are refused unless the identical bytes are already
 *      known evidence (a duplicate sighting, which is a no-op — the
 *      persistence lane, not this lane, judges that).
 *   3. EVENT IDENTITY — the envelope's declared event id must agree
 *      with its own payload facts (an `id`/`event_id` inside, when
 *      present, must be the SAME id).
 *   4. PROVIDER CLAIM — when the payload names its own provider/house,
 *      it must agree with the route's provider code.
 */
final class PaymentWebhookVerificationService
{
    /**
     * Default replay horizon in seconds (±5 minutes).
     */
    public const REPLAY_WINDOW_SECONDS = 300;

    /**
     * Silence is refusal: return nothing on success. When $allowStale
     * is true the REPLAY WINDOW check is skipped — used ONLY for
     * already-persisted envelopes whose signature, identity and
     * provider facts still re-prove (job retries), never for ingress.
     *
     * @throws PaymentWebhookException
     */
    public function verify(PaymentWebhookData $envelope, bool $allowStale = false): void
    {
        $secret = $this->secretFor($envelope->providerCode);

        // FAIL-CLOSED by construction.
        if ($secret === null || $secret === '') {
            throw PaymentWebhookException::signatureInvalid($envelope->providerCode, ['cause' => 'no-verification-key-configured']);
        }

        $signature = $envelope->signature;

        if ($signature === null || $signature === '') {
            throw PaymentWebhookException::signatureInvalid($envelope->providerCode, ['cause' => 'signature-absent']);
        }

        $expected = 'sha256='.hash_hmac('sha256', PaymentWebhookData::payloadBytes($envelope->payload), $secret);
        $provided = $signature;

        if (! hash_equals($expected, $provided)) {
            throw PaymentWebhookException::signatureInvalid($envelope->providerCode);
        }

        // REPLAY WINDOW — the envelope's own clock, when it carries one.
        $providerTs = $envelope->payload['timestamp'] ?? $envelope->payload['created'] ?? $envelope->payload['created_at'] ?? null;

        if (! $allowStale && $providerTs !== null) {
            $ts = is_numeric($providerTs) ? (int) $providerTs : strtotime((string) $providerTs);

            if ($ts !== false && abs(time() - $ts) > self::REPLAY_WINDOW_SECONDS) {
                throw PaymentWebhookException::replayWindow($envelope->providerCode, $envelope->eventId, ['drift_seconds' => time() - $ts]);
            }
        }

        // EVENT IDENTITY — the envelope must agree with itself.
        $payloadEventId = $envelope->payload['id'] ?? $envelope->payload['event_id'] ?? null;

        if (is_string($payloadEventId) && $payloadEventId !== '' && $payloadEventId !== $envelope->eventId) {
            throw PaymentWebhookException::eventMismatch($envelope->providerCode, $envelope->eventId, 'the payload\'s own id disagrees with the envelope');
        }

        // PROVIDER CLAIM — when the payload names its house, it must be
        // THIS house.
        $claimed = null;

        foreach (['provider', 'gateway', 'source'] as $key) {
            if (is_string($envelope->payload[$key] ?? null)) {
                $claimed = strtolower(trim((string) $envelope->payload[$key]));
                break;
            }
        }

        if ($claimed !== null && $claimed !== '' && $claimed !== $envelope->providerCode) {
            throw PaymentWebhookException::providerMismatch($envelope->providerCode, $claimed);
        }
    }

    /**
     * The provider's webhook verification key, from config/env only.
     */
    public function secretFor(string $providerCode): ?string
    {
        $providerCode = strtolower(trim($providerCode));

        return (string) (
            config("payment.gateways.{$providerCode}.webhook_secret")
            ?? env('PAYMENT_'.strtoupper(str_replace(['-', '_'], '_', $providerCode)).'_WEBHOOK_SECRET')
            ?: ''
        ) ?: null;
    }

    /**
     * The expected signature string for a payload under this provider's
     * key — used by tests AND by lanes that must construct outbound
     * verification examples; never used to bypass verification.
     */
    public function expectedSignatureFor(string $providerCode, array $payload): ?string
    {
        $secret = $this->secretFor($providerCode);

        if ($secret === null) {
            return null;
        }

        return 'sha256='.hash_hmac('sha256', PaymentWebhookData::payloadBytes($payload), $secret);
    }
}
