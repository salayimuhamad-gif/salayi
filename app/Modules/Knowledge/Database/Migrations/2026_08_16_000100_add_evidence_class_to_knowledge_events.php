<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 12: the one schema addition of the market-grounding work.
 *
 * Nullable and unbackfilled on purpose: pre-Phase-12 rows are read as
 * admin_observation at the model layer, which is the conservative reading —
 * rewriting history to claim a class nobody chose would be a silent lie.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knowledge_events', function (Blueprint $table): void {
            $table->string('evidence_class', 32)->nullable()->after('confidence');
        });
    }

    public function down(): void
    {
        Schema::table('knowledge_events', function (Blueprint $table): void {
            $table->dropColumn('evidence_class');
        });
    }
};
