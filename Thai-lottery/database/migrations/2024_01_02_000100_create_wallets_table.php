<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * wallets
 *
 * Player balance container. Every monetary column is DECIMAL(20,2) so that no
 * value is ever rounded by a binary floating point type. The application never
 * derives money from `float`; the App\Models\Wallet model casts these columns to
 * decimal strings and uses bcmath.
 *
 * available_balance is intentionally NOT a stored column: App\Models\Wallet
 * exposes it as a derived accessor (balance - locked_balance). Storing it would
 * duplicate state that must never disagree with its two sources.
 *
 * `version` supports optimistic locking for the future wallet engine. The engine
 * must increment it inside the same UPDATE it uses to change a balance.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallets', function (Blueprint $table): void {
            $table->id();

            // A wallet is financial history: a deleted user must not silently
            // erase balances, so deletion is restricted at database level.
            $table->foreignId('user_id')->constrained()->restrictOnDelete();

            $table->string('type', 32)->default('primary');
            $table->string('status', 32)->default('active')->index();
            $table->string('currency', 3)->default('THB');

            $table->decimal('balance', 20, 2)->default(0);
            $table->decimal('locked_balance', 20, 2)->default(0);
            $table->decimal('total_deposited', 20, 2)->default(0);
            $table->decimal('total_withdrawn', 20, 2)->default(0);
            $table->decimal('total_wagered', 20, 2)->default(0);
            $table->decimal('total_won', 20, 2)->default(0);

            // Optimistic concurrency token for the wallet engine.
            $table->unsignedBigInteger('version')->default(0);

            $table->timestamp('locked_at')->nullable();
            $table->string('locked_reason')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Exactly one wallet per user, per wallet type, per currency.
            $table->unique(['user_id', 'type', 'currency'], 'wallets_user_type_currency_unique');
            $table->index(['user_id', 'status']);
            $table->index(['user_id', 'currency']);
        });

        $this->addMoneyGuards();
    }

    public function down(): void
    {
        Schema::dropIfExists('wallets');
    }

    /**
     * Non-negative money guards.
     *
     * Laravel's schema builder has no portable CHECK constraint API, so these
     * are issued as raw statements and only on MySQL/MariaDB, where CHECK is
     * enforced from MySQL 8.0.16 onwards. Other drivers (for example the SQLite
     * database used by the automated test suite) skip them and rely on the
     * application layer.
     */
    private function addMoneyGuards(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        DB::statement('ALTER TABLE `wallets` ADD CONSTRAINT `wallets_balance_non_negative` CHECK (`balance` >= 0)');
        DB::statement('ALTER TABLE `wallets` ADD CONSTRAINT `wallets_locked_balance_non_negative` CHECK (`locked_balance` >= 0)');
        DB::statement('ALTER TABLE `wallets` ADD CONSTRAINT `wallets_locked_within_balance` CHECK (`locked_balance` <= `balance`)');
    }
};
