<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * bet_items
 *
 * One selected lottery number inside a bet.
 *
 * `number` is a STRING, never an integer: Thai lottery numbers are positional
 * digit strings where leading zeros are significant ('007' and '7' are different
 * selections and '07' is a valid 2D number).
 *
 * `position` distinguishes markets that share a digit length, for example 2D top
 * versus 2D bottom, or run top versus run bottom (see config/lottery.php).
 *
 * The bet type is NOT duplicated here: it is owned by the parent bet and
 * App\Models\BetItem exposes it through a `bet_type` accessor. Storing it again
 * would allow an item to claim a type its bet does not have. Exposure queries
 * per draw therefore join through `bet_id`, which is indexed, and the composite
 * index on (bet_id, number) supports them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bet_items', function (Blueprint $table): void {
            $table->id();

            // Stake history: restrict rather than cascade.
            $table->foreignId('bet_id')->constrained()->restrictOnDelete();

            $table->string('number', 16)->index();
            $table->string('position', 32)->nullable()->index();

            $table->decimal('amount', 20, 2);
            $table->unsignedInteger('payout_multiplier')->default(0);
            $table->decimal('potential_payout', 20, 2)->default(0);

            $table->boolean('is_winner')->default(false)->index();
            $table->decimal('actual_payout', 20, 2)->default(0);

            $table->json('metadata')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['bet_id', 'number']);
            $table->index(['number', 'position']);
        });

        $this->addMoneyGuards();
    }

    public function down(): void
    {
        Schema::dropIfExists('bet_items');
    }

    /**
     * MySQL/MariaDB-only guards (CHECK enforced from MySQL 8.0.16). Skipped on
     * other drivers such as the SQLite database used by the test suite.
     */
    private function addMoneyGuards(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        DB::statement('ALTER TABLE `bet_items` ADD CONSTRAINT `bet_items_amount_positive` CHECK (`amount` > 0)');
        DB::statement('ALTER TABLE `bet_items` ADD CONSTRAINT `bet_items_actual_payout_non_negative` CHECK (`actual_payout` >= 0)');
    }
};
