<?php

declare(strict_types=1);

/*
 * Disposable fixtures for the Playwright acceptance suite.
 *
 * WHY THIS IS A STANDALONE SCRIPT AND NOT A SEEDER CLASS.
 *
 * A seeder registered in DatabaseSeeder is one `db:seed` away from running on a
 * real installation, and this script creates accounts with known passwords. It
 * is deliberately awkward to invoke, refuses to run outside the local/testing
 * environment, and refuses to run against a database that already holds real
 * users — three independent guards, because the cost of getting this wrong is a
 * known-password super admin on a production host.
 *
 *   php tests/Browser/support/seed-browser-fixtures.php
 *
 * It writes tests/Browser/support/fixtures.json for the specs to read. That file
 * is gitignored and excluded from every release archive: credentials belong in
 * neither Clean Source nor Production Deployment.
 *
 * WHAT IT CREATES
 *
 *   - a super admin, for the authorised-access and admin-navigation tests
 *   - a plain account with no roles, for the privilege-boundary test
 *   - an MFA-enrolled super admin, with its TOTP secret exported so the spec can
 *     compute a valid code
 *   - the `advisor.residential` feature flag switched ON, so the Advisor's
 *     enabled state can be exercised
 *
 * WHAT IT DOES NOT CREATE
 *
 *   No areas, no offers, no AI conversations, no market indices. The
 *   acceptance suite asserts against empty states and real flags; inventing
 *   market data to make a screenshot look fuller would defeat the one property
 *   this product's UI is built around. The four investment-map projects are
 *   the deliberate exception: the map suite must prove markers, prices and
 *   trends render from PERSISTED rows, and an empty map cannot prove that.
 *   Between them the four cover every trend the product may claim — up, down
 *   and flat each from two real comparable observations, and unknown from a
 *   single observation, because insufficient history must render as a
 *   neutral marker and the only way to test that is to seed the absence.
 */

use App\Modules\Geography\Models\Area;
use App\Modules\Geography\Models\Place;
use App\Modules\Geography\Models\PlaceCategory;
use App\Modules\Identity\Enums\RoleKey;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\Totp;
use App\Modules\Knowledge\Models\KnowledgeEvent;
use App\Modules\Market\Enums\PriceType;
use App\Modules\Market\Enums\ScopeType;
use App\Modules\Market\Models\PriceRecord;
use App\Modules\Portfolio\Models\PortfolioProperty;
use App\Modules\Portfolio\Models\PortfolioValuation;
use App\Modules\Portfolio\Models\ValuationQuestion;
use App\Modules\Portfolio\Models\ValuationQuestionOption;
use App\Modules\Portfolio\Models\ValuationRuleSet;
use App\Modules\Portfolio\Services\ValuationRulePublisher;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/*
 * Imports precede the bootstrap deliberately. With the `require` lines first,
 * Pint's fully_qualified_strict_types rule shortened `Kernel::class` while the
 * `use` block below it was not yet in scope for static analysis, and PHPStan
 * then reported a class it could not find. Ordinary PSR-12 file order avoids
 * the whole argument.
 */
require __DIR__.'/../../../vendor/autoload.php';

$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

function fail(string $message): never
{
    fwrite(STDERR, "REFUSED: {$message}\n");
    exit(1);
}

/* Guard 1 — environment. */
if (! in_array(app()->environment(), ['local', 'testing'], true)) {
    fail('APP_ENV is "'.app()->environment().'". This script runs only in local or testing.');
}

/* Guard 2 — the database must not already hold real accounts. */
$existing = DB::table('users')->whereNotLike('email', '%@browser-test.invalid')->count();

if ($existing > 0) {
    fail("the users table holds {$existing} account(s) that this script did not create.");
}

/* Guard 3 — an explicit opt-in, so a stray invocation cannot proceed silently. */
if (($argv[1] ?? '') !== '--confirm-disposable-database') {
    fail('pass --confirm-disposable-database to confirm this database is disposable.');
}

echo "seeding browser fixtures into '".config('database.connections.mysql.database')."'\n";

/* Roles must exist before anything can be attached to them. */
Artisan::call('db:seed', ['--class' => 'Database\\Seeders\\RoleSeeder', '--force' => true]);

$superAdmin = Role::query()->where('key', RoleKey::SuperAdmin->value)->firstOrFail();

$password = 'BrowserTest!'.bin2hex(random_bytes(6));

$admin = User::query()->updateOrCreate(
    ['email' => 'admin@browser-test.invalid'],
    ['name' => 'Browser Admin', 'password' => Hash::make($password), 'preferred_locale' => 'ckb', 'is_active' => true],
);
$admin->roles()->sync([$superAdmin->id]);

