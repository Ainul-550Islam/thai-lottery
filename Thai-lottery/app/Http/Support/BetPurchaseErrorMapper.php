<?php

declare(strict_types=1);

namespace App\Http\Support;

use App\Enums\BetValidationCode;
use App\Exceptions\BetDomainException;
use App\Exceptions\BetPurchaseConcurrencyException;
use App\Exceptions\BetPurchaseException;
use App\Exceptions\BetPurchaseIdempotencyException;
use App\Exceptions\BetPurchaseValidationException;
use App\Exceptions\FinancialException;
use App\Exceptions\IdempotencyConflictException;
use App\Exceptions\InsufficientBalanceException;
use App\Exceptions\InvalidBetAmountException;
use App\Exceptions\InvalidLotteryNumberException;
use App\Exceptions\MarketResultUnavailableException;
use App\Exceptions\NumberLimitExceededException;
use App\Exceptions\RiskException;
use App\Exceptions\UnsupportedBetMarketException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Translates a domain, application or framework exception into a stable API error.
 *
 * WHY A DEDICATED MAPPER
 * The Phase 4.1-4.3 exceptions were written explicitly WITHOUT HTTP knowledge: the base
 * BetDomainException documents that it carries no status, no render() and no response
 * building, because an exception must be safe to throw inside a transaction that is
 * about to roll back. Phase 4.4 therefore adds the translation OUTSIDE those classes
 * rather than retrofitting HTTP concerns into verified domain code. No Phase 4.1-4.3
 * exception class was modified, and no new duplicate exception class was created - this
 * mapper consumes the existing ones.
 *
 * THE OUTPUT IS A CONTRACT
 * The `code` strings returned here are the stable public vocabulary a client may branch
 * on. They are lower_snake_case and independent of internal wording: a domain exception
 * message may be reworded without changing the API code, and the internal
 * `bet_purchase_*` error codes stay internal.
 *
 * MESSAGES ARE REWRITTEN, NEVER FORWARDED
 * `$exception->getMessage()` is never placed in a response. Domain messages are written
 * for operators and legitimately contain internal detail - the insufficient-balance
 * message, for example, names the wallet id and its available balance. Every public
 * message below is a fixed, safe string chosen for this mapper. That is also why a stack
 * trace, a SQL fragment or a driver error can never reach a client through this path:
 * there is no branch that copies an exception's own text.
 *
 * DETAILS ARE WHITELISTED, NOT DUMPED
 * The domain context array is never serialised wholesale. Only the specific keys named
 * in each branch are copied, so a context key added later by a domain service cannot
 * silently start leaking. `wallet_id` is deliberately excluded everywhere.
 *
 * DELIBERATE NON-RESPONSIBILITIES
 * - No logging. The controller logs, with correlation context it owns.
 * - No database access and no model loading.
 * - No money arithmetic. Amount strings are copied verbatim.
 * - No retry, no compensation and no mutation of any kind.
 */
final class BetPurchaseErrorMapper
{
    public const CODE_UNAUTHENTICATED = 'unauthenticated';

    public const CODE_VALIDATION_FAILED = 'validation_failed';

    public const CODE_DRAW_NOT_FOUND = 'draw_not_found';

    public const CODE_DRAW_NOT_OPEN = 'draw_not_open';

    public const CODE_DRAW_CLOSED = 'draw_closed';

    public const CODE_INVALID_MARKET = 'invalid_market';

    public const CODE_INVALID_DIGITS = 'invalid_digits';

    public const CODE_INVALID_NUMBER = 'invalid_number';

    public const CODE_INVALID_STAKE = 'invalid_stake';

    public const CODE_INSUFFICIENT_BALANCE = 'insufficient_balance';

    public const CODE_NUMBER_LIMIT_EXCEEDED = 'number_limit_exceeded';

    public const CODE_RISK_REJECTED = 'risk_rejected';

    public const CODE_DUPLICATE_IDEMPOTENCY_KEY = 'duplicate_idempotency_key';

    public const CODE_IDEMPOTENCY_PAYLOAD_MISMATCH = 'idempotency_payload_mismatch';

