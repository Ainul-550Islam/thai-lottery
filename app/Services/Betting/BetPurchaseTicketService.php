<?php

declare(strict_types=1);

namespace App\Services\Betting;

use App\DTOs\BetPurchaseContext;
use App\Enums\TicketStatus;
use App\Exceptions\BetPurchaseException;
use App\Models\Ticket;
use Illuminate\Support\Facades\DB;

/**
 * Creates the Ticket for a purchase, exactly once.
 *
 * WHY ONE TICKET PER PURCHASE, AND WHY 3D TOD IS STILL ONE TICKET
 * A ticket is the player's receipt for what they bought. One purchase request is one
 * receipt. A 3D Tod selection on '123' is covered by six arrangements of the digits, and
 * it produces ONE ticket - not six - because the player made one selection and paid once.
 * Six tickets would tell the player they had bought six things, would make the ticket
 * totals six times the money taken, and would each look independently cancellable.
 *
 * WHY Ticket::create() IS NOT USED
 * status, total_amount, total_bets, total_numbers, issued_at and uuid are all outside
 * Ticket::$fillable in Phase 1, which is what stops a ticket being mass-assigned into
 * existence already confirmed and already carrying a total that nobody computed. Only
 * ticket_number, user_id, draw_id, currency and metadata are fillable, so Ticket::create()
 * physically cannot write the totals or the status. They are assigned here explicitly,
 * one at a time, from the validated context.
 *
 * WHY THE TOTAL IS THE STAKE, AS A STRING
 * total_amount is DECIMAL(20,2) and is set from the same exact decimal stake the wallet
 * is debited, as a string. So a 10.55 stake produces a ticket total of exactly 10.55 -
 * the receipt and the debit are the same number by construction, not by two independent
 * calculations that happen to agree. No float and no rounding is involved.
 *
 * WHY THE TICKET IS CREATED BEFORE THE BET IS LINKED, AND BEFORE THE DEBIT
 * The Phase 1 foreign key is bets.ticket_id: one ticket holds many bets, and there is no
 * tickets.bet_id column. The ticket must therefore exist before a bet can point at it.
 * Both happen inside the purchase transaction, so a rollback removes the ticket too and
 * an unpaid ticket can never be observed by anything outside the transaction.
 *
 * WHY THE TICKET IS ISSUED PENDING AND CONFIRMED LATER
 * A ticket created before the money moves is not yet paid for. It is issued as Pending
 * and promoted to Confirmed by confirm() only after the wallet debit and the ledger
 * verification have both succeeded, so a Confirmed ticket always has settled money and a
 * balanced posting behind it.
 */
final class BetPurchaseTicketService
{
    /**
     * One purchase is one bet on one number.
     */
    public const BETS_PER_PURCHASE = 1;

    public const NUMBERS_PER_PURCHASE = 1;

    public function __construct(
        private readonly BetPurchaseReferenceService $references,
    ) {
    }

    /**
     * Persist the ticket for a validated purchase.
     *
     * @throws BetPurchaseException
     */
    public function create(BetPurchaseContext $context): Ticket
    {
        $this->assertInsideTransaction('create a ticket');

        $ticket = new Ticket();

        $ticket->fill([
            'ticket_number' => $this->references->ticketNumber(),
            'user_id' => $context->userId(),
            'draw_id' => $context->drawId(),
            'currency' => $context->currency()->value,
            'metadata' => $this->metadataFor($context),
        ]);

        // Guarded columns, each assigned from a server-derived value.
        $ticket->status = TicketStatus::Pending;
        $ticket->total_amount = $context->stake->amount();
        $ticket->total_bets = self::BETS_PER_PURCHASE;
        $ticket->total_numbers = self::NUMBERS_PER_PURCHASE;
        $ticket->issued_at = now();
        $ticket->uuid = $this->references->uuid();

        $ticket->save();

        return $ticket;
    }

    /**
     * Promote a paid-for ticket to Confirmed.
     *
     * @throws BetPurchaseException
     */
    public function confirm(Ticket $ticket): Ticket
    {
        $this->assertInsideTransaction('confirm a ticket');

        $ticket->status = TicketStatus::Confirmed;
        $ticket->confirmed_at = now();
        $ticket->save();

        return $ticket;
    }

    /**
     * The ticket's metadata: what the receipt is for.
     *
     * @return array<string, mixed>
     */
    public function metadataFor(BetPurchaseContext $context): array
    {
        $metadata = [
            'market' => $context->marketKey,
            'bet_type' => $context->betType->value,
            'number' => $context->canonicalNumber(),
            'stake' => $context->stake->amount(),
            'potential_payout' => $context->potentialPayout->toString(),
            'idempotency_key' => $context->betIdempotencyKey,
            'purchase_phase' => '4.3',
        ];

        if ($context->isTod()) {
            $metadata['covered_numbers'] = $context->permutations;
            $metadata['covered_number_count'] = $context->permutationCount();
            // Spelled out on the receipt itself: covering several arrangements did not
            // multiply what the player paid.
            $metadata['tickets_for_selection'] = self::BETS_PER_PURCHASE;
        }

        return $metadata;
    }

    /**
     * @throws BetPurchaseException
     */
    private function assertInsideTransaction(string $operation): void
    {
        if (DB::transactionLevel() < 1) {
            throw BetPurchaseException::outsideTransaction($operation);
        }
    }
}
