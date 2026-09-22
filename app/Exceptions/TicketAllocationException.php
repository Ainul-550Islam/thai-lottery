<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Allocation-court refusals.
 *
 * Vocabulary of the vendor/allocation lane: what may fail when capacity is
 * committed to (or uncommitted from) a vendor. Codes are the sheet music
 * the job / service / listener agree to stay in tune by.
 */
final class TicketAllocationException extends RuntimeException
{
    public const CODE_DUPLICATE = 'TICKET_ALLOCATION_DUPLICATE';

    public const CODE_QUOTA_OVERFLOW = 'TICKET_ALLOCATION_QUOTA_OVERFLOW';

    public const CODE_VENDOR_INELIGIBLE = 'TICKET_ALLOCATION_VENDOR_INELIGIBLE';

    public const CODE_STATE_FORBIDS = 'TICKET_ALLOCATION_STATE_FORBIDS';

    public const CODE_NOT_FOUND = 'TICKET_ALLOCATION_NOT_FOUND';

    public const CODE_ALREADY_RUNNING = 'TICKET_ALLOCATION_ALREADY_RUNNING';

    public function __construct(
        string $message,
        private readonly string $errorCode,
        private readonly array $context = [],
    ) {
        parent::__construct($message);
    }

    /**
     * A second allocation for the same (vendor, product, draw) triple with
     * a DIFFERENT quantity — same triple, new ask: a fork, never a replay.
     */
    public static function duplicate(int $vendorId, int $productId, int $drawId, string $allocationKey, array $context = []): self
    {
        return new self(
            sprintf(
                'An allocation already binds vendor #%d to product #%d for draw #%d under a different ask.',
                $vendorId,
                $productId,
                $drawId,
            ),
            self::CODE_DUPLICATE,
            $context + ['vendor_id' => $vendorId, 'ticket_product_id' => $productId, 'draw_id' => $drawId, 'allocation_key' => $allocationKey],
        );
    }

    /**
     * The vendor's capacity ledger says no: this accent would exceed the
     * maximum paper the channel may hold at once.
     */
    public static function quotaOverflow(int $vendorId, int $capacity, int $held, int $additional, array $context = []): self
    {
        return new self(
            sprintf('Vendor #%d may hold %d units at once; %d held + %d asked exceeds the surface.', $vendorId, $capacity, $held, $additional),
            self::CODE_QUOTA_OVERFLOW,
            $context + ['vendor_id' => $vendorId, 'capacity' => $capacity, 'held' => $held, 'additional' => $additional],
        );
    }

    /**
     * The vendor's lifecycle forbids convenience — suspended / closed /
     * pending: the eligibility court refuses on standing, not on quantity.
     */
    public static function vendorIneligible(int $vendorId, string $status, string $action, array $context = []): self
    {
        return new self(
            sprintf('Vendor #%d is [%s]; the gesture [%s] is ineligible from here.', $vendorId, $status, $action),
            self::CODE_VENDOR_INELIGIBLE,
            $context + ['vendor_id' => $vendorId, 'status' => $status, 'action' => $action],
        );
    }

    /**
     * The allocation's own lifecycle refuses the attempted step.
     */
    public static function stateForbids(string $allocationKey, string $current, string $attempted, array $context = []): self
    {
        return new self(
            sprintf('Allocation [%s...] is [%s]; the gesture [%s] is forbidden from here.', substr($allocationKey, 0, 12), $current, $attempted),
            self::CODE_STATE_FORBIDS,
            $context + ['allocation_key' => $allocationKey, 'current' => $current, 'attempted' => $attempted],
        );
    }

    public static function notFound(string $allocationKey, array $context = []): self
    {
        return new self(
            sprintf('Allocation [%s...] is not on the ledger.', substr($allocationKey, 0, 12)),
            self::CODE_NOT_FOUND,
            $context + ['allocation_key' => $allocationKey],
        );
    }

    public static function alreadyRunning(int $transactionLevel, array $context = []): self
    {
        return new self(
            sprintf('TicketAllocationService owns its transaction boundary; caller is at transaction level %d.', $transactionLevel),
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
