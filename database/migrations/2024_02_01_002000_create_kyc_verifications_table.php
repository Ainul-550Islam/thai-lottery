<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * User-level KYC verification decisions — the decision evidence rows.
 *
 * The verdict is DERIVED (from verified documents) and the row is the
 * immutable pronouncement of what was derived, from which document set
 * fingerprint, by which reviewer/source, at which moment. A new
 * decision never edits an old row; a superseded decision keeps its
 * facts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kyc_verifications', function (Blueprint $table): void {
            $table->id();
            $table->string('verification_reference', 96)->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('status', 16)->default('pending')->index();
            $table->string('document_set_fingerprint', 64)->index();
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source', 32)->default('system');
            $table->string('rejection_reason', 255)->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kyc_verifications');
    }
};
