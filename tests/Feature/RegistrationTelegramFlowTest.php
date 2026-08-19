<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Identity\Console\PruneTelegramReturnHandoffs;
use App\Modules\Identity\Console\PruneUnlinkedAccounts;
use App\Modules\Identity\Models\TelegramLoginIntent;
use App\Modules\Identity\Models\TelegramVerificationToken;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\AbandonedAccountPolicy;
use App\Modules\Identity\Services\TelegramReturnHandoff;
use App\Modules\Identity\Services\TelegramVerificationService;
use App\Modules\Identity\Support\PostLinkDestination;
use App\Modules\Identity\Support\TelegramReturnUrl;
use App\Modules\Identity\Support\UserReferenceContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The v7 ACCOUNT-FIRST registration model.
 *
 * The account is created and signed in by the form; Telegram linking happens
 * afterwards, from an authenticated session. The security property carried over
 * from the Telegram-first model is that an unlinked account can do nothing: it
 * exists, it is signed in, and every gated surface still refuses it.
 *
 * These tests exist to hold that line. It would be easy to satisfy "register
 * without Telegram" by quietly loosening the gate, and the tests that would
 * catch that are the ones asserting refusal, not the ones asserting success.
 */
final class RegistrationTelegramFlowTest extends TestCase
{
    use RefreshDatabase;

    /** Meets the platform's configured password policy. */
    private const PASSWORD = 'Str0ng!Passw0rd#2026';

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * The registration throttle is per IP over ten minutes, and every test
         * in this class shares one process and one IP. Clearing the limiter
         * between tests keeps each test measuring the flow rather than the
         * leftovers of the previous one.
         */
        Cache::flush();

