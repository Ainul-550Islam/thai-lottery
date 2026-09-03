<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * financial_transactions
 *
 * One atomic money movement. Every row must be balanced by the ledger entries
 * that reference it (sum of debits = sum of credits), which the future ledger
 * service enforces; the schema only guarantees structure.
 *
 * Deletion semantics: a transaction is auditable history. user_id and wallet_id
 * are therefore SET NULL on delete (the record survives the actor) and the
 * ledger entries that reference this row are RESTRICT (see ledger_entries), so
 * accounting history can never be cascaded away.
 *
 * `uuid` is nullable: the numeric primary key remains the model key, and the
 * existing App\Models\FinancialTransaction does not yet generate UUIDs. Adding
 * a HasUuids-style generator is a model change and belongs to a later batch.
 * `reference_type` / `reference_id` are prepared for the polymorphic link to the
 * originating domain record (deposit, withdrawal, bet, payout).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_transactions', function (Blueprint $table): void {
            $table->id();

            $table->uuid('uuid')->nullable()->unique();
            $table->string('reference_number', 64)->unique();

            // System transactions may exist without an owning user or wallet.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('wallet_id')->nullable()->constrained()->nullOnDelete();

            $table->string('type', 32)->index();
            $table->string('status', 32)->default('pending')->index();
            $table->string('currency', 3)->default('THB');

            $table->decimal('amount', 20, 2);
            $table->decimal('fee', 20, 2)->default(0);

            $table->string('description')->nullable();
            $table->json('metadata')->nullable();

            // Replay protection for gateway callbacks and retried commands.
            $table->string('idempotency_key', 128)->nullable()->unique();

            // Originating domain record, resolved by the ledger service.
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();

            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->timestamp('processed_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'type', 'status']);
            $table->index(['wallet_id', 'created_at']);
            $table->index(['status', 'created_at']);
            $table->index(['reference_type', 'reference_id']);
        });

        $this->addMoneyGuards();
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_transactions');
    }

    /**
     * MySQL/MariaDB-only CHECK constraints (MySQL 8.0.16+ enforces them).
     * Skipped on other drivers, including the SQLite test database.
     */
    private function addMoneyGuards(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        DB::statement('ALTER TABLE `financial_transactions` ADD CONSTRAINT `financial_transactions_amount_positive` CHECK (`amount` > 0)');
        DB::statement('ALTER TABLE `financial_transactions` ADD CONSTRAINT `financial_transactions_fee_non_negative` CHECK (`fee` >= 0)');
    }
};
