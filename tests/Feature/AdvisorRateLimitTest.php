<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Advisor\Models\AdvisorConversation;
use App\Modules\Advisor\Models\AdvisorMessage;
use App\Modules\Identity\Models\User;
use App\Modules\Leads\Models\DemandProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * The advisor's rate limits (Phase 14).
 *
 * Before this phase no /advisor route carried any throttle: one scripted,
 * Telegram-linked account could trigger up to two paid AI completions and
 * two advisor_messages rows per request, forever — AiGateway has no
 * per-user control and the monthly cost ceiling defaults to unlimited.
 *
 * The contracts pinned here: reply is capped at 10/minute per user and the
 * cheap mutations share a 30/minute bucket; the buckets are PER USER, so
 * one person's loop never spends another's allowance; a throttled request
 * is refused BEFORE any money or rows — zero AI calls, zero writes — and
 * the refusal speaks the conversation's language.
 */
final class AdvisorRateLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->setFeatures(['advisor.residential' => true]);
    }

    private function member(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['telegram_verified_at' => now()])->save();

        return $user;
    }

    /** @return TestResponse<Response> */
    private function reply(User $user, string $prefix = ''): TestResponse
    {
        return $this->actingAs($user)->postJson($prefix.'/advisor/reply', [
            'message' => 'بەڵێ',
            'locale' => 'ckb',
        ]);
    }

    /* ------------------------------------------------------ advisor-reply */

    public function test_ten_replies_pass_and_the_eleventh_is_refused_with_the_localized_message(): void
    {
        $member = $this->member();
        $this->actingAs($member)->get('/advisor')->assertOk();

        foreach (range(1, 10) as $i) {
            $this->reply($member)->assertOk();
        }

        $blocked = $this->reply($member);

        $blocked->assertStatus(429);
        $blocked->assertJsonPath('message', __('advisor.chat.rate_limited', [], 'ckb'));
        $this->assertNotNull($blocked->headers->get('Retry-After'), 'the framework Retry-After header must survive');
    }

    public function test_a_throttled_reply_spends_no_ai_money_and_writes_no_rows(): void
    {
        config([
            'services.ai.provider' => 'openai_compatible',
            'services.ai.providers.openai_compatible.base_url' => 'https://ai.test/v1',
            'services.ai.providers.openai_compatible.key' => 'fake-test-key',
            'services.ai.providers.openai_compatible.model' => 'fake-model',
        ]);

        $aiCalls = 0;
        Http::fake(function () use (&$aiCalls) {
            $aiCalls++;

            return Http::response([
                'model' => 'fake-model',
                'choices' => [['message' => ['content' => 'باشە.'], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 5],
            ]);
        });

        $member = $this->member();
        $this->actingAs($member)->get('/advisor')->assertOk();

        foreach (range(1, 10) as $i) {
            $this->reply($member)->assertOk();
        }

        $callsAtThreshold = $aiCalls;
        $rowsAtThreshold = AdvisorMessage::query()->count();

        $this->reply($member)->assertStatus(429);

        // Refused BEFORE the controller: nothing left the app, nothing landed.
        $this->assertSame($callsAtThreshold, $aiCalls, 'a throttled reply must trigger zero AI requests');
        $this->assertSame($rowsAtThreshold, AdvisorMessage::query()->count(), 'a throttled reply must write zero advisor_messages rows');
    }

    public function test_one_users_throttle_never_spends_anothers_allowance(): void
    {
        $spender = $this->member();
        $bystander = $this->member();

        $this->actingAs($spender)->get('/advisor')->assertOk();

        foreach (range(1, 10) as $i) {
            $this->reply($spender)->assertOk();
        }
        $this->reply($spender)->assertStatus(429);

        $this->actingAs($bystander)->get('/advisor')->assertOk();
        $this->reply($bystander)->assertOk();
    }

    public function test_the_refusal_speaks_each_conversation_language(): void
    {
        foreach (['' => 'ckb', '/ar' => 'ar', '/en' => 'en'] as $prefix => $locale) {
            $member = $this->member();
            $this->actingAs($member)->get(($prefix === '' ? '' : $prefix).'/advisor')->assertOk();

            foreach (range(1, 10) as $i) {
                $this->reply($member, $prefix)->assertOk();
            }

            $this->reply($member, $prefix)
                ->assertStatus(429)
                ->assertJsonPath('message', __('advisor.chat.rate_limited', [], $locale));
        }
    }

    /* ------------------------------------------------------ advisor-write */

    public function test_the_cheap_mutations_share_one_thirty_per_minute_bucket(): void
    {
        $member = $this->member();
        $this->actingAs($member)->get('/advisor')->assertOk();

        // Spend the shared bucket across all three routes: 10 amends,
        // 10 resets, 10 submits (invalid on purpose — a 422 still counts,
        // which is correct: validation happens after the throttle).
        foreach (range(1, 10) as $i) {
            $this->actingAs($member)->post('/advisor/amend', ['slot' => 'purpose', 'value' => 'residence'])
                ->assertStatus(302);
        }
        foreach (range(1, 10) as $i) {
            $this->actingAs($member)->postJson('/advisor/reset')->assertStatus(302);
        }
        foreach (range(1, 10) as $i) {
            $this->actingAs($member)->postJson('/advisor/request', ['consent' => false])
                ->assertStatus(422);
        }

        // Request 31, on ANY of the three, is refused — one bucket.
        $conversationsBefore = AdvisorConversation::query()->where('status', 'open')->count();
        $leadsBefore = DemandProfile::query()->count();

        $this->actingAs($member)->postJson('/advisor/reset')->assertStatus(429);
        $this->actingAs($member)->postJson('/advisor/request', ['consent' => true])->assertStatus(429);
        $this->actingAs($member)->postJson('/advisor/amend', ['slot' => 'purpose', 'value' => 'x'])->assertStatus(429);

        // And none of the blocked mutations happened.
        $this->assertSame(
            $conversationsBefore,
            AdvisorConversation::query()->where('status', 'open')->count(),
            'a throttled reset must not close or open conversations',
        );
        $this->assertSame($leadsBefore, DemandProfile::query()->count(), 'a throttled request must not write a lead');
    }

    public function test_the_reply_bucket_and_the_write_bucket_are_independent(): void
    {
        $member = $this->member();
        $this->actingAs($member)->get('/advisor')->assertOk();

        foreach (range(1, 10) as $i) {
            $this->reply($member)->assertOk();
        }
        $this->reply($member)->assertStatus(429);

        // The write bucket is untouched by the reply spree.
        $this->actingAs($member)->post('/advisor/amend', ['slot' => 'purpose', 'value' => 'residence'])
            ->assertStatus(302);
    }

    /* -------------------------------------------- deliberately unthrottled */

    public function test_the_page_and_the_noop_recommend_stay_unthrottled(): void
    {
        $member = $this->member();
        $this->actingAs($member)->get('/advisor')->assertOk();

        foreach (range(1, 40) as $i) {
            $this->actingAs($member)->get('/advisor')->assertOk();
        }

        foreach (range(1, 40) as $i) {
            $this->actingAs($member)->post('/advisor/recommend')->assertStatus(302);
        }
    }
}
