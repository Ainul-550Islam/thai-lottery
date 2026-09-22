<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Eligibility decisions.
 * ONE ROW per (payout, principal) decision-space: re-evaluation rotates
 * status in place (every rotation pronounced via the service).
 */
return new class () extends Migration
{
    public function up(): void
    {
        Schema::create('prize_eligibility_decisions', function (Blueprint $table): void {
            $table->id();

            $table->string('decision_key', 64)->unique();
            $table->foreignId('payout_id')->constrained('payouts')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();

            $table->string('status', 16)->default('pending')->index();

            // The whole snapshot the decision was born from.
            $table->json('snapshot');

            $table->string('refusal_code', 48)->nullable();
            $table->string('refusal_reason', 255)->nullable();

            $table->timestamps();

            $table->index(['payout_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prize_eligibility_decisions');
    }
};
