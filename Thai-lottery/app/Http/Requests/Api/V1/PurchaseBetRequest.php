<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Enums\BetMarket;
use App\DTOs\BetPurchaseData;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Structural validation for POST /api/v1/bets/purchase.
 *
 * WHAT THIS CLASS IS FOR, AND WHAT IT IS NOT FOR
 * It validates the SHAPE of the request: required keys, types, string-ness, character
 * sets, list length. It does NOT decide whether the bet is legal. Whether the draw is
 * open, whether the number suits the market, whether the stake is within bounds and on
 * step, what the payout rate is - all of that is Phase 4.2 and Phase 4.3's authority and
 * is re-checked server-side regardless of what passes here. Duplicating those rules in a
 * FormRequest would create a second, divergent rulebook: the day a market's digit count
 * changed, one of the two would be wrong.
 *
 * LOTTERY NUMBERS MUST ARRIVE AS STRINGS
 * The `number` field is required to be a string. A JSON number cannot express '007':
 * `{"number": 007}` is not even valid JSON, and `{"number": 7}` has already lost the
 * leading zeros before PHP sees it. Rather than accept 7 and try to guess how many zeros
 * the client meant - which would invent a bet the player did not place - the request is
 * refused with an explicit message. The value is then passed downstream untouched: no
 * intval, no floatval, no cast, no zero-padding, no trimming of leading zeros.
 *
 * STAKES MUST ARRIVE AS DECIMAL STRINGS
 * The `stake` field is required to be a string matching a strict decimal pattern. A JSON
 * float is refused on purpose: `10.55` decoded into a PHP float is already a binary
 * approximation, and no amount of care afterwards can recover the exact decimal the
 * player agreed to. Requiring the string form keeps the value exact from the wire to
 * bcmath. Integers are refused for the same reason a float is - so there is exactly one
 * accepted representation and no silent reformatting.
 *
 * FORBIDDEN FIELDS ARE REFUSED LOUDLY
 * Phase 4.3's BetPurchaseData already STRIPS client-supplied wallet ids, balances,
 * multipliers, payouts, statuses, uuids and risk decisions, and records them as ignored.
 * That is the safety net. This class additionally REFUSES the request when such a field
 * is present, because a client sending `potential_payout` is either broken or probing,
 * and silently ignoring it teaches the client that the field works. Both layers are kept:
 * refusing here is a policy, stripping there is a guarantee.
 *
 * NO AUTHORIZATION LOGIC HERE
 * authorize() returns true. Authentication is enforced by the route's `auth:sanctum` and
 * `active` middleware, and the user identity is taken from the auth context in the
 * controller. There is no per-resource authorization to perform on a create.
 */
final class PurchaseBetRequest extends FormRequest
{
    /**
     * A stake: 1-12 integer digits, optionally 1-2 decimal places.
     *
     * The decimal places are capped at 2 to match the currency scale rather than being
     * silently rounded down to it later. '10.555' is refused, not turned into '10.56'.
     */
    private const STAKE_PATTERN = '/^[0-9]{1,12}(\.[0-9]{1,2})?$/';

    /**
     * A lottery number: 1-3 digits, nothing else.
     *
     * No sign, no separator, no whitespace, no letters. The exact digit count required by
     * the chosen market is enforced by Phase 4.2, not here.
     */
    private const NUMBER_PATTERN = '/^[0-9]{1,3}$/';

    /**
     * A client key: printable, bounded, and long enough to be unguessable.
     *
     * The bounds come from config('security.idempotency'), which already declares
     * min_key_length 16 and max_key_length 128, so this endpoint agrees with the
     * project's existing idempotency policy instead of inventing its own.
     */
    private const CLIENT_KEY_PATTERN = '/^[A-Za-z0-9._:-]+$/';

    /**
     * Server-owned fields that Phase 4.3's deny-list does not name, but which a client
     * must equally never supply.
     *
     * `user_id` heads the list. Phase 4.3 is already immune to it - BetPurchaseData takes
     * the user id as a separate constructor argument and never reads it from the payload,
     * so a payload claiming another user cannot buy on their behalf. This entry therefore
     * adds no safety; it adds honesty. A client sending user_id is told it is not accepted
     * rather than being allowed to believe it was honoured.
     *
     * The identifier fields are refused because they are generated inside the purchase
     * transaction: accepting a bet uuid or a ticket number from a client would let the
     * caller choose a primary-key-adjacent value, and accepting `idempotency_key` would
     * bypass the scoped derivation that keeps two users' keys from colliding.
     *
     * @var list<string>
     */
    private const SERVER_OWNED_FIELDS = [
        'user_id',
        'uuid',
        'bet_uuid',
        'ticket_uuid',
        'bet_id',
        'bet_item_id',
        'idempotency_key',
        'placed_at',
        'issued_at',
        'confirmed_at',
        'total_amount',
        'total_bets',
        'total_numbers',
        'currency',
    ];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $minKey = (int) config('security.idempotency.min_key_length', 16);
        $maxKey = (int) config('security.idempotency.max_key_length', 128);

