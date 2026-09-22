<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;


use App\Http\Responses\ApiResponse;
use App\Models\Draw;
use App\Services\Lottery\GloPrizeCatalogue;
use App\Services\Lottery\GloResultService;
use App\Services\Lottery\GloTicketChecker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/*
 * SANDBOX RECONSTRUCTION — routes/api.php references this controller but it
 * was never committed. Minimal faithful implementation over the three
 * committed GLO services. Read-only: no money movement, no state writes.
 */
class GloController
{
    public function __construct(
        private readonly GloPrizeCatalogue $catalogue,
        private readonly GloResultService $results,
        private readonly GloTicketChecker $checker,
    ) {}

    public function prizes(): JsonResponse
    {
        return ApiResponse::success($this->catalogue->all(), 'GLO official prize structure');
    }

    public function draw(Request $request, string $draw): JsonResponse
    {
        $model = ctype_digit($draw)
            ? Draw::query()->find((int) $draw)
            : Draw::query()->where('draw_number', $draw)->orWhere('uuid', $draw)->first();

        if ($model === null) {
            return ApiResponse::error('not_found', 'Draw not found', 404);
        }

        return ApiResponse::success(
            $this->results->recordedForDraw((int) $model->getKey()),
            'GLO recorded official numbers for draw',
        );
    }

    public function checkTicket(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'draw_id' => ['required', 'integer', 'min:1'],
            'ticket_number' => ['required', 'string', 'regex:/^\d{6}$/'],
        ]);

        $draw = Draw::query()->find((int) $validated['draw_id']);

        if ($draw === null) {
            return ApiResponse::error('not_found', 'Draw not found', 404);
        }

        try {
            $result = $this->checker->check((int) $draw->getKey(), (string) $validated['ticket_number']);
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error('invalid_ticket', $e->getMessage(), 422);
        }

        return ApiResponse::success($result, 'Ticket checked against official numbers (informational only)');
    }
}
