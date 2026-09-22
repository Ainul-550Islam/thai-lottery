<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Applied compliance actions — one row per deterministic act fact.
 *
 * The action_key is the act's identity (sha-256 over case, type,
 * reason, evidence): re-delivering the same act replays the row;
 * a different act under the same key is a fork. Wallet-touching acts
 * stamp the wallet fact they produced so Release can PROVE it is the
 * court lifting its own hold — a release against someone else's hold
 * is refused with the conflict named.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compliance_actions', function (Blueprint $table): void {
            $table->id();
            $table->string('action_key', 64)->unique();
            $table->foreignId('case_id')->constrained('compliance_cases')->cascadeOnDelete();
            $table->string('action_type', 16)->index();
            $table->string('reason_code', 32);
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->string('evidence_reference', 96);
            $table->boolean('is_released')->default(false)->index();
            $table->string('wallet_fact', 191)->nullable();
            $table->timestamp('applied_at');
            $table->timestamp('released_at')->nullable();
            $table->string('release_note', 255)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compliance_actions');
    }
};
