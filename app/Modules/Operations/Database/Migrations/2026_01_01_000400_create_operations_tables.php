<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            // Denormalised: the log must stay readable after an account is
            // erased under spec 30.3 data rights.
            $table->string('actor_label')->nullable();
            $table->string('action', 96);
            $table->string('auditable_type', 64)->nullable();
            $table->unsignedBigInteger('auditable_id')->nullable();
            $table->json('changes')->nullable();
            $table->json('context')->nullable();
            $table->string('ip_hash', 64)->nullable();
            $table->string('user_agent')->nullable();
            $table->string('route')->nullable();
            $table->string('method', 8)->nullable();
            $table->uuid('request_id')->nullable();
            $table->string('result', 16)->default('success');
            $table->string('severity', 16)->default('info');
            // No updated_at: append-only (see AuditLog model).
            $table->timestamp('created_at')->useCurrent();

            $table->index(['auditable_type', 'auditable_id']);
            $table->index(['actor_id', 'created_at']);
            $table->index(['action', 'created_at']);
            $table->index('severity');
        });

        Schema::create('scheduler_heartbeats', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 96)->unique();
            $table->timestamp('last_run_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamps();
        });

        Schema::create('release_records', function (Blueprint $table): void {
            $table->id();
            $table->string('version', 32);
            $table->unsignedInteger('schema_version')->default(1);
            $table->string('channel', 24)->default('production');
            $table->string('artifact_checksum', 64)->nullable();
            $table->json('migrations_applied')->nullable();
            $table->string('status', 24)->default('applied');
            $table->text('notes')->nullable();
            $table->foreignId('applied_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('applied_at')->nullable();
            $table->timestamp('rolled_back_at')->nullable();
            $table->timestamps();

            $table->index(['version', 'status']);
        });

        Schema::create('backup_records', function (Blueprint $table): void {
            $table->id();
            $table->string('kind', 24)->default('database');
            $table->string('disk', 32)->default('backups');
            $table->string('path');
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('checksum', 64)->nullable();
            $table->string('status', 24)->default('completed');
            $table->string('trigger', 32)->default('manual');
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['kind', 'status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_records');
        Schema::dropIfExists('release_records');
        Schema::dropIfExists('scheduler_heartbeats');
        Schema::dropIfExists('audit_logs');
    }
};
