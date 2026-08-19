<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Identity\Models\TelegramVerificationToken;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\TelegramReturnHandoff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The production replay regression, reproduced at the HTTP layer.
 *
 * Observed during the v7 deployment walkthrough (DEPLOYMENT_NOTES §14 step 8):
 * the one-time "Return to MyHawler" button was opened once and authenticated a
 * cold browser — correct — and then opening the SAME link again returned the
 * tester to the authenticated account instead of the neutral expired page. The
 * deployment was rolled back on that observation.
 *
 * What the database enforces was never the gap: the handoff row is consumed
 * atomically under a row lock, and a second REDEMPTION is refused
 * (TelegramReturnHandoffConcurrencyTest proves it across two real processes).
 * The gap is in what the HTTP responses permit. The redirect that finishes a
 * successful redemption — the one that carries the freshly authenticated
 * session to onboarding — went out with Symfony's default `no-cache, private`,
 * and `no-cache` still allows the response to be STORED. A browser reopening
 * the same URL may replay the stored 302 from cache or history without the
 * server ever seeing a second request; Telegram's in-app browser is exactly
 * the client that does this. From the outside that is indistinguishable from
 * the spent link authenticating again.
 *
 * So the contract these tests hold has two halves:
 *
 *   1. when the replay DOES reach the server, it must land on the neutral
 *      expired page — never on the account — and must not touch the stored
 *      consumption state; and
 *   2. every response on the return route must carry `no-store`, so no client
 *      or intermediary may keep a copy that can re-enter the account later.
 */
final class TelegramReturnReplayTest extends TestCase
{
    use RefreshDatabase;

    /** Meets the platform's configured password policy. */
    private const PASSWORD = 'Str0ng!Passw0rd#2026';

    protected function setUp(): void
    {
        parent::setUp();

        // One process, one IP: the registration throttle would otherwise count
        // earlier tests' submissions against later ones.
        Cache::flush();

        if ((string) config('mulkihawler.security.blind_index_key', '') === '') {
            config([
                'mulkihawler.security.blind_index_key' => str_repeat('a', 64),
                'mulkihawler.security.pii_key' => str_repeat('b', 64),
            ]);
        }
    }

    public function test_reopening_a_consumed_return_link_lands_on_the_neutral_page_not_the_account(): void
    {
        $handoff = $this->mintHandoffThroughTheRealFlow(717001);
        $userId = (int) User::query()->firstOrFail()->id;

        // Telegram's in-app browser: no session, no cookies, nothing.
        Auth::logout();
        $this->flushSession();

        $first = $this->get('/account/return/'.$handoff);

        $first->assertRedirect();
        $this->assertStringEndsWith('/account/onboarding', (string) $first->headers->get('Location'),
            'the first open did not land on onboarding');
        $this->assertSame($userId, Auth::id(), 'the first open did not authenticate the cold browser');

        $consumedAt = DB::table('telegram_return_handoffs')->value('consumed_at');
        $this->assertNotNull($consumedAt, 'the first open did not consume the handoff');

        /*
         * The SAME browser, still holding the session the link just
         * established — the deployment walkthrough's step 8, exactly. No
         * logout and no session flush here, deliberately: the production
         * replay happened in the browser that had just been signed in.
         */
        $replay = $this->get('/account/return/'.$handoff);

        $replay->assertRedirect();

        $location = (string) $replay->headers->get('Location');

        $this->assertStringContainsString('return-expired', $location,
            'a consumed return link replayed into the account instead of the neutral page');
        $this->assertStringNotContainsString('/account/onboarding', $location,
            'a consumed return link completed the flow a second time');

        // The stored state is untouched: still exactly one consumption, at the
        // original instant.
        $this->assertSame(1, DB::table('telegram_return_handoffs')->whereNotNull('consumed_at')->count());
        $this->assertEquals($consumedAt, DB::table('telegram_return_handoffs')->value('consumed_at'),
            'the replay altered the consumption record');
    }

    public function test_a_cold_replay_after_the_session_ends_is_refused_and_authenticates_nobody(): void
    {
        $handoff = $this->mintHandoffThroughTheRealFlow(717002);

        Auth::logout();
        $this->flushSession();
        $this->get('/account/return/'.$handoff)->assertRedirect();

        // The person signs out; the link leaks or is reopened much later in a
        // browser with nothing left in it.
        Auth::logout();
        $this->flushSession();

        $replay = $this->get('/account/return/'.$handoff);

        $replay->assertRedirect();
        $this->assertStringContainsString('return-expired', (string) $replay->headers->get('Location'));
        $this->assertFalse(Auth::check(), 'a spent return link authenticated a cold browser a second time');
    }

