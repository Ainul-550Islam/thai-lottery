<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Public result board rows.
 * ONE ROW = ONE VERSION on the board. Rows rotate monotonically per draw
 * and are never deleted; the public surface answers strictly the live
 * status row.
 */
return new class () extends Migration
{
    public function up(): void
    {
        Schema::create('draw_publications', function (Blueprint $table): void {
            $table->id();

            // Deterministic identity: sha256(draw|version|fingerprint).
            $table->string('publication_key', 64)->unique();

            $table->foreignId('draw_id')->constrained()->restrictOnDelete();
            $table->foreignId('draw_certification_id')->constrained('draw_certifications')->restrictOnDelete();

            $table->unsignedInteger('version');
            $table->string('status', 32)->default('pending')->index();
            $table->string('result_fingerprint', 64);

            $table->timestamp('published_at')->nullable()->index();
            $table->timestamp('retracted_at')->nullable();

            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['draw_id', 'version']);
            $table->index(['draw_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('draw_publications');
    }
};
