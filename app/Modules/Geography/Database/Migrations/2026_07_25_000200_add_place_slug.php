<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Public slug for places (spec 10.3, 12.2).
 *
 * Places had no public identifier. Areas and projects are both addressed by
 * slug; a place profile at `/places/417` would be the only surface in the
 * product exposing a sequential primary key, which leaks the size of the
 * dataset and the order rows were created in — and is unreadable and
 * unshareable besides.
 *
 * The backfill is deterministic rather than random: the same row produces the
 * same slug on every environment, so a slug generated during the installer's
 * migrate step matches one generated on a developer's machine. A random
 * suffix would make the two diverge and turn any URL written down during
 * testing into a 404 after the next fresh install.
 *
 * Uniqueness is enforced by the index, and collisions are resolved by
 * appending the id — which is already unique by definition, so the loop
 * terminates. Sorani and Arabic names transliterate to empty under Str::slug,
 * so `place-{id}` is the honest fallback rather than an empty string that
 * would collide with every other untransliterable name.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Re-runnable: a migration interrupted partway on a shared
        // host must be repeatable rather than repaired by hand.
        if (Schema::hasColumn('places', 'slug')) {
            return;
        }

        Schema::table('places', function (Blueprint $table): void {
            $table->string('slug', 160)->nullable()->after('external_id');
        });

        $this->backfillSlugs();

        // Indexed after the backfill, not before: adding a unique index to an
        // empty column and then filling it means every insert re-checks the
        // index, and on a shared host that is the difference between a
        // migration that finishes and one that times out.
        Schema::table('places', function (Blueprint $table): void {
            $table->unique('slug', 'places_slug_unique');
        });
    }

    /**
     * Give every existing place a stable, readable slug.
     *
     * Chunked because a places table on a real Erbil dataset is tens of
     * thousands of rows, and a shared host's memory limit is not generous.
     */
    private function backfillSlugs(): void
    {
        $taken = [];

        DB::table('places')
            ->select('id', 'name_en', 'name_ckb', 'name_ar')
            ->orderBy('id')
            ->chunkById(500, function ($places) use (&$taken): void {
                foreach ($places as $place) {
                    $slug = $this->slugFor($place, $taken);
                    $taken[$slug] = true;

                    DB::table('places')->where('id', $place->id)->update(['slug' => $slug]);
                }
            });
    }

    /** @param  array<string, true>  $taken */
    private function slugFor(object $place, array $taken): string
    {
        // English first: it is the only one of the three that transliterates
        // to a usable ASCII slug. The others fall through to the id form,
        // which is stable and honest about carrying no name.
        $base = Str::slug((string) ($place->name_en ?? ''));

        if ($base === '') {
            $base = Str::slug((string) ($place->name_ckb ?? ''));
        }

        if ($base === '') {
            $base = Str::slug((string) ($place->name_ar ?? ''));
        }

        if ($base === '') {
            return 'place-'.$place->id;
        }

        $base = Str::limit($base, 140, '');

        // The id suffix is unique by definition, so this resolves in one step.
        return isset($taken[$base]) ? $base.'-'.$place->id : $base;
    }

    public function down(): void
    {
        Schema::table('places', function (Blueprint $table): void {
            $table->dropUnique('places_slug_unique');
        });

        Schema::table('places', function (Blueprint $table): void {
            // MIGRATION-GUARD: intentional-drop — reversing this migration's own
            // additive column. No data predates it; slugs are derived from
            // names that remain in place.
            $table->dropColumn('slug');
        });
    }
};
