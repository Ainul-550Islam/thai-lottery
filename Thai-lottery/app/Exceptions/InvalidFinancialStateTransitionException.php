<?php

declare(strict_types=1);

namespace App\Exceptions;

use Throwable;

/**
 * A financial record was asked to move to a status it may not move to.
 *
 * WHAT IT CARRIES AND WHY THAT IS SAFE
 * ------------------------------------
 * The entity type, the entity id, the current status and the requested status.
 * All four are non-sensitive: the type is a short label such as "deposit", the id
 * is an internal auto-increment integer, and the statuses are enum backing values
 * that already appear in every admin screen. No amount, no beneficiary detail, no
 * customer identity and no credential is attached.
 *
 * The entity type is stored as a SHORT LABEL, not as a fully-qualified class
 * name, so an error surface can never advertise the internal namespace layout.
 * Use forEntity() when a class name is what you have; it is reduced to its base
 * name in snake case.
 *
 * No HTTP status code, no rendering, no database query.
 */
class InvalidFinancialStateTransitionException extends FinancialException
{
    public const ERROR_CODE = 'invalid_financial_state_transition';

    public const ERROR_TERMINAL_STATE = 'financial_state_is_terminal';

    private string $entityType;

    private ?int $entityId;

    private ?string $currentStatus;

    private ?string $requestedStatus;

    /**
     * @param  array<string, string|int|float|bool|null>  $context
     */
    public function __construct(
        string $entityType,
        ?int $entityId,
        ?string $currentStatus,
        ?string $requestedStatus,
        ?string $errorCode = null,
        ?string $message = null,
        array $context = [],
        ?Throwable $previous = null,
    ) {
        $this->entityType = $entityType;
        $this->entityId = $entityId;
        $this->currentStatus = $currentStatus;
        $this->requestedStatus = $requestedStatus;

        $resolvedMessage = $message ?? sprintf(
            '%s %s cannot move from status "%s" to "%s".',
            $entityType,
            $entityId === null ? '(unsaved)' : (string) $entityId,
            $currentStatus ?? 'unknown',
            $requestedStatus ?? 'unknown',
        );

        parent::__construct(
            $resolvedMessage,
            $errorCode ?? self::ERROR_CODE,
            array_merge([
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'current_status' => $currentStatus,
                'requested_status' => $requestedStatus,
            ], $context),
            $previous,
        );
    }

    /**
     * Build from whatever identifies the entity, reducing a class name to a short
     * label so no namespace is exposed.
     *
     * @param  array<string, string|int|float|bool|null>  $context
     */
    public static function forEntity(
        string $entityTypeOrClass,
        ?int $entityId,
        ?string $currentStatus,
        ?string $requestedStatus,
        array $context = [],
        ?Throwable $previous = null,
    ): self {
        return new self(
            self::normaliseEntityType($entityTypeOrClass),
            $entityId,
            $currentStatus,
            $requestedStatus,
            self::ERROR_CODE,
            null,
            $context,
            $previous,
        );
    }

    /**
     * The record is in a terminal state; nothing may follow it.
     *
     * @param  array<string, string|int|float|bool|null>  $context
     */
    public static function terminalState(
        string $entityTypeOrClass,
        ?int $entityId,
        ?string $currentStatus,
        ?string $requestedStatus,
        array $context = [],
        ?Throwable $previous = null,
    ): self {
        $entityType = self::normaliseEntityType($entityTypeOrClass);

        return new self(
            $entityType,
            $entityId,
            $currentStatus,
            $requestedStatus,
            self::ERROR_TERMINAL_STATE,
            sprintf(
                '%s %s is in terminal status "%s" and cannot move to "%s". A financial correction requires a new reversing transaction, not a status change.',
                $entityType,
                $entityId === null ? '(unsaved)' : (string) $entityId,
                $currentStatus ?? 'unknown',
                $requestedStatus ?? 'unknown',
            ),
            $context,
            $previous,
        );
    }

    /**
     * Reduce "App\Models\Deposit" to "deposit". Anything already short is passed
     * through lowercased.
     */
    private static function normaliseEntityType(string $entityTypeOrClass): string
    {
        $base = $entityTypeOrClass;

        if (str_contains($base, '\\')) {
            $segments = explode('\\', $base);
            $base = (string) end($segments);
        }

        $snake = preg_replace('/(?<!^)[A-Z]/', '_$0', $base);

        return strtolower($snake ?? $base);
    }

    /**
     * Short, non-sensitive label of the entity kind, e.g. "deposit".
     */
    public function entityType(): string
    {
        return $this->entityType;
    }

    public function entityId(): ?int
    {
        return $this->entityId;
    }

    public function currentStatus(): ?string
    {
        return $this->currentStatus;
    }

    public function requestedStatus(): ?string
    {
        return $this->requestedStatus;
    }

    /**
     * Whether the refusal was caused by the record already being terminal.
     */
    public function isTerminalRefusal(): bool
    {
        return $this->errorCode() === self::ERROR_TERMINAL_STATE;
    }
}
