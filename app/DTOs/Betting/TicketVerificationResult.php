<?php

declare(strict_types=1);

namespace App\DTOs\Betting;

use App\Enums\TicketVerificationStatus;

/**
 * The verdict of a ticket verification.
 *
 * COARSE BY DEFAULT
 * The default payload confirms existence and broad state only. Owner-scoped
 * detail (stake total, bet count) is attached separately and only when the
 * verification ran inside the owner's authenticated scope — a verifier holding
 * someone else's ticket number learns that the ticket exists and whether it is
 * live, never its money.
 *
 * READ-ONLY
 * No field here implies any state change; verification performs no write.
 */
final readonly class TicketVerificationResult
{
    /**
     * @param  array<string, mixed>|null  $ownerDetail  extra fields for the owner scope; null for public checks
     */
    public function __construct(
        public TicketVerificationStatus $status,
        public string $ticketNumber,
        public bool $found,
        public ?int $drawId = null,
        public ?string $drawNumber = null,
        public ?string $checkedAt = null,
        public ?array $ownerDetail = null,
    ) {
    }

    public function isFound(): bool
    {
        return $this->found;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = [
            'ticket_number' => $this->ticketNumber,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'found' => $this->found,
            'good_standing' => $this->status->isGoodStanding(),
            'draw_id' => $this->drawId,
            'draw_number' => $this->drawNumber,
            'checked_at' => $this->checkedAt,
        ];

        if ($this->ownerDetail !== null) {
            $payload['owner'] = $this->ownerDetail;
        }

        return $payload;
    }
}
