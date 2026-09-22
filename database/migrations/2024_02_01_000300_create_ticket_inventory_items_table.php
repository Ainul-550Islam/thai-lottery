<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ticket inventory items.
 *
 * ONE ROW = ONE UNIT of ticket paper: an existentially typed answer to
 * "where is serial X today". Serial + fingerprint bindings make players
 * of replay/drift immediately visible; TicketInventoryStatus writes the
 * lifecycle; allocation membership is the standing rule — projected FROM
 * the unit onto the allocation (allocation_id), so memory/scopes never
 * fork authoritative meaning.
 *
 * reserved_* columns are the reservation lanes a unit travels through;
 * transient state modeling is allowed here because reservation is
 * genuinely time-bound reality (a paper hold).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_inventory_items', function (Blueprint $table): void {
            $table->id();

            // Deterministic identity: sha256(product|draw|serial).
            $table->string('inventory_key', 64)->unique();

            // The serial stamped on the paper.
            $table->string('serial', 64);

            $table->foreignId('ticket_product_id')->constrained('ticket_products')->restrictOnDelete();
            $table->foreignId('draw_id')->constrained('draws')->restrictOnDelete();

            // Member stamp: which allocation lane currently holds this unit.
            $table->foreignId('ticket_allocation_id')->nullable()
                ->constrained('ticket_allocations')->restrictOnDelete();

            // TicketInventoryStatus: available / reserved / sold / voided /
            // expired.
            $table->string('status', 32)->default('available')->index();

            // sha256 signature of (identity + context) the lanes prove by.
            $table->string('fingerprint', 64);

            // Reservation lanes: WHO holds this unit and until when.
            $table->foreignId('reserved_by_vendor_id')->nullable()
                ->constrained('retail_vendors')->restrictOnDelete();
            $table->timestamp('reserved_at')->nullable();
            $table->timestamp('reserved_until')->nullable()->index();

            $table->string('voided_reason', 255)->nullable();

            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->index(['ticket_product_id', 'draw_id', 'status']);
            $table->index(['ticket_allocation_id', 'status']);
            $table->unique(['serial', 'ticket_product_id', 'draw_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_inventory_items');
    }
};
