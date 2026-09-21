<?php

declare(strict_types=1);

namespace App\Services\Lottery;

use App\Enums\GloPrizeTier;
use App\Models\DrawResult;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Checks a GLO 6-digit ticket number against a draw's officially recorded numbers.
 *
 * WHY THIS EXISTS
 * The project's online-operator markets (2d/3d/run) are settled by the betting engine
 * against DERIVED values. The physical GLO ticket pays a different, official ladder —
 * first, adjacent, second, third, fourth, fifth, front 3, last 3, last 2 — that nothing
 * in the settlement pipeline models. This class is the official ticket checker: it maps
 * every recorded winning number onto that ladder and reports every prize tier the
 * ticket has won, with the amounts read from config('glo.prizes').
 *
 * STRING ARITHMETIC ONLY
 * A ticket number is a 6-digit string, never an integer, so a ticket like '000123'
 * keeps its leading zeroes. The adjacent-numbers check works on digit strings with
 * zero padding via bcadd and bcsub, and a resize with sprintf keeps the width, so the
 * neighbours of '000000' are refused (negative / out of range wrap) rather than
 * invented. No float and no integer cast appears anywhere.
 *
 * PURE READER
 * No database write, no state change, no money movement. A winning number is sourced
 * from the draw's draw_results row and the recorded tiers; the result object returned
 * is informational only and never credited to anything.
 */
class GloTicketChecker
{
    /** The physical ticket number width, in digits. */
    public const TICKET_DIGITS = 6;

    /** Adjacent prize rolls this many numbers each side of the first prize. */
    public const ADJACENT_WINDOW = 1;

    private const DIGITS_ONLY = '/^[0-9]+$/';

    public function __construct(
        private readonly ConfigRepository $config,
        private readonly GloResultService $results,
    ) {}

    /**
     * Check one ticket number against one draw's recorded official numbers.
     *
     * @return array{draw_id: int, ticket_number: string, matches: list<array<string, mixed>>, won: bool, total_prize: string}
     */
    public function check(int $drawId, string $ticketNumber): array
    {
        $ticket = $this->normaliseTicket($ticketNumber);

        $records = $this->results->recordedForDraw($drawId);
        $firstPrize = $records['first_prize'] ?? null;

        $matches = [];

        // Every prize tier declared in the official catalogue is considered, so a
        // ticket can win several tiers at once (a first prize ticket is also a last
        // two / last three candidate in a later extension that stores those numbers).
        $prizes = (array) $this->config->get('glo.prizes', []);

        foreach (GloPrizeTier::cases() as $tier) {
            $tierNumbers = $records[$tier->value] ?? [];

            foreach ($tierNumbers as $number) {
                if ($this->matches($tier, $ticket, (string) $number, (string) $firstPrize)) {
                    $entry = $prizes[$tier->value] ?? [];

                    $matches[] = [
                        'tier' => $tier->value,
                        'label' => $tier->label(),
                        'number' => (string) $number,
                        'amount' => (string) ($entry['amount'] ?? '0.00'),
                        'digits' => $tier->digits(),
                        'match_mode' => $tier->matchMode(),
                    ];
                    break;
                }
            }
        }

        $total = '0.00';
        foreach ($matches as $match) {
            $total = bcadd($total, (string) $match['amount'], 2);
        }

        return [
            'draw_id' => $drawId,
            'ticket_number' => $ticket,
            'matches' => $matches,
            'won' => $matches !== [],
            'total_prize' => $total,
        ];
    }

    /**
     * Whether a ticket number matches one candidate under a tier's rule.
     */
    public function matches(GloPrizeTier $tier, string $ticket, string $number, string $firstPrize = ''): bool
    {
        return match ($tier->matchMode()) {
            'exact' => $ticket === $number,
            'neighbor' => $firstPrize !== ''
                && in_array($ticket, $this->adjacentOf($firstPrize), true),
            'prefix' => str_starts_with($ticket, $number),
            'suffix' => str_ends_with($ticket, $number),
            default => false,
        };
    }

    /**
     * The two neighbours of a 6-digit first prize, as digit strings.
     *
     * @return list<string>
     */
    public function adjacentOf(string $firstPrize): array
    {
        $width = self::TICKET_DIGITS;

        // Scale 0 is explicit: the application boots with bcscale(2), and a scale-2
        // increment would produce '456124.00' and strip a leading zero from '001230',
        // both of which would corrupt a digit string. The ticket width is an integer
        // arithmetic domain, so every operation here pins scale 0.
        $below = bcsub($firstPrize, (string) self::ADJACENT_WINDOW, 0);
        $above = bcadd($firstPrize, (string) self::ADJACENT_WINDOW, 0);

        $neighbours = [];

        if (bccomp($below, '0') >= 0) {
            $neighbours[] = sprintf('%0'.$width.'s', $below);
        }

        $upperBound = bcsub(bcpow('10', (string) $width, 0), '1', 0);

        if (bccomp($above, $upperBound) <= 0) {
            $neighbours[] = sprintf('%0'.$width.'s', $above);
        }

        return $neighbours;
    }

    /**
     * Normalise a submitted ticket to a 6-digit string or throw.
     *
     * @throws \InvalidArgumentException
     */
    public function normaliseTicket(mixed $ticketNumber): string
    {
        if (is_int($ticketNumber) || is_float($ticketNumber)) {
            $ticketNumber = (string) $ticketNumber;
        }

        if (! is_string($ticketNumber)) {
            throw new \InvalidArgumentException('The ticket number must be a 6-digit string.');
        }

        $raw = trim($ticketNumber);

        if ($raw === '' || preg_match(self::DIGITS_ONLY, $raw) !== 1) {
            throw new \InvalidArgumentException('The ticket number must contain only digits.');
        }

        // A shorter string is zero padded to the ticket width; a longer one is
        // refused because a physical GLO ticket is exactly six digits.
        if (strlen($raw) > self::TICKET_DIGITS) {
            throw new \InvalidArgumentException(
                sprintf('The ticket number must be at most %d digits.', self::TICKET_DIGITS)
            );
        }

        return sprintf('%0'.self::TICKET_DIGITS.'s', $raw);
    }

    /**
     * Whether a draw has a recorded first prize the checker can use.
     */
    public function drawHasResult(int $drawId): bool
    {
        return DrawResult::query()->where('draw_id', $drawId)->exists();
    }
}
