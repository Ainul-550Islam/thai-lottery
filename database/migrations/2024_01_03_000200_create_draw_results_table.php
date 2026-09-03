<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('draw_results', function (Blueprint $table): void {
            $table->id();
            // The published result is the official record of the draw and is
            // treated as history, exactly like winning_numbers: restrict, never
            // cascade.
            $table->foreignId('draw_id')->constrained()->restrictOnDelete();
            $table->string('first_prize', 16);
            $table->json('second_prize')->nullable();
            $table->json('third_prize')->nullable();
            $table->json('consolation_prizes')->nullable();
            $table->json('all_numbers')->nullable();
            $table->unsignedBigInteger('total_winners')->default(0);
            $table->decimal('total_payout', 24, 2)->default(0);
            $table->decimal('house_profit', 24, 2)->default(0);
            $table->timestamp('published_at')->nullable()->index();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique('draw_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('draw_results');
    }
};
