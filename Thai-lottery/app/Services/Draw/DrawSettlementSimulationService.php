<?php

declare(strict_types=1);

namespace App\Services\Draw;

use App\DTOs\DrawResultData;
use App\DTOs\SettlementSelectionResult;
use App\DTOs\SettlementSimulationResult;
use App\Enums\AuditAction;
use App\Enums\BetStatus;
use App\Enums\Currency;
use App\Enums\DrawLifecycleState;
use App\Enums\MarketResultType;
use App\Enums\RiskLevel;
use App\Enums\SettlementSimulationStatus;
use App\Exceptions\DrawLifecycleException;
use App\Exceptions\DrawResultValidationException;
use App\Exceptions\SettlementSimulationException;
use App\Models\AuditLog;
use App\Models\Bet;
use App\Models\BetItem;
use App\Models\Draw;
use App\Models\DrawResult;
use App\Models\WinningNumber;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\DB;

/**
 * Settles every selection of a published draw as a NON-MONETARY SIMULATION.
 *
 * WHAT THIS IS NOT
 * It is not a payout processor. It does not credit a wallet, debit a wallet, create
 * a wallet hold, write a ledger entry, create a financial transaction, create a
 * payouts row, create a deposit, create a withdrawal, call a payment gateway or
 * transfer crypto. There is no import of anything under App\Services\Finance except
 * the pure BCMath value type App\Services\Finance\Money reached indirectly through
 * App\ValueObjects\BetAmount, and bets.payout_id is left NULL forever, because a
 * payouts row is a REAL MONEY obligation carrying a wallet_id and a
 * financial_transaction_id.
 *
 * MODE IS A CONSTANT, NOT A SETTING
 * self::MODE is 'simulation' and is a class constant. There is deliberately no
 * configuration key, environment variable or method parameter that could switch this
 * service into a real money mode, so no deployment can turn it into one by accident.
 *
 * WHAT IT WRITES, AND WHY EACH IS NOT MONEY
 *   bet_items.is_winner           the decision, a reporting flag on the bet line
 *   bet_items.actual_payout       the SIMULATED prize, a reporting decimal column
 *   bet_items.payout_multiplier   the configured rate that was applied
 *   bets.status                   Won or Lost
 *   bets.actual_payout            the summed simulated prize of the bet's selections
 *   bets.won_at                   when a winning bet was settled
 *   draws.status                  ResultPublished -> Settled, plus completed_at
 *   audit_logs                    one row recording the run
 * None of those is a balance, a ledger account, a financial transaction or a payout
 * obligation. The wallets, financial_transactions, ledger_entries, ledger_accounts,
 * payouts, deposits, withdrawals, payments and agent_commissions tables are NEVER
 * written by this service. wallets.balance, wallets.locked_balance and wallets.total_won
 * are left exactly as the purchase left them.
 *
 * ATOMIC: ALL OR NOTHING
 * The whole run is one database transaction. A failure anywhere - an unresolvable
 * market, a contradictory market, a rate that will not fit its column, a stake that
 * cannot be read - rolls back every bet_items update, every bets update and the state
 * change together. There is no partial settlement. Because the rollback guarantee
 * must belong to this service, it REFUSES to run inside a caller's transaction, the
 * same guard the verified App\Services\Betting\BetPurchaseTransactionService applies.
 *
 * IDEMPOTENT, FROM THE DATABASE AND NOT FROM A CACHE
 * Settled is a terminal lifecycle state reachable only from ResultPublished. A second
 * call locks the draw, sees Settled, WRITES NOTHING, and returns the stored outcome
 * with alreadySettled = true and selectionsWritten = 0. Nothing here consults a cache,
 * a lock file or an in-memory flag. The guarantee rests on the stored draw status, the
 * row lock, and the unique keys on draw_results.draw_id and winning_numbers.
 *
 * CONCURRENCY
 * Two simultaneous runs serialise on SELECT ... FOR UPDATE of the draw row. The first
 * settles and commits; the second then reads Settled and becomes the no-op path. Lock
 * order is DRAW -> DRAW_RESULT -> BETS -> BET_ITEMS, strictly narrower than the
 * existing finance order WALLET -> FINANCIAL ENTITY -> LEDGER ACCOUNTS, and disjoint
 * from it, so it cannot deadlock against a purchase.
 *
 * NO CLIENT INPUT AT ALL
 * settle() takes an integer draw id and nothing else. There is no amount parameter,
 * no winner parameter, no multiplier parameter and no force, override, skip or bypass
 * parameter. Decisions come from the verified Phase 4.2 rules, prizes from
 * configuration, and the drawn numbers from the published draw_results row.
 *
 * NO RAW SQL
 * Every read and write goes through Eloquent. There is no DB::statement, DB::raw,
 * DB::select, DB::update or DB::unprepared call.
 */
