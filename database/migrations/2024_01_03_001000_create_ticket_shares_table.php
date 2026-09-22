<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Revocable bearer share links for tickets.
 *
 * TOKEN COLUMN
 * token_hash stores SHA-256(raw token) and is unique. The raw token is 32 random
 * bytes (256 bits) base64url-encoded, so the hash column is never a usable
 * credential: knowing the table contents grants nothing, and the uniqueness
 * constraint makes an accidental duplicate token impossible to persist.
 *
 * EXPIRY
 * expires_at is read-compared on every resolution (compute-on-read), so expiry
 * does not depend on a sweeper. The sweeper only backfills the stored status for
 * reporting; a lapsed link is dead regardless.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_shares', function (Blueprint $table): void {
            $table->id();

            $table->uuid('uuid')->nullable()->unique();

            $table->foreignId('ticket_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();

            $table->string('token_hash', 64)->unique();

            $table->string('status', 16)->default('active')->index();
            $table->unsignedInteger('views')->default(0);

            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('revoked_at')->nullable();

            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['ticket_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_shares');
    }
};