    public const CODE_TRANSACTION_FAILED = 'transaction_failed';

    public const CODE_MARKET_RESULT_UNAVAILABLE = 'market_result_unavailable';

    public const CODE_PURCHASE_NOT_ALLOWED = 'purchase_not_allowed';

    public const CODE_AUTHORIZATION_FAILED = 'authorization_failed';

    public const CODE_RESOURCE_NOT_FOUND = 'resource_not_found';

    public const CODE_RATE_LIMITED = 'rate_limited';

    public const CODE_MULTI_ITEM_UNSUPPORTED = 'multi_item_purchase_unsupported';

    /**
     * The reason code Phase 3.1 reports when a number's own ceiling is the blocker.
     *
     * Sourced from App\Enums\NumberLimitStatus::rejectionReasonCode(). It is compared as
     * a string rather than imported as an enum case because the value travels through the
     * risk verdict array as a plain string, and re-deriving it from the enum here would
     * imply the enum is the transport when it is not.
     */
    private const RISK_REASON_NUMBER_LIMIT = 'NUMBER_LIMIT_EXCEEDED';

    /**
     * Map any throwable to a safe API error triple.
     *
     * @return array{code: string, status: int, message: string, details: array<string, mixed>}
     */
    public function map(Throwable $exception): array
    {
        return match (true) {
            $exception instanceof AuthenticationException => $this->unauthenticated(),
            $exception instanceof ValidationException => $this->validationFailed($exception),
            $exception instanceof ThrottleRequestsException => $this->rateLimited(),
            $exception instanceof AuthorizationException => $this->authorizationFailed(),
            $exception instanceof ModelNotFoundException,
            $exception instanceof NotFoundHttpException => $this->notFound(),
            $exception instanceof BetPurchaseIdempotencyException => $this->idempotency($exception),
            $exception instanceof BetPurchaseConcurrencyException => $this->concurrency($exception),
            $exception instanceof BetPurchaseValidationException => $this->domainValidation($exception),
            $exception instanceof BetPurchaseException => $this->purchase($exception),

            // Phase 3.1 risk exceptions, most specific first.
            $exception instanceof NumberLimitExceededException => $this->numberLimitExceeded($exception),
            $exception instanceof RiskException => $this->risk($exception),

            // Phase 4.2 market-rule exceptions.
            $exception instanceof MarketResultUnavailableException => $this->marketResultUnavailable($exception),
            $exception instanceof UnsupportedBetMarketException => $this->unsupportedMarket($exception),
            $exception instanceof InvalidLotteryNumberException => $this->invalidNumber($exception),
            $exception instanceof InvalidBetAmountException => $this->invalidStake($exception),

            // Phase 2.x financial exceptions, most specific first.
            $exception instanceof InsufficientBalanceException => $this->insufficientBalance($exception),
            $exception instanceof IdempotencyConflictException => $this->idempotencyConflict(),
            $exception instanceof FinancialException => $this->financial($exception),

            $exception instanceof BetDomainException => $this->betDomain($exception),

            // Framework and middleware aborts. EnsureUserIsActive, for instance, calls
            // abort(403) rather than throwing a domain exception, and a 403 that arrived as
            // a plain HttpException must still leave the API as a 403 - collapsing it into
            // a 500 would tell a suspended account that the SERVER is broken and would put
            // an operational error in the logs for what is in fact a correct refusal. The
            // status is honoured; the message never is, so an abort() reason written for a
            // developer cannot reach the client.
            $exception instanceof HttpExceptionInterface => $this->httpException($exception),

            default => $this->unexpected(),
        };
    }

    /**
     * @return array{code: string, status: int, message: string, details: array<string, mixed>}
     */
    private function unauthenticated(): array
    {
        return [
            'code' => self::CODE_UNAUTHENTICATED,
            'status' => 401,
            'message' => 'Authentication is required for this request.',
            'details' => [],
        ];
    }

