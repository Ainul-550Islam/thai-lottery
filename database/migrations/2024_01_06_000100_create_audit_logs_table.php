<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * audit_logs
 *
 * Append-only trail of security and financial events. There is deliberately no
 * `updated_at` and no `deleted_at`: a written audit row is never edited and never
 * soft-deleted, which App\Models\AuditLog mirrors with UPDATED_AT = null and no
 * SoftDeletes trait.
 *
 * The actor reference is SET NULL on delete so that removing a user account can
 * never erase what that account did.
 *
 * SECURITY: passwords, password hashes, API keys, private keys, webhook secrets,
 * card data, decrypted withdrawal payout details and full authentication tokens
 * must never be written into old_values, new_values or metadata. The keys to strip
 * are listed in config/security.php under 'audit.sensitive_fields', replaced by
 * 'audit.redaction_placeholder'.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('action', 32)->index();
            $table->string('risk_level', 16)->nullable()->index();

            // Polymorphic audit target (nullable: some actions have no subject).
            $table->nullableMorphs('auditable');

            $table->string('description')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('url')->nullable();
            $table->string('method', 10)->nullable();

            // Correlation id so every row written during one HTTP request or
            // queued job can be replayed together.
            $table->uuid('request_id')->nullable()->index();

            $table->json('metadata')->nullable();

            // Append-only: created_at only, no updated_at, no soft deletes.
            $table->timestamp('created_at')->nullable()->index();

            $table->index(['user_id', 'action']);
            $table->index(['action', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
