<?php

namespace App\Enums;

/**
 * The persisted status of a draw, stored in draws.status (varchar(32)).
 *
 * PHASE 5.1 ADDITION
 * ------------------
 * One case was appended: ResultPublished = 'result_published'. It names the state
 * of a draw whose official result is published but whose selections have not yet
 * been settled. Before Phase 5.1 that state did not exist, so "published" and
 * "settled" both had to be stored as Completed, which made it impossible to refuse
 * a second settlement run on the strength of the stored status alone.
 *
 * The change is purely ADDITIVE and needed NO migration, because draws.status is
 * varchar(32) and 'result_published' is 16 characters.
 *
 *   - No existing case was renamed or removed.
 *   - No existing backing value was changed.
 *   - No method was removed and no signature was changed.
 *   - label() and color() use exhaustive match(), so exactly one arm was appended
 *     to each. No existing arm was altered.
 *   - canAcceptBets(), canClose(), canDraw() and isFinal() return for all six
 *     original cases exactly what they returned before, and false for the new
 *     case (a published draw takes no bets, cannot be closed again, cannot be
 *     drawn again, and is not final because settlement still has to run).
 *
 * config('lottery.draw.statuses') is array_column(self::cases(), 'value'), so the
 * new value appears there automatically and no configuration file was edited.
 *
 * The SEVEN state lifecycle required by Phase 5.1, and the transition rules
 * between states, are NOT declared here. They live in App\Enums\DrawLifecycleState,
 * which maps its spec-named states bidirectionally onto these persisted values.
 * This enum remains a plain persistence vocabulary.
 */
enum DrawStatus: string
{
    case Scheduled = 'scheduled';
    case Open = 'open';
    case Closed = 'closed';
    case Drawing = 'drawing';
    case ResultPublished = 'result_published';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Scheduled => 'Scheduled',
            self::Open => 'Open',
            self::Closed => 'Closed',
            self::Drawing => 'Drawing',
            self::ResultPublished => 'Result Published',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
        };
    }

    public function canAcceptBets(): bool
    {
        return $this === self::Open;
    }

    public function canClose(): bool
    {
        return in_array($this, [self::Open, self::Scheduled]);
    }

    public function canDraw(): bool
    {
        return $this === self::Closed;
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::Completed, self::Cancelled]);
    }

    public function color(): string
    {
        return match ($this) {
            self::Scheduled => 'gray',
            self::Open => 'green',
            self::Closed => 'yellow',
            self::Drawing => 'blue',
            self::ResultPublished => 'teal',
            self::Completed => 'green',
            self::Cancelled => 'red',
        };
    }
}
