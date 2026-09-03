<?php

declare(strict_types=1);

namespace App\Services\Betting;

use App\DTOs\BetPurchaseContext;
use App\DTOs\BetPurchaseData;
use App\Enums\BetMarket;
use App\Enums\BetType;
use App\Enums\BetValidationCode;
use App\Enums\Currency;
use App\Exceptions\BetDomainException;
use App\Exceptions\BetPurchaseException;
use App\Exceptions\BetPurchaseValidationException;
use App\Models\Draw;
use App\Models\User;
use App\Services\Risk\RiskDecisionService;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Turns a sanitised purchase request into a fully resolved, validated context -
 * without touching money, and without opening a transaction.
 *
 * WHY THIS CLASS DOES NOT VALIDATE MARKETS, NUMBERS, STAKES OR DRAW STATE ITSELF
 * Phase 4.2's App\Services\Betting\BetValidationService already owns all of it, as an
 * ordered eleven-step pipeline over configuration: draw exists, draw open, market
 * supported, side valid, selection type valid, number canonical, digit length, stake
 * valid, multiplier valid, market rule, risk. Reimplementing any of those steps here
 * would create a second source of truth, and the two would drift: a number accepted
 * by one canonicaliser and looked up by another is how a limit silently applies to the
 * wrong number. Every rule question is therefore delegated, and the verdict is carried
 * into the context verbatim so the purchase records the ORIGINAL code and step.
 *
 * WHAT THIS CLASS ADDS THAT PHASE 4.2 DOES NOT DO
 *   1. It resolves the PLAYER, which the validation service has no notion of.
 *   2. It resolves the WALLET server-side. This is a security control, not a
 *      convenience: the request has no wallet field at all, so no client can direct a
 *      debit at a wallet it does not own.
 *   3. It accepts a MARKET KEY ('3d_direct') and decomposes it into the family, side
 *      and selection type triple the validation service expects. Clients speak in
 *      market keys because that is what configuration declares as sellable.
 *   4. It derives the two idempotency keys, so the transaction never has to.
 *   5. It records, for a Tod selection, how many arrangements the one selection covers.
 *
 * WHY THE RISK ENGINE IS CONSULTED HERE READ-ONLY, AND WHY THAT IS NOT THE REAL CHECK
 * Phase 4.2 consults Phase 3.1 with requireReservable = false, which is a read without
 * a lock. Its value is that an obviously impossible bet - a globally blocked number, a
 * number already at its ceiling - is refused before any lock is taken, which keeps the
 * critical section short. It is NOT the gate: capacity is only ever granted by
 * NumberLimitEngine::reserve() under SELECT ... FOR UPDATE inside the transaction, and
 * the outcome recorded here is explicitly labelled a preview. Treating this read as
 * the decision is precisely the bug that lets two concurrent bets exceed a ceiling.
 *
 * NOTHING HERE MUTATES ANYTHING
 * No wallet, no ledger, no bet, no ticket, no number limit and no draw is written. A
 * caller that catches BetPurchaseValidationException from this class knows the database
 * is untouched, which is what makes "no mutation on validation failure" structural.
 */
final class BetPurchaseValidator
{
    public function __construct(
        private readonly ConfigRepository $config,
        private readonly BetValidationService $validation,
        private readonly BetPurchaseIdempotencyService $idempotency,
        private readonly BetPurchaseWalletService $wallets,
        private readonly BetPurchaseRiskService $risk,
        private readonly TodPermutationService $permutations,
        private readonly RiskDecisionService $decisions,
    ) {
    }

