<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trusted_devices', function (Blueprint $table): void {
            $table->id();
            $table->string('device_fingerprint', 64)->index();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('trust_status', 16)->default('pending')->index();
            $table->string('presentation_hash', 64);
            $table->string('evidence_fingerprint', 64);
            $table->timestamp('registered_at');
            $table->timestamp('trusted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoked_by', 96)->nullable();
            $table->string('revocation_reason', 128)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['user_id', 'device_fingerprint']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trusted_devices');
    }
};
