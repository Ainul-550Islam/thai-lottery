<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The Government Lottery Office (GLO) official prize tiers.
 *
 * WHY THIS ENUM IS THE SINGLE VOCABULARY
 * config/glo.php keys its prize schedule by these backing values,
 * App\Services\Lottery\GloResultService reads recorded numbers per tier,
 * App\Services\Lottery\GloPrizeCatalogue iterates cases() to build the admin
 * and API catalogue, and App\Services\Lottery\GloTicketChecker matches a
 * 6-digit ticket per tier through matchMode(). One tier named differently in
 * any of those places would silently drop a prize category, so all four read
 * this one enum and nothing else may name a tier.
 *
 * OFFICIAL LADDER (standard draw, per ticket)
 *   first           1 number  — the full 6-digit first prize
 *   adjacent_first  2 numbers — first prize number +/- 1 (derived, never recorded)
 *   second          5 numbers — exact 6-digit
 *   third          10 numbers — exact 6-digit
 *   fourth         50 numbers — exact 6-digit
 *   fifth         100 numbers — exact 6-digit
 *   front_three     2 numbers — the ticket's FIRST 3 digits
 *   last_three      2 numbers — the ticket's LAST 3 digits
 *   last_two        1 number  — the ticket's LAST 2 digits
 *
 * VALUES ARE PERSISTED AND PUBLISHED
 * Backing values appear in draw_results.metadata tier payloads and in the
 * public API (/api/v1/glo/*). Add new tiers at the end; never rename or remove
 * a value, because recorded results hold these strings.
 */
enum GloPrizeTier: string
{
    case First = 'first';
    case AdjacentFirst = 'adjacent_first';
    case Second = 'second';
    case Third = 'third';
    case Fourth = 'fourth';
    case Fifth = 'fifth';
    case FrontThree = 'front_three';
    case LastThree = 'last_three';
    case LastTwo = 'last_two';

    public function label(): string
    {
        return match ($this) {
            self::First => 'First Prize',
            self::AdjacentFirst => 'Adjacent First Prize',
            self::Second => 'Second Prize',
            self::Third => 'Third Prize',
            self::Fourth => 'Fourth Prize',
            self::Fifth => 'Fifth Prize',
            self::FrontThree => 'Front Three Digits',
            self::LastThree => 'Last Three Digits',
            self::LastTwo => 'Last Two Digits',
        };
    }

    /**
     * How many digits of the ticket this tier compares.
     */
    public function digits(): int
    {
        return match ($this) {
            self::First,
            self::AdjacentFirst,
            self::Second,
            self::Third,
            self::Fourth,
            self::Fifth => 6,
            self::FrontThree,
            self::LastThree => 3,
            self::LastTwo => 2,
        };
    }

    /**
     * How a ticket number is compared against a recorded winning number:
     *   exact    — the whole ticket equals the recorded number
     *   neighbor — the ticket is first_prize +/- 1 (GloTicketChecker computes
     *              the neighbours with scale-0 bcmath, never integer casts)
     *   prefix   — the ticket's leading digits equal the recorded number
     *   suffix   — the ticket's trailing digits equal the recorded number
     */
    public function matchMode(): string
    {
        return match ($this) {
            self::First,
            self::Second,
            self::Third,
            self::Fourth,
            self::Fifth => 'exact',
            self::AdjacentFirst => 'neighbor',
            self::FrontThree => 'prefix',
            self::LastThree,
            self::LastTwo => 'suffix',
        };
    }

    /**
     * Whether the tier's numbers must be recorded for the draw (false only for
     * the adjacent tier, which is derived from the first prize at read time).
     */
    public function isRecorded(): bool
    {
        return $this !== self::AdjacentFirst;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
