<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\KycDocumentType;
use App\Http\Responses\ApiResponse;
use App\Models\KycDocument;
use App\Models\User;
use App\Services\Security\KycVerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Player API Controller for KYC status inquiry and document uploads.
 */
final class KycController
{
    public function __construct(
        private readonly KycVerificationService $kycService,
    ) {
    }

    /**
     * Get authenticated player's KYC status and submitted documents list.
     */
    public function status(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $documents = KycDocument::query()
            ->where('user_id', $user->id)
            ->latest('id')
            ->get();

        $kycStatus = $user->kycStatus();

        return ApiResponse::success(
            data: [
                'kyc_status' => $kycStatus->value,
                'is_verified' => $kycStatus->isVerified(),
                'documents' => $documents->map(fn (KycDocument $doc): array => [
                    'id' => $doc->id,
                    'document_type' => $doc->document_type instanceof \BackedEnum ? $doc->document_type->value : (string) $doc->document_type,
                    'document_number' => $doc->document_number,
                    'original_filename' => $doc->original_filename,
                    'mime_type' => $doc->mime_type,
                    'file_size' => $doc->file_size,
                    'status' => $doc->status instanceof \BackedEnum ? $doc->status->value : (string) $doc->status,
                    'rejection_reason' => $doc->rejection_reason,
                    'verified_at' => $doc->verified_at?->toIso8601String(),
                    'created_at' => $doc->created_at?->toIso8601String(),
                ])->all(),
            ],
            message: 'KYC status retrieved successfully.',
        );
    }

    /**
     * Upload an identity document for verification.
     */
    public function upload(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'document_type' => ['required', 'string', 'in:'.implode(',', array_column(KycDocumentType::cases(), 'value'))],
            'document_number' => ['nullable', 'string', 'max:100'],
            'document' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:10240'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $type = KycDocumentType::from($validated['document_type']);
        $file = $request->file('document');

        if ($file === null || ! $file->isValid()) {
            return ApiResponse::error(
                code: 'invalid_file',
                message: 'Uploaded file is invalid or missing.',
                status: 422,
            );
        }

        try {
            $document = $this->kycService->submitDocument(
                user: $user,
                type: $type,
                file: $file,
                documentNumber: $validated['document_number'] ?? null,
                ipAddress: $request->ip(),
            );
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error(
                code: 'kyc_upload_failed',
                message: $e->getMessage(),
                status: 422,
            );
        }

        return ApiResponse::success(
            data: [
                'document' => [
                    'id' => $document->id,
                    'document_type' => $document->document_type instanceof \BackedEnum ? $document->document_type->value : (string) $document->document_type,
                    'document_number' => $document->document_number,
                    'original_filename' => $document->original_filename,
                    'status' => $document->status instanceof \BackedEnum ? $document->status->value : (string) $document->status,
                    'created_at' => $document->created_at?->toIso8601String(),
                ],
            ],
            message: 'KYC document uploaded successfully and is pending review.',
            status: 201,
        );
    }
}
