<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('template_key', 96)->index();
            $table->string('locale', 8)->default('th');
            $table->unsignedInteger('version')->default(1);
            $table->string('status', 16)->default('draft')->index();
            $table->string('subject', 255);
            $table->text('body');
            $table->string('content_fingerprint', 64)->unique();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('retired_at')->nullable();
            $table->unique(['template_key', 'locale', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_templates');
    }
};
