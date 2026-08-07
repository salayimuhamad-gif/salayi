<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('languages', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 8)->unique();
            $table->string('name');
            $table->string('native_name');
            $table->string('direction', 3)->default('rtl');
            $table->boolean('is_enabled')->default(true);
            $table->boolean('is_default')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('translations', function (Blueprint $table): void {
            $table->id();
            $table->string('group', 64)->default('app');
            $table->string('key', 191);
            $table->string('locale', 8);
            $table->text('value')->nullable();

            // Spec 7.4 workflow. AI output can never enter as 'published'.
            $table->string('status', 24)->default('missing');
            $table->boolean('is_overridden')->default(false);
            $table->string('source', 32)->nullable();

            // Derived by the model on save; never entered by hand.
            $table->string('search_key', 191)->nullable();

            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['group', 'key', 'locale']);
            $table->index(['locale', 'status']);
            $table->index('search_key');
        });

        Schema::create('glossary_terms', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 64)->unique();
            $table->string('term_ckb');
            $table->string('term_ar')->nullable();
            $table->string('term_en')->nullable();
            $table->text('description')->nullable();
            $table->json('approved_alternatives')->nullable();
            $table->json('blocked_alternatives')->nullable();
            $table->json('search_aliases')->nullable();
            $table->string('capitalization', 24)->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('glossary_terms');
        Schema::dropIfExists('translations');
        Schema::dropIfExists('languages');
    }
};
