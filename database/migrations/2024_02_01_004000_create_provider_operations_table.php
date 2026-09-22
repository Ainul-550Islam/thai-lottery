<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_operations', function (Blueprint $table): void {
            $table->id();
            $table->string('change_fingerprint', 64)->unique();      // exactly-once state change
            $table->string('provider', 64);                          // provider key (derived server-side)
            $table->string('status', 16);                            // ProviderOperationStatus
            $table->string('evidence_fingerprint', 64)->nullable();  // why-seat proof digest
            $table->foreignId('changed_by_user_id')->constrained('users');
            $table->text('note')->nullable();                        // operator note (no secrets)
            $table->timestamp('effective_at');                       // when the seat stands from
            $table->timestamps();

            $table->index(['provider', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_operations');
    }
};
