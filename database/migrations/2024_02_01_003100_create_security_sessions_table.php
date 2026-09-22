<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Named to stand beside (never against) Laravel's own `sessions`.
        Schema::create('security_sessions', function (Blueprint $table): void {
            $table->id();
            $table->string('session_fingerprint', 64)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status', 16)->default('active')->index();
            $table->string('device_fingerprint', 64)->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('issued_at');
            $table->timestamp('expires_at')->index();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoked_by', 96)->nullable();
            $table->string('revocation_reason', 128)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_sessions');
    }
};
