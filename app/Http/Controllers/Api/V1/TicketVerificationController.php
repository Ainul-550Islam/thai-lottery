<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\DTOs\Betting\TicketShareData;
use App\DTOs\Betting\TicketVerificationData;
use App\Exceptions\BetDomainException;
use App\Http\Requests\Api\V1\VerifyTicketRequest;
use App\Http\Resources\TicketShareResource;
use App\Http\Resources\TicketVerificationResource;
use App\Http\Responses\ApiResponse;
use App\Services\Betting\TicketShareService;
use App\Services\Betting\TicketVerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Ticket verification and bearer share-link endpoints.
 *
 * Transport-only; the privacy contract (coarse public verdicts, owner-scoped
 * money detail, enumeration-safe failures) is enforced by the services. This
 * controller wires the authenticated vs public caller to the right service
 * path and shapes the envelope.
 */
class TicketVerificationController
{
    public function __construct(
        private readonly TicketVerificationService $verification,
        private readonly TicketShareService $shares,
    ) {
    }

    /**
     * POST /api/v1/tickets/verify — owner-scoped when authenticated.
     */
    public function verify(VerifyTicketRequest $request): JsonResponse
    {
        $user = $request->user();

        try {
            $result = $this->verification->verify(TicketVerificationData::fromRequestArray(
                $user !== null ? (int) $user->getAuthIdentifier() : null,
                $request->validated(),
            ));
        } catch (BetDomainException $e) {
            return ApiResponse::error($e->codeKey(), $e->getMessage(), 422);
        }

        return ApiResponse::success(
            (new TicketVerificationResource($result))->toArray($request),
            $result->isFound() ? 'Ticket verified.' : 'No ticket with that number.',
        );
    }

    /**
     * POST /api/v1/tickets/{ticket}/share — create or rotate the caller's link.
     */
    public function share(Request $request, string $ticket): JsonResponse
    {
        $user = $request->user();
        abort_if($user === null, 401);

        $validated = $request->validate([
            'ttl_hours' => ['nullable', 'integer', 'min:1', 'max:720'],
        ]);

        try {
            $result = $this->shares->create(TicketShareData::fromRequestArray(
                (int) $user->getAuthIdentifier(),
                $ticket,
                $validated,
            ));
        } catch (BetDomainException $e) {
            return ApiResponse::error($e->codeKey(), $e->getMessage(), 422);
        }

        return ApiResponse::success(
            (new TicketShareResource($result))->toArray($request),
            'Share link created.',
        );
    }

    /**
     * DELETE /api/v1/tickets/shares/{share} — revoke the caller's own link.
     */
    public function revokeShare(Request $request, int $share): JsonResponse
    {
        $user = $request->user();
        abort_if($user === null, 401);

        try {
            $revoked = $this->shares->revoke($share, (int) $user->getAuthIdentifier());
        } catch (BetDomainException $e) {
            return ApiResponse::error($e->codeKey(), $e->getMessage(), 422);
        }

        return ApiResponse::success([
            'share_id' => (int) $revoked->getKey(),
            'status' => $revoked->status->value,
            'revoked_at' => $revoked->revoked_at?->toIso8601String(),
        ], 'Share link revoked.');
    }

    /**
     * GET /api/v1/tickets/shared/{token} — public; the route sits outside the
     * auth group by design. Possession of the token IS the authorization.
     */
    public function shared(Request $request, string $token): JsonResponse
    {
        try {
            $share = $this->shares->resolve($token);

            $result = $this->verification->verify(new TicketVerificationData(
                ticketNumber: (string) $share->ticket->ticket_number,
                ownerUserId: null,
            ));
        } catch (BetDomainException $e) {
            return ApiResponse::error($e->codeKey(), $e->getMessage(), 404);
        }

        return ApiResponse::success(
            (new TicketVerificationResource($result))->toArray($request),
            'Shared ticket.',
        );
    }
}
