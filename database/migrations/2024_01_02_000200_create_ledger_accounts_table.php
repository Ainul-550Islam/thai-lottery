<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ledger_accounts
 *
 * Chart of accounts for the double-entry ledger. Rows are configuration, not
 * transactions, but they are referenced by immutable ledger entries, therefore
 * an account may never be hard-deleted while entries point at it (see the
 * restrictOnDelete on ledger_entries.ledger_account_id).
 *
 * Naming note: the self reference is `parent_account_id` (not `parent_id`)
 * because App\Models\LedgerAccount::parent() already binds to that column.
 * Availability is expressed by the boolean `is_active` rather than a `status`
 * string, again to match the existing model; a second status column would be
 * duplicate state.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_accounts', function (Blueprint $table): void {
            $table->id();

            $table->string('code', 32)->unique();
            $table->string('name');
            $table->string('type', 32)->index();
            $table->string('currency', 3)->default('THB');
            $table->string('description')->nullable();
            $table->boolean('is_active')->default(true)->index();

            // Self-referencing hierarchy. A parent account is detached rather
            // than deleting its children, so the tree can never be orphaned.
            $table->foreignId('parent_account_id')->nullable()
                ->constrained('ledger_accounts')->nullOnDelete();

            $table->decimal('opening_balance', 24, 2)->default(0);
            $table->decimal('current_balance', 24, 2)->default(0);

            $table->json('metadata')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['type', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_accounts');
    }
};
