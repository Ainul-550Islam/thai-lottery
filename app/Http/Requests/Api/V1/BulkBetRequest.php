<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Enums\BetMarket;
use App\DTOs\Betting\BulkBetSelectionData;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Structural validation for POST /api/v1/bets/purchase-bulk and its read-only
 * quote counterpart.
 *
 * WHY THIS IS NOT JUST PurchaseBetRequest AGAIN
 * The single-purchase endpoint deliberately refuses more than one item at
 * execution time (one request — one atomic selection). This endpoint is the
 * multi-item surface, with the same field-level fidelity rules per item and
 * its own slip-level rules (bounded item count, one client key per slip).
 *
 * PER-ITEM FIDELITY (identical to the single purchase, deliberately)
 * numbers and stakes are digit/decimal STRINGS; forbidden fields are refused
 * both at the top level and inside every item.
 */
final class BulkBetRequest extends FormRequest
{
    private const NUMBER_PATTERN = '/^[0-9]{1,3}$/';
    private const STAKE_PATTERN = '/^[0-9]{1,12}(\.[0-9]{1,2})?$/';
    private const CLIENT_KEY_PATTERN = '/^[A-Za-z0-9._:-]+$/';

    /**
     * @return list<string>
     */
    private const SERVER_OWNED_FIELDS = [
        'user_id',
        'uuid',
        'bet_id',
        'ticket_id',
        'ticket_number',
        'payout',
        'potential_payout',
        'total_charged',
        'status',
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

            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*' => ['required', 'array'],

            'items.*.market' => [
                'required',
                'string',
                'in:'.implode(',', BetMarket::allMarketKeys()),
            ],

            'items.*.number' => ['required', 'string', 'regex:'.self::NUMBER_PATTERN],
            'items.*.stake' => ['required', 'string', 'regex:'.self::STAKE_PATTERN],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'items.required' => 'A bulk request must contain an items list.',
            'items.max' => 'A bulk request may contain at most 50 selections.',
            'items.*.number.regex' => 'Each number may contain digits only, sent as a string so leading zeros survive.',
            'items.*.stake.regex' => 'Each stake must be a decimal string with at most two decimal places.',
            'items.*.market.in' => 'One of the selected markets is not available.',
        ];
    }

    /**
     * Refuse server-owned fields at the top level and per item.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $top = $this->all();
            unset($top['items']);

            foreach (self::SERVER_OWNED_FIELDS as $key) {
                if (array_key_exists($key, $top)) {
                    $validator->errors()->add($key, 'This field is decided by the server and must not be sent.');
                }
            }

            $items = $this->input('items');

            if (! is_array($items)) {
                return;
            }

            foreach ($items as $index => $item) {
                if (! is_array($item)) {
                    continue;
                }

                foreach (['payout', 'multiplier', 'status', 'wallet_id'] as $key) {
                    if (array_key_exists($key, $item)) {
                        $validator->errors()->add(
                            sprintf('items.%s.%s', (string) $index, $key),
                            'This field is decided by the server and must not be sent.',
                        );
                    }
                }
            }
        });
    }

    public function drawId(): int
    {
        return (int) $this->validated()['draw_id'];
    }

    public function clientKey(): string
    {
        return (string) $this->validated()['client_key'];
    }

    /**
     * The slip as sanitised selection objects.
     *
     * @return list<BulkBetSelectionData>
     */
    public function selections(): array
    {
        $selections = [];

        /** @var array<int, array<string, mixed>> $items */
        $items = $this->validated()['items'];

        foreach ($items as $item) {
            $selections[] = BulkBetSelectionData::fromRequestArray($item);
        }

        return $selections;
    }
}
