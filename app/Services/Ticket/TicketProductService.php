<?php

declare(strict_types=1);

namespace App\Services\Ticket;

use App\DTOs\Ticket\TicketProductData;
use App\Enums\AuditAction;
use App\Enums\DrawLifecycleState;
use App\Enums\RiskLevel;
use App\Enums\TicketProductStatus;
use App\Models\AuditLog;
use App\Models\Draw;
use App\Models\TicketProduct;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Manufactures and advances fixed lottery-ticket PRODUCTS.
 *
 * WHAT THE SERVICE GUARDS
 * -----------------------
 *   DUPLICATES ARE IMPOSSIBLE BY IDENTITY. The product_key derives from
 *   (code + draw + denomination + currency) and is UNIQUE at the engine:
 *   manufacturing the same product twice replays onto the same row (join,
 *   never mint), and a same-code-different-basis product can never collide
 *   with an existing key — the two creations have different keys.
 *
 *   IMMUTABILITY PAST DRAFT. Denomination, code, draw association and
 *   units never mutate once Active: changing the offering after buyers
 *   have seen it is retro-editing what they were shown. The lawful exit
 *   for a bad product is Close, plus a NEW product under a new code.
 *
 *   THE DRAW IS REAL AND SENSIBLE. The draw association is validated
 *   against the draw row at creation: no product against a draw that
 *   doesn't exist, and (when the draw carries a lifecycle) nothing Active
 *   against a draw already result-published/settled.
 *
 *   LIFECYCLE IS THE ENUM'S. Every transition asserts via TicketProductStatus.
 *
 * SUSPENSION NEVER VOIDS TICKETS
 * ------------------------------
 * Suspended products stop RETAILING; sold tickets owned by players remain
 * lawful with full prize rights. Suspension is an incident pause, not an
 * eraser.
 */
class TicketProductService
{
    /**
     * Create a product in Draft. Idempotent by product key: identical
     * intent returns the row; same code with different basis keys
     * differently and coexists (which is lawful catalog behavior).
     *
     * @return array{product: TicketProduct, created: bool}
     */
    public function create(TicketProductData $data): array
    {
        if (DB::transactionLevel() > 0) {
            throw new InvalidArgumentException(
                'Ticket product creation owns its transaction boundary; caller is already in a transaction.',
            );
        }

        $this->assertWellFormed($data);

        return DB::transaction(function () use ($data): array {
            $existing = TicketProduct::query()->lockForUpdate()
                ->where('product_key', $data->productKey())
                ->first();

            if ($existing instanceof TicketProduct) {
                return ['product' => $existing, 'created' => false];
            }

            // The draw needs to exist for a product to be offered against it.
            $draw = Draw::query()->find($data->drawId);

            if (! $draw instanceof Draw) {
                throw new InvalidArgumentException(sprintf(
                    'Ticket product [%s]: draw #%d does not exist; a product must name a real draw.',
                    strtoupper(trim($data->productCode)),
                    $data->drawId,
                ));
            }

            $product = new TicketProduct();
            $product->fill([
                'product_key' => $data->productKey(),
                'product_code' => strtoupper(trim($data->productCode)),
                'draw_id' => $data->drawId,
                'denomination' => $data->denomination,
                'currency' => $data->currency->value,
                'units_total' => $data->unitsTotal,
                'units_allocated' => 0,
                'starts_at' => $data->startsAt,
                'ends_at' => $data->endsAt,
                'metadata' => ['context' => $data->context],
            ]);
            $product->status = TicketProductStatus::Draft;
            $product->save();

            $this->recordAudit($product, sprintf(
                'Ticket product [%s] drafted: %s %s per unit, %d unit cap, draw #%d.',
                $product->product_code,
                bcadd($data->denomination, '0.00', 2),
                $data->currency->value,
                $data->unitsTotal,
                $data->drawId,
            ), RiskLevel::Low);

            return ['product' => $product, 'created' => true];
        });
    }

