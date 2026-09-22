<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prize match records.
 * ONE ROW = ONE deterministic match conversation: (draw, bet, tier,
 * amount, result fingerprint). Rejected/Verified rotations happen in
 * place; history is on audit_log, dedupe is the match_key.
 */
return new class () extends Migration
{
    public function up(): void
    {
        Schema::create('prize_matches', function (Blueprint $table): void {
            $table->id();

            // Deterministic identity.
            $table->string('match_key', 64)->unique();

            $table->foreignId('draw_id')->constrained()->restrictOnDelete();
            $table->foreignId('bet_id')->constrained()->restrictOnDelete();
            $table->foreignId('ticket_id')->nullable()->constrained()->nullOnDelete()->index();

            $table->string('prize_tier', 32);
            $table->decimal('matched_amount', 20, 2);
            $table->string('result_fingerprint', 64);

            $table->string('status', 16)->default('unmatched')->index();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->string('rejected_reason', 255)->nullable();

            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['draw_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prize_matches');
    }
};
