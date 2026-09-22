<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Compliance investigation cases.
 *
 * ONE LIVE conversation per (subject, case type): a live case re-opened
 * is a replay (facts agreement checks), never a duplicate file. The
 * case carries its trigger evidence fingerprint, assigned desk, and
 * the sealed act timestamps.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compliance_cases', function (Blueprint $table): void {
            $table->id();
            $table->string('case_key', 64)->unique();
            $table->foreignId('subject_user_id')->constrained('users')->restrictOnDelete();
            $table->string('case_type', 32)->index();
            $table->string('risk_level', 16)->index();
            $table->string('trigger_reference', 96)->nullable();
            $table->string('evidence_fingerprint', 64)->index();
            $table->string('status', 16)->default('open')->index();
            $table->string('assigned_desk', 32)->default('compliance');
            $table->string('escalation_reason', 255)->nullable();
            $table->string('resolution_note', 255)->nullable();
            $table->timestamp('investigating_since')->nullable();
            $table->timestamp('escalated_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['subject_user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compliance_cases');
    }
};
