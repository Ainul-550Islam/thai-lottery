<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Winner notification lanes.
 * ONE ROW per (claimant, payout, channel, dedupe-key) conversation;
 * status rotations in place; provider facts stay in metadata, never on
 * the public surface.
 */
return new class () extends Migration
{
    public function up(): void
    {
        Schema::create('winner_notifications', function (Blueprint $table): void {
            $table->id();

            $table->string('notification_key', 64)->unique();

            $table->foreignId('payout_id')->constrained('payouts')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();

            $table->string('channel', 16);
            $table->string('draw_reference', 64);
            $table->unsignedInteger('result_version');
            $table->string('dedupe_key', 64);

            $table->string('status', 16)->default('pending')->index();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->string('failure_reason', 255)->nullable();
            $table->unsignedSmallInteger('attempt_count')->default(0);

            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['status', 'channel']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('winner_notifications');
    }
};
