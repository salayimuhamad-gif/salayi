<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Advisor\Models\AdvisorMessage;
use App\Modules\Advisor\Services\AdvisorConversationService;
use App\Modules\Advisor\Services\AdvisorTurnUnderstanding;
use App\Modules\Geography\Models\Area;
use App\Modules\Identity\Models\User;
use App\Modules\Knowledge\Enums\KnowledgeStatus;
use App\Modules\Knowledge\Models\KnowledgeEvent;
use App\Modules\Projects\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * Phase 12: the advisor's market answers come from admin-recorded
 * KnowledgeEvents, in the voice of their evidence class, or not at all.
 *
 * The failure this phase closed (fail-before, reproduced on main): with an
 * approved, AI-permitted, sourced Ankawa price event in the database, "why
 * did prices rise in Ankawa?" was misrouted to a project explanation and
 * "what happened to prices in Ankawa?" fell into the generic continue_hint —
 * the recorded insight informed nothing, and no reply carried evidence.
 *
 * The contracts pinned here:
 *   - a market question is answered FROM the recorded insight, with the
 *     exact KnowledgeEvent ids persisted in evidence_ids;
 *   - only aiUsable rows can ever reach a reply (draft, review, rejected,
 *     expired and non-permitted rows are invisible);
 *   - each evidence class keeps its stance — a verified fact is stated with
 *     its source, an observation/interpretation/prediction is NEVER phrased
 *     as fact, and a null legacy class reads as observation;
 *   - with no recorded insight the answer is an honest "nothing recorded",
 *     never an invented reason and never the generic hint;
 *   - all of it works with AI off, and the AI-on path stays draft-bound.
 *
 * Every provider interaction is Http::fake — nothing leaves the process.
 */
final class AdvisorMarketIntelligenceTest extends TestCase
{
    use RefreshDatabase;

    /** The ckb generic catch-all this phase closed for market questions. */
    private const CONTINUE_HINT_CKB = 'دەتوانیت پرسیار لەسەر پێشنیارەکان بکەیت';

