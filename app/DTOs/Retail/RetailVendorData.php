<?php

declare(strict_types=1);

namespace App\DTOs\Retail;

use App\Enums\RetailVendorStatus;

/**
 * Immutable retail vendor identity + status data.
 *
 * "Who is this channel entitled to do business today" is a bundle, not a
 * row: the canonical vendor reference the ops console quotes, the vendor's
 * lifecycle state, and the capacity context that explains how much paper
 * the channel may hold at once. Identity comes FROM the vendor row; quota
 * context belongs with it — caller NEVER reconstructs the pair from
 * disparate queries (splitting them is how mixed stale answers arrive).
 *
 * Eligibility is derivation at the boundary:
 * Active + quota capacity not fully held → may receive; Active → may sell.
 * The full finalized test lives in RetailVendorService; this data carries
 * the legs the test needs so the test lacks nothing when it runs.
 */
final readonly class RetailVendorData
{
    public function __construct(
        public int $vendorId,
        public string $vendorCode,
        public RetailVendorStatus $status,
        public int $quotaCapacity,
    ) {
    }

    /**
     * Canonicalization mirrors the code grammar the codebase already uses
     * for canonical identifiers: uppercase ASCII, digits, hyphens.
     *
     * @throws \App\Exceptions\TicketAllocationException on a non-canonical
     *                                                    vendor reference.
     */
    public static function fromInput(
        int $vendorId,
        string $vendorCode,
        RetailVendorStatus $status,
        int $quotaCapacity,
    ): self {
        $canonical = strtoupper(trim($vendorCode));

        if (! preg_match('/^[A-Z0-9-]{3,64}$/', $canonical)) {
            throw new \App\Exceptions\TicketAllocationException(
                sprintf(
                    'Vendor code [%s] is not canonical (uppercase ASCII, digits, hyphens).',
                    $canonical,
                ),
                'CODE_VENDOR_CODE_NOT_CANONICAL',
            );
        }

        return new self(
            vendorId: $vendorId,
            vendorCode: $canonical,
            status: $status,
            quotaCapacity: $quotaCapacity,
        );
    }

    /**
     * Whether this vendor is in the one selling-capable state. Final
     * eligibility arbitration (including capacity headroom) stays the
     * RetailVendorService's own test.
     */
    public function isActive(): bool
    {
        return $this->status === RetailVendorStatus::Active;
    }

    /**
     * Whether this vendor's lifecycle admits commercial activity at all.
     */
    public function admitsCommerce(): bool
    {
        return $this->status->mayReceiveAllocation() && $this->quotaCapacity > 0;
    }

    /**
     * @return array{vendor_id: int, vendor_code: string, status: string, quota_capacity: int}
     */
    public function toArray(): array
    {
        return [
            'vendor_id' => $this->vendorId,
            'vendor_code' => $this->vendorCode,
            'status' => $this->status->value,
            'quota_capacity' => $this->quotaCapacity,
        ];
    }
}
