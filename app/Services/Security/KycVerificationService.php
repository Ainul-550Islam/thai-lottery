<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Enums\AuditAction;
use App\Enums\KycDocumentType;
use App\Enums\KycStatus;
use App\Models\AuditLog;
use App\Models\KycDocument;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * KYC Document Upload, Private Storage, and Verification Lifecycle Service.
 */
final class KycVerificationService
{
    private const ALLOWED_MIMES = [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'image/webp',
    ];

    private const MAX_FILE_SIZE_BYTES = 10 * 1024 * 1024; // 10 MB

    /**
     * Submit an identity document to private local storage for verification.
     */
    public function submitDocument(
        User $user,
        KycDocumentType $type,
        UploadedFile $file,
        ?string $documentNumber = null,
        ?string $ipAddress = null,
    ): KycDocument {
        if (! $file->isValid()) {
            throw new InvalidArgumentException('Uploaded file is invalid or corrupted.');
        }

        $mime = $file->getMimeType() ?? $file->getClientMimeType();
        if (! in_array($mime, self::ALLOWED_MIMES, true)) {
            throw new InvalidArgumentException('Invalid document file type. Allowed: PDF, JPG, PNG, WEBP.');
        }

        $size = $file->getSize();
        if ($size > self::MAX_FILE_SIZE_BYTES) {
            throw new InvalidArgumentException('Document exceeds maximum size limit of 10MB.');
        }

        // Store file in secure non-public storage with unguessable hashed filename
        $extension = $file->getClientOriginalExtension();
        $safeName = sprintf('kyc_%d_%s.%s', $user->id, Str::random(32), $extension);
        $path = $file->storeAs('kyc_documents/'.$user->id, $safeName, 'local');

        $document = KycDocument::create([
            'user_id' => $user->id,
            'document_type' => $type,
            'document_number' => $documentNumber ? trim($documentNumber) : null,
            'file_path' => $path,
            'original_filename' => $file->getClientOriginalName(),
            'mime_type' => $mime,
            'file_size' => $size,
            'status' => KycStatus::Pending,
            'metadata' => [
                'submission_ip' => $ipAddress,
                'submitted_at' => now()->toIso8601String(),
            ],
        ]);

        AuditLog::create([
            'user_id' => $user->id,
            'action' => AuditAction::Create,
            'auditable_type' => KycDocument::class,
            'auditable_id' => $document->id,
            'ip_address' => $ipAddress,
            'metadata' => [
                'action_type' => 'kyc_document_submitted',
                'document_type' => $type->value,
                'document_id' => $document->id,
            ],
        ]);

        return $document;
    }

    /**
     * Review and approve/reject a KYC document by a compliance admin.
     */
    public function reviewDocument(
        KycDocument $document,
        User $reviewer,
        bool $approved,
        ?string $rejectionReason = null,
    ): void {
        $newStatus = $approved ? KycStatus::Verified : KycStatus::Rejected;

        $document->status = $newStatus;
        $document->verified_at = now();
        $document->verified_by = $reviewer->id;
        $document->rejection_reason = $approved ? null : $rejectionReason;
        $document->save();

        if ($document->user && \Illuminate\Support\Facades\Schema::hasColumn('users', 'kyc_status')) {
            $document->user->kyc_status = $newStatus;
            $document->user->save();
        }

        AuditLog::create([
            'user_id' => $reviewer->id,
            'action' => AuditAction::Update,
            'auditable_type' => KycDocument::class,
            'auditable_id' => $document->id,
            'metadata' => [
                'action_type' => $approved ? 'kyc_document_approved' : 'kyc_document_rejected',
                'target_user_id' => $document->user_id,
                'status' => $newStatus->value,
                'rejection_reason' => $rejectionReason,
            ],
        ]);
    }
}
