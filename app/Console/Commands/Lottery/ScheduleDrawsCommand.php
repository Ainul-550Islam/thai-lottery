<?php

declare(strict_types=1);

namespace App\Console\Commands\Lottery;

use Illuminate\Support\Facades\Log;

/**
 * Creates the draws the declared calendar says should exist.
 *
 * Before this command, a draw only existed if somebody inserted a row, even though
 * config('lottery.draw.official_schedule') had declared draws on the 1st and 16th at
 * 15:00 since Phase 1. Nothing read that setting.
 *
 * SAFE TO RUN AS OFTEN AS YOU LIKE. A draw's identifier is derived from its scheduled
 * moment (DR-YYYYMMDD-HHMM), so a second run recomputes the same identifier, finds the
 * existing row and creates nothing. There is no "did it already run today" flag to get
 * out of sync, and two concurrent runs cannot double-create: the unique index on
 * draws.draw_number decides, and the loser treats the duplicate as already-provisioned.
 *
 * It never modifies a draw that exists - not its schedule, not its status, not its
 * betting window. Changing a live draw is an operator decision with bets already
 * placed against it; this command only fills gaps.
 */
final class ScheduleDrawsCommand extends LotteryAutomationCommand
{
    protected $signature = 'lottery:schedule-draws
        {--days= : Override the horizon from config(lottery.automation.horizon_days)}
        {--dry-run : List what would be created without writing anything}
        {--force : Run even when automation is disabled}';

    protected $description = 'Create the upcoming draws declared by config(lottery.draw.official_schedule)';

    protected function activity(): string
    {
        return 'schedule draws';
    }

    public function handle(): int
    {
        if (! $this->assertAutomationAllowed() || ! $this->assertNotInTransaction()) {
            return self::SUCCESS;
        }

        $days = $this->option('days');
        $horizon = is_numeric($days) ? max(1, (int) $days) : null;

        if ((bool) $this->option('dry-run') === true) {
            return $this->reportDryRun($horizon);
        }

        $result = $this->schedule->provision($horizon);
        $created = $result['created'];

        foreach ($created as $draw) {
            $this->line(sprintf(
                '  + %s  draw %s  betting %s -> %s',
                (string) $draw->scheduled_at,
                (string) $draw->draw_number,
                (string) $draw->betting_open_at,
                (string) $draw->betting_close_at,
            ));
        }

        $this->info(sprintf(
            'Provisioned %d new draw(s); %d already existed. Horizon %d day(s), through %s.',
            count($created),
            $result['existing'],
            $result['horizon_days'],
            $result['horizon_until']->toDateTimeString(),
        ));

        Log::info('lottery.automation.provisioned', [
            'created' => count($created),
            'existing' => $result['existing'],
            'horizon_days' => $result['horizon_days'],
        ]);

        return self::SUCCESS;
    }

    /**
     * Show the calendar and which occurrences are missing, writing nothing.
     */
    private function reportDryRun(?int $horizonDays): int
    {
        $horizon = $horizonDays ?? (int) config('lottery.automation.horizon_days', 90);
        $now = now();
        $occurrences = $this->schedule->occurrencesBetween($now, $now->copy()->addDays(max(1, $horizon)));

        if ($occurrences === []) {
            $this->warn(sprintf(
                'The calendar declares no draw in the next %d day(s). Check config(lottery.draw.official_schedule).',
                $horizon,
            ));

            return self::SUCCESS;
        }

        $rows = [];

        foreach ($occurrences as $moment) {
            $drawNumber = $this->schedule->drawNumberFor($moment);

            $rows[] = [
                $moment->toDateTimeString(),
                $drawNumber,
                $this->schedule->bettingCloseAtFor($moment)->toDateTimeString(),
                \App\Models\Draw::withTrashed()->where('draw_number', $drawNumber)->exists() ? 'exists' : 'would create',
            ];
        }

        $this->table(['Scheduled (market time)', 'Draw number', 'Betting closes', 'Action'], $rows);

        return self::SUCCESS;
    }
}
