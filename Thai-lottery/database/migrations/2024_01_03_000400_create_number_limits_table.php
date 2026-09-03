<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * number_limits
 *
 * Risk exposure ceilings per (draw, bet_type, number).
 *
 * Two independent ceilings are tracked:
 *  - max_amount / current_amount: accepted stake on that number. These are the
 *    columns App\Models\NumberLimit already reads, and they are the stake limit
 *    referred to as "maximum stake" in the risk configuration.
 *  - maximum_payout_exposure / current_payout_exposure: the liability the house
 *    would owe if that number wins, which grows with the payout multiplier and
 *    is the ceiling that actually protects the bankroll.
 *
 * `number` is a string so leading zeros survive. No concurrency logic lives in
 * the schema: the unique key plus row locking is what the future risk service
 * will build on, and it must re-check the ceiling inside its own transaction.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('number_limits', function (Blueprint $table): void {
            $table->id();

            // Risk configuration follows its draw.
            $table->foreignId('draw_id')->constrained()->cascadeOnDelete();

            $table->string('bet_type', 16)->index();
            $table->string('number', 16);

            $table->decimal('max_amount', 20, 2);
            $table->decimal('current_amount', 20, 2)->default(0);

            $table->decimal('maximum_payout_exposure', 20, 2)->nullable();
            $table->decimal('current_payout_exposure', 20, 2)->default(0);

            $table->string('status', 32)->default('active')->index();
            $table->timestamp('exceeded_at')->nullable();

            $table->json('metadata')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['draw_id', 'bet_type', 'number'], 'number_limits_draw_type_number_unique');
            $table->index(['draw_id', 'status']);
        });

        $this->addMoneyGuards();
    }

    public function down(): void
    {
        Schema::dropIfExists('number_limits');
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

        DB::statement('ALTER TABLE `number_limits` ADD CONSTRAINT `number_limits_max_amount_positive` CHECK (`max_amount` > 0)');
        DB::statement('ALTER TABLE `number_limits` ADD CONSTRAINT `number_limits_current_amount_non_negative` CHECK (`current_amount` >= 0)');
        DB::statement('ALTER TABLE `number_limits` ADD CONSTRAINT `number_limits_current_exposure_non_negative` CHECK (`current_payout_exposure` >= 0)');
    }
};
