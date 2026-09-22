<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Provider-vs-internal reconciliation evidence.
 *
 * One LIVE conversation row per payment (reconciliation_key family
 * with generation suffix after a Resolved sealing — same discipline
 * as the wallet-liability lane). Drift is evidence, not an exception:
 * the row carries readable drift lines; the fail-closed gate is the
 * service's assertConsistent().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_reconciliations', function (Blueprint $table): void {
            $table->id();
            $table->string('reconciliation_key', 96)->unique();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 32)->index();
            $table->string('external_reference', 191)->index();
            $table->decimal('expected_amount', 20, 2);
            $table->decimal('observed_amount', 20, 2)->nullable();
            $table->string('currency', 3);
            $table->string('internal_status', 32);
            $table->string('observed_status', 32)->nullable();
            $table->string('status', 24)->default('pending')->index();
            $table->string('fingerprint', 64);
            $table->json('drift_lines')->nullable();
            $table->string('resolved_note', 255)->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['provider', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_reconciliations');
    }
};
