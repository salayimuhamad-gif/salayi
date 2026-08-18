<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\TelegramReturnHandoff;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Two simultaneous redemptions of ONE return handoff token.
 *
 * A sequential replay test cannot show this. The first implementation consumed
 * the token with `Cache::pull()` and claimed that was atomic; on the database
 * cache store it is a read followed by a delete, so two requests arriving
 * together could both read a live token and both authenticate. Since the token
 * authenticates a cold browser, "both win" means an extra session for whoever
 * else holds the link.
 *
 * The redemption now happens inside a transaction under `SELECT ... FOR
 * UPDATE`, so this test runs a competing redemption in a SEPARATE PROCESS with
 * its own connection — the only way to prove a row lock does what it claims.
 *
 * MySQL/MariaDB only: SQLite has no meaningful row-level locking, so asserting
 * this against SQLite would prove nothing. The test refuses the wrong driver
 * rather than passing vacuously.
 */
final class TelegramReturnHandoffConcurrencyTest extends TestCase
{
    /*
     * DELIBERATELY WITHOUT RefreshDatabase, following the pattern already
     * established by SweepCasConcurrencyTest. That trait wraps each test in a
     * transaction that never commits, so a second PROCESS sees none of the
     * rows — and this test exists precisely to let a second process see and
     * contend for one. The cost is manual cleanup, which tearDown() does.
     */
    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Row-lock concurrency is only meaningful on MySQL/MariaDB.');
        }

        // Committed schema: the child process needs DDL it can actually see.
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();

        $this->assertSame(0, DB::transactionLevel(), 'Schema setup must leave no open transaction.');

        config([
            'mulkihawler.security.blind_index_key' => str_repeat('a', 64),
            'mulkihawler.security.pii_key' => str_repeat('b', 64),
        ]);
    }

    protected function tearDown(): void
    {
        // Committed rows are real, so they go by hand, children first.
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::table('telegram_return_handoffs')->delete();
            DB::table('consents')->delete();
            DB::table('users')->delete();
        }

        parent::tearDown();
    }

    public function test_only_one_of_two_simultaneous_redemptions_can_succeed(): void
    {
        $user = User::factory()->create([
            'telegram_id' => '515151',
            'telegram_verified_at' => now(),
            'is_active' => true,
        ]);

        $raw = app(TelegramReturnHandoff::class)->mint($user, '515151', 'ckb');

        $this->assertDatabaseHas('telegram_return_handoffs', [
            'user_id' => $user->id,
            'consumed_at' => null,
        ]);

        // The raw token must never be what is stored.
        $this->assertDatabaseMissing('telegram_return_handoffs', ['token_hash' => $raw]);

        $dir = sys_get_temp_dir().'/handoff-'.bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);

        $worker = $dir.'/worker.php';
        file_put_contents($worker, <<<'PHP'
        <?php
        // A competing redemption with its OWN process, OWN connection and OWN
        // transaction. Writes "ok" or "refused" so the parent can count.
        [$script, $base, $raw, $go, $out] = $argv;

        require $base.'/vendor/autoload.php';
        $app = require $base.'/bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

        $deadline = microtime(true) + 30;

        while (! is_file($go) && microtime(true) < $deadline) {
            usleep(200);
        }

        $result = app(App\Modules\Identity\Services\TelegramReturnHandoff::class)->redeem($raw);

        // Written then renamed: the parent polls for this file, and a
        // half-written file read at the wrong instant looked like an empty
        // result rather than a verdict.
        file_put_contents($out.'.tmp', $result === null ? 'refused' : 'ok');
        rename($out.'.tmp', $out);

        exit(0);
        PHP);

        $go = $dir.'/go';
        $childOut = $dir.'/child';

        $process = proc_open(
            ['php', $worker, base_path(), $raw, $go, $childOut],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        $this->assertIsResource($process, 'Could not start the competing process.');

        // Release both at once, as close to simultaneous as this can be made.
        touch($go);

        $mine = app(TelegramReturnHandoff::class)->redeem($raw) === null ? 'refused' : 'ok';

        $deadline = microtime(true) + 60;

        while ((! is_file($childOut) || trim((string) @file_get_contents($childOut)) === '') && microtime(true) < $deadline) {
            usleep(500);
        }

        $this->assertFileExists($childOut, 'The competing process never reported a result.');

        $theirs = trim((string) file_get_contents($childOut));

        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        proc_close($process);

        $results = [$mine, $theirs];
        sort($results);

        $this->assertSame(
            ['ok', 'refused'],
            $results,
            "exactly one redemption must succeed; got mine={$mine} theirs={$theirs}"
        );

        // And the row records exactly one consumption.
        $this->assertSame(
            1,
            DB::table('telegram_return_handoffs')->whereNotNull('consumed_at')->count()
        );

        array_map('unlink', glob($dir.'/*') ?: []);
        rmdir($dir);
    }

    public function test_a_consumed_token_cannot_be_revived(): void
    {
        $user = User::factory()->create([
            'telegram_id' => '525252',
            'telegram_verified_at' => now(),
            'is_active' => true,
        ]);

        $service = app(TelegramReturnHandoff::class);
        $raw = $service->mint($user, '525252', 'ar');

        $this->assertNotNull($service->redeem($raw));
        $this->assertNull($service->redeem($raw), 'a spent token was accepted again');

        // Clearing consumed_at by hand must not resurrect it either: the row
        // is gone from the caller's reach because expiry is checked under the
        // same lock, and a revived row is a new fact, not the old token.
        DB::table('telegram_return_handoffs')->update([
            'consumed_at' => null,
            'expires_at' => now()->subMinute(),
        ]);

        $this->assertNull($service->redeem($raw), 'an expired token was accepted');
    }
}
