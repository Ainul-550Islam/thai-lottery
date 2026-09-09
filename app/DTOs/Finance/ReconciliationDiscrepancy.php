<?php

declare(strict_types=1);

namespace App\DTOs\Finance;

use App\Enums\DiscrepancyCategory;
use App\Enums\DiscrepancySeverity;

final readonly class ReconciliationDiscrepancy
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public string $entityType,
        public int|string|null $entityId,
        public ?string $referenceNumber,
        public DiscrepancyCategory $category,
        public DiscrepancySeverity $severity,
        public ?string $expectedAmount,
        public ?string $actualAmount,
        public ?string $difference,
        public string $currency,
        public string $description,
        public array $details = [],
        public ?string $detectedAt = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'entity_type' => $this->entityType,
            'entity_id' => $this->entityId,
            'reference_number' => $this->referenceNumber,
            'category' => $this->category->value,
            'category_label' => $this->category->label(),
            'severity' => $this->severity->value,
            'severity_label' => $this->severity->label(),
            'expected_amount' => $this->expectedAmount,
            'actual_amount' => $this->actualAmount,
            'difference' => $this->difference,
            'currency' => $this->currency,
            'description' => $this->description,
            'details' => $this->details,
            'detected_at' => $this->detectedAt,
        ];
    }
}
