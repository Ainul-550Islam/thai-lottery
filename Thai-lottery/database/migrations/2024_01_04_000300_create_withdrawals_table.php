<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * withdrawals
 *
 * A request to move money out of a wallet, plus its manual review trail.
 *
 * SECURITY: `payout_details` holds the beneficiary information (bank account or
 * wallet number) and is written through Laravel's encrypted cast by
 * App\Models\Withdrawal, which is why the column is TEXT rather than JSON - the
 * stored value is ciphertext, not queryable JSON. No gateway credentials, API
 * keys, passwords or card data are stored in this table.
 *
 * `requested_at` records when the customer asked; `completed_at` when the money
 * actually left. The review columns (reviewed_by / reviewed_at / approved_at /
 * rejected_at) exist so an approval can always be attributed to a human.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('withdrawals', function (Blueprint $table): void {
            $table->id();

            $table->uuid('uuid')->nullable()->unique();
            $table->string('reference_number', 64)->unique();

            // Payment history: restrict, never cascade.
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('wallet_id')->constrained()->restrictOnDelete();
            $table->foreignId('financial_transaction_id')->nullable()
                ->constrained()->nullOnDelete();

            $table->string('method', 32)->index();
            $table->string('provider', 32)->nullable()->index();
            $table->string('provider_reference', 191)->nullable()->index();

            $table->string('status', 32)->default('pending')->index();
            $table->string('currency', 3)->default('THB');

            $table->decimal('amount', 20, 2);
            $table->decimal('fee', 20, 2)->default(0);
            $table->decimal('net_amount', 20, 2);

            // Replay protection for provider callbacks and retried commands.
            $table->string('idempotency_key', 128)->nullable()->unique();

            // Encrypted beneficiary payload (ciphertext, therefore TEXT).
            $table->text('payout_details')->nullable();

            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->string('rejection_reason')->nullable();

            $table->json('metadata')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'status']);
            $table->index(['status', 'created_at']);
            $table->index(['provider', 'provider_reference'], 'withdrawals_provider_ref_lookup_index');
        });

        $this->addMoneyGuards();
    }

    public function down(): void
    {
        Schema::dropIfExists('withdrawals');
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

        DB::statement('ALTER TABLE `withdrawals` ADD CONSTRAINT `withdrawals_amount_positive` CHECK (`amount` > 0)');
        DB::statement('ALTER TABLE `withdrawals` ADD CONSTRAINT `withdrawals_fee_non_negative` CHECK (`fee` >= 0)');
        DB::statement('ALTER TABLE `withdrawals` ADD CONSTRAINT `withdrawals_net_amount_non_negative` CHECK (`net_amount` >= 0)');
    }
};
