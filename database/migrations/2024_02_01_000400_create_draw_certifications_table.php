<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Draw certifications.
 * ONE ROW = ONE certification act against a draw. History is kept
 * (supersession rotates rows, never deletes), so draw_id is indexed
 * rather than unique: the service enforces exactly one LIVE certification
 * per draw inside the lock, while the table remembers every act.
 */
return new class () extends Migration
{
    public function up(): void
    {
        Schema::create('draw_certifications', function (Blueprint $table): void {
            $table->id();

            // Deterministic identity: sha256(draw|fingerprint|certifier).
            $table->string('certification_key', 64)->unique();

            // The certified paper is history at the draw lane: restrict.
            $table->foreignId('draw_id')->constrained()->restrictOnDelete()->index();

            $table->string('status', 32)->default('draft')->index();
            $table->string('source_type', 32);

            // The signed truth: what the court corroborated at
            // certification time.
            $table->string('result_fingerprint', 64);
            $table->json('winning_numbers');

            $table->string('certifier_reference', 64);
            $table->timestamp('certified_at')->nullable()->index();

            // When superseded: the key of the takeover certification.
            $table->string('superseded_by_key', 64)->nullable();

            $table->json('metadata')->nullable();
            $table->timestamps();

            // One live certification per draw at query speed (the
            // service still owns the authority under lockForUpdate).
            $table->index(['draw_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('draw_certifications');
    }
};
