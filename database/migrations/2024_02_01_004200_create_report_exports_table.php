<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_exports', function (Blueprint $table): void {
            $table->id();
            $table->string('artifact_fingerprint', 64)->unique(); // one checksum-verified artifact
            $table->foreignId('report_job_id')->constrained('operational_report_jobs')->cascadeOnDelete();
            $table->string('format', 8);                          // ReportFormat
            $table->string('status', 16)->default('ready');       // ready | expired
            $table->string('checksum', 64);                       // sha256 of sealed bytes
            $table->unsignedBigInteger('byte_size');
            $table->string('storage_path', 191);                  // server-local artifact path
            $table->unsignedInteger('row_count');
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(['report_job_id', 'format']);
            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_exports');
    }
};
