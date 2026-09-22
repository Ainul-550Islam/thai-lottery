<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payout batches: the manufactured groups of approved payout obligations
 * that move money in scheduled execution runs.
 *
 * WHY A TABLE (WHEN CLAIMS/WINDOWS/APPROVALS RIDE ON PAYOUT METADATA)
 * -------------------------------------------------------------------
 * A batch is not a lane ON one payout — it is an aggregate OF many. The
 * things a batch row must provide cannot come from any single payout:
 *
 *   identity      batch_key is sha256 over the ordered member references +
 *                 currency, unique at the database level, so manufacturing
 *                 a second batch off the same content is refused by the
 *                 engine even under a race the application missed
 *   claiming      the Pending → Processing flip (the executor's claim of a
 *                 run) needs ONE row to lock atomically; locking N member
 *                 payouts instead would hand two executors the same batch
 *   lifecycle     PayoutBatchStatus lives here with processed/paid/failed
 *                 counters the executor advances per member
 *   audit anchor  batch-level audits attach to one auditable row
 *
 * MEMBERSHIP IS PROJECTED, NOT STORED HERE
 *   Members carry their own `metadata.batch` stamp (batch key + join time +
 *   outcome) on the payouts table itself. The batch row never duplicates
 *   the member list: the member side is authoritative for "which payouts",
 *   this row is authoritative for "what the batch claims and how it went".
 *   aggregate_amount is the bcmath-verifiable claim of worth, checked
 *   against the members at creation and again at completion.
 *
 * MONEY IS A DECIMAL STRING, NEVER A FLOAT
 *   aggregate_amount, paid_amount are DECIMAL(20,2) like every other money
 *   column in this schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payout_batches', function (Blueprint $table): void {
            $table->id();

            // Deterministic identity: sha256 of sorted member references +
            // currency. UNIQUE is the engine-level duplicate guard.
            $table->string('batch_key', 64)->unique();

            // PayoutBatchStatus value.
            $table->string('status', 32)->default('pending')->index();

            $table->string('currency', 3)->default('THB');

            // Integer counters the executor advances per member outcome.
            $table->unsignedInteger('member_count')->default(0);
            $table->unsignedInteger('processed_count')->default(0);
            $table->unsignedInteger('paid_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->unsignedInteger('replayed_count')->default(0);

            // Money claims/actuals, decimal-string exact.
            $table->decimal('aggregate_amount', 20, 2)->default(0);
            $table->decimal('paid_amount', 20, 2)->default(0);

            // Run lifecycle stamps.
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('failure_reason', 255)->nullable();

            // Operator-visible creation note + structured context.
            $table->string('note', 255)->nullable();
            $table->json('metadata')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payout_batches');
    }
};
