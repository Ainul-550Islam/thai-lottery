<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_delivery_attempts', function (Blueprint $table): void {
            $table->id();
            $table->string('attempt_identity', 64)->unique();
            $table->foreignId('notification_id')->constrained('notifications')->cascadeOnDelete();
            $table->unsignedTinyInteger('attempt_number');
            $table->string('channel', 16);
            $table->string('status', 16)->index();
            $table->string('failure_reason', 32)->nullable();
            $table->string('provider_reference', 128)->nullable();
            $table->timestamp('attempted_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['notification_id', 'attempt_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_delivery_attempts');
    }
};
