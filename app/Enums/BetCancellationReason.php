<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Why a bet was cancelled.
 *
 * The reason is recorded verbatim on bets.cancelled_reason and drives whether the
 * cancellation could carry a fee later: a player-initiated reason is the player's
 * own decision, while operator/draw reasons are platform events that must never
 * be charged to the player.
 *
 * VALUES ARE PERSISTED
 * The backing string is stored in bets.cancelled_reason and surfaced through the
 * API, so a value is forever once shipped. Add new cases at the end; never rename
 * or remove one.
 */
enum BetCancellationReason: string
{
    case PlayerRequest = 'player_request';
    case DuplicateBet = 'duplicate_bet';
    case WrongNumber = 'wrong_number';
    case WrongStake = 'wrong_stake';
    case Amendment = 'amendment';
    case Operator = 'operator';
    case DrawCancelled = 'draw_cancelled';

    public function label(): string
    {
        return match ($this) {
            self::PlayerRequest => 'Cancelled by player',
            self::DuplicateBet => 'Duplicate bet',
            self::WrongNumber => 'Wrong number entered',
            self::WrongStake => 'Wrong stake entered',
            self::Amendment => 'Replaced by amendment',
            self::Operator => 'Cancelled by operator',
            self::DrawCancelled => 'Draw was cancelled',
        };
    }

    /**
     * Whether the reason originates with the player (true) or with the platform
     * (false). Platform reasons must always refund in full.
     */
    public function isPlayerInitiated(): bool
    {
        return in_array($this, [
            self::PlayerRequest,
            self::DuplicateBet,
            self::WrongNumber,
            self::WrongStake,
            self::Amendment,
        ], true);
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
