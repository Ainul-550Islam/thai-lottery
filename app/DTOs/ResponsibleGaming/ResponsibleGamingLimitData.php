<?php

declare(strict_types=1);

namespace App\DTOs\ResponsibleGaming;

use App\Enums\ResponsibleGamingLimitType;
use App\Exceptions\ResponsibleGamingLimitException;

/**
 * Exact-decimal limit definition: user, type, amount (bc-exact),
 * currency, the pronunciation's rolling period, effective window and
 * the deterministic LIMIT KEY the identity of the row flows from.
 */
final class ResponsibleGamingLimitData
{
    public readonly ResponsibleGamingLimitType $type;

    public function __construct(
        public readonly int $userId,
        ResponsibleGamingLimitType|string $type,
        public readonly string $amount,
        public readonly string $currency,
        public readonly ?\DateTimeInterface $effectiveFrom = null,
        public readonly ?\DateTimeInterface $effectiveTo = null,
    ) {
        $this->type = is_string($type) ? ResponsibleGamingLimitType::from($type) : $type;
    }

    /**
     * @param  array{user_id:int, limit_type:ResponsibleGamingLimitType|string, amount:string|int|float, currency?:string, effective_from?:\DateTimeInterface|null, effective_to?:\DateTimeInterface|null}  $data
     */
    public static function fromInput(array $data): self
    {
        $currency = strtoupper(trim((string) ($data['currency'] ?? 'THB')));
        $amountRaw = $data['amount'] ?? null;
        $amount = is_string($amountRaw) ? trim($amountRaw) : (is_int($amountRaw) ? (string) $amountRaw : null);

        if ($amount === null || ! preg_match('/^\d{1,12}(\.\d{1,2})?$/', $amount)) {
            throw ResponsibleGamingLimitException::invalidAmount(
                is_scalar($amountRaw) ? (string) $amountRaw : 'n/a',
            );
        }

        $type = $data['limit_type'] ?? null;

        if (! $type instanceof ResponsibleGamingLimitType) {
            $type = is_string($type) ? ResponsibleGamingLimitType::tryFrom(strtolower(trim($type))) : null;
        }

        if (! $type instanceof ResponsibleGamingLimitType || ! $type->isVersionedCeiling()) {
            throw ResponsibleGamingLimitException::malformed('A versionable limit type is required');
        }

        if (bccomp($amount, '0', 2) <= 0) {
            throw ResponsibleGamingLimitException::invalidAmount($amount);
        }

        if (! preg_match('/^[A-Z]{3}$/', $currency)) {
            throw ResponsibleGamingLimitException::malformed('Currency must be a 3-letter ISO code');
        }

        $from = $data['effective_from'] ?? null;
        $to = $data['effective_to'] ?? null;

        if ($from !== null && $to !== null && ! $to->greaterThan($from)) {
            throw ResponsibleGamingLimitException::malformed('effective_to must stand after effective_from');
        }

        return new self(
            userId: (int) ($data['user_id'] ?? 0),
            type: $type,
            amount: $amount,
            currency: $currency,
            effectiveFrom: $from,
            effectiveTo: $to,
        );
    }

    /**
     * Same (user, type, amount, window) = one version, replayed.
     */
    public function limitKey(): string
    {
        return hash('sha256', implode('|', [
            'glo-rgl', (string) $this->userId, $this->type->value, $this->amount, $this->currency,
            $this->effectiveFrom?->format(DATE_ATOM) ?? 'now',
            $this->effectiveTo?->format(DATE_ATOM) ?? 'open',
        ]));
    }
}
