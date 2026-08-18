<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Correction §6.8: optional images and documents on portfolio properties.
 *
 * ADDITIVE ONLY — one new table, no change to anything that exists. The
 * files themselves live on the PRIVATE local disk under generated names;
 * this table is the only map from a property to them, which is why `path`
 * is here and never in any response payload.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portfolio_media', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('portfolio_property_id')
                ->constrained('portfolio_properties')
                ->cascadeOnDelete();
            $table->string('kind', 16); // image | document
            $table->string('disk', 32);
            $table->string('path', 255);
            /*
             * The uploader's own filename, kept ONLY as a display label —
             * sanitised at write time, never used to address the filesystem.
             */
            $table->string('original_name', 160);
            $table->string('mime', 96);
            $table->unsignedBigInteger('size_bytes');
            $table->timestamps();

            $table->index('portfolio_property_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portfolio_media');
    }
};
