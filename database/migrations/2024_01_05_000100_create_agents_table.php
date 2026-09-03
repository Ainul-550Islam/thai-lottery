<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agents', function (Blueprint $table): void {
            $table->id();
            $table->string('agent_code', 32)->unique();
            // An agent row carries commission totals, so deleting the user must
            // not silently destroy that accounting history.
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('parent_agent_id')->nullable()
                ->constrained('agents')->nullOnDelete();
            $table->string('status', 32)->default('inactive')->index();
            $table->string('currency', 3)->default('THB');
            $table->decimal('commission_rate', 8, 4)->default(0);
            $table->unsignedBigInteger('total_referrals')->default(0);
            $table->decimal('total_commission_earned', 24, 2)->default(0);
            $table->decimal('total_commission_paid', 24, 2)->default(0);
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->string('suspension_reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agents');
    }
};
