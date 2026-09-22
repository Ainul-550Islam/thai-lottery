<?php

declare(strict_types=1);

namespace App\DTOs\Operations;

use App\Enums\ProviderOperationStatus;
use App\Exceptions\ProviderOperationException;

/**
 * ProviderOperationData — a provider state-change ask. The provider
 * identity is DERIVED SERVER-SIDE from the lane's config (callers
 * may name the provider key as a lane token only); never carried in
 * operator free text.
 */
final class ProviderOperationData
{
    public function __construct(
        public readonly string $provider,
        public readonly ProviderOperationStatus $status,
        public readonly int $changedByUserId,
        public readonly ?string $note,
    ) {
    }

    /**
     * @param array{provider:string, status:string|ProviderOperationStatus, changed_by_user_id:int, note?:string|null} $data
     */
    public static function fromInput(array $data): self
    {
        $status = $data['status'] ?? null;
        if (! $status instanceof ProviderOperationStatus) {
            $status = is_string($status) ? ProviderOperationStatus::tryFrom(strtolower(trim($status))) : null;
        }
        if (! $status instanceof ProviderOperationStatus) {
            throw ProviderOperationException::malformed('A valid provider state is required');
        }

        $note = isset($data['note']) ? substr(trim((string) $data['note']), 0, 500) : null;

        return new self(
            provider: trim((string) ($data['provider'] ?? '')),
            status: $status,
            changedByUserId: (int) ($data['changed_by_user_id'] ?? 0),
            note: $note !== '' ? $note : null,
        );
    }

    /**
     * Deterministic identity of THE change — provider + seat +
     * changer + note facts. Identical re-asks land exactly-once.
     */
    public function changeFingerprint(): string
    {
        return hash('sha256', implode('|', [
            'glo-provop', $this->provider, $this->status->value,
            (string) $this->changedByUserId, (string) $this->note,
        ]));
    }
}