class DrawSettlementSimulationService
{
    /**
     * The only mode this service has. A constant, so nothing can change it.
     */
    public const MODE = 'simulation';

    /**
     * Tables this service is forbidden to write, named so the intent is testable.
     *
     * Every name here is a table this project actually creates, so a test can assert
     * both that the table exists and that the run left it untouched.
     *
     * @var list<string>
     */
    public const FORBIDDEN_TABLES = [
        'wallets',
        'financial_transactions',
        'ledger_entries',
        'ledger_accounts',
        'payouts',
        'deposits',
        'withdrawals',
        'payments',
        'agent_commissions',
    ];

    public function __construct(
        private readonly ConfigRepository $config,
        private readonly DrawLifecycleService $lifecycle,
        private readonly DrawResultValidator $validator,
        private readonly SelectionSettlementResolver $selections,
    ) {}

    /**
     * Settle one published draw as a simulation.
     *
     * Takes a draw id and nothing else, on purpose.
     *
     * @throws DrawLifecycleException
     * @throws SettlementSimulationException
     */
    public function settle(int $drawId): SettlementSimulationResult
    {
        if (DB::transactionLevel() > 0) {
            // The rollback guarantee must belong to this run. Inside a caller's
            // transaction a failure here could be swallowed by an outer catch and the
            // partial writes committed anyway.
            throw SettlementSimulationException::alreadyRunning($drawId, DB::transactionLevel());
        }

        return DB::transaction(function () use ($drawId): SettlementSimulationResult {
            $draw = $this->lifecycle->lockForUpdate($drawId);
            $stateBefore = $this->lifecycle->currentState($draw);

            if ($stateBefore->isSettled()) {
                // Already settled: read back, write nothing.
                return $this->replayStoredSettlement($draw, $stateBefore);
            }

            $this->lifecycle->assertCanSettle($draw, ['stage' => 'settlement']);

            return $this->performSettlement($draw, $stateBefore);
        });
    }

    /**
     * The stored outcome of an already settled draw, without writing.
     *
     * @throws DrawLifecycleException
     * @throws SettlementSimulationException
     */
    public function storedSettlement(int $drawId): SettlementSimulationResult
    {
        $draw = Draw::query()->whereKey($drawId)->first();

        if (! $draw instanceof Draw) {
            throw DrawLifecycleException::notFound($drawId);
        }

        $state = $this->lifecycle->currentState($draw);

        if (! $state->isSettled()) {
            throw DrawLifecycleException::notSettleable($drawId, $state, ['stage' => 'stored_settlement']);
        }

        return $this->replayStoredSettlement($draw, $state);
    }