        /*
         * Registration writes a blind index over the phone number, and the
         * application refuses to build an unkeyed one. Test-only keys are set
         * here so this file carries its own prerequisites and no shared
         * configuration changes.
         */
        if ((string) config('mulkihawler.security.blind_index_key', '') === '') {
            config([
                'mulkihawler.security.blind_index_key' => str_repeat('a', 64),
                'mulkihawler.security.pii_key' => str_repeat('b', 64),
            ]);
        }
    }

    private function webhookSecret(): string
    {
        $secret = (string) config('services.telegram.webhook_secret', '');

        if ($secret === '') {
            $secret = 'test-webhook-secret';
            config(['services.telegram.webhook_secret' => $secret]);
        }

        return $secret;
    }

    /**
     * Drive a Telegram /start through the REAL webhook with the real secret
     * header. Nothing in this file shortcuts past the webhook: that separation
     * is the property under test.
     */
    private function sendStart(string $payload, int $telegramId = 555000111, ?int $updateId = null): void
    {
        /*
         * Telegram calls the webhook server-to-server with no cookie, so it
         * cannot touch the browser's session. In-process the test client
         * shares one session store, and the webhook request would silently
         * take over the session id — making the poll unable to find its own
         * intent for a reason that exists only in the harness. The browser
         * session is captured and restored around the call so the test
         * models the real isolation instead of an artefact of it.
         */
        $browserSession = session()->getId();

        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', $this->webhookSecret())
            ->postJson('/webhooks/telegram/updates', [
                'update_id' => $updateId ?? random_int(1, 2_000_000_000),
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

    /**
     * Register through the real form. Returns the redirect target.
     */
    private function register(string $locale = 'ckb', string $phone = '07501234567', string $name = 'Test Person'): string
    {
        $response = $this->post('/register', [
            'name' => $name,
            'phone' => $phone,
            // The password is required now: it is what makes every later visit
            // an ordinary sign-in instead of another trip through Telegram.
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
            'locale' => $locale,
            'accept_terms' => true,
            'consent_contact' => false,
        ]);

        $response->assertRedirect();

        return (string) $response->headers->get('Location');
    }

    /**
     * Continue as the browser would, on a session that persists.
     *
     * The harness gives every request a fresh session (SESSION_DRIVER=array),
     * so the remember-me cookie set at registration re-authenticates on each
     * request and MIGRATES the session id every time. A real browser keeps its
     * session cookie and never takes that path. Re-establishing the session
     * guard here models the browser; without it the intent's session
     * fingerprint could never match the one polling for it, and the test would
     * be measuring the harness rather than the product.
     *
     * Authentication itself is asserted from the real flow, before this is
     * called, so nothing here is assumed.
     */
    private function continueInBrowser(): User
    {
        $id = Auth::id();

        // By id when a session exists: `firstOrFail()` would pick the oldest
        // row, which in any test that pre-seeds another account is somebody
        // else entirely.
        $user = $id === null
            ? User::query()->latest('id')->firstOrFail()
            : User::query()->findOrFail($id);

        $this->actingAs($user->fresh());

        return $user;
    }

    /**
     * Reduce every account in the database to the shape the reclamation sweep
     * actually exists for: unreachable by anybody.
     *
     * WHY THIS IS NEEDED NOW. The sweep was written when an unlinked account
     * had no email, no password, and a link that died in ten minutes — so once
     * the browser session ended nobody could ever reach the row again, and
     * reclaiming it after 72 hours freed a phone number that was otherwise
     * locked away from its real owner forever.
     *
     * Neither half of that is true of a registration made today. The account
     * has a password, and its verification link has no expiry, so "come back
     * next month and finish" is a supported journey rather than a lost cause.
     * The policy therefore refuses to reclaim an account reachable by either
     * route, and a test that registers through the form no longer produces a
     * reclaimable account at all.
     *
     * The tests that call this are about the sweep's MECHANICS — that
     * `--hours` reaches the policy, that the boundary is respected exactly,
     * that a dry run changes nothing, that release is independently
     * fail-closed. Those properties are unchanged and still worth holding, so
     * the account is put into the legacy shape deliberately and visibly rather
     * than the tests being deleted or the policy loosened to keep them green.
     *
     * The new rule itself is asserted directly in
     * SimplifiedTelegramVerificationTest.
     */
    private function makeUnreachable(): void
    {
        User::query()->update(['password' => null]);

        TelegramVerificationToken::query()->update(['revoked_at' => now()]);
    }

    /**
     * The verification link the signed-in account is shown — obtained by
     * visiting the page, exactly as the browser does.
     *
     * This used to return an `account_link` INTENT token: ten minutes long,
     * bound to the browser session, and minted fresh on every render. The page
     * now issues the permanent verification token instead, so the helper reads
     * that. Every test below still drives the same route.
     */
    private function linkTokenForCurrentSession(string $prefix = ''): string
    {
        $this->continueInBrowser();

        $this->get($prefix.'/account/telegram/link')->assertOk();

        return (string) TelegramVerificationToken::query()
            ->where('user_id', Auth::id())
            ->usable()
            ->latest('id')
            ->firstOrFail()
            ->raw();
    }

    /**
     * Complete verification the way a person does: one press of Start.
     *
     * The browser-confirmation round trip this helper used to perform is gone
     * from THIS path — that is the product change. It has not been weakened
     * away: the account-link flow that re-points an already-linked identity
     * still requires it, and the refusals that the confirmation used to be the
     * only defence against are asserted directly instead (a spent token cannot
     * be claimed by a second Telegram account; an identity in use elsewhere is
     * never reassigned; a linked account is never silently re-pointed).
     *
     * @return string the poll state after the Start
     */
    private function completeLink(string $token, int $telegramId, ?int $updateId = null): string
    {
        $this->sendStart($token, $telegramId, $updateId);

        /*
         * `actingAs()` holds one model instance for the whole test, and the
         * webhook wrote to the row behind it. Re-establishing the session with
         * a fresh instance is what a browser gets for free on its next
         * request; without it the gate reads a stale `telegram_verified_at`.
         */
        $this->continueInBrowser();

        $state = (string) $this->getJson('/account/telegram/link/poll')->json('state');

        $this->continueInBrowser();

        return $state;
    }

    // ---------------------------------------------------------------- 1-3

    public function test_registration_creates_the_account_and_signs_it_in_immediately(): void
    {
        $location = $this->register('ckb');

        $user = User::query()->firstOrFail();

        $this->assertSame('Test Person', $user->name);
        $this->assertTrue(Auth::check(), 'the new account was not authenticated');
        $this->assertSame($user->id, Auth::id());

        // Requirement 3: straight to the verification choice — where both
        // doors (Telegram and WhatsApp) are offered — not to a gated route.
        $this->assertStringContainsString('/account/verify', $location);
    }

    public function test_the_session_is_regenerated_so_a_pre_registration_id_cannot_become_authenticated(): void
    {
        $this->get('/register')->assertOk();
        $before = session()->getId();

        $this->register('ckb');

        $this->assertNotSame($before, session()->getId(), 'the session id survived registration');
    }

    public function test_registration_lands_on_the_verification_choice_in_the_chosen_language(): void
    {
        foreach (['ckb' => '', 'ar' => '/ar', 'en' => '/en'] as $locale => $prefix) {
            Auth::logout();
            $this->flushSession();
            User::query()->delete();

            $location = $this->register($locale, '0750'.random_int(1000000, 9999999));

            $this->assertStringContainsString($prefix.'/account/verify', $location,
                "wrong locale destination for {$locale}");
        }
    }

    // ------------------------------------------------------------------ 4

    public function test_the_new_account_exists_unlinked(): void
    {
        $this->register('ckb');

        $user = User::query()->firstOrFail();

        $this->assertNull($user->telegram_id);
        $this->assertNull($user->telegram_verified_at);

        // A typed number proves nothing, and now proves even less.
        $this->assertFalse((bool) $user->phone_verified);
    }

    // ---------------------------------------------------------------- 5-7

    public function test_an_unlinked_account_cannot_reach_protected_features(): void
    {
        $this->register('ckb');

        foreach (['/account/onboarding', '/account/profile', '/account/privacy'] as $path) {
            $response = $this->get($path);

            $response->assertRedirect();
            // The gate sends unverified sessions to the verification CHOICE,
            // where both doors are offered.
            $this->assertStringContainsString('/account/verify', (string) $response->headers->get('Location'),
                "{$path} did not refuse an unlinked account");
        }
    }

    public function test_the_linking_page_itself_stays_open_to_an_unlinked_account(): void
    {
        $this->register('ckb');
        $this->continueInBrowser();

        $this->get('/account/telegram/link')->assertOk();
        $this->getJson('/account/telegram/link/poll')->assertOk();
    }

    public function test_registration_does_not_bounce_between_pages(): void
    {
        $location = $this->register('ckb');

        // Following the redirect must terminate on a rendered page, not on
        // another redirect: that is what a loop would look like.
        $this->get((string) parse_url($location, PHP_URL_PATH))->assertOk();
    }

    // ------------------------------------------------------------------ 8

    public function test_start_links_the_existing_account_and_creates_no_second_user(): void
    {
        $this->register('ckb');
        $userId = (int) Auth::id();

        $this->completeLink($this->linkTokenForCurrentSession(), 424242);

        $this->assertSame(1, User::query()->count(), 'linking created a second account');

        $user = User::query()->findOrFail($userId);
        $this->assertSame('424242', (string) $user->telegram_id);
        $this->assertNotNull($user->telegram_verified_at);
    }

    public function test_after_linking_the_protected_surface_opens(): void
    {
        $this->register('ckb');
        $this->completeLink($this->linkTokenForCurrentSession(), 424243);

        $this->get('/account/onboarding')->assertOk();
    }

    // ------------------------------------------------------------------ 9

    public function test_the_poll_reports_the_link_so_an_open_tab_advances(): void
    {
        $this->register('ckb');
        $token = $this->linkTokenForCurrentSession();

        $this->assertSame('pending', $this->getJson('/account/telegram/link/poll')->json('state'));

        $this->sendStart($token, 424244);

        /*
         * The tab advances on the Start alone. It used to see
         * `awaiting_confirmation` here and have to post a candidate handle
         * back; that step is the one the simplified flow removes, so the poll
         * goes straight from pending to completed and nothing is clicked in
         * between. Asserting the exact state is what stops the two-step flow
         * reappearing unnoticed.
         */
        $this->continueInBrowser();

        $done = $this->getJson('/account/telegram/link/poll');
        $this->assertSame('completed', $done->json('state'));
        $this->assertNotEmpty($done->json('redirect'), 'the poll gave the tab nowhere to advance to');
    }

    // ----------------------------------------------------------------- 10

    public function test_the_chosen_locale_survives_registration_and_is_stored_on_the_account(): void
    {
        foreach (['ckb', 'ar', 'en'] as $locale) {
            Auth::logout();
            $this->flushSession();
            User::query()->delete();

            $this->register($locale, '0750'.random_int(1000000, 9999999));

            $this->assertSame($locale, User::query()->firstOrFail()->preferred_locale);
        }
    }

    // ----------------------------------------------------------------- 11

    public function test_an_already_linked_account_is_unaffected(): void
    {
        $user = User::factory()->create([
            'telegram_verified_at' => now(),
            'telegram_id' => '999888777',
            'is_active' => true,
        ]);

        $this->actingAs($user)->get('/account/onboarding')->assertOk();
    }

    public function test_a_suspended_account_is_blocked_whatever_its_link_state(): void
    {
        foreach ([null, now()] as $verifiedAt) {
            $user = User::factory()->create([
                'telegram_verified_at' => $verifiedAt,
                'telegram_id' => $verifiedAt === null ? null : (string) random_int(100000, 999999),
                'is_active' => false,
            ]);

            $response = $this->actingAs($user)->get('/account/onboarding');

            $this->assertContains($response->status(), [302, 403], 'a suspended account reached onboarding');
        }
    }

    // ----------------------------------------------------------------- 12

    public function test_a_duplicate_phone_number_is_refused_without_signing_anyone_in(): void
    {
        $this->register('ckb', '07501234567');
        $firstId = (int) Auth::id();

        Auth::logout();
        $this->flushSession();
        Cache::flush();

        $response = $this->post('/register', [
            'name' => 'Someone Else',
            'phone' => '07501234567',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
            'locale' => 'ckb',
            'accept_terms' => true,
            'consent_contact' => false,
        ]);

        $response->assertSessionHasErrors('phone');

        // The critical part: no takeover, no second row.
        $this->assertFalse(Auth::check(), 'a duplicate submission signed somebody in');
        $this->assertSame(1, User::query()->count());
        $this->assertSame($firstId, (int) User::query()->firstOrFail()->id);
    }

    public function test_a_link_token_is_single_use(): void
    {
        $this->register('ckb');
        $token = $this->linkTokenForCurrentSession();

        $this->completeLink($token, 424245);

        // A different Telegram identity replaying the spent token must not
        // repoint the account.
        $this->sendStart($token, 777777);

        $this->assertSame('424245', (string) User::query()->firstOrFail()->telegram_id);
    }

    public function test_duplicate_telegram_updates_are_idempotent(): void
    {
        $this->register('ckb');
        $token = $this->linkTokenForCurrentSession();

        $updateId = random_int(1, 2_000_000_000);
        $this->sendStart($token, 424246, $updateId);
        $this->sendStart($token, 424246, $updateId);
        $this->completeLink($token, 424246);

        $this->assertSame(1, User::query()->count());
        $this->assertSame('424246', (string) User::query()->firstOrFail()->telegram_id);
    }

    /**
     * The successor to test_an_expired_token_cannot_link.
     *
     * That test forced the account-link intent's `expires_at` into the past and
     * asserted the Start was refused. The verification token has no expiry at
     * all — deliberately, so that registering tonight and pressing Start next
     * month works — so there is no clock left to wind forward and the old
     * premise cannot be recreated.
     *
     * REVOCATION is what replaced it: the other half of "permanent until used
     * OR revoked", and now the only way a live link becomes a dead one. The
     * property the old test protected — a link the system has invalidated must
     * not verify anybody — is asserted here against that mechanism.
     */
    public function test_a_revoked_token_cannot_link(): void
    {
        $this->register('ckb');
        $token = $this->linkTokenForCurrentSession();
        $user = $this->continueInBrowser();

        app(TelegramVerificationService::class)->revokeAllFor($user, 'regression_test');

        $this->sendStart($token, 424247);

        $this->assertNull(User::query()->firstOrFail()->telegram_verified_at);
    }

    public function test_a_telegram_identity_already_on_another_account_cannot_be_claimed(): void
    {
        $existing = User::factory()->create([
            'telegram_id' => '555111222',
            'telegram_verified_at' => now(),
            'is_active' => true,
        ]);

        $this->register('ckb', '07509998877');
        $newId = (int) Auth::id();

        $this->completeLink($this->linkTokenForCurrentSession(), 555111222);

        $this->assertNull(User::query()->findOrFail($newId)->telegram_verified_at,
            'a Telegram identity was linked to two accounts');
        $this->assertSame('555111222', (string) User::query()->findOrFail($existing->id)->telegram_id);
    }

    public function test_a_start_from_another_device_completes_without_the_browser(): void
    {
        /*
         * Opening Telegram on a phone while the tab sits on a laptop is the
         * NORMAL case, not an edge one, and this is the behaviour the
         * simplification is for: the webhook carries no browser session, and
         * it does not need one. One press finishes the job from any device.
         *
         * This test previously asserted the opposite — that a Start alone
         * parked a candidate and waited for the owner's browser to approve it.
         * That gate was the only thing standing between a leaked token and a
         * link, so removing it is only defensible because of what the token can
         * do: attach a Telegram identity to an account that has NONE, granting
         * the presser no session, no password and no data. The three tests
         * below this one hold the line that actually matters, and each of them
         * would have passed under the old flow too.
         */
        $this->register('ckb');
        $userId = (int) Auth::id();
        $token = $this->linkTokenForCurrentSession();

        Auth::logout();
        $this->flushSession();

        $this->sendStart($token, 424248);

        $verified = User::query()->findOrFail($userId);

        $this->assertNotNull($verified->telegram_verified_at,
            'one press of Start must verify the account, from any device');
        $this->assertSame('424248', (string) $verified->telegram_id);

        // No second step was left hanging anywhere for anybody to press.
        $this->actingAs($verified);
        $this->assertSame('completed', $this->getJson('/account/telegram/link/poll')->json('state'));
    }

    public function test_a_spent_verification_link_cannot_be_claimed_by_a_second_telegram_account(): void
    {
        /*
         * The takeover the removed confirmation step used to prevent, refused
         * directly instead. Somebody who obtains a link AFTER its owner has
         * used it gets nothing.
         */
        $this->register('ckb');
        $userId = (int) Auth::id();
        $token = $this->linkTokenForCurrentSession();

        $this->sendStart($token, 111222333, updateId: 991001);
        $this->assertSame('111222333', (string) User::query()->findOrFail($userId)->telegram_id);

        $this->sendStart($token, 444555666, updateId: 991002);

        $this->assertSame('111222333', (string) User::query()->findOrFail($userId)->telegram_id,
            'a spent link must never re-point an account at another Telegram identity');
    }

    public function test_a_verification_link_cannot_take_a_telegram_identity_from_another_account(): void
    {
        $owner = User::factory()->create([
            'telegram_id' => '888888888',
            'telegram_verified_at' => now(),
        ]);

        $this->register('ckb', phone: '07504443333');
        $userId = (int) Auth::id();
        $token = $this->linkTokenForCurrentSession();

        $this->sendStart($token, 888888888);

        $this->assertNull(User::query()->findOrFail($userId)->telegram_verified_at,
            'an identity already in use must not verify a second account');
        $this->assertSame('888888888', (string) $owner->fresh()->telegram_id,
            'and its owner must keep it');
    }

    public function test_abandoned_unlinked_accounts_are_reclaimed_but_linked_ones_are_not(): void
    {
        /*
         * `password => null` on the unlinked pair, because that is the
         * population this sweep is for: accounts from before the simplified
         * flow, with no email, no password and no live verification link, which
         * genuinely nobody can reach. An account WITH a password is reachable
         * by its owner and is refused by the policy — asserted separately in
         * SimplifiedTelegramVerificationTest.
         */
        $abandoned = User::factory()->create([
            'telegram_id' => null,
            'telegram_verified_at' => null,
            'password' => null,
            'created_at' => now()->subDays(30),
        ]);

        $linked = User::factory()->create([
            'telegram_id' => '333222111',
            'telegram_verified_at' => now(),
            'created_at' => now()->subDays(30),
        ]);

        $recent = User::factory()->create([
            'telegram_id' => null,
            'telegram_verified_at' => null,
            'password' => null,
            'created_at' => now()->subHour(),
        ]);

        $this->artisan(PruneUnlinkedAccounts::class, ['--hours' => 72])->assertSuccessful();

        $this->assertNull(User::query()->find($abandoned->id), 'the abandoned account was not reclaimed');
        $this->assertNotNull(User::query()->find($linked->id), 'a linked account was reclaimed');
        $this->assertNotNull(User::query()->find($recent->id), 'a fresh registration was reclaimed');
    }

    // ------------------------------------- corrections: Telegram return button

    /**
     * @return array<int, array<string, mixed>> the decoded outbound Telegram payloads
     */
    private function capturedTelegramPayloads(): array
    {
        $out = [];

        foreach (Http::recorded() as [$request]) {
            if (str_contains($request->url(), 'api.telegram.org')) {
                $out[] = $request->data();
            }
        }

        return $out;
    }

    private function fakeTelegramApi(): void
    {
        config(['services.telegram.bot_token' => '123456:test-token']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);
    }

    /**
     * The bot's success button: localized, on our own origin, and carrying
     * NOTHING that authenticates anybody.
     *
     * The previous edition asserted the opposite of that last clause — it
     * required the button to carry a one-time handoff token and proved the
     * destination by redeeming it from a cold session. That was right for the
     * old flow, where the person had just confirmed in a browser and the
     * handoff saved them a sign-in they could not otherwise perform.
     *
     * It is wrong now, and deliberately so. The account has a password, so
     * anyone whose tab is gone can simply sign in; minting a credential that
     * establishes a session in a cold browser, purely to save that step, would
     * put a working session in a chat message. So the button is a plain link,
     * and this test holds that: right label, right locale, our origin, no
     * token, and a gated page still refuses whoever opens it.
     */
    public function test_the_bot_sends_a_localized_return_button_that_authenticates_nobody(): void
    {
        $expected = [
            // The default locale has no prefix, so its home URL is the bare
            // site root — asserted as such rather than as a locale segment.
            'ckb' => ['گەڕانەوە بۆ MyHawler', '/'],
            'ar' => ['العودة إلى MyHawler', '/ar'],
            'en' => ['Return to MyHawler', '/en'],
        ];

        foreach ($expected as $locale => [$label, $path]) {
            Auth::logout();
            $this->flushSession();
            User::query()->forceDelete();
            TelegramLoginIntent::query()->delete();
            Cache::flush();
            $this->fakeTelegramApi();

            $this->register($locale, '0750'.random_int(1000000, 9999999));
            $token = $this->linkTokenForCurrentSession();
            $this->completeLink($token, random_int(100000, 999999));

            $markups = [];

            foreach ($this->capturedTelegramPayloads() as $payload) {
                if (isset($payload['reply_markup'])) {
                    $decoded = json_decode((string) $payload['reply_markup'], true);

                    if (isset($decoded['inline_keyboard'])) {
                        $markups[] = $decoded['inline_keyboard'][0][0];
                    }
                }
            }

            $this->assertNotEmpty($markups, "no inline return button was sent for {$locale}");

            $button = end($markups);
            $url = (string) $button['url'];

            $this->assertSame($label, $button['text'], "wrong button label for {$locale}");
            $this->assertStringStartsWith((string) config('app.url'), $url,
                "the return URL left our own origin for {$locale}");
            $this->assertStringEndsWith($path, $url,
                "the return button did not point at the {$locale} side of the site");

            // The whole point: no credential of any kind travels in the chat.
            $this->assertStringNotContainsString('/account/return/', $url,
                "the success button carried an authenticating handoff for {$locale}");

            Auth::logout();
            $this->flushSession();

            // And opening it signs nobody in: a gated page still refuses.
            $this->get('/account/onboarding')->assertRedirect();
        }
    }

    public function test_the_return_url_cannot_be_pointed_off_our_own_origin(): void
    {
        // Only fixed destination keys exist, so there is no input through
        // which another host could be requested.
        $this->assertNull(TelegramReturnUrl::for('https://evil.example/steal', 'en'));
        $this->assertNull(TelegramReturnUrl::for('//evil.example', 'en'));
        $this->assertNull(TelegramReturnUrl::for('anything-else', 'ckb'));

        $host = (string) parse_url((string) config('app.url'), PHP_URL_HOST);

        foreach (['ckb', 'ar', 'en'] as $locale) {
            $url = TelegramReturnUrl::for('onboarding', $locale);
            $this->assertNotNull($url);
            $this->assertSame($host, parse_url($url, PHP_URL_HOST), 'the return URL left our origin');
        }
    }

    public function test_the_bot_answers_in_the_website_language_not_the_telegram_client_language(): void
    {
        $this->fakeTelegramApi();

        // Registered in Arabic; the Telegram account is set to English.
        $this->register('ar', '07507654321');
        $token = $this->linkTokenForCurrentSession('/ar');

        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', $this->webhookSecret())
            ->postJson('/webhooks/telegram/updates', [
                'update_id' => random_int(1, 2_000_000_000),
                'message' => [
                    'message_id' => 1,
                    'date' => time(),
                    'chat' => ['id' => 909090, 'type' => 'private'],
                    'from' => ['id' => 909090, 'is_bot' => false, 'first_name' => 'T', 'language_code' => 'en'],
                    'text' => '/start '.$token,
                ],
            ])->assertOk();

        $buttons = [];

        foreach ($this->capturedTelegramPayloads() as $payload) {
            if (isset($payload['reply_markup'])) {
                $decoded = json_decode((string) $payload['reply_markup'], true);

                if (isset($decoded['inline_keyboard'])) {
                    $buttons[] = $decoded['inline_keyboard'][0][0];
                }
            }
        }

        $this->assertNotEmpty($buttons, 'the bot sent no return button');
        $this->assertSame('العودة إلى MyHawler', end($buttons)['text']);
        $this->assertMatchesRegularExpression('#/ar(/|$)#', (string) end($buttons)['url'],
            'the return button did not point at the Arabic side of the site');
    }

    // --------------------------------- corrections: current-request locale

    public function test_the_flashed_confirmation_is_rendered_in_the_chosen_language(): void
    {
        $expected = [
            'ckb' => 'هەژمارەکەت بە سەرکەوتوویی دروست کرا',
            'ar' => 'تم إنشاء حسابك بنجاح',
            'en' => 'Your account was created successfully',
        ];

        foreach ($expected as $locale => $message) {
            Auth::logout();
            $this->flushSession();
            User::query()->forceDelete();
            Cache::flush();

            // Deliberately submitted from a page rendered in Kurdish while a
            // DIFFERENT language is chosen on the form: the flash must follow
            // the choice, not the page it was submitted from.
            App::setLocale('ckb');

            $this->register($locale, '0750'.random_int(1000000, 9999999));

            $this->assertSame($message, session('status'),
                "the flashed message was not rendered in {$locale}");
        }
    }

    public function test_the_duplicate_message_is_rendered_in_the_chosen_language(): void
    {
        $this->register('ckb', '07501230000');
        Auth::logout();
        $this->flushSession();
        Cache::flush();
        App::setLocale('ckb');

        $response = $this->post('/register', [
            'name' => 'Someone Else',
            'phone' => '07501230000',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
            'locale' => 'en',
            'accept_terms' => true,
            'consent_contact' => false,
        ]);

        $errors = session('errors');
        $this->assertNotNull($errors);
        $this->assertSame(
            __('identity.register.conflict', [], 'en'),
            $errors->first('phone'),
            'the conflict message did not follow the submitted locale'
        );

        // It must not send an unlinked person to Telegram sign-in, which
        // cannot recover an account that has no Telegram identity.
        $this->assertStringNotContainsStringIgnoringCase('telegram', $errors->first('phone'));
    }

    // ------------------------------------------- corrections: recovery paths

    public function test_an_unlinked_account_can_abandon_its_registration_and_register_again(): void
    {
        $this->register('ckb', '07505550000');
        $this->continueInBrowser();

        $this->post('/account/registration/abandon')->assertRedirect();
        $this->assertFalse(Auth::check(), 'abandoning left the person signed in');

        Cache::flush();
        $this->flushSession();

        // The number is free immediately — no waiting for the retention window.
        $this->register('ckb', '07505550000');
        $this->assertTrue(Auth::check());
        $this->assertSame(1, User::query()->count());
    }

    public function test_a_linked_account_cannot_be_abandoned(): void
    {
        $this->register('ckb', '07505551111');
        $this->completeLink($this->linkTokenForCurrentSession(), 616161);

        $user = User::query()->firstOrFail();
        $this->assertNotNull($user->telegram_verified_at);

        $this->post('/account/registration/abandon')->assertRedirect();

        // Signed out, but the account itself survives untouched.
        $survivor = User::query()->findOrFail($user->id);
        $this->assertSame('616161', (string) $survivor->telegram_id);
        $this->assertFalse($survivor->trashed());
    }

    public function test_an_account_that_owns_content_is_never_reclaimed(): void
    {
        $this->register('ckb', '07505552222');
        $user = $this->continueInBrowser();

        DB::table('saved_searches')->insert([
            'user_id' => $user->id,
            'name' => 'Anything',
            'criteria' => json_encode(['q' => 'x']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $policy = app(AbandonedAccountPolicy::class);
        $assessment = $policy->assessSelfAbandon($user->fresh());

        $this->assertFalse($assessment['eligible']);
        $this->assertStringStartsWith('owns:', $assessment['reason']);

        $this->post('/account/registration/abandon')->assertRedirect();
        $this->assertNotNull(User::query()->find($user->id), 'an account with content was deleted');
    }

    public function test_losing_the_session_before_linking_leaves_a_documented_state(): void
    {
        $this->register('ckb', '07505553333');
        $userId = (int) Auth::id();

        // The browser is gone: cookies cleared, no way back in.
        Auth::logout();
        $this->flushSession();
        Cache::flush();

        // The account still exists and is still unlinked...
        $stranded = User::query()->findOrFail($userId);
        $this->assertNull($stranded->telegram_verified_at);

        // ...and re-registering the same number is REFUSED rather than
        // silently signing anyone in. This is the documented lockout: the
        // number is released by the retention sweep, and the message does not
        // promise a Telegram login that cannot work.
        $response = $this->post('/register', [
            'name' => 'Test Person',
            'phone' => '07505553333',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
            'locale' => 'ckb',
            'accept_terms' => true,
            'consent_contact' => false,
        ]);

        $response->assertSessionHasErrors('phone');
        $this->assertFalse(Auth::check());
        $this->assertSame(1, User::query()->count());
    }

    /**
     * Every user reference in the MIGRATED schema must be classified.
     *
     * The previous version of this test only proved the listed pairs existed,
     * which is one-directional: it could never notice a user-owned column that
     * nobody added to the list, and a missed column means an account is
     * anonymized and deleted while it still owns something. This walks the
     * schema instead and fails when a reference appears in neither group.
     */
    public function test_every_user_reference_in_the_schema_is_classified(): void
    {
        $discovered = [];

        foreach (Schema::getTables() as $table) {
            $name = is_array($table) ? $table['name'] : $table->name;

            foreach (Schema::getColumnListing($name) as $column) {
                $looksLikeUserReference =
                    preg_match('/(^|_)user_id$/', $column) === 1
                    || str_ends_with($column, '_by')
                    || str_ends_with($column, '_by_user_id')
                    || str_ends_with($column, '_user_id')
                    || in_array($column, ['author_id', 'uploaded_by', 'manager_user_id', 'assigned_to_user_id'], true);

                if ($looksLikeUserReference) {
                    $discovered[] = $name.'.'.$column;
                }
            }
        }

        sort($discovered);

        $classified = UserReferenceContract::classified();

        $unclassified = array_values(array_diff($discovered, $classified));

        $this->assertSame([], $unclassified,
            'these user references are in neither BLOCK_RECLAMATION nor INTENTIONALLY_IGNORED — '
            .'classify them before the cleanup can be trusted: '.implode(', ', $unclassified));

        $stale = array_values(array_diff($classified, $discovered));

        $this->assertSame([], $stale,
            'the contract names references that no longer exist: '.implode(', ', $stale));
    }

    public function test_every_blocking_pair_exists_and_every_ignored_pair_has_a_reason(): void
    {
        $this->assertNotEmpty(UserReferenceContract::BLOCK_RECLAMATION);

        foreach (UserReferenceContract::BLOCK_RECLAMATION as [$table, $column]) {
            $this->assertTrue(Schema::hasTable($table), "blocking contract names a missing table: {$table}");
            $this->assertTrue(Schema::hasColumn($table, $column),
                "blocking contract names {$table}.{$column}, which does not exist — cleanup would fail closed forever");
        }

        foreach (UserReferenceContract::INTENTIONALLY_IGNORED as $entry) {
            [$table, $column, $reason] = $entry;

            $this->assertTrue(Schema::hasTable($table), "ignored contract names a missing table: {$table}");
            $this->assertTrue(Schema::hasColumn($table, $column), "ignored contract names a missing column: {$table}.{$column}");
            $this->assertNotSame('', trim($reason),
                "{$table}.{$column} is ignored without a documented reason");
        }
    }

    public function test_release_rechecks_the_complete_blocking_set_inside_the_transaction(): void
    {
        $this->register('ckb', '07507770001');
        $this->makeUnreachable();
        $user = $this->continueInBrowser();

        $policy = app(AbandonedAccountPolicy::class);

        // Eligible at assessment time...
        $this->assertTrue($policy->assessSelfAbandon($user->fresh())['eligible']);

        // ...then content appears, exactly as it could between an assessment
        // and the write that acts on it.
        DB::table('saved_searches')->insert([
            'user_id' => $user->id,
            'name' => 'Attached after assessment',
            'criteria' => json_encode(['q' => 'x']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // release() must refuse on its own, without being asked to re-assess.
        $this->assertFalse($policy->release($user->fresh(), 'regression_test'));

        $survivor = User::query()->find($user->id);
        $this->assertNotNull($survivor, 'an account that gained content was deleted');
        $this->assertFalse($survivor->trashed());
        $this->assertNotNull($survivor->phone_index, 'the phone index was released despite owned content');
        $this->assertSame(1, DB::table('saved_searches')->where('user_id', $user->id)->count());
    }

    // ------------------------------ return-handoff retention (scheduled)

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function seedHandoffRow(array $overrides = []): int
    {
        $user = User::factory()->create([
            'telegram_id' => (string) random_int(100000, 999999),
            'telegram_verified_at' => now(),
            'is_active' => true,
        ]);

        return (int) DB::table('telegram_return_handoffs')->insertGetId(array_merge([
            'token_hash' => hash('sha256', Str::random(64)),
            'user_id' => $user->id,
            'telegram_id_hash' => hash('sha256', (string) $user->telegram_id),
            'locale' => 'ckb',
            'destination' => 'onboarding',
            'expires_at' => now()->addMinutes(5),
            'consumed_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    public function test_a_live_handoff_survives_the_scheduled_cleanup(): void
    {
        $live = $this->seedHandoffRow(['expires_at' => now()->addMinutes(5)]);

        $this->artisan(PruneTelegramReturnHandoffs::class)->assertSuccessful();

        $this->assertDatabaseHas('telegram_return_handoffs', ['id' => $live]);
    }

    public function test_an_expired_consumed_handoff_is_removed(): void
    {
        $spent = $this->seedHandoffRow([
            'expires_at' => now()->subDays(3),
            'consumed_at' => now()->subDays(3),
        ]);

        $this->artisan(PruneTelegramReturnHandoffs::class)->assertSuccessful();

        $this->assertDatabaseMissing('telegram_return_handoffs', ['id' => $spent]);
    }

    public function test_an_expired_unconsumed_handoff_follows_the_same_policy(): void
    {
        // Never used, but expired well past the grace period: it can no longer
        // be redeemed, so holding its user reference serves no purpose.
        $stale = $this->seedHandoffRow([
            'expires_at' => now()->subDays(3),
            'consumed_at' => null,
        ]);

        // Expired only moments ago: inside the grace period, so kept.
        $recent = $this->seedHandoffRow([
            'expires_at' => now()->subMinutes(5),
            'consumed_at' => null,
        ]);

        $this->artisan(PruneTelegramReturnHandoffs::class)->assertSuccessful();

        $this->assertDatabaseMissing('telegram_return_handoffs', ['id' => $stale]);
        $this->assertDatabaseHas('telegram_return_handoffs', ['id' => $recent]);
    }

    public function test_the_handoff_cleanup_is_idempotent_and_supports_dry_run(): void
    {
        $spent = $this->seedHandoffRow([
            'expires_at' => now()->subDays(3),
            'consumed_at' => now()->subDays(3),
        ]);

        $this->artisan(PruneTelegramReturnHandoffs::class, ['--dry-run' => true])->assertSuccessful();
        // Third argument of assertDatabaseHas is a CONNECTION name, not a
        // message — passing prose there asks Laravel for a connection called
        // "a dry run deleted something".
        $this->assertDatabaseHas('telegram_return_handoffs', ['id' => $spent]);

        $this->artisan(PruneTelegramReturnHandoffs::class)->assertSuccessful();
        $this->artisan(PruneTelegramReturnHandoffs::class)->assertSuccessful();

        $this->assertSame(0, DB::table('telegram_return_handoffs')->where('id', $spent)->count());
    }

    public function test_the_handoff_cleanup_is_scheduled(): void
    {
        $this->artisan('schedule:list')
            ->expectsOutputToContain('prune-return-handoffs')
            ->assertSuccessful();
    }

    public function test_the_retention_window_comes_from_configuration(): void
    {
        config(['mulkihawler.registration.unlinked_retention_hours' => 5]);
        $this->assertSame(5, AbandonedAccountPolicy::retentionHours());

        config(['mulkihawler.registration.unlinked_retention_hours' => 0]);
        $this->assertSame(1, AbandonedAccountPolicy::retentionHours(), 'a zero window must not mean instant deletion');
    }

    public function test_reclaiming_anonymizes_the_row_and_keeps_only_the_tombstone(): void
    {
        $this->register('ckb', '07505554444');
        $this->makeUnreachable();
        $user = User::query()->firstOrFail();
        $created = $user->created_at;

        User::query()->update(['created_at' => now()->subDays(30)]);

        $this->artisan(PruneUnlinkedAccounts::class)->assertSuccessful();

        $row = User::withTrashed()->findOrFail($user->id);

        $this->assertNotNull($row->deleted_at);
        $this->assertSame('', (string) $row->name, 'the name survived reclamation');
        $this->assertNull($row->phone_encrypted);
        $this->assertNull($row->phone_index, 'the unique phone index was not released');
        $this->assertSame((string) config('app.locale'), (string) $row->preferred_locale,
            'the chosen language survived reclamation');
        $this->assertNotNull($row->created_at, 'the retention decision is no longer auditable');
        $this->assertNotNull($created);
    }

    public function test_reclaiming_is_idempotent(): void
    {
        $this->register('ckb', '07505555555');
        $this->makeUnreachable();
        User::query()->update(['created_at' => now()->subDays(30)]);

        $this->artisan(PruneUnlinkedAccounts::class)->assertSuccessful();
        $this->artisan(PruneUnlinkedAccounts::class)->assertSuccessful();

        $this->assertSame(1, User::withTrashed()->count());
        $this->assertSame(0, User::query()->count());
    }

    public function test_dry_run_changes_nothing(): void
    {
        $this->register('ckb', '07505556666');
        $this->makeUnreachable();
        User::query()->update(['created_at' => now()->subDays(30)]);

        $this->artisan(PruneUnlinkedAccounts::class, ['--dry-run' => true])->assertSuccessful();

        $this->assertSame(1, User::query()->count(), 'a dry run deleted something');
        $this->assertNotNull(User::query()->firstOrFail()->phone_index);
    }

    // ================= BLOCKER 1: one canonical post-link destination =========

    public function test_a_new_account_lands_on_onboarding_everywhere_after_linking(): void
    {
        $this->fakeTelegramApi();
        $this->register('ckb');
        $token = $this->linkTokenForCurrentSession();
        $this->sendStart($token, 818001);

        /*
         * The Start finishes the job, so the poll IS the answer — there is no
         * confirm response to compare against any more. What the test is
         * really holding is that every surface agrees on one destination, so
         * the poll's answer is checked against the canonical resolver and
         * against a second poll, which is what a reopened tab would get.
         */
        $this->continueInBrowser();

        $poll = $this->getJson('/account/telegram/link/poll');

        // The EXACT destination, not "either of two routes".
        $this->assertStringEndsWith('/account/onboarding', (string) $poll->json('redirect'),
            'the poll sent a new account somewhere other than onboarding');

        $this->continueInBrowser();
        $again = $this->getJson('/account/telegram/link/poll');
        $this->assertStringEndsWith('/account/onboarding', (string) $again->json('redirect'),
            'a second tab disagreed about where this account belongs');
    }

    public function test_an_account_that_finished_onboarding_returns_to_profile(): void
    {
        $user = User::factory()->create([
            'telegram_id' => '818002',
            'telegram_verified_at' => now(),
            'onboarding_completed_at' => now()->subDay(),
            'is_active' => true,
        ]);

        $this->assertStringEndsWith('/account/profile', PostLinkDestination::for($user));
        $this->assertSame('account.profile', PostLinkDestination::routeName($user));

        $fresh = User::factory()->create([
            'telegram_id' => '818003',
            'telegram_verified_at' => now(),
            'onboarding_completed_at' => null,
            'is_active' => true,
        ]);

        $this->assertSame('account.onboarding', PostLinkDestination::routeName($fresh));
    }

    public function test_the_destination_is_localized_for_each_language(): void
    {
        $user = User::factory()->create(['onboarding_completed_at' => null]);

        $this->assertStringEndsWith('/account/onboarding', PostLinkDestination::for($user, 'ckb'));
        $this->assertStringContainsString('/ar/account/onboarding', PostLinkDestination::for($user, 'ar'));
        $this->assertStringContainsString('/en/account/onboarding', PostLinkDestination::for($user, 'en'));
    }

    // ============ BLOCKER 2: the return handoff for a cold browser ===========

    /**
     * Mint a return handoff and return its raw token.
     *
     * WHAT CHANGED, AND WHY THIS STILL EXERCISES THE REAL THING. This used to
     * walk register → Start → poll → confirm and pull the handoff out of the
     * bot message, because the browser confirmation was what minted one.
     * Registration no longer has a confirmation step, so that walk now ends
     * with the account verified and no handoff anywhere — every test below it
     * failed on a 422 from a confirm call with nothing to confirm.
     *
     * The handoff itself is NOT retired: AccountTelegramLinkController::confirm()
     * still mints one when an account that already has a Telegram identity is
     * re-pointed, and every property the tests below assert — single use,
     * expiry, refusal after unlinking, refusal on identity change, refusal for
     * a suspended account, locale preservation, no readable PII in the URL —
     * belongs to TelegramReturnHandoff and not to whatever triggered it.
     *
     * So the account is brought to a verified state through the REAL new flow,
     * and the handoff is then minted through the same service the confirmation
     * calls, with the same arguments. Nothing is stubbed and no property under
     * test is bypassed.
     */
    private function mintHandoffThroughTheRealFlow(int $telegramId, string $locale = 'ckb'): string
    {
        $this->fakeTelegramApi();
        $this->register($locale, '0750'.random_int(1000000, 9999999));

        $token = $this->linkTokenForCurrentSession($locale === 'ckb' ? '' : '/'.$locale);
        $this->sendStart($token, $telegramId);

        $user = $this->continueInBrowser()->fresh();

        $this->assertNotNull($user->telegram_verified_at,
            'the account was not verified, so there is nothing to hand back to');

        return app(TelegramReturnHandoff::class)->mint($user, (string) $telegramId, $locale);
    }

    public function test_a_cold_browser_with_no_cookies_is_authenticated_by_the_handoff(): void
    {
        $handoff = $this->mintHandoffThroughTheRealFlow(818010);
        $userId = (int) User::query()->firstOrFail()->id;

        // Telegram's in-app browser: no session, no cookies, nothing.
        Auth::logout();
        $this->flushSession();

        $response = $this->get('/account/return/'.$handoff);

        $response->assertRedirect();
        $this->assertStringEndsWith('/account/onboarding', (string) $response->headers->get('Location'),
            'the handoff did not land on onboarding');
        $this->assertSame($userId, Auth::id(), 'the cold browser was not authenticated');
    }

    public function test_the_handoff_is_single_use_and_a_replay_is_refused(): void
    {
        $handoff = $this->mintHandoffThroughTheRealFlow(818011);

        Auth::logout();
        $this->flushSession();
        $this->get('/account/return/'.$handoff)->assertRedirect();

        Auth::logout();
        $this->flushSession();

        $replay = $this->get('/account/return/'.$handoff);
        $replay->assertRedirect();
        $this->assertStringContainsString('return-expired', (string) $replay->headers->get('Location'));
        $this->assertFalse(Auth::check(), 'a replayed handoff authenticated somebody');
    }

    public function test_an_expired_handoff_is_refused(): void
    {
        $handoff = $this->mintHandoffThroughTheRealFlow(818012);

        Auth::logout();
        $this->flushSession();

        $this->travel(TelegramReturnHandoff::TTL_SECONDS + 60)->seconds();

        $response = $this->get('/account/return/'.$handoff);
        $this->assertStringContainsString('return-expired', (string) $response->headers->get('Location'));
        $this->assertFalse(Auth::check());

        $this->travelBack();
    }

    public function test_a_wrong_or_forged_handoff_is_refused(): void
    {
        $this->get('/account/return/'.str_repeat('a', 64))->assertRedirect();
        $this->assertFalse(Auth::check(), 'a forged token authenticated somebody');

        // Too short / malformed never even reaches the controller.
        $this->get('/account/return/short')->assertNotFound();
    }

    public function test_a_handoff_is_refused_after_the_account_is_unlinked(): void
    {
        $handoff = $this->mintHandoffThroughTheRealFlow(818013);
        $user = User::query()->firstOrFail();

        Auth::logout();
        $this->flushSession();

        // The identity it was minted for is gone.
        $user->forceFill(['telegram_id' => null, 'telegram_verified_at' => null])->save();

        $response = $this->get('/account/return/'.$handoff);
        $this->assertStringContainsString('return-expired', (string) $response->headers->get('Location'));
        $this->assertFalse(Auth::check());
    }

    public function test_a_handoff_is_refused_when_the_telegram_identity_changed(): void
    {
        $handoff = $this->mintHandoffThroughTheRealFlow(818014);
        $user = User::query()->firstOrFail();

        Auth::logout();
        $this->flushSession();

        $user->forceFill(['telegram_id' => '999000111'])->save();

        $response = $this->get('/account/return/'.$handoff);
        $this->assertStringContainsString('return-expired', (string) $response->headers->get('Location'));
        $this->assertFalse(Auth::check());
    }

    public function test_a_handoff_is_refused_for_a_suspended_account(): void
    {
        $handoff = $this->mintHandoffThroughTheRealFlow(818015);
        User::query()->firstOrFail()->forceFill(['is_active' => false])->save();

        Auth::logout();
        $this->flushSession();

        $response = $this->get('/account/return/'.$handoff);
        $this->assertStringContainsString('return-expired', (string) $response->headers->get('Location'));
        $this->assertFalse(Auth::check());
    }

    public function test_the_handoff_preserves_the_registration_locale(): void
    {
        foreach (['ar' => '/ar/account/onboarding', 'en' => '/en/account/onboarding'] as $locale => $expected) {
            Auth::logout();
            $this->flushSession();
            User::query()->forceDelete();
            TelegramLoginIntent::query()->delete();
            Cache::flush();

            $handoff = $this->mintHandoffThroughTheRealFlow(random_int(818020, 818999), $locale);

            Auth::logout();
            $this->flushSession();

            $response = $this->get('/account/return/'.$handoff);
            $this->assertStringContainsString($expected, (string) $response->headers->get('Location'),
                "the handoff lost the {$locale} locale");
        }
    }

    public function test_the_handoff_url_carries_nothing_readable_about_the_person(): void
    {
        /*
         * The handoff is minted the way it actually is now — from the service
         * the re-point confirmation calls — because the registration journey no
         * longer produces one. The property under test is unchanged: the URL a
         * person receives must reveal nothing about them.
         */
        $handoff = $this->mintHandoffThroughTheRealFlow(818030, 'ckb');
        $user = User::query()->firstOrFail();

        $urls = [];

        foreach ($this->capturedTelegramPayloads() as $payload) {
            if (isset($payload['reply_markup'])) {
                $decoded = json_decode((string) $payload['reply_markup'], true);
                $urls[] = (string) ($decoded['inline_keyboard'][0][0]['url'] ?? '');
            }
        }

        $joined = implode(' ', $urls).' '.route('telegram.return', ['token' => $handoff]);

        $token = $handoff;

        // A single-digit id would collide with a random token by chance, which
        // would make this assertion meaningless rather than strict — so the
        // check is that the token is opaque and unrelated to the account, not
        // that it avoids one character.
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{64}$/', $token, 'the handoff is not an opaque token');
        $this->assertStringNotContainsString('818030', $joined, 'the URL leaked the Telegram id');
        /*
         * The account's REAL number, decrypted from the row, not a literal.
         * The helper generates a fresh number per run, so asserting a
         * hard-coded one would have passed no matter what the URL contained.
         */
        $phone = (string) $user->phone();

        $this->assertNotSame('', $phone, 'the account has no phone to check the URL against');
        $this->assertStringNotContainsString(ltrim($phone, '+'), $joined, 'the URL leaked the phone number');
        $this->assertStringNotContainsString(session()->getId(), $joined, 'the URL leaked the session id');
        $this->assertStringNotContainsString(base64_encode((string) $user->id), $token);

        // Two mints for the same account must differ: the token cannot be
        // derived from anything about the person.
        $second = app(TelegramReturnHandoff::class)->mint($user, '818030', 'ckb');
        $this->assertNotSame($token, $second);
    }

    public function test_the_handoff_introspection_route_cannot_exist_in_production(): void
    {
        /*
         * The browser suite needs the handoff URL, and the only place it
         * exists is a Telegram message the fake API swallowed. The route that
         * exposes it is guarded by environment; this test is the guard's
         * guard, because a testing convenience that leaked into production
         * would hand out a live one-time credential.
         */
        $source = file_get_contents(base_path('app/Modules/Identity/Routes/web.php'));

        $this->assertStringContainsString("app()->environment(['testing', 'local'])", $source,
            'the introspection route is not environment-guarded');
        $this->assertStringNotContainsString("Route::get('/__testing__", explode("app()->environment(['testing', 'local'])", $source)[0],
            'an introspection route is registered outside the environment guard');

        // And the mint side records nothing outside those environments.
        $mint = file_get_contents(base_path('app/Modules/Identity/Services/TelegramReturnHandoff.php'));
        $this->assertStringContainsString("app()->environment(['testing', 'local'])", $mint);
    }

    // ====== BLOCKER 3: release() is independently fail-closed ================

    public function test_release_refuses_content_attached_after_assessment(): void
    {
        $this->register('ckb', '07507770001');
        $this->makeUnreachable();
        $user = $this->continueInBrowser();
        User::query()->update(['created_at' => now()->subDays(30)]);
        $user = $user->fresh();

        $policy = app(AbandonedAccountPolicy::class);

        // 1. assessed as eligible while empty
        $this->assertTrue($policy->assess($user)['eligible']);

        // 2. content attached AFTER that assessment
        DB::table('saved_searches')->insert([
            'user_id' => $user->id,
            'name' => 'Attached after assessment',
            'criteria' => json_encode(['q' => 'x']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 3-5. release() must refuse on its own, and change nothing.
        $this->assertFalse($policy->release($user, 'test'), 'release deleted an account that had gained content');
        $this->assertNotNull(User::query()->find($user->id));
        $this->assertNotNull(User::query()->find($user->id)->phone_index, 'the phone index was cleared anyway');
        $this->assertSame(1, DB::table('saved_searches')->where('user_id', $user->id)->count());
    }

    public function test_release_called_directly_on_an_account_with_content_never_deletes_it(): void
    {
        $this->register('ckb', '07507770002');
        $this->makeUnreachable();
        $user = $this->continueInBrowser();

        DB::table('saved_searches')->insert([
            'user_id' => $user->id,
            'name' => 'Owned',
            'criteria' => json_encode(['q' => 'y']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // No assess() call at all: release must not trust a caller.
        $this->assertFalse(app(AbandonedAccountPolicy::class)->release($user->fresh(), 'test'));
        $this->assertNotNull(User::query()->find($user->id));
    }

    // ====== BLOCKER 4: --hours actually controls eligibility =================

    public function test_the_configured_default_window_applies_when_no_override_is_given(): void
    {
        config(['mulkihawler.registration.unlinked_retention_hours' => 72]);
        $this->register('ckb', '07507770010');
        $this->makeUnreachable();
        User::query()->update(['created_at' => now()->subHours(80)]);

        $this->artisan(PruneUnlinkedAccounts::class)->assertSuccessful();
        $this->assertSame(0, User::query()->count());
    }

    public function test_a_short_hours_override_reclaims_what_the_configured_window_would_keep(): void
    {
        config(['mulkihawler.registration.unlinked_retention_hours' => 72]);
        $this->register('ckb', '07507770011');
        $this->makeUnreachable();
        User::query()->update(['created_at' => now()->subHours(3)]);

        // The configured window keeps it...
        $this->artisan(PruneUnlinkedAccounts::class)->assertSuccessful();
        $this->assertSame(1, User::query()->count());

        // ...and the override must actually reach the policy.
        $this->artisan(PruneUnlinkedAccounts::class, ['--hours' => 1])->assertSuccessful();
        $this->assertSame(0, User::query()->count(), '--hours did not control eligibility');
    }

    public function test_a_long_hours_override_keeps_what_the_configured_window_would_reclaim(): void
    {
        config(['mulkihawler.registration.unlinked_retention_hours' => 72]);
        $this->register('ckb', '07507770012');
        $this->makeUnreachable();
        User::query()->update(['created_at' => now()->subHours(100)]);

        $this->artisan(PruneUnlinkedAccounts::class, ['--hours' => 168])->assertSuccessful();
        $this->assertSame(1, User::query()->count(), 'a longer override did not protect the account');
    }

    public function test_the_retention_boundary_is_respected_exactly(): void
    {
        $this->register('ckb', '07507770013');
        $this->makeUnreachable();

        // Just inside the window: kept.
        User::query()->update(['created_at' => now()->subHours(24)->addMinutes(5)]);
        $this->artisan(PruneUnlinkedAccounts::class, ['--hours' => 24])->assertSuccessful();
        $this->assertSame(1, User::query()->count());

        // Just outside: reclaimed.
        User::query()->update(['created_at' => now()->subHours(24)->subMinutes(5)]);
        $this->artisan(PruneUnlinkedAccounts::class, ['--hours' => 24])->assertSuccessful();
        $this->assertSame(0, User::query()->count());
    }

    public function test_dry_run_uses_the_same_override_and_changes_nothing(): void
    {
        $this->register('ckb', '07507770014');
        $this->makeUnreachable();
        User::query()->update(['created_at' => now()->subHours(3)]);

        $this->artisan(PruneUnlinkedAccounts::class, ['--hours' => 1, '--dry-run' => true])
            ->expectsOutputToContain('Would reclaim 1')
            ->assertSuccessful();

        $this->assertSame(1, User::query()->count(), 'a dry run deleted something');
    }

    public function test_the_command_reports_the_policy_it_actually_applied(): void
    {
        $this->artisan(PruneUnlinkedAccounts::class, ['--hours' => 5])
            ->expectsOutputToContain('retention 5h (--hours override)')
            ->assertSuccessful();

        $this->artisan(PruneUnlinkedAccounts::class)
            ->expectsOutputToContain('configured default')
            ->assertSuccessful();
    }

    public function test_the_override_never_overrides_the_linked_or_owned_refusals(): void
    {
        $linked = User::factory()->create([
            'telegram_id' => '818040',
            'telegram_verified_at' => now(),
            'created_at' => now()->subYear(),
        ]);

        $this->register('ckb', '07507770015');
        $this->makeUnreachable();
        $owner = $this->continueInBrowser();
        User::query()->where('id', $owner->id)->update(['created_at' => now()->subYear()]);
        DB::table('saved_searches')->insert([
            'user_id' => $owner->id, 'name' => 'Keep me',
            'criteria' => json_encode(['q' => 'z']), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->artisan(PruneUnlinkedAccounts::class, ['--hours' => 1])->assertSuccessful();

        $this->assertNotNull(User::query()->find($linked->id), 'an aggressive override deleted a linked account');
        $this->assertNotNull(User::query()->find($owner->id), 'an aggressive override deleted an account with content');
    }

    public function test_a_reclaimed_phone_number_can_register_again(): void
    {
        $this->register('ckb', '07501112233');

        // The FIRST registration is the one the sweep must be able to reclaim,
        // so it is the one reduced to the legacy unreachable shape. The second
        // registration below is a live account and is deliberately left alone.
        $this->makeUnreachable();

        User::query()->update(['created_at' => now()->subDays(30)]);

        Auth::logout();
        $this->flushSession();
        Cache::flush();

        $this->artisan(PruneUnlinkedAccounts::class, ['--hours' => 72])->assertSuccessful();
        $this->assertSame(0, User::query()->count());

        $this->register('ckb', '07501112233');

        $this->assertSame(1, User::query()->count());
        $this->assertTrue(Auth::check());
    }
}