    /**
     * Put a product on offer. Lifecycle assertion, plus the draw check:
     * nothing may activate against a draw whose results are already
     * published or settled.
     */
    public function activate(TicketProduct $product): TicketProduct
    {
        return DB::transaction(function () use ($product): TicketProduct {
            /** @var TicketProduct|null $locked */
            $locked = TicketProduct::query()->lockForUpdate()->find((int) $product->getKey());

            if (! $locked instanceof TicketProduct) {
                throw new InvalidArgumentException(sprintf('Ticket product id %d not found.', (int) $product->getKey()));
            }

            if (! $locked->status->canTransitionTo(TicketProductStatus::Active)) {
                throw new InvalidArgumentException(sprintf(
                    'Ticket product [%s]: %s → active is not a lawful product step.',
                    $locked->product_code,
                    $locked->status->value,
                ));
            }

            // The parent draw must not already be reported. Activating a
            // product whose draw's result is public would sell against a
            // settled outcome.
            $draw = $locked->draw()->first();

            if ($draw instanceof Draw) {
                $lifecycle = method_exists($draw, 'lifecycleState')
                    ? $draw->lifecycleState()
                    : null;

                // Saleable windows only: a draw that is reported, settled or
                // cancelled can never be newly offered against.
                if ($lifecycle instanceof DrawLifecycleState
                    && ! in_array($lifecycle, [
                        DrawLifecycleState::Draft,
                        DrawLifecycleState::Open,
                        DrawLifecycleState::Closed,
                        DrawLifecycleState::ResultPending,
                    ], true)
                ) {
                    throw new InvalidArgumentException(sprintf(
                        'Ticket product [%s]: draw #%d is %s; a product may not activate against a reported/settled draw.',
                        $locked->product_code,
                        (int) $locked->draw_id,
                        $lifecycle->value,
                    ));
                }
            }

            $locked->status = TicketProductStatus::Active;
            $locked->activated_at = Carbon::now();
            $locked->save();

            $this->recordAudit($locked, sprintf('Ticket product [%s] activated for retail.', $locked->product_code), RiskLevel::Medium);

            return $locked;
        });
    }

    public function suspend(TicketProduct $product, string $reason): TicketProduct
    {
        return $this->transition($product, TicketProductStatus::Suspended, $reason);
    }

    public function resume(TicketProduct $product): TicketProduct
    {
        return $this->transition($product, TicketProductStatus::Active, 'incident resume');
    }

    /**
     * Close a product terminally (draw settled/reconciled, or sold out).
     */
    public function close(TicketProduct $product, ?string $reason = null): TicketProduct
    {
        $locked = $this->transition($product, TicketProductStatus::Closed, $reason ?? 'closed');

        $locked->closed_at = Carbon::now();
        $locked->save();

        return $locked;
    }

    public function withdraw(TicketProduct $product, string $reason): TicketProduct
    {
        return $this->transition($product, TicketProductStatus::Withdrawn, $reason);
    }

    /**
     * Internal: one lawful product transition with row lock + audit.
     */
    private function transition(TicketProduct $product, TicketProductStatus $target, string $reason): TicketProduct
    {
        return DB::transaction(function () use ($product, $target, $reason): TicketProduct {
            /** @var TicketProduct|null $locked */
            $locked = TicketProduct::query()->lockForUpdate()->find((int) $product->getKey());

            if (! $locked instanceof TicketProduct) {
                throw new InvalidArgumentException(sprintf('Ticket product id %d not found.', (int) $product->getKey()));
            }

            if (! $locked->status->canTransitionTo($target)) {
                throw new InvalidArgumentException(sprintf(
                    'Ticket product [%s]: %s → %s is not a lawful product step.',
                    $locked->product_code,
                    $locked->status->value,
                    $target->value,
                ));
            }

            $from = $locked->status->value;

            $locked->status = $target;
            $locked->save();

            $this->recordAudit($locked, sprintf(
                'Ticket product [%s] %s → %s (%s).',
                $locked->product_code,
                $from,
                $target->value,
                $reason,
            ), RiskLevel::Medium);

            return $locked;
        });
    }

    /**
     * @throws InvalidArgumentException
     */
    private function assertWellFormed(TicketProductData $data): void
    {
        if (! $data->codeIsCanonical()) {
            throw new InvalidArgumentException(sprintf(
                'Ticket product code [%s] is not canonical (uppercase ASCII, digits, hyphens).',
                $data->productCode,
            ));
        }

        if (! $data->denominationIsWellFormed()) {
            throw new InvalidArgumentException(sprintf(
                'Ticket product [%s]: denomination [%s] is not a positive 2-decimal money string.',
                strtoupper(trim($data->productCode)),
                $data->denomination,
            ));
        }

        if ($data->unitsTotal <= 0) {
            throw new InvalidArgumentException(sprintf(
                'Ticket product [%s]: a product printed with %d units is not an offering.',
                strtoupper(trim($data->productCode)),
                $data->unitsTotal,
            ));
        }

        if (! $data->windowIsOrdered()) {
            throw new InvalidArgumentException(sprintf(
                'Ticket product [%s]: availability window starts before it ends.',
                strtoupper(trim($data->productCode)),
            ));
        }
    }

    private function recordAudit(TicketProduct $product, string $description, RiskLevel $riskLevel): void
    {
        $log = new AuditLog();

        $log->fill([
            'user_id' => null,
            'action' => AuditAction::Update,
            'risk_level' => $riskLevel,
            'auditable_type' => TicketProduct::class,
            'auditable_id' => $product->getKey(),
            'description' => $description,
            'metadata' => [
                'product_key' => $product->product_key,
                'product_code' => $product->product_code,
                'status' => $product->status->value,
                'draw_id' => (int) $product->draw_id,
                'action' => 'ticket_product',
            ],
        ]);

        $log->save();
    }
}
