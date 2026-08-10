<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Advisor\Models\AdvisorMessage;
use App\Modules\Advisor\Support\NumericGuard;
use App\Modules\Advisor\Support\RetrievalGuard;
use App\Modules\Identity\Models\User;
use App\Modules\Projects\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Tests\TestCase;

/**
 * The hybrid architecture's hard rule, exercised on the LIVE path: the model
 * explains, it does not decide — and it cannot state a fact the published
 * data does not contain. Publication status gates what can be recommended at
 * all; NumericGuard gates every number the model wants to utter about it.
 */
final class AdvisorGroundingTest extends TestCase
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

    private function project(string $slug, string $status): Project
    {
        $project = Project::query()->create([
            'slug' => $slug,
            'name_ckb' => 'پڕۆژەی '.$slug,
            'name_en' => 'Project '.$slug,
            'project_type' => 'residential',
            'construction_status' => 'under_construction',
            'delivery_status' => 'not_started',
            'publication_status' => $status,
        ]);

        if ($status === 'published') {
            $project->forceFill(['published_at' => now()])->save();
        }

        return $project;
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

    /** Route the three prompt kinds; override only the advisory reply. */
    private function fakeAiWithAdvisory(string $advisoryReply): void
    {
        Http::fake(function ($request) use ($advisoryReply) {
            $body = $request->data();
            $system = (string) ($body['messages'][0]['content'] ?? '');
            $user = (string) ($body['messages'][1]['content'] ?? '');

            if (str_contains($system, 'output ONLY a JSON object')) {
                return Http::response(self::chat(
                    '{"intent":"explain","criteria_updates":{},"referenced_positions":[1],"needs_clarification":false}',
                ));
            }

            if (str_contains($system, 'answering a follow-up')) {
                return Http::response(self::chat($advisoryReply));
            }

            $decoded = json_decode($user, true);

            return Http::response(self::chat('باشە. '.(string) ($decoded['required_question'] ?? '')));
        });
    }

    private function completeIntake(User $member): void
    {
        $this->actingAs($member)->get('/advisor')->assertOk();
        $this->actingAs($member)->postJson('/advisor/reply', ['message' => 'بۆ نیشتەجێبوون', 'locale' => 'ckb'])->assertOk();
        $this->actingAs($member)->postJson('/advisor/reply', ['message' => '١٥٠ هەزار دۆلار', 'locale' => 'ckb'])->assertOk();
        $this->actingAs($member)->postJson('/advisor/reply', ['message' => 'شوقە', 'locale' => 'ckb'])->assertOk();
        $this->actingAs($member)->postJson('/advisor/reply', ['message' => 'بەسە', 'locale' => 'ckb'])->assertOk();
    }

    /* -------------------------------------------------- publication gating */

    public function test_a_draft_project_is_never_recommended(): void
    {
        $this->project('published-home', 'published');
        $this->project('draft-home', 'draft');

        $member = $this->member();
        $this->actingAs($member)->get('/advisor')->assertOk();
        $this->actingAs($member)->postJson('/advisor/reply', ['message' => 'بۆ نیشتەجێبوون', 'locale' => 'ckb']);
        $this->actingAs($member)->postJson('/advisor/reply', ['message' => '١٥٠ هەزار دۆلار', 'locale' => 'ckb']);
        $this->actingAs($member)->postJson('/advisor/reply', ['message' => 'شوقە', 'locale' => 'ckb']);
        $response = $this->actingAs($member)
            ->postJson('/advisor/reply', ['message' => 'بەسە', 'locale' => 'ckb'])
            ->assertOk();

        $slugs = collect((array) $response->json('recommendations.items'))->pluck('slug');

        $this->assertTrue($slugs->contains('published-home'));
        $this->assertFalse($slugs->contains('draft-home'), 'a draft must never surface as a recommendation');
    }

    /* --------------------------------------------------- numeric grounding */

    public function test_model_prose_with_an_invented_price_is_discarded(): void
    {
        $this->project('grounded-home', 'published');
        $this->aiOn();
        $this->fakeAiWithAdvisory('پڕۆژەی grounded-home نرخەکەی تەنها 185000 دۆلارە و گەرەنتی قازانجی 25% هەیە.');

        $member = $this->member();
        $this->completeIntake($member);

        $this->actingAs($member)
            ->postJson('/advisor/reply', ['message' => 'بۆچی یەکەمیان؟', 'locale' => 'ckb'])
            ->assertOk();

        $assistant = AdvisorMessage::query()->where('role', 'assistant')->latest('id')->firstOrFail();

        // The invented 185,000 USD and 25% return died at the guard: the
        // shipped turn is the deterministic answer, truthfully attributed.
        $this->assertNull($assistant->provider);
        $this->assertSame('deterministic', $assistant->validation_detail['source'] ?? null);
        $this->assertStringNotContainsString('185000', (string) $assistant->content);
    }

    public function test_model_prose_without_invented_numbers_ships_as_model(): void
    {
        $this->project('clean-home', 'published');
        $this->aiOn();
        $this->fakeAiWithAdvisory('پڕۆژەی clean-home لەگەڵ داواکارییەکەت دەگونجێت و نرخی بڵاوکراوەی نییە لە داتاکاندا.');

        $member = $this->member();
        $this->completeIntake($member);

        $this->actingAs($member)
            ->postJson('/advisor/reply', ['message' => 'بۆچی یەکەمیان؟', 'locale' => 'ckb'])
            ->assertOk();

        $assistant = AdvisorMessage::query()->where('role', 'assistant')->latest('id')->firstOrFail();

        $this->assertSame('openai_compatible', $assistant->provider);
        $this->assertSame('model', $assistant->validation_detail['source'] ?? null);
    }

    /* ------------------------------------------------------ guard contracts */

    public function test_the_retrieval_guard_refuses_drafts_foreign_records_and_unknown_sources(): void
    {
        $guard = new RetrievalGuard;

        $this->assertFalse($guard->admit(['type' => 'project', 'state' => 'draft'])['allowed']);
        $this->assertTrue($guard->admit(['type' => 'project', 'state' => 'published'])['allowed']);
        $this->assertFalse($guard->admit(['type' => 'secret_ledger', 'state' => 'published'])['allowed']);
        // Another user's conversation context is refused even when the state
        // string looks right — ownership, not labels, is the rule.
        $this->assertFalse($guard->admit(
            ['type' => 'conversation_context', 'state' => 'own', 'owner_id' => 7],
            requestingUserId: 8,
        )['allowed']);
    }

    #[RequiresPhpExtension('bcmath')]
    public function test_the_numeric_guard_permits_rounding_but_never_invention(): void
    {
        $guard = new NumericGuard;

        // 243,500 rounded to the claim's own precision IS 244,000 — honest.
        $rounded = $guard->validate('نزیکەی 244,000 دۆلار', ['243500']);
        $this->assertTrue($rounded['grounded']);

        // 245,000 rounds from nothing in evidence — an invention, flagged.
        $invented = $guard->validate('نزیکەی 245,000 دۆلار', ['243500']);
        $this->assertFalse($invented['grounded']);
        $this->assertNotSame([], $invented['ungrounded']);
    }
}
