<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\DrawResultException;
use App\Http\Requests\Draw\ConfirmDrawResultRequest;
use App\Http\Requests\Draw\IngestDrawResultRequest;
use App\Http\Resources\DrawResultResource;
use App\Http\Responses\ApiResponse;
use App\Models\Draw;
use App\Models\DrawResult;
use App\Services\Draw\DrawResultConfirmationService;
use App\Services\Draw\DrawResultIngestionService;
use App\Services\Draw\DrawResultPublicationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Controlled draw-result ingestion / confirmation / publication API.
 *
 * THE MAKER / CHECKER BOUNDARY, AND WHERE IT LIVES
 * This controller performs the ADMITTANCE half of the maker/checker court
 * (whichever operator may speak at each step). It NEVER re-derives which
 * checker may confirm which ingestion, for that court the
 * DrawResultConfirmationService is the sole judge — being the recorder of
 * the four-eyes conversations is a lane, and this file is not a lane.
 *
 * ENDPOINT OWNERSHIP SUMMARY
 * - show: the PUBLISHED truth about a draw, in the public shape; operators
 *   may additionally switch to the authorized shape by passing an
 *   authorized read flag, demoted if absent.
 * - ingest: operator staging of an announced result. NEVER finalizes.
 * - confirm: second-pair-of-eyes boundary — a checker who is not the maker
 *   (and whose note + claimed result must be supplied) may confirm.
 * - publish: after confirmation, the one who promoted the result into
 *   public truth (the pipeline's own card serves as guarantee: only a
 *   Confirmed record passes, the service asserts this again).
 */
final class DrawResultController
{
    public function __construct(
        private readonly DrawResultIngestionService $ingestion,
        private readonly DrawResultConfirmationService $confirmation,
        private readonly DrawResultPublicationService $publication,
    ) {
    }

    /**
     * The published result for a draw. Not-found and never-published are
     * deliberately the same 404 (a draw without a result carries no public
     * truth at all), and unauth'd operators are demoted to public shape.
     */
    public function show(string $draw, Request $request): JsonResponse
    {
        if (! ctype_digit($draw) || (int) $draw < 1) {
            return ApiResponse::error(code: 'draw_not_found', message: 'Draw not found.', status: 404);
        }

        $result = DrawResult::query()->where('draw_id', (int) $draw)->first();

        if (! $result instanceof DrawResult) {
            return ApiResponse::error(code: 'draw_result_not_found', message: 'No published result for this draw.', status: 404);
        }

        $user = $request->user();
        $authorized = $user !== null && ($user->isAdmin() || $user->isSuperAdmin());

        $resource = new DrawResultResource($result);

        if (! $authorized) {
            $resource->forPublic();
        }

        return ApiResponse::success(
            data: ['result' => $resource->resolve($request)],
            message: 'Draw result retrieved successfully.',
        );
    }

    /**
     * Stage an announced result against a draw (the MAKER act).
     *
     * Nothing ingested here may pay out anything — this endpoint asserts the
     * shape only, and the ingestion service's own fingerprint replaces or
     * re-serves idempotently.
     */
    public function storeIngest(IngestDrawResultRequest $request): JsonResponse
    {
        $draw = Draw::query()->find((int) $request->route('draw'));

        if (! $draw instanceof Draw) {
            return ApiResponse::error(code: 'draw_not_found', message: 'Draw not found.', status: 404);
        }

        $user = $request->user();

        try {
            $record = $this->ingestion->ingest(
                (int) $draw->getKey(),
                $request->resultPayload(),
                $request->resultSource(),
                (int) $user->getAuthIdentifier(),
            );
        } catch (DrawResultException $e) {
            return ApiResponse::error(
                code: 'draw_result_ingestion_refused',
                message: $e->getMessage(),
                status: 422,
            );
        }

        return ApiResponse::success(
            data: ['ingestion' => $this->summarizeLane($record)],
            message: ($record['superseded'] ?? false) === true
                ? 'Result staged, replacing the earlier pending stage.'
                : 'Result staged successfully.',
            status: (($record['superseded'] ?? false) === true) ? 200 : 201,
        );
    }

    /**
     * Confirm a staged result (the CHECKER act — a different operator than
     * the maker, per the confirmation service's own boundary).
     */
    public function storeConfirmation(ConfirmDrawResultRequest $request): JsonResponse
    {
        $draw = Draw::query()->find((int) $request->route('draw'));

        if (! $draw instanceof Draw) {
            return ApiResponse::error(code: 'draw_not_found', message: 'Draw not found.', status: 404);
        }

        $user = $request->user();

        try {
            $record = $this->confirmation->confirm(
                (int) $draw->getKey(),
                (int) $user->getAuthIdentifier(),
                $request->claimedResult(),
            );
        } catch (DrawResultException $e) {
            return ApiResponse::error(
                code: 'draw_result_confirmation_refused',
                message: $e->getMessage(),
                status: 422,
            );
        }

        return ApiResponse::success(
            data: ['confirmation' => $this->summarizeLane($record)],
            message: 'Draw result confirmed. It is now eligible for publication.',
        );
    }

