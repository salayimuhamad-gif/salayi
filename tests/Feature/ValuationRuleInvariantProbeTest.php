<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Portfolio\Models\ValuationQuestion;
use App\Modules\Portfolio\Models\ValuationQuestionOption;
use App\Modules\Portfolio\Models\ValuationRuleSet;
use App\Modules\Portfolio\Services\ValuationRulePublisher;
use App\Modules\Projects\Models\Project;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * PHASE 1 REVIEW PROBES — invariant tests for three suspected lifecycle
 * bypasses, written in INVARIANT form: each asserts what a correct system
 * must refuse, so on the current code a confirmed hole shows up as a
 * FAILING test. They are expected to fail until the fixes are approved
 * and applied, at which point this file becomes the regression suite for
 * exactly these holes — no rewrite needed.
 *
 * Probed invariants:
 *   1a  draft -> active must be impossible by plain model save; only the
 *       publisher (with its content validation and supersession) may
 *       activate a set.
 *   1b  retirement metadata must not be independently mutable on an
 *       active set: retired_at may only change together with the
 *       status transition to retired.
 *   2a  a question of a PUBLISHED set must not escape the freeze by
 *       being reparented onto a draft set first.
 *   2b  an option of a PUBLISHED set must not escape the freeze by
 *       being reparented onto a draft set's question first.
 *   3a  version numbers must be unique within the GLOBAL scope family
 *       (project_id NULL) even when two writers compute the next
 *       version concurrently — the race's damage is reproduced
 *       deterministically by taking both MAX+1 reads BEFORE either
 *       insert, exactly the interleaving two concurrent
 *       duplicateAsDraft() calls produce.
 *   3b  contrast control: the same duplicate insert in a PROJECT family
 *       IS refused by the composite unique index, proving the hole is
 *       specific to NULL project_id (NULLs never collide in a unique
 *       index on either engine).
 */
final class ValuationRuleInvariantProbeTest extends TestCase
{
    use RefreshDatabase;

    private function draftSet(string $name, int $version, ?int $projectId = null): ValuationRuleSet
    {
        return ValuationRuleSet::query()->create([
            'name' => $name,
            'scope_transaction' => ValuationRuleSet::SCOPE_TRANSACTION_SALE,
            'project_id' => $projectId,
            'version' => $version,
            'status' => ValuationRuleSet::STATUS_DRAFT,
        ]);
    }

    private function question(ValuationRuleSet $set, string $key): ValuationQuestion
    {
        return ValuationQuestion::query()->create([
            'valuation_rule_set_id' => $set->id,
            'key' => $key,
            'label_ckb' => 'پرسیار '.$key,
            'label_ar' => 'سؤال '.$key,
            'label_en' => 'Question '.$key,
            'sort_order' => 0,
            'is_active' => true,
        ]);
    }

    private function option(ValuationQuestion $question, string $key): ValuationQuestionOption
    {
        return ValuationQuestionOption::query()->create([
            'valuation_question_id' => $question->id,
            'key' => $key,
            'label_ckb' => 'هەڵبژاردە '.$key,
            'label_ar' => 'خيار '.$key,
            'label_en' => 'Option '.$key,
            'adjustment_percent' => '5.000',
            'sort_order' => 0,
            'is_active' => true,
        ]);
    }

    /**
     * A set published through the REAL lifecycle, with one question and
     * one option.
     *
     * @return array{0: ValuationRuleSet, 1: ValuationQuestion, 2: ValuationQuestionOption}
     */
    private function activeSet(string $keyPrefix, int $version): array
    {
        $set = $this->draftSet('Active '.$keyPrefix, $version);
        $question = $this->question($set, $keyPrefix.'_q');
        $option = $this->option($question, $keyPrefix.'_o');

        $publisher = app(ValuationRulePublisher::class);
        $publisher->publish($set);
        $set->refresh();

        return [$set, $question, $option];
    }

    public function test_probe_1a_a_draft_cannot_become_active_by_plain_save(): void
    {
        $set = $this->draftSet('Probe 1a', 1);

        $this->expectException(RuntimeException::class);

        // Bypass attempt: no publisher, no content validation, no
        // supersession — just a model save flipping the status.
        $set->status = ValuationRuleSet::STATUS_ACTIVE;
        $set->save();
    }

    public function test_probe_1b_retirement_metadata_is_not_independently_mutable_on_an_active_set(): void
    {
        [$set] = $this->activeSet('p1b', 1);

        $this->expectException(RuntimeException::class);

        // Bypass attempt: stamp retired_at while the status stays active —
        // a set that claims to be live and retired at once.
        $set->retired_at = now();
        $set->save();
    }

    public function test_probe_2a_a_published_question_cannot_escape_the_freeze_by_reparenting(): void
    {
        [, $question] = $this->activeSet('p2a', 1);
        $draft = $this->draftSet('Probe 2a target', 2);

        $this->expectException(RuntimeException::class);

        // Bypass attempt: point the ACTIVE set's question at a draft set;
        // a guard that reads only the NEW parent sees "draft" and allows
        // the active set's content to change.
        $question->valuation_rule_set_id = $draft->id;
        $question->save();
    }

    public function test_probe_2b_a_published_option_cannot_escape_the_freeze_by_reparenting(): void
    {
        [, , $option] = $this->activeSet('p2b', 1);
        $draft = $this->draftSet('Probe 2b target', 2);
        $draftQuestion = $this->question($draft, 'p2b_target_q');

        $this->expectException(RuntimeException::class);

        // Bypass attempt: move the ACTIVE set's option under a draft
        // question — same escape as 2a, one level down.
        $option->valuation_question_id = $draftQuestion->id;
        $option->save();
    }

    public function test_probe_3a_the_global_family_refuses_duplicate_version_numbers(): void
    {
        $publisher = app(ValuationRulePublisher::class);

        /*
         * The exact interleaving of two concurrent writers: BOTH compute
         * the next version before EITHER inserts. MAX+1 with no lock and
         * no enforceable unique constraint (NULL project_id rows never
         * collide) means both reads agree...
         */
        $first = $publisher->nextVersion(ValuationRuleSet::SCOPE_TRANSACTION_SALE, null);
        $second = $publisher->nextVersion(ValuationRuleSet::SCOPE_TRANSACTION_SALE, null);

        $this->assertSame($first, $second);

        // ...and both inserts land. For the invariant to hold, at most ONE
        // row with this version may exist in the global family afterwards.
        $this->draftSet('Racer A', $first);
        $this->draftSet('Racer B', $second);

        $this->assertSame(
            1,
            ValuationRuleSet::query()
                ->where('scope_transaction', ValuationRuleSet::SCOPE_TRANSACTION_SALE)
                ->whereNull('project_id')
                ->where('version', $first)
                ->count(),
            'the GLOBAL scope family accepted duplicate version numbers',
        );
    }

    public function test_probe_3b_contrast_a_project_family_is_defended_by_the_unique_index(): void
    {
        $project = Project::query()->create([
            'slug' => 'probe-3b',
            'name_ckb' => 'پڕۆژەی probe-3b',
            'name_en' => 'Project probe-3b',
            'project_type' => 'residential',
            'construction_status' => 'under_construction',
            'delivery_status' => 'not_started',
            'publication_status' => 'published',
        ]);

        $this->draftSet('Project racer A', 1, $project->id);

        // Non-NULL project_id: the composite unique index works, so the
        // duplicate is refused at the database. This passing while 3a
        // fails localises the hole to NULL-family versioning exactly.
        $this->expectException(QueryException::class);

        $this->draftSet('Project racer B', 1, $project->id);
    }
}
