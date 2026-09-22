<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operational_report_jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('query_fingerprint', 64)->unique();   // same ask = one job, forever
            $table->foreignId('requester_user_id')->constrained('users');
            $table->string('report_type', 24);                   // ReportType
            $table->json('filters');                             // sealed filter blend
            $table->string('status', 16)->default('queued');     // ReportJobStatus
            $table->timestamp('horizon_start')->nullable();
            $table->timestamp('horizon_end')->nullable();
            $table->unsignedInteger('row_count')->nullable();
            $table->string('failure_reason', 96)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at');                     // artifact retention horizon
            $table->timestamps();

            $table->index(['status', 'expires_at']);
            $table->index('requester_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operational_report_jobs');
    }
};
