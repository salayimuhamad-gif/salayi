<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Portfolio\Exceptions\ValuationRulePublishException;
use App\Modules\Portfolio\Models\ValuationQuestion;
use App\Modules\Portfolio\Models\ValuationQuestionOption;
use App\Modules\Portfolio\Models\ValuationRuleSet;
use App\Modules\Portfolio\Services\ValuationRulePublisher;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * PHASE 2 REVIEW PROBES — the DRAFT-EDIT side of the publish races, in
 * INVARIANT form: each test asserts what a correct system must guarantee,
 * so where the hole exists the probe FAILS.
 *
 * The claim under probe: an editor that observed DRAFT and a publisher
 * that wins the race can interleave so the editor's write lands on the
 * now-ACTIVE set. Two distinct defects produce it:
 *
 *   - the SET row's own guards judge the IN-MEMORY original status
 *     (getOriginal), so even a fully sequential stale-instance write
 *     lands after activation (5a);
 *   - the child guards DO re-read the parent freshly, but the read and
 *     the write are two separate unlocked statements, so a write whose
 *     guard read pre-dates the publish commit still lands after it
 *     (5b, 5c, 5d) — exactly the order a blocked writer resumes in;
 *   - publish validation reads only the ACTIVE subset, so a mid-publish
 *     activation flip of an INACTIVE row smuggles never-validated
 *     content into the published set (5g, 5h).
 *
 * All probes are deterministic SAME-CONNECTION replays of the racing
 * statement order (guard read -> publish commit -> write). A true second
 * connection cannot be used inside this harness: RefreshDatabase keeps
 * fixtures uncommitted (invisible to any other connection), and a single
 * PHP thread waiting on its own row lock would deadlock. The statement
 * order replayed here is byte-identical to what MariaDB's lock queue
 * produces when the blocked writer resumes; the defect being proven is
 * the non-atomic check-then-write, which is order, not locking.
 *
 * 5e and 5f are the two POSITIVE controls: the edit that wins first is
 * seen by publish validation, and a fresh post-publish child edit is
 * already refused (the child guards' fresh read catches the sequential
 * case — the set row's guards, per 5a, do not).
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
     * Interleave $between after the NEXT child-guard status read — the
     * aggregate count the question/option guards run against $table —
     * and before the write that follows it. This is the exact point a
     * blocked writer's resumed statement occupies.
     */
    private function onGuardStatusRead(bool &$armed, string $table, callable $between): void
    {
        DB::listen(static function (QueryExecuted $event) use (&$armed, $table, $between): void {
            if (! $armed) {
                return;
            }

            $sql = strtolower($event->sql);

            if (! str_starts_with(ltrim($sql), 'select') || ! str_contains($sql, 'aggregate') || ! str_contains($sql, $table)) {
                return;
            }

            $armed = false;
            $between();
        });
    }

    /**
     * Interleave $between after publish()'s LAST validation read (the
     * options eager load) — inside the publisher's window, before
     * activation.
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
        $setId = $set->id;

        $armed = true;
        $this->onGuardStatusRead($armed, 'valuation_questions', static function () use ($setId): void {
            // Publisher A commits BETWEEN B's guard read (which saw
            // draft) and B's UPDATE — the blocked-writer resume order.
            $fresh = ValuationRuleSet::query()->findOrFail($setId);
            app(ValuationRulePublisher::class)->publish($fresh);
        });

        try {
            $option->adjustment_percent = '30.000';
            $option->save();
        } catch (RuntimeException) {
            // Refusal is the CORRECT outcome.
        }

        $this->assertFalse($armed, 'probe harness: the guard read was never observed');
        $this->assertSame(
            ValuationRuleSet::STATUS_ACTIVE,
            ValuationRuleSet::query()->findOrFail($setId)->status,
            'probe harness: the interleaved publish never happened',
        );

        $this->assertSame(
            '5.000',
            (string) ValuationQuestionOption::query()->findOrFail($option->id)->adjustment_percent,
            'a stale option write landed on an ACTIVE set — the guard read and the write are not one atomic step',
        );
    }

    public function test_probe_5c_a_blocked_stale_question_delete_cannot_land_after_activation(): void
    {
        $set = $this->draftSet('Probe 5c', 1);
        $this->option($this->question($set, 'p5c_keep'), 'p5c_keep_o');
        $doomed = $this->question($set, 'p5c_doomed');
        $this->option($doomed, 'p5c_doomed_o');
        $setId = $set->id;

        $armed = true;
        $this->onGuardStatusRead($armed, 'valuation_rule_sets', static function () use ($setId): void {
            $fresh = ValuationRuleSet::query()->findOrFail($setId);
            app(ValuationRulePublisher::class)->publish($fresh);
        });

        try {
            $doomed->delete();
        } catch (RuntimeException) {
            // Refusal is the CORRECT outcome.
        }

        $this->assertFalse($armed, 'probe harness: the guard read was never observed');
        $this->assertSame(
            ValuationRuleSet::STATUS_ACTIVE,
            ValuationRuleSet::query()->findOrFail($setId)->status,
            'probe harness: the interleaved publish never happened',
        );

        $this->assertTrue(
            ValuationQuestion::query()->whereKey($doomed->id)->exists(),
            'a stale delete removed a question (and its options, by cascade) from an ACTIVE set',
        );
    }

    public function test_probe_5d_a_blocked_stale_question_insert_cannot_land_under_an_active_set(): void
    {
        $set = $this->draftSet('Probe 5d', 1);
        $this->option($this->question($set, 'p5d_q'), 'p5d_o');
        $setId = $set->id;

        $armed = true;
        $this->onGuardStatusRead($armed, 'valuation_rule_sets', static function () use ($setId): void {
            $fresh = ValuationRuleSet::query()->findOrFail($setId);
            app(ValuationRulePublisher::class)->publish($fresh);
        });

        try {
            $this->question($set, 'smuggled');
        } catch (RuntimeException) {
            // Refusal is the CORRECT outcome.
        }

        $this->assertFalse($armed, 'probe harness: the guard read was never observed');
        $this->assertSame(
            ValuationRuleSet::STATUS_ACTIVE,
            ValuationRuleSet::query()->findOrFail($setId)->status,
            'probe harness: the interleaved publish never happened',
        );

        $this->assertSame(
            0,
            ValuationQuestion::query()->where('valuation_rule_set_id', $setId)->where('key', 'smuggled')->count(),
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
}
