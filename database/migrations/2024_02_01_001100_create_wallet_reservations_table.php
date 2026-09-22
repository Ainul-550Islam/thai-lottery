<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wallet reservations: money staged for a purpose.
 * ONE ROW = ONE reservation conversation (wallet, reference, amount).
 * Mechanical money movement rides the wallet's own lock lane; this
 * table owns identity, lifecycle, and evidence of each question asked.
 */
return new class () extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_reservations', function (Blueprint $table): void {
            $table->id();

            $table->string('reservation_key', 64)->unique();
            $table->foreignId('wallet_id')->constrained('wallets')->restrictOnDelete();

            $table->string('reference', 64);
            $table->decimal('amount', 20, 2);
            $table->string('currency', 3);
            $table->string('purpose', 32);

            $table->string('status', 16)->default('pending')->index();

            $table->timestamp('reserved_at')->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('expired_at')->nullable();

            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['wallet_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_reservations');
    }
};
