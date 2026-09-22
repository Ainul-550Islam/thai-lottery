<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_receipts', function (Blueprint $table): void {
            $table->id();
            $table->string('receipt_fingerprint', 64)->unique();
            $table->foreignId('notification_id')->constrained('notifications')->cascadeOnDelete();
            $table->string('provider_reference', 128)->index();
            $table->string('delivery_state', 16)->index();
            $table->string('failure_reason', 32)->nullable();
            $table->timestamp('reported_at');
            $table->timestamp('created_at')->useCurrent();
            $table->index(['notification_id', 'delivery_state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_receipts');
    }
};
