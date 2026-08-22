<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Portfolio\Exceptions\ValuationRulePublishException;
use App\Modules\Portfolio\Models\ValuationQuestion;
use App\Modules\Portfolio\Models\ValuationQuestionOption;
use App\Modules\Portfolio\Models\ValuationRuleSet;
use App\Modules\Portfolio\Services\ValuationRulePublisher;
use App\Modules\Projects\Models\Project;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * PHASE 2 REVIEW PROBES — publish-time TOCTOU races, in INVARIANT form:
 * each test asserts what a correct publisher must guarantee, so on code
 * where the race exists the probe shows up as a FAILING test.
 *
 * The window under probe: ValuationRulePublisher::publish() validates the
 * draft's questions/options BEFORE DB::transaction(), and every decision
 * inside the transaction (predecessor selection, scope intersection,
 * activation, the ambiguous-scope post-check) reads the caller's IN-MEMORY
 * model, never a locked re-read of the row.
 *
 * The concurrent writer is reproduced deterministically with a DB::listen
 * hook: when the LAST pre-transaction validation read (the options eager
 * load) is observed, the hook performs the second request's write — a
 * perfectly legal draft edit at that instant — and disarms. No sleeps, no
 * timing, identical interleaving on SQLite and MariaDB. This is faithful
 * to the current code because publish() holds no lock at the mutation
 * point, so a real second connection's write would land the same way.
 *
 * Probed invariants:
 *   4a  an ACTIVE set must never hold content that publish validation
 *       would refuse — here, an active option outside the ±25 bound that
 *       was authored mid-publish (valid at validation, out of bounds at
 *       activation).
 *   4b  supersession and the post-check must see the target's PERSISTED
 *       scope: a property_types drift (apartment -> villa) after
 *       validation must not produce two actives both claiming villas in
 *       one family.
 *   4c  the same for the family itself: a project_id drift (global ->
 *       project) after validation must not activate the row into a family
 *       whose predecessors were never selected, locked or retired.
 *   4d  retirement metadata is written once: a second retire() through a
 *       stale in-memory instance must not rewrite retired_at.
 *
 * Every probe also asserts its own harness (the hook fired, the mutation
 * persisted), so a silently mis-aimed hook cannot produce a false PASS.
 */
final class ValuationPublishRaceProbeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  list<string>|null  $propertyTypes
     */
    private function draftSet(string $name, int $version, ?int $projectId = null, ?array $propertyTypes = null): ValuationRuleSet
    {
        return ValuationRuleSet::query()->create([
            'name' => $name,
            'scope_transaction' => ValuationRuleSet::SCOPE_TRANSACTION_SALE,
            'project_id' => $projectId,
            'property_types' => $propertyTypes,
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

    /**
     * Arm the concurrent writer: the first time the pre-transaction
     * validation's options eager load is observed, run $mutation and
     * disarm. The by-ref flag doubles as the fired-proof.
     */
    private function onOptionsValidationRead(bool &$armed, callable $mutation): void
    {
        DB::listen(static function (QueryExecuted $event) use (&$armed, $mutation): void {
            if (! $armed) {
                return;
            }

            if (! str_contains($event->sql, 'valuation_question_options')) {
                return;
            }

            if (! str_starts_with(strtolower(ltrim($event->sql)), 'select')) {
                return;
            }

            $armed = false;
            $mutation();
        });
    }

    public function test_probe_4a_an_active_set_never_holds_content_publish_validation_would_refuse(): void
    {
        $set = $this->draftSet('Probe 4a', 1);
        $question = $this->question($set, 'p4a_q');
        $option = $this->option($question, 'p4a_o');
        $optionId = $option->id;

        $armed = true;
        $this->onOptionsValidationRead($armed, static function () use ($optionId): void {
            /*
             * The second request's edit, landing AFTER the validation read
             * and BEFORE activation. The set is still a DRAFT at this
             * instant, so this exact save is legal for that request.
             */
            $fresh = ValuationQuestionOption::query()->findOrFail($optionId);
            $fresh->adjustment_percent = '30.000';
            $fresh->save();
        });

        $publisher = app(ValuationRulePublisher::class);

        try {
            $publisher->publish($set);
        } catch (ValuationRulePublishException) {
            // A refusal is a CORRECT outcome: drifted content must not go live.
        }

        // Harness proofs: the hook fired and the concurrent edit persisted.
        $this->assertFalse($armed, 'probe harness: the validation read was never observed');
        $this->assertSame(
            '30.000',
            (string) ValuationQuestionOption::query()->findOrFail($optionId)->adjustment_percent,
            'probe harness: the concurrent edit did not land',
        );

        // The invariant: no ACTIVE set may carry an active option outside
        // the authoring bound the publisher exists to prove.
        $offending = ValuationQuestionOption::query()
            ->where('is_active', true)
            ->where(static function ($query): void {
                $query->where('adjustment_percent', '>', 25)
                    ->orWhere('adjustment_percent', '<', -25);
            })
            ->whereHas('question', static function ($query): void {
                $query->where('is_active', true)
                    ->whereHas('ruleSet', static function ($inner): void {
                        $inner->where('status', ValuationRuleSet::STATUS_ACTIVE);
                    });
            })
            ->count();

        $this->assertSame(
            0,
            $offending,
            'an ACTIVE rule set holds an out-of-bounds percent — content drifted between validation and activation',
        );
    }

    public function test_probe_4b_supersession_and_post_check_see_the_targets_persisted_type_scope(): void
    {
        // Scope B occupant: an ACTIVE global set claiming villas.
        $villas = $this->draftSet('Probe 4b villas', 1, null, ['villa']);
        $this->option($this->question($villas, 'p4b_v_q'), 'p4b_v_o');

        $publisher = app(ValuationRulePublisher::class);
        $publisher->publish($villas);

        // The target: a draft in the SAME family, scope A (apartments) —
        // legitimately non-intersecting, so publishing it as-is would
        // correctly leave the villa set active alongside it.
        $draft = $this->draftSet('Probe 4b target', 2, null, ['apartment']);
        $this->option($this->question($draft, 'p4b_t_q'), 'p4b_t_o');
        $draftId = $draft->id;

        $armed = true;
        $this->onOptionsValidationRead($armed, static function () use ($draftId): void {
            // The second request repoints the still-draft target at villas
            // AFTER validation read the apartment scope into memory.
            $fresh = ValuationRuleSet::query()->findOrFail($draftId);
            $fresh->fill(['property_types' => ['villa']]);
            $fresh->save();
        });

        try {
            $publisher->publish($draft);
        } catch (ValuationRulePublishException) {
            // Refusing the drifted target is a CORRECT outcome.
        }

        $this->assertFalse($armed, 'probe harness: the validation read was never observed');
        $this->assertSame(
            ['villa'],
            ValuationRuleSet::query()->findOrFail($draftId)->property_types,
            'probe harness: the concurrent scope edit did not land',
        );

        /*
         * The invariant: whatever the outcome, at most ONE active set in
         * the global family may claim a villa. Two claimants mean the
         * predecessor filter and the post-check both judged intersection
         * against a stale in-memory scope while activation blessed the
         * persisted one.
         */
        $claimants = ValuationRuleSet::query()
            ->where('status', ValuationRuleSet::STATUS_ACTIVE)
            ->where('scope_transaction', ValuationRuleSet::SCOPE_TRANSACTION_SALE)
            ->whereNull('project_id')
            ->get()
            ->filter(static fn (ValuationRuleSet $candidate): bool => $candidate->appliesTo(null, 'villa'));

        $this->assertLessThanOrEqual(
            1,
            $claimants->count(),
            sprintf(
                'two ACTIVE sets both claim villas in one family (ids: %s) — supersession used a stale target scope',
                $claimants->pluck('id')->implode(', '),
            ),
        );
    }

    public function test_probe_4c_activation_cannot_land_in_a_family_whose_predecessors_were_never_selected(): void
    {
        $project = $this->project('probe-4c');

        // Scope B occupant: the project family's ACTIVE set, claiming
        // every property type of that project.
        $occupant = $this->draftSet('Probe 4c occupant', 1, $project->id);
        $this->option($this->question($occupant, 'p4c_o_q'), 'p4c_o_o');

        $publisher = app(ValuationRulePublisher::class);
        $publisher->publish($occupant);

        // The target: a GLOBAL draft (scope A). Version 2, so the drifted
        // row cannot be saved from the race by the family unique index.
        $draft = $this->draftSet('Probe 4c target', 2);
        $this->option($this->question($draft, 'p4c_t_q'), 'p4c_t_o');
        $draftId = $draft->id;
        $projectId = $project->id;

        $armed = true;
        $this->onOptionsValidationRead($armed, static function () use ($draftId, $projectId): void {
            // The second request moves the still-draft target into the
            // project family AFTER validation captured the global scope.
            $fresh = ValuationRuleSet::query()->findOrFail($draftId);
            $fresh->project_id = $projectId;
            $fresh->save();
        });

        try {
            $publisher->publish($draft);
        } catch (ValuationRulePublishException) {
            // Refusing the drifted target is a CORRECT outcome.
        }

        $this->assertFalse($armed, 'probe harness: the validation read was never observed');
        $this->assertSame(
            $projectId,
            ValuationRuleSet::query()->findOrFail($draftId)->project_id,
            'probe harness: the concurrent family move did not land',
        );

        // The invariant: at most one active set may claim this project's
        // properties. Two mean predecessor selection locked and swept the
        // WRONG family while activation landed the row in this one.
        $claimants = ValuationRuleSet::query()
            ->where('status', ValuationRuleSet::STATUS_ACTIVE)
            ->where('scope_transaction', ValuationRuleSet::SCOPE_TRANSACTION_SALE)
            ->get()
            ->filter(static fn (ValuationRuleSet $candidate): bool => $candidate->appliesTo($projectId, 'apartment'));

        $this->assertLessThanOrEqual(
            1,
            $claimants->count(),
            sprintf(
                'two ACTIVE sets both claim project %d (ids: %s) — activation landed in a family supersession never saw',
                $projectId,
                $claimants->pluck('id')->implode(', '),
            ),
        );
    }

    public function test_probe_4d_a_stale_retire_never_rewrites_the_retirement_timestamp(): void
    {
        $set = $this->draftSet('Probe 4d', 1);
        $this->option($this->question($set, 'p4d_q'), 'p4d_o');

        $publisher = app(ValuationRulePublisher::class);
        $publisher->publish($set);
        $set->refresh();

        // A second request's copy of the ACTIVE set, loaded before the
        // first retirement — the stale instance.
        $stale = ValuationRuleSet::query()->findOrFail($set->id);

        $publisher->retire($set);

        $firstRetiredAt = (string) ValuationRuleSet::query()->findOrFail($set->id)->retired_at;
        $this->assertNotSame('', $firstRetiredAt);

        // An hour later the stale instance retires "again". A correct
        // publisher refuses (the persisted row is no longer active); the
        // one thing that must not happen is a rewritten timestamp.
        $this->travel(1)->hours();

        try {
            $publisher->retire($stale);
        } catch (ValuationRulePublishException) {
            // The refusal is the CORRECT outcome.
        }

        $this->assertSame(
            $firstRetiredAt,
            (string) ValuationRuleSet::query()->findOrFail($set->id)->retired_at,
            'a second retire() through a stale in-memory instance rewrote retired_at',
        );
    }
}
