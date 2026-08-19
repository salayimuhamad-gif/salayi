<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('key', 64)->index();
            $table->string('channel', 24);
            $table->string('locale', 8)->default('ckb');
            $table->string('subject');
            $table->text('body');
            $table->string('action_url')->nullable();
            $table->json('payload')->nullable();
            $table->string('priority', 16)->default('normal');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            // The common query is "this user's unread notices, newest first".
            $table->index(['user_id', 'read_at', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
