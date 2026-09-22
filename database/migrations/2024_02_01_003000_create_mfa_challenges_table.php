<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mfa_challenges', function (Blueprint $table): void {
            $table->id();
            $table->string('challenge_key', 64)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status', 16)->default('pending')->index();
            $table->string('channel', 16)->default('totp');
            $table->string('secret_fingerprint', 64);
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('issued_at');
            $table->timestamp('expires_at')->index();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mfa_challenges');
    }
};
