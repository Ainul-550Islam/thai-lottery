<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * payouts
 *
 * The obligation to pay a winner, and the record that it was paid. A payout row
 * must outlive its user, bet and ticket, so every owner reference is either
 * RESTRICT (user, draw) or SET NULL (bet, ticket, wallet, transaction).
 *
 * `multiplier` uses DECIMAL(20,4): Thai lottery multipliers are integral today
 * (for example 900x for 3D), but agent and promotional schemes need fractional
 * precision, and a payout multiplier must never be a float.
 *
 * Idempotency: `reference_number` is unique, which is what makes crediting a
 * winner safely repeatable - the payout engine claims the reference first and a
 * duplicate attempt collides at database level.
 *
 * `processed_at` is the moment the money actually moved (the column the existing
 * App\Models\Payout uses); no separate `paid_at` column is added because it would
 * be the same fact stored twice.
 *
 * This migration also closes the bets <-> payouts cycle by attaching the
 * deferred foreign key on bets.payout_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payouts', function (Blueprint $table): void {
            $table->id();

            $table->uuid('uuid')->nullable()->unique();
            $table->string('reference_number', 64)->unique();

            $table->foreignId('draw_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();

            $table->foreignId('bet_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('ticket_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('wallet_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('financial_transaction_id')->nullable()
                ->constrained()->nullOnDelete();

            $table->string('status', 32)->default('pending')->index();
            $table->string('currency', 3)->default('THB');

            $table->decimal('amount', 20, 2);
            $table->decimal('multiplier', 20, 4)->nullable();

            $table->timestamp('processed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('failure_reason')->nullable();

            $table->json('metadata')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['draw_id', 'status']);
            $table->index(['user_id', 'status']);
            $table->index(['status', 'created_at']);
        });

        // Deferred FK: bets.payout_id -> payouts.id (declared in the bets
        // migration as a plain column to avoid a circular table dependency).
        Schema::table('bets', function (Blueprint $table): void {
            $table->foreign('payout_id')->references('id')->on('payouts')->nullOnDelete();
        });

        $this->addMoneyGuards();
    }

    public function down(): void
    {
        Schema::table('bets', function (Blueprint $table): void {
            $table->dropForeign(['payout_id']);
        });

        Schema::dropIfExists('payouts');
    }

    /**
     * MySQL/MariaDB-only guards (CHECK enforced from MySQL 8.0.16). Skipped on
     * other drivers such as the SQLite database used by the test suite.
     */
    private function addMoneyGuards(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        DB::statement('ALTER TABLE `payouts` ADD CONSTRAINT `payouts_amount_positive` CHECK (`amount` > 0)');
        DB::statement('ALTER TABLE `payouts` ADD CONSTRAINT `payouts_multiplier_non_negative` CHECK (`multiplier` IS NULL OR `multiplier` >= 0)');
    }
};
