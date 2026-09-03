<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * users, password_reset_tokens, sessions
 *
 * This is Laravel 11's standard first migration, which by framework convention
 * creates all three authentication tables in one file. It is preserved in that
 * shape - splitting password_reset_tokens and sessions into separate migrations
 * would diverge from the framework skeleton without any benefit.
 *
 * The users table carries only account identity and lifecycle fields that
 * App\Models\User actually uses; balances, limits and betting data live in their
 * own tables. `status` holds an App\Enums\UserStatus value as a string rather
 * than a database ENUM, so adding a state never requires an ALTER TABLE.
 *
 * Deletion: users are soft-deleted. Financial tables additionally protect
 * history at database level (RESTRICT / SET NULL), so a hard delete can never
 * silently erase wallets, transactions, bets, payouts or audit rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('username')->unique();
            $table->string('phone')->nullable()->unique();
            $table->string('password');
            $table->string('status', 32)->default('pending_verification')->index();
            $table->string('avatar_url')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamp('phone_verified_at')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip', 45)->nullable();
            $table->json('preferences')->nullable();
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table): void {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
