<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Geography\Models\Area;
use App\Modules\Identity\Models\User;
use App\Modules\Localization\Support\SoraniText;
use App\Modules\Marketplace\Enums\OfferStatus;
use App\Modules\Marketplace\Models\Offer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * Marketplace offer search integrity (Phase 17).
 *
 * `offers.search_key` shipped with the marketplace tables and BOTH offer
 * searches — the admin moderation list and the public /offers browse —
 * have always filtered on it, but no code path ever wrote it, so every
 * text query in every locale returned nothing on both surfaces. The
 * contracts pinned here: the model's title-keyed derivation populates the
 * key on create and refreshes it on update; the real admin endpoint and
 * the real public endpoint both find offers in ckb, ar and en, through
 * folding, digits and partial words; fixing search does NOT leak
 * unpublished offers into public results; existing filters still compose
 * with q on both surfaces; the backfill migration repairs legacy NULL and
 * '' rows without touching a key that is already real; and the healthy
 * name_* search-key siblings keep their exact behavior.
 */
final class OfferSearchIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private const BACKFILL = 'app/Modules/Marketplace/Database/Migrations/2026_08_17_000200_backfill_offer_search_keys.php';

    protected function setUp(): void
    {
        parent::setUp();

        $this->setFeatures(['marketplace.offers' => true]);
    }

    private function admin(): User
    {
        return User::factory()->superAdmin()->create();
    }

    /**
     * An offer through the same lifecycle the admin store() uses.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function offer(array $attributes = [], OfferStatus $status = OfferStatus::Draft): Offer
    {
        $offer = new Offer(array_merge([
            'title_ckb' => 'شوقەی فرۆشتن لە ئەنکاوە',
            'offer_type' => 'sale',
            'property_type' => 'apartment',
            'location_precision' => 'area',
        ], $attributes));
        $offer->status = OfferStatus::Draft;
        $offer->save();

        if ($status !== OfferStatus::Draft) {
            $offer->forceFill([
                'status' => $status,
                'published_at' => $status === OfferStatus::Published ? now() : null,
            ])->save();
        }

        return $offer->fresh();
    }

    /** @param array<string, mixed> $attributes */
    private function published(array $attributes = []): Offer
    {
        return $this->offer($attributes, OfferStatus::Published);
    }

    /**
     * @param  array<string, string>  $filters
     * @return TestResponse<Response>
     */
    private function adminSearch(string $q, array $filters = []): TestResponse
    {
        return $this->actingAs($this->admin())
            ->get('/admin/offers?'.http_build_query(array_merge(['q' => $q], $filters)));
    }

    /**
     * @param  list<int>  $ids
     * @param  array<string, string>  $filters
     */
    private function assertAdminFinds(string $q, array $ids, array $filters = []): void
    {
        $this->adminSearch($q, $filters)
            ->assertOk()
            ->assertInertia(function ($page) use ($ids): void {
                $page->where('offers.total', count($ids))
                    ->where('offers.data', function ($rows) use ($ids): bool {
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

    /**
     * The ids the PUBLIC browse returns for a query — organic and sponsored
     * together, because a leak through either lane is a leak.
     *
     * @param  array<string, string>  $filters
     * @return list<string> public_ids
     */
    private function publicResults(string $q, array $filters = []): array
    {
        $response = $this->get('/offers?'.http_build_query(array_merge(['q' => $q], $filters)));
        $response->assertOk();

        $props = $response->viewData('page')['props'];

        return collect([...$props['organic'], ...$props['sponsored']])
            ->pluck('public_id')->values()->all();
    }

    /* ------------------------------------------------- key derivation */

    public function test_titles_in_all_three_languages_populate_the_key(): void
    {
        $ckbOnly = $this->offer(['title_ckb' => 'شوقەی نوێ لە هەولێر']);
        $this->assertSame(SoraniText::searchKey('شوقەی نوێ لە هەولێر'), $ckbOnly->search_key);
        $this->assertNotSame('', $ckbOnly->search_key);

        $trilingual = $this->offer([
            'title_ckb' => 'شوقەی مێترۆ',
            'title_ar' => 'شقة المترو',
            'title_en' => 'Metro Apartment',
        ]);
        $this->assertStringContainsString(SoraniText::searchKey('شقة المترو'), $trilingual->search_key);
        $this->assertStringContainsString(SoraniText::searchKey('Metro Apartment'), $trilingual->search_key);
    }

    public function test_an_update_refreshes_the_key_and_the_queries_follow(): void
    {
        $offer = $this->offer(['title_ckb' => 'کۆشکی گەورە بۆ کرێ']);

        $this->assertAdminFinds('کۆشکی', [$offer->id]);

        $offer->title_ckb = 'باڵەخانەی بازرگانی نوێ';
        $offer->save();

        // The old needle stops matching; the new one matches.
        $this->assertAdminFinds('کۆشکی', []);
        $this->assertAdminFinds('باڵەخانەی', [$offer->id]);
        $this->assertSame(SoraniText::searchKey('باڵەخانەی بازرگانی نوێ'), $offer->fresh()->search_key);
    }

    /* --------------------------------------- the real admin endpoint */

    public function test_the_admin_endpoint_finds_offers_in_all_three_languages(): void
    {
        $offer = $this->offer([
            'title_ckb' => 'شوقەی مێترۆی هەولێر',
            'title_ar' => 'شقة مترو أربيل',
            'title_en' => 'Erbil Metro Apartment',
        ]);
        $this->offer(['title_ckb' => 'زەوی جیاواز', 'property_type' => 'land']);

        $this->assertAdminFinds('مێترۆی', [$offer->id]);       // ckb, partial word
        $this->assertAdminFinds('مترو', [$offer->id]);          // ar
        $this->assertAdminFinds('Metro', [$offer->id]);         // en, case-insensitive
        $this->assertAdminFinds('metro apartment', [$offer->id]);
    }

    public function test_folded_variants_and_digits_match_in_admin(): void
    {
        $offer = $this->offer(['title_ckb' => 'پڕۆژەی ٢٠٢٦ لە مێترۆ']);

        // ێ→ی and ۆ→و fold identically on both sides.
        $this->assertAdminFinds('میترو', [$offer->id]);
        $this->assertAdminFinds('مێترۆ', [$offer->id]);
        // Arabic-Indic digits stored, Latin digits queried.
        $this->assertAdminFinds('2026', [$offer->id]);
        // And an unrelated query returns zero.
        $this->assertAdminFinds('قوتابخانە', []);
    }

    public function test_admin_status_and_queue_filters_still_compose_with_q(): void
    {
        $draft = $this->offer(['title_ckb' => 'مێترۆ ڕەشنووس']);
        $submitted = $this->offer(['title_ckb' => 'مێترۆ پێشکەشکراو'], OfferStatus::Submitted);

        $this->assertAdminFinds('مێترۆ', [$draft->id, $submitted->id]);
        $this->assertAdminFinds('مێترۆ', [$draft->id], ['status' => 'draft']);
        $this->assertAdminFinds('مێترۆ', [$submitted->id], ['status' => 'submitted']);
        // The moderation queue (submitted/under_review) composes too.
        $this->assertAdminFinds('مێترۆ', [$submitted->id], ['queue' => '1']);
    }

    /* -------------------------------------- the real public endpoint */

    public function test_the_public_browse_finds_published_offers_in_all_three_languages(): void
    {
        $offer = $this->published([
            'title_ckb' => 'شوقەی مێترۆی ئەنکاوە',
            'title_ar' => 'شقة مترو عنكاوة',
            'title_en' => 'Ankawa Metro Apartment',
        ]);
        $this->published(['title_ckb' => 'زەوی لە ناوچەیەکی جیاواز', 'property_type' => 'land']);

        $this->assertSame([$offer->public_id], $this->publicResults('مێترۆی'));
        $this->assertSame([$offer->public_id], $this->publicResults('مترو'));
        $this->assertSame([$offer->public_id], $this->publicResults('Metro'));
        // Folded spelling and partial words behave exactly as in admin.
        $this->assertSame([$offer->public_id], $this->publicResults('میترو'));
        // Unrelated query returns nothing.
        $this->assertSame([], $this->publicResults('قوتابخانە'));
    }

    public function test_fixing_search_does_not_leak_unpublished_offers_publicly(): void
    {
        $published = $this->published(['title_ckb' => 'مێترۆ بڵاوکراوە']);
        $this->offer(['title_ckb' => 'مێترۆ ڕەشنووسی شاراوە']);                           // draft
        $this->offer(['title_ckb' => 'مێترۆ پێشکەشکراوی شاراوە'], OfferStatus::Submitted); // awaiting moderation

        // All three now carry matching keys — the ADMIN sees all three…
        $this->adminSearch('مێترۆ')->assertInertia(fn ($page) => $page->where('offers.total', 3));

        // …but the public browse returns ONLY the published one. Search
        // correctness must not widen visibility by a single row.
        $this->assertSame([$published->public_id], $this->publicResults('مێترۆ'));
    }

    public function test_public_type_filter_still_composes_with_q(): void
    {
        $apartment = $this->published(['title_ckb' => 'مێترۆ شوقە']);
        $land = $this->published(['title_ckb' => 'مێترۆ زەوی', 'property_type' => 'land']);

        $this->assertSame(
            collect([$apartment->public_id, $land->public_id])->sort()->values()->all(),
            collect($this->publicResults('مێترۆ'))->sort()->values()->all(),
        );
        $this->assertSame([$apartment->public_id], $this->publicResults('مێترۆ', ['type' => 'apartment']));
        $this->assertSame([$land->public_id], $this->publicResults('مێترۆ', ['type' => 'land']));
    }

    /* ------------------------------------------------------ backfill */

    public function test_the_backfill_repairs_null_and_empty_keys_and_preserves_real_ones(): void
    {
        // Legacy rows exactly as production holds them, inserted through the
        // query builder so no model hook can interfere.
        $base = [
            'offer_type' => 'sale', 'property_type' => 'apartment',
            'location_precision' => 'area', 'status' => 'draft',
            'created_at' => now(), 'updated_at' => now(),
        ];
        $nullId = DB::table('offers')->insertGetId(array_merge($base, [
            'public_id' => 'p17-backfill-null',
            'title_ckb' => 'گەڕەکی نوێ بۆ فرۆشتن', 'search_key' => null,
        ]));
        $emptyId = DB::table('offers')->insertGetId(array_merge($base, [
            'public_id' => 'p17-backfill-empty',
            'title_ckb' => 'بازاڕی کرێ', 'title_en' => 'Bazaar for rent', 'search_key' => '',
        ]));
        $keptId = DB::table('offers')->insertGetId(array_merge($base, [
            'public_id' => 'p17-backfill-kept',
            'title_ckb' => 'ناونیشانی جیاواز', 'search_key' => 'hand-repaired-key',
        ]));

        $migration = require base_path(self::BACKFILL);
        $migration->up();

        $keys = DB::table('offers')->whereIn('id', [$nullId, $emptyId, $keptId])->pluck('search_key', 'id');

        $this->assertSame(SoraniText::searchKey('گەڕەکی نوێ بۆ فرۆشتن'), $keys[$nullId]);
        $this->assertSame(SoraniText::searchKey('بازاڕی کرێ Bazaar for rent'), $keys[$emptyId]);
        $this->assertSame('hand-repaired-key', $keys[$keptId]);

        // Idempotent: a second run changes nothing.
        $migration->up();
        $this->assertSame(
            $keys->sort()->values()->all(),
            DB::table('offers')->whereIn('id', [$nullId, $emptyId, $keptId])->pluck('search_key', 'id')->sort()->values()->all(),
        );

        // And a repaired row is genuinely findable through the admin endpoint.
        $this->assertAdminFinds('Bazaar', [$emptyId]);
    }

    /* ---------------------------------------------- sibling contract */

    public function test_a_name_keyed_sibling_still_derives_its_key_from_names(): void
    {
        $area = Area::query()->create([
            'slug' => 'offer-search-integrity-area',
            'type' => 'district',
            'name_ckb' => 'ئەنکاوە',
            'name_ar' => 'عنكاوة',
            'name_en' => 'Ankawa',
            'publication_status' => 'published',
        ]);

        $this->assertSame(SoraniText::searchKey('ئەنکاوە عنكاوة Ankawa'), $area->fresh()->search_key);
    }
}
