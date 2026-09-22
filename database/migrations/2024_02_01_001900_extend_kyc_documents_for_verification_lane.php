<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive extension of kyc_documents for the batch-13 authoritative
 * verification lane: issuer country, document fingerprint (identity of
 * the BYTES), expiry, verification reference and the spec-lifecycle
 * status column `verification_status` (legacy `status` stays and is
 * kept in correspondence so legacy gates never go blind).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kyc_documents', function (Blueprint $table): void {
            $table->string('verification_status', 16)->default('uploaded')->index()->after('status');
            $table->string('issuer_country', 2)->nullable()->after('document_type');
            $table->string('document_fingerprint', 64)->nullable()->index()->after('document_number');
            $table->string('verification_reference', 96)->nullable()->index()->after('verified_by');
            $table->timestamp('expires_at')->nullable()->index()->after('verified_at');

            $table->unique(['user_id', 'document_fingerprint'], 'kyc_doc_user_fingerprint_unique');
        });
    }

    public function down(): void
    {
        Schema::table('kyc_documents', function (Blueprint $table): void {
            $table->dropUnique('kyc_doc_user_fingerprint_unique');
            $table->dropColumn([
                'verification_status',
                'issuer_country',
                'document_fingerprint',
                'verification_reference',
                'expires_at',
            ]);
        });
    }
};
