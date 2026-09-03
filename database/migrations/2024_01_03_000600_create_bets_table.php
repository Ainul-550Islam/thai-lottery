<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * bets
 *
 * One bet aggregate placed by a user inside a draw. The staked money is
 * `stake_amount` (DECIMAL(20,2)) - this is the column the existing
 * App\Models\Bet uses as the bet total; no second `total_amount` column is
 * introduced because two columns holding the same money would be able to
 * disagree.
 *
 * Circular dependency: `ticket_id` points forward to tickets (created earlier in
 * the migration order, so the constraint is safe here), while `payout_id` points
 * to payouts, which is created later. `payout_id` is therefore declared here as
 * a plain nullable indexed column and its foreign key is attached in the payouts
 * migration. No schema cycle blocks a fresh install.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bets', function (Blueprint $table): void {
            $table->id();

            $table->uuid('uuid')->nullable()->unique();
            $table->string('bet_number', 64)->unique();

            // Bets are financial history: restrict, never cascade.
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('draw_id')->constrained()->restrictOnDelete();
            $table->foreignId('ticket_id')->nullable()->constrained()->nullOnDelete();

            // Deferred foreign key, attached in the payouts migration.
            $table->unsignedBigInteger('payout_id')->nullable()->index();

            $table->string('type', 16)->index();
            $table->string('status', 32)->default('pending')->index();
            $table->string('currency', 3)->default('THB');

            $table->decimal('stake_amount', 20, 2);
            $table->decimal('potential_payout', 20, 2)->default(0);
            $table->decimal('actual_payout', 20, 2)->default(0);
            $table->unsignedInteger('total_numbers')->default(0);

            // Replay protection for retried bet submissions.
            $table->string('idempotency_key', 128)->nullable()->unique();

            $table->timestamp('placed_at')->nullable()->index();
            $table->timestamp('won_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancelled_reason')->nullable();

            $table->json('metadata')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'status']);
            $table->index(['draw_id', 'type', 'status']);
            $table->index(['user_id', 'created_at']);
        });

        $this->addMoneyGuards();
    }

    public function down(): void
    {
        Schema::dropIfExists('bets');
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

        DB::statement('ALTER TABLE `bets` ADD CONSTRAINT `bets_stake_amount_positive` CHECK (`stake_amount` > 0)');
        DB::statement('ALTER TABLE `bets` ADD CONSTRAINT `bets_actual_payout_non_negative` CHECK (`actual_payout` >= 0)');
    }
};
