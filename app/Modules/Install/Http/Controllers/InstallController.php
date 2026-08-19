<?php

declare(strict_types=1);

namespace App\Modules\Install\Http\Controllers;

use App\Modules\Install\Services\InstallConfigurator;
use App\Modules\Install\Services\InstallRunner;
use App\Modules\Install\Services\InstallState;
use App\Modules\Install\Services\RequirementChecker;
use App\Modules\Install\Services\StepValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Guided web installer (spec 33).
 *
 * COMPLETE as of Step 8. The mutating steps — migrate, seed, storage link,
 * cache, health check, complete and lock — returned HTTP 501 from Step 1
 * through Step 7 and are now implemented in InstallRunner, together with the
 * backup and rollback that make them safe. That sequencing was deliberate: a
 * migrate step without a tested rollback is worse than no migrate step at all,
 * because the operator only discovers the problem when the site is already
 * down and there is no way back.
 *
 * See docs/ROADMAP_STATUS.md for the per-step status.
 */
final class InstallController extends Controller
{
    public function __construct(
        private readonly InstallState $state,
        private readonly RequirementChecker $requirements,
        private readonly InstallRunner $runner,
        private readonly StepValidator $validator,
        private readonly InstallConfigurator $configurator,
    ) {}

    public function index(): RedirectResponse
    {
        return redirect()->route('install.step', ['step' => $this->state->currentStep()]);
    }

    public function show(string $step): Response
    {
        abort_unless(in_array($step, $this->state->stepKeys(), true), 404);

        return Inertia::render('Install/Wizard', [
            'step' => $step,
            'mode' => $this->state->mode(),
            'steps' => $this->state->stepKeys(),
            'completed' => $this->state->completedSteps(),
            'progress' => $this->state->progress(),
            'answers' => $this->state->answers($step),
            'payload' => $this->payloadFor($step),
            /*
             * Flattened to [{code, name}] for the wizard. config() holds a
             * keyed map with direction, script and numeral metadata the
             * installer does not need, and shipping the raw shape meant the
             * language selectors rendered empty.
             */
            'locales' => collect((array) config('localization.supported'))
                ->map(static fn (array $meta, string $code): array => [
                    'code' => $code,
                    'name' => (string) ($meta['native'] ?? $meta['name'] ?? $code),
                ])
                ->values()
                ->all(),
        ]);
    }

    public function store(Request $request, string $step): RedirectResponse
    {
        abort_unless(in_array($step, $this->state->stepKeys(), true), 404);

        // The mutating steps run through InstallRunner, which owns backup,
        // rollback and the health gate. A failure stops the wizard on the
        // current step with the reason rather than advancing past it.
        $mutating = [
            'migrate' => fn (): array => $this->runner->migrate(),
            'seed' => fn (): array => $this->runner->seed(),
            'storage_link' => fn (): array => $this->runner->storageLink(),
            'assets' => fn (): array => $this->runner->assets(),
            'cache' => fn (): array => $this->runner->cache(),
            'health_check' => fn (): array => $this->runner->healthCheck(),
            'complete' => fn (): array => $this->runner->complete(),
            // Repair prompt §2.10: its own method. Mapping this to complete()
            // made the lock step unreachable — complete() locked, deleted the
            // state file and 404'd the route before the operator arrived here.
            'lock' => fn (): array => $this->runner->lock(),
        ];

        if (isset($mutating[$step])) {
            $result = $mutating[$step]();

            if (! $result['ok']) {
                return redirect()
                    ->route('install.step', ['step' => $step])
                    ->with('error', __('install.status.step_failed'))
                    ->with('install_failure', $result['detail'])
                    ->with('rolled_back', $result['rolled_back']);
            }

            $keys = $this->state->stepKeys();
            $position = array_search($step, $keys, true);
            $next = $position !== false && isset($keys[$position + 1]) ? $keys[$position + 1] : $step;

            return redirect()->route('install.step', ['step' => $next]);
        }

        /*
         * Repair prompt §2.3: validated, not $request->all(). Unchecked input
         * used to be persisted straight to the state file, so a mistyped port
         * or an empty database name failed several steps later at `migrate`,
         * by which point nothing points at the screen that was wrong.
         */
        $validated = $this->validator->rules($step) === []
            ? []
            : $request->validate($this->validator->rules($step));

        /*
         * The password never reaches the state file (§2.5). It is held in the
         * session for the single hop to `seed`, where the roles table finally
         * exists and the account can be created (§2.7).
         */
        if ($step === 'super_admin' && isset($validated['admin_password'])) {
            $this->state->rememberAdminPassword((string) $validated['admin_password']);
        }

        // Writes .env, applies runtime config, then stores the answers with
        // every secret stripped (§2.4, §2.5, §2.6).
        $this->configurator->commit($step, $validated);

        $keys = $this->state->stepKeys();
        $position = array_search($step, $keys, true);
        $next = $position !== false && isset($keys[$position + 1]) ? $keys[$position + 1] : $step;

        return redirect()->route('install.step', ['step' => $next]);
    }

