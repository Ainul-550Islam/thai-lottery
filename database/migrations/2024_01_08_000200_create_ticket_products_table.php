<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fixed lottery-ticket products (GLO-parity): the manufactured offering
 * line — code + denomination + draw association + unit cap.
 *
 * WHY A TABLE (WHEN DOMAINS LIKE CLAIMS RIDE ON METADATA LANES)
 * ------------------------------------------------------------
 * A product is not a lane on a payout: it is a first-class catalog entity.
 * What only a row can give it:
 *
 *   identity      product_key (sha256 of code + draw + denomination +
 *                 currency) is UNIQUE at the engine — manufacturing the
 *                 same product twice is refused by the database, race-safe
 *   catalog       products are listed, screened, and allocation-counted
 *                 from one row each; scanning payout-shaped JSON lanes
 *                 could never answer "what is on offer"
 *   caps          units_total/units_allocated counters advance atomically
 *                 through AllocateTicketInventoryJob, guarded by the row
 *   lifecycle     TicketProductStatus lives on the row with timestamps
 *
 * DENOMINATION IS MONEY, THEREFORE DECIMAL(20,2). NEVER A FLOAT.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_products', function (Blueprint $table): void {
            $table->id();

            // Deterministic identity: sha256(code|draw|denomination|currency).
            $table->string('product_key', 64)->unique();

            // Human-facing machine code: 'GLO-6D-80THB-2026-10'.
            $table->string('product_code', 64)->index();

            $table->foreignId('draw_id')->constrained()->restrictOnDelete();

            $table->decimal('denomination', 20, 2);
            $table->string('currency', 3)->default('THB');

            // Print cap + atomic allocation counter.
            $table->unsignedBigInteger('units_total')->default(0);
            $table->unsignedBigInteger('units_allocated')->default(0);

            // TicketProductStatus value.
            $table->string('status', 32)->default('draft')->index();

            // Optional retail availability window.
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();

            // Lifecycle stamps.
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('closed_at')->nullable();

            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['draw_id', 'status']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_products');
    }
};
