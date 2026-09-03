<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table): void {
            $table->id();
            $table->string('reference_number', 64)->unique();
            // A payment is financial history: deleting a user must never erase
            // gateway records, so deletion is restricted at database level.
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->nullableMorphs('payable');
            $table->string('method', 32)->index();
            $table->string('status', 32)->default('pending')->index();
            $table->string('currency', 3)->default('THB');
            $table->decimal('amount', 20, 2);
            $table->decimal('fee', 20, 2)->default(0);
            $table->string('gateway', 32)->nullable()->index();
            $table->string('gateway_reference')->nullable();
            $table->json('gateway_response')->nullable();
            $table->timestamp('authorized_at')->nullable();
            $table->timestamp('captured_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('failure_reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['gateway', 'gateway_reference'], 'payments_gateway_reference_unique');
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
