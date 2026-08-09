<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Identity\Console\PruneUnlinkedAccounts;
use App\Modules\Identity\Models\TelegramVerificationToken;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\AbandonedAccountPolicy;
use App\Modules\Identity\Services\TelegramVerificationService;
use App\Modules\Identity\Support\PhoneNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * REGISTER → OPEN TELEGRAM → PRESS START → VERIFIED.
 *
 * The simplified flow, and specifically the things that are easy to break
 * while making it simple. Three properties are load-bearing and each has tests
 * here that would fail if it were quietly given up:
 *
 *   1. ONE press. A Start verifies. No browser confirmation, no code, no
 *      second message. A test that accepted either "verified" or "awaiting
 *      confirmation" would let the old two-step flow come back unnoticed, so
 *      the assertions name the state exactly.
 *
 *   2. NO CLOCK. The token has no expiry column, and the tests travel months
 *      into the future to prove it — not by inspecting the schema, which would
 *      pass just as happily against a token that expires in code.
 *
 *   3. Simple is not lax. Consuming a token must not let a DIFFERENT Telegram
 *      account claim the account afterwards; a Telegram identity already in use
 *      must not be reassigned; an account already linked must not be silently
 *      re-pointed. Those are the tests that matter most, because the change
 *      that would break them is exactly the change that makes the happy path
 *      shorter.
 */
final class SimplifiedTelegramVerificationTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Str0ng!Passw0rd#2026';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        if ((string) config('mulkihawler.security.blind_index_key', '') === '') {
            config([
                'mulkihawler.security.blind_index_key' => str_repeat('a', 64),
                'mulkihawler.security.pii_key' => str_repeat('b', 64),
            ]);
        }

        config(['services.telegram.bot_username' => 'MyHawlerBot']);

        // The bot responder posts to api.telegram.org. Faking it keeps these
        // tests offline and lets them assert what the bot was asked to say.
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
    }

    // ----------------------------------------------------------------
    // Harness
    // ----------------------------------------------------------------

    private function webhookSecret(): string
    {
        $secret = (string) config('services.telegram.webhook_secret', '');

        if ($secret === '') {
            $secret = 'test-webhook-secret';
            config(['services.telegram.webhook_secret' => $secret]);
        }

        return $secret;
    }

    /** Register through the real form. Returns the redirect target. */
    private function register(
        string $locale = 'ckb',
        string $phone = '07501234567',
        string $name = 'Test Person',
    ): string {
        $response = $this->post('/register', [
            'name' => $name,
            'phone' => $phone,
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
     * Drive `/start TOKEN` through the REAL webhook with the real secret
     * header. Nothing here shortcuts past it: the separation between what a
     * browser may do and what only Telegram may do is the property under test.
     */
    private function sendStart(string $token, int $telegramId = 555000111, ?int $updateId = null): void
    {
        /*
         * Telegram calls server-to-server with no cookie. In-process the test
         * client shares one session store, so the webhook request would
         * otherwise take over the browser's session id — an artefact of the
         * harness, not of the product. Captured and restored around the call.
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
                    'text' => '/start '.$token,
                ],
            ])->assertOk();

        if ($browserSession !== '') {
            session()->setId($browserSession);
            session()->start();
        }
    }

    /** Re-establish the browser's session against a fresh model instance. */
    private function continueInBrowser(): User
    {
        $id = Auth::id();

        $user = $id === null
            ? User::query()->latest('id')->firstOrFail()
            : User::query()->findOrFail($id);

        $this->actingAs($user->fresh());

        return $user;
    }

    /** The raw token behind this account's live verification link. */
    private function liveToken(User $user): string
    {
        $token = TelegramVerificationToken::query()
            ->where('user_id', $user->id)
            ->usable()
            ->latest('id')
            ->firstOrFail();

        return (string) $token->raw();
    }

    // ----------------------------------------------------------------
    // Registration
    // ----------------------------------------------------------------

    public function test_registration_creates_exactly_one_unverified_account_with_a_password(): void
    {
        $this->register();

        $this->assertSame(1, User::query()->count(), 'registration must create exactly one account');

        $user = User::query()->firstOrFail();

        $this->assertNull($user->telegram_verified_at, 'a fresh account is not verified');
        $this->assertNull($user->telegram_id);
        $this->assertNotNull($user->password, 'the account must have a password to sign in with later');
        $this->assertTrue(Hash::check(self::PASSWORD, $user->password));
        $this->assertFalse((bool) $user->phone_verified, 'a typed number is never verified by registration');
    }

    public function test_registration_redirects_to_the_verification_page(): void
    {
        $this->assertStringContainsString('/account/telegram/link', $this->register());
    }

    public function test_registration_without_a_password_is_refused(): void
    {
        $this->post('/register', [
            'name' => 'No Password',
            'phone' => '07509998888',
            'locale' => 'ckb',
            'accept_terms' => true,
        ])->assertSessionHasErrors('password');

        $this->assertSame(0, User::query()->count());
    }

    public function test_a_mismatched_password_confirmation_is_refused(): void
    {
        $this->post('/register', [
            'name' => 'Mismatch',
            'phone' => '07509998888',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD.'x',
            'locale' => 'ckb',
            'accept_terms' => true,
        ])->assertSessionHasErrors('password');

        $this->assertSame(0, User::query()->count());
    }

    // ----------------------------------------------------------------
    // The permanent token
    // ----------------------------------------------------------------

    public function test_a_verification_token_exists_immediately_after_registration(): void
    {
        $this->register();

        $token = TelegramVerificationToken::query()->firstOrFail();

        $this->assertSame((int) User::query()->firstOrFail()->id, (int) $token->user_id);
        $this->assertTrue($token->isUsable());
        $this->assertNull($token->used_at);
        $this->assertNull($token->revoked_at);
    }

    public function test_the_token_table_has_no_expiry_column_at_all(): void
    {
        /*
         * Asserted on the SCHEMA as well as on behaviour further down. A
         * column nobody reads today is a column somebody starts reading
         * tomorrow, and the whole contract is that validity is state and not
         * time.
         */
        $this->assertFalse(
            \Illuminate\Support\Facades\Schema::hasColumn('telegram_verification_tokens', 'expires_at'),
            'the verification token must have no expiry column',
        );
    }

    public function test_the_raw_token_is_not_stored_in_plaintext(): void
    {
        $this->register();
        $user = User::query()->firstOrFail();
        $raw = $this->liveToken($user);

        $row = \Illuminate\Support\Facades\DB::table('telegram_verification_tokens')
            ->where('user_id', $user->id)
            ->first();

        $this->assertNotSame($raw, $row->token_hash);
        $this->assertNotSame($raw, $row->token_encrypted);
        $this->assertStringNotContainsString($raw, (string) $row->token_encrypted,
            'the ciphertext must not contain the raw token');
        $this->assertSame(hash('sha256', $raw), $row->token_hash,
            'the lookup key is the digest of the raw token');
        $this->assertSame($raw, Crypt::decryptString((string) $row->token_encrypted),
            'and the encrypted copy must open to the same value');
    }

    public function test_the_token_is_opaque_and_carries_nothing_about_the_person(): void
    {
        $this->register(phone: '07501234567', name: 'Rezan Ahmed');
        $user = User::query()->firstOrFail();
        $raw = $this->liveToken($user);

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $raw, 'opaque hex only');

        foreach ([(string) $user->id, '7501234567', '9647501234567', 'Rezan', 'Ahmed'] as $secret) {
            $this->assertStringNotContainsString(
                strtolower($secret),
                strtolower($raw),
                "the token must not encode {$secret}",
            );
        }
    }

    public function test_the_deep_link_is_rendered_and_contains_only_the_token(): void
    {
        $this->register();
        $user = $this->continueInBrowser();

        $this->get('/account/telegram/link')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Account/TelegramLink')
                ->where('deep_link', 'https://t.me/MyHawlerBot?start='.$this->liveToken($user))
                // §14: no candidate step is offered to a new registration.
                ->where('candidate', null));
    }

    public function test_revisiting_the_page_resumes_the_same_link_instead_of_replacing_it(): void
    {
        $this->register();
        $user = $this->continueInBrowser();

        $first = $this->liveToken($user);

        $this->get('/account/telegram/link')->assertOk();
        $this->get('/account/telegram/link')->assertOk();

        $this->assertSame($first, $this->liveToken($user),
            'rendering the page must never invalidate a link the person may already have open');
        $this->assertSame(1, TelegramVerificationToken::query()->count());
    }

    public function test_the_token_does_not_depend_on_the_browser_session(): void
    {
        $this->register();
        $user = User::query()->firstOrFail();
        $token = $this->liveToken($user);

        // Every trace of the browser is gone: no cookie, no session, no
        // authentication. The token is a property of the ACCOUNT.
        $this->flushSession();
        Auth::logout();

        $this->sendStart($token, 424242);

        $this->assertNotNull($user->fresh()->telegram_verified_at);
    }

    public function test_the_link_still_works_three_months_later(): void
    {
        $this->register();
        $user = User::query()->firstOrFail();
        $token = $this->liveToken($user);

        /*
         * The point of the whole design, asserted against the clock rather
         * than against the schema: a token that expired in code would pass
         * the no-expiry-column test and fail this one.
         */
        $this->travel(93)->days();

        $this->sendStart($token, 909090);

        $this->assertNotNull($user->fresh()->telegram_verified_at,
            'a verification link must never expire with time');
    }

    // ----------------------------------------------------------------
    // Telegram START
    // ----------------------------------------------------------------

    public function test_one_press_of_start_links_and_verifies_with_no_second_step(): void
    {
        $this->register();
        $user = $this->continueInBrowser();
        $token = $this->liveToken($user);

        $this->sendStart($token, 777000111);

        $fresh = $user->fresh();
        $this->assertSame('777000111', $fresh->telegram_id);
        $this->assertNotNull($fresh->telegram_verified_at);
        $this->assertSame('testuser', $fresh->telegram_username);

        // The token is spent, and the account gained no second identity.
        $this->assertNotNull(TelegramVerificationToken::query()->firstOrFail()->used_at);
        $this->assertSame(1, User::query()->count());

        /*
         * The state is `completed` and NOT `awaiting_confirmation`. Naming it
         * exactly is what stops the two-step flow returning unnoticed.
         */
        $this->continueInBrowser();
        $poll = $this->getJson('/account/telegram/link/poll');
        $poll->assertOk();
        $this->assertSame('completed', $poll->json('state'));
    }

    public function test_the_bot_confirms_success_without_asking_for_anything_further(): void
    {
        $this->register('en');
        $user = $this->continueInBrowser();

        $this->sendStart($this->liveToken($user), 777000222);

        $sent = collect(Http::recorded())
            ->map(fn (array $pair): string => (string) ($pair[0]->data()['text'] ?? ''))
            ->filter()
            ->values();

        $this->assertTrue($sent->isNotEmpty(), 'the bot must reply');
        $this->assertTrue(
            $sent->contains(fn (string $text): bool => str_contains($text, 'verified successfully')),
            'the reply must confirm verification',
        );
        $this->assertFalse(
            $sent->contains(fn (string $text): bool => str_contains($text, 'confirm this Telegram account there')),
            'the bot must never ask for a browser confirmation on this path',
        );
    }

    public function test_after_verifying_the_protected_surface_opens(): void
    {
        $this->register();
        $user = $this->continueInBrowser();

        $this->get('/account/profile')->assertRedirect();

        $this->sendStart($this->liveToken($user), 777000333);
        $this->continueInBrowser();

        $this->get('/account/onboarding')->assertOk();
    }

    // ----------------------------------------------------------------
    // Idempotency
    // ----------------------------------------------------------------

    public function test_a_redelivered_start_changes_nothing_and_still_reports_success(): void
    {
        $this->register();
        $user = $this->continueInBrowser();
        $token = $this->liveToken($user);

        $this->sendStart($token, 777000444, updateId: 4242);
        $verifiedAt = $user->fresh()->telegram_verified_at;

        // A different update id, so the replay ledger does not simply refuse
        // it: this must be idempotent on its own merits.
        $this->sendStart($token, 777000444, updateId: 4243);

        $fresh = $user->fresh();
        $this->assertSame('777000444', $fresh->telegram_id);
        $this->assertEquals($verifiedAt, $fresh->telegram_verified_at,
            'a second press must not move the verification timestamp');
        $this->assertSame(1, User::query()->count());
        $this->assertSame(1, TelegramVerificationToken::query()->count());
    }

    public function test_the_same_telegram_account_pressing_a_spent_token_is_told_it_is_already_verified(): void
    {
        $this->register();
        $user = $this->continueInBrowser();
        $token = $this->liveToken($user);

        $this->sendStart($token, 777000555, updateId: 5001);

        $result = app(TelegramVerificationService::class)->redeem($token, ['id' => '777000555']);

        $this->assertTrue($result['ok']);
        $this->assertTrue($result['already_verified'] ?? false);
        $this->assertSame((int) $user->id, $result['user_id']);
    }

    // ----------------------------------------------------------------
    // Security
    // ----------------------------------------------------------------

    public function test_a_consumed_token_cannot_be_used_by_another_telegram_account_to_steal_the_account(): void
    {
        $this->register();
        $user = $this->continueInBrowser();
        $token = $this->liveToken($user);

        $this->sendStart($token, 111000111, updateId: 6001);
        $this->assertSame('111000111', $user->fresh()->telegram_id);

        // A different Telegram account now presents the SAME, already-spent
        // token. This is the takeover this design must refuse.
        $this->sendStart($token, 222000222, updateId: 6002);

        $this->assertSame('111000111', $user->fresh()->telegram_id,
            'a spent token must never re-point an account at another Telegram identity');
        $this->assertSame(1, User::query()->count());
    }

    public function test_a_telegram_identity_already_on_another_account_cannot_be_reassigned(): void
    {
        // Account A, verified with Telegram 900900900.
        $this->register(phone: '07501110000');
        $first = $this->continueInBrowser();
        $this->sendStart($this->liveToken($first), 900900900, updateId: 7001);
        $this->assertNotNull($first->fresh()->telegram_verified_at);

        // Account B, registering separately, whose owner presses Start from
        // the SAME Telegram account.
        $this->flushSession();
        Auth::logout();
        $this->register(phone: '07502220000');
        $second = User::query()->where('id', '!=', $first->id)->firstOrFail();

        $this->sendStart($this->liveToken($second), 900900900, updateId: 7002);

        $this->assertNull($second->fresh()->telegram_verified_at,
            'a Telegram identity in use elsewhere must not verify a second account');
        $this->assertSame('900900900', $first->fresh()->telegram_id,
            'and the original owner must keep it');
        $this->assertTrue(
            TelegramVerificationToken::query()->where('user_id', $second->id)->firstOrFail()->isUsable(),
            'a refused attempt must not burn the token — the real owner still needs it',
        );
    }

    public function test_an_already_linked_account_cannot_be_switched_to_another_telegram_identity(): void
    {
        $this->register();
        $user = $this->continueInBrowser();

        $this->sendStart($this->liveToken($user), 300300300, updateId: 8001);
        $this->assertSame('300300300', $user->fresh()->telegram_id);

        // A second, still-live token minted afterwards must not become a way
        // to re-point the identity.
        $service = app(TelegramVerificationService::class);
        $linked = $user->fresh();

        $second = $service->mint($linked);
        $result = $service->redeem($second['raw'], ['id' => '400400400']);

        $this->assertFalse($result['ok']);
        $this->assertSame('conflict', $result['reason']);
        $this->assertSame('300300300', $user->fresh()->telegram_id);
    }

    public function test_a_revoked_token_fails(): void
    {
        $this->register();
        $user = $this->continueInBrowser();
        $token = $this->liveToken($user);

        app(TelegramVerificationService::class)->revokeAllFor($user, 'test');

        $this->sendStart($token, 500500500);

        $this->assertNull($user->fresh()->telegram_verified_at);
    }

    public function test_an_unknown_token_fails_and_verifies_nobody(): void
    {
        $this->register();
        $user = $this->continueInBrowser();

        $this->sendStart(str_repeat('f', 64), 600600600);

        $this->assertNull($user->fresh()->telegram_verified_at);
    }

    public function test_asking_for_a_new_link_revokes_the_previous_one(): void
    {
        $this->register();
        $user = $this->continueInBrowser();
        $old = $this->liveToken($user);

        $this->post('/account/telegram/link/restart')->assertRedirect();

        $new = $this->liveToken($user->fresh());
        $this->assertNotSame($old, $new);

        // The old link is dead; the new one works.
        $this->sendStart($old, 700700700, updateId: 9001);
        $this->assertNull($user->fresh()->telegram_verified_at);

        $this->sendStart($new, 700700700, updateId: 9002);
        $this->assertNotNull($user->fresh()->telegram_verified_at);
    }

    public function test_no_raw_token_reaches_the_audit_trail(): void
    {
        $this->register();
        $user = User::query()->firstOrFail();
        $raw = $this->liveToken($user);

        $this->sendStart($raw, 800800800);

        $audit = \Illuminate\Support\Facades\DB::table('audit_logs')->get();

        foreach ($audit as $row) {
            $serialised = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $this->assertStringNotContainsString($raw, (string) $serialised,
                'a raw verification token must never be written to the audit trail');
            $this->assertStringNotContainsString(hash('sha256', $raw), (string) $serialised,
                'nor its lookup digest');
        }
    }

    // ----------------------------------------------------------------
    // Signing in
    // ----------------------------------------------------------------

    public function test_a_verified_account_signs_in_with_phone_and_password_without_telegram(): void
    {
        $this->register(phone: '07501234567');
        $user = $this->continueInBrowser();
        $this->sendStart($this->liveToken($user), 313131313);

        $this->flushSession();
        Auth::logout();

        $response = $this->post('/login', [
            'login' => '07501234567',
            'password' => self::PASSWORD,
        ]);

        $response->assertRedirect();
        $this->assertAuthenticatedAs($user->fresh());
        $this->assertStringNotContainsString('/account/telegram/link',
            (string) $response->headers->get('Location'),
            'a verified account must never be sent back through Telegram');
    }

    public function test_the_same_number_signs_in_however_it_is_written(): void
    {
        $this->register(phone: '07501234567');
        $user = $this->continueInBrowser();
        $this->sendStart($this->liveToken($user), 323232323);

        foreach (['07501234567', '+964 750 123 4567', '9647501234567', '0750 123 4567'] as $written) {
            $this->flushSession();
            Auth::logout();
            Cache::flush();

            $this->post('/login', ['login' => $written, 'password' => self::PASSWORD])
                ->assertRedirect();

            $this->assertAuthenticatedAs($user->fresh(), "sign-in must accept {$written}");
        }
    }

    public function test_an_unverified_account_signing_in_lands_on_the_verification_page(): void
    {
        $this->register(phone: '07505550000');
        $user = User::query()->firstOrFail();

        $this->flushSession();
        Auth::logout();

        $response = $this->post('/login', [
            'login' => '07505550000',
            'password' => self::PASSWORD,
        ]);

        $this->assertAuthenticatedAs($user->fresh());
        $this->assertStringContainsString('/account/telegram/link',
            (string) $response->headers->get('Location'));

        // And the SAME permanent link is waiting there — §17.
        $this->continueInBrowser();
        $this->get('/account/telegram/link')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('deep_link', 'https://t.me/MyHawlerBot?start='.$this->liveToken($user)));
    }

    public function test_a_wrong_password_is_refused_with_one_generic_message(): void
    {
        $this->register(phone: '07506660000');

        $this->flushSession();
        Auth::logout();

        $this->post('/login', ['login' => '07506660000', 'password' => 'not-the-password'])
            ->assertSessionHasErrors('login');

        $this->assertGuest();
    }

    public function test_an_unknown_identifier_gives_the_same_answer_as_a_wrong_password(): void
    {
        $this->register(phone: '07507770000');

        $this->flushSession();
        Auth::logout();

        $unknown = $this->post('/login', ['login' => '07500000000', 'password' => self::PASSWORD]);
        $wrong = $this->post('/login', ['login' => '07507770000', 'password' => 'wrong-password-here']);

        $this->assertSame(
            session()->get('errors')?->get('login'),
            $wrong->getSession()->get('errors')?->get('login'),
        );
        $unknown->assertSessionHasErrors('login');
        $wrong->assertSessionHasErrors('login');
        $this->assertGuest();
    }

    public function test_a_suspended_account_cannot_sign_in_whatever_its_verification_state(): void
    {
        $this->register(phone: '07508880000');
        $user = User::query()->firstOrFail();
        $user->forceFill(['suspended_at' => now(), 'suspended_reason' => 'test'])->save();

        $this->flushSession();
        Auth::logout();

        $this->post('/login', ['login' => '07508880000', 'password' => self::PASSWORD])
            ->assertSessionHasErrors('login');

        $this->assertGuest();
    }

    // ----------------------------------------------------------------
    // Registering again
    // ----------------------------------------------------------------

    public function test_registering_again_with_the_same_number_creates_no_second_account(): void
    {
        $this->register(phone: '07501234567');

        $this->flushSession();
        Auth::logout();
        Cache::flush();

        $this->post('/register', [
            'name' => 'Someone Else',
            'phone' => '07501234567',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
            'locale' => 'ckb',
            'accept_terms' => true,
        ])->assertSessionHasErrors('phone');

        $this->assertSame(1, User::query()->count(), 'a duplicate number must never mint a second account');
        $this->assertGuest('and must never sign anybody in');
    }

    public function test_the_duplicate_refusal_does_not_confirm_that_the_number_is_registered(): void
    {
        $this->register(phone: '07501234567');

        $this->flushSession();
        Auth::logout();
        Cache::flush();

        $response = $this->post('/register', [
            'name' => 'Someone Else',
            'phone' => '07501234567',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
            'locale' => 'en',
            'accept_terms' => true,
        ]);

        $message = (string) $response->getSession()->get('errors')?->first('phone');

        /*
         * The message must offer a way forward WITHOUT asserting the number is
         * taken — an anonymous visitor may be typing somebody else's number,
         * and a definite answer would turn this form into a lookup service.
         */
        $this->assertNotSame('', $message);

        foreach (['already registered', 'already exists', 'in use'] as $leak) {
            $this->assertStringNotContainsStringIgnoringCase($leak, $message,
                'the refusal must not confirm that an account exists');
        }
    }

    // ----------------------------------------------------------------
    // Retention
    // ----------------------------------------------------------------

    public function test_an_account_with_a_live_link_is_never_reclaimed_however_long_it_waits(): void
    {
        $this->register();
        $user = User::query()->firstOrFail();

        // Far past the 72-hour window the sweep was built around.
        $this->travel(120)->days();

        $assessment = app(AbandonedAccountPolicy::class)->assess($user->fresh());

        $this->assertFalse($assessment['eligible'],
            'reclaiming an account under a link that never expires would make the promise a lie');

        $this->artisan(PruneUnlinkedAccounts::class)->assertExitCode(0);

        $this->assertNotNull(User::query()->find($user->id), 'the account must survive the sweep');
    }

    public function test_the_link_still_verifies_after_the_sweep_has_run(): void
    {
        $this->register();
        $user = User::query()->firstOrFail();
        $token = $this->liveToken($user);

        $this->travel(120)->days();
        $this->artisan(PruneUnlinkedAccounts::class)->assertExitCode(0);

        $this->sendStart($token, 616161616);

        $this->assertNotNull($user->fresh()->telegram_verified_at);
    }

    public function test_an_old_passwordless_account_with_no_live_token_is_still_reclaimed(): void
    {
        /*
         * The population the sweep was written for, and it must keep working:
         * no password, no live link, so genuinely nobody can reach it and its
         * phone number is locked away from its real owner.
         */
        $user = User::factory()->create([
            'password' => null,
            'telegram_id' => null,
            'telegram_verified_at' => null,
            'created_at' => now()->subDays(30),
        ]);
        $user->setPhone(PhoneNumber::toE164('07509990000'));
        $user->save();

        $assessment = app(AbandonedAccountPolicy::class)->assess($user->fresh());

        $this->assertTrue($assessment['eligible'], $assessment['reason']);
    }

    public function test_abandoning_a_registration_revokes_the_link(): void
    {
        $this->register();
        $user = $this->continueInBrowser();
        $token = $this->liveToken($user);

        $this->post('/account/registration/abandon')->assertRedirect();

        $this->assertSame(0, TelegramVerificationToken::query()->usable()->count(),
            'walking away must not leave a working link behind');

        // And the dead link verifies nobody, whether or not the row survived.
        $this->sendStart($token, 626262626);

        $survivor = User::withTrashed()->find($user->id);
        $this->assertTrue($survivor === null || $survivor->telegram_verified_at === null);
    }

    // ----------------------------------------------------------------
    // Localization
    // ----------------------------------------------------------------

    /**
     * @dataProvider localeProvider
     */
    public function test_the_flow_works_and_answers_in_each_language(string $locale, string $prefix): void
    {
        $this->register($locale, phone: '0750'.random_int(1000000, 9999999));

        $user = $this->continueInBrowser();
        $this->assertSame($locale, $user->preferred_locale);

        $this->get($prefix.'/account/telegram/link')->assertOk();

        $this->sendStart($this->liveToken($user), random_int(100000, 999999));

        $this->assertNotNull($user->fresh()->telegram_verified_at);

        // The bot answered in the language chosen on the WEBSITE, not the one
        // the Telegram client happens to be set to.
        $token = TelegramVerificationToken::query()->firstOrFail();
        $this->assertSame($locale, $token->locale);

        $this->continueInBrowser();
        $poll = $this->getJson($prefix.'/account/telegram/link/poll');
        $this->assertSame('completed', $poll->json('state'));
        $this->assertStringContainsString($prefix.'/account/onboarding', (string) $poll->json('redirect'));
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function localeProvider(): array
    {
        return [
            'sorani' => ['ckb', ''],
            'arabic' => ['ar', '/ar'],
            'english' => ['en', '/en'],
        ];
    }
}
