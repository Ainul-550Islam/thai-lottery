<?php

declare(strict_types=1);

namespace App\DTOs\Ticket;

use App\Enums\Currency;

/**
 * Immutable definition of a fixed lottery-ticket PRODUCT at creation time.
 *
 * WHAT ONE OBJECT NAMES
 * ---------------------
 * The full identity of the line item offered: the immutable product CODE
 * (its machine name — never reused, never edited), the denomination (its
 * face price), the draw it associates to, the unit count it prints and
 * the canonical availability window. Everything the product service needs
 * to manufacture a line legibly, and nothing else.
 *
 * WHY A DTO AND NOT ARRAY-AT-THE-CONTROLLER
 * -----------------------------------------
 * A product is one of the very few CREATE-side entities of the whole
 * system: once Retailable it mutates nothing but status. The creation
 * call must prove its basis NOW; granting a controller the freedom to
 * pass partial/half-shape data here manufactures products nobody can
 * price or close legibly.
 *
 * DENOMINATION
 * ------------
 * The face price as a 2-decimal string ('80.00'). Decimals never go
 * through float, and price never arrives negative or free-form. When a
 * price needs changing after Draft, the lawful act is Withdrawn + a new
 * product with a NEW CODE.
 *
 * THE PRODUCT CODE
 * ----------------
 * humanable and stable: 'GLO-6D-80THB-2026-10'. It appears on audit lines,
 * inventory rows and statements — drafted to be read. Uppercase ASCII,
 * with hyphens as separators so code == identity at sort/glance time.
 * Machine derivation via deriveProductKey() instead gives the 64-char
 * identity of the line: identical code + draw + denomination products may
 * only exist once (duplicate prevention at engine level).
 */
class TicketProductData
{
    /**
     * @param  string  $denomination  Face price 2-decimal string.
     * @param  int  $unitsTotal  Units printed for this product draw-order
     *                           (the cap any allocation may draw from).
     * @param  string|null  $startsAt  ISO-8601 retail availability start,
     *                                 optional; null = from activation.
     * @param  string|null  $endsAt  ISO-8601 retail availability stop,
     *                               optional; draw close is always a hard
     *                               stop regardless.
     * @param  array<string, mixed>  $context  Safe manufacturing context
     *                                        (series, print batch refs).
     */
    public function __construct(
        public readonly string $productCode,
        public readonly string $denomination,
        public readonly Currency $currency,
        public readonly int $drawId,
        public readonly int $unitsTotal,
        public readonly ?string $startsAt = null,
        public readonly ?string $endsAt = null,
        public readonly array $context = [],
    ) {
    }

    /**
     * The deterministic product identity: identical code + draw +
     * denomination may exist exactly once.
     */
    public static function deriveProductKey(
        string $productCode,
        int $drawId,
        string $denomination,
        Currency $currency,
    ): string {
        return hash('sha256', sprintf(
            'ticket-product:%s:%d:%s:%s',
            strtoupper(trim($productCode)),
            $drawId,
            bcadd($denomination, '0.00', 2),
            $currency->value,
        ));
    }

    public function productKey(): string
    {
        return self::deriveProductKey(
            $this->productCode,
            $this->drawId,
            $this->denomination,
            $this->currency,
        );
    }

    /**
     * Code looks canonical (uppercase, digit, hyphen only)?
     */
    public function codeIsCanonical(): bool
    {
        return preg_match('/^[A-Z0-9]+(-[A-Z0-9]+)*$/', $this->productCode) === 1;
    }

    public function denominationIsWellFormed(): bool
    {
        return preg_match('/^\d+(\.\d{1,2})?$/', $this->denomination) === 1
            && bccomp(bcadd($this->denomination, '0.00', 2), '0.00', 2) > 0;
    }

    /**
     * The availability window is ordered when fully stated.
     */
    public function windowIsOrdered(): bool
    {
        if ($this->startsAt === null || $this->endsAt === null) {
            return true;
        }

        return strcmp($this->startsAt, $this->endsAt) <= 0;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'product_key' => $this->productKey(),
            'product_code' => strtoupper(trim($this->productCode)),
            'denomination' => bcadd($this->denomination, '0.00', 2),
            'currency' => $this->currency->value,
            'draw_id' => $this->drawId,
            'units_total' => $this->unitsTotal,
            'starts_at' => $this->startsAt,
            'ends_at' => $this->endsAt,
            'context' => $this->context,
        ];
    }
}