    /**
     * Resolve and validate a purchase request.
     *
     * @throws BetPurchaseValidationException when the request is refused
     * @throws BetPurchaseException when the player has no usable wallet, or the
     *                              resolved rate cannot be persisted
     */
    public function validate(BetPurchaseData $data): BetPurchaseContext
    {
        // The schema is checked before anything else. If the database cannot enforce
        // request-level idempotency, no purchase may proceed at all: a retry would
        // otherwise debit the player twice and there would be no way to detect it.
        $this->idempotency->assertSchemaSupportsIdempotency();

        $betKey = $this->idempotency->betKeyFor($data);
        $financialKey = $this->idempotency->financialKeyFor($data);

        $user = $this->resolveUser($data->userId);

        $triple = BetMarket::decomposeMarketKey($data->marketKey);

        if ($triple === null) {
            throw BetPurchaseValidationException::unknownMarket(
                $data->marketKey,
                BetMarket::allMarketKeys(),
                ['user_id' => $data->userId, 'draw_id' => $data->drawId],
            );
        }

        $market = $triple['market'];
        $selectionType = $triple['selection_type'];
        $side = $triple['side'];

        // The whole Phase 4.2 pipeline runs here, unchanged and un-second-guessed.
        // consultRisk stays true so an impossible selection is refused before a lock.
        $verdict = $this->validation->validate(
            drawId: $data->drawId,
            market: $market->value,
            side: $side->value,
            selectionType: $selectionType->value,
            rawNumber: $data->rawNumber,
            rawStake: $data->rawStake,
            consultRisk: true,
        );

        if (! $verdict->isAccepted()) {
            throw BetPurchaseValidationException::fromValidation($verdict);
        }

        $selection = $verdict->selection;
        $calculation = $verdict->calculation;

        // Defensive: an accepted verdict always carries both, but a purchase must never
        // proceed on an assumption about another service's internals.
        if ($selection === null || $calculation === null || $selection->number === null) {
            throw BetPurchaseValidationException::refused(
                BetValidationCode::ValidationFailed,
                'Validation reported acceptance without a resolved selection, canonical number '
                .'or calculation, so no purchase can be derived from it.',
                ['market' => $data->marketKey, 'draw_id' => $data->drawId],
            );
        }

        $marketKey = $selection->marketKey ?? $data->marketKey;
        $betType = $this->resolveBetType($marketKey, $selection->betType);
        $number = $selection->number;
        $stake = $selection->stake;
        $multiplier = $calculation->multiplier;

        // The rate is persisted into an unsignedInteger column in the Phase 1 schema.
        // A fractional rate is reported, never rounded: rounding a rate would change
        // every payout for that market for ever.
        if (! $calculation->multiplierFitsBetItemColumn()) {
            throw BetPurchaseException::multiplierNotStorable(
                $multiplier->value(),
                $marketKey,
                ['draw_id' => $data->drawId, 'bet_type' => $betType->value],
            );
        }

        $draw = $this->resolveDraw($data->drawId);
        $currency = $stake->currency();
        $wallet = $this->wallets->resolveWallet($user, $currency);

        $riskPreview = $this->risk->preview(
            drawId: $data->drawId,
            betType: $betType,
            canonicalNumber: $number->value(),
            stake: $stake->money(),
            multiplier: $multiplier->value(),
        );

        // A preview that already says "not reservable" is refused now, so no lock is
        // taken for a bet that cannot possibly fit. A preview that says "reservable" is
        // NOT trusted as permission; reserve() re-decides under the row lock.
        $decision = $this->decisions->decide($riskPreview, false);

        if (! $this->risk->previewAllows($decision)) {
            throw BetPurchaseValidationException::fromValidation(
                \App\DTOs\BetValidationResult::riskRejected(
                    $decision,
                    $selection,
                    ['market' => $marketKey, 'number' => $number->value()],
                ),
            );
        }

        return new BetPurchaseContext(
            data: $data,
            user: $user,
            wallet: $wallet,
            draw: $draw,
            marketKey: $marketKey,
            market: $market,
            side: $side,
            selectionType: $selectionType,
            betType: $betType,
            number: $number,
            position: $this->resolvePosition($marketKey, $side->value),
            stake: $stake,
            multiplier: $multiplier,
            potentialPayout: $calculation->potentialPayout,
            calculation: $calculation,
            validation: $verdict,
            betIdempotencyKey: $betKey,
            financialIdempotencyKey: $financialKey,
            permutations: $this->arrangementsFor($selectionType->value, $number->value()),
            riskPreview: $riskPreview,
        );
    }

    /**
     * The player, resolved from the authenticated identifier only.
     *
     * @throws BetPurchaseException
     */
    public function resolveUser(int $userId): User
    {
        if ($userId <= 0) {
            throw BetPurchaseException::userUnavailable($userId, 'no authenticated player was supplied');
        }

        $user = User::query()->whereKey($userId)->first();

        if (! $user instanceof User) {
            throw BetPurchaseException::userUnavailable($userId, 'the account does not exist');
        }

        if (! $user->canTransact()) {
            throw BetPurchaseException::userUnavailable(
                $userId,
                sprintf('the account status is %s', (string) ($user->status->value ?? 'unknown')),
                ['user_status' => (string) ($user->status->value ?? 'unknown')],
            );
        }

        return $user;
    }

