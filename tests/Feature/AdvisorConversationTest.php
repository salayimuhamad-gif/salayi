<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Advisor\Models\AdvisorConversation;
use App\Modules\Advisor\Models\AdvisorMessage;
use App\Modules\Identity\Models\User;
use App\Modules\Projects\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The repaired advisor conversation, end to end over HTTP.
 *
 * The contracts under test are the ones the audit found missing: one message
 * can answer several questions, an answered question is never asked again,
 * intake completeness OPENS the advisory stage instead of 409-ing the chat,
 * the follow-up conversation works with the model on or off, and every turn
 * records truthfully whether a model or the deterministic fallback produced
 * it. Every provider interaction is Http::fake — nothing leaves the process.
 */
final class AdvisorConversationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->setFeatures(['advisor.residential' => true]);
    }

    /** A member who has completed the Telegram link — the advisor's gate. */
    private function member(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['telegram_verified_at' => now()])->save();

        return $user;
    }

    private function aiOn(): void
    {
        config([
            'services.ai.provider' => 'openai_compatible',
            'services.ai.providers.openai_compatible.base_url' => 'https://ai.test/v1',
            'services.ai.providers.openai_compatible.key' => 'fake-test-key',
            'services.ai.providers.openai_compatible.model' => 'fake-model',
        ]);
    }

    /** @return array<string, mixed> */
    private static function chat(string $text): array
    {
        return [
            'model' => 'fake-model',
            'choices' => [['message' => ['content' => $text], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 20, 'completion_tokens' => 10],
        ];
    }

    /**
     * One fake serving all three prompt kinds, told apart by their system
     * prompts. Overridable per test.
     *
     * @param  array<string, string|callable>  $overrides
     */
    private function fakeAi(array $overrides = []): void
    {
        Http::fake(function ($request) use ($overrides) {
            $body = $request->data();
            $system = (string) ($body['messages'][0]['content'] ?? '');
            $user = (string) ($body['messages'][1]['content'] ?? '');

            if (str_contains($system, 'output ONLY a JSON object')) {
                $understanding = $overrides['understanding']
                    ?? '{"intent":"provide_info","criteria_updates":{},"referenced_positions":[],"needs_clarification":false}';

                return Http::response(self::chat(is_callable($understanding) ? $understanding($user) : $understanding));
            }

            if (str_contains($system, 'answering a follow-up')) {
                $advisory = $overrides['advisory'] ?? null;

                if ($advisory === null) {
                    // No override: echo the deterministic draft, which is
                    // grounded by construction.
                    $decoded = json_decode($user, true);

                    return Http::response(self::chat((string) ($decoded['draft_answer'] ?? '')));
                }

                return Http::response(self::chat(is_callable($advisory) ? $advisory($user) : $advisory));
            }

            $intake = $overrides['intake'] ?? null;

            if ($intake === null) {
                // Echo the required question with a short Sorani-friendly
                // acknowledgement, as a well-behaved model would.
                $decoded = json_decode($user, true);

                return Http::response(self::chat('باشە. '.(string) ($decoded['required_question'] ?? '')));
            }

            return Http::response(self::chat(is_callable($intake) ? $intake($user) : $intake));
        });
    }

    private function send(string $message, string $locale = 'ckb'): TestResponse
    {
        return $this->postJson('/advisor/reply', ['message' => $message, 'locale' => $locale]);
    }

    private function publishedProject(string $slug): Project
    {
        $project = Project::query()->create([
            'slug' => $slug,
            'name_ckb' => 'پڕۆژەی '.$slug,
            'name_en' => 'Project '.$slug,
            'project_type' => 'residential',
            'construction_status' => 'under_construction',
            'delivery_status' => 'not_started',
            'publication_status' => 'published',
        ]);
        $project->forceFill(['published_at' => now()])->save();

        return $project;
    }

    /** Drive a member's interview to completion deterministically. */
    private function completeIntake(User $member, string $locale = 'ckb'): void
    {
        $this->actingAs($member)->get('/advisor')->assertOk();
        $this->actingAs($member); // session persists across the sequence

        $this->send('بۆ نیشتەجێبوون', $locale)->assertOk();
        $this->send('١٥٠ هەزار دۆلار', $locale)->assertOk();
        $response = $this->send('شوقە', $locale)->assertOk();

        // Required slots done: skip the optional tail with a finish command.
        if ($response->json('complete') !== true) {
            $this->send('بەسە', $locale)->assertOk()->assertJsonPath('complete', true);
        }
    }

    /* ---------------------------------------------- multi-fact extraction */

    public function test_one_sorani_message_fills_several_criteria_and_none_are_reasked(): void
    {
        $this->aiOn();
        $this->fakeAi([
            'understanding' => '{"intent":"provide_info","criteria_updates":{"purpose":"residence","area":"عەنکاوە","payment":"قیست","family":"بەڵێ"},"referenced_positions":[],"needs_clarification":false}',
        ]);

        $member = $this->member();
        $this->actingAs($member)->get('/advisor')->assertOk();

        // Type, budget WITH unit, and currency come from the deterministic
        // extractors; purpose, area, family and payment from the validated
        // model structure. One message, seven slots.
        $response = $this->actingAs($member)
            ->send('شوقە دەمەوێت بۆ خێزانەکەم لە نزیک عەنکاوە، بودجەم نزیکەی ١٨٠ ملیۆن دینارە و قیست باشترە')
            ->assertOk();

        $answered = collect($response->json('summary'))
            ->where('answered', true)
            ->pluck('key')
            ->all();

        foreach (['purpose', 'currency', 'budget', 'property_type', 'area', 'family', 'payment'] as $slot) {
            $this->assertContains($slot, $answered, "slot {$slot} should be captured from one message");
        }

        // The next question is for a genuinely missing slot — nothing above.
        $this->assertSame('workplace', $response->json('next_slot'));
    }

    public function test_a_currency_and_budget_answer_skips_the_currency_question_deterministically(): void
    {
        // No AI at all: the deterministic pickups alone must prevent re-asking.
        $member = $this->member();
        $this->actingAs($member)->get('/advisor')->assertOk();

        $this->actingAs($member)->send('بۆ نیشتەجێبوون')->assertOk()
            ->assertJsonPath('next_slot', 'currency');

        // "١٥٠ هەزار دۆلار" answers currency AND budget in one breath.
        $this->actingAs($member)->send('١٥٠ هەزار دۆلار')->assertOk()
            ->assertJsonPath('next_slot', 'property_type');
    }

    /* ------------------------------------- completion is a stage, not death */

    public function test_intake_completion_opens_the_advisory_stage_instead_of_409(): void
    {
        $member = $this->member();
        $this->completeIntake($member);

        // The old contract answered 409 "already complete" here. The repaired
        // one answers the follow-up.
        $response = $this->actingAs($member)->send('سوپاس، بەڵام هەرزانتر دەوێت')->assertOk();

        $this->assertSame('advisory', $response->json('stage'));
        $this->assertTrue($response->json('complete'));
        $this->assertNotSame('', (string) $response->json('assistant_message.content'));
    }

    public function test_there_is_no_fixed_question_limit_after_completion(): void
    {
        $member = $this->member();
        $this->completeIntake($member);

        $before = AdvisorMessage::query()->where('role', 'assistant')->count();

        foreach (['یەکەم چۆنە؟', 'بەراورد بکە', 'قیست هەیە؟', 'زۆر باشە', 'سوپاس'] as $message) {
            $this->actingAs($member)->send($message)->assertOk()
                ->assertJsonPath('stage', 'advisory');
        }

        $this->assertSame(
            $before + 5,
            AdvisorMessage::query()->where('role', 'assistant')->count(),
            'every post-completion message must receive a persisted answer',
        );
    }

    /* ------------------------------------------ post-recommendation actions */

    public function test_a_budget_change_after_recommendations_recomputes_them(): void
    {
        $this->publishedProject('advisory-budget');
        $member = $this->member();
        $this->completeIntake($member);

        $response = $this->actingAs($member)
            ->send('What if my budget is 180 million IQD?', 'en')
            ->assertOk();

        // Fresh recommendations travelled with the turn, and the stored
        // criteria carry the revised, unit-expanded budget in dinars.
        $this->assertIsArray($response->json('recommendations'));
        $criteria = $this->rawCriteria($member);
        $this->assertSame('180000000', (string) $criteria['budget']);
        $this->assertSame('IQD', $criteria['currency']);

        $this->assertSame('advisory', $response->json('stage'));
    }

    public function test_a_bare_budget_number_after_recommendations_changes_nothing(): void
    {
        $member = $this->member();
        $this->completeIntake($member);

        // "180" carries no unit: under the interview's own budget rules it
        // must not overwrite anything.
        $this->actingAs($member)->send('180', 'en')->assertOk();

        $this->assertSame('150000', (string) $this->rawCriteria($member)['budget']);
    }

    public function test_comparison_answers_name_the_actual_recommended_projects(): void
    {
        $this->publishedProject('compare-one');
        $this->publishedProject('compare-two');

        $member = $this->member();
        $this->completeIntake($member);

        $response = $this->actingAs($member)->send('بەراوردی یەکەم و دووەم بکە')->assertOk();

        $content = (string) $response->json('assistant_message.content');
        $this->assertStringContainsString('compare-one', $content);
        $this->assertStringContainsString('compare-two', $content);
    }

    public function test_a_property_type_change_via_the_model_reruns_matching(): void
    {
        $this->aiOn();
        $this->fakeAi([
            'understanding' => '{"intent":"change_criteria","criteria_updates":{"property_type":"villa"},"referenced_positions":[],"needs_clarification":false}',
        ]);

        $member = $this->member();
        $this->completeIntake($member);

        $response = $this->actingAs($member)->send('بڕیارم گۆڕی، ڤێلام دەوێت')->assertOk();

        $typeRow = collect($response->json('summary'))->firstWhere('key', 'property_type');
        $this->assertSame('villa', $typeRow['value'] === null ? null : $this->rawCriteria($member)['property_type']);
        $this->assertIsArray($response->json('recommendations'));
    }

    /** @return array<string, mixed> */
    private function rawCriteria(User $member): array
    {
        return (array) $this->app['session.store']->get('advisor.criteria', []);
    }

    /* --------------------------------------------------- model provenance */

    public function test_a_sorani_intake_turn_is_generated_by_the_model_when_it_answers_in_sorani(): void
    {
        $this->aiOn();
        $this->fakeAi();

        $member = $this->member();
        $this->actingAs($member)->get('/advisor')->assertOk();

        $this->actingAs($member)->send('بۆ نیشتەجێبوون')->assertOk();

        $assistant = AdvisorMessage::query()->where('role', 'assistant')->latest('id')->firstOrFail();
        $this->assertSame('openai_compatible', $assistant->provider);
        $this->assertSame('fake-model', $assistant->model);
        $this->assertSame('model', $assistant->validation_detail['source'] ?? null);
    }

    public function test_foreign_language_model_output_for_a_sorani_turn_is_rejected_truthfully(): void
    {
        $this->aiOn();
        $this->fakeAi(['intake' => 'This is fluent English, which a Sorani conversation must refuse.']);

        $member = $this->member();
        $this->actingAs($member)->get('/advisor')->assertOk();

        $this->actingAs($member)->send('بۆ نیشتەجێبوون')->assertOk();

        $assistant = AdvisorMessage::query()->where('role', 'assistant')->latest('id')->firstOrFail();
        // Fallback, recorded as fallback: no provider, no model, and the
        // deterministic source stamped in the validation detail.
        $this->assertNull($assistant->provider);
        $this->assertSame('deterministic', $assistant->validation_detail['source'] ?? null);
        $this->assertSame('ckb', $assistant->locale);
    }

    public function test_arabic_and_english_multi_turn_conversations_stay_in_their_language(): void
    {
        foreach ([['ar', 'للسكن', 'شقة'], ['en', 'For living', 'Apartment']] as [$locale, $purpose, $type]) {
            $member = $this->member();
            $this->actingAs($member)->get('/advisor')->assertOk();

            $first = $this->actingAs($member)->send($purpose, $locale)->assertOk();
            $this->assertSame($locale, $first->json('conversation_locale'));
            $this->assertSame($locale, $first->json('assistant_message.locale'));

            $second = $this->actingAs($member)->send($type, $locale)->assertOk();
            $this->assertSame($locale, $second->json('assistant_message.locale'));
        }
    }

    /* ------------------------------------------------------------ recovery */

    public function test_a_provider_failure_mid_turn_loses_nothing_and_falls_back(): void
    {
        $this->aiOn();
        Http::fake(['ai.test/*' => Http::response(['error' => 'down'], 500)]);

        $member = $this->member();
        $this->actingAs($member)->get('/advisor')->assertOk();

        $response = $this->actingAs($member)->send('بۆ نیشتەجێبوون')->assertOk();

        // The person's message is stored, the answer is the deterministic
        // question, and the criteria advanced — the outage cost the phrasing,
        // not the turn.
        $this->assertSame(1, AdvisorMessage::query()->where('role', 'user')->count());
        $this->assertSame('currency', $response->json('next_slot'));
        $this->assertNull(AdvisorMessage::query()->where('role', 'assistant')->latest('id')->firstOrFail()->provider);
    }

    public function test_an_orphaned_user_turn_is_recovered_once_not_twice(): void
    {
        $member = $this->member();
        $this->actingAs($member)->get('/advisor')->assertOk();

        $conversation = AdvisorConversation::query()->firstOrFail();
        $orphan = AdvisorMessage::query()->create([
            'advisor_conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => 'بۆ نیشتەجێبوون',
            'locale' => 'ckb',
            'content_class' => 'fact',
        ]);
        // Old enough that the recovery path treats it as abandoned.
        $orphan->forceFill(['created_at' => now()->subMinute()])->save();

        $this->actingAs($member)->get('/advisor')->assertOk();
        $afterFirst = AdvisorMessage::query()->where('role', 'assistant')->count();
        $this->assertSame(1, $afterFirst, 'the orphaned turn receives exactly one recovery answer');

        $this->actingAs($member)->get('/advisor')->assertOk();
        $this->assertSame(
            $afterFirst,
            AdvisorMessage::query()->where('role', 'assistant')->count(),
            'a second render must not duplicate the recovery answer',
        );
    }

    /* ------------------------------------------------------- truthful state */

    public function test_the_page_reports_ai_unavailable_when_no_provider_is_selected(): void
    {
        $member = $this->member();

        $this->actingAs($member)->get('/advisor')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('ai_available', false)
                ->where('stage', 'intake'));
    }
}
