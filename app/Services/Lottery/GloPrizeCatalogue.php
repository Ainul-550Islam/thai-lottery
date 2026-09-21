<?php

declare(strict_types=1);

namespace App\Services\Lottery;

use App\Enums\GloPrizeTier;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Read-only catalogue of the Government Lottery Office (GLO) official prize rules.
 *
 * WHY THIS WRAPS config('glo') INSTEAD OF REPLACING IT
 * Amounts, winner counts and claim rules are data, so they live in config/glo.php.
 * This class is the single reader that turns that data into a stable, structured
 * payload the API, the Filament page and any report can share. No caller reads the
 * raw config keys directly, which keeps money-related phrasing in one place.
 *
 * STRUCTURE ONLY, NO MONEY MATH
 * No amount is added, multiplied or rounded here: every baht value is returned
 * exactly as declared. The class performs no database access and knows nothing
 * about draws, results or tickets.
 */
class GloPrizeCatalogue
{
    public function __construct(private readonly ConfigRepository $config) {}

    /**
     * The full official prize schedule, tier by tier.
     *
     * @return list<array<string, mixed>>
     */
    public function prizes(): array
    {
        $declared = (array) $this->config->get('glo.prizes', []);

        $prizes = [];

        foreach (GloPrizeTier::cases() as $tier) {
            $entry = $declared[$tier->value] ?? [];

            $prizes[] = [
                'tier' => $tier->value,
                'label' => $tier->label(),
                'amount' => (string) ($entry['amount'] ?? '0.00'),
                'winners' => (int) ($entry['winners'] ?? 0),
                'digits' => $tier->digits(),
                'match_mode' => $tier->matchMode(),
                'tax_withheld' => (bool) ($entry['tax_withheld'] ?? false),
                'min_claim_venue' => (string) ($entry['min_claim_venue'] ?? 'any_bank'),
            ];
        }

        return $prizes;
    }

    /**
     * The claim, tax and ticket rules in report form.
     *
     * @return array<string, mixed>
     */
    public function claimRules(): array
    {
        return (array) $this->config->get('glo.claim', []);
    }

    /**
     * The physical government ticket rules.
     *
     * @return array<string, mixed>
     */
    public function ticketRules(): array
    {
        return (array) $this->config->get('glo.ticket', []);
    }

    /**
     * The GLO draw calendar rules.
     *
     * @return array<string, mixed>
     */
    public function drawCalendar(): array
    {
        return (array) $this->config->get('glo.draw_calendar', []);
    }

    /**
     * The whole catalogue as one payload for the API and the admin page.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return [
            'prizes' => $this->prizes(),
            'claim' => $this->claimRules(),
            'ticket' => $this->ticketRules(),
            'draw_calendar' => $this->drawCalendar(),
        ];
    }
}
