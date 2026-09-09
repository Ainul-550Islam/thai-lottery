<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('responsible_gaming_limits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->restrictOnDelete();
            $table->decimal('daily_deposit_limit', 20, 2)->nullable();
            $table->decimal('single_bet_limit', 20, 2)->nullable();
            $table->decimal('daily_wagering_limit', 20, 2)->nullable();
            $table->timestamp('self_excluded_until')->nullable();
            $table->timestamp('cool_off_until')->nullable();
            $table->string('self_exclusion_reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('responsible_gaming_limits');
    }
};
