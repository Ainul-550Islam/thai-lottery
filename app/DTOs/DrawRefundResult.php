<?php

declare(strict_types=1);

namespace App\DTOs;

/**
 * Immutable outcome of a draw refund operation.
 */
final class DrawRefundResult
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public readonly int $drawId,
        public readonly string $drawNumber,
        public readonly int $betsRefunded,
        public readonly string $totalRefundedAmount,
        public readonly string $currency,
        public readonly string $reason,
        public readonly ?string $refundedAt = null,
        public readonly array $context = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'draw_id' => $this->drawId,
            'draw_number' => $this->drawNumber,
            'bets_refunded' => $this->betsRefunded,
            'total_refunded_amount' => $this->totalRefundedAmount,
            'currency' => $this->currency,
            'reason' => $this->reason,
            'refunded_at' => $this->refundedAt,
            'context' => $this->context,
        ];
    }
}
