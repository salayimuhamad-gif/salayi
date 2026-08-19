<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable()->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password')->nullable();

            // Sorani is the default for every new account (spec 7.1).
            $table->string('preferred_locale', 8)->default('ckb');
            $table->string('timezone', 64)->default('Asia/Baghdad');

            // Spec 22.2 + 30.1: phone is stored encrypted with a keyed blind
            // index for equality lookup. A manually typed number is never
            // "verified" — only Telegram's Share Contact sets that flag.
            $table->text('phone_encrypted')->nullable();
            $table->string('phone_index', 64)->nullable()->unique();
            $table->boolean('phone_verified')->default(false);
            $table->string('telegram_id', 64)->nullable()->unique();
            $table->string('telegram_username', 64)->nullable();

            // MFA (spec 30.1). Secret and recovery codes are encrypted at rest.
            $table->text('mfa_secret')->nullable();
            $table->text('mfa_recovery_codes')->nullable();
            $table->timestamp('mfa_confirmed_at')->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamp('suspended_at')->nullable();
            $table->string('suspended_reason')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip_hash', 64)->nullable();

            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_active', 'deleted_at']);
        });

        Schema::create('password_reset_tokens', function (Blueprint $table): void {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('roles', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 64)->unique();
            $table->string('name');
            $table->string('description')->nullable();
            // System roles are seeded and cannot be deleted through the UI.
            $table->boolean('is_system')->default(true);
            $table->timestamps();
        });

        Schema::create('role_user', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'role_id']);
        });

        Schema::create('consents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 64);
            $table->boolean('granted');
            $table->string('source', 64)->nullable();
            $table->json('evidence')->nullable();
            $table->string('ip_hash', 64)->nullable();
            $table->string('user_agent_hash', 64)->nullable();
            $table->string('locale', 8)->nullable();
            $table->timestamp('granted_at')->nullable();
            $table->timestamp('withdrawn_at')->nullable();
            $table->timestamps();

            // Not unique on (user, type): withdrawal appends a row rather than
            // overwriting, so the history is provable (spec 23.3, 30.3).
            $table->index(['user_id', 'type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consents');
        Schema::dropIfExists('role_user');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