$plain = User::query()->updateOrCreate(
    ['email' => 'plain@browser-test.invalid'],
    ['name' => 'Browser Plain', 'password' => Hash::make($password), 'preferred_locale' => 'ckb', 'is_active' => true],
);
$plain->roles()->sync([]);

$secret = Totp::generateSecret();
$adminSecret = Totp::generateSecret();

$mfa = User::query()->updateOrCreate(
    ['email' => 'mfa@browser-test.invalid'],
    ['name' => 'Browser MFA', 'password' => Hash::make($password), 'preferred_locale' => 'ckb', 'is_active' => true],
);

/*
 * MFA material is deliberately NOT mass-assignable on the model — spec 32.2
 * keeps the secret and the recovery codes off every fillable list so they can
 * never arrive from a request. Setting them here goes through explicit
 * assignment, which is the same path the real enrolment controller uses.
 */
/*
 * v6 merge: the Advisor and the other personal surfaces now sit behind
 * `telegram.linked`, not `verified`. The Telegram work made a PROVEN
 * identity the gate — a typed phone is not a proven one — so an account
 * that is only `phone_verified` is redirected to the link page and the
 * Advisor's enabled state is unreachable.
 *
 * The fixtures therefore express the CURRENT contract: verified phone AND
 * a linked, active account. Nothing contacts Telegram and no phone number
 * is stored; `telegram_verified_at` is the same column the confirmation
 * flow sets, written here through explicit assignment.
 */
$linkedId = 990000;

foreach ([$admin, $plain, $mfa] as $account) {
    // Typed int on the model, not bool — the column is a tinyint flag.
    $account->phone_verified = 1;
    $account->telegram_id = (string) $linkedId++;
    $account->telegram_verified_at = now();
    $account->is_active = true;
    $account->save();
}

/*
 * THE SECRET IS STORED ENCRYPTED, because that is what the product does.
 * MfaController::store writes `Crypt::encryptString($secret)` and
 * MfaController::verify reads it back through `Crypt::decryptString`, returning
 * null when that fails. Seeding the plaintext therefore produced a null secret
 * at verification time and every correct code was rejected as "wrong or
 * expired" — a fixture bug that looked exactly like a broken second factor for
 * two sessions. The plaintext goes to fixtures.json so the test can compute
 * codes; the database gets the ciphertext, exactly as enrolment writes it.
 *
 * The admin is enrolled too: `admin_mfa_required` defaults true and both
 * accounts are administrative, so without a second factor no test can reach an
 * admin page at all.
 */
$admin->mfa_secret = Crypt::encryptString($adminSecret);
$admin->mfa_confirmed_at = now();
$admin->save();

$mfa->mfa_secret = Crypt::encryptString($secret);
$mfa->mfa_confirmed_at = now();
$mfa->save();
$mfa->roles()->sync([$superAdmin->id]);

/*
 * The Advisor's enabled state. The flag lives in the database precisely so an
 * operator can switch it without a deploy, so the test switches it the same way
 * rather than patching config — the test then exercises the real code path.
 */
DB::table('feature_flags')->updateOrInsert(
    ['flag' => 'advisor.residential'],
    ['enabled' => true, 'updated_at' => now(), 'created_at' => now()],
);

/*
 * The Investment Map, switched on the same way — through the flag table the
 * operator would use — plus two published projects so the surface has real
 * rows: one with a price history (the trend badge must render from data,
 * not fixtures injected client-side) and one bare.
 */
DB::table('feature_flags')->updateOrInsert(
    ['flag' => 'map.investment'],
    ['enabled' => true, 'updated_at' => now(), 'created_at' => now()],
);

/*
 * The public map explorer as well: the map-production suite must prove /map
 * leaves its loading state on a real (deterministic) style, and a flag left
 * off would make that spec measure the flag, not the map.
 */
DB::table('feature_flags')->updateOrInsert(
    ['flag' => 'map.explorer'],
    ['enabled' => true, 'updated_at' => now(), 'created_at' => now()],
);

/*
 * One published project per trend the markers may claim. Every coordinate is
 * inside Erbil's bounding box, every history is two comparable observations
 * (same currency, same price type) or deliberately none — these rows exercise
 * the derivation, they never bypass it.
 */
