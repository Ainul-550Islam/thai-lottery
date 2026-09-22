<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * A refused or invalid payout-APPROVAL step.
 *
 * COVERAGE
 * --------
 * Everything the maker/checker lane may refuse: the checker stepping on the
 * maker's own request (independence violation), a decision on a request
 * already decided (duplicate decision), a decision on a request that does
 * not exist, an approval on un-payable money (amount drift at decision time),
 * or an unsworn decision (rejection without a stated reason).
 *
 * WHAT IT IS NOT
 * PayoutException describes the MONEY side (credits going wrong). The human
 * lane never moves money; a refusal here stops a checker decision. The two
 * exceptions carry different catch sites: the batch compensates wallets on
 * PayoutException, while the approval controller renders auth/state errors
 * on PayoutApprovalException.
 *
 * Context: ids, status strings, decimal amounts. Never a credential, stack,
 * SQL or personal datum.
 */
class PayoutApprovalException extends RuntimeException
{
    public const CODE_REQUEST_NOT_FOUND = 'APPROVAL_REQUEST_NOT_FOUND';

    public const CODE_NOT_PENDING = 'APPROVAL_NOT_PENDING';

    public const CODE_MAKER_EQUALS_CHECKER = 'APPROVAL_MAKER_EQUALS_CHECKER';

    public const CODE_DUPLICATE_DECISION = 'APPROVAL_DUPLICATE_DECISION';

    public const CODE_DECISION_SHAPE = 'APPROVAL_DECISION_INVALID_SHAPE';

    public const CODE_REJECTION_WITHOUT_REASON = 'APPROVAL_REJECTION_WITHOUT_REASON';

    public const CODE_ALREADY_RUNNING = 'APPROVAL_ALREADY_RUNNING';

    /**
     * @param  array<string, scalar|null>  $context
     */
    public function __construct(
        string $message,
        private readonly string $errorCode,
        private readonly array $context = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * The request the decision names does not exist.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function requestNotFound(int $requestId, array $context = []): self
    {
        return new self(
            sprintf('Payout approval request #%d does not exist.', $requestId),
            self::CODE_REQUEST_NOT_FOUND,
            $context + ['request_id' => $requestId],
        );
    }

    /**
     * The request is not Waiting for a checker — a decision on it is either a
     * replay, an edit or a betrayal of the lane's own states.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function notPending(int $requestId, string $currentStatus, array $context = []): self
    {
        return new self(
            sprintf(
                'Payout approval request #%d is %s; only pending requests admit a checker decision.',
                $requestId,
                $currentStatus,
            ),
            self::CODE_NOT_PENDING,
            $context + ['request_id' => $requestId, 'current_status' => $currentStatus],
        );
    }

    /**
     * The checker is the very person who submitted the request. Independent
     * hands are the whole reason the lane exists.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function makerEqualsChecker(int $requestId, int $userId, array $context = []): self
    {
        return new self(
            sprintf(
                'Payout approval request #%d: user #%d submitted it and cannot approve their own request (four-eyes violated).',
                $requestId,
                $userId,
            ),
            self::CODE_MAKER_EQUALS_CHECKER,
            $context + ['request_id' => $requestId, 'user_id' => $userId],
        );
    }

    /**
     * A decision was already recorded for this request. The second one would
     * silently rewrite the audit of the first.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function duplicateDecision(int $requestId, array $context = []): self
    {
        return new self(
            sprintf(
                'Payout approval request #%d already carries a checker decision; a second one is a replay or a fraud and is refused either way.',
                $requestId,
            ),
            self::CODE_DUPLICATE_DECISION,
            $context + ['request_id' => $requestId],
        );
    }

    /**
     * The decision status itself is not one of the two lawful checker
     * outcomes (Approved/Rejected). Machine-lane statuses are not humanitive.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function decisionShapeInvalid(int $requestId, string $givenStatus, array $context = []): self
    {
        return new self(
            sprintf(
                'Payout approval request #%d: decision [%s] is neither Approved nor Rejected — a machine state was offered where a human verdict belongs.',
                $requestId,
                $givenStatus,
            ),
            self::CODE_DECISION_SHAPE,
            $context + ['request_id' => $requestId, 'given_status' => $givenStatus],
        );
    }

    /**
     * A rejection offered no stated reason. A refusal a checker could not or
     * would not explain is unauditable, and unauditable refusals are refused.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function rejectionWithoutReason(int $requestId, array $context = []): self
    {
        return new self(
            sprintf(
                'Payout approval request #%d: a rejection MUST state its reason; submitting one without is refused.',
                $requestId,
            ),
            self::CODE_REJECTION_WITHOUT_REASON,
            $context + ['request_id' => $requestId],
        );
    }

    /**
     * The approval lane owns its transaction boundary.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function alreadyRunning(int $transactionLevel, array $context = []): self
    {
        return new self(
            sprintf('Payout approvals own their transaction boundary; caller is at transaction level %d.', $transactionLevel),
            self::CODE_ALREADY_RUNNING,
            $context + ['transaction_level' => $transactionLevel],
        );
    }

    /**
     * A cancellation petition arrived for a payout whose lane no longer
     * admits one — most often because the obligation stopped being at rest,
     * or because a live approval request already owns the outcome.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function cancellationRefused(int $payoutId, string $state, string $reason, array $context = []): self
    {
        return new self(
            sprintf(
                'Cancellation petition refused for payout #%d: lane is [%s]; %s',
                $payoutId,
                $state,
                $reason,
            ),
            self::CODE_NOT_PENDING,
            $context + ['payout_id' => $payoutId, 'lane_state' => $state],
        );
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    /**
     * @return array<string, scalar|null>
     */
    public function context(): array
    {
        return $this->context;
    }
}