    /**
     * The published result of a draw, rebuilt as a validated value object.
     *
     * Re-validated on the way out of the database rather than trusted, so a row that
     * was somehow written with a malformed first prize refuses settlement instead of
     * settling every selection against a broken value.
     *
     * @throws DrawLifecycleException
     * @throws DrawResultValidationException
     */
    public function publishedResultFor(Draw $draw): DrawResultData
    {
        $drawId = (int) $draw->getKey();

        $result = DrawResult::query()->where('draw_id', $drawId)->first();

        if (! $result instanceof DrawResult) {
            throw DrawLifecycleException::resultMissing($drawId, $this->lifecycle->currentState($draw));
        }

        $metadata = is_array($result->metadata) ? $result->metadata : [];
        $bottomTwoKey = $this->validator->bottomTwoMetadataKey();

        $data = $this->validator->validate([
            'first_prize' => $result->first_prize,
            'bottom_two' => $metadata[$bottomTwoKey] ?? null,
        ]);

        $this->assertResultIntegrity($drawId, $data);

        return $data;
    }

    /**
     * Refuse a result that no longer agrees with its own published winning numbers.
     *
     * WHY THIS EXISTS
     * ---------------
     * The authoritative result of a draw is deliberately stored in two places.
     * draw_results holds the raw six digit first prize and, in metadata, the bottom
     * two. winning_numbers holds the DERIVED per market winning value of every
     * configured market. App\Services\Draw\DrawResultPublicationService writes both
     * inside a single transaction, so the moment publication commits, the derived
     * values in winning_numbers are exactly what this method recomputes.
     *
     * Neither table carries a database level immutability constraint, and
     * App\Models\WinningNumber is soft deletable. So an UPDATE on draw_results, an
     * edit of the bottom two inside draw_results.metadata, an UPDATE on a
     * winning_numbers row, or a soft delete of one, can all leave the pair
     * disagreeing. Settling in that condition would decide winners from a number
     * the published record does not contain, and the replay path would then compare
     * stored bet_items against a different result than the one they were settled
     * from. Both paths call publishedResultFor(), so both are covered by this one
     * check.
     *
     * The check is read only and issues one SELECT. It changes no passing rule: for
     * every result published through DrawResultPublicationService it passes by
     * construction, which the Phase 5.1 suite plus the follow-up suite both prove.
     *
     * @throws SettlementSimulationException
     */
    private function assertResultIntegrity(int $drawId, DrawResultData $data): void
    {
        /** @var list<WinningNumber> $rows */
        $rows = WinningNumber::query()
            ->where('draw_id', $drawId)
            ->orderBy('id')
            ->get()
            ->all();

        if ($rows === []) {
            throw SettlementSimulationException::resultTampered(
                $drawId,
                'no published winning number row exists for it, so the published result cannot be '
                .'confirmed against the per market numbers it was published with',
            );
        }

        $tiersSeen = [];

        foreach ($rows as $row) {
            $tier = $row->prize_tier;

            $resultType = is_string($tier) ? MarketResultType::tryFrom($tier) : null;

            if (! $resultType instanceof MarketResultType) {
                throw SettlementSimulationException::resultTampered(
                    $drawId,
                    sprintf(
                        'published winning number %d carries prize tier %s, which is not a known market '
                        .'result type',
                        (int) $row->getKey(),
                        $tier === null ? 'NULL' : $tier,
                    ),
                    ['winning_number_id' => (int) $row->getKey()],
                );
            }

            $tiersSeen[$resultType->value] = true;

            $expected = $data->valueFor($resultType);
            $stored = (string) $row->number;

            // Strict string comparison. '07' and '7' are different published numbers
            // and a numeric comparison would call them equal.
            if ($stored !== $expected) {
                throw SettlementSimulationException::resultTampered(
                    $drawId,
                    sprintf(
                        'published winning number %d for prize tier %s stores %s, but the stored result '
                        .'derives %s for that tier',
                        (int) $row->getKey(),
                        $resultType->value,
                        $stored,
                        $expected,
                    ),
                    [
                        'winning_number_id' => (int) $row->getKey(),
                        'prize_tier' => $resultType->value,
                        'stored_number' => $stored,
                        'derived_number' => $expected,
                    ],
                );
            }
        }

        foreach (MarketResultType::cases() as $resultType) {
            if (! isset($tiersSeen[$resultType->value])) {
                throw SettlementSimulationException::resultTampered(
                    $drawId,
                    sprintf(
                        'no published winning number remains for prize tier %s, so a market of that tier '
                        .'has no official number to be settled against',
                        $resultType->value,
                    ),
                    ['missing_prize_tier' => $resultType->value],
                );
            }
        }
    }

