<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inbound provider webhook envelopes — the paper evidence of WHAT the
 * provider told us. Exactly-once is structural: the payload
 * fingerprint is UNIQUE, so the same bytes never become two facts,
 * and Duplicate is a pronoun for a second sighting of a known fact —
 * a no-op with evidence of its own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_webhooks', function (Blueprint $table): void {
            $table->id();
            $table->string('webhook_key', 64)->unique();
            $table->string('provider', 32)->index();
            $table->string('event_id', 96)->index();
            $table->string('event_type', 96);
            $table->string('signature', 255)->nullable();
            $table->json('payload');
            $table->string('payload_fingerprint', 64)->unique();
            $table->string('status', 16)->default('received')->index();
            $table->string('status_reason', 255)->nullable();
            $table->timestamp('received_at');
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->unsignedInteger('sightings')->default(1);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'event_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_webhooks');
    }
};
