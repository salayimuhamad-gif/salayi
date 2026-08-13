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

use App\Modules\Identity\Enums\RoleKey;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\Totp;
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

cache()->flush();

file_put_contents(
    __DIR__.'/fixtures.json',
    json_encode([
        'password' => $password,
        'admin' => ['email' => $admin->email, 'secret' => $adminSecret],
        'plain' => ['email' => $plain->email],
        'mfa' => ['email' => $mfa->email, 'secret' => $secret],
        'wizard_draft_id' => $wizardDraftId,
        'flags' => ['advisor.residential' => true, 'map.investment' => true, 'map.explorer' => true, 'projects.wizard' => true],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n",
);

echo "wrote tests/Browser/support/fixtures.json\n";
echo "  admin: {$admin->email}\n  plain: {$plain->email}\n  mfa:   {$mfa->email}\n";
echo "  advisor.residential = ON\n";
echo "  map.investment = ON, map.explorer = ON\n";
echo "  4 published projects: trends up / down / flat / unknown\n";
