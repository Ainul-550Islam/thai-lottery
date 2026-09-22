<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payment gateway providers as the house registers them.
 *
 * SECRETS: none live here. `non_secrets` carries only non-sensitive
 * configuration (endpoints' public metadata, fee tables, feature
 * flags); credentials stay in env/config and are never persisted or
 * logged. The registry refuses to store anything sensitive by
 * construction: the column set simply has no home for it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_providers', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name', 64);
            $table->string('driver', 32)->index();
            $table->string('status', 16)->default('pending')->index();
            $table->json('supported_currencies');
            $table->json('non_secrets')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->timestamp('disabled_at')->nullable();
            $table->string('status_reason', 255)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_providers');
    }
};
