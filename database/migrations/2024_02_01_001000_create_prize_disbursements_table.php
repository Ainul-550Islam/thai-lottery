<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prize disbursement rows.
 * ONE ROW = ONE settlement conversation (reservation → disbursement →
 * terminal) against a payout. Amounts are exact decimals; conservation
 * is the service's law, not a column constraint's.
 */
return new class () extends Migration
{
    public function up(): void
    {
        Schema::create('prize_disbursements', function (Blueprint $table): void {
            $table->id();

            $table->string('disbursement_key', 64)->unique();

            $table->foreignId('payout_id')->constrained('payouts')->restrictOnDelete();
            $table->foreignId('payout_batch_id')->nullable()->constrained('payout_batches')->nullOnDelete()->index();

            $table->decimal('amount', 20, 2);
            $table->string('currency', 3);
            $table->string('settlement_fingerprint', 64);

            $table->string('status', 16)->default('pending')->index();
            $table->timestamp('reserved_at')->nullable();
            $table->timestamp('disbursed_at')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('failure_reason', 255)->nullable();
            $table->string('reversal_reason', 255)->nullable();

            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['payout_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prize_disbursements');
    }
};