    /**
     * Laravel validation messages are safe to forward: they are produced by this
     * project's own rules, not by a driver or a third party.
     *
     * @return array{code: string, status: int, message: string, details: array<string, mixed>}
     */
    private function validationFailed(ValidationException $exception): array
    {
        return [
            'code' => self::CODE_VALIDATION_FAILED,
            'status' => 422,
            'message' => 'The request failed validation.',
            'details' => ['fields' => $exception->errors()],
        ];
    }

    /**
     * @return array{code: string, status: int, message: string, details: array<string, mixed>}
     */
    private function rateLimited(): array
    {
        return [
            'code' => self::CODE_RATE_LIMITED,
            'status' => 429,
            'message' => 'Too many requests. Please slow down and retry shortly.',
            'details' => [],
        ];
    }

    /**
     * @return array{code: string, status: int, message: string, details: array<string, mixed>}
     */
    private function authorizationFailed(): array
    {
        return [
            'code' => self::CODE_AUTHORIZATION_FAILED,
            'status' => 403,
            'message' => 'This action is not authorized.',
            'details' => [],
        ];
    }

    /**
     * A missing resource and a resource belonging to somebody else are reported
     * identically, on purpose. Returning 403 for "exists but not yours" and 404 for
     * "does not exist" turns the endpoint into an existence oracle: an attacker could
     * enumerate valid bet ids without ever reading one. Both answer 404.
     *
     * @return array{code: string, status: int, message: string, details: array<string, mixed>}
     */
    private function notFound(): array
    {
        return [
            'code' => self::CODE_RESOURCE_NOT_FOUND,
            'status' => 404,
            'message' => 'The requested resource was not found.',
            'details' => [],
        ];
    }

    /**
     * @return array{code: string, status: int, message: string, details: array<string, mixed>}
     */
    private function idempotency(BetPurchaseIdempotencyException $exception): array
    {
        return match ($exception->errorCode()) {
            'bet_purchase_idempotency_payload_mismatch' => [
                'code' => self::CODE_IDEMPOTENCY_PAYLOAD_MISMATCH,
                'status' => 409,
                'message' => 'This client_key was already used for a different purchase. '
                    .'Use a new client_key for a new purchase.',
                'details' => [],
            ],
            'bet_purchase_idempotency_key_required',
            'bet_purchase_idempotency_key_invalid' => [
                'code' => self::CODE_VALIDATION_FAILED,
                'status' => 422,
                'message' => 'The supplied client_key is not usable.',
                'details' => [],
            ],
            // replay_incomplete and schema_change_required are operator faults, not
            // client faults: the stored purchase is not readable back, or the schema
            // cannot enforce idempotency at all. Neither is something the caller can
            // fix by changing the request, so neither is reported as a 4xx.
            default => $this->transactionFailed(),
        };
    }

    /**
     * @return array{code: string, status: int, message: string, details: array<string, mixed>}
     */
    private function concurrency(BetPurchaseConcurrencyException $exception): array
    {
        return match ($exception->errorCode()) {
            'bet_purchase_idempotent_race_lost',
            'bet_purchase_unique_violation' => [
                'code' => self::CODE_DUPLICATE_IDEMPOTENCY_KEY,
                'status' => 409,
                'message' => 'An identical purchase for this client_key is already being processed. '
                    .'Retry the same request to read the stored result.',
                'details' => [],
            ],
            default => [
                // A deadlock or an exhausted reference-generation retry is transient and
                // nothing was persisted, so the honest advice is "retry the same request",
                // which is safe precisely because the request is idempotent.
                'code' => self::CODE_TRANSACTION_FAILED,
                'status' => 503,
                'message' => 'The purchase could not be completed because of contention. '
                    .'Nothing was charged. Retry the same request.',
                'details' => ['retryable' => true],
            ],
        };
    }

