<?php

declare(strict_types=1);

namespace App\Services\Betting;

use App\DTOs\Betting\TicketVerificationData;
use App\DTOs\Betting\TicketVerificationResult;
use App\Enums\BetStatus;
use App\Enums\TicketVerificationStatus;
use App\Exceptions\TicketVerificationException;
use App\Models\Draw;
use App\Models\Ticket;

/**
 * Read-only ticket verification: "does this number exist, and in what state".
 *
 * THE GLO STORY IT SERVES
 * Anyone holding a ticket — buyer, a relative checking a shared slip, an agent
 * about to honour a claim — must answer two questions without learning anything
 * else: does this ticket number exist, and is it currently in good standing.
 * That is all this service returns.
 *
 * PRIVACY CONTRACT (enforced here, not by convention)
 * - The public verdict vocabulary is TicketVerificationStatus: valid / pending /
 *   cancelled / expired / not_found. Coarse on purpose.
 * - No amounts, no stake breakdown, no owner identity in public answers.
 * - Money detail (ownerDetail) is attached ONLY when the caller's id matches
 *   the ticket's owner. There is no "exists but belongs to someone else"
 *   answer: for a non-owner, that is NotFound-shaped as well (found=false is
 *   not returned for an existing ticket — the found flag says "this number is
 *   a real ticket", never who owns it).
 * - Verification holds no locks, writes nothing, moves no money.
 */
class TicketVerificationService
{
    /**
     * Verify a ticket number. If $data->ownerUserId matches the ticket owner,
     * money detail is included; otherwise the answer stays coarse.
     *
     * @throws TicketVerificationException only when verification is disabled
     */
    public function verify(TicketVerificationData $data): TicketVerificationResult
    {
        $this->assertEnabled();

        $number = trim($data->ticketNumber);
        if ($number === '') {
            return new TicketVerificationResult(
                status: TicketVerificationStatus::NotFound,
                ticketNumber: $data->ticketNumber,
                found: false,
                checkedAt: now()->toIso8601String(),
            );
        }

        /** @var Ticket|null $ticket */
        $ticket = Ticket::query()
            ->where('ticket_number', $number)
            ->with(['draw', 'bets'])
            ->first();

        if ($ticket === null) {
            return new TicketVerificationResult(
                status: TicketVerificationStatus::NotFound,
                ticketNumber: $number,
                found: false,
                checkedAt: now()->toIso8601String(),
            );
        }

        /** @var Draw|null $draw */
        $draw = $ticket->draw()->first();

        $ownerDetail = null;
        if ($data->ownerUserId !== null && $ticket->user_id === $data->ownerUserId) {
            $ownerDetail = $this->ownerDetail($ticket);
        }

        return new TicketVerificationResult(
            status: TicketVerificationStatus::fromTicketStatus($ticket->status),
            ticketNumber: (string) $ticket->ticket_number,
            found: true,
            drawId: $draw !== null ? (int) $draw->getKey() : null,
            drawNumber: $draw !== null ? (string) $draw->draw_number : null,
            checkedAt: now()->toIso8601String(),
            ownerDetail: $ownerDetail,
        );
    }

    /**
     * Owner-scope detail: totals over the ticket's non-cancelled bets.
     *
     * @return array<string, mixed>
     */
    private function ownerDetail(Ticket $ticket): array
    {
        $total = '0.00';
        $count = 0;
        $numbers = [];

        foreach ($ticket->bets as $bet) {
            if ($bet->status === BetStatus::Cancelled) {
                continue;
            }

            $total = bcadd($total, (string) $bet->stake_amount, 2);
            $count++;
            $numbers[] = (string) $bet->bet_number;
        }

        return [
            'total_amount' => $total,
            'bet_count' => $count,
            'bets' => $numbers,
        ];
    }

    private function assertEnabled(): void
    {
        if (! (bool) config('lottery.verification.enabled', true)) {
            throw TicketVerificationException::disabled();
        }
    }
}
