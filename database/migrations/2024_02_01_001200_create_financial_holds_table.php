<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Compliance/settlement financial holds.
 * ONE ROW = ONE hold conversation: (wallet, source reference, amount).
 * Holds stage money; the legal trail (release/convert/expiry) is stamped
 * onto the row with its evidence.
 */
return new class () extends Migration
{
    public function up(): void
    {
        Schema::create('financial_holds', function (Blueprint $table): void {
            $table->id();

            $table->string('hold_key', 64)->unique();
            $table->foreignId('wallet_id')->constrained('wallets')->restrictOnDelete();

            $table->decimal('amount', 20, 2);
            $table->string('currency', 3);
            $table->string('reason', 255);
            $table->string('source_reference', 64);

            $table->string('status', 16)->default('active')->index();

            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamp('converted_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('expired_at')->nullable();

            // Release/expiry/conversion evidence always attaches the ACT,
            // never silently: {released_reason, released_at_iso, ...}.
            $table->json('evidence')->nullable();

            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['wallet_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_holds');
    }
};
