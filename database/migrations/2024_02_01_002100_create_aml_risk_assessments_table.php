<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AML risk assessments — versioned, deterministic, replay-safe.
 *
 * The assessment is a pure function of the evidence fingerprint:
 * same facts in, same row out (replay); new facts yield a NEW
 * version, superseding the old one (never editing it).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('aml_risk_assessments', function (Blueprint $table): void {
            $table->id();
            $table->string('assessment_key', 64)->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('risk_level', 16)->index();
            $table->unsignedSmallInteger('score');
            $table->json('reason_codes');
            $table->string('assessment_version', 16);
            $table->string('evidence_fingerprint', 64)->index();
            $table->string('status', 16)->default('current')->index();
            $table->timestamp('assessed_at');
            $table->timestamp('superseded_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'evidence_fingerprint'], 'aml_user_evidence_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aml_risk_assessments');
    }
};
