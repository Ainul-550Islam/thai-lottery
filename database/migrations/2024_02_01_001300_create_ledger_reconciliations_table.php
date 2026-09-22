<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ledger reconciliation conversations.
 * ONE LIVE ROW PER WALLET: the current judgment; a terminal row is
 * history. A new comparison on an already-judged wallet opens a new
 * conversation only AFTER the prior one is terminal.
 */
return new class () extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_reconciliations', function (Blueprint $table): void {
            $table->id();

            $table->string('reconciliation_key', 64)->unique();
            $table->foreignId('wallet_id')->constrained('wallets')->restrictOnDelete()->index();

            $table->string('currency', 3);
            $table->string('scope', 32)->default('wallet-liability');

            $table->decimal('expected_balance', 24, 2);
            $table->decimal('ledger_aggregate', 24, 2);
            $table->decimal('reservation_effect', 24, 2)->default(0);
            $table->string('fingerprint', 64);

            $table->string('status', 16)->default('pending')->index();
            $table->json('drift_lines')->nullable();

            $table->string('resolved_note', 255)->nullable();
            $table->timestamp('resolved_at')->nullable();

            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['wallet_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_reconciliations');
    }
};
