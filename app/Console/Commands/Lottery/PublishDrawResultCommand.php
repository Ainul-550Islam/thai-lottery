<?php

declare(strict_types=1);

namespace App\Console\Commands\Lottery;

use App\Models\Draw;
use App\Services\Draw\DrawResultPublicationService;
use App\Services\Draw\DrawScheduleService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Publishes the official result of one draw. OPERATOR COMMAND, NEVER SCHEDULED.
 *
 * WHY THIS IS NOT AUTOMATED AND WILL NOT BE
 * The winning numbers of a Thai government lottery draw are produced outside this
 * system. Nothing in this codebase can know them, and inventing them would turn a
 * lottery into a random number generator with a settlement engine attached.
 * config('lottery.results.require_admin_confirmation') is true, so a human enters the
 * numbers and takes responsibility for them. The scheduler therefore advances a draw
 * to result-pending and stops; this command is the only path onward, and the schedule
 * in bootstrap/app.php deliberately does not register it.
 *
 * Until a Filament admin panel exists (Phase 6, not started), this command IS the
 * operator surface for publication.
 *
 * WHAT IT DOES NOT DO
 * It does not validate the numbers itself - DrawResultValidator does, and it refuses
 * anything that is not a digit string of the configured length. It does not write the
 * result rows - DrawResultPublicationService does, atomically, together with the
 * lifecycle transition, so a published draw always has a draw_results row. It does not
 * settle: settlement is a separate step with its own delay, precisely so a mistyped
 * result can be caught before the draw becomes terminal.
 */
final class PublishDrawResultCommand extends Command
{
    protected $signature = 'lottery:publish-result
        {draw : The draw id or draw number}
        {--first-prize= : The official first prize, a digit string of the configured length}
        {--bottom-two= : The official bottom two digits}
        {--yes : Skip the confirmation prompt (for a non-interactive operator script)}';

    protected $description = 'Publish the official result of one draw (operator command; never scheduled)';

    public function __construct(
        private readonly DrawResultPublicationService $publication,
        private readonly DrawScheduleService $schedule,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $draw = $this->resolveDraw((string) $this->argument('draw'));

        if (! $draw instanceof Draw) {
            $this->error(sprintf('No draw matches "%s".', (string) $this->argument('draw')));

            return self::FAILURE;
        }

        $firstPrize = $this->option('first-prize');
        $bottomTwo = $this->option('bottom-two');

        // Prompted rather than defaulted. There is no sensible default for a lottery
        // result, and a command that could publish one without being told the numbers
        // would be a defect.
        if (! is_string($firstPrize) || trim($firstPrize) === '') {
            $firstPrize = (string) $this->ask(sprintf(
                'Official first prize (%d digits)',
                (int) config('lottery.results.first_prize_digits', 6),
            ));
        }

        if (! is_string($bottomTwo) || trim($bottomTwo) === '') {
            $bottomTwo = (string) $this->ask('Official bottom two digits');
        }

        $firstPrize = trim($firstPrize);
        $bottomTwo = trim($bottomTwo);

        $this->table(
            ['Draw', 'Scheduled', 'Status', 'First prize', 'Bottom two'],
            [[
                (string) $draw->draw_number,
                (string) $draw->scheduled_at,
                (string) $draw->status->value,
                $firstPrize,
                $bottomTwo,
            ]],
        );

        $this->warn('Publication is not reversible: this project implements nothing reversal-shaped, and a published draw cannot be cancelled.');

        if ((bool) $this->option('yes') !== true && ! $this->confirm('Publish these numbers as the official result?', false)) {
            $this->line('Cancelled. Nothing was published.');

            return self::SUCCESS;
        }

        try {
            $published = $this->publication->publish((int) $draw->getKey(), [
                'first_prize' => $firstPrize,
                'bottom_two' => $bottomTwo,
            ]);
        } catch (Throwable $exception) {
            // The refusal is the point of the message. An operator on a terminal needs
            // to read why the numbers were rejected, and the validator's message says
            // exactly which rule failed.
            $this->error($exception->getMessage());

            Log::warning('lottery.publication.refused', [
                'draw_id' => (int) $draw->getKey(),
                'draw_number' => (string) $draw->draw_number,
                'exception' => $exception,
            ]);

            return self::FAILURE;
        }

        $winningNumbers = $published['winning_numbers'];

        $this->info(sprintf(
            'Published draw %s: first prize %s, bottom two %s, %d winning number row(s).',
            (string) $draw->draw_number,
            $firstPrize,
            $bottomTwo,
            count($winningNumbers),
        ));

        Log::info('lottery.publication.published', [
            'draw_id' => (int) $draw->getKey(),
            'draw_number' => (string) $draw->draw_number,
            'winning_numbers' => count($winningNumbers),
            'operator_command' => true,
        ]);

        $delay = (int) config('lottery.automation.settlement_delay_minutes', 0);

        if ($this->schedule->autoSettleEnabled() && $this->schedule->automationEnabled()) {
            $this->line(sprintf(
                'Automatic settlement will run in about %d minute(s). To settle now: php artisan lottery:settle-draws --draw=%s',
                $delay,
                (string) $draw->draw_number,
            ));
        } else {
            $this->line(sprintf(
                'Automatic settlement is off. To settle: php artisan lottery:settle-draws --draw=%s',
                (string) $draw->draw_number,
            ));
        }

        return self::SUCCESS;
    }

    private function resolveDraw(string $identifier): ?Draw
    {
        return Draw::query()
            ->where('draw_number', $identifier)
            ->when(is_numeric($identifier), fn ($query) => $query->orWhere('id', (int) $identifier))
            ->first();
    }
}
