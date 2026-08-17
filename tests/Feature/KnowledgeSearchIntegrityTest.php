<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Geography\Models\Area;
use App\Modules\Identity\Models\User;
use App\Modules\Knowledge\Enums\KnowledgeStatus;
use App\Modules\Knowledge\Models\KnowledgeEvent;
use App\Modules\Localization\Support\SoraniText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * Knowledge search integrity (Phase 15).
 *
 * `HasTrilingualNames::syncSearchKey()` derives from name_* columns, and
 * knowledge_events has only title_* — so every save wrote an EMPTY search
 * key and the admin knowledge search, filtering `search_key LIKE`, matched
 * nothing in any language, ever. The contracts pinned here: the model's
 * title-keyed override populates the key from all three titles with the
 * repository's one fold; both write paths keep it fresh; the real admin
 * endpoint finds rows in ckb, ar and en, through folding, digits, partial
 * words and the existing filters; the backfill migration repairs legacy
 * NULL and '' rows without touching a key that is already real; and the
 * healthy name_* siblings keep their exact behavior.
 */
final class KnowledgeSearchIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private const BACKFILL = 'app/Modules/Knowledge/Database/Migrations/2026_08_17_000100_backfill_knowledge_event_search_keys.php';

    private function admin(): User
    {
        return User::factory()->superAdmin()->create();
    }

    /**
     * A Draft event through the same lifecycle the admin store() uses.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function event(array $attributes = []): KnowledgeEvent
    {
        $event = new KnowledgeEvent(array_merge([
            'title_ckb' => 'پڕۆژەی مێترۆی هەولێر ڕاگەیەنرا',
            'event_type' => 'infrastructure',
            'direction' => 'positive',
            'strength' => 3,
            'effective_date' => '2026-08-01',
            'source' => 'planning department bulletin',
            'ai_usage_permitted' => false,
        ], $attributes));
        $event->status = KnowledgeStatus::Draft;
        $event->save();

        return $event->fresh();
    }

    /**
     * @param  array<string, string>  $filters
     * @return TestResponse<Response>
     */
    private function search(User $admin, string $q, array $filters = []): TestResponse
    {
        return $this->actingAs($admin)
            ->get('/admin/knowledge?'.http_build_query(array_merge(['q' => $q], $filters)));
    }

    /**
     * @param  list<int>  $ids
     * @param  array<string, string>  $filters
     */
    private function assertFindsExactly(User $admin, string $q, array $ids, array $filters = []): void
    {
        $this->search($admin, $q, $filters)
            ->assertOk()
            ->assertInertia(function ($page) use ($ids): void {
                $page->where('events.total', count($ids))
                    ->where('events.data', function ($rows) use ($ids): bool {
                        // Inertia hands array props to this closure as a
                        // Collection; normalise before comparing ids.
                        /** @var Collection<int, array<string, mixed>> $rows */
                        $found = collect($rows)->pluck('id')
                            ->map(static fn ($id): int => (int) $id)
                            ->sort()->values()->all();
                        $expected = $ids;
                        sort($expected);

                        return $found === $expected;
                    });
            });
    }

    /* ------------------------------------------------- key derivation */

    public function test_a_kurdish_title_populates_the_search_key(): void
    {
        $event = $this->event();

        $this->assertSame(SoraniText::searchKey('پڕۆژەی مێترۆی هەولێر ڕاگەیەنرا'), $event->search_key);
        $this->assertNotSame('', $event->search_key);
    }

    public function test_arabic_and_english_titles_both_contribute_to_the_key(): void
    {
        $event = $this->event([
            'title_ckb' => 'هەواڵی وەبەرهێنان',
            'title_ar' => 'إعلان مترو أربيل',
            'title_en' => 'Erbil Metro Announced',
        ]);

        $this->assertStringContainsString(SoraniText::searchKey('إعلان مترو أربيل'), $event->search_key);
        $this->assertStringContainsString(SoraniText::searchKey('Erbil Metro Announced'), $event->search_key);
    }

    public function test_an_update_refreshes_the_key_and_the_queries_follow(): void
    {
        $admin = $this->admin();
        $event = $this->event(['title_ckb' => 'کۆنگرەی نیشتەجێبوون بەڕێوەچوو']);

        $this->assertFindsExactly($admin, 'کۆنگرەی', [$event->id]);

        $event->title_ckb = 'یاسای نوێی زەوی پەسەندکرا';
        $event->save();

        // The old needle stops matching; the new one matches.
        $this->assertFindsExactly($admin, 'کۆنگرەی', []);
        $this->assertFindsExactly($admin, 'یاسای', [$event->id]);
        $this->assertSame(SoraniText::searchKey('یاسای نوێی زەوی پەسەندکرا'), $event->fresh()->search_key);
    }

    /* --------------------------------------- the real admin endpoint */

    public function test_the_admin_endpoint_finds_events_in_all_three_languages(): void
    {
        $admin = $this->admin();
        $event = $this->event([
            'title_ckb' => 'پڕۆژەی مێترۆی هەولێر ڕاگەیەنرا',
            'title_ar' => 'إعلان مترو أربيل',
            'title_en' => 'Erbil Metro Announced',
        ]);
        $this->event(['title_ckb' => 'هەواڵێکی جیاواز', 'event_type' => 'market_shift']);

        $this->assertFindsExactly($admin, 'مێترۆی', [$event->id]);   // ckb, partial word
        $this->assertFindsExactly($admin, 'مترو', [$event->id]);     // ar
        $this->assertFindsExactly($admin, 'Metro', [$event->id]);    // en, case-insensitive
        $this->assertFindsExactly($admin, 'metro announced', [$event->id]);
    }

    public function test_folded_sorani_variants_and_digits_match_across_spellings(): void
    {
        $admin = $this->admin();
        $event = $this->event(['title_ckb' => 'بودجەی ساڵی ٢٠٢٦ بۆ مێترۆ']);

        // ێ→ی and ۆ→و fold identically on both sides: the plain-Arabic
        // spelling finds the Kurdish-lettered title.
        $this->assertFindsExactly($admin, 'میترو', [$event->id]);
        $this->assertFindsExactly($admin, 'مێترۆ', [$event->id]);

        // Arabic-Indic digits in the stored title, Latin digits in the query.
        $this->assertFindsExactly($admin, '2026', [$event->id]);
    }

    public function test_an_unrelated_query_returns_nothing(): void
    {
        $this->event();

        $this->assertFindsExactly($this->admin(), 'قوتابخانە', []);
    }

    /* ------------------------------------- admin semantics preserved */

    public function test_draft_rows_stay_searchable_and_filters_compose_with_q(): void
    {
        $admin = $this->admin();

        $draft = $this->event(['title_ckb' => 'مێترۆ قۆناغی یەکەم']);
        $approved = $this->event(['title_ckb' => 'مێترۆ قۆناغی دووەم', 'event_type' => 'market_shift']);
        $approved->forceFill(['status' => KnowledgeStatus::Approved])->save();

        // A Draft is findable in admin exactly as today.
        $this->assertSame(KnowledgeStatus::Draft, $draft->fresh()->status);
        $this->assertFindsExactly($admin, 'مێترۆ', [$draft->id, $approved->id]);

        // status and event_type still compose with q.
        $this->assertFindsExactly($admin, 'مێترۆ', [$draft->id], ['status' => 'draft']);
        $this->assertFindsExactly($admin, 'مێترۆ', [$approved->id], ['status' => 'approved']);
        $this->assertFindsExactly($admin, 'مێترۆ', [$approved->id], ['event_type' => 'market_shift']);
        $this->assertFindsExactly($admin, 'مێترۆ', [$draft->id], ['event_type' => 'infrastructure']);
    }

    /* ------------------------------------------------------ backfill */

    public function test_the_backfill_repairs_null_and_empty_keys_and_preserves_real_ones(): void
    {
        $admin = $this->admin();

        // Legacy rows exactly as production holds them: NULL from direct
        // inserts, '' from every pre-fix Eloquent save — inserted here
        // through the query builder so no model hook can interfere.
        $base = [
            'event_type' => 'infrastructure', 'direction' => 'positive', 'strength' => 3,
            'effective_date' => '2026-08-01', 'status' => 'draft',
            'created_at' => now(), 'updated_at' => now(),
        ];
        $nullId = DB::table('knowledge_events')->insertGetId($base + [
            'title_ckb' => 'گەڕەکی نوێ لە ئەنکاوە', 'search_key' => null,
        ]);
        $emptyId = DB::table('knowledge_events')->insertGetId($base + [
            'title_ckb' => 'بازاڕی نوێی هەولێر', 'title_en' => 'New Erbil Bazaar', 'search_key' => '',
        ]);
        $keptId = DB::table('knowledge_events')->insertGetId($base + [
            'title_ckb' => 'ناونیشانی جیاواز', 'search_key' => 'hand-repaired-key',
        ]);

        $migration = require base_path(self::BACKFILL);
        $migration->up();

        $keys = DB::table('knowledge_events')->whereIn('id', [$nullId, $emptyId, $keptId])
            ->pluck('search_key', 'id');

        $this->assertSame(SoraniText::searchKey('گەڕەکی نوێ لە ئەنکاوە'), $keys[$nullId]);
        $this->assertSame(SoraniText::searchKey('بازاڕی نوێی هەولێر New Erbil Bazaar'), $keys[$emptyId]);
        // A key someone already repaired is never overwritten.
        $this->assertSame('hand-repaired-key', $keys[$keptId]);

        // Idempotent: a second run changes nothing.
        $migration->up();
        $this->assertSame(
            $keys->sort()->values()->all(),
            DB::table('knowledge_events')->whereIn('id', [$nullId, $emptyId, $keptId])
                ->pluck('search_key', 'id')->sort()->values()->all(),
        );

        // And the repaired rows are genuinely findable through the endpoint.
        $this->assertFindsExactly($admin, 'گەڕەکی', [$nullId]);
        $this->assertFindsExactly($admin, 'Bazaar', [$emptyId]);
    }

    /* ---------------------------------------------- sibling contract */

    public function test_a_name_keyed_sibling_still_derives_its_key_from_names(): void
    {
        $area = Area::query()->create([
            'slug' => 'search-integrity-area',
            'type' => 'district',
            'name_ckb' => 'ئەنکاوە',
            'name_ar' => 'عنكاوة',
            'name_en' => 'Ankawa',
            'publication_status' => 'published',
        ]);

        $this->assertSame(SoraniText::searchKey('ئەنکاوە عنكاوة Ankawa'), $area->fresh()->search_key);
    }
}
