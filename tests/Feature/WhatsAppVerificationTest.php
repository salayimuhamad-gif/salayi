<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Identity\Console\PruneUnlinkedAccounts;
use App\Modules\Identity\Models\TelegramVerificationToken;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Models\WhatsAppOtp;
use App\Modules\Identity\Services\AbandonedAccountPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * REGISTER → CHOOSE WHATSAPP → RECEIVE A CODE → TYPE IT → VERIFIED.
 *
 * The second door, and specifically the properties that keep two doors from
 * being twice the attack surface:
 *
 *   1. ONE SUCCESS IS ENOUGH, AND ONLY ONE EVER HAPPENS. Whichever method
 *      lands first verifies the account; the other's pending material is
 *      retired and resolves as "already verified" — it never stamps a second
 *      verification event, never attaches an identity, and never overwrites
 *      what the winner recorded. Both orders are asserted.
 *
 *   2. THE CODE IS A CREDENTIAL. Stored only as a keyed digest, dead in ten
 *      minutes, burnt after five wrong guesses, single-use, refused once the
 *      phone it was sent to is no longer the phone on the account.
 *
 *   3. WHATSAPP NEVER TOUCHES TELEGRAM STATE. `telegram_id`,
 *      `telegram_username` and `telegram_verified_at` stay exactly as they
 *      were, whatever the WhatsApp flow does — asserted directly, because the
 *      change that would break it is exactly the "helpful" one.
 */
