<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('player_protection_actions', function (Blueprint $table): void {
            $table->id();
            $table->string('action_key', 64)->unique();
            $table->string('case_key', 64)->index();
            $table->string('action_type', 32)->index();
            $table->string('scope', 32)->default('account');
            $table->foreignId('actor_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('reason_code', 96);
            $table->boolean('is_released')->default(false);
            $table->string('wallet_fact', 64)->nullable();
            $table->foreignId('wallet_id')->nullable()->constrained('wallets')->nullOnDelete();
            $table->timestamp('applied_at')->useCurrent();
            $table->timestamp('released_at')->nullable();
            $table->foreignId('released_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->index(['case_key', 'action_type', 'is_released']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('player_protection_actions');
    }
};