    /**
     * Domain validation refusals, keyed by the stable Phase 4.1 validation code.
     *
     * @return array{code: string, status: int, message: string, details: array<string, mixed>}
     */
    private function domainValidation(BetPurchaseValidationException $exception): array
    {
        $context = $exception->context();

        return match ($exception->validationCode()) {
            BetValidationCode::DrawNotFound => [
                'code' => self::CODE_DRAW_NOT_FOUND,
                'status' => 404,
                'message' => 'The requested draw does not exist.',
                'details' => $this->only($context, ['draw_id']),
            ],
            BetValidationCode::DrawClosed => [
                'code' => self::CODE_DRAW_CLOSED,
                'status' => 422,
                'message' => 'This draw is no longer accepting bets.',
                'details' => $this->only($context, ['draw_id', 'draw_status']),
            ],
            BetValidationCode::InvalidMarket,
            BetValidationCode::UnsupportedMarket => [
                'code' => self::CODE_INVALID_MARKET,
                'status' => 422,
                'message' => 'The requested market is not available.',
                'details' => $this->only($context, ['market', 'supported_markets']),
            ],
            BetValidationCode::InvalidDigits => [
                'code' => self::CODE_INVALID_DIGITS,
                'status' => 422,
                'message' => 'The number does not have the digit count this market requires.',
                'details' => $this->only($context, ['market', 'expected_digits', 'actual_digits']),
            ],
            BetValidationCode::InvalidNumber,
            BetValidationCode::InvalidSelectionType,
            BetValidationCode::InvalidSide => [
                'code' => self::CODE_INVALID_NUMBER,
                'status' => 422,
                'message' => 'The submitted number is not valid for this market.',
                'details' => $this->only($context, ['market', 'expected_digits']),
            ],
            BetValidationCode::InvalidAmount => [
                'code' => self::CODE_INVALID_STAKE,
                'status' => 422,
                'message' => 'The submitted stake is not an accepted amount for this market.',
                'details' => $this->only($context, ['minimum', 'maximum', 'step', 'currency']),
            ],
            BetValidationCode::RiskRejected => $this->riskRejection($context),
            BetValidationCode::InvalidMultiplier,
            BetValidationCode::SpecificationRequired => [
                // The project has not declared a rule the sale depends on. The domain
                // refuses to invent one, so the API refuses the sale rather than pricing
                // it on a guess.
                'code' => self::CODE_PURCHASE_NOT_ALLOWED,
                'status' => 422,
                'message' => 'This selection cannot be sold because a required market rule is not configured.',
                'details' => $this->only($context, ['market']),
            ],
            default => [
                'code' => self::CODE_VALIDATION_FAILED,
                'status' => 422,
                'message' => 'The purchase was refused by the betting rules.',
                'details' => $this->only($context, ['market']),
            ],
        };
    }

    /**
     * @return array{code: string, status: int, message: string, details: array<string, mixed>}
     */
    private function purchase(BetPurchaseException $exception): array
    {
        $context = $exception->context();

        return match ($exception->errorCode()) {
            'bet_purchase_insufficient_balance' => [
                'code' => self::CODE_INSUFFICIENT_BALANCE,
                'status' => 422,
                'message' => 'Your available balance is not enough for this stake.',
                // `required` and `available` describe the caller's OWN wallet and are
                // useful for a top-up prompt. `wallet_id` is withheld: the client never
                // needs it and must never be able to send it back.
                'details' => $this->only($context, ['required', 'available', 'currency']),
            ],
            'bet_purchase_risk_rejected' => $this->riskRejection($context),
            'bet_purchase_draw_unavailable' => [
                'code' => self::CODE_DRAW_NOT_OPEN,
                'status' => 422,
                'message' => 'This draw is not open for betting.',
                'details' => $this->only($context, ['draw_id', 'draw_status']),
            ],
            'bet_purchase_user_unavailable',
            'bet_purchase_wallet_unavailable' => [
                'code' => self::CODE_PURCHASE_NOT_ALLOWED,
                'status' => 403,
                'message' => 'Your account cannot place bets at the moment.',
                'details' => [],
            ],
            'bet_purchase_multiplier_not_storable' => [
                'code' => self::CODE_PURCHASE_NOT_ALLOWED,
                'status' => 422,
                'message' => 'This market cannot be sold because its payout rate cannot be recorded exactly.',
                'details' => $this->only($context, ['market']),
            ],
            // ledger_not_balanced, invariant_violated and outside_transaction all mean
            // the platform refused to commit something it could not prove correct.
            // Nothing was persisted; the client is not at fault and cannot fix it.
            default => $this->transactionFailed(),
        };
    }