        return [
            'draw_id' => ['required', 'integer', 'min:1'],

            'client_key' => [
                'required',
                'string',
                'min:'.$minKey,
                'max:'.$maxKey,
                'regex:'.self::CLIENT_KEY_PATTERN,
            ],

            'items' => ['required', 'array', 'min:1', 'max:'.$this->maxItems()],

            'items.*' => ['required', 'array'],

            'items.*.market' => [
                'required',
                'string',
                // The allowed set is read from the authoritative market registry, never
                // hard-coded here, so a market added to config/lottery.php becomes
                // purchasable without editing this file.
                'in:'.implode(',', BetMarket::allMarketKeys()),
            ],

            'items.*.number' => [
                'required',
                'string',
                'regex:'.self::NUMBER_PATTERN,
            ],

            'items.*.stake' => [
                'required',
                'string',
                'regex:'.self::STAKE_PATTERN,
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'items.*.number.string' => 'The number must be sent as a string so leading zeros are preserved. '
                .'Send "007", not 7.',
            'items.*.number.regex' => 'The number may contain digits only.',
            'items.*.stake.string' => 'The stake must be sent as a decimal string so the exact amount is '
                .'preserved. Send "10.55", not 10.55.',
            'items.*.stake.regex' => 'The stake must be a decimal amount with at most two decimal places.',
            'items.*.market.in' => 'The selected market is not available.',
            'client_key.regex' => 'The client_key may contain letters, digits, dots, colons, hyphens and '
                .'underscores only.',
        ];
    }

    /**
     * Reject any field the client is not permitted to decide.
     *
     * Both the top level and each item are inspected. The check is deliberately driven by
     * BetPurchaseData::FORBIDDEN_CLIENT_KEYS - the same list Phase 4.3 uses to strip - so
     * the two layers cannot drift apart, and both a snake_case and a camelCase spelling of
     * each key are matched because a JavaScript client will naturally send the latter.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach ($this->forbiddenKeysPresent($this->safePayload()) as $key) {
                $validator->errors()->add(
                    $key,
                    'This field is decided by the server and must not be sent.',
                );
            }

            $items = $this->input('items');

            if (! is_array($items)) {
                return;
            }

            foreach ($items as $index => $item) {
                if (! is_array($item)) {
                    continue;
                }

                foreach ($this->forbiddenKeysPresent($item) as $key) {
                    $validator->errors()->add(
                        sprintf('items.%s.%s', (string) $index, $key),
                        'This field is decided by the server and must not be sent.',
                    );
                }
            }
        });
    }

    /**
     * The validated draw id, as an int.
     *
     * Cast with (int) on an already-validated integer field. This is an identifier, not
     * money and not a lottery number, so an integer cast is the correct representation
     * rather than a precision risk.
     */
    public function drawId(): int
    {
        return (int) $this->validated()['draw_id'];
    }

    /**
     * The client's request key, verbatim.
     *
     * It is NOT used as the database idempotency key. Phase 4.3 derives the authoritative
     * key by hashing user + draw + this value under its own scope, which is what keeps two
     * different users' identical keys from colliding.
     */
    public function clientKey(): string
    {
        return (string) $this->validated()['client_key'];
    }

    /**
     * The requested selections, as raw strings.
     *
     * Every value is returned exactly as it arrived. Nothing is padded, trimmed of
     * significant characters, normalised or cast.
     *
     * @return list<array{market: string, number: string, stake: string}>
     */
    public function items(): array
    {
        $items = [];

        /** @var array<int, array<string, mixed>> $validated */
        $validated = $this->validated()['items'];

        foreach ($validated as $item) {
            $items[] = [
                'market' => (string) $item['market'],
                'number' => (string) $item['number'],
                'stake' => (string) $item['stake'],
            ];
        }

        return $items;
    }

    /**
     * How many items one request may carry.
     *
     * A bound is required so a single request cannot be used to submit thousands of
     * selections and turn one rate-limited call into an unbounded amount of work. See the
     * controller for why more than one item is currently refused at execution time.
     */
    private function maxItems(): int
    {
        return 50;
    }

    /**
     * The top-level payload without the nested items.
     *
     * @return array<string, mixed>
     */
    private function safePayload(): array
    {
        $payload = $this->all();

        unset($payload['items']);

        return $payload;
    }

    /**
     * @param  array<array-key, mixed>  $payload
     * @return list<string>
     */
    private function forbiddenKeysPresent(array $payload): array
    {
        $present = [];

        $forbiddenKeys = array_merge(BetPurchaseData::FORBIDDEN_CLIENT_KEYS, self::SERVER_OWNED_FIELDS);

        foreach ($forbiddenKeys as $forbidden) {
            foreach ($this->spellings($forbidden) as $spelling) {
                if (array_key_exists($spelling, $payload)) {
                    $present[] = $spelling;
                }
            }
        }

        return array_values(array_unique($present));
    }

    /**
     * Both spellings of a forbidden key.
     *
     * `walletId` and `wallet_id` are the same attempt and both are refused.
     *
     * @return list<string>
     */
    private function spellings(string $key): array
    {
        $snake = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $key));
        $camel = lcfirst(str_replace('_', '', ucwords($key, '_')));

        return array_values(array_unique([$key, $snake, $camel]));
    }
}
