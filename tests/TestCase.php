<?php

declare(strict_types=1);

namespace Tests;

use App\Modules\Core\Contracts\FeatureFlagRepository;
use App\Modules\Core\Support\FeatureFlagResolver;
use App\Modules\Identity\Http\Middleware\EnsureMfaConfirmed;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Session\Session;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Every test starts with an EMPTY cleanup journal.
     *
     * The journal is a real file under `storage/app`, so its artifacts —
     * the active line file, a rotated `.claimed.jsonl`, its lease, the
     * lock and the dead-letter file — outlive the test that produced
     * them. A later test then replays another test's leftovers: the
     * replay command adopts a rotated file by design, reports the
     * unreplayable lines as failed or quarantined, and exits non-zero.
     * That is the command behaving correctly on state no production
     * install would ever have.
     *
     * Isolation belongs here rather than in each test, because any test
     * that touches cleanup can leave a claim behind — including one that
     * fails midway, which is precisely when it will not clean up after
     * itself.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->clearCleanupJournalArtifacts();
    }

    protected function tearDown(): void
    {
        $this->clearCleanupJournalArtifacts();

        parent::tearDown();
    }

    private function clearCleanupJournalArtifacts(): void
    {
        $directory = storage_path('app');

        if (! is_dir($directory)) {
            return;
        }

        foreach (glob($directory.'/cleanup-journal*') ?: [] as $artifact) {
            if (is_file($artifact)) {
                @unlink($artifact);
            }
        }
    }

    /**
     * PUBLIC, matching the framework contract.
     *
     * `protected` here was a guaranteed fatal — PHP refuses to reduce the
     * visibility of an inherited public method, so the suite could not even be
     * loaded, let alone run. Nothing caught it because the suite had never
     * executed.
     */
    public function createApplication(): Application
    {
        $app = require __DIR__.'/../bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    /**
     * Sign in as a user whose MFA challenge is already satisfied for THIS session.
     *
     * `EnsureMfaConfirmed` passes only when the session holds its own id under
     * `SESSION_KEY`. Writing that key before a request is not enough: the
     * StartSession middleware re-seeds the store's id from the request cookie,
     * so a value written against a different id is discarded and the request is
     * bounced to /admin/mfa/challenge. Every admin test was therefore asserting
     * against a redirect it had caused itself.
     *
     * THE GUARD IS NOT TOUCHED. It still runs on every request and still
     * compares the session id. What changes is that the test now behaves like a
     * real browser: one session, started once, its id written into the
     * confirmation key, and its cookie carried into the protected request so the
     * middleware resolves the SAME session instance.
     *
     * Negative paths stay covered by `UserFactory::withoutMfa()` and by
     * `MfaGuardTest`, which drives setup, challenge, rotation and confirmation
     * directly.
     *
     * @param  Authenticatable  $user
     * @return $this
     */
    public function actingAs($user, $guard = null): static
    {
        parent::actingAs($user, $guard);

        return $this->withConfirmedMfaSession();
    }

    /**
     * Put the active test session into the "challenge completed" state.
     *
     * The id is read from the session instance itself AFTER it has started, and
     * the matching cookie is attached so the next request resolves that exact
     * session rather than a fresh one.
     */
    /** @return $this */
    protected function withConfirmedMfaSession(): static
    {
        /** @var Session $session */
        $session = $this->app['session']->driver();

        if (! $session->isStarted()) {
            $session->start();
        }

        $id = $session->getId();

        $session->put(EnsureMfaConfirmed::SESSION_KEY, $id);
        $session->save();

        /*
         * THE COOKIE IS WHAT TIES THE TWO REQUESTS TOGETHER.
         *
         * `StartSession` re-seeds the store's id from the incoming session
         * cookie on every request. With no cookie it mints a fresh id, so the
         * confirmation written against the previous id no longer matched and
         * the guard bounced the request to /admin/mfa/challenge — correctly.
         * The data appeared to survive only because the array store keeps its
         * attributes in memory, which made an identity problem look like a
         * value problem.
         *
         * `withCookie()` is the right call: Laravel's own
         * `prepareCookiesForRequest()` applies `CookieValuePrefix` and
         * encryption to default cookies, so passing the raw id here produces
         * exactly what `EncryptCookies` expects. Encrypting it by hand double
         * encrypts it and the cookie is discarded.
         */
        /*
         * CREDENTIALS ON, or JSON requests arrive anonymous.
         *
         * Laravel only attaches cookies to `getJson()`/`postJson()` when
         * `withCredentials()` has been set — `prepareCookiesForJsonRequest()`
         * returns an empty array otherwise. So the session cookie reached HTML
         * requests and not JSON ones, every XHR endpoint was bounced to
         * /admin/mfa/challenge, and the tests judged a redirect instead of the
         * JSON they asked for. A real browser sends the session cookie on
         * same-origin fetches, so this matches production rather than relaxing
         * anything.
         */
        $this->withCredentials();

        return $this->withCookie($session->getName(), $id);
    }

    /**
     * Turn feature flags on or off for one test.
     *
     * `config(['features.defaults.map.explorer' => true])` DOES NOT WORK, and
     * failed silently for the entire life of this suite. `config/features.php`
     * stores flags under LITERAL keys that contain dots — `'map.explorer'` is
     * one array key, not two levels. Laravel's config setter splits on dots, so
     * that call created a *separate* nested `map => ['explorer' => true]` entry
     * and left the real `'map.explorer'` key sitting at its default. Every
     * flag-gated route stayed shut and dozens of tests asserted against a 404
     * they had themselves caused.
     *
     * Writing the defaults array back wholesale keeps the literal key intact.
     * The resolver is a singleton that snapshots the defaults when it is first
     * built, so it is discarded here and rebuilt on next use.
     *
     * @param  array<string, bool>  $flags  flag name => enabled
     */
    protected function setFeatures(array $flags): void
    {
        $defaults = (array) config('features.defaults', []);

        foreach ($flags as $flag => $enabled) {
            $defaults[$flag] = $enabled;
        }

        config(['features.defaults' => $defaults]);

        $this->app->forgetInstance(FeatureFlagResolver::class);
        $this->app->forgetInstance(FeatureFlagRepository::class);
    }
}
