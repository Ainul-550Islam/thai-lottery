<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('responsible_gaming_limit_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('limit_type', 32)->index();
            $table->string('limit_status', 16)->default('pending')->index();
            $table->decimal('amount', 14, 2);
            $table->string('currency', 3)->default('THB');
            $table->string('limit_key', 64)->unique();
            $table->string('replaced_by_key', 64)->nullable();
            $table->timestamp('effective_from')->nullable();
            $table->timestamp('effective_to')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('expired_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->index(['user_id', 'limit_type', 'limit_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('responsible_gaming_limit_versions');
    }
};