    /**
     * Promote a CONFIRMED result into public truth.
     *
     * The publication service re-asserts the confirmation state behind its
     * row lock; this controller merely names the operator who pressed the
     * button. Both the controller and the service independently reject a
     * publication without confirmation, which is how a double pretend-press
     * is never seen as a success.
     */
    public function storePublication(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user === null || ! ($user->isAdmin() || $user->isSuperAdmin())) {
            abort(403);
        }

        $draw = Draw::query()->find((int) $request->route('draw'));

        if (! $draw instanceof Draw) {
            return ApiResponse::error(code: 'draw_not_found', message: 'Draw not found.', status: 404);
        }

        // The publication lane does not read the confirmation card itself —
        // it validates whatever it is given, asserts the draw may publish,
        // and writes the official result. The maker/checker contract is
        // therefore completed HERE: the numbers offered to publication are
        // re-derived from the CONFIRMED ingestion record (never from the
        // request body), so publication always writes exactly what the
        // second pair of hands confirmed.
        $status = $this->confirmation->statusOf((int) $draw->getKey());

        if ($status !== \App\Enums\DrawConfirmationStatus::Confirmed) {
            return ApiResponse::error(
                code: 'draw_result_publication_refused',
                message: 'Only a confirmed draw result may be published.',
                status: 422,
            );
        }

        // PUBLICATION-PATH TRUTH IN THIS PIPELINE
        // The confirmation service publishes atomically inside confirm()
        // (its docblock: confirm owns the book into DrawResultPublication
        // — the checker press IS the publication). This endpoint therefore
        // serves the idempotent half of the operator's "make sure it's out"
        // gesture: if the confirmed draw already carries its published
        // result, this re-reads it and reports success; the duplicated
        // gesture is never minted as a second official result.
        if ($this->publication->hasPublishedResult((int) $draw->getKey())) {
            $existing = $this->publication->resultFor((int) $draw->getKey());

            return ApiResponse::success(
                data: [
                    'published' => [
                        'draw_id' => (int) $draw->getKey(),
                        'result_id' => $existing instanceof DrawResult ? (int) $existing->getKey() : null,
                        'first_prize' => $existing instanceof DrawResult ? (string) $existing->first_prize : null,
                        'bottom_two' => $existing instanceof DrawResult
                            ? (string) (is_array($existing->metadata) ? ($existing->metadata['bottom_two'] ?? $existing->metadata['two_digit_bottom'] ?? null) : null)
                            : null,
                        'already_published' => true,
                    ],
                ],
                message: 'The draw result is already published.',
            );
        }

        // Recover the CONFIRMED card (its status is Confirmed — by definition
        // no longer Pending, so the pending-only preview lane cannot see it;
        // the current-ingestion lane can) and re-derive the publication
        // payload from WHAT THE CHECKERS SAW, never from the request body.
        $record = $this->ingestion->currentIngestion((int) $draw->getKey());
        $payload = is_array($record['payload'] ?? null) ? $record['payload'] : [];

        if (! is_array($record) || ($payload['first_prize'] ?? '') === '') {
            return ApiResponse::error(
                code: 'draw_result_publication_refused',
                message: 'The confirmed result payload could not be recovered for publication.',
                status: 422,
            );
        }

        $input = [
            'first_prize' => (string) $payload['first_prize'],
            'bottom_two' => (string) ($payload['bottom_two'] ?? ''),
            'source' => (string) ($record['source'] ?? ''),
        ];

        try {
            $published = $this->publication->publish((int) $draw->getKey(), $input);
        } catch (DrawResultException | \App\Exceptions\DrawLifecycleException | \App\Exceptions\DrawResultValidationException $e) {
            return ApiResponse::error(
                code: 'draw_result_publication_refused',
                message: $e->getMessage(),
                status: 422,
            );
        }

        return ApiResponse::success(
            data: [
                'published' => [
                    'draw_id' => (int) $draw->getKey(),
                    'result_id' => isset($published['result']) && $published['result'] instanceof DrawResult
                        ? (int) $published['result']->getKey()
                        : null,
                    'first_prize' => isset($published['data']) ? (string) $published['data']->firstPrize() : null,
                    'bottom_two' => isset($published['data']) ? (string) $published['data']->bottomTwo() : null,
                    'winning_numbers' => isset($published['winning_numbers']) && is_countable($published['winning_numbers'])
                        ? count($published['winning_numbers'])
                        : 0,
                ],
            ],
            message: 'Draw result published.',
        );
    }

    /**
     * Reduce a service-record payload to the response-safe subset the
     * surface is allowed to print (nothing provenance-bearing beyond the
     * coarse identifiers the console shows).
     *
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    private function summarizeLane(array $record): array
    {
        $safe = [];

        foreach (['draw_id', 'status', 'fingerprint', 'source', 'staged_at', 'ingested_at', 'confirmed_at', 'maker_user_id', 'checker_user_id', 'superseded'] as $key) {
            if (array_key_exists($key, $record)) {
                $safe[$key] = $record[$key];
            }
        }

        return $safe;
    }
}
