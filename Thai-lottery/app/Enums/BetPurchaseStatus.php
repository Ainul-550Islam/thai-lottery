<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The terminal outcome of one atomic bet purchase attempt.
 *
 * WHY THIS ENUM EXISTS AND WHAT IT IS NOT
 * This is the status of the PURCHASE REQUEST, not the status of the bet. The bet
 * itself carries App\Enums\BetStatus and the ticket carries App\Enums\TicketStatus;
 * neither of those vocabularies can express "this request was a replay of an
 * earlier request", which is exactly the distinction a caller of the purchase
 * pipeline must be able to make. Reusing BetStatus for that would corrupt the
 * settlement vocabulary that Phase 5 depends on.
 *
 * WHY THERE IS NO "FAILED" CASE
 * A failed purchase never returns a value. Every failure path throws an
 * App\Exceptions\BetPurchaseException subclass so that the surrounding database
 * transaction is unwound by the exception itself. If "failed" were representable
 * here, a caller could receive a result object describing a purchase that had
 * partially mutated the wallet, and there would be no language-level guarantee
 * that the rollback happened. Both cases below therefore describe a COMMITTED,
 * fully consistent state.
 *
 * WHY THERE IS NO "PENDING" CASE
 * The purchase pipeline is synchronous and atomic. There is no moment at which a
 * purchase is half-made and awaiting something: either the transaction committed
 * (Purchased or Replayed) or it rolled back (exception). A pending case would
 * invite a caller to poll for an outcome that cannot exist.
 */
enum BetPurchaseStatus: string
{
    /**
     * A new bet, bet item, ticket, wallet debit and ledger posting were created
     * and committed by THIS request.
     */
    case Purchased = 'purchased';

    /**
     * This request carried an idempotency key that had already been used by an
     * earlier successful purchase. Nothing was created; the earlier bet, ticket,
     * wallet debit and ledger posting are returned unchanged. No second debit and
     * no second ledger movement occurred.
     */
    case Replayed = 'replayed';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::Purchased => 'Purchased',
            self::Replayed => 'Replayed (idempotent)',
        };
    }

    /**
     * True when this request created nothing because an earlier identical request
     * had already succeeded.
     */
    public function isReplay(): bool
    {
        return $this === self::Replayed;
    }

    /**
     * True when this request performed the financial mutation itself.
     */
    public function isNewPurchase(): bool
    {
        return $this === self::Purchased;
    }
}
