<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Draw reconciliation conversations.
 * ONE LIVE ROW PER DRAW: the current judgment. A resolved/matched row
 * closes the conversation; the next act for the same draw begins a new
 * row only when the first was terminal (otherwise the row rotates in
 * place and its drift lines stay visible).
 */
return new class () extends Migration
{
    public function up(): void
    {
        Schema::create('draw_reconciliations', function (Blueprint $table): void {
            $table->id();

            $table->string('reconciliation_key', 64)->unique();

            $table->foreignId('draw_id')->constrained()->restrictOnDelete()->index();
            $table->string('status', 32)->default('pending')->index();

            // Asserted vs actual, by lane — json so drift vocabulary can
            // name more lanes than today without a schema migration:
            // {result, tickets, prizes, payouts} per-lane {expected, actual}.
            $table->json('asserted_totals');
            $table->json('actual_totals');
            $table->json('drift_lines')->nullable();

            $table->string('resolved_note', 255)->nullable();
            $table->timestamp('resolved_at')->nullable();

            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('draw_reconciliations');
    }
};
