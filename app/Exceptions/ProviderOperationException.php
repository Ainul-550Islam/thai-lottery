<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * ProviderOperationException — provider state/seat failures.
 */
final class ProviderOperationException extends RuntimeException
{
    public const MALFORMED = 'PROVOP_MALFORMED';
    public const UNKNOWN_PROVIDER = 'PROVOP_UNKNOWN_PROVIDER';
    public const STATE_ALREADY_SEATED = 'PROVOP_STATE_ALREADY_SEATED';
    public const INVALID_TRANSITION = 'PROVOP_INVALID_TRANSITION';

    private function __construct(
        private readonly string $deskCode,
        string $message,
        private readonly array $context = [],
    ) {
        parent::__construct('Provider operation refused: '.$message);
    }

    public function errorCode(): string
    {
        return $this->deskCode;
    }

    /** @return array<string, mixed> */
    public function context(): array
    {
        return $this->context;
    }

    public static function malformed(string $reason): self
    {
        return new self(self::MALFORMED, $reason, ['reason' => $reason]);
    }

    public static function unknownProvider(string $provider): self
    {
        return new self(self::UNKNOWN_PROVIDER, sprintf('provider [%s] is not a lane this desk carries', $provider), ['provider' => $provider]);
    }

    public static function stateAlreadySeated(string $provider, string $status): self
    {
        return new self(self::STATE_ALREADY_SEATED, sprintf('provider [%s] already rests in seat [%s]', $provider, $status), ['provider' => $provider, 'status' => $status]);
    }

    public static function invalidTransition(string $provider, string $from, string $to): self
    {
        return new self(self::INVALID_TRANSITION, sprintf('provider [%s] may not move %s → %s', $provider, $from, $to), ['provider' => $provider, 'from' => $from, 'to' => $to]);
    }
}