    /**
     * Connection test (spec 33.2 "connection testing", "clear error messages").
     *
     * Returns a sanitised reason. A raw PDO exception on an installer page
     * leaks the host, port and username to anyone who can reach the URL.
     */
    public function testDatabase(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'db_host' => ['required', 'string', 'max:255'],
            'db_port' => ['required', 'integer', 'between:1,65535'],
            'db_database' => ['required', 'string', 'max:64'],
            'db_username' => ['required', 'string', 'max:64'],
            'db_password' => ['nullable', 'string', 'max:255'],
        ]);

        config([
            'database.connections.installer' => [
                'driver' => 'mysql',
                'host' => $validated['db_host'],
                'port' => $validated['db_port'],
                'database' => $validated['db_database'],
                'username' => $validated['db_username'],
                'password' => $validated['db_password'] ?? '',
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
            ],
        ]);

        try {
            DB::connection('installer')->getPdo();
            $version = (string) DB::connection('installer')->selectOne('select version() as v')?->v;

            $this->state->log('database connection test succeeded');

            return response()->json([
                'ok' => true,
                'server_version' => $version,
                'utf8mb4' => true,
            ]);
        } catch (Throwable $e) {
            $this->state->log('database connection test failed');

            return response()->json([
                'ok' => false,
                'reason' => $this->explain($e),
            ], 422);
        } finally {
            DB::purge('installer');
        }
    }

    /**
     * Mail connection test (spec 33.2 "connection testing").
     *
     * Sends nothing. It validates the transport can be constructed and the host
     * resolves, which is what an operator needs at install time; actually
     * delivering a test message needs a recipient nobody has agreed to receive
     * mail at yet.
     */
    public function testMail(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'mail_host' => ['required', 'string', 'max:255'],
            'mail_port' => ['required', 'integer', 'between:1,65535'],
            'mail_username' => ['nullable', 'string', 'max:255'],
            'mail_scheme' => ['nullable', 'string', 'in:tls,ssl,smtp'],
        ]);

        $errno = 0;
        $errstr = '';
        $connection = @fsockopen($validated['mail_host'], (int) $validated['mail_port'], $errno, $errstr, 8);

        if ($connection === false) {
            $this->state->log('mail connection test failed');

            return response()->json([
                'ok' => false,
                'reason' => __('install.mail.unreachable'),
            ], 422);
        }

        fclose($connection);
        $this->state->log('mail connection test succeeded');

        return response()->json(['ok' => true, 'reason' => null]);
    }

    /**
     * Telegram bot configuration check (repair prompt §2.12).
     *
     * Calls getMe, which is the cheapest call that proves the token is real and
     * has no side effects — it does not register a webhook or send anything.
     *
     * The token is NEVER echoed back, not in the success payload and not in the
     * error. An installer page is unauthenticated by necessity, so a response
     * that reflected the token would hand it to anyone who could reach the URL.
     * Only the bot's public username is returned, which is what the operator
     * needs to confirm they configured the right bot.
     */
    public function testTelegram(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'telegram_bot_token' => ['required', 'string', 'max:255'],
        ]);

        try {
            $response = Http::timeout(8)
                ->get('https://api.telegram.org/bot'.$validated['telegram_bot_token'].'/getMe');

            if (! $response->successful() || $response->json('ok') !== true) {
                $this->state->log('telegram check failed');

                return response()->json([
                    'ok' => false,
                    'reason' => __('install.telegram.invalid_token'),
                ], 422);
            }

            $this->state->log('telegram check succeeded');

            return response()->json([
                'ok' => true,
                'bot_username' => (string) $response->json('result.username', ''),
            ]);
        } catch (Throwable) {
            // Deliberately not $e->getMessage(): a Guzzle transfer exception
            // includes the full request URL, and the token is in that path.
            $this->state->log('telegram check unreachable');

            return response()->json([
                'ok' => false,
                'reason' => __('install.telegram.unreachable'),
            ], 422);
        }
    }

    /**
     * AI provider configuration check (repair prompt §2.12).
     *
     * Lists models rather than running a completion: it proves the base URL and
     * key are valid without spending tokens or depending on a model name the
     * operator has not chosen yet.
     */
    public function testAi(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ai_base_url' => ['required', 'string', 'max:255', 'url'],
            'ai_api_key' => ['required', 'string', 'max:255'],
        ]);

        try {
            $response = Http::timeout(10)
                ->withToken($validated['ai_api_key'])
                ->get(rtrim($validated['ai_base_url'], '/').'/models');

            if (! $response->successful()) {
                $this->state->log('ai provider check failed', ['status' => $response->status()]);

                return response()->json([
                    'ok' => false,
                    // The status is safe to report and is the one detail that
                    // distinguishes a bad key (401) from a bad URL (404).
                    'reason' => $response->status() === 401
                        ? __('install.ai.unauthorized')
                        : __('install.ai.unreachable'),
                ], 422);
            }

            $this->state->log('ai provider check succeeded');

            return response()->json(['ok' => true, 'reason' => null]);
        } catch (Throwable) {
            $this->state->log('ai provider check unreachable');

            return response()->json([
                'ok' => false,
                'reason' => __('install.ai.unreachable'),
            ], 422);
        }
    }

    /** @return array<string, mixed> */
    private function payloadFor(string $step): array
    {
        return match ($step) {
            'requirements' => $this->requirements->php() + ['advisory' => $this->requirements->advisory()],
            'extensions' => $this->requirements->extensions(),
            'permissions' => $this->requirements->permissions(),
            default => [],
        };
    }

    /** Maps a driver exception to a message an hPanel user can act on. */
    private function explain(Throwable $e): string
    {
        $message = $e->getMessage();

        return match (true) {
            str_contains($message, '1045') => __('install.db.access_denied'),
            str_contains($message, '1049') => __('install.db.unknown_database'),
            str_contains($message, '2002') => __('install.db.host_unreachable'),
            str_contains($message, 'could not find driver') => __('install.db.missing_driver'),
            default => __('install.db.generic_failure'),
        };
    }
}