    /**
     * Phase 3.1 refused because the number itself is full.
     *
     * This is the branch that makes `number_limit_exceeded` reachable even when the
     * refusal arrives as the dedicated Phase 3.1 exception rather than inside a purchase
     * exception's risk verdict.
     *
     * @return array{code: string, status: int, message: string, details: array<string, mixed>}
     */
    private function numberLimitExceeded(NumberLimitExceededException $exception): array
    {
        return [
            'code' => self::CODE_NUMBER_LIMIT_EXCEEDED,
            'status' => 422,
            'message' => 'This number has reached its limit for this draw. '
                .'Try a smaller stake or a different number.',
            'details' => [],
        ];
    }

    /**
     * Phase 4.2 cannot evaluate the market because the result source it needs is absent -
     * for example 2D Bottom when draw_results.metadata has no bottom_two value.
     *
     * Reported as its own code rather than folded into a generic failure, because the
     * platform must never invent a bottom result and the client deserves to know the
     * refusal is about an unavailable result source, not about their number.
     *
     * @return array{code: string, status: int, message: string, details: array<string, mixed>}
     */
    private function marketResultUnavailable(MarketResultUnavailableException $exception): array
    {
        return [
            'code' => self::CODE_MARKET_RESULT_UNAVAILABLE,
            'status' => 422,
            'message' => 'The result source this market depends on is not available for this draw.',
            'details' => [],
        ];
    }

    /**
     * @return array{code: string, status: int, message: string, details: array<string, mixed>}
     */
    private function unsupportedMarket(UnsupportedBetMarketException $exception): array
    {
        return [
            'code' => self::CODE_INVALID_MARKET,
            'status' => 422,
            'message' => 'The requested market is not available.',
            'details' => [],
        ];
    }

    /**
     * @return array{code: string, status: int, message: string, details: array<string, mixed>}
     */
    private function invalidNumber(InvalidLotteryNumberException $exception): array
    {
        return [
            'code' => self::CODE_INVALID_NUMBER,
            'status' => 422,
            'message' => 'The submitted number is not valid for this market.',
            'details' => [],
        ];
    }

    /**
     * @return array{code: string, status: int, message: string, details: array<string, mixed>}
     */
    private function invalidStake(InvalidBetAmountException $exception): array
    {
        return [
            'code' => self::CODE_INVALID_STAKE,
            'status' => 422,
            'message' => 'The submitted stake is not an accepted amount for this market.',
            'details' => [],
        ];
    }

    /**
     * @return array{code: string, status: int, message: string, details: array<string, mixed>}
     */
    private function insufficientBalance(InsufficientBalanceException $exception): array
    {
        return [
            'code' => self::CODE_INSUFFICIENT_BALANCE,
            'status' => 422,
            'message' => 'Your available balance is not enough for this stake.',
            'details' => [],
        ];
    }

    /**
     * @return array{code: string, status: int, message: string, details: array<string, mixed>}
     */
    private function idempotencyConflict(): array
    {
        return [
            'code' => self::CODE_IDEMPOTENCY_PAYLOAD_MISMATCH,
            'status' => 409,
            'message' => 'This client_key was already used for a different purchase. '
                .'Use a new client_key for a new purchase.',
            'details' => [],
        ];
    }

    /**
     * A Phase 3.1 risk exception that escaped as itself rather than as a purchase
     * exception. Reported as a risk rejection so the client sees one vocabulary.
     *
     * @return array{code: string, status: int, message: string, details: array<string, mixed>}
     */
    private function risk(RiskException $exception): array
    {
        return $this->riskRejection($exception instanceof BetDomainException ? $exception->context() : []);
    }

    /**
     * A Phase 2.x financial exception. Reported without its message: those messages name
     * wallets, balances, transactions and ledger accounts.
     *
     * @return array{code: string, status: int, message: string, details: array<string, mixed>}
     */
    private function financial(FinancialException $exception): array
    {
        return $this->transactionFailed();
    }

