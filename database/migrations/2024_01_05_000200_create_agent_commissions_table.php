<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_commissions', function (Blueprint $table): void {
            $table->id();
            $table->string('reference_number', 64)->unique();
            // Commission rows are accounting history: restrict, never cascade.
            $table->foreignId('agent_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('bet_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('draw_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('financial_transaction_id')->nullable()
                ->constrained()->nullOnDelete();
            $table->string('status', 32)->default('accrued')->index();
            $table->string('currency', 3)->default('THB');
            $table->decimal('base_amount', 20, 2);
            $table->decimal('commission_rate', 8, 4);
            $table->decimal('commission_amount', 20, 2);
            $table->timestamp('accrued_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['agent_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_commissions');
    }
};
