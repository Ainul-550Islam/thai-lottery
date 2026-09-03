<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * tickets
 *
 * The customer-facing receipt that groups one or more bets. The relationship
 * direction is tickets 1 - N bets, so the foreign key lives on `bets.ticket_id`
 * and there is deliberately NO `tickets.bet_id` column: that would create a
 * circular foreign key between the two tables and break a fresh install.
 *
 * `uuid` is nullable because the numeric id remains the model key and the
 * existing App\Models\Ticket does not generate UUIDs yet; `ticket_number` is the
 * unique human/printed identifier that is already in use.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tickets', function (Blueprint $table): void {
            $table->id();

            $table->uuid('uuid')->nullable()->unique();
            $table->string('ticket_number', 64)->unique();

            // Tickets are financial history: restrict, never cascade.
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('draw_id')->constrained()->restrictOnDelete();

            $table->string('status', 32)->default('pending')->index();
            $table->string('currency', 3)->default('THB');

            $table->decimal('total_amount', 20, 2)->default(0);
            $table->unsignedInteger('total_bets')->default(0);
            $table->unsignedInteger('total_numbers')->default(0);

            $table->timestamp('issued_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('expired_at')->nullable();

            $table->json('metadata')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'status']);
            $table->index(['draw_id', 'status']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tickets');
    }
};
