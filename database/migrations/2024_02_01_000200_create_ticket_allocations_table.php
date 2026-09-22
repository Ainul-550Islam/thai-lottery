<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vendor ticket allocations.
 *
 * The QUOTA CONVERSATION ROW: one row per (vendor, product, draw) triple,
 * stamped by the deterministic allocation key so rows can't mint twice
 * under any retry surface (chunked jobs, reconciler, UI double-click).
 *
 * NO COUNTER CACHES. How many units the allocation currently holds, how
 * many have sold, is projected from member stamps — the
 * ticket_inventory_items that name this allocation as their holder — so a
 * drift between the row and the inventory is structurally impossible.
 * Counter arithmetic belongs code-side, under lock.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_allocations', function (Blueprint $table): void {
            $table->id();

            // Deterministic identifier: sha256(vendor|product|draw).
            $table->string('allocation_key', 64)->unique();

            $table->foreignId('vendor_id')->constrained('retail_vendors')->restrictOnDelete();
            $table->foreignId('ticket_product_id')->constrained('ticket_products')->restrictOnDelete();
            $table->foreignId('draw_id')->constrained('draws')->restrictOnDelete();

            // The ask: how many units the allocation court committed them.
            $table->unsignedInteger('quantity');

            // TicketAllocationStatus: pending / allocated / accepted /
            // released / exhausted.
            $table->string('status', 32)->default('pending')->index();

            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->index(['vendor_id', 'status']);
            $table->index(['ticket_product_id', 'draw_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_allocations');
    }
};