    /**
     * THE REGRESSION. The successful redemption's redirect — the response that
     * carries a freshly authenticated session — must never be storable. With
     * only the framework default (`no-cache, private`), a browser may keep the
     * 302 in cache or history and replay it when the same button is tapped
     * again, re-entering the account without the server seeing a request.
     * `no-store` is the directive that forbids keeping a copy at all.
     */
    public function test_the_successful_redemption_response_is_never_storable(): void
    {
        $handoff = $this->mintHandoffThroughTheRealFlow(717003);

        Auth::logout();
        $this->flushSession();

        $success = $this->get('/account/return/'.$handoff);

        $success->assertRedirect();
        $this->assertStringContainsString(
            'no-store',
            (string) $success->headers->get('Cache-Control'),
            'the authenticated redirect is storable: a client can replay it from cache or history, '
                .'which is the production observation — the spent link "returned the user to the '
                .'authenticated account instead of showing an expired/neutral page"'
        );
    }

    /** The refusal and the neutral page must be equally unstorable — a cached
     * refusal would pin a stale verdict onto a live link, and a cached neutral
     * page would do the reverse. */
    public function test_the_refusal_and_the_neutral_page_are_never_storable(): void
    {
        $handoff = $this->mintHandoffThroughTheRealFlow(717004);

        Auth::logout();
        $this->flushSession();
        $this->get('/account/return/'.$handoff)->assertRedirect();

        $replay = $this->get('/account/return/'.$handoff);

        $replay->assertRedirect();
        $this->assertStringContainsString('no-store', (string) $replay->headers->get('Cache-Control'),
            'the replay refusal is storable');

        $neutral = $this->get('/account/return-expired');

        $neutral->assertOk();
        $this->assertStringContainsString('no-store', (string) $neutral->headers->get('Cache-Control'),
            'the neutral expired page is storable');
    }

    // ------------------------------------------------------------------
    // Helpers — the same real-flow walk RegistrationTelegramFlowTest uses:
    // register through the form, verify through the webhook, and mint the
    // handoff through the service the confirmation step calls. Nothing is
    // stubbed and no property under test is bypassed.
    // ------------------------------------------------------------------

    private function webhookSecret(): string
    {
        $secret = (string) config('services.telegram.webhook_secret', '');

        if ($secret === '') {
            $secret = 'test-webhook-secret';
            config(['services.telegram.webhook_secret' => $secret]);
        }

        return $secret;
    }

    private function sendStart(string $payload, int $telegramId): void
    {
        // The webhook must not hijack the browser's session; see
        // RegistrationTelegramFlowTest::sendStart for the full reasoning.
        $browserSession = session()->getId();

        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', $this->webhookSecret())
            ->postJson('/webhooks/telegram/updates', [
                'update_id' => random_int(1, 2_000_000_000),
                'message' => [
                    'message_id' => 1,
                    'date' => time(),
                    'chat' => ['id' => $telegramId, 'type' => 'private'],
                    'from' => [
                        'id' => $telegramId,
                        'is_bot' => false,
                        'first_name' => 'Test',
                        'username' => 'testuser',
                    ],
                    'text' => '/start '.$payload,
                ],
            ])->assertOk();

        if ($browserSession !== '') {
            session()->setId($browserSession);
            session()->start();
        }
    }

    private function mintHandoffThroughTheRealFlow(int $telegramId): string
    {
        config(['services.telegram.bot_token' => '123456:test-token']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        $this->post('/register', [
            'name' => 'Replay Test Person',
            'phone' => '0750'.random_int(1000000, 9999999),
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
            'locale' => 'ckb',
            'accept_terms' => true,
            'consent_contact' => false,
        ])->assertRedirect();

        $user = User::query()->latest('id')->firstOrFail();
        $this->actingAs($user->fresh());

        $this->get('/account/telegram/link')->assertOk();

        $token = (string) TelegramVerificationToken::query()
            ->where('user_id', $user->id)
            ->usable()
            ->latest('id')
            ->firstOrFail()
            ->raw();

        $this->sendStart($token, $telegramId);

        $user = $user->fresh();

        $this->assertNotNull($user->telegram_verified_at,
            'the account was not verified, so there is nothing to hand back to');

        return app(TelegramReturnHandoff::class)->mint($user, (string) $telegramId, 'ckb');
    }
}
