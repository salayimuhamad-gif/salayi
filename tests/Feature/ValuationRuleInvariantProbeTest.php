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
 * PHASE 1 REVIEW PROBES — invariant tests for three lifecycle bypasses,
 * written in INVARIANT form: each asserts what a correct system must
 * refuse. On the pre-fix code every confirmed hole showed up as a FAILING
 * test (CI run #254 on cc445d5 — probes 1a, 1b, 2a, 2b and 3a); with the
 * fixes applied this file is the permanent regression suite for exactly
 * those holes, plus the positive coverage proving the legal moves still
 * work.
 *
 * Probed invariants:
 *   1a  draft -> active must be impossible by plain model save; only the
 *       publisher (with its content validation and supersession) may
 *       activate a set.
 *   1b  retirement metadata must not be independently mutable on an
 *       active set: retired_at may only change together with the
 *       status transition to retired.
 *   1c  active -> retired must go through the publisher too — even the
 *       correctly shaped transition (status + retired_at together) is
 *       refused as a plain save.
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
 *       IS refused by the 000100 composite unique index, proving hole 3
 *       was specific to NULL project_id (NULLs never collide in a
 *       unique index on either engine).
 *
 * The positive side — draft -> draft reparenting, distinct versions in
 * one family, the same version across distinct families, and the
 * publisher's own publish/supersede/retire channel — is covered at the
 * bottom, so the freeze can never quietly widen into refusing the moves
 * the design allows.
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

    public function test_probe_1c_an_active_set_cannot_be_retired_by_plain_save(): void
    {
        [$set] = $this->activeSet('p1c', 1);

        $this->expectException(RuntimeException::class);

        // Bypass attempt: the full retirement shape (status + retired_at
        // together) — legal in meaning, but through a plain save instead of
        // the publisher, which is the only channel allowed to move
        // lifecycle columns.
        $set->status = ValuationRuleSet::STATUS_RETIRED;
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
         * the next version before EITHER inserts. MAX+1 with no lock means
         * both reads agree...
         */
        $first = $publisher->nextVersion(ValuationRuleSet::SCOPE_TRANSACTION_SALE, null);
        $second = $publisher->nextVersion(ValuationRuleSet::SCOPE_TRANSACTION_SALE, null);

        $this->assertSame($first, $second);

        /*
         * ...but only the FIRST insert lands. The project_family generated
         * key (migration 2026_08_22_000200) folds NULL project_id into an
         * indexable 0, so the second writer dies on vrs_family_version_unique
         * at the database — the race's damage is refused, not stored.
         */
        $this->draftSet('Racer A', $first);

        try {
            $this->draftSet('Racer B', $second);
            $this->fail('the GLOBAL scope family accepted duplicate version numbers');
        } catch (QueryException) {
            // Refused by the database, exactly where a race cannot skip it.
        }

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

    /* ---------------------------------------------------------------------
     * positive coverage — the legal moves the freeze must never swallow
     * ------------------------------------------------------------------- */

    private function project(string $slug): Project
    {
        return Project::query()->create([
            'slug' => $slug,
            'name_ckb' => 'پڕۆژەی '.$slug,
            'name_en' => 'Project '.$slug,
            'project_type' => 'residential',
            'construction_status' => 'under_construction',
            'delivery_status' => 'not_started',
            'publication_status' => 'published',
        ]);
    }

    public function test_a_draft_question_may_reparent_onto_another_draft(): void
    {
        $source = $this->draftSet('Draft source', 1);
        $target = $this->draftSet('Draft target', 2);
        $question = $this->question($source, 'movable_q');

        // Both endpoints are drafts, so the move is authoring, not escape.
        $question->valuation_rule_set_id = $target->id;
        $question->save();

        $this->assertSame($target->id, $question->refresh()->valuation_rule_set_id);
    }

    public function test_a_draft_option_may_reparent_onto_another_draft_question(): void
    {
        $source = $this->draftSet('Draft option source', 1);
        $target = $this->draftSet('Draft option target', 2);
        $option = $this->option($this->question($source, 'src_q'), 'movable_o');
        $targetQuestion = $this->question($target, 'dst_q');

        $option->valuation_question_id = $targetQuestion->id;
        $option->save();

        $this->assertSame($targetQuestion->id, $option->refresh()->valuation_question_id);
    }

    public function test_distinct_versions_still_coexist_in_the_global_family(): void
    {
        $this->draftSet('Global v1', 1);
        $this->draftSet('Global v2', 2);

        // The family index binds (scope, family, version) — the version
        // SEQUENCE within one family stays perfectly legal.
        $this->assertSame(
            2,
            ValuationRuleSet::query()
                ->where('scope_transaction', ValuationRuleSet::SCOPE_TRANSACTION_SALE)
                ->whereNull('project_id')
                ->count(),
        );
    }

    public function test_the_same_version_number_still_coexists_across_distinct_families(): void
    {
        $projectA = $this->project('family-a');
        $projectB = $this->project('family-b');

        // coalesce(project_id, 0) keeps families APART: global folds to 0,
        // never onto a real project id, so version 1 exists once per family
        // without any cross-family collision.
        $this->draftSet('Global v1', 1);
        $this->draftSet('Project A v1', 1, $projectA->id);
        $this->draftSet('Project B v1', 1, $projectB->id);

        $this->assertSame(3, ValuationRuleSet::query()->where('version', 1)->count());
    }

    public function test_the_publisher_remains_the_working_lifecycle_channel(): void
    {
        [$set] = $this->activeSet('lifecycle', 1);

        // Publish worked: the guard refuses plain saves, not the publisher.
        $this->assertSame(ValuationRuleSet::STATUS_ACTIVE, $set->status);
        $this->assertNotNull($set->published_at);

        // Supersession still works: v2 publishes and retires v1 atomically.
        $publisher = app(ValuationRulePublisher::class);
        $publisher->duplicateAsDraft($set);

        $v2 = ValuationRuleSet::query()->orderByDesc('id')->firstOrFail();
        $publisher->publish($v2);

        $retired = ValuationRuleSet::query()->findOrFail($set->id);
        $this->assertSame(ValuationRuleSet::STATUS_RETIRED, $retired->status);
        $this->assertNotNull($retired->retired_at);

        // And the explicit retirement path still works too.
        $active = ValuationRuleSet::query()->findOrFail($v2->id);
        $publisher->retire($active);

        $this->assertSame(
            ValuationRuleSet::STATUS_RETIRED,
            ValuationRuleSet::query()->findOrFail($v2->id)->status,
        );
    }
}
