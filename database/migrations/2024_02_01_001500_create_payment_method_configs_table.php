<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer-facing payment methods offered on a provider, per currency,
 * with hard money bounds. The (provider, method, currency) triple is
 * the method's identity — one row per offered triple.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_method_configs', function (Blueprint $table): void {
            $table->id();
            $table->string('method_key', 64)->unique();
            $table->foreignId('provider_id')->constrained('payment_providers')->cascadeOnDelete();
            $table->string('method_code', 32)->index();
            $table->string('currency', 3);
            $table->decimal('min_amount', 20, 2);
            $table->decimal('max_amount', 20, 2);
            $table->unsignedSmallInteger('fee_bps')->default(0);
            $table->string('status', 16)->default('pending')->index();
            $table->timestamp('retired_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['provider_id', 'method_code', 'currency'], 'pay_method_triple_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_method_configs');
    }
};