    /**
     * The draw, re-read after validation purely so the context carries the model.
     *
     * Its STATE is not re-judged here - Phase 4.2 step 1 and step 2 already did that
     * against config('lottery.betting.require_open_draw') and the betting window
     * columns. Re-judging it with a second, independently written rule is how two
     * different answers to "is this draw open?" come to exist in one codebase.
     *
     * @throws BetPurchaseException
     */
    public function resolveDraw(int $drawId): Draw
    {
        $draw = Draw::query()->whereKey($drawId)->first();

        if (! $draw instanceof Draw) {
            throw BetPurchaseException::drawUnavailable($drawId, 'the draw no longer exists');
        }

        return $draw;
    }

    /**
     * The persistence bet type of a market, read from configuration.
     *
     * Phase 4.2 already resolves this; the configured value is re-read only to confirm
     * the two agree. A disagreement means the market definition changed between the two
     * reads, and continuing would reserve risk capacity under one bet type while
     * persisting the bet under another.
     *
     * @throws BetPurchaseValidationException
     */
    public function resolveBetType(string $marketKey, ?BetType $resolved): BetType
    {
        $definition = $this->config->get('lottery.markets.'.$marketKey);

        $configured = BetMarket::betTypeFor(is_array($definition) ? $definition : null);

        if ($configured === null) {
            throw BetPurchaseValidationException::refused(
                BetValidationCode::SpecificationRequired,
                sprintf(
                    'SPECIFICATION REQUIRED: config lottery.markets.%s declares no usable bet_type, '
                    .'so no bet can be persisted for this market.',
                    $marketKey,
                ),
                ['market' => $marketKey],
            );
        }

        if ($resolved !== null && $resolved !== $configured) {
            throw BetPurchaseValidationException::refused(
                BetValidationCode::SpecificationRequired,
                sprintf(
                    'SPECIFICATION REQUIRED: validation resolved bet type "%s" for market "%s" but '
                    .'configuration declares "%s". Refusing to choose between them.',
                    $resolved->value,
                    $marketKey,
                    $configured->value,
                ),
                ['market' => $marketKey],
            );
        }

        return $configured;
    }

    /**
     * The configured position of a market, used for bet_items.position.
     *
     * Falls back to the side of the market key, which is where the position comes from
     * for every market this project declares, rather than to a literal.
     */
    public function resolvePosition(string $marketKey, string $side): string
    {
        $definition = $this->config->get('lottery.markets.'.$marketKey);

        if (is_array($definition) && is_string($definition['position'] ?? null) && $definition['position'] !== '') {
            return $definition['position'];
        }

        return $side;
    }

    /**
     * The distinct arrangements a Tod selection covers.
     *
     * For every other market this returns an empty list, and the context then reports a
     * permutation count of 1. For Tod it returns the unique permutations of the digits -
     * six for '123', three for '112', one for '111' - which are recorded as METADATA
     * only. They are never used to multiply the stake, to create extra bet items, to
     * create extra tickets or to make extra ledger movements: a Tod selection is one
     * selection with one charge.
     *
     * @return list<string>
     */
    public function arrangementsFor(string $selectionType, string $canonicalNumber): array
    {
        if ($selectionType !== \App\Enums\BetSelectionType::Tod->value) {
            return [];
        }

        try {
            return $this->permutations->uniquePermutationStrings($canonicalNumber);
        } catch (BetDomainException) {
            // Metadata must never be able to fail a purchase. If the permutation
            // service refuses the digits, the count simply falls back to one selection.
            return [];
        }
    }

    /**
     * The betting currency this project declares, for reporting.
     *
     * @throws BetDomainException
     */
    public function currency(): Currency
    {
        $configured = $this->config->get('lottery.betting.currency');

        if (! is_string($configured) || Currency::tryFrom($configured) === null) {
            throw BetDomainException::misconfigured(
                'lottery.betting.currency',
                'the betting currency is absent or is not a currency this project declares',
            );
        }

        return Currency::from($configured);
    }
}