    /**
     * The settlement rules in report form.
     *
     * @return array<string, mixed>
     */
    public function audit(): array
    {
        return [
            'mode' => self::MODE,
            'mode_is_constant' => true,
            'mode_config_key' => null,
            'requires_state' => DrawLifecycleState::ResultPublished->value,
            'produces_state' => DrawLifecycleState::Settled->value,
            'tables_written' => ['bet_items', 'bets', 'draws', 'audit_logs'],
            'tables_read_only' => ['draw_results', 'winning_numbers', 'markets', 'market_rules'],
            'tables_forbidden' => self::FORBIDDEN_TABLES,
            'bets_payout_id' => 'left NULL always; a payouts row is a real money obligation',
            'lock_order' => 'DRAW -> DRAW_RESULT -> BETS -> BET_ITEMS',
            'idempotency_source' => 'the stored draws.status plus SELECT ... FOR UPDATE; no cache',
            'guarantees' => $this->guarantees(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function guarantees(): array
    {
        return [
            'non_monetary' => 'No wallet balance is credited or debited, no wallet hold is created, no '
                .'ledger entry is written, no financial transaction is created, no payouts row is '
                .'created, no deposit or withdrawal is created and no payment gateway is called.',
            'mode_constant' => 'self::MODE is a class constant equal to "simulation". There is no '
                .'configuration key, environment variable or parameter that can change it.',
            'atomic' => 'The entire run is one transaction. A failure rolls back every bet_items '
                .'update, every bets update and the state change together.',
            'own_transaction' => 'The run refuses to start inside a caller transaction, so its rollback '
                .'guarantee cannot be captured by an outer catch.',
            'idempotent' => 'A second run locks the draw, sees the terminal Settled state, writes '
                .'nothing and returns the stored outcome. The guarantee comes from the database, not '
                .'from a cache.',
            'concurrent_safe' => 'Concurrent runs serialise on SELECT ... FOR UPDATE of the draw row; '
                .'the loser becomes the no-op path.',
            'no_client_input' => 'settle() accepts a draw id only. There is no amount, winner, '
                .'multiplier, force, override, skip or bypass parameter.',
            'verified_rules' => 'Every decision comes from the Phase 4.2 match services and every rate '
                .'from configuration through MarketPayoutService.',
            'exact_decimals' => 'Totals are summed with bcadd on decimal strings. There is no float '
                .'cast, intval(), floatval() or round() in this class.',
            'no_raw_sql' => 'Every statement goes through Eloquent; there is no DB::statement, DB::raw, '
                .'DB::select, DB::update or DB::unprepared call.',
            'no_leakage' => 'Failures carry a stable error code and safe identifiers only; no stack '
                .'trace, SQL string or credential is placed in the context.',
            'result_integrity' => 'Before any winner is decided, the stored result is recomputed into '
                .'its per market winning values and compared as strings against the published '
                .'winning_numbers rows. A disagreement, an unknown prize tier or a missing tier '
                .'refuses the run. The comparison is a string comparison, so 07 and 7 are different.',
        ];
    }

    /**
     * Do the settlement. Called with the draw already locked, inside the transaction.
     *
     * @throws DrawLifecycleException
     * @throws SettlementSimulationException
     */
    private function performSettlement(Draw $draw, DrawLifecycleState $stateBefore): SettlementSimulationResult
    {
        $drawId = (int) $draw->getKey();
        $result = $this->publishedResultFor($draw);

        $records = [];
        $selectionsWritten = 0;
        $betsUpdated = 0;
        $winners = 0;
        $totalStake = '0.00';
        $totalPrize = '0.00';
        $currency = null;

        foreach ($this->lockedBets($drawId) as $bet) {
            $betPrize = '0.00';
            $betHasWinner = false;
            $betTouched = false;

            $currency = $this->assertSingleCurrency($drawId, $currency, $bet);

            foreach ($this->lockedItems((int) $bet->getKey()) as $item) {
                $record = $this->selections->resolve($item, $bet, $result);

                $this->writeSelection($item, $record);

                $records[] = $record;
                $selectionsWritten++;
                $betTouched = true;

                $totalStake = bcadd($totalStake, $record->stake, 2);
                $totalPrize = bcadd($totalPrize, $record->simulatedPrize, 2);
                $betPrize = bcadd($betPrize, $record->simulatedPrize, 2);

                if ($record->isWinner()) {
                    $betHasWinner = true;
                    $winners++;
                }
            }

            if ($betTouched) {
                $this->writeBet($bet, $betHasWinner, $betPrize);
                $betsUpdated++;
            }
        }

        $draw = $this->lifecycle->markSettled($draw, [
            'stage' => 'settlement',
            'mode' => self::MODE,
            'selections' => $selectionsWritten,
        ]);

        $stateAfter = $this->lifecycle->currentState($draw);

        $simulation = new SettlementSimulationResult(
            drawId: $drawId,
            drawNumber: (string) $draw->draw_number,
            stateBefore: $stateBefore,
            stateAfter: $stateAfter,
            firstPrize: $result->firstPrize(),
            bottomTwo: $result->bottomTwo(),
            selections: $records,
            selectionsEvaluated: count($records),
            selectionsWritten: $selectionsWritten,
            betsUpdated: $betsUpdated,
            winningSelections: $winners,
            totalStake: $totalStake,
            totalSimulatedPrize: $totalPrize,
            currency: ($currency ?? $this->defaultCurrency())->value,
            alreadySettled: false,
            mode: self::MODE,
            settledAt: now()->toIso8601String(),
            context: [
                'lock_order' => 'DRAW -> DRAW_RESULT -> BETS -> BET_ITEMS',
                'wrote_wallet' => false,
                'wrote_ledger' => false,
                'wrote_financial_transaction' => false,
                'wrote_payout' => false,
            ],
        );

        $this->recordAudit($draw, $simulation);

        return $simulation;
    }

    /**
     * Rebuild the outcome of an already settled draw, writing nothing.
     *
     * The selections are recomputed from the published result through the same
     * resolver, which performs no writes, and each recomputed record is checked
     * against what is actually stored on the row. That makes the returned value
     * identical to the original run's, which is what
     * SettlementSimulationResult::idempotencyFingerprint() compares, and it turns any
     * drift between the stored outcome and the rules into a refusal rather than a
     * quietly different second answer.
     *
     * @throws DrawLifecycleException
     * @throws SettlementSimulationException
     */
    private function replayStoredSettlement(Draw $draw, DrawLifecycleState $state): SettlementSimulationResult
    {
        $drawId = (int) $draw->getKey();
        $result = $this->publishedResultFor($draw);

        $records = [];
        $winners = 0;
        $totalStake = '0.00';
        $totalPrize = '0.00';
        $currency = null;

        foreach ($this->betsFor($drawId) as $bet) {
            $currency = $this->assertSingleCurrency($drawId, $currency, $bet);

            foreach ($this->itemsFor((int) $bet->getKey()) as $item) {
                $record = $this->selections->resolve($item, $bet, $result);

                $this->assertStoredMatchesRecomputed($item, $record);

                $records[] = $record;
                $totalStake = bcadd($totalStake, $record->stake, 2);
                $totalPrize = bcadd($totalPrize, $record->simulatedPrize, 2);

                if ($record->isWinner()) {
                    $winners++;
                }
            }
        }

        return new SettlementSimulationResult(
            drawId: $drawId,
            drawNumber: (string) $draw->draw_number,
            stateBefore: $state,
            stateAfter: $state,
            firstPrize: $result->firstPrize(),
            bottomTwo: $result->bottomTwo(),
            selections: $records,
            selectionsEvaluated: count($records),
            // Zero, because this path wrote nothing at all.
            selectionsWritten: 0,
            betsUpdated: 0,
            winningSelections: $winners,
            totalStake: $totalStake,
            totalSimulatedPrize: $totalPrize,
            currency: ($currency ?? $this->defaultCurrency())->value,
            alreadySettled: true,
            mode: self::MODE,
            settledAt: $draw->completed_at?->toIso8601String() ?? now()->toIso8601String(),
            context: [
                'source' => 'stored settlement re-read; no row was written',
                'wrote_wallet' => false,
                'wrote_ledger' => false,
                'wrote_financial_transaction' => false,
                'wrote_payout' => false,
            ],
        );
    }

    /**
     * Write the three settlement columns of one selection.
     *
     * Assigned directly rather than through fill(), because is_winner,
     * actual_payout and payout_multiplier are excluded from BetItem::$fillable
     * precisely so that only a settlement service can set them.
     */
    private function writeSelection(BetItem $item, SettlementSelectionResult $record): void
    {
        $columns = $record->toBetItemColumns();

        $item->is_winner = $columns['is_winner'];
        $item->actual_payout = $columns['actual_payout'];
        $item->payout_multiplier = $columns['payout_multiplier'];

        $item->save();
    }

    /**
     * Write the settlement columns of one bet.
     *
     * bets.payout_id is deliberately NOT set. A payouts row carries a wallet_id and a
     * financial_transaction_id and is a real money obligation; a simulation creates
     * none, so the column stays NULL.
     */
    private function writeBet(Bet $bet, bool $hasWinner, string $simulatedPrize): void
    {
        $bet->status = $hasWinner ? BetStatus::Won : BetStatus::Lost;
        $bet->actual_payout = $simulatedPrize;

        if ($hasWinner && $bet->won_at === null) {
            $bet->won_at = now();
        }

        $bet->save();
    }

    /**
     * Refuse when a stored settlement disagrees with the recomputed one.
     *
     * @throws SettlementSimulationException
     */
    private function assertStoredMatchesRecomputed(BetItem $item, SettlementSelectionResult $record): void
    {
        $itemId = (int) $item->getKey();

        if ((bool) $item->is_winner !== $record->isWinner()) {
            throw SettlementSimulationException::selectionUnreadable(
                $itemId,
                sprintf(
                    'the stored winner flag (%s) disagrees with the recomputed decision (%s) for '
                    .'market %s; the stored settlement is not reproducible and is reported rather '
                    .'than overwritten',
                    $item->is_winner ? 'true' : 'false',
                    $record->isWinner() ? 'true' : 'false',
                    $record->marketKey,
                ),
            );
        }

        if (bccomp((string) $item->actual_payout, $record->simulatedPrize, 2) !== 0) {
            throw SettlementSimulationException::selectionUnreadable(
                $itemId,
                sprintf(
                    'the stored simulated prize %s disagrees with the recomputed %s for market %s; '
                    .'the stored settlement is not reproducible and is reported rather than overwritten',
                    (string) $item->actual_payout,
                    $record->simulatedPrize,
                    $record->marketKey,
                ),
            );
        }
    }

    /**
     * The bets of a draw, locked for update, in a stable order.
     *
     * Ordered by primary key so concurrent runs take the row locks in the same
     * sequence and cannot deadlock against one another.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Bet>
     */
    private function lockedBets(int $drawId)
    {
        return Bet::query()
            ->with('ticket')
            ->where('draw_id', $drawId)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    /**
     * The selections of a bet, locked for update, in a stable order.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, BetItem>
     */
    private function lockedItems(int $betId)
    {
        return BetItem::query()
            ->where('bet_id', $betId)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    /**
     * The bets of a draw, unlocked, for the read-only replay path.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Bet>
     */
    private function betsFor(int $drawId)
    {
        return Bet::query()
            ->with('ticket')
            ->where('draw_id', $drawId)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * The selections of a bet, unlocked, for the read-only replay path.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, BetItem>
     */
    private function itemsFor(int $betId)
    {
        return BetItem::query()
            ->where('bet_id', $betId)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * Keep the run to one currency.
     *
     * The run reports one total, so mixing currencies inside a draw would produce a
     * meaningless sum. Refused rather than silently added together.
     *
     * @throws SettlementSimulationException
     */
    private function assertSingleCurrency(int $drawId, ?Currency $current, Bet $bet): Currency
    {
        $betCurrency = $bet->currency;

        if (! $betCurrency instanceof Currency) {
            throw SettlementSimulationException::selectionUnreadable(
                (int) $bet->getKey(),
                'the bet has no usable currency',
                ['draw_id' => $drawId],
            );
        }

        if ($current !== null && $current !== $betCurrency) {
            throw SettlementSimulationException::selectionUnreadable(
                (int) $bet->getKey(),
                sprintf(
                    'draw %d mixes currencies (%s and %s); one settlement run reports one total, so '
                    .'this is refused rather than summed',
                    $drawId,
                    $current->value,
                    $betCurrency->value,
                ),
                ['draw_id' => $drawId],
            );
        }

        return $betCurrency;
    }

    /**
     * The configured default betting currency, used only when a draw has no bets.
     */
    private function defaultCurrency(): Currency
    {
        $configured = $this->config->get('lottery.betting.currency');

        if (is_string($configured)) {
            $currency = Currency::tryFrom($configured);

            if ($currency instanceof Currency) {
                return $currency;
            }
        }

        return Currency::THB;
    }

    /**
     * Record the run in audit_logs.
     *
     * AuditAction::Update is used, not AuditAction::Payout: Payout describes a real
     * money payout and this run pays nothing. The metadata states the simulation
     * explicitly so an auditor reading the log cannot mistake it for a payment.
     */
    private function recordAudit(Draw $draw, SettlementSimulationResult $simulation): void
    {
        $log = new AuditLog();

        $log->fill([
            'user_id' => null,
            'action' => AuditAction::Update,
            'risk_level' => RiskLevel::Low,
            'auditable_type' => Draw::class,
            'auditable_id' => $draw->getKey(),
            'description' => sprintf(
                'Simulated settlement of draw %s: %d selection(s) evaluated, %d winning, simulated '
                .'prize total %s %s. NON-MONETARY: no wallet, ledger entry, financial transaction or '
                .'payout was created.',
                (string) $draw->draw_number,
                $simulation->selectionsEvaluated,
                $simulation->winningSelections,
                $simulation->totalSimulatedPrize,
                $simulation->currency,
            ),
            'old_values' => ['status' => $simulation->stateBefore->toDrawStatus()->value],
            'new_values' => ['status' => $simulation->stateAfter->toDrawStatus()->value],
            'metadata' => [
                'mode' => self::MODE,
                'draw_id' => $simulation->drawId,
                'first_prize' => $simulation->firstPrize,
                'bottom_two' => $simulation->bottomTwo,
                'selections_evaluated' => $simulation->selectionsEvaluated,
                'selections_written' => $simulation->selectionsWritten,
                'bets_updated' => $simulation->betsUpdated,
                'winning_selections' => $simulation->winningSelections,
                'total_stake' => $simulation->totalStake,
                'total_simulated_prize' => $simulation->totalSimulatedPrize,
                'currency' => $simulation->currency,
                'status_counts' => $simulation->statusCounts(),
                'non_monetary' => true,
                'wallet_modified' => false,
                'ledger_modified' => false,
                'financial_transaction_created' => false,
                'payout_created' => false,
                'payment_gateway_called' => false,
                'settlement_statuses' => SettlementSimulationStatus::values(),
            ],
        ]);

        $log->save();
    }
}
