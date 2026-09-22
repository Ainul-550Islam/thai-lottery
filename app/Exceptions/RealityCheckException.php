<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * RealityCheckException — reality-check refusals (schedule, stale
 * acknowledgement, duplicate delivery, session mismatch).
 */
final class RealityCheckException extends RuntimeException
{
    public const INVALID_SCHEDULE = 'RC_INVALID_SCHEDULE';

    public const STALE_ACKNOWLEDGEMENT = 'RC_STALE_ACKNOWLEDGEMENT';

    public const DUPLICATE_DELIVERY = 'RC_DUPLICATE_DELIVERY';

    public const SESSION_MISMATCH = 'RC_SESSION_MISMATCH';

    public const INVALID_TRANSITION = 'RC_INVALID_TRANSITION';

    public const NOT_FOUND = 'RC_NOT_FOUND';

    /**
     * @param  array<string, mixed>  $context
     */
    private function __construct(
        private readonly string $deskCode,
        string $message,
        private readonly array $context = [],
    ) {
        parent::__construct('Reality check refused: '.$message);
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

    public static function invalidSchedule(string $reason): self
    {
        return new self(self::INVALID_SCHEDULE, $reason, ['reason' => $reason]);
    }

    public static function staleAcknowledgement(string $reference): self
    {
        return new self(self::STALE_ACKNOWLEDGEMENT, sprintf(
            'check [%s] is past its acknowledgement horizon', $reference,
        ), ['reference' => $reference]);
    }

    public static function duplicateDelivery(string $fingerprint): self
    {
        return new self(self::DUPLICATE_DELIVERY, sprintf(
            'delivery [%s] exists under different facts', substr($fingerprint, 0, 12),
        ), ['fingerprint' => $fingerprint]);
    }

    public static function sessionMismatch(string $expected, string $actual): self
    {
        return new self(self::SESSION_MISMATCH, sprintf(
            'check belongs to session [%s], not [%s]', $expected, $actual,
        ), ['expected_session' => $expected, 'actual_session' => $actual]);
    }

    public static function invalidTransition(string $reference, string $from, string $to): self
    {
        return new self(self::INVALID_TRANSITION, sprintf(
            'check [%s] may not move %s → %s', $reference, $from, $to,
        ), ['reference' => $reference, 'from' => $from, 'to' => $to]);
    }

    public static function notFound(string $reference): self
    {
        return new self(self::NOT_FOUND, sprintf('check [%s] is unknown to this desk', $reference), ['reference' => $reference]);
    }
}
