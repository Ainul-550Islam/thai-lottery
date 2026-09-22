<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Deposit/withdrawal payment intents.
 *
 * The intent is the house's OWN pre-payment fact: who, which wallet,
 * how much, which way, bound to a deterministic idempotency key so a
 * retried create is the same fact, never a second one. The wallet is
 * bound at creation and may NEVER be rebound; the linked payment
 * aggregate (legacy lane) is attached once the provider is invoked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_intents', function (Blueprint $table): void {
            $table->id();
            $table->string('intent_key', 64)->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('wallet_id')->constrained('wallets')->restrictOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained('payments')->nullOnDelete();
            $table->string('direction', 16)->index();
            $table->string('method_code', 32)->index();
            $table->decimal('amount', 20, 2);
            $table->string('currency', 3);
            $table->string('status', 16)->default('initiated')->index();
            $table->string('idempotency_key', 96)->unique();
            $table->string('failure_reason', 32)->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamp('succeeded_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'direction', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_intents');
    }
};
