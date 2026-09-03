<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * deposits
 *
 * A request to move money into a wallet.
 *
 * SECURITY: this table stores no raw card data, no CVV, no PAN, no payment
 * passwords and no gateway credentials. Only a non-secret `provider_reference`
 * (the identifier the provider itself prints on the transaction) may be kept, so
 * that a payment can be reconciled with the provider's own statement.
 *
 * `method` carries the App\Enums\PaymentMethod value; `provider` names the
 * concrete gateway integration that handled it, which can differ from the method
 * (for example method = bank_transfer handled by different acquirers).
 *
 * `confirmed_at` is the completion timestamp used by App\Models\Deposit, so no
 * duplicate `completed_at` column is introduced.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deposits', function (Blueprint $table): void {
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

            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('failure_reason')->nullable();

            $table->json('metadata')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'status']);
            $table->index(['status', 'created_at']);
            $table->index(['provider', 'provider_reference'], 'deposits_provider_ref_lookup_index');
        });

        $this->addMoneyGuards();
    }

    public function down(): void
    {
        Schema::dropIfExists('deposits');
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

        DB::statement('ALTER TABLE `deposits` ADD CONSTRAINT `deposits_amount_positive` CHECK (`amount` > 0)');
        DB::statement('ALTER TABLE `deposits` ADD CONSTRAINT `deposits_fee_non_negative` CHECK (`fee` >= 0)');
        DB::statement('ALTER TABLE `deposits` ADD CONSTRAINT `deposits_net_amount_non_negative` CHECK (`net_amount` >= 0)');
    }
};
