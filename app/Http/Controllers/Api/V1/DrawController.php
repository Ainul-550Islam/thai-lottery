<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\DrawStatus;
use App\Enums\DrawType;
use App\Http\Resources\DrawResource;
use App\Http\Resources\DrawResultResource;
use App\Http\Responses\ApiResponse;
use App\Models\Draw;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Player-facing read API for lottery draws and official results.
 */
final class DrawController
{
    /**
     * List lottery draws with status and type filtering and pagination.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Draw::query();

        // Optional status filter
        if ($request->filled('status')) {
            $status = DrawStatus::tryFrom((string) $request->query('status'));
            if ($status !== null) {
                $query->where('status', $status);
            }
        }

        // Optional type filter
        if ($request->filled('type')) {
            $type = DrawType::tryFrom((string) $request->query('type'));
            if ($type !== null) {
                $query->where('type', $type);
            }
        }

        $perPage = min(max((int) $request->query('per_page', 15), 1), 50);

        $draws = $query
            ->with(['result.winningNumbers', 'winningNumbers'])
            ->latest('scheduled_at')
            ->paginate($perPage);

        return ApiResponse::success(
            data: [
                'items' => DrawResource::collection($draws->items()),
                'pagination' => [
                    'current_page' => $draws->currentPage(),
                    'last_page' => $draws->lastPage(),
                    'per_page' => $draws->perPage(),
                    'total' => $draws->total(),
                ],
            ],
            message: 'Draws retrieved successfully.',
        );
    }

    /**
     * Retrieve the currently open draw for active wagering, or the nearest upcoming scheduled draw.
     */
    public function current(Request $request): JsonResponse
    {
        $openDraw = Draw::query()
            ->where('status', DrawStatus::Open)
            ->with(['result.winningNumbers', 'winningNumbers'])
            ->first();

        if ($openDraw instanceof Draw) {
            return ApiResponse::success(
                data: (new DrawResource($openDraw))->toArray($request),
                message: 'Current open draw retrieved.',
            );
        }

        $upcomingDraw = Draw::query()
            ->where('status', DrawStatus::Scheduled)
            ->where('scheduled_at', '>=', now())
            ->orderBy('scheduled_at')
            ->first();

        if ($upcomingDraw instanceof Draw) {
            return ApiResponse::success(
                data: (new DrawResource($upcomingDraw))->toArray($request),
                message: 'Next scheduled draw retrieved.',
            );
        }

        return ApiResponse::error(
            code: 'no_active_draw',
            message: 'No active or scheduled draw is available at the moment.',
            status: 404,
        );
    }

    /**
     * Retrieve draw details by ID or draw_number.
     */
    public function show(Request $request, string $draw): JsonResponse
    {
        $model = $this->resolve($draw);

        if (! $model instanceof Draw) {
            return ApiResponse::error(
                code: 'resource_not_found',
                message: 'The requested draw was not found.',
                status: 404,
            );
        }

        $model->load(['result.winningNumbers', 'winningNumbers']);

        return ApiResponse::success(
            data: (new DrawResource($model))->toArray($request),
            message: 'Draw details retrieved.',
        );
    }

    /**
     * Retrieve published winning results and prize breakdown for a draw.
     */
    public function results(Request $request, string $draw): JsonResponse
    {
        $model = $this->resolve($draw);

        if (! $model instanceof Draw) {
            return ApiResponse::error(
                code: 'resource_not_found',
                message: 'The requested draw was not found.',
                status: 404,
            );
        }

        if (! in_array($model->status, [DrawStatus::ResultPublished, DrawStatus::Completed], true) || $model->result === null) {
            return ApiResponse::error(
                code: 'results_not_available',
                message: 'Official results are not yet published for this draw.',
                status: 404,
            );
        }

        $model->result->load('winningNumbers');

        return ApiResponse::success(
            data: (new DrawResultResource($model->result))->toArray($request),
            message: 'Draw results retrieved successfully.',
        );
    }

    private function resolve(string $identifier): ?Draw
    {
        $query = Draw::query();

        if (ctype_digit($identifier)) {
            $query->whereKey((int) $identifier);
        } else {
            $query->where('draw_number', $identifier);
        }

        return $query->first();
    }
}
