<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Identity\Models\TelegramLoginIntent;
use App\Modules\Identity\Models\TelegramVerificationToken;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\TelegramOwnershipTransfer;
use App\Modules\Identity\Services\TelegramRegistrar;
use App\Modules\Identity\Services\TelegramVerificationService;
use App\Modules\Operations\Models\AuditLog;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * TELEGRAM OWNERSHIP TRANSFER — the identity belongs to whoever can prove
 * control of it.
 *
 * The product situation under test: a person's Telegram is linked to an older
 * MULK account, they register a new account, press Start — and instead of the
 * old terminal conflict they are offered, in their authenticated browser, the
 * decision to MOVE the Telegram identity to the account they are actually
 * using. The properties that must never be given up while making that
 * possible:
 *
 *   - a Start ALONE moves nothing, ever — parking a question is not deciding
 *     it, and the decision exists only in the destination's authenticated
 *     browser, behind an explicit acknowledgement;
 *   - exactly one account holds a Telegram identity at every instant — the
 *     UNIQUE index is never relaxed, and a race between two destinations has
 *     exactly one winner;
 *   - only the CLAIM moves. The old account keeps its data, its password,
 *     its phone, its everything — and either remains verified through
 *     WhatsApp or lands in the same password-in, verify-before-personal-
 *     features state every account-first registration starts in;
 *   - nobody learns anything about anybody: the browser is told only the
 *     candidate's own Telegram display identity and a yes/no question.
 */
final class TelegramOwnershipTransferTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Str0ng!Passw0rd#2026';

    private const TELEGRAM = 900900900;

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
        ]);

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

    /** Register through the real form and return the signed-in user. */
    private function register(string $phone, string $name = 'Test Person'): User
    {
        $this->post('/register', [
            'name' => $name,
            'phone' => $phone,
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
            'locale' => 'ckb',
            'accept_terms' => true,
            'consent_contact' => false,
        ])->assertRedirect();

        $id = Auth::id();

        $user = $id === null
            ? User::query()->latest('id')->firstOrFail()
            : User::query()->findOrFail($id);

        $this->actingAs($user->fresh());

        return $user;
    }

    /** Drive `/start TOKEN` through the REAL webhook with the secret header. */
    private function sendStart(string $token, int $telegramId, ?int $updateId = null): void
    {
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
                        'first_name' => 'Owner',
                        'username' => 'owneruser',
                    ],
                    'text' => '/start '.$token,
                ],
            ])->assertOk();

        if ($browserSession !== '') {
            session()->setId($browserSession);
            session()->start();
        }
    }

    /** The raw token behind this account's live verification link. */
    private function liveToken(User $user): string
    {
        return (string) TelegramVerificationToken::query()
            ->where('user_id', $user->id)
            ->usable()
            ->latest('id')
            ->firstOrFail()
            ->raw();
    }

    /**
     * The standard stage: OLD account verified with the Telegram identity,
     * NEW account registered, Start pressed with the same identity — the
     * transfer question is now parked for the new account.
     *
     * @return array{source: User, destination: User}
     */
    private function stageCollision(): array
    {
        $source = $this->register('07501110000', 'Old Owner');
        $this->get('/account/telegram/link');
        $this->sendStart($this->liveToken($source), self::TELEGRAM, updateId: 1001);
        $this->assertNotNull($source->fresh()->telegram_verified_at);

        $this->flushSession();
        Auth::logout();

        $destination = $this->register('07502220000', 'New Owner');
        $this->get('/account/telegram/link');
        $this->sendStart($this->liveToken($destination), self::TELEGRAM, updateId: 1002);

        return ['source' => $source, 'destination' => $destination];
    }

    private function pendingTransfer(User $destination): ?TelegramLoginIntent
    {
        return TelegramLoginIntent::query()
            ->where('purpose', TelegramLoginIntent::PURPOSE_ACCOUNT_TRANSFER)
            ->where('user_id', $destination->id)
            ->whereNull('consumed_at')
            ->whereNull('cancelled_at')
            ->latest('id')
            ->first();
    }

    /**
     * @return TestResponse<JsonResponse>
     */
    private function confirmTransfer(TelegramLoginIntent $intent, bool $accept = true): TestResponse
    {
        $payload = ['candidate_handle' => $intent->candidateHandle()];

        if ($accept) {
            $payload['accept_transfer'] = true;
        }

        return $this->postJson('/account/telegram/link/confirm', $payload);
    }

    private function assertClaimHeldOnlyBy(User $user): void
    {
        $this->assertSame(
            [$user->id],
            User::query()->where('telegram_id', (string) self::TELEGRAM)->pluck('id')->all(),
            'exactly one account must hold the Telegram identity',
        );
    }

    // ----------------------------------------------------------------
    // Case C: the collision parks a question instead of ending the road
    // ----------------------------------------------------------------

    public function test_a_colliding_start_parks_the_transfer_question_and_moves_nothing(): void
    {
        ['source' => $source, 'destination' => $destination] = $this->stageCollision();

        // Nothing moved: a Start alone must never change ownership.
        $this->assertSame((string) self::TELEGRAM, $source->fresh()->telegram_id);
        $this->assertNotNull($source->fresh()->telegram_verified_at);
        $this->assertNull($destination->fresh()->telegram_id);
        $this->assertNull($destination->fresh()->telegram_verified_at);

        // But the question is parked, with the candidate identity on it.
        $intent = $this->pendingTransfer($destination);
        $this->assertNotNull($intent, 'the collision must park a transfer question');
        $this->assertSame((string) self::TELEGRAM, $intent->candidate_telegram_id);
        $this->assertNotNull($intent->candidate_at);

        // And the destination's token was NOT burnt by asking.
        $this->assertTrue(
            TelegramVerificationToken::query()->where('user_id', $destination->id)->firstOrFail()->isUsable(),
            'parking the question must leave the verification link usable',
        );
    }

    public function test_the_poll_asks_the_question_without_describing_the_old_account(): void
    {
        ['source' => $source, 'destination' => $destination] = $this->stageCollision();

        $this->actingAs($destination->fresh());
        $response = $this->getJson('/account/telegram/link/poll');

        $response->assertOk()->assertJsonPath('state', 'awaiting_transfer');

        $candidate = $response->json('candidate');
        $this->assertSame(['name', 'username', 'handle', 'at'], array_keys($candidate));

        // The response describes the CANDIDATE's Telegram identity — which
        // the person pressing Start already knows — and nothing about the
        // account currently holding it.
        $body = $response->getContent();
        $this->assertStringNotContainsString('Old Owner', $body);
        $this->assertStringNotContainsString('07501110000', $body);
        $this->assertStringNotContainsString((string) $source->id, (string) $response->json('candidate.name'));
    }

    // ----------------------------------------------------------------
    // The confirmed transfer
    // ----------------------------------------------------------------

    public function test_confirming_with_the_acknowledgement_moves_the_claim_atomically(): void
    {
        ['source' => $source, 'destination' => $destination] = $this->stageCollision();
        $intent = $this->pendingTransfer($destination);

        $this->actingAs($destination->fresh());
        $this->confirmTransfer($intent)->assertOk()->assertJsonPath('state', 'completed');

        // Destination: the full claim, freshly stamped.
        $freshDestination = $destination->fresh();
        $this->assertSame((string) self::TELEGRAM, $freshDestination->telegram_id);
        $this->assertNotNull($freshDestination->telegram_verified_at);

        // Source: the full claim gone — all three columns.
        $freshSource = $source->fresh();
        $this->assertNull($freshSource->telegram_id);
        $this->assertNull($freshSource->telegram_username);
        $this->assertNull($freshSource->telegram_verified_at);

        $this->assertClaimHeldOnlyBy($destination);

        // The question is spent and cannot answer twice.
        $this->assertNotNull($intent->fresh()->consumed_at);
        $this->assertSame(TelegramLoginIntent::RESULT_COMPLETED, $intent->fresh()->result);

        // The move is audited from both directions, ids only.
        $this->assertTrue(
            AuditLog::query()->where('action', 'identity.telegram_ownership_transferred')->exists(),
            'a transfer must leave an explicit audit record',
        );
    }

    public function test_a_bare_confirm_without_the_acknowledgement_moves_nothing(): void
    {
        ['source' => $source, 'destination' => $destination] = $this->stageCollision();
        $intent = $this->pendingTransfer($destination);

        $this->actingAs($destination->fresh());
        $this->confirmTransfer($intent, accept: false)
            ->assertStatus(409)
            ->assertJsonPath('state', 'transfer_required');

        $this->assertSame((string) self::TELEGRAM, $source->fresh()->telegram_id);
        $this->assertNull($destination->fresh()->telegram_id);
        $this->assertNull($this->pendingTransfer($destination)?->consumed_at);
    }

    public function test_repeated_starts_re_park_but_never_transfer(): void
    {
        ['source' => $source, 'destination' => $destination] = $this->stageCollision();

        // Pressing Start again and again only ever re-asks the question.
        $this->sendStart($this->liveToken($destination->fresh()), self::TELEGRAM, updateId: 1003);
        $this->sendStart($this->liveToken($destination->fresh()), self::TELEGRAM, updateId: 1004);

        $this->assertSame((string) self::TELEGRAM, $source->fresh()->telegram_id);
        $this->assertNull($destination->fresh()->telegram_id);

        // Convergence: exactly ONE live question, however many Starts.
        $this->assertSame(1, TelegramLoginIntent::query()
            ->where('purpose', TelegramLoginIntent::PURPOSE_ACCOUNT_TRANSFER)
            ->where('user_id', $destination->id)
            ->whereNull('consumed_at')
            ->whereNull('cancelled_at')
            ->count());
    }

    public function test_webhook_redelivery_is_deduplicated_and_the_winner_stays_idempotent(): void
    {
        ['source' => $source, 'destination' => $destination] = $this->stageCollision();

        // Telegram redelivering the SAME update is absorbed by the inbox.
        $this->sendStart($this->liveToken($destination->fresh()), self::TELEGRAM, updateId: 1002);
        $this->assertSame((string) self::TELEGRAM, $source->fresh()->telegram_id);

        // Complete the transfer, then press Start once more: the winner gets
        // the idempotent already-verified answer, and no new question parks.
        $intent = $this->pendingTransfer($destination);
        $this->actingAs($destination->fresh());
        $this->confirmTransfer($intent)->assertOk();

        $token = TelegramVerificationToken::query()
            ->where('user_id', $destination->id)
            ->latest('id')
            ->firstOrFail();

        $this->assertNotNull($token->revoked_at, 'winning the claim retires the destination link');

        $this->assertClaimHeldOnlyBy($destination);
        $this->assertNull($this->pendingTransfer($destination->fresh()));
    }

    // ----------------------------------------------------------------
    // Races
    // ----------------------------------------------------------------

    public function test_two_destinations_race_and_exactly_one_wins(): void
    {
        ['source' => $source, 'destination' => $first] = $this->stageCollision();

        // A THIRD account parks the same question.
        $this->flushSession();
        Auth::logout();
        $second = $this->register('07503330000', 'Third Person');
        $this->get('/account/telegram/link');
        $this->sendStart($this->liveToken($second), self::TELEGRAM, updateId: 2001);

        $firstIntent = $this->pendingTransfer($first);
        $secondIntent = $this->pendingTransfer($second);
        $this->assertNotNull($firstIntent);
        $this->assertNotNull($secondIntent);

        // The first destination confirms and wins.
        $this->actingAs($first->fresh());
        $this->confirmTransfer($firstIntent)->assertOk()->assertJsonPath('state', 'completed');
        $this->assertClaimHeldOnlyBy($first);

        // The second's confirmation is now STALE: ownership changed after its
        // question was parked, so it is refused instead of stealing the claim
        // straight back from the winner.
        $this->actingAs($second->fresh());
        $this->confirmTransfer($secondIntent)
            ->assertStatus(409)
            ->assertJsonPath('state', 'candidate_changed');

        $this->assertClaimHeldOnlyBy($first);
        $this->assertNull($second->fresh()->telegram_id);
        $this->assertNull($source->fresh()->telegram_id);
    }

    public function test_a_candidate_swapped_between_render_and_click_is_refused(): void
    {
        ['destination' => $destination] = $this->stageCollision();

        $shownHandle = $this->pendingTransfer($destination)->candidateHandle();

        // A DIFFERENT Telegram account presses Start before the person
        // clicks: the parked question changes underneath the rendered one.
        // (The second identity is also claimed, so it parks a fresh
        // question rather than linking.)
        $this->flushSession();
        Auth::logout();
        $other = $this->register('07504440000', 'Fourth Person');
        $this->get('/account/telegram/link');
        $this->sendStart($this->liveToken($other), 911911911, updateId: 2101);
        $this->assertSame((string) 911911911, $other->fresh()->telegram_id);

        $this->actingAs($destination->fresh());
        $this->sendStart($this->liveToken($destination->fresh()), 911911911, updateId: 2102);

        // The click answers the OLD question; the server refuses it.
        $this->postJson('/account/telegram/link/confirm', [
            'candidate_handle' => $shownHandle,
            'accept_transfer' => true,
        ])->assertStatus(409)->assertJsonPath('state', 'candidate_changed');

        $this->assertNull($destination->fresh()->telegram_id);
    }

    public function test_the_same_account_pressing_its_own_telegram_is_idempotent_not_a_transfer(): void
    {
        $owner = $this->register('07501110000', 'Only Owner');
        $this->get('/account/telegram/link');
        $this->sendStart($this->liveToken($owner), self::TELEGRAM, updateId: 3001);
        $this->assertNotNull($owner->fresh()->telegram_verified_at);

        // A second live link for the same account, pressed with the SAME
        // Telegram: the idempotent already-verified answer, never a transfer
        // question against yourself.
        $service = app(TelegramVerificationService::class);
        $minted = $service->mint($owner->fresh());
        $result = $service->redeem($minted['raw'], ['id' => (string) self::TELEGRAM]);

        $this->assertTrue($result['ok']);
        $this->assertTrue($result['already_verified'] ?? false);
        $this->assertSame(0, TelegramLoginIntent::query()
            ->where('purpose', TelegramLoginIntent::PURPOSE_ACCOUNT_TRANSFER)
            ->count());
    }

    public function test_a_suspended_destination_cannot_receive_the_claim(): void
    {
        ['source' => $source, 'destination' => $destination] = $this->stageCollision();
        $intent = $this->pendingTransfer($destination);

        $destination->fresh()->forceFill(['suspended_at' => now()])->save();

        // Service-level: the post-lock re-check refuses even when the
        // request-level gates are out of the picture.
        $result = app(TelegramOwnershipTransfer::class)
            ->confirm($destination->fresh(), (string) self::TELEGRAM);

        $this->assertFalse($result['ok']);
        $this->assertSame((string) self::TELEGRAM, $source->fresh()->telegram_id);
        $this->assertNull($destination->fresh()->telegram_id);
        $this->assertNull($intent->fresh()->consumed_at);
    }

    // ----------------------------------------------------------------
    // What happens to the old account
    // ----------------------------------------------------------------

    public function test_a_whatsapp_verified_source_stays_verified_and_unrestricted(): void
    {
        ['source' => $source, 'destination' => $destination] = $this->stageCollision();

        // The old account also verified through WhatsApp at some point.
        $source->fresh()->forceFill(['whatsapp_verified_at' => now()->subDay()])->save();

        $intent = $this->pendingTransfer($destination);
        $this->actingAs($destination->fresh());
        $this->confirmTransfer($intent)->assertOk();

        $freshSource = $source->fresh();
        $this->assertNull($freshSource->telegram_id);
        $this->assertNotNull($freshSource->whatsapp_verified_at, 'the WhatsApp claim must survive untouched');
        $this->assertTrue($freshSource->hasVerifiedAccount());

        // And the personal surface still opens for it.
        $this->flushSession();
        $this->actingAs($freshSource);
        $this->get('/account/profile')->assertOk();
    }

    public function test_a_source_with_no_other_method_can_sign_in_but_only_reach_verification(): void
    {
        ['source' => $source, 'destination' => $destination] = $this->stageCollision();

        $intent = $this->pendingTransfer($destination);
        $this->actingAs($destination->fresh());
        $this->confirmTransfer($intent)->assertOk();

        $this->assertFalse($source->fresh()->hasVerifiedAccount());

        // The old password still signs in — the account is restricted, not
        // locked out, and certainly not deleted.
        $this->flushSession();
        Auth::logout();

        $this->post('/login', [
            'login' => '07501110000',
            'password' => self::PASSWORD,
        ])->assertRedirect();

        $this->assertSame($source->id, Auth::id(), 'the source account must still authenticate by password');

        // Personal surfaces refuse and route to the verification choice…
        $this->get('/account/profile')->assertRedirect();
        $this->assertStringContainsString('/account/verify', (string) $this->get('/account/profile')->headers->get('Location'));

        // …which is reachable, with both doors on offer.
        $this->get('/account/verify')->assertOk();
    }

    public function test_the_restricted_source_recovers_by_linking_a_different_telegram(): void
    {
        ['source' => $source, 'destination' => $destination] = $this->stageCollision();

        $this->actingAs($destination->fresh());
        $this->confirmTransfer($this->pendingTransfer($destination))->assertOk();

        // The old account signs in and verifies with a DIFFERENT Telegram.
        $this->flushSession();
        Auth::logout();
        $this->actingAs($source->fresh());
        $this->get('/account/telegram/link')->assertOk();

        $this->sendStart($this->liveToken($source->fresh()), 777777777, updateId: 4001);

        $freshSource = $source->fresh();
        $this->assertSame('777777777', $freshSource->telegram_id);
        $this->assertNotNull($freshSource->telegram_verified_at);
        $this->assertTrue($freshSource->hasVerifiedAccount());

        // Access restores automatically — no support step, no new status.
        $this->get('/account/profile')->assertOk();
    }

    public function test_the_source_can_attach_a_new_telegram_only_after_the_transfer_not_during(): void
    {
        ['source' => $source, 'destination' => $destination] = $this->stageCollision();

        // While it still holds the claim, the old account cannot be silently
        // re-pointed at another Telegram — the standing rule, unchanged.
        $service = app(TelegramVerificationService::class);
        $minted = $service->mint($source->fresh());
        $result = $service->redeem($minted['raw'], ['id' => '777777777']);

        $this->assertFalse($result['ok']);
        $this->assertSame('conflict', $result['reason']);
        $this->assertSame((string) self::TELEGRAM, $source->fresh()->telegram_id);

        // After the transfer strips it, the same act succeeds.
        $this->actingAs($destination->fresh());
        $this->confirmTransfer($this->pendingTransfer($destination))->assertOk();

        $minted = $service->mint($source->fresh());
        $result = $service->redeem($minted['raw'], ['id' => '777777777']);

        $this->assertTrue($result['ok']);
        $this->assertSame('777777777', $source->fresh()->telegram_id);
    }

    public function test_cancelling_leaves_the_claim_exactly_where_it_was(): void
    {
        ['source' => $source, 'destination' => $destination] = $this->stageCollision();

        $this->actingAs($destination->fresh());
        $this->postJson('/account/telegram/link/reject')
            ->assertOk()
            ->assertJsonPath('state', 'cancelled');

        $this->assertSame((string) self::TELEGRAM, $source->fresh()->telegram_id);
        $this->assertNull($destination->fresh()->telegram_id);
        $this->assertNull($this->pendingTransfer($destination));

        $this->assertTrue(
            AuditLog::query()->where('action', 'identity.telegram_transfer_cancelled')->exists(),
            'declining the transfer must be auditable',
        );
    }

    // ----------------------------------------------------------------
    // The legacy session-bound intent flow carries the same rule
    // ----------------------------------------------------------------

    public function test_the_legacy_intent_flow_parks_and_executes_the_transfer(): void
    {
        $source = $this->register('07501110000', 'Old Owner');
        $this->get('/account/telegram/link');
        $this->sendStart($this->liveToken($source), self::TELEGRAM, updateId: 5001);

        $this->flushSession();
        Auth::logout();
        $destination = $this->register('07502220000', 'New Owner');

        $registrar = app(TelegramRegistrar::class);
        $sessionId = 'legacy-session-under-test';

        $intent = $registrar->resumeOrBeginAccountLink($destination->fresh(), $sessionId, null, null);

        // The Start against the session intent PARKS instead of conflicting.
        $result = $registrar->redeemAccountLink($intent, [
            'id' => (string) self::TELEGRAM,
            'username' => 'owneruser',
            'first_name' => 'Owner',
        ]);

        $this->assertTrue($result['ok']);
        $this->assertTrue($result['pending_confirmation'] ?? false);
        $this->assertSame((string) self::TELEGRAM, $intent->fresh()->candidate_telegram_id);
        $this->assertSame((string) self::TELEGRAM, $source->fresh()->telegram_id, 'parking must not move the claim');

        // A bare confirm still refuses — the old behaviour for a browser
        // that never surfaced the transfer decision.
        $bare = $registrar->confirmAccountLink($destination->fresh(), $sessionId, (string) self::TELEGRAM);
        $this->assertFalse($bare['ok']);
        $this->assertSame('conflict', $bare['reason']);
        $this->assertSame((string) self::TELEGRAM, $source->fresh()->telegram_id);

        // The acknowledged confirm executes the transfer.
        $accepted = $registrar->confirmAccountLink($destination->fresh(), $sessionId, (string) self::TELEGRAM, acceptTransfer: true);

        $this->assertTrue($accepted['ok']);
        $this->assertSame((string) self::TELEGRAM, $destination->fresh()->telegram_id);
        $this->assertNull($source->fresh()->telegram_id);
        $this->assertClaimHeldOnlyBy($destination);
    }

    // ----------------------------------------------------------------
    // Invariants
    // ----------------------------------------------------------------

    public function test_the_unique_index_on_telegram_id_still_stands(): void
    {
        ['source' => $source, 'destination' => $destination] = $this->stageCollision();

        $this->expectException(UniqueConstraintViolationException::class);

        $destination->fresh()->forceFill([
            'telegram_id' => (string) self::TELEGRAM,
        ])->save();
    }

    public function test_no_claims_are_faked_across_methods(): void
    {
        ['source' => $source, 'destination' => $destination] = $this->stageCollision();

        $this->actingAs($destination->fresh());
        $this->confirmTransfer($this->pendingTransfer($destination))->assertOk();

        // Telegram verification moved; WhatsApp claims were invented for
        // NOBODY on either side.
        $this->assertNull($destination->fresh()->whatsapp_verified_at);
        $this->assertNull($source->fresh()->whatsapp_verified_at);
    }
}