    /** The ckb verified-fact stance prefix ("according to"). */
    private const FACT_STANCE_CKB = 'بەپێی';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->setFeatures(['advisor.residential' => true]);
    }

    /* ----------------------------------------------------------- fixtures */

    private function member(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['telegram_verified_at' => now()])->save();

        return $user;
    }

    private function ankawa(): Area
    {
        return Area::query()->firstOrCreate(['slug' => 'ankawa'], [
            'type' => 'district',
            'name_ckb' => 'ئەنکاوە',
            'name_ar' => 'عنكاوة',
            'name_en' => 'Ankawa',
            'publication_status' => 'published',
        ]);
    }

    private function publishedProject(string $slug = 'market-home', ?int $areaId = null): Project
    {
        $project = Project::query()->firstOrCreate(['slug' => $slug], [
            'name_ckb' => 'پڕۆژەی '.$slug,
            'name_en' => 'Project '.$slug,
            'project_type' => 'residential',
            'construction_status' => 'under_construction',
            'delivery_status' => 'not_started',
            'publication_status' => 'published',
        ]);
        $project->forceFill(['published_at' => now(), 'area_id' => $areaId])->save();

        return $project;
    }

    /**
     * An AI-usable Ankawa price-rise event; overrides shape the variants.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function insight(array $overrides = []): KnowledgeEvent
    {
        $event = new KnowledgeEvent;
        $event->forceFill(array_merge([
            'entity_type' => 'area',
            'entity_id' => $this->ankawa()->id,
            'title_ckb' => 'بەرزبوونەوەی نرخەکان لە ئەنکاوە',
            'title_ar' => 'ارتفاع الأسعار في عنكاوة',
            'title_en' => 'Price rise in Ankawa',
            'summary_ckb' => 'نرخی موڵک لە ئەنکاوە بەرزبووەتەوە بەهۆی داواکاری زیاتر.',
            'summary_ar' => 'ارتفعت أسعار العقارات في عنكاوة بسبب زيادة الطلب.',
            'summary_en' => 'Property prices in Ankawa rose on higher demand.',
            'event_type' => 'price',
            'direction' => 'positive',
            'strength' => 4,
            'effective_date' => '2026-08-01',
            'source' => 'دائیرەی ئاماری هەولێر',
            'confidence' => 'high',
            'evidence_class' => 'verified_fact',
            'ai_usage_permitted' => true,
            'status' => KnowledgeStatus::Approved,
        ], $overrides));
        $event->save();

        return $event;
    }

    /** Drive the interview to the advisory stage, answering the area slot. */
    private function completeIntake(User $member, string $area = 'ئەنکاوە'): void
    {
        $this->actingAs($member)->get('/advisor')->assertOk();
        $this->send($member, 'بۆ نیشتەجێبوون')->assertOk();
        $this->send($member, '١٥٠ هەزار دۆلار')->assertOk();
        $this->send($member, 'شوقە')->assertOk();
        $this->send($member, $area)->assertOk();
        $this->send($member, 'بەسە')->assertOk()->assertJsonPath('complete', true);
    }

    /** @return TestResponse<Response> */
    private function send(User $member, string $message, string $locale = 'ckb'): TestResponse
    {
        return $this->actingAs($member)->postJson('/advisor/reply', [
            'message' => $message,
            'locale' => $locale,
        ]);
    }

    /** The latest persisted assistant turn. */
    private function lastAssistant(): AdvisorMessage
    {
        return AdvisorMessage::query()->where('role', 'assistant')->latest('id')->firstOrFail();
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
     * The three prompt kinds routed by system prompt; understanding answers
     * market_question, and every advisory request body is captured so the
     * evidence the model was actually shown can be asserted.
     *
     * @param  list<array<string, mixed>>  $advisoryBodies  filled by reference
     */
    private function fakeAiForMarket(array &$advisoryBodies, string|callable|null $advisory = null): void
    {
        Http::fake(function ($request) use (&$advisoryBodies, $advisory) {
            $body = $request->data();
            $system = (string) ($body['messages'][0]['content'] ?? '');
            $user = (string) ($body['messages'][1]['content'] ?? '');

            if (str_contains($system, 'output ONLY a JSON object')) {
                return Http::response(self::chat(
                    '{"intent":"market_question","criteria_updates":{},"referenced_positions":[],"needs_clarification":false}',
                ));
            }

            if (str_contains($system, 'answering a follow-up')) {
                $decoded = (array) json_decode($user, true);
                $advisoryBodies[] = $decoded;

                if ($advisory === null) {
                    // A well-behaved model: echo the grounded draft.
                    return Http::response(self::chat((string) ($decoded['draft_answer'] ?? '')));
                }

                return Http::response(self::chat(is_callable($advisory) ? $advisory($decoded) : $advisory));
            }

            $decoded = (array) json_decode($user, true);

            return Http::response(self::chat('باشە. '.(string) ($decoded['required_question'] ?? '')));
        });
    }

    /* -------------------------------------------- A. grounding happy path */

    public function test_a_market_question_is_answered_from_the_recorded_insight_with_provenance(): void
    {
        $this->publishedProject();
        $event = $this->insight();

        $member = $this->member();
        $this->completeIntake($member);

        // The exact production phrasing — carries the explain keyword بۆچی,
        // which misrouted it pre-fix.
        $response = $this->send($member, 'بۆچی نرخەکان لە ئەنکاوە بەرزبوونەوە؟')->assertOk();
        $content = (string) $response->json('assistant_message.content');

        $this->assertStringNotContainsString(self::CONTINUE_HINT_CKB, $content);
        $this->assertStringContainsString('داواکاری زیاتر', $content, 'the recorded summary must reach the reply');
        $this->assertStringContainsString(self::FACT_STANCE_CKB.' دائیرەی ئاماری هەولێر', $content, 'a verified fact attributes its source');
        $this->assertStringContainsString('2026-08-01', $content, 'the as-of date is stated');

        $assistant = $this->lastAssistant();
        $this->assertSame([$event->id], array_map('intval', (array) $assistant->evidence_ids));
        $this->assertSame('analysis', $assistant->content_class);
        // AI off: the grounded answer is deterministic, and says so.
        $this->assertNull($assistant->provider);
        $this->assertSame('deterministic', $assistant->validation_detail['source'] ?? null);
    }

    public function test_the_area_named_in_the_question_itself_scopes_retrieval(): void
    {
        $this->publishedProject();
        $event = $this->insight();

        $member = $this->member();
        // The interview recorded NO area preference at all.
        $this->completeIntake($member, 'گرنگ نییە');

        $response = $this->send($member, 'چی بەسەر نرخەکان هاتووە لە ئەنکاوە؟')->assertOk();

        $this->assertStringContainsString('داواکاری زیاتر', (string) $response->json('assistant_message.content'));
        $this->assertSame([$event->id], array_map('intval', (array) $this->lastAssistant()->evidence_ids));
    }

    public function test_an_insight_on_a_recommended_project_grounds_the_answer(): void
    {
        $project = $this->publishedProject();
        $event = $this->insight([
            'entity_type' => 'project',
            'entity_id' => $project->id,
            'title_ckb' => 'نرخی پڕۆژەکە نوێ کرایەوە',
            'summary_ckb' => 'خشتەی نرخی ئەم پڕۆژەیە لە مانگی ئابدا نوێ کرایەوە.',
        ]);

        $member = $this->member();
        $this->completeIntake($member);

        $this->send($member, 'چی بەسەر نرخەکان هاتووە؟')->assertOk();

        $this->assertContains($event->id, array_map('intval', (array) $this->lastAssistant()->evidence_ids));
    }

    public function test_with_no_area_preference_the_recommended_projects_areas_stand_in(): void
    {
        $area = $this->ankawa();
        $this->publishedProject('area-home', $area->id);
        $event = $this->insight();

        $member = $this->member();
        $this->completeIntake($member, 'گرنگ نییە');

        // No area in the question either: the recommendations' own areas scope it.
        $this->send($member, 'چی بەسەر نرخەکان دێت؟')->assertOk();

        $this->assertContains($event->id, array_map('intval', (array) $this->lastAssistant()->evidence_ids));
    }

    /* ---------------------------------------------------- B/F. isolation */

    public function test_an_unrelated_areas_insight_is_never_used(): void
    {
        $this->publishedProject();
        $other = Area::query()->create([
            'type' => 'district',
            'slug' => 'kasnazan',
            'name_ckb' => 'کەسنەزان',
            'publication_status' => 'published',
        ]);
        $this->insight([
            'entity_id' => $other->id,
            'title_ckb' => 'گۆڕانکاری لە کەسنەزان',
            'summary_ckb' => 'نرخەکان لە کەسنەزان جیاوازن.',
        ]);

        $member = $this->member();
        $this->completeIntake($member); // explicit area: Ankawa

        $response = $this->send($member, 'بۆچی نرخەکان لە ئەنکاوە بەرزبوونەوە؟')->assertOk();
        $content = (string) $response->json('assistant_message.content');

        // Honest no-data for the area actually asked about — the other
        // area's insight must not stand in for it.
        $this->assertStringNotContainsString('کەسنەزان', $content);
        $this->assertStringContainsString('هیچ زانیارییەکی بازاڕی تۆمارکراوم نییە', $content);
        $this->assertSame([], (array) $this->lastAssistant()->evidence_ids);
    }

    public function test_no_recorded_insight_yields_an_honest_answer_not_the_generic_hint(): void
    {
        $this->publishedProject();

        $member = $this->member();
        $this->completeIntake($member);

        $response = $this->send($member, 'بۆچی نرخەکان لە ئەنکاوە بەرزبوونەوە؟')->assertOk();
        $content = (string) $response->json('assistant_message.content');

        $this->assertStringContainsString('هیچ زانیارییەکی بازاڕی تۆمارکراوم نییە', $content);
        $this->assertStringContainsString('تۆمار نەکراوە', $content, 'the answer says it cannot state an unrecorded reason');
        $this->assertStringNotContainsString(self::CONTINUE_HINT_CKB, $content);
        $this->assertSame([], (array) $this->lastAssistant()->evidence_ids);
    }

    /* ---------------------------------------------- C. leakage protection */

    public function test_only_ai_usable_rows_can_reach_a_reply(): void
    {
        $this->publishedProject();

        $leaks = [
            'draft' => ['status' => KnowledgeStatus::Draft],
            'review' => ['status' => KnowledgeStatus::Review],
            'rejected' => ['status' => KnowledgeStatus::Rejected],
            'expired' => ['expires_on' => now()->subDay()->toDateString()],
            'not permitted' => ['ai_usage_permitted' => false],
        ];

        foreach ($leaks as $label => $overrides) {
            KnowledgeEvent::query()->forceDelete();
            $this->insight($overrides + ['summary_ckb' => 'ئەم دەقە نابێت دەربکەوێت.']);

            $member = $this->member();
            $this->completeIntake($member);

            $response = $this->send($member, 'بۆچی نرخەکان لە ئەنکاوە بەرزبوونەوە؟')->assertOk();
            $content = (string) $response->json('assistant_message.content');

            $this->assertStringNotContainsString('ئەم دەقە نابێت دەربکەوێت', $content, "a {$label} event must never be used");
            $this->assertSame([], (array) $this->lastAssistant()->evidence_ids, "a {$label} event must leave no evidence trail");
        }
    }

    /* ------------------------------------------------- D. evidence classes */

    public function test_a_verified_fact_is_stated_directly_with_its_source(): void
    {
        $this->publishedProject();
        $this->insight(); // verified_fact, high confidence

        $member = $this->member();
        $this->completeIntake($member);

        $content = (string) $this->send($member, 'بۆچی نرخەکان لە ئەنکاوە بەرزبوونەوە؟')
            ->assertOk()->json('assistant_message.content');

        $this->assertStringContainsString(self::FACT_STANCE_CKB.' دائیرەی ئاماری هەولێر', $content);
        // A fact needs no hedge — no observation/interpretation/prediction voice.
        $this->assertStringNotContainsString('تیمەکەمان ئەم تێبینییەی تۆمار کردووە', $content);
        $this->assertStringNotContainsString('پێشبینی', $content);
    }

    public function test_an_admin_observation_is_framed_as_recorded_by_the_team(): void
    {
        $this->publishedProject();
        $this->insight(['evidence_class' => 'admin_observation']);

        $member = $this->member();
        $this->completeIntake($member);

        $content = (string) $this->send($member, 'بۆچی نرخەکان لە ئەنکاوە بەرزبوونەوە؟')
            ->assertOk()->json('assistant_message.content');

        $this->assertStringContainsString('تیمەکەمان ئەم تێبینییەی تۆمار کردووە', $content);
        $this->assertStringNotContainsString(self::FACT_STANCE_CKB.' ', $content, 'an observation must never wear the fact stance');
    }

    public function test_a_market_interpretation_is_framed_as_a_reading_with_its_confidence(): void
    {
        $this->publishedProject();
        $this->insight(['evidence_class' => 'market_interpretation', 'confidence' => 'medium']);

        $member = $this->member();
        $this->completeIntake($member);

        $content = (string) $this->send($member, 'بۆچی نرخەکان لە ئەنکاوە بەرزبوونەوە؟')
            ->assertOk()->json('assistant_message.content');

        $this->assertStringContainsString('بۆچوونە، نەک ڕاستییەکی پشتڕاستکراو', $content);
        // Confidence hedges WITHIN the class — it never upgrades it.
        $this->assertStringContainsString('متمانە: مامناوەند', $content);
        $this->assertStringNotContainsString(self::FACT_STANCE_CKB.' ', $content);
    }

    public function test_a_prediction_is_framed_as_an_expectation_on_its_expected_date(): void
    {
        $this->publishedProject();
        $this->insight([
            'evidence_class' => 'prediction',
            'title_ckb' => 'چاوەڕوانی بەرزبوونەوەی نرخ لە ئەنکاوە',
            'summary_ckb' => 'چاوەڕوان دەکرێت نرخەکان بەرز ببنەوە.',
            'expected_date' => '2026-12-01',
        ]);

        $member = $this->member();
        $this->completeIntake($member);

        $content = (string) $this->send($member, 'بۆچی نرخەکان لە ئەنکاوە بەرزبوونەوە؟')
            ->assertOk()->json('assistant_message.content');

        $this->assertStringContainsString('پێشبینییە و هێشتا پشتڕاست نەکراوەتەوە', $content);
        $this->assertStringContainsString('2026-12-01', $content, 'a prediction carries its expected date');
        $this->assertStringNotContainsString(self::FACT_STANCE_CKB.' ', $content);
    }

    /* ------------------------------------------------------ E. legacy null */

    public function test_a_legacy_unclassified_event_reads_as_an_observation_never_a_fact(): void
    {
        $this->publishedProject();
        $this->insight(['evidence_class' => null]);

        $member = $this->member();
        $this->completeIntake($member);

        $content = (string) $this->send($member, 'بۆچی نرخەکان لە ئەنکاوە بەرزبوونەوە؟')
            ->assertOk()->json('assistant_message.content');

        $this->assertStringContainsString('تیمەکەمان ئەم تێبینییەی تۆمار کردووە', $content);
        $this->assertStringNotContainsString(self::FACT_STANCE_CKB.' ', $content, 'null must never be read as verified_fact');
    }

    /* -------------------------------------------------------- G. AI-on path */

    public function test_with_ai_on_the_structured_evidence_reaches_the_prompt_and_the_model_stays_draft_bound(): void
    {
        $this->publishedProject();
        $event = $this->insight(['evidence_class' => 'admin_observation']);

        $this->aiOn();
        $advisoryBodies = [];
        $this->fakeAiForMarket($advisoryBodies);

        $member = $this->member();
        $this->completeIntake($member);

        $response = $this->send($member, 'بۆچی نرخەکان لە ئەنکاوە بەرزبوونەوە؟')->assertOk();

        // The model was shown the insight as STRUCTURE — class, dates, source
        // — alongside a draft that already embodies the stance.
        $this->assertNotSame([], $advisoryBodies, 'the advisory prompt must have been issued');
        $insights = (array) ($advisoryBodies[0]['market_insights'] ?? []);
        $this->assertSame('admin_observation', $insights[0]['evidence_class'] ?? null);
        $this->assertSame('2026-08-01', $insights[0]['effective_date'] ?? null);
        $this->assertStringContainsString(
            'تیمەکەمان ئەم تێبینییەی تۆمار کردووە',
            (string) ($advisoryBodies[0]['draft_answer'] ?? ''),
            'the draft the model may rephrase already carries the stance',
        );

        // The echoed draft ships as model output — grounded by construction —
        // and the provenance still names the exact row.
        $assistant = $this->lastAssistant();
        $this->assertSame('openai_compatible', $assistant->provider);
        $this->assertStringContainsString('داواکاری زیاتر', (string) $response->json('assistant_message.content'));
        $this->assertSame([$event->id], array_map('intval', (array) $assistant->evidence_ids));
    }

    public function test_a_guard_rejected_model_reply_falls_back_to_the_grounded_draft_not_generic_text(): void
    {
        $this->publishedProject();
        $event = $this->insight();

        $this->aiOn();
        $advisoryBodies = [];
        // Fluent English for a Sorani conversation: the language gate refuses it.
        $this->fakeAiForMarket($advisoryBodies, 'Prices in Ankawa rose sharply and will keep rising, trust me.');

        $member = $this->member();
        $this->completeIntake($member);

        $response = $this->send($member, 'بۆچی نرخەکان لە ئەنکاوە بەرزبوونەوە؟')->assertOk();
        $content = (string) $response->json('assistant_message.content');

        $assistant = $this->lastAssistant();
        $this->assertNull($assistant->provider);
        $this->assertSame('deterministic', $assistant->validation_detail['source'] ?? null);
        // The fallback is the GROUNDED draft — insight, stance and evidence
        // intact — not the generic hint the old dead end shipped.
        $this->assertStringContainsString('داواکاری زیاتر', $content);
        $this->assertStringContainsString(self::FACT_STANCE_CKB.' دائیرەی ئاماری هەولێر', $content);
        $this->assertSame([$event->id], array_map('intval', (array) $assistant->evidence_ids));
    }

    public function test_model_prose_with_an_invented_price_dies_at_the_numeric_guard(): void
    {
        $this->publishedProject();
        $this->insight();

        $this->aiOn();
        $advisoryBodies = [];
        // 185000 appears in no card, no insight and no visitor message — the
        // exact invented-price failure mode the guard exists to stop.
        $this->fakeAiForMarket($advisoryBodies, 'نرخی شوقە لە ئەنکاوە ئێستا 185000 دۆلارە و بەرزیش دەبێتەوە.');

        $member = $this->member();
        $this->completeIntake($member);

        $response = $this->send($member, 'بۆچی نرخەکان لە ئەنکاوە بەرزبوونەوە؟')->assertOk();

        $assistant = $this->lastAssistant();
        $this->assertSame('deterministic', $assistant->validation_detail['source'] ?? null);
        $this->assertStringNotContainsString('185000', (string) $response->json('assistant_message.content'));
    }

    /* --------------------------------------------------- H. intent routing */

    public function test_market_questions_are_recognized_deterministically_in_all_three_languages(): void
    {
        $service = $this->app->make(AdvisorConversationService::class);

        $market = [
            'بۆچی نرخەکان لە ئەنکاوە بەرزبوونەوە؟',
            'چی بەسەر نرخەکان هاتووە لە ئەنکاوە؟',
            'ئایا ئەنکاوە شوێنێکی باشە بۆ کڕین ئێستا؟',
            'ليش ارتفعت الأسعار في عنكاوة؟',
            'شنو صاير بأسعار عنكاوة؟',
            'هل عنكاوة منطقة زينة للشراء هسه؟',
            'Why did prices rise in Ankawa?',
            'What is happening with prices in Ankawa?',
            'Is Ankawa a good place to buy now?',
        ];

        foreach ($market as $text) {
            $this->assertSame('market_question', $service->fallbackIntent($text), $text);
        }
    }

    public function test_existing_intents_are_not_stolen_by_the_market_recognizer(): void
    {
        $service = $this->app->make(AdvisorConversationService::class);

        $unchanged = [
            'بەراورد بکە' => 'compare',
            'بەراوردی نرخەکان بکە' => 'compare',
            'سوپاس' => 'finish',
            'هەرزانتر هەیە؟' => 'ask_cheaper',
            'بۆچی یەکەمیان؟' => 'explain',
            'ليش الأول؟' => 'explain',
            'قیست هەیە؟' => 'project_question',
            // A lone price word without a movement or assessment stays put.
            'What is the price of the first one?' => 'other',
        ];

        foreach ($unchanged as $text => $intent) {
            $this->assertSame($intent, $service->fallbackIntent($text), $text);
        }
    }

    public function test_the_understanding_validator_accepts_the_market_intent(): void
    {
        $understanding = $this->app->make(AdvisorTurnUnderstanding::class);

        $result = $understanding->validate(
            '{"intent":"market_question","criteria_updates":{},"referenced_positions":[],"needs_clarification":false}',
        );

        $this->assertSame('market_question', $result['intent'] ?? null);
    }

    /* ------------------------------------------------- 11. multilingual */

    public function test_arabic_and_english_conversations_answer_in_their_own_stance_templates(): void
    {
        $this->publishedProject();
        $this->insight();

        foreach ([
            ['ar', ['للسكن', '150 ألف دولار', 'شقة', 'عنكاوة', 'كافي اعرض النتائج'], 'ليش ارتفعت الأسعار في عنكاوة؟', 'حسب دائیرەی ئاماری هەولێر', 'زيادة الطلب'],
            ['en', ['For living', '150 thousand USD', 'Apartment', 'Ankawa', 'finish'], 'Why did prices rise in Ankawa?', 'According to دائیرەی ئاماری هەولێر', 'higher demand'],
        ] as [$locale, $intake, $question, $stance, $summaryBit]) {
            $member = $this->member();
            $this->actingAs($member)->get('/advisor')->assertOk();

            foreach ($intake as $answer) {
                $this->send($member, $answer, $locale)->assertOk();
            }

            $response = $this->send($member, $question, $locale)->assertOk();
            $content = (string) $response->json('assistant_message.content');

            $this->assertStringContainsString($stance, $content, "{$locale}: fact stance in the conversation language");
            $this->assertStringContainsString($summaryBit, $content, "{$locale}: the localized summary is used");
        }
    }

    /* ------------------------------------- I. validation and admin surface */

    public function test_evidence_class_is_required_and_closed_on_create(): void
    {
        $admin = User::factory()->superAdmin()->create();

        $payload = [
            'title_ckb' => 'ڕووداوی تاقیکردنەوە',
            'event_type' => 'price',
            'direction' => 'positive',
            'strength' => 3,
            'effective_date' => '2026-08-01',
            'confidence' => 'medium',
        ];

        $this->actingAs($admin)->post('/admin/knowledge', $payload)
            ->assertSessionHasErrors('evidence_class');

        $this->actingAs($admin)->post('/admin/knowledge', $payload + ['evidence_class' => 'gossip'])
            ->assertSessionHasErrors('evidence_class');
    }

    public function test_a_verified_fact_cannot_be_recorded_without_a_source(): void
    {
        $admin = User::factory()->superAdmin()->create();

        $this->actingAs($admin)->post('/admin/knowledge', [
            'title_ckb' => 'ڕاستی بێ سەرچاوە',
            'event_type' => 'price',
            'direction' => 'positive',
            'strength' => 3,
            'effective_date' => '2026-08-01',
            'confidence' => 'high',
            'evidence_class' => 'verified_fact',
            'source' => '',
        ])->assertSessionHasErrors('evidence_class');
    }

    public function test_an_area_scoped_classified_event_is_created_through_the_admin_endpoint(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $area = $this->ankawa();

        $this->actingAs($admin)->post('/admin/knowledge', [
            'title_ckb' => 'تێبینی بازاڕی ئەنکاوە',
            'summary_ckb' => 'داواکاری لە ئەنکاوە زیادی کردووە.',
            'event_type' => 'demand',
            'direction' => 'positive',
            'strength' => 3,
            'entity_type' => 'area',
            'entity_id' => $area->id,
            'effective_date' => '2026-08-01',
            'confidence' => 'medium',
            'evidence_class' => 'admin_observation',
        ])->assertSessionHasNoErrors();

        $event = KnowledgeEvent::query()->latest('id')->firstOrFail();
        $this->assertSame('area', $event->entity_type);
        $this->assertSame($area->id, $event->entity_id);
        $this->assertSame('admin_observation', $event->evidence_class?->value);
        $this->assertSame(KnowledgeStatus::Draft, $event->status, 'the workflow still starts at draft');
    }

    public function test_the_admin_form_offers_the_closed_class_set_and_areas(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $this->ankawa();

        $this->actingAs($admin)->get('/admin/knowledge')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Knowledge/Index')
                ->has('options.evidence_classes', 4)
                ->has('options.entity_types', 2)
                ->where('options.areas.0.label', 'ئەنکاوە'));
    }

    public function test_every_new_phase12_key_ships_in_all_three_locales(): void
    {
        $keys = [
            'knowledge.entity_type',
            'knowledge.evidence_class',
            'knowledge.evidence_class_hint',
            'knowledge.entity_types.project',
            'knowledge.entity_types.area',
            'knowledge.evidence_classes.verified_fact',
            'knowledge.evidence_classes.admin_observation',
            'knowledge.evidence_classes.market_interpretation',
            'knowledge.evidence_classes.prediction',
            'knowledge.errors.fact_needs_source',
        ];

        foreach (['ckb', 'ar', 'en'] as $locale) {
            foreach ($keys as $key) {
                $value = __($key, [], $locale);
                $this->assertIsString($value);
                $this->assertNotSame($key, $value, "{$key} must be translated in {$locale}");
            }
        }
    }
}
