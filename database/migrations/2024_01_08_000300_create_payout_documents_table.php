<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payout statements: the winner-facing account of one completed payout —
 * gross prize, tax withheld, net paid, with claim/batch references.
 *
 * THE ENTITY THEORY (same as batches and ticket products):
 *   identity      document_key is sha256 of the payout reference; one
 *                 payout may have exactly ONE lawful statement, and its
 *                 uniqueness is enforced by the engine, not by care
 *   evidence      statements answer regulator/player questions years out;
 *                 they need stable, queryable rows joined to payouts
 *   lifecycle     PayoutDocumentStatus (draft/generated/issued/cancelled/
 *                 archived) lives on the row with explicit stamps
 *
 * MONEY IS DECIMAL(20,2) — never a float. The same structural identity
 * equation the statement documents proves in the service: gross = tax + net.
 * The table stores all three, so a projection can re-check the ledger
 * without contacting the payout row at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payout_documents', function (Blueprint $table): void {
            $table->id();

            // Deterministic identity: sha256('payout-statement:' + payout ref).
            $table->string('document_key', 64)->unique();

            // Human-facing statement number ('PST-' + anchor prefix).
            $table->string('statement_number', 40)->unique();

            $table->foreignId('payout_id')->constrained()->restrictOnDelete();
            $table->foreignId('claimant_user_id')->constrained('users')->restrictOnDelete();

            // Statement composition references.
            $table->string('payout_reference', 64)->index();
            $table->string('claim_reference', 64)->nullable();
            $table->string('batch_reference', 64)->nullable();

            $table->decimal('gross_amount', 20, 2);
            $table->decimal('tax_amount', 20, 2)->default(0);
            $table->decimal('net_amount', 20, 2);
            $table->string('currency', 3)->default('THB');

            // PayoutDocumentStatus value.
            $table->string('status', 32)->default('draft')->index();

            // Fact-time of completion + lifecycle stamps.
            $table->timestamp('completed_at');
            $table->timestamp('generated_at')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('archived_at')->nullable();

            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['claimant_user_id', 'status']);
            $table->index(['payout_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payout_documents');
    }
};
