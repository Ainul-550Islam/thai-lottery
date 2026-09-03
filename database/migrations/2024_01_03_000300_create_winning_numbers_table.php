<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * winning_numbers
 *
 * Normalised, published result rows - the single source of truth for what won a
 * draw. `bet_type` is the result family ('2d', '3d', 'tod', 'run'), matching
 * App\Enums\BetType, and `prize_tier` names the tier ('first', 'last_two', ...).
 * `position` further separates markets that share a tier, for example top versus
 * bottom.
 *
 * `number` is a string so leading zeros survive.
 *
 * The composite unique key prevents the same result being published twice for a
 * draw. Because MySQL treats NULLs as distinct in unique indexes, `position` is
 * deliberately left out of that key: the (draw, bet_type, number, prize_tier)
 * tuple must be unique whether or not a position is recorded.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('winning_numbers', function (Blueprint $table): void {
            $table->id();

            // Published results are history: restrict, never cascade.
            $table->foreignId('draw_id')->constrained()->restrictOnDelete();

            $table->string('bet_type', 16)->index();
            $table->string('number', 16);
            $table->string('prize_tier', 32)->nullable();
            $table->string('position', 32)->nullable();

            $table->unsignedInteger('payout_multiplier')->default(0);
            $table->unsignedBigInteger('total_winners')->default(0);
            $table->decimal('total_payout', 24, 2)->default(0);

            $table->timestamp('published_at')->nullable()->index();

            $table->json('metadata')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['draw_id', 'bet_type', 'number', 'prize_tier'], 'winning_numbers_unique');
            $table->index(['draw_id', 'bet_type']);
            $table->index(['bet_type', 'number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('winning_numbers');
    }
};
