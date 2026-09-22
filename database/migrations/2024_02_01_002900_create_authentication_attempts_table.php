<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('authentication_attempts', function (Blueprint $table): void {
            $table->id();
            $table->string('attempt_fingerprint', 64)->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('method', 16)->default('password')->index();
            $table->string('identifier_hash', 64)->index();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->string('device_fingerprint', 64)->nullable();
            $table->string('outcome', 24)->index();
            $table->string('outcome_reason', 64)->nullable();
            $table->timestamp('attempted_at')->index();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['user_id', 'attempted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('authentication_attempts');
    }
};