final class WhatsAppVerificationTest extends TestCase
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

        config([
            'services.telegram.bot_username' => 'MyHawlerBot',
            'services.telegram.bot_token' => 'test-bot-token',
            'services.bird.api_key' => 'bk_test_key',
            'services.bird.workspace_id' => 'ws-test',
            'services.bird.channel_id' => 'ch-whatsapp',
            // The raw Channels API references the template by PROJECT id;
            // version, locale and the parameter key ride on their defaults
            // here so the defaults themselves stay pinned.
            'services.bird.otp_template_project_id' => '11111111-2222-3333-4444-555555555555',
        ]);

        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true]),
            // The documented Channels API success: 202 Accepted.
            'api.bird.com/*' => Http::response(['id' => 'msg-1', 'status' => 'accepted'], 202),
        ]);
    }

    // ----------------------------------------------------------------
    // Harness
    // ----------------------------------------------------------------

    /** Register through the real form. Returns the redirect target. */
    private function register(string $locale = 'ckb', string $phone = '07501234567'): string
    {
        $response = $this->post('/register', [
            'name' => 'Test Person',
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

    private function continueInBrowser(): User
    {
        $id = Auth::id();

        $user = $id === null
            ? User::query()->latest('id')->firstOrFail()
            : User::query()->findOrFail($id);

        $this->actingAs($user->fresh());

        return $user;
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

    /** Drive `/start TOKEN` through the REAL webhook, secret header and all. */
    private function sendStart(string $token, int $telegramId = 909000111): void
    {
        $browserSession = session()->getId();

        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', $this->webhookSecret())
            ->postJson('/webhooks/telegram/updates', [
                'update_id' => random_int(1, 2_000_000_000),
                'message' => [
                    'message_id' => 1,
                    'date' => time(),
                    'chat' => ['id' => $telegramId, 'type' => 'private'],
                    'from' => ['id' => $telegramId, 'is_bot' => false, 'first_name' => 'Test'],
                    'text' => '/start '.$token,
                ],
            ])->assertOk();

        if ($browserSession !== '') {
            session()->setId($browserSession);
            session()->start();
        }
    }

    /**
     * The code Bird was just asked to deliver, read from the RECORDED outbound
     * request — the test receives the code the way the person would, so the
     * whole path from mint to template parameter is exercised.
     */
    private function lastDeliveredCode(): string
    {
        $code = null;

        foreach (Http::recorded() as [$request]) {
            if (str_contains($request->url(), 'api.bird.com')) {
                $data = $request->data();
                $code = (string) ($data['template']['parameters'][0]['value'] ?? '');
            }
        }

        $this->assertNotNull($code, 'no OTP was delivered through Bird');
        $this->assertMatchesRegularExpression('/^\d{6}$/', (string) $code);

        return (string) $code;
    }

    /** Register and walk the WhatsApp door up to "code delivered". */
    private function registerAndRequestCode(string $phone = '07501234567'): User
    {
        $this->register('ckb', $phone);
        $user = $this->continueInBrowser();

        $this->post('/account/verify/whatsapp/send')->assertRedirect();

        return $user;
    }

    // ----------------------------------------------------------------
    // The choice page
    // ----------------------------------------------------------------

    public function test_registration_lands_on_a_choice_offering_both_doors(): void
    {
        $location = $this->register();

        $this->assertStringContainsString('/account/verify', $location);

        $this->continueInBrowser();

        $this->get('/account/verify')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Account/VerifyChoice')
                ->where('telegram_available', true)
                ->where('whatsapp_available', true));
    }

    public function test_without_bird_configuration_the_choice_offers_telegram_alone(): void
    {
        config(['services.bird.api_key' => '']);

        $this->register();
        $this->continueInBrowser();

        $this->get('/account/verify')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('telegram_available', true)
                ->where('whatsapp_available', false));

        // The code screen refuses too, back to the choice.
        $this->get('/account/verify/whatsapp')->assertRedirect();

        $this->post('/account/verify/whatsapp/send')
            ->assertRedirect()
            ->assertSessionHasErrors('whatsapp');
    }

    public function test_a_verified_account_is_forwarded_past_the_choice_without_minting_anything(): void
    {
        $this->register();
        $user = $this->continueInBrowser();

        $user->forceFill(['whatsapp_verified_at' => now(), 'phone_verified' => true])->save();
        TelegramVerificationToken::query()->update(['revoked_at' => now()]);

        // The guard caches the instance actingAs() was given; a real request
        // reads the fresh row, so the harness must too.
        $this->actingAs($user->fresh());

        foreach (['/account/verify', '/account/verify/whatsapp', '/account/telegram/link'] as $path) {
            $response = $this->get($path);

            $response->assertRedirect();
            $this->assertStringContainsString('/account/onboarding', (string) $response->headers->get('Location'),
                "{$path} did not forward a verified account");
        }

        // Visiting the Telegram page minted NO fresh token for a verified
        // account — a live token would be a standing second-verification key.
        $this->assertSame(0, TelegramVerificationToken::query()->usable()->count());
    }

    // ----------------------------------------------------------------
    // Sending the code
    // ----------------------------------------------------------------

    public function test_the_outgoing_request_is_byte_exact_against_birds_channels_api_contract(): void
    {
        $this->registerAndRequestCode('07501234567');

        $delivered = null;

        foreach (Http::recorded() as [$request]) {
            if (str_contains($request->url(), 'api.bird.com')) {
                $delivered = $request;
            }
        }

        $this->assertNotNull($delivered, 'nothing was sent through Bird');

        /*
         * The EXACT official Channels API contract, asserted whole rather
         * than field-by-field: one POST to the workspace/channel messages
         * endpoint, the receiver as a WhatsApp contact identified by its
         * E.164 number, and the approved template referenced by PROJECT id +
         * version + locale with the digits as one typed {type,key,value}
         * parameter. A field this shape does not name — an SDK-style slug, a
         * components wrapper — fails this assertion by existing.
         */
        $this->assertSame('POST', $delivered->method());
        $this->assertSame(
            'https://api.bird.com/workspaces/ws-test/channels/ch-whatsapp/messages',
            $delivered->url(),
        );

        $code = (string) ($delivered->data()['template']['parameters'][0]['value'] ?? '');
        $this->assertMatchesRegularExpression('/^\d{6}$/', $code, 'no six-digit code in the payload');

        $this->assertSame([
            'receiver' => [
                'contacts' => [
                    ['identifierValue' => '+9647501234567'],
                ],
            ],
            'template' => [
                'projectId' => '11111111-2222-3333-4444-555555555555',
                'version' => 'latest',
                'locale' => 'en',
                'parameters' => [
                    ['type' => 'string', 'key' => 'code', 'value' => $code],
                ],
            ],
        ], $delivered->data());

        // Authenticated by the workspace's access key, as JSON.
        $this->assertSame('AccessKey bk_test_key', $delivered->header('Authorization')[0] ?? null);
        $this->assertStringContainsString('application/json', (string) ($delivered->header('Content-Type')[0] ?? ''));

        // And the code itself is stored ONLY as a keyed digest.
        $this->assertDatabaseMissing('whatsapp_otps', ['code_hash' => $code]);
        $this->assertDatabaseHas('whatsapp_otps', ['code_hash' => WhatsAppOtp::hashOf($code)]);
    }

    public function test_only_the_documented_202_accepted_counts_as_sent(): void
    {
        /*
         * The Channels API's documented success is 202 Accepted. A different
         * status — here a 200 with a plausible-looking body — is a response
         * the contract never promised, and trusting it would leave a live
         * code the platform cannot prove was ever handed to Bird. Refused,
         * and the minted code is revoked. (Per-test host because stubs
         * resolve first-match-wins against setUp's 202.)
         */
        config(['services.bird.base_url' => 'https://bird-odd.example']);
        Http::fake(['bird-odd.example/*' => Http::response(['id' => 'msg-1', 'status' => 'accepted'], 200)]);

        $this->register();
        $this->continueInBrowser();

        $this->post('/account/verify/whatsapp/send')
            ->assertRedirect()
            ->assertSessionHasErrors('whatsapp');

        $this->assertSame(0, WhatsAppOtp::query()->usable()->count());
        $this->assertSame(1, WhatsAppOtp::query()->whereNotNull('revoked_at')->count());
    }

    public function test_an_immediate_resend_is_refused_by_the_cooldown_and_the_first_code_survives(): void
    {
        $this->registerAndRequestCode();

        $this->post('/account/verify/whatsapp/send')->assertRedirect();

        // One live code, and exactly one delivery went out.
        $this->assertSame(1, WhatsAppOtp::query()->usable()->count());

        $birdCalls = 0;

        foreach (Http::recorded() as [$request]) {
            if (str_contains($request->url(), 'api.bird.com')) {
                $birdCalls++;
            }
        }

        $this->assertSame(1, $birdCalls, 'the cooldown did not stop a second paid delivery');
    }

    public function test_a_delivery_failure_revokes_the_minted_code(): void
    {
        /*
         * Stubs resolve first-match-wins in registration order, so re-faking
         * setUp's api.bird.com pattern would never be consulted. Pointing the
         * client at a host only THIS test stubs makes the failure real.
         */
        config(['services.bird.base_url' => 'https://bird-down.example']);
        Http::fake(['bird-down.example/*' => Http::response(['error' => 'nope'], 500)]);

        $this->register();
        $this->continueInBrowser();

        $this->post('/account/verify/whatsapp/send')
            ->assertRedirect()
            ->assertSessionHasErrors('whatsapp');

        // No live code is left behind pretending it was delivered.
        $this->assertSame(0, WhatsAppOtp::query()->usable()->count());
        $this->assertSame(1, WhatsAppOtp::query()->whereNotNull('revoked_at')->count());
    }

    // ----------------------------------------------------------------
    // Redeeming the code
    // ----------------------------------------------------------------

    public function test_typing_the_delivered_code_verifies_the_account_and_opens_the_gates(): void
    {
        $user = $this->registerAndRequestCode();
        $code = $this->lastDeliveredCode();

        $sessionBefore = session()->getId();

        $response = $this->post('/account/verify/whatsapp/confirm', ['code' => $code]);

        $response->assertRedirect();
        $this->assertStringContainsString('/account/onboarding', (string) $response->headers->get('Location'));

        // Privilege change → fresh session id, like every sibling flow.
        $this->assertNotSame($sessionBefore, session()->getId(), 'the session survived verification');

        $fresh = $user->fresh();

        // The two claims this method proves…
        $this->assertNotNull($fresh->whatsapp_verified_at);
        $this->assertTrue((bool) $fresh->phone_verified);
        $this->assertSame('whatsapp', $fresh->verificationMethod());

        // …and NOT the two it does not: no Telegram identity was invented.
        $this->assertNull($fresh->telegram_id);
        $this->assertNull($fresh->telegram_verified_at);

        // The code is spent, and the OTHER method's pending token is retired,
        // so neither can ever produce a second verification event.
        $this->assertSame(0, WhatsAppOtp::query()->usable()->count());
        $this->assertSame(0, TelegramVerificationToken::query()->usable()->count());

        // The personal surfaces open — both gates. Re-established the way a
        // browser continues: the guard's cached pre-verification instance is
        // a harness artefact, not a session.
        $this->actingAs($user->fresh());
        $this->get('/account/onboarding')->assertOk();
        $this->get('/account/profile')->assertOk();
    }

    public function test_a_wrong_code_is_refused_and_five_wrong_tries_burn_the_challenge(): void
    {
        $user = $this->registerAndRequestCode();
        $code = $this->lastDeliveredCode();

        $wrong = $code === '000000' ? '111111' : '000000';

        foreach (range(1, WhatsAppOtp::MAX_ATTEMPTS) as $attempt) {
            $this->post('/account/verify/whatsapp/confirm', ['code' => $wrong])
                ->assertRedirect()
                ->assertSessionHasErrors('code');

            $this->assertNull($user->fresh()->whatsapp_verified_at,
                "a wrong code verified the account on attempt {$attempt}");
        }

        // The budget is spent: even the RIGHT code is dead now.
        $this->post('/account/verify/whatsapp/confirm', ['code' => $code])
            ->assertRedirect()
            ->assertSessionHasErrors('code');

        $fresh = $user->fresh();

        $this->assertNull($fresh->whatsapp_verified_at, 'a burnt challenge still verified the account');
        $this->assertFalse((bool) $fresh->phone_verified);
        $this->assertSame(0, WhatsAppOtp::query()->usable()->count());
    }

    public function test_an_expired_code_is_refused(): void
    {
        $user = $this->registerAndRequestCode();
        $code = $this->lastDeliveredCode();

        $this->travel(WhatsAppOtp::TTL_SECONDS + 60)->seconds();

        $this->post('/account/verify/whatsapp/confirm', ['code' => $code])
            ->assertRedirect()
            ->assertSessionHasErrors('code');

        $this->assertNull($user->fresh()->whatsapp_verified_at, 'an expired code verified the account');

        $this->travelBack();
    }

    public function test_a_code_sent_to_a_number_the_account_no_longer_holds_is_refused(): void
    {
        $user = $this->registerAndRequestCode('07501234567');
        $code = $this->lastDeliveredCode();

        // The account's number changes between delivery and redemption.
        $fresh = $user->fresh();
        $fresh->setPhone('+9647509999999');
        $fresh->save();

        $this->post('/account/verify/whatsapp/confirm', ['code' => $code])
            ->assertRedirect()
            ->assertSessionHasErrors('code');

        $after = $user->fresh();

        $this->assertNull($after->whatsapp_verified_at,
            'a code proved possession of a number the account no longer holds');
        $this->assertFalse((bool) $after->phone_verified);
    }

    public function test_a_consumed_code_never_verifies_a_second_time(): void
    {
        $user = $this->registerAndRequestCode();
        $code = $this->lastDeliveredCode();

        $this->post('/account/verify/whatsapp/confirm', ['code' => $code])->assertRedirect();

        $stampedAt = $user->fresh()->whatsapp_verified_at;
        $this->assertNotNull($stampedAt);

        $this->travel(1)->minutes();

        // Typing the same code again resolves as already-verified: the person
        // moves on, and the ORIGINAL stamp is untouched — no second event.
        $replay = $this->post('/account/verify/whatsapp/confirm', ['code' => $code]);

        $replay->assertRedirect();
        $this->assertStringContainsString('/account/onboarding', (string) $replay->headers->get('Location'));

        $this->assertTrue($stampedAt->equalTo($user->fresh()->whatsapp_verified_at),
            'the replay re-stamped the verification');
        $this->assertSame(1, WhatsAppOtp::query()->whereNotNull('consumed_at')->count());

        $this->travelBack();
    }

    // ----------------------------------------------------------------
    // The two doors against each other
    // ----------------------------------------------------------------

    public function test_pressing_start_after_a_whatsapp_verification_resolves_as_already_verified(): void
    {
        // The Telegram token minted at registration, captured BEFORE WhatsApp
        // wins — this is the link sitting in the person's chat.
        $this->register();
        $user = $this->continueInBrowser();

        $token = (string) TelegramVerificationToken::query()
            ->where('user_id', $user->id)
            ->usable()
            ->firstOrFail()
            ->raw();

        $this->post('/account/verify/whatsapp/send')->assertRedirect();
        $this->post('/account/verify/whatsapp/confirm', ['code' => $this->lastDeliveredCode()])
            ->assertRedirect();

        $verifiedAt = $user->fresh()->whatsapp_verified_at;
        $this->assertNotNull($verifiedAt);

        // Now the old link is pressed anyway.
        $this->sendStart($token, 909000222);

        $fresh = $user->fresh();

        // No second verification event, no identity invented, no overwrite:
        // the account is exactly as WhatsApp left it.
        $this->assertNull($fresh->telegram_id, 'a spent journey attached a Telegram identity');
        $this->assertNull($fresh->telegram_verified_at, 'a second verification event was stamped');
        $this->assertTrue($verifiedAt->equalTo($fresh->whatsapp_verified_at));
        $this->assertSame('whatsapp', $fresh->verificationMethod());

        // And the chat was told the truth — already verified, not a failure.
        $said = null;

        foreach (Http::recorded() as [$request]) {
            if (str_contains($request->url(), 'api.telegram.org')) {
                $said = (string) ($request->data()['text'] ?? '');
            }
        }

        $this->assertSame(__('identity.telegram.bot_account_already_verified', [], 'ckb'), $said);
    }

    public function test_a_pending_whatsapp_code_after_a_telegram_verification_resolves_without_a_second_event(): void
    {
        $this->register();
        $user = $this->continueInBrowser();

        // A WhatsApp code goes out first…
        $this->post('/account/verify/whatsapp/send')->assertRedirect();
        $code = $this->lastDeliveredCode();

        // …then Telegram wins the race.
        $token = (string) TelegramVerificationToken::query()
            ->where('user_id', $user->id)
            ->usable()
            ->firstOrFail()
            ->raw();

        $this->sendStart($token, 909000333);

        $fresh = $user->fresh();

        $this->assertNotNull($fresh->telegram_verified_at);
        $this->assertSame('909000333', $fresh->telegram_id);

        // The pending code was retired the moment Telegram won.
        $this->assertSame(0, WhatsAppOtp::query()->usable()->count());

        // Typing it anyway resolves as already-verified and stamps NOTHING:
        // whatsapp_verified_at stays null, phone_verified stays exactly the
        // claim Telegram left (false — a typed number is still unproven).
        $this->post('/account/verify/whatsapp/confirm', ['code' => $code])->assertRedirect();

        $after = $user->fresh();

        $this->assertNull($after->whatsapp_verified_at, 'the losing method stamped a second verification');
        $this->assertFalse((bool) $after->phone_verified);
        $this->assertSame('telegram', $after->verificationMethod());
        $this->assertNotNull($after->telegram_verified_at);
    }

    // ----------------------------------------------------------------
    // The account lifecycle honours the second door
    // ----------------------------------------------------------------

    public function test_a_whatsapp_verified_account_is_never_reclaimed_or_self_abandonable(): void
    {
        $this->register();
        $user = $this->continueInBrowser();

        $this->post('/account/verify/whatsapp/send')->assertRedirect();
        $this->post('/account/verify/whatsapp/confirm', ['code' => $this->lastDeliveredCode()])
            ->assertRedirect();

        $fresh = $user->fresh();

        // The reclamation policy refuses on the WhatsApp stamp alone.
        $assessment = app(AbandonedAccountPolicy::class)->assess($fresh);
        $this->assertFalse($assessment['eligible']);
        $this->assertSame('verified', $assessment['reason']);

        $this->assertFalse(app(AbandonedAccountPolicy::class)->assessSelfAbandon($fresh)['eligible']);

        // And the sweep never even selects it, whatever the window.
        $this->travel(200)->hours();
        $this->artisan(PruneUnlinkedAccounts::class, ['--hours' => 1])->assertSuccessful();
        $this->travelBack();

        $this->assertNotNull(User::query()->find($user->id), 'the sweep reclaimed a verified account');
        $this->assertFalse((bool) $user->fresh()->trashed());
    }

    public function test_signing_in_later_lands_a_whatsapp_verified_account_on_its_destination(): void
    {
        $this->register('ckb', '07505551234');
        $this->continueInBrowser();

        $this->post('/account/verify/whatsapp/send')->assertRedirect();
        $this->post('/account/verify/whatsapp/confirm', ['code' => $this->lastDeliveredCode()])
            ->assertRedirect();

        Auth::logout();
        $this->flushSession();

        $response = $this->post('/login', [
            'login' => '07505551234',
            'password' => self::PASSWORD,
        ]);

        $response->assertRedirect();
        // Straight to onboarding — never back to a verification page.
        $this->assertStringContainsString('/account/onboarding', (string) $response->headers->get('Location'));
    }
}
