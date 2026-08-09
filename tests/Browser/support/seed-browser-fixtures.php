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
 *   this product's UI is built around. The two investment-map projects (one
 *   with a real price history) are the deliberate exception: the map suite
 *   must prove markers, prices and trends render from PERSISTED rows, and an
 *   empty map cannot prove that.
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

DB::table('projects')->updateOrInsert(
    ['slug' => 'browser-invest-tower'],
    [
        'name_ckb' => 'بورجی وەبەرهێنانی تاقیکردنەوە',
        'project_type' => 'tower',
        'construction_status' => 'under_construction',
        'delivery_status' => 'not_started',
        'publication_status' => 'published',
        'latitude' => 36.1950000,
        'longitude' => 44.0150000,
        'created_at' => now(),
        'updated_at' => now(),
    ],
);
$tower = DB::table('projects')->where('slug', 'browser-invest-tower')->first();

DB::table('projects')->updateOrInsert(
    ['slug' => 'browser-invest-villa'],
    [
        'name_ckb' => 'ڤیلاکانی تاقیکردنەوە',
        'project_type' => 'villa',
        'construction_status' => 'under_construction',
        'delivery_status' => 'not_started',
        'publication_status' => 'published',
        'latitude' => 36.2050000,
        'longitude' => 44.0250000,
        'created_at' => now(),
        'updated_at' => now(),
    ],
);

foreach ([['100000.00', '2026-06-01'], ['104800.00', '2026-07-01']] as [$price, $date]) {
    DB::table('project_prices')->updateOrInsert(
        ['project_id' => $tower->id, 'effective_date' => $date],
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

cache()->flush();

file_put_contents(
    __DIR__.'/fixtures.json',
    json_encode([
        'password' => $password,
        'admin' => ['email' => $admin->email, 'secret' => $adminSecret],
        'plain' => ['email' => $plain->email],
        'mfa' => ['email' => $mfa->email, 'secret' => $secret],
        'flags' => ['advisor.residential' => true, 'map.investment' => true],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n",
);

echo "wrote tests/Browser/support/fixtures.json\n";
echo "  admin: {$admin->email}\n  plain: {$plain->email}\n  mfa:   {$mfa->email}\n";
echo "  advisor.residential = ON\n";
echo "  map.investment = ON (+2 published projects, 1 with price history)\n";
