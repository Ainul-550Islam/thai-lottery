<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ledger_entries
 *
 * One line of the double-entry ledger. The side is stored as `type`
 * ('debit'|'credit') together with a single positive `amount` column, which is
 * the shape App\Models\LedgerEntry was built against; that model additionally
 * exposes read-only `debit` and `credit` attributes so reports can render the
 * classic two-column ledger without denormalising the value into two columns
 * that could disagree.
 *
 * Immutability: ledger entries are historical accounting records. Both foreign
 * keys that point at mutable owners are RESTRICT (account) or SET NULL (wallet),
 * and the transaction reference is RESTRICT so deleting a transaction can never
 * cascade its accounting history away.
 *
 * No posting logic lives here - the ledger service decides balance, side and
 * posting time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_entries', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('ledger_account_id')->constrained()->restrictOnDelete();

            // Historical accounting rows must survive; never cascade.
            $table->foreignId('financial_transaction_id')->constrained()->restrictOnDelete();

            $table->foreignId('wallet_id')->nullable()->constrained()->nullOnDelete();

            $table->string('type', 16)->index();
            $table->decimal('amount', 20, 2);
            $table->string('currency', 3)->default('THB');
            $table->decimal('balance_after', 24, 2)->nullable();

            $table->string('description')->nullable();

            // Polymorphic link to the domain record that caused the movement.
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();

            $table->json('metadata')->nullable();

            $table->timestamp('posted_at')->nullable()->index();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['reference_type', 'reference_id']);
            $table->index(['ledger_account_id', 'posted_at']);
            $table->index(['financial_transaction_id', 'type']);
            $table->index(['wallet_id', 'posted_at']);
        });

        $this->addStructuralGuards();
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_entries');
    }

    /**
     * Structural guards: an entry is strictly one side, and the amount is a
     * positive magnitude (direction is carried by `type`, never by the sign).
     *
     * MySQL/MariaDB only (CHECK is enforced from MySQL 8.0.16). Other drivers,
     * including the SQLite test database, skip these and rely on the ledger
     * service plus the App\Enums\LedgerEntryType cast.
     */
    private function addStructuralGuards(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        DB::statement("ALTER TABLE `ledger_entries` ADD CONSTRAINT `ledger_entries_type_allowed` CHECK (`type` IN ('debit', 'credit'))");
        DB::statement('ALTER TABLE `ledger_entries` ADD CONSTRAINT `ledger_entries_amount_positive` CHECK (`amount` > 0)');
    }
};
