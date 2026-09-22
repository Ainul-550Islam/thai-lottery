<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records for bet amendment requests.
 *
 * REPORT TABLE, NOT A MONEY TABLE
 * refunded_amount and charged_amount are snapshots of money that moved through
 * FinancialTransactionService and BetPurchaseService respectively; the ledger is
 * the authority for the money itself. The amounts live here so an operator can
 * answer "what did this amendment do" without replaying ledger entries.
 *
 * old_stake is NOT NULL: an amendment always knows the stake it refunded.
 * new_stake is NULL when the player only changed the number — the replacement is
 * purchased for the SAME stake. charged_amount records what was actually debited
 * for the replacement (equal to the replacement's committed stake), and stays
 * NULL while status is pending/failed.
 *
 * IDEMPOTENCY
 * idempotency_key is nullable-unique like bets.idempotency_key: sqlite/mysql both
 * allow many NULLs, so retries carry a key and first-time fire-and-forget calls
 * carry none.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bet_amendments', function (Blueprint $table): void {
            $table->id();

            $table->uuid('uuid')->nullable()->unique();

            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('bet_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('replacement_bet_id')->nullable()->index();

            $table->string('status', 32)->default('pending')->index();
            $table->string('currency', 3)->default('THB');

            $table->string('old_number', 16);
            $table->decimal('old_stake', 20, 2);
            $table->string('new_number', 16)->nullable();
            $table->decimal('new_stake', 20, 2)->nullable();

            $table->decimal('refunded_amount', 20, 2)->default(0);
            $table->decimal('charged_amount', 20, 2)->nullable();

            $table->string('failure_reason')->nullable();

            $table->string('idempotency_key', 128)->nullable()->unique();

            $table->timestamp('applied_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();

            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['bet_id', 'status']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bet_amendments');
    }
};
