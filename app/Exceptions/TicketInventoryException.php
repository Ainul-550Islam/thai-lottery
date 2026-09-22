<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Inventory allocation/reservation/sale invariant violations.
 *
 * One invented vocabulary for the whole inventory court: every refusal the
 * TicketInventoryService can speak, pronounced with a stable machine code
 * so channels, jobs and the reconciler each answer the same refusal by the
 * same name without parsing prose.
 *
 * Codes are ABSOLUTE: they never carry numbers, so a downstream matcher
 * switch()es on exactly the codes below and a new state of the world that
 * needs a new refusal gets its own professor's name here, first.
 */
final class TicketInventoryException extends RuntimeException
{
    public const CODE_NOT_FOUND = 'TICKET_INVENTORY_NOT_FOUND';

    public const CODE_STATE_FORBIDS = 'TICKET_INVENTORY_STATE_FORBIDS';

    public const CODE_DUPLICATE_SERIAL = 'TICKET_INVENTORY_DUPLICATE_SERIAL';

    public const CODE_QUOTA_EXCEEDED = 'TICKET_INVENTORY_QUOTA_EXCEEDED';

    public const CODE_FINGERPRINT_MISMATCH = 'TICKET_INVENTORY_FINGERPRINT_MISMATCH';

    public const CODE_NOT_RESERVABLE = 'TICKET_INVENTORY_NOT_RESERVABLE';

    public const CODE_NOT_SALEABLE = 'TICKET_INVENTORY_NOT_SALEABLE';

    public const CODE_NOT_RELEASABLE = 'TICKET_INVENTORY_NOT_RELEASABLE';

    public const CODE_ALREADY_RUNNING = 'TICKET_INVENTORY_ALREADY_RUNNING';

    public function __construct(
        string $message,
        private readonly string $errorCode,
        private readonly array $context = [],
    ) {
        parent::__construct($message);
    }

    public static function notFound(string $serial, array $context = []): self
    {
        return new self(
            "Ticket inventory unit [{$serial}] does not exist in the ledger.",
            self::CODE_NOT_FOUND,
            $context,
        );
    }

    public static function stateForbids(string $serial, string $current, string $attempted, array $context = []): self
    {
        return new self(
            sprintf('Inventory unit [%s] is [%s]; the gesture [%s] is forbidden from here.', $serial, $current, $attempted),
            self::CODE_STATE_FORBIDS,
            $context + ['serial' => $serial, 'current' => $current, 'attempted' => $attempted],
        );
    }

    public static function duplicateSerial(int $productId, int $drawId, string $serial, array $context = []): self
    {
        return new self(
            sprintf('Inventory unit [%s] already exists for product #%d draw #%d and cannot be minted twice.', $serial, $productId, $drawId),
            self::CODE_DUPLICATE_SERIAL,
            $context + ['product_id' => $productId, 'draw_id' => $drawId, 'serial' => $serial],
        );
    }

    public static function quotaExceeded(int $productId, int $unitsTotal, int $attempted, array $context = []): self
    {
        return new self(
            sprintf('Product #%d holds %d units at catalog; minting %d would breach the retail surface.', $productId, $unitsTotal, $attempted),
            self::CODE_QUOTA_EXCEEDED,
            $context + ['product_id' => $productId, 'units_total' => $unitsTotal, 'attempted' => $attempted],
        );
    }

    public static function fingerprintMismatch(string $serial, string $stored, string $given, array $context = []): self
    {
        return new self(
            sprintf('Inventory unit [%s] identity drifted: stored fingerprint [%s], presented [%s].', $serial, $stored, $given),
            self::CODE_FINGERPRINT_MISMATCH,
            $context + ['serial' => $serial, 'stored' => $stored, 'given' => $given],
        );
    }

    public static function notReservable(string $serial, string $current, array $context = []): self
    {
        return new self(
            sprintf('Inventory unit [%s] is [%s]; only Available may be reserved.', $serial, $current),
            self::CODE_NOT_RESERVABLE,
            $context + ['serial' => $serial, 'current' => $current],
        );
    }

    public static function notSaleable(string $serial, string $current, array $context = []): self
    {
        return new self(
            sprintf('Inventory unit [%s] is [%s]; only Available/Reserved may sell.', $serial, $current),
            self::CODE_NOT_SALEABLE,
            $context + ['serial' => $serial, 'current' => $current],
        );
    }

    public static function notReleasable(string $serial, string $current, array $context = []): self
    {
        return new self(
            sprintf('Inventory unit [%s] is [%s]; only a live Reservation ever releases.', $serial, $current),
            self::CODE_NOT_RELEASABLE,
            $context + ['serial' => $serial, 'current' => $current],
        );
    }

    public static function alreadyRunning(int $transactionLevel, array $context = []): self
    {
        return new self(
            sprintf('TicketInventoryService owns its transaction boundary; caller is at transaction level %d.', $transactionLevel),
            self::CODE_ALREADY_RUNNING,
            $context + ['transaction_level' => $transactionLevel],
        );
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function context(): array
    {
        return $this->context;
    }
}
