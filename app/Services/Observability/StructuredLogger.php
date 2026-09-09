<?php

declare(strict_types=1);

namespace App\Services\Observability;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Log;

/**
 * Production Structured Logger with Automatic Secret Redaction.
 *
 * Enforces structured key-value logging with correlation identifiers and
 * recursively strips API keys, passwords, tokens, and bank details.
 */
class StructuredLogger
{
    /**
     * Default list of sensitive keys to redact if config is unavailable.
     *
     * @var list<string>
     */
    private const DEFAULT_SENSITIVE_KEYS = [
        'password',
        'password_confirmation',
        'current_password',
        'secret',
        'api_key',
        'api_secret',
        'private_key',
        'token',
        'access_token',
        'refresh_token',
        'webhook_secret',
        'payout_details',
        'card_number',
        'cvv',
        'pin',
        'bank_account',
        'authorization',
        'x-bkash-signature',
        'x-signature',
        'x-crypto-signature',
    ];

    public function __construct(
        private readonly ?ConfigRepository $config = null,
    ) {
    }

    /**
     * Log an informational message with sanitized structured context.
     *
     * @param  array<string, mixed>  $context
     */
    public function info(string $message, array $context = [], ?string $channel = null): void
    {
        $this->write('info', $message, $context, $channel);
    }

    /**
     * Log a warning message with sanitized structured context.
     *
     * @param  array<string, mixed>  $context
     */
    public function warning(string $message, array $context = [], ?string $channel = null): void
    {
        $this->write('warning', $message, $context, $channel);
    }

    /**
     * Log an error message with sanitized structured context.
     *
     * @param  array<string, mixed>  $context
     */
    public function error(string $message, array $context = [], ?string $channel = null): void
    {
        $this->write('error', $message, $context, $channel);
    }

    /**
     * Log a critical message with sanitized structured context.
     *
     * @param  array<string, mixed>  $context
     */
    public function critical(string $message, array $context = [], ?string $channel = null): void
    {
        $this->write('critical', $message, $context, $channel);
    }

    /**
     * Log a financial operation event with sanitized context and dedicated finance channel.
     *
     * @param  array<string, mixed>  $context
     */
    public function financial(string $operation, string $message, array $context = []): void
    {
        $enriched = array_merge([
            'operation' => $operation,
            'is_financial' => true,
        ], $context);

        $this->write('info', sprintf('[FINANCE:%s] %s', strtoupper($operation), $message), $enriched, 'finance');
    }

    /**
     * Recursively redact sensitive keys from a context array.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function redact(array $data): array
    {
        $sensitiveKeys = $this->sensitiveKeys();
        $placeholder = '[REDACTED]';

        $sanitized = [];

        foreach ($data as $key => $value) {
            $normalizedKey = strtolower(str_replace(['-', ' '], '_', (string) $key));

            if ($this->isKeySensitive($normalizedKey, $sensitiveKeys)) {
                $sanitized[$key] = $placeholder;
            } elseif (is_array($value)) {
                $sanitized[$key] = $this->redact($value);
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }

    /**
     * Check if a key matches any sensitive pattern.
     *
     * @param  list<string>  $sensitiveKeys
     */
    private function isKeySensitive(string $key, array $sensitiveKeys): bool
    {
        foreach ($sensitiveKeys as $pattern) {
            if ($key === $pattern || str_contains($key, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function sensitiveKeys(): array
    {
        /** @var list<string>|null $configured */
        $configured = $this->config?->get('security.audit.sensitive_fields');

        if (is_array($configured) && $configured !== []) {
            return array_map('strtolower', $configured);
        }

        return self::DEFAULT_SENSITIVE_KEYS;
    }

    /**
     * Write sanitized log entry.
     *
     * @param  array<string, mixed>  $context
     */
    private function write(string $level, string $message, array $context = [], ?string $channel = null): void
    {
        $sanitizedContext = $this->redact($context);
        $sanitizedContext['correlation_id'] = CorrelationContext::get();

        $logger = $channel !== null ? Log::channel($channel) : Log::channel('single');
        $logger->log($level, $message, $sanitizedContext);
    }
}
