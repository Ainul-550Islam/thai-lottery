<?php

declare(strict_types=1);

namespace App\DTOs\ResponsibleGaming;

use App\Exceptions\SelfExclusionException;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * IMMUTABLE self-exclusion request as pronounced by the player and
 * seated by the service: user, effective/ends windows, scope, reason
 * CODE (never free text), and the deterministic request fingerprint.
 * The same prayer re-submitted lands on the same row.
 */
final class SelfExclusionData
{
    /** Shortest legal exclusion (24 hours) — server-side floor. */
    private const MIN_DURATION_HOURS = 24;

    /** Longest legal exclusion (5 years) — server-side ceiling. */
    private const MAX_DURATION_DAYS = 1825;

    public readonly CarbonInterface $effectiveAt;

    public readonly CarbonInterface $endsAt;

    public function __construct(
        public readonly int $userId,
        public readonly \DateTimeInterface|string $effectiveAtInput,
        public readonly \DateTimeInterface|string $endsAtInput,
        public readonly string $scope,
        public readonly string $reasonCode,
    ) {
        $this->effectiveAt = Carbon::parse($effectiveAtInput);
        $this->endsAt = Carbon::parse($endsAtInput);
    }

    /**
     * @param  array{user_id:int, ends_at:\DateTimeInterface|string, effective_at?:\DateTimeInterface|string|null, scope?:string, reason_code:string}  $data
     */
    public static function fromInput(array $data): self
    {
        $scope = strtolower(trim((string) ($data['scope'] ?? 'account')));
        $reasonCode = strtoupper(trim((string) ($data['reason_code'] ?? '')));
        $effectiveAt = $data['effective_at'] ?? null;

        if ($scope === '' || strlen($scope) > 32) {
            throw SelfExclusionException::malformed('A scope must be a 1-32 character token');
        }

        if ($reasonCode === '' || ! preg_match('/^[A-Z0-9_:\-\.]{1,64}$/', $reasonCode)) {
            throw SelfExclusionException::malformed('A reason code must be a canonical 1-64 character TOKEN');
        }

        $self = new self(
            userId: (int) ($data['user_id'] ?? 0),
            effectiveAtInput: $effectiveAt ?? now(),
            endsAtInput: $data['ends_at'] ?? throw SelfExclusionException::invalidDuration('An ends_at moment is required'),
            scope: $scope,
            reasonCode: $reasonCode,
        );

        if ($self->userId <= 0) {
            throw SelfExclusionException::malformed('A valid user id is required');
        }

        $durationHours = $self->effectiveAt->diffInHours($self->endsAt, false);

        if (! $self->endsAt->gt($self->effectiveAt)
            || $durationHours < self::MIN_DURATION_HOURS
            || $durationHours > self::MAX_DURATION_DAYS * 24) {
            throw SelfExclusionException::invalidDuration(sprintf(
                'duration must fall in [%d hours, %d days]', self::MIN_DURATION_HOURS, self::MAX_DURATION_DAYS,
            ));
        }

        return $self;
    }

    /**
     * Same (user, scope, reason, effective, ends) = same request.
     */
    public function requestFingerprint(): string
    {
        return hash('sha256', implode('|', [
            'glo-se', (string) $this->userId, $this->scope, $this->reasonCode,
            $this->effectiveAt->toIso8601String(), $this->endsAt->toIso8601String(),
        ]));
    }
}
