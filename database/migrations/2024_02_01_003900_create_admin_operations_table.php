<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_operations', function (Blueprint $table): void {
            $table->id();
            $table->string('operation_fingerprint', 64)->unique();   // exactly-once propose
            $table->foreignId('actor_user_id')->constrained('users'); // maker
            $table->string('type', 40);                               // AdminOperationType
            $table->string('status', 20)->default('pending');         // AdminOperationStatus
            $table->string('target_lane', 24);                        // 'user' | 'wallet' | ...
            $table->string('target_reference', 96)->nullable();       // subject identity (never free text)
            $table->string('payload_fingerprint', 64);                // validated parameters
            $table->json('payload');                                  // sealed evidence-bearing parameters
            $table->string('evidence_fingerprint', 64)-> nullable();  // proof bundle digest
            $table->foreignId('approver_user_id')->nullable()->constrained('users'); // checker (≠ maker)
            $table->text('approval_note')->nullable();
            $table->string('denial_reason', 96)->nullable();
            $table->string('result_summary', 160)->nullable();        // outcome seat note (no secrets)
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['target_lane', 'target_reference']);
            $table->index('actor_user_id');
            $table->index('approver_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_operations');
    }
};
