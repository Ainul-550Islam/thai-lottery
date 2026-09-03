<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * draws
 *
 * A single lottery draw. Two different families of timestamps are stored on
 * purpose:
 *
 *  - betting_open_at / betting_close_at / scheduled_at: the planned schedule,
 *    written when the draw is created.
 *  - opened_at / closed_at / drawn_at / completed_at / result_published_at: the
 *    actual lifecycle events, written by the draw engine as they happen.
 *
 * Results are NOT stored here. The normalised `winning_numbers` table is the
 * single source of truth for published numbers, and `draw_results` holds the
 * official prize structure.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('draws', function (Blueprint $table): void {
            $table->id();

            $table->string('draw_number', 64)->unique();
            $table->string('type', 16)->index();
            $table->string('status', 32)->default('scheduled')->index();

            // Planned schedule.
            $table->timestamp('scheduled_at')->index();
            $table->timestamp('betting_open_at')->nullable();
            $table->timestamp('betting_close_at')->nullable()->index();

            // Actual lifecycle events.
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('drawn_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('result_published_at')->nullable();

            // Aggregated counters maintained by the draw engine.
            $table->unsignedBigInteger('total_bets')->default(0);
            $table->decimal('total_amount_wagered', 24, 2)->default(0);
            $table->decimal('total_payout', 24, 2)->default(0);
            $table->decimal('house_profit', 24, 2)->default(0);

            $table->json('metadata')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'scheduled_at']);
            $table->index(['type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('draws');
    }
};
