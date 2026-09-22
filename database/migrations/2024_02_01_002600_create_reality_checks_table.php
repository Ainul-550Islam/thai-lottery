<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reality_checks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status', 16)->default('scheduled')->index();
            $table->string('session_reference', 64);
            $table->unsignedInteger('threshold_minutes');
            $table->timestamp('due_at')->index();
            $table->string('delivery_fingerprint', 64)->unique();
            $table->string('delivery_channel', 16)->default('in_app');
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->string('acknowledgement_fingerprint', 64)->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->unique(['user_id', 'session_reference', 'due_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reality_checks');
    }
};
