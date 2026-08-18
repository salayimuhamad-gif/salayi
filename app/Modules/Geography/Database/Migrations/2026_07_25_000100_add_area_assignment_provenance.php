<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Area assignment provenance (File two §4, §6.1, §6.9).
 *
 * §4 requires two things that pull against each other: a project must be
 * linked to its area AUTOMATICALLY from its coordinates, and an administrator
 * must be able to CORRECT that link by hand. Without a record of which of
 * those happened, the next automatic run silently overwrites the correction —
 * and the administrator's only signal is that their fix quietly stopped
 * being true.
 *
 * So three additive, reversible columns:
 *
 *   - `area_is_manual`  — this link was chosen by a person; leave it alone.
 *   - `area_assigned_at` — when the automatic resolver last decided.
 *   - `area_match_type` — HOW it was decided: a boundary containment, an
 *     inherited parent, or a human. §6.10 asks for the calculation date to be
 *     visible; showing "assigned automatically on 3 March by boundary match"
 *     is what makes an area attribution auditable rather than magic.
 *
 * Nothing is dropped and no existing row changes meaning: an area_id that is
 * already set stays set, and is treated as manual, because it was typed by a
 * person before any resolver existed. Assuming otherwise would let the first
 * automatic pass rewrite every area a human had already chosen.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Re-runnable: a migration interrupted partway on a shared
        // host must be repeatable rather than repaired by hand.
        if (Schema::hasColumn('projects', 'area_is_manual')) {
            return;
        }

        Schema::table('projects', function (Blueprint $table): void {
            $table->boolean('area_is_manual')->default(false)->after('area_id');
            $table->timestamp('area_assigned_at')->nullable()->after('area_is_manual');
            $table->string('area_match_type', 24)->nullable()->after('area_assigned_at');
        });

        // Every area link that exists today predates automatic resolution and
        // was therefore chosen by a person. Marking them manual protects them
        // from the first run of the resolver.
        DB::table('projects')
            ->whereNotNull('area_id')
            ->update(['area_is_manual' => true, 'area_match_type' => 'manual']);

        Schema::table('places', function (Blueprint $table): void {
            $table->boolean('area_is_manual')->default(false)->after('area_id');
            $table->timestamp('area_assigned_at')->nullable()->after('area_is_manual');
            $table->string('area_match_type', 24)->nullable()->after('area_assigned_at');
        });

        DB::table('places')
            ->whereNotNull('area_id')
            ->update(['area_is_manual' => true, 'area_match_type' => 'manual']);

        $this->renumberAreaDepths();
    }

    /**
     * Recompute `areas.depth` after AreaType gained `nahiya` (File two §4.1).
     *
     * Inserting a level between district and sector shifts every finer type
     * down by one. `depth` is stored, and AreaType::canContain() compares
     * stored depths, so leaving old rows at their previous numbers would let a
     * sector be accepted as the parent of a nahiya — silently inverting the
     * containment the hierarchy exists to guarantee.
     *
     * The materialised `path` column is unaffected: it holds ids, not depths.
     */
    private function renumberAreaDepths(): void
    {
        $depths = [
            'city' => 0,
            'district' => 1,
            'nahiya' => 2,
            'sector' => 3,
            'zone' => 4,
            'neighborhood' => 5,
            'mahalla' => 6,
        ];

        foreach ($depths as $type => $depth) {
            DB::table('areas')->where('type', $type)->update(['depth' => $depth]);
        }
    }

    public function down(): void
    {
        // Restore the pre-nahiya depth numbering. Any nahiya rows created in
        // the meantime keep a depth of 2, which collides with sector — that is
        // reported rather than silently resolved, because merging two
        // administrative levels is a data decision, not a migration's to make.
        foreach (['city' => 0, 'district' => 1, 'sector' => 2, 'zone' => 3, 'neighborhood' => 4, 'mahalla' => 5] as $type => $depth) {
            DB::table('areas')->where('type', $type)->update(['depth' => $depth]);
        }

        Schema::table('projects', function (Blueprint $table): void {
            // MIGRATION-GUARD: intentional-drop — reversing this migration's own
            // additive columns. No data predates them.
            $table->dropColumn(['area_is_manual', 'area_assigned_at', 'area_match_type']);
        });

        Schema::table('places', function (Blueprint $table): void {
            // MIGRATION-GUARD: intentional-drop — reversing this migration's own
            // additive columns. No data predates them.
            $table->dropColumn(['area_is_manual', 'area_assigned_at', 'area_match_type']);
        });
    }
};
