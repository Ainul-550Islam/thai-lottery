<?php

declare(strict_types=1);

namespace App\DTOs\Finance;

use App\Enums\Currency;
use App\Enums\PrizePayoutMethod;

/**
 * Immutable input for a payout REQUEST: the assertion that a specific prize
 * obligation should be discharged to a specified beneficiary by a specified
 * channel.
 *
 * WHY IMMUTABLE
 * -------------
 * A payout request enters the maker/checker lane. Anything it carries is
 * evidence for both the checker's decision and the eventual audit of the
 * disbursement; a reference the checker saw must equal the reference the
 * audit later reads. Mutability between the two sights is exactly how
 * undetectable frauds are made. All fields therefore readonly; a different
 * request is a NEW request.
 *
 * THE IDEMPOTENCY IDENTITY
 * ------------------------
 * requestKey is the deterministic idempotency anchor of the operation. The
 * maker/checker service refuses to create two identical requests under one
 * key; it derives from (prize reference + amount + beneficiary) so the same
 * obligation can never be submitted twice with a fake new identity, and
 * retries across workers land on the same key claim.
 */
final class PayoutRequestData
{
    /**
     * @param  string  $prizeReference  The identity of the prize obligation
     *                                  being discharged (claim reference,
     *                                  payout row reference_number, or an
     *                                  explicit manual reference).
     * @param  string  $amount  Decimal string of money to disburse; bcmath-
     *                          scale-2 exactness everywhere downstream.
     * @param  array<string, mixed>|null  $beneficiary  Destination details for
     *                                                  the channel: wallet id
     *                                                  (wallet), bank rails
     *                                                  metadata (transfer), or
     *                                                  payee name/address (cheque).
     * @param  array<string, mixed>  $context  Safe diagnostic context (claim id, draw id, bet id...).
     */
    public function __construct(
        public readonly string $prizeReference,
        public readonly string $amount,
        public readonly Currency $currency,
        public readonly PrizePayoutMethod $method,
        public readonly int $requesterUserId,
        public readonly ?int $beneficiaryUserId,
        public readonly ?array $beneficiary,
        public readonly ?string $reason,
        public readonly string $requestKey,
        public readonly array $context = [],
    ) {
    }

    /**
     * Build a deterministic request key from the request's obligation anchor
     * — the same prize can never be requested twice under a new key, and two
     * callers racing the same key arrive at one record.
     */
    public static function deriveRequestKey(
        string $prizeReference,
        string $amount,
        Currency $currency,
        int $beneficiaryUserId,
    ): string {
        return hash('sha256', sprintf(
            'payout-request:%s:%s:%s:%d',
            $prizeReference,
            bcadd($amount, '0.00', 2),
            $currency->value,
            $beneficiaryUserId,
        ));
    }

    /**
     * Satisfy the amount is a well-formed 2-decimal decimal string. Decimals
     * never go through float anywhere below.
     */
    public function amountIsWellFormed(): bool
    {
        return preg_match('/^\d+(\.\d{1,2})?$/', $this->amount) === 1;
    }

    /**
     * The audit/report payload: the request in projection form. Personal
     * details of the beneficiary (address, account numbers) ARE part of the
     * audit evidence and are included — the audit scrubbing layer is the
     * only thing that redacts classified fields further downstream.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'prize_reference' => $this->prizeReference,
            'amount' => $this->amount,
            'currency' => $this->currency->value,
            'method' => $this->method->value,
            'requester_user_id' => $this->requesterUserId,
            'beneficiary_user_id' => $this->beneficiaryUserId,
            'beneficiary' => $this->beneficiary,
            'reason' => $this->reason,
            'request_key' => $this->requestKey,
            'context' => $this->context,
        ];
    }
}
