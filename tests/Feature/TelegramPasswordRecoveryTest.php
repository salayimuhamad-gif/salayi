<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Identity\Models\PasswordRecoveryChallenge;
use App\Modules\Identity\Models\TelegramVerificationToken;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Telegram password recovery (the overrides' rule: a SEPARATE mechanism).
 *
 * The properties under test, in the order they matter:
 *
 *   1. SEPARATION. The permanent verification token cannot reset a password,
 *      and a recovery token cannot verify an account. Either direction
 *      failing quietly is exactly the failure the dedicated table exists to
 *      prevent, so both directions are asserted against the REAL endpoints.
 *
 *   2. Silence. The request form answers identically for an unknown number,
 *      an unlinked account and a recoverable one; only the last produces a
 *      chat message.
 *
 *   3. The credential's lifecycle: short clock, one use, retired by a newer
 *      request, bound to the account's live Telegram identity, budgeted per
 *      token, and every session of the account dead after a reset.
 */
final class TelegramPasswordRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Str0ng!Passw0rd#2026';

    private const NEW_PASSWORD = 'An0ther!Str0ng#2026';

    private const PHONE = '07501234567';

    private const TELEGRAM_ID = 555000111;

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
            'services.telegram.webhook_secret' => 'test-webhook-secret',
        ]);

        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
    }

    /* ------------------------------------------------------------ harness */

    /** Register through the real form and verify through the real webhook. */
    private function verifiedUser(string $locale = 'ckb'): User
    {
        $this->post('/register', [
            'name' => 'Test Person',
            'phone' => self::PHONE,
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
            'locale' => $locale,
            'accept_terms' => true,
            'consent_contact' => false,
        ])->assertRedirect();

        $raw = TelegramVerificationToken::query()->firstOrFail()->raw();

        $this->sendStart($raw, self::TELEGRAM_ID);

        $this->post('/logout');

        $user = User::query()->firstOrFail();
        $this->assertNotNull($user->telegram_verified_at);

        return $user;
    }

    private function sendStart(string $token, int $telegramId): void
    {
        $browserSession = session()->getId();

        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'test-webhook-secret')
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
                    'text' => '/start '.$token,
                ],
            ])->assertOk();

        if ($browserSession !== '') {
            session()->setId($browserSession);
            session()->start();
        }
    }

    private function requestRecovery(string $phone = self::PHONE): void
    {
        $this->post('/forgot-password/telegram', ['phone' => $phone])
            ->assertRedirect()
            ->assertSessionHas('status', __('identity.recovery.sent_notice'));
    }

    /**
     * The recovery button URL from a recorded chat message, decoded — the
     * markup is a JSON string whose slashes json_encode escapes, so a raw
     * substring match on `/recover/` would never fire.
     */
    private function recoveryUrlFrom(ClientRequest $request): ?string
    {
        if (! str_contains($request->url(), 'sendMessage')) {
            return null;
        }

        $markup = (string) ($request->data()['reply_markup'] ?? '');

        if ($markup === '') {
            return null;
        }

        $decoded = json_decode($markup, true);
        $url = $decoded['inline_keyboard'][0][0]['url'] ?? null;

        return is_string($url) && str_contains($url, '/recover/') ? $url : null;
    }

    /** The raw token, read out of the faked chat message's button URL. */
    private function tokenFromChat(): string
    {
        $url = null;

        Http::assertSent(function (ClientRequest $request) use (&$url): bool {
            $found = $this->recoveryUrlFrom($request);

            if ($found === null) {
                return false;
            }

            $url = $found;

            return true;
        });

        $this->assertIsString($url);

        return substr((string) $url, strrpos((string) $url, '/') + 1);
    }

    /** How many recovery messages went to the chat. Safe when none did. */
    private function chatMessageCount(): int
    {
        $count = 0;

        foreach (Http::recorded() as [$request]) {
            if ($this->recoveryUrlFrom($request) !== null) {
                $count++;
            }
        }

        return $count;
    }

    /* ------------------------------------------------- request behaviour */

    public function test_a_recoverable_account_gets_one_chat_message_with_a_link(): void
    {
        $this->verifiedUser();
        $this->requestRecovery();

        $token = $this->tokenFromChat();

        $this->assertSame(64, strlen($token));
        $this->assertTrue(ctype_alnum($token));

        // Stored hashed; the raw value must not sit in any column.
        $challenge = PasswordRecoveryChallenge::query()->firstOrFail();
        $this->assertSame(hash('sha256', $token), $challenge->token_hash);

        // The short clock, pinned: fifteen minutes, not a standing key.
        $this->assertTrue($challenge->expires_at->lessThanOrEqualTo(now()->addMinutes(15)));
    }

    public function test_an_unknown_number_gets_the_same_answer_and_no_message(): void
    {
        $this->requestRecovery('07509999999');

        $this->assertSame(0, $this->chatMessageCount());
        $this->assertSame(0, PasswordRecoveryChallenge::query()->count());
    }

    public function test_an_unlinked_account_gets_the_same_answer_and_no_message(): void
    {
        // Registered with a password but never verified with Telegram.
        $this->post('/register', [
            'name' => 'Unlinked Person',
            'phone' => self::PHONE,
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
            'locale' => 'ckb',
            'accept_terms' => true,
            'consent_contact' => false,
        ])->assertRedirect();
        $this->post('/logout');

        $this->requestRecovery();

        $this->assertSame(0, $this->chatMessageCount());
        $this->assertSame(0, PasswordRecoveryChallenge::query()->count());
    }

    public function test_a_suspended_account_gets_the_same_answer_and_no_message(): void
    {
        $user = $this->verifiedUser();
        $user->forceFill(['suspended_at' => now()])->save();

        $this->requestRecovery();

        $this->assertSame(0, $this->chatMessageCount());
    }

    public function test_a_new_request_retires_the_previous_challenge(): void
    {
        $this->verifiedUser();

        $this->requestRecovery();
        $first = $this->tokenFromChat();

        $this->travel(1)->minutes();
        Cache::flush(); // the per-phone limiter, not the property under test

        $this->requestRecovery();

        $this->assertSame(1, PasswordRecoveryChallenge::query()->whereNull('revoked_at')->count());

        // The old link is dead even though its clock has not run out.
        $this->get('/recover/'.$first)->assertRedirect('/forgot-password');
    }

    public function test_requests_are_rate_limited_per_phone(): void
    {
        $this->verifiedUser();

        foreach (range(1, 3) as $i) {
            $this->post('/forgot-password/telegram', ['phone' => self::PHONE])->assertRedirect();
        }

        $this->post('/forgot-password/telegram', ['phone' => self::PHONE])->assertStatus(429);
    }

    /* ---------------------------------------------------- the reset form */

    public function test_a_live_link_renders_the_reset_form(): void
    {
        $this->verifiedUser();
        $this->requestRecovery();

        $this->get('/recover/'.$this->tokenFromChat())
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Auth/RecoverTelegram'));
    }

    public function test_a_garbage_token_lands_on_the_request_form_with_a_neutral_notice(): void
    {
        $this->get('/recover/not-a-real-token')
            ->assertRedirect('/forgot-password');
    }

    public function test_the_link_expires_on_its_short_clock(): void
    {
        $this->verifiedUser();
        $this->requestRecovery();
        $token = $this->tokenFromChat();

        $this->travel(16)->minutes();

        $this->get('/recover/'.$token)->assertRedirect('/forgot-password');

        $this->post('/recover', [
            'token' => $token,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertRedirect('/forgot-password');

        // The password did not change.
        $this->post('/login', ['login' => self::PHONE, 'password' => self::PASSWORD])
            ->assertRedirect();
        $this->assertNotNull(Auth::user());
    }

    /* --------------------------------------------------------- the reset */

    public function test_a_reset_changes_the_password_once_and_only_once(): void
    {
        $user = $this->verifiedUser();
        $oldRemember = $user->remember_token;

        $this->requestRecovery();
        $token = $this->tokenFromChat();

        $this->post('/recover', [
            'token' => $token,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertRedirect('/login');

        // Old password dead, new password works.
        $this->post('/login', ['login' => self::PHONE, 'password' => self::PASSWORD]);
        $this->assertNull(Auth::user());

        $this->post('/login', ['login' => self::PHONE, 'password' => self::NEW_PASSWORD])
            ->assertRedirect();
        $this->assertNotNull(Auth::user());
        $this->post('/logout');

        // Remember token rotated; challenge consumed.
        $this->assertNotSame($oldRemember, $user->refresh()->remember_token);
        $this->assertNotNull(PasswordRecoveryChallenge::query()->firstOrFail()->consumed_at);

        // ONE use. The same link cannot set a third password.
        $this->post('/recover', [
            'token' => $token,
            'password' => 'Th1rd!Password#2026',
            'password_confirmation' => 'Th1rd!Password#2026',
        ])->assertRedirect('/forgot-password');

        $this->post('/login', ['login' => self::PHONE, 'password' => self::NEW_PASSWORD])
            ->assertRedirect();
        $this->assertNotNull(Auth::user());
    }

    public function test_a_reset_kills_every_live_session_of_the_account(): void
    {
        $user = $this->verifiedUser();

        // A live session on another device.
        DB::table('sessions')->insert([
            'id' => 'other-device-session',
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'other-device',
            'payload' => base64_encode(serialize([])),
            'last_activity' => now()->getTimestamp(),
        ]);

        $this->requestRecovery();

        $this->post('/recover', [
            'token' => $this->tokenFromChat(),
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertRedirect('/login');

        $this->assertSame(
            0,
            DB::table('sessions')->where('user_id', $user->id)->count(),
            'A password reset must invalidate every session the account holds.',
        );
    }

    public function test_a_challenge_dies_if_the_telegram_identity_changes(): void
    {
        $user = $this->verifiedUser();
        $this->requestRecovery();
        $token = $this->tokenFromChat();

        // Support re-points the account to a different Telegram identity
        // between mint and use. The challenge was delivered to the OLD chat.
        $user->forceFill(['telegram_id' => '999999999'])->save();

        $this->get('/recover/'.$token)->assertRedirect('/forgot-password');

        $this->post('/recover', [
            'token' => $token,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertRedirect('/forgot-password');

        $this->post('/login', ['login' => self::PHONE, 'password' => self::PASSWORD])
            ->assertRedirect();
        $this->assertNotNull(Auth::user());
    }

    public function test_the_per_token_attempt_budget_refuses_the_sixth_try(): void
    {
        $this->verifiedUser();

        $fake = str_repeat('Ab3', 21).'X'; // 64 alphanumeric chars, no row

        foreach (range(1, 6) as $i) {
            $this->post('/recover', [
                'token' => $fake,
                'password' => self::NEW_PASSWORD,
                'password_confirmation' => self::NEW_PASSWORD,
            ])->assertRedirect('/forgot-password');
        }

        // The sixth refusal is the budget, not the lookup — proven from the
        // audit trail, because the HTTP answer is deliberately identical.
        $this->assertTrue(
            DB::table('audit_logs')
                ->where('action', 'identity.password_recovery_refused')
                ->where('context', 'like', '%attempt_budget%')
                ->exists(),
        );
    }

    /* ------------------------------------------------ mechanism separation */

    public function test_the_permanent_verification_token_cannot_reset_a_password(): void
    {
        // An account mid-registration: it holds a LIVE permanent verification
        // token. That token must be worthless at every recovery endpoint.
        $this->post('/register', [
            'name' => 'Unlinked Person',
            'phone' => self::PHONE,
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
            'locale' => 'ckb',
            'accept_terms' => true,
            'consent_contact' => false,
        ])->assertRedirect();
        $this->post('/logout');

        $verificationRaw = TelegramVerificationToken::query()->firstOrFail()->raw();

        $this->get('/recover/'.$verificationRaw)->assertRedirect('/forgot-password');

        $this->post('/recover', [
            'token' => $verificationRaw,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertRedirect('/forgot-password');

        // Password unchanged, and the verification token is STILL usable for
        // its real purpose — probing it at the wrong door spent nothing.
        $this->post('/login', ['login' => self::PHONE, 'password' => self::PASSWORD])
            ->assertRedirect();
        $this->assertNotNull(Auth::user());
        $this->post('/logout');

        $this->sendStart($verificationRaw, self::TELEGRAM_ID);
        $this->assertNotNull(User::query()->firstOrFail()->telegram_verified_at);
    }

    public function test_a_recovery_token_cannot_verify_an_account(): void
    {
        $user = $this->verifiedUser();
        $this->requestRecovery();
        $token = $this->tokenFromChat();

        // Un-verify a COPY of the state: a second account waiting to verify.
        // Sending the recovery token to the verification webhook must not
        // touch it — the webhook sees an unknown token.
        $user->forceFill([
            'telegram_id' => null,
            'telegram_username' => null,
            'telegram_verified_at' => null,
        ])->save();

        $this->sendStart($token, 777000222);

        $refreshed = $user->refresh();
        $this->assertNull($refreshed->telegram_verified_at);
        $this->assertNull($refreshed->telegram_id);
    }
}
