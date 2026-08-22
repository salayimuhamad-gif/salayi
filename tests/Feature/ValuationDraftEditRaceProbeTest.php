<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Portfolio\Exceptions\ValuationRulePublishException;
use App\Modules\Portfolio\Models\ValuationQuestion;
use App\Modules\Portfolio\Models\ValuationQuestionOption;
use App\Modules\Portfolio\Models\ValuationRuleSet;
use App\Modules\Portfolio\Services\ValuationRuleEditor;
use App\Modules\Portfolio\Services\ValuationRulePublisher;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * PHASE 2 REVIEW PROBES — the DRAFT-EDIT side of the publish races, in
 * INVARIANT form: each test asserts what a correct system must guarantee.
 * Against the pre-fix code (run #259) six of these failed; under the
 * serialization fix they are the permanent regression suite for it.
 *
 *   5a  a stale draft-era instance writing the SET row after activation —
 *       refused by the guards' PERSISTED-status read, at the model layer,
 *       whatever path the write takes.
 *   5b/5c/5d  option update, question delete, question insert arriving
 *       after a publish won — driven through the SERIALIZED editor, the
 *       production write path: the locked re-check reads ACTIVE and
 *       refuses before the operation closure ever runs (each probe's
 *       operationRan flag proves child resolution happens only after
 *       the locked parent re-check). The genuine blocked-then-resume
 *       ordering with real row locks is proven separately, cross-process
 *       on MariaDB, in ValuationSerializationConcurrencyTest.
 *   5e/5f  POSITIVE controls: an edit that wins the race outright is
 *       seen by publish validation and refused; a freshly-loaded child
 *       edit after publish is refused by the child guard's fresh read.
 *   5g/5h  an INACTIVE question/option flipped active INSIDE the publish
 *       window (same-connection DB::listen injection): the publisher's
 *       final fresh re-assertions detect the never-validated content and
 *       roll the whole transition back, flip included.
 *
 * Residual, stated plainly: a RAW model-layer child write that bypasses
 * the editor still performs its fresh status check and its write as two
 * separate statements; every production write path routes through the
 * editor, so that gap is reachable only by code that ignores the
 * serialization seam on purpose.
 */
final class ValuationDraftEditRaceProbeTest extends TestCase
{
    use RefreshDatabase;

    private function draftSet(string $name, int $version): ValuationRuleSet
    {
        return ValuationRuleSet::query()->create([
            'name' => $name,
            'scope_transaction' => ValuationRuleSet::SCOPE_TRANSACTION_SALE,
            'project_id' => null,
            'version' => $version,
            'status' => ValuationRuleSet::STATUS_DRAFT,
        ]);
    }

    private function question(ValuationRuleSet $set, string $key, bool $isActive = true): ValuationQuestion
    {
        return ValuationQuestion::query()->create([
            'valuation_rule_set_id' => $set->id,
            'key' => $key,
            'label_ckb' => 'پرسیار '.$key,
            'label_ar' => 'سؤال '.$key,
            'label_en' => 'Question '.$key,
            'sort_order' => 0,
            'is_active' => $isActive,
        ]);
    }

    private function option(
        ValuationQuestion $question,
        string $key,
        string $percent = '5.000',
        bool $isActive = true,
    ): ValuationQuestionOption {
        return ValuationQuestionOption::query()->create([
            'valuation_question_id' => $question->id,
            'key' => $key,
            'label_ckb' => 'هەڵبژاردە '.$key,
            'label_ar' => 'خيار '.$key,
            'label_en' => 'Option '.$key,
            'adjustment_percent' => $percent,
            'sort_order' => 0,
            'is_active' => $isActive,
        ]);
    }

    /**
     * Interleave $between after publish()'s options validation read —
     * inside the publisher's window, before activation.
     */
    private function onPublishValidationRead(bool &$armed, callable $between): void
    {
        DB::listen(static function (QueryExecuted $event) use (&$armed, $between): void {
            if (! $armed) {
                return;
            }

            $sql = strtolower($event->sql);

            if (! str_starts_with(ltrim($sql), 'select') || ! str_contains($sql, 'valuation_question_options')) {
                return;
            }

            $armed = false;
            $between();
        });
    }

    public function test_probe_5a_a_stale_draft_era_instance_cannot_edit_the_set_after_activation(): void
    {
        $set = $this->draftSet('Probe 5a', 1);
        $this->option($this->question($set, 'p5a_q'), 'p5a_o');

        // Editor B loads the set while it is a DRAFT...
        $stale = ValuationRuleSet::query()->findOrFail($set->id);

        // ...publisher A wins the race completely...
        $publisher = app(ValuationRulePublisher::class);
        $publisher->publish($set);

        // ...and B's write arrives after activation. No interleaving is
        // even needed: the set guards judge the instance's ORIGINAL
        // status, which still says draft.
        try {
            $stale->name = 'Edited after activation';
            $stale->save();
        } catch (RuntimeException) {
            // Refusal is the CORRECT outcome.
        }

        $this->assertSame(
            ValuationRuleSet::STATUS_ACTIVE,
            ValuationRuleSet::query()->findOrFail($set->id)->status,
            'probe harness: the publish never happened',
        );

        $this->assertSame(
            'Probe 5a',
            ValuationRuleSet::query()->findOrFail($set->id)->name,
            'a stale draft-era instance edited an ACTIVE set — the set guards judge in-memory state, not the persisted row',
        );
    }

    public function test_probe_5b_a_blocked_stale_option_update_cannot_land_after_activation(): void
    {
        $set = $this->draftSet('Probe 5b', 1);
        $option = $this->option($this->question($set, 'p5b_q'), 'p5b_o');

        // Publisher A wins; editor B's serialized write then arrives — the
        // order a blocked writer resumes in once A's commit releases the
        // parent row lock.
        $publisher = app(ValuationRulePublisher::class);
        $publisher->publish($set);

        $editor = app(ValuationRuleEditor::class);
        $operationRan = false;
        $refused = false;

        try {
            $editor->withLockedDraft($set->id, static function () use (&$operationRan): void {
                $operationRan = true;
            });
        } catch (ValuationRulePublishException $e) {
            $refused = true;
            $this->assertSame('frozen', $e->errorKey);
        }

        $this->assertTrue($refused, 'the editor accepted an ACTIVE set');
        $this->assertFalse($operationRan, 'the editor ran an operation against a non-draft set');

        $this->assertSame(
            ValuationRuleSet::STATUS_ACTIVE,
            ValuationRuleSet::query()->findOrFail($set->id)->status,
            'probe harness: the publish never happened',
        );

        $this->assertSame(
            '5.000',
            (string) ValuationQuestionOption::query()->findOrFail($option->id)->adjustment_percent,
            'a stale option write landed on an ACTIVE set',
        );
    }

    public function test_probe_5c_a_blocked_stale_question_delete_cannot_land_after_activation(): void
    {
        $set = $this->draftSet('Probe 5c', 1);
        $this->option($this->question($set, 'p5c_keep'), 'p5c_keep_o');
        $doomed = $this->question($set, 'p5c_doomed');
        $this->option($doomed, 'p5c_doomed_o');

        $publisher = app(ValuationRulePublisher::class);
        $publisher->publish($set);

        $editor = app(ValuationRuleEditor::class);
        $operationRan = false;
        $refused = false;

        try {
            $editor->withLockedDraft($set->id, static function () use (&$operationRan): void {
                $operationRan = true;
            });
        } catch (ValuationRulePublishException $e) {
            $refused = true;
            $this->assertSame('frozen', $e->errorKey);
        }

        $this->assertTrue($refused, 'the editor accepted an ACTIVE set');
        $this->assertFalse($operationRan, 'the editor resolved a child of a non-draft set for deletion');

        $this->assertTrue(
            ValuationQuestion::query()->whereKey($doomed->id)->exists(),
            'a stale delete removed a question (and its options, by cascade) from an ACTIVE set',
        );
    }

    public function test_probe_5d_a_blocked_stale_question_insert_cannot_land_under_an_active_set(): void
    {
        $set = $this->draftSet('Probe 5d', 1);
        $this->option($this->question($set, 'p5d_q'), 'p5d_o');

        $publisher = app(ValuationRulePublisher::class);
        $publisher->publish($set);

        $editor = app(ValuationRuleEditor::class);
        $operationRan = false;
        $refused = false;

        try {
            $editor->withLockedDraft($set->id, static function () use (&$operationRan): void {
                $operationRan = true;
            });
        } catch (ValuationRulePublishException $e) {
            $refused = true;
            $this->assertSame('frozen', $e->errorKey);
        }

        $this->assertTrue($refused, 'the editor accepted an ACTIVE set');
        $this->assertFalse($operationRan, 'the editor ran a create against a non-draft set');

        $this->assertSame(
            0,
            ValuationQuestion::query()->where('valuation_rule_set_id', $set->id)->where('key', 'smuggled')->count(),
            'a stale insert added a never-validated question to an ACTIVE set',
        );
    }

    public function test_probe_5e_an_edit_that_wins_the_race_is_seen_by_publish_validation(): void
    {
        $set = $this->draftSet('Probe 5e', 1);
        $option = $this->option($this->question($set, 'p5e_q'), 'p5e_o');

        // Editor B wins outright: the out-of-bounds percent commits while
        // the set is still a draft — a legal edit.
        $option->adjustment_percent = '30.000';
        $option->save();

        // Publish must now validate the FINAL content and refuse it.
        try {
            app(ValuationRulePublisher::class)->publish($set);
            $this->fail('publish blessed content it never validated');
        } catch (ValuationRulePublishException $e) {
            $this->assertSame('adjustment_out_of_bounds', $e->errorKey);
        }

        $this->assertSame(
            ValuationRuleSet::STATUS_DRAFT,
            ValuationRuleSet::query()->findOrFail($set->id)->status,
            'a refused publish must leave the draft a draft',
        );
    }

    public function test_probe_5f_a_fresh_edit_after_publish_wins_is_refused(): void
    {
        $set = $this->draftSet('Probe 5f', 1);
        $option = $this->option($this->question($set, 'p5f_q'), 'p5f_o');

        $publisher = app(ValuationRulePublisher::class);
        $publisher->publish($set);

        // The editor re-loads AFTER losing the race — the fresh guard
        // read sees ACTIVE and must refuse. (This passing while 5a fails
        // localises the sequential-stale hole to the SET row's guards.)
        $fresh = ValuationQuestionOption::query()->findOrFail($option->id);

        $this->expectException(RuntimeException::class);

        $fresh->adjustment_percent = '24.000';
        $fresh->save();
    }

    public function test_probe_5g_an_inactive_question_cannot_become_active_behind_publish_validation(): void
    {
        $set = $this->draftSet('Probe 5g', 1);
        $this->option($this->question($set, 'p5g_visible'), 'p5g_visible_o');

        // Invisible to validation: the question is INACTIVE, and its
        // active option carries a percent the bound forbids.
        $hidden = $this->question($set, 'p5g_hidden', isActive: false);
        $this->option($hidden, 'p5g_hidden_o', percent: '35.000');
        $hiddenId = $hidden->id;

        $armed = true;
        $this->onPublishValidationRead($armed, static function () use ($hiddenId): void {
            // Editor B flips the hidden question live INSIDE the
            // publisher's window — the set is still a draft, so the
            // child guard allows it.
            $fresh = ValuationQuestion::query()->findOrFail($hiddenId);
            $fresh->is_active = true;
            $fresh->save();
        });

        try {
            app(ValuationRulePublisher::class)->publish($set);
        } catch (ValuationRulePublishException) {
            // Refusal is the CORRECT outcome.
        }

        $this->assertFalse($armed, 'probe harness: the validation read was never observed');

        $freshSet = ValuationRuleSet::query()->findOrFail($set->id);
        $freshHidden = ValuationQuestion::query()->findOrFail($hiddenId);

        $this->assertFalse(
            $freshSet->status === ValuationRuleSet::STATUS_ACTIVE && $freshHidden->is_active,
            'a question that was inactive at validation time is ACTIVE under a PUBLISHED set — its 35.000 option was never validated',
        );
    }

    public function test_probe_5h_an_inactive_option_cannot_become_active_behind_publish_validation(): void
    {
        $set = $this->draftSet('Probe 5h', 1);
        $question = $this->question($set, 'p5h_q');
        $this->option($question, 'p5h_visible');

        // Invisible to validation: inactive, out of bounds.
        $hidden = $this->option($question, 'p5h_hidden', percent: '35.000', isActive: false);
        $hiddenId = $hidden->id;

        $armed = true;
        $this->onPublishValidationRead($armed, static function () use ($hiddenId): void {
            $fresh = ValuationQuestionOption::query()->findOrFail($hiddenId);
            $fresh->is_active = true;
            $fresh->save();
        });

        try {
            app(ValuationRulePublisher::class)->publish($set);
        } catch (ValuationRulePublishException) {
            // Refusal is the CORRECT outcome.
        }

        $this->assertFalse($armed, 'probe harness: the validation read was never observed');

        $freshSet = ValuationRuleSet::query()->findOrFail($set->id);
        $freshHidden = ValuationQuestionOption::query()->findOrFail($hiddenId);

        $this->assertFalse(
            $freshSet->status === ValuationRuleSet::STATUS_ACTIVE && $freshHidden->is_active,
            'an option that was inactive at validation time is ACTIVE at 35.000 under a PUBLISHED set',
        );
    }

    /* ---------------------------------------------------------------------
     * serialization-contract coverage — the fix's own regression pins
     * ------------------------------------------------------------------- */

    public function test_a_retired_set_still_deletes_through_the_serialized_path(): void
    {
        $set = $this->draftSet('Retired delete', 1);
        $this->option($this->question($set, 'rd_q'), 'rd_o');

        $publisher = app(ValuationRulePublisher::class);
        $publisher->publish($set);
        $publisher->retire(ValuationRuleSet::query()->findOrFail($set->id));

        // The delete path must serialize on the same lock WITHOUT becoming
        // draft-only: retired sets stay deletable (history lives in the
        // valuation snapshots, never in the live rule tables).
        $editor = app(ValuationRuleEditor::class);
        $deleted = $editor->deleteSetIfNotActive($set->id);

        $this->assertSame(ValuationRuleSet::STATUS_RETIRED, $deleted->status);
        $this->assertFalse(ValuationRuleSet::query()->whereKey($set->id)->exists());
    }

    public function test_a_stale_draft_era_instance_cannot_delete_an_active_set(): void
    {
        $set = $this->draftSet('Stale delete', 1);
        $this->option($this->question($set, 'sd_q'), 'sd_o');

        // Editor B loads the set while it is a DRAFT...
        $stale = ValuationRuleSet::query()->findOrFail($set->id);

        // ...the publisher wins completely...
        $publisher = app(ValuationRulePublisher::class);
        $publisher->publish($set);

        // ...and B's delete is refused by the persisted-status guard, even
        // at the raw model layer with no editor in sight.
        $this->expectException(RuntimeException::class);

        $stale->delete();
    }

    public function test_the_editor_resolves_children_through_the_locked_set(): void
    {
        $set = $this->draftSet('Editor positive', 1);
        $option = $this->option($this->question($set, 'ep_q'), 'ep_o');
        $optionId = $option->id;

        // The positive half of the contract: on a genuine draft the
        // operation runs against children resolved THROUGH the locked set,
        // and the closure's return value comes back to the caller.
        $editor = app(ValuationRuleEditor::class);

        $updated = $editor->withLockedDraft($set->id, static function (ValuationRuleSet $locked) use ($optionId): ValuationQuestionOption {
            /** @var ValuationQuestion $question */
            $question = $locked->questions()->firstOrFail();
            /** @var ValuationQuestionOption $row */
            $row = $question->options()->findOrFail($optionId);
            $row->adjustment_percent = '7.500';
            $row->save();

            return $row;
        });

        $this->assertSame('7.500', (string) $updated->adjustment_percent);
        $this->assertSame(
            '7.500',
            (string) ValuationQuestionOption::query()->findOrFail($optionId)->adjustment_percent,
        );
    }

    public function test_an_inactive_question_stays_frozen_after_publication(): void
    {
        $set = $this->draftSet('Post-publish question flip', 1);
        $this->option($this->question($set, 'pqf_active'), 'pqf_o');
        $dormant = $this->question($set, 'pqf_dormant', isActive: false);

        $publisher = app(ValuationRulePublisher::class);
        $publisher->publish($set);

        // Published means ALL of it is published — an inactive question of
        // an active set cannot be flipped live afterwards; that would be
        // content nothing ever validated.
        $this->expectException(RuntimeException::class);

        $dormant->is_active = true;
        $dormant->save();
    }

    public function test_an_inactive_option_stays_frozen_after_publication(): void
    {
        $set = $this->draftSet('Post-publish option flip', 1);
        $question = $this->question($set, 'pof_q');
        $this->option($question, 'pof_live');
        $dormant = $this->option($question, 'pof_dormant', percent: '35.000', isActive: false);

        $publisher = app(ValuationRulePublisher::class);
        $publisher->publish($set);

        $this->expectException(RuntimeException::class);

        $dormant->is_active = true;
        $dormant->save();
    }
}