$projects = [
    // +4.8% across two USD sale_asking rows → up.
    ['slug' => 'browser-invest-tower', 'name' => 'بورجی وەبەرهێنانی تاقیکردنەوە', 'type' => 'tower',
        'lat' => 36.1950000, 'lng' => 44.0150000, 'history' => [['100000.00', '2026-06-01'], ['104800.00', '2026-07-01']]],
    // ONE observation → unknown: a current price exists, but a single point
    // is not a direction. This row is the "never dress missing history up
    // as flat" case, live in a browser.
    ['slug' => 'browser-invest-villa', 'name' => 'ڤیلاکانی تاقیکردنەوە', 'type' => 'villa',
        'lat' => 36.2050000, 'lng' => 44.0250000, 'history' => [['95000.00', '2026-07-01']]],
    // −10% → down.
    ['slug' => 'browser-invest-bazaar', 'name' => 'بازاڕی وەبەرهێنانی تاقیکردنەوە', 'type' => 'commercial',
        'lat' => 36.1850000, 'lng' => 44.0000000, 'history' => [['100000.00', '2026-06-01'], ['90000.00', '2026-07-01']]],
    // Unchanged across two real observations → genuinely flat, never a
    // stand-in for "no data".
    ['slug' => 'browser-invest-court', 'name' => 'کۆمەڵگەی نیشتەجێبوونی تاقیکردنەوە', 'type' => 'residential',
        'lat' => 36.1750000, 'lng' => 44.0350000, 'history' => [['100000.00', '2026-06-01'], ['100000.00', '2026-07-01']]],
];