    /**
     * @return array{code: string, status: int, message: string, details: array<string, mixed>}
     */
    private function betDomain(BetDomainException $exception): array
    {
        return [
            'code' => self::CODE_VALIDATION_FAILED,
            'status' => 422,
            'message' => 'The purchase was refused by the betting rules.',
            'details' => $this->only($exception->context(), ['market']),
        ];
    }

    /**
     * Distinguish "this number is full" from every other risk refusal.
     *
     * A player can act on a number limit - pick another number, or a smaller stake. The
     * other risk reasons are internal posture and are reported as a flat refusal without
     * the reason, so the endpoint cannot be used to probe the risk engine's thresholds.
     *
     * @param  array<string, mixed>  $context
     * @return array{code: string, status: int, message: string, details: array<string, mixed>}
     */
    private function riskRejection(array $context): array
    {
        $reason = $context['risk_reason_code'] ?? null;

        if (is_string($reason) && $reason === self::RISK_REASON_NUMBER_LIMIT) {
            return [
                'code' => self::CODE_NUMBER_LIMIT_EXCEEDED,
                'status' => 422,
                'message' => 'This number has reached its limit for this draw. '
                    .'Try a smaller stake or a different number.',
                'details' => $this->only($context, ['market', 'number']),
            ];
        }

        return [
            'code' => self::CODE_RISK_REJECTED,
            'status' => 422,
            'message' => 'This selection cannot be accepted at the moment.',
            'details' => $this->only($context, ['market', 'number']),
        ];
    }

    /**
     * @return array{code: string, status: int, message: string, details: array<string, mixed>}
     */
    private function transactionFailed(): array
    {
        return [
            'code' => self::CODE_TRANSACTION_FAILED,
            'status' => 500,
            'message' => 'The purchase could not be completed. Nothing was charged.',
            'details' => [],
        ];
    }

    /**
     * Convert a framework/middleware abort into the envelope, honouring its status code but
     * never its message.
     *
     * @return array{code: string, status: int, message: string, details: array<string, mixed>}
     */
    private function httpException(HttpExceptionInterface $exception): array
    {
        $status = $exception->getStatusCode();

        return match (true) {
            $status === 401 => $this->unauthenticated(),
            $status === 403 => $this->authorizationFailed(),
            $status === 404 => $this->notFound(),
            $status === 429 => $this->rateLimited(),

            // Any other client error is reported as a refusal rather than a server fault,
            // with a generic reason. A 405 or a 415 is a malformed request, so the stable
            // validation code is the honest one to return.
            $status >= 400 && $status < 500 => [
                'code' => self::CODE_VALIDATION_FAILED,
                'status' => $status,
                'message' => 'The request could not be accepted.',
                'details' => [],
            ],

            // A 5xx abort is a server fault and is reported exactly like any other
            // unexpected failure, with nothing of the original attached.
            default => $this->unexpected(),
        };
    }

    /**
     * Anything with no mapping at all.
     *
     * A QueryException, a TypeError or a driver failure lands here and is reduced to a
     * fixed sentence. This is the branch that guarantees no SQL text and no stack trace
     * reaches a client even when the failure was never anticipated.
     *
     * @return array{code: string, status: int, message: string, details: array<string, mixed>}
     */
    private function unexpected(): array
    {
        return [
            'code' => self::CODE_TRANSACTION_FAILED,
            'status' => 500,
            'message' => 'The request could not be completed.',
            'details' => [],
        ];
    }

    /**
     * Copy only the named keys, and only when their value is safe to serialise.
     *
     * @param  array<string, mixed>  $context
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    private function only(array $context, array $keys): array
    {
        $details = [];

        foreach ($keys as $key) {
            if (! array_key_exists($key, $context)) {
                continue;
            }

            $value = $context[$key];

            if (is_scalar($value) || $value === null) {
                $details[$key] = $value;

                continue;
            }

            if (is_array($value) && $this->isFlatScalarList($value)) {
                $details[$key] = $value;
            }
        }

        return $details;
    }

    /**
     * @param  array<array-key, mixed>  $value
     */
    private function isFlatScalarList(array $value): bool
    {
        foreach ($value as $item) {
            if (! is_scalar($item) && $item !== null) {
                return false;
            }
        }

        return true;
    }
}
