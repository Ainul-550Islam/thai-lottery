<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('player_protection_cases', function (Blueprint $table): void {
            $table->id();
            $table->string('case_key', 64)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status', 16)->default('open')->index();
            $table->string('trigger_reason', 96)->index();
            $table->string('action_summary', 32)->nullable();
            $table->json('risk_indicators');
            $table->string('evidence_fingerprint', 64);
            $table->timestamp('opened_at')->useCurrent();
            $table->timestamp('monitoring_since')->nullable();
            $table->timestamp('escalated_at')->nullable();
            $table->string('escalated_by', 96)->nullable();
            $table->string('escalation_reason', 255)->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->string('resolved_by', 96)->nullable();
            $table->text('resolution_note')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->string('closed_by', 96)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('player_protection_cases');
    }
};
