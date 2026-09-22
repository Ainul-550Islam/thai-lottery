<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\TicketProductStatus;
use App\Http\Resources\TicketProductResource;
use App\Http\Responses\ApiResponse;
use App\Models\TicketProduct;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Fixed-ticket product catalogue API.
 *
 * THE PUBLIC / OPERATOR SPLIT
 * Public consumers of the catalogue (any authenticated principal) may only
 * ever see ACTIVE products — the sells-right-now row set — and only the
 * sanitized TicketProductResource shape. Operators (admin / super-admin)
 * may widen the lens with `?lane=all` to survey Draft/Suspended/Closed and
 * Withdrawn rows for their console, without ever changing what the public
 * surface emits. The read-side only: product lifecycle MUTATION stays the
 * TicketProductService's own four-eyes court, behind operator console code
 * this controller deliberately does not replicate.
 *
 * DELIBERATE NON-GOALS
 * No product creation, no allocation, no suspension here — the catalogue
 * answers buyer-legible questions; manufacturing questions route through
 * the AllocateTicketInventoryJob + operator console, not the player API.
 */
final class TicketProductController
{
    /**
     * The catalogue:
     * - default: ACTIVE products only, purchasable window filter applied
     *   server-side (the product's sale window is honored as data, not as
     *   a client-side decoration).
     * - operator (lane=all): the full lifecycle row set, demoted for
     *   everyone else.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $isOperator = $user !== null && ($user->isAdmin() || $user->isSuperAdmin());

        $lane = $isOperator ? (string) $request->query('lane', 'active') : 'active';

        $query = TicketProduct::query()->orderByDesc('id');

        if ($lane === 'all') {
            // operator-wide survey: every lifecycle row, no filtering.
            $statusFilter = $request->query('status');

            if (is_string($statusFilter) && $statusFilter !== '') {
                $status = TicketProductStatus::tryFrom($statusFilter);

                if ($status instanceof TicketProductStatus) {
                    $query->where('status', $status->value);
                } else {
                    return ApiResponse::error(
                        code: 'ticket_product_status_unknown',
                        message: 'The requested product status lane is not a known lifecycle state.',
                        status: 422,
                    );
                }
            }
        } else {
            // The public rule: ACTIVE only. Realistically purchasable also
            // means "within the sale window" — the resource recomputes this
            // per row; the query carries the coarse filter only.
            $query->where('status', TicketProductStatus::Active->value);
        }

        $perPage = max(1, min(50, (int) $request->query('per_page', 20)));

        $products = $query->paginate($perPage);

        return ApiResponse::success(
            data: [
                'items' => collect($products->items())
                    ->map(fn (TicketProduct $product): array => (new TicketProductResource($product))->resolve($request))
                    ->all(),
                'pagination' => [
                    'current_page' => $products->currentPage(),
                    'last_page' => $products->lastPage(),
                    'per_page' => $products->perPage(),
                    'total' => $products->total(),
                ],
            ],
            message: 'Ticket products retrieved successfully.',
        );
    }

    /**
     * One product by its canonical code. For non-operators, anything that is
     * not ACTIVE looks exactly like a nonexistent code — same 404, same
     * body, no lifecycle disclosure.
     */
    public function show(string $product, Request $request): JsonResponse
    {
        $user = $request->user();
        $isOperator = $user !== null && ($user->isAdmin() || $user->isSuperAdmin());

        $row = TicketProduct::query()
            ->where('product_code', $product)
            ->first();

        if (! $row instanceof TicketProduct) {
            return ApiResponse::error(
                code: 'ticket_product_not_found',
                message: 'Ticket product not found.',
                status: 404,
            );
        }

        if (! $isOperator && $row->status !== TicketProductStatus::Active) {
            return ApiResponse::error(
                code: 'ticket_product_not_found',
                message: 'Ticket product not found.',
                status: 404,
            );
        }

        return ApiResponse::success(
            data: ['product' => (new TicketProductResource($row))->resolve($request)],
            message: 'Ticket product retrieved successfully.',
        );
    }
}
