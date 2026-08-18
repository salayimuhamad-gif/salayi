<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 128)->unique();
            $table->string('group', 64)->default('general');
            $table->string('type', 16)->default('string');
            $table->text('value')->nullable();

            // is_secret: never rendered to a browser (spec 37.5).
            // is_public: safe for the shared Inertia payload.
            $table->boolean('is_secret')->default(false);
            $table->boolean('is_public')->default(false);
            $table->string('description')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['group', 'is_public']);
        });

        Schema::create('feature_flags', function (Blueprint $table): void {
            $table->id();
            $table->string('flag', 96)->unique();
            $table->boolean('enabled')->default(false);
            $table->string('note')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('branding_assets', function (Blueprint $table): void {
            $table->id();
            $table->string('slot', 48);
            $table->unsignedInteger('version')->default(1);
            $table->boolean('is_current')->default(false);
            $table->string('disk', 32)->default('public');
            $table->string('path');
            $table->string('original_name')->nullable();
            $table->string('mime_type', 96)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            // SHA-256 of the stored bytes, so a revert can prove it restored
            // the same file (spec 8: uploads validated and versioned).
            $table->string('checksum', 64)->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['slot', 'version']);
            $table->index(['slot', 'is_current']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branding_assets');
        Schema::dropIfExists('feature_flags');
        Schema::dropIfExists('site_settings');
    }
};