foreach ($projects as $fixture) {
    DB::table('projects')->updateOrInsert(
        ['slug' => $fixture['slug']],
        [
            'name_ckb' => $fixture['name'],
            'project_type' => $fixture['type'],
            'construction_status' => 'under_construction',
            'delivery_status' => 'not_started',
            'publication_status' => 'published',
            'latitude' => $fixture['lat'],
            'longitude' => $fixture['lng'],
            'created_at' => now(),
            'updated_at' => now(),
        ],
    );
    $row = DB::table('projects')->where('slug', $fixture['slug'])->first();

    foreach ($fixture['history'] as [$price, $date]) {
        DB::table('project_prices')->updateOrInsert(
            ['project_id' => $row->id, 'effective_date' => $date],
            [
                'price_from' => $price,
                'currency' => 'USD',
                'price_type' => 'sale_asking',
                'period' => substr($date, 0, 7),
                'source' => 'browser-fixture',
                'confidence' => 'medium',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }
}

/*
 * Map Phase 3: ONE published project INSIDE the browser-ankawa ring (its
 * south-west quarter), so the hit-test priority is provable in a real
 * browser — a click on this marker must NEVER select the polygon beneath
 * it. Deliberately without price history: the marker is a lone neutral dot
 * with no trend glyph and no price-label band. Placed ≥70px (at the
 * explorer's default camera) from the area's own centroid marker so the
 * two never cluster, and clear of the polygon-click pixel the spec uses.
 */
DB::table('projects')->updateOrInsert(
    ['slug' => 'browser-area-project'],
    [
        'name_ckb' => 'پڕۆژەی ناو ئەنکاوە',
        'project_type' => 'residential',
        'construction_status' => 'under_construction',
        'delivery_status' => 'not_started',
        'publication_status' => 'published',
        'latitude' => 36.2110000,
        'longitude' => 43.9720000,
        'created_at' => now(),
        'updated_at' => now(),
    ],
);

/*
 * The project wizard, for the map suite's provider-failure test: the flag
 * switched on the operator's way, plus ONE unscoped draft owned by the admin
 * with the identity step already completed — so the Location step (the
 * wizard MapPicker) is directly reachable in a browser without walking the
 * earlier steps, which are not what that test measures.
 */
DB::table('feature_flags')->updateOrInsert(
    ['flag' => 'projects.wizard'],
    ['enabled' => true, 'updated_at' => now(), 'created_at' => now()],
);

DB::table('project_drafts')->updateOrInsert(
    ['user_id' => $admin->id, 'current_step' => 'location'],
    [
        'company_id' => null,
        'project_id' => null,
        'payload' => json_encode(['identity' => ['name_ckb' => 'ڕەشنووسی تاقیکردنەوەی نەخشە']]),
        'completed_steps' => json_encode(['identity']),
        'last_touched_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ],
);
$wizardDraftId = (int) DB::table('project_drafts')
    ->where('user_id', $admin->id)
    ->where('current_step', 'location')
    ->value('id');

/*
 * Phase 12: ONE published area, for the market-grounding flow — the admin
 * records a KnowledgeEvent about this area through the real form, and the
 * advisor's market answer must ground on it. Names in all three languages so
 * the deterministic area matching is exercised the way production data is.
 */
$ankawa = Area::query()->updateOrCreate(
    ['slug' => 'browser-ankawa'],
    [
        'type' => 'district',
        'name_ckb' => 'ئەنکاوە',
        'name_ar' => 'عنكاوة',
        'name_en' => 'Ankawa',
        'publication_status' => 'published',
    ],
);

/*
 * Phase 13: the public area profile behind its real flag, plus ONE Published
 * market event on the fixture area — deterministic content for the mobile
 * no-overflow and Arabic-locale assertions, which run on viewports where the
 * admin publish flow does not. The desktop spec still drives the real
 * create→review→approve→publish workflow for its own token event.
 */
DB::table('feature_flags')->updateOrInsert(
    ['flag' => 'geography.areas'],
    ['enabled' => true, 'updated_at' => now(), 'created_at' => now()],
);

/*
 * Map Phase 2: the places layer behind its real flag, with two published
 * places in two categories inside the explorer's opening viewport. The map
 * spec toggles the layer and a category chip against this data, driving the
 * setPois() overlay path in a real browser — any runtime error there fails
 * the run through the console-error diagnostics.
 */
DB::table('feature_flags')->updateOrInsert(
    ['flag' => 'places.database'],
    ['enabled' => true, 'updated_at' => now(), 'created_at' => now()],
);

$poiSchoolCategory = PlaceCategory::query()->updateOrCreate(
    ['key' => 'school'],
    [
        'group' => 'education',
        'name_ckb' => 'قوتابخانە',
        'name_ar' => 'مدرسة',
        'name_en' => 'School',
        'is_active' => true,
        'sort_order' => 1,
    ],
);

$poiPharmacyCategory = PlaceCategory::query()->updateOrCreate(
    ['key' => 'pharmacy'],
    [
        'group' => 'health',
        'name_ckb' => 'دەرمانخانە',
        'name_ar' => 'صيدلية',
        'name_en' => 'Pharmacy',
        'is_active' => true,
        'sort_order' => 2,
    ],
);

/*
 * Map Phase 3: both places are ASSIGNED to the fixture area (assignment is
 * the services contract — the summary counts area_id, never point-in-
 * polygon), so the browser-ankawa Area Intelligence card renders real
 * seeded service counts: education 1, health 1.
 */
Place::query()->updateOrCreate(
    ['slug' => 'browser-poi-school'],
    [
        'external_id' => 'osm:node:900001',
        'area_id' => $ankawa->id,
        'place_category_id' => $poiSchoolCategory->id,
        'name_ckb' => 'قوتابخانەی ئازادی',
        'name_ar' => 'مدرسة آزادي',
        'name_en' => 'Azadi School',
        'latitude' => 36.1910000,
        'longitude' => 44.0080000,
        'publication_status' => 'published',
        'is_public' => true,
        'is_duplicate_primary' => true,
        'operational_status' => 'operating',
        'source' => 'openstreetmap',
        'source_url' => 'https://www.openstreetmap.org/node/900001',
    ],
);

Place::query()->updateOrCreate(
    ['slug' => 'browser-poi-pharmacy'],
    [
        'external_id' => 'osm:node:900002',
        'area_id' => $ankawa->id,
        'place_category_id' => $poiPharmacyCategory->id,
        'name_ckb' => 'دەرمانخانەی نەورۆز',
        'name_ar' => 'صيدلية نوروز',
        'name_en' => 'Nawroz Pharmacy',
        'latitude' => 36.1880000,
        'longitude' => 44.0120000,
        'publication_status' => 'published',
        'is_public' => true,
        'is_duplicate_primary' => true,
        'operational_status' => 'operating',
        'source' => 'openstreetmap',
        'source_url' => 'https://www.openstreetmap.org/node/900002',
    ],
);

KnowledgeEvent::query()->updateOrCreate(
    [
        'entity_type' => 'area',
        'entity_id' => $ankawa->id,
        'title_ckb' => 'بەرزبوونەوەی داواکاری لە ئەنکاوە',
    ],
    [
        'title_ar' => 'ارتفاع الطلب في عنكاوة',
        'title_en' => 'Demand rising in Ankawa',
        'summary_ckb' => 'داواکاری لەسەر موڵک لە ئەنکاوە بەرزبووەتەوە.',
        'summary_ar' => 'ارتفع الطلب على العقارات في عنكاوة.',
        'summary_en' => 'Property demand in Ankawa has risen.',
        'event_type' => 'demand',
        'direction' => 'positive',
        'strength' => 3,
        'effective_date' => '2026-08-01',
        'source' => 'تیمی چاودێری بازاڕ',
        'confidence' => 'medium',
        'evidence_class' => 'admin_observation',
        'ai_usage_permitted' => false,
        'status' => 'published',
    ],
);

/*
 * Wave 3: the location-intelligence scenario. The fixture area gains what the
 * geolocation flow actually resolves against — a real polygon and a real
 * published index value — because the E2E must prove the card renders from
 * PERSISTED rows through the real resolver, exactly as the four investment
 * projects prove the trend pipeline. The stubbed browser coordinate
 * (36.225, 43.99) sits inside this ring; the outside-coverage coordinate
 * (36.10, 44.20) sits inside the operating area but outside every polygon.
 *
 * Map Phase 3 widened the ring (43.960–44.004 × 36.205–36.245, previously
 * 43.980–44.000 × 36.215–36.235): the explorer's polygon-click tests need a
 * pixel INSIDE the ring that clears the area's own centroid marker, the
 * in-ring project above, and every ring edge by a comfortable margin at the
 * default camera (36.19, 44.009, z11). Both Wave 3 coordinates keep their
 * meaning — INSIDE stays inside, OUTSIDE stays outside.
 */
$ankawa->forceFill([
    'latitude' => '36.2250000',
    'longitude' => '43.9900000',
    'boundary_wkt' => 'POLYGON((43.960 36.205, 44.004 36.205, 44.004 36.245, 43.960 36.245, 43.960 36.205))',
])->save();

// The price layer's flag plus the portfolio flag, switched the operator's
// way: the card's price block and its valuation CTA are both real gated
// surfaces, and the specs must exercise their enabled states.
DB::table('feature_flags')->updateOrInsert(
    ['flag' => 'market.indices'],
    ['enabled' => true, 'updated_at' => now(), 'created_at' => now()],
);

DB::table('feature_flags')->updateOrInsert(
    ['flag' => 'portfolio'],
    ['enabled' => true, 'updated_at' => now(), 'created_at' => now()],
);

// ONE published area-scoped index with one published, reliable value — the
// real figure the resolved card must show. Trilingual name, like the area.
DB::table('market_indices')->updateOrInsert(
    ['key' => 'browser-ankawa-sale'],
    [
        'name_ckb' => 'پێوەری فرۆشتنی ئەنکاوە',
        'name_ar' => 'مؤشر بيع عنكاوة',
        'name_en' => 'Ankawa sale index',
        'scope_type' => 'area',
        'scope_id' => $ankawa->id,
        'price_type' => 'sale_asking',
        'basis' => 'per_sqm',
        'currency' => 'USD',
        'methodology_version' => 'v1',
        'minimum_sample' => 10,
        'publication_status' => 'published',
        'created_at' => now(),
        'updated_at' => now(),
    ],
);
$browserIndexId = (int) DB::table('market_indices')->where('key', 'browser-ankawa-sale')->value('id');

DB::table('market_index_values')->updateOrInsert(
    ['market_index_id' => $browserIndexId, 'period' => '2026-07'],
    [
        'effective_date' => '2026-07-31',
        'methodology_version' => 'v1',
        'value' => '1250.0000',
        'sample_size' => 34,
        'excluded_outliers' => 0,
        // IndexCalculator's real vocabulary: insufficient/low/moderate/high.
        // 'medium' is not a level the product produces, and the card renders
        // confidence through market.confidence.* — an invented level would
        // surface as a raw translation key.
        'confidence' => 'moderate',
        'is_limited' => false,
        'publication_status' => 'published',
        'published_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ],
);

/*
 * Map Phase 4: ONE observation exactly twelve months older, so the
 * heatmap's 1y and All windows hold a genuine +5.04% pair for the seeded
 * ring (1190 → 1250) while every other movement expectation stays
 * byte-identical: the pair is a year apart, so 7d/30d/1m stay honestly
 * unsupported, and the index carries no property_type — the spanning
 * "all categories" case — so the pulse panel's category-filter counts
 * (exact-count assertions in market-movement.spec.ts) never see it.
 * The latest reliable value is still 2026-07's 1250, so the Wave 3
 * location card and Phase 3 area card keep their figure untouched.
 */
DB::table('market_index_values')->updateOrInsert(
    ['market_index_id' => $browserIndexId, 'period' => '2025-07'],
    [
        'effective_date' => '2025-07-31',
        'methodology_version' => 'v1',
        'value' => '1190.0000',
        'sample_size' => 30,
        'excluded_outliers' => 0,
        'confidence' => 'moderate',
        'is_limited' => false,
        'publication_status' => 'published',
        'published_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ],
);

/*
 * Wave 4: the market-movement scenario. Two published areas with published
 * monthly index series — a genuine gainer, a genuine loser, a second sale
 * category and one rent series — so the browser spec can drive the REAL
 * /market/movement endpoint against persisted rows, exactly as the four
 * investment projects prove the map trend pipeline. The June and July
 * observations sit exactly 30 real days apart (2026-06-01 → 2026-07-01),
 * which is what makes the 30D dated window HONESTLY available; nothing sits
 * within 7 days of anything, so 7D must render disabled with its reason.
 * No coordinates and no polygons: these areas must stay invisible to every
 * map surface and to the location resolver.
 */

/*
 * The whole surface lives behind `market.intelligence`, enforced at the
 * route boundary (Market/Routes/web.php): with the flag off, /market is a
 * genuine 404 and the homepage hides both market sections, so the spec
 * would be measuring the flag instead of the movement panel — the same
 * reason map.explorer is switched on above.
 */
DB::table('feature_flags')->updateOrInsert(
    ['flag' => 'market.intelligence'],
    ['enabled' => true, 'updated_at' => now(), 'created_at' => now()],
);

$movementAreas = [
    'mv-kasnazan' => ['کەسنەزان', 'كسنزان', 'Kasnazan'],
    'mv-baharka' => ['بەهارکە', 'بهاركة', 'Baharka'],
];

$movementAreaIds = [];

foreach ($movementAreas as $slug => [$ckb, $ar, $en]) {
    $movementAreaIds[$slug] = Area::query()->updateOrCreate(
        ['slug' => $slug],
        [
            'type' => 'district',
            'name_ckb' => $ckb,
            'name_ar' => $ar,
            'name_en' => $en,
            'publication_status' => 'published',
        ],
    )->id;
}

$movementIndices = [
    // key => [area slug, property_type, price_type, series period => value]
    'movement-kasnazan-apartment-sale' => ['mv-kasnazan', 'apartment', 'sale_asking', [
        '2026-02' => '100000', '2026-03' => '104000', '2026-06' => '110000', '2026-07' => '121000',
    ]],
    'movement-baharka-apartment-sale' => ['mv-baharka', 'apartment', 'sale_asking', [
        '2026-02' => '190000', '2026-03' => '195000', '2026-06' => '200000', '2026-07' => '184000',
    ]],
    'movement-kasnazan-office-sale' => ['mv-kasnazan', 'office', 'sale_asking', [
        '2026-06' => '300000', '2026-07' => '306000',
    ]],
    'movement-kasnazan-apartment-rent' => ['mv-kasnazan', 'apartment', 'rent_asking', [
        '2026-06' => '700', '2026-07' => '665',
    ]],
];

foreach ($movementIndices as $key => [$slug, $propertyType, $priceType, $series]) {
    DB::table('market_indices')->updateOrInsert(
        ['key' => $key],
        [
            'name_ckb' => 'پێوەری '.$movementAreas[$slug][0],
            'name_ar' => 'مؤشر '.$movementAreas[$slug][1],
            'name_en' => $movementAreas[$slug][2].' index',
            'scope_type' => 'area',
            'scope_id' => $movementAreaIds[$slug],
            'property_type' => $propertyType,
            'price_type' => $priceType,
            'basis' => 'median',
            'currency' => 'USD',
            'methodology_version' => 'v1',
            'minimum_sample' => 3,
            'publication_status' => 'published',
            'created_at' => now(),
            'updated_at' => now(),
        ],
    );

    $indexId = (int) DB::table('market_indices')->where('key', $key)->value('id');

    foreach ($series as $period => $value) {
        DB::table('market_index_values')->updateOrInsert(
            ['market_index_id' => $indexId, 'period' => $period, 'revision_number' => 0],
            [
                'effective_date' => $period.'-01',
                'value' => $value,
                'sample_size' => 12,
                'excluded_outliers' => 0,
                'confidence' => 'moderate',
                'is_limited' => false,
                'warning' => null,
                'methodology_version' => 'v1',
                'revision_status' => 'initial',
                'publication_status' => 'published',
                'published_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }
}

/*
 * Wave 5: the profile + portfolio scenario. A dedicated Telegram-linked
 * member owning four properties whose REAL persisted rows exercise every
 * honesty rule the dashboard makes: a two-point USD valuation history (the
 * only chartable series), a second USD figure so the same-currency total is
 * a real sum, an IQD figure that must stay in its own group — never added
 * to the USD one — and a property with no valuation at all, which must read
 * as awaiting rather than as zero. Labels are owner-typed synthetic strings
 * (encrypted at rest like any label); the entity chip resolves through the
 * published mv-kasnazan area the Wave 4 block already seeds.
 */
$portfolioUser = User::query()->updateOrCreate(
    ['email' => 'portfolio@browser-test.invalid'],
    ['name' => 'Browser Portfolio', 'password' => Hash::make($password), 'preferred_locale' => 'ckb', 'is_active' => true],
);
$portfolioUser->roles()->sync([]);
$portfolioUser->phone_verified = 1;
$portfolioUser->telegram_id = '990100';
$portfolioUser->telegram_verified_at = now();
$portfolioUser->save();

// Idempotent: this user's portfolio is rebuilt from nothing on every run,
// so a re-seed never doubles the totals the spec asserts.
foreach (PortfolioProperty::query()->where('user_id', $portfolioUser->id)->get() as $stale) {
    $stale->valuations()->delete();
    $stale->forceDelete();
}

$portfolioSeed = [
    // key => [label, currency, type, area id, [[midpoint, calculated_at], …]]
    'home' => ['W5 Kasnazan home', 'USD', 'apartment', $movementAreaIds['mv-kasnazan'], [
        ['150000.0000', '2026-05-10 09:00:00'],
        ['165000.0000', '2026-07-20 09:00:00'],
    ]],
    'flat' => ['W5 City flat', 'USD', 'apartment', null, [
        ['85000.0000', '2026-07-01 09:00:00'],
    ]],
    'land' => ['W5 Baharka land', 'IQD', 'land', $movementAreaIds['mv-baharka'], [
        ['320000000.0000', '2026-06-15 09:00:00'],
    ]],
    'awaiting' => ['W5 Awaiting office', 'USD', 'commercial', null, []],
];

$portfolioPropertyIds = [];

foreach ($portfolioSeed as $key => [$label, $currency, $type, $areaId, $valuations]) {
    $property = new PortfolioProperty;
    $property->fill([
        'user_id' => $portfolioUser->id,
        'property_type' => $type,
        'area_id' => $areaId,
        'currency' => $currency,
        'location_precision' => PortfolioProperty::PRECISION_AREA_ONLY,
        'consent_valuation' => true,
    ]);
    $property->setLabel($label);
    $property->setNotes(null);
    $property->save();

    $portfolioPropertyIds[$key] = $property->id;

    foreach ($valuations as [$midpoint, $calculatedAt]) {
        PortfolioValuation::query()->create([
            'portfolio_property_id' => $property->id,
            'midpoint' => $midpoint,
            'low' => $midpoint,
            'high' => $midpoint,
            'currency' => $currency,
            'confidence' => 'moderate',
            'match_level' => 2,
            'match_label' => 'area',
            'methodology' => 'median_comparables_v1',
            'comparison_count' => 8,
            'excluded_asking_count' => 3,
            'excluded_asking_note' => 'Asking prices were excluded from this estimate.',
            'no_valuation_reason' => null,
            'calculated_at' => $calculatedAt,
        ]);
    }
}

/*
 * Wave 6: the valuation-rules scenario, fully isolated from the Wave 5
 * member so neither spec's persisted counts can drift into the other's
 * assertions. A dedicated Telegram-linked member owns ONE apartment in a
 * dedicated two-level lineage (district under city — the only geometry the
 * engine's wider-area tier can reach while aggregate evidence carries no
 * unit size), three published USD sale records make the median a REAL
 * 110000, and one ACTIVE rule set — published through the real publisher —
 * asks one trilingual question whose signed percentages live server-side
 * only.
 */
DB::table('feature_flags')->updateOrInsert(
    ['flag' => 'portfolio.valuation_rules'],
    ['enabled' => true, 'updated_at' => now(), 'created_at' => now()],
);

$valuationUser = User::query()->updateOrCreate(
    ['email' => 'valuation@browser-test.invalid'],
    ['name' => 'Browser Valuation', 'password' => Hash::make($password), 'preferred_locale' => 'ckb', 'is_active' => true],
);
$valuationUser->roles()->sync([]);
$valuationUser->phone_verified = 1;
$valuationUser->telegram_id = '990200';
$valuationUser->telegram_verified_at = now();
$valuationUser->save();

$w6City = Area::query()->updateOrCreate(
    ['slug' => 'w6-erbil'],
    [
        'type' => 'city',
        'name_ckb' => 'هەولێری ڕێسا',
        'name_ar' => 'أربيل القواعد',
        'name_en' => 'Rules Erbil',
        'publication_status' => 'published',
    ],
);

$w6District = Area::query()->updateOrCreate(
    ['slug' => 'w6-rules'],
    [
        'type' => 'district',
        'parent_id' => $w6City->id,
        'name_ckb' => 'گەڕەکی ڕێسا',
        'name_ar' => 'حي القواعد',
        'name_en' => 'Rules district',
        'publication_status' => 'published',
    ],
);

// Idempotent evidence: three keyed records, rewritten in place on re-seed.
foreach ([['w6-rules-1', '100000.0000'], ['w6-rules-2', '110000.0000'], ['w6-rules-3', '120000.0000']] as [$recordId, $price]) {
    PriceRecord::query()->updateOrCreate(
        ['record_id' => $recordId],
        [
            'scope_type' => ScopeType::Area,
            'scope_id' => $w6District->id,
            'property_type' => 'apartment',
            'transaction_type' => 'sale',
            'price_type' => PriceType::SaleVerified,
            'currency' => 'USD',
            'price' => $price,
            'effective_date' => '2026-06-01',
            'period' => '2026-06',
            'publication_status' => 'published',
        ],
    );
}

// Rebuild the member's portfolio and the rule set from nothing on every
// run: the spec's history grows as each locale requests a valuation, so a
// re-seed must reset it, and rule rows are raw-deleted (FKs cascade the
// children) because the model layer rightly refuses to edit active sets.
foreach (PortfolioProperty::query()->where('user_id', $valuationUser->id)->get() as $stale) {
    $stale->valuations()->delete();
    $stale->forceDelete();
}

DB::table('valuation_rule_sets')->where('name', 'W6 browser rules')->delete();

$w6Property = new PortfolioProperty;
$w6Property->fill([
    'user_id' => $valuationUser->id,
    'property_type' => 'apartment',
    'area_id' => $w6District->id,
    'currency' => 'USD',
    'location_precision' => PortfolioProperty::PRECISION_AREA_ONLY,
    'consent_valuation' => true,
]);
$w6Property->setLabel('W6 Rules flat');
$w6Property->setNotes(null);
$w6Property->save();

$w6Set = ValuationRuleSet::query()->create([
    'name' => 'W6 browser rules',
    'scope_transaction' => ValuationRuleSet::SCOPE_TRANSACTION_SALE,
    'property_types' => ['apartment'],
    'version' => app(ValuationRulePublisher::class)
        ->nextVersion(ValuationRuleSet::SCOPE_TRANSACTION_SALE, null),
    'status' => ValuationRuleSet::STATUS_DRAFT,
]);

$w6Question = ValuationQuestion::query()->create([
    'valuation_rule_set_id' => $w6Set->id,
    'key' => 'renovation_state',
    'label_ckb' => 'دۆخی نۆژەنکردنەوە',
    'label_ar' => 'حالة التجديد',
    'label_en' => 'Renovation state',
    'sort_order' => 0,
    'is_active' => true,
]);

ValuationQuestionOption::query()->create([
    'valuation_question_id' => $w6Question->id,
    'key' => 'renovated',
    'label_ckb' => 'بەم دواییە نۆژەنکراوەتەوە',
    'label_ar' => 'مجدد حديثاً',
    'label_en' => 'Recently renovated',
    'adjustment_percent' => '5.000',
    'sort_order' => 0,
    'is_active' => true,
]);

ValuationQuestionOption::query()->create([
    'valuation_question_id' => $w6Question->id,
    'key' => 'original',
    'label_ckb' => 'دۆخی بنەڕەتی',
    'label_ar' => 'بحالته الأصلية',
    'label_en' => 'Original condition',
    'adjustment_percent' => '-3.500',
    'sort_order' => 1,
    'is_active' => true,
]);

// Published through the REAL lifecycle, so the browser exercises exactly
// what an operator would have produced.
app(ValuationRulePublisher::class)->publish($w6Set);

cache()->flush();

file_put_contents(
    __DIR__.'/fixtures.json',
    json_encode([
        'password' => $password,
        'admin' => ['email' => $admin->email, 'secret' => $adminSecret],
        'plain' => ['email' => $plain->email],
        'mfa' => ['email' => $mfa->email, 'secret' => $secret],
        'portfolio' => ['email' => $portfolioUser->email, 'property_ids' => $portfolioPropertyIds],
        'valuation' => ['email' => $valuationUser->email, 'property_id' => $w6Property->id],
        'wizard_draft_id' => $wizardDraftId,
        'flags' => ['advisor.residential' => true, 'map.investment' => true, 'map.explorer' => true, 'projects.wizard' => true, 'market.indices' => true, 'portfolio' => true, 'market.intelligence' => true, 'portfolio.valuation_rules' => true],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n",
);

echo "wrote tests/Browser/support/fixtures.json\n";
echo "  admin: {$admin->email}\n  plain: {$plain->email}\n  mfa:   {$mfa->email}\n";
echo "  advisor.residential = ON\n";
echo "  map.investment = ON, map.explorer = ON\n";
echo "  4 published projects: trends up / down / flat / unknown\n";
echo "  market.indices = ON, portfolio = ON\n";
echo "  portfolio member: {$portfolioUser->email} — 4 properties (USD ×2 valued, IQD ×1, 1 awaiting)\n";
echo "  valuation member: {$valuationUser->email} — 1 apartment, 3 USD sale records, active rule set (portfolio.valuation_rules = ON)\n";
echo "  browser-ankawa: boundary polygon + published sale index (Wave 3 location card)\n";
