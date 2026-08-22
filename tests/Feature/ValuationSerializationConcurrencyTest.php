<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Portfolio\Exceptions\ValuationRulePublishException;
use App\Modules\Portfolio\Models\ValuationQuestion;
use App\Modules\Portfolio\Models\ValuationQuestionOption;
use App\Modules\Portfolio\Models\ValuationRuleSet;
use App\Modules\Portfolio\Services\ValuationRulePublisher;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * GENUINE cross-process concurrency evidence for the editor/publisher
 * serialization — the one place the suite uses a REAL second database
 * connection instead of a deterministic same-connection replay.
 *
 * Deliberately NOT RefreshDatabase: the child process is a separate PDO
 * connection and can only see COMMITTED rows, so this class commits its
 * fixtures for real and removes them in tearDown (which also runs after
 * failures). The child is tests/Support/locked_edit_attempt.php — it
 * boots the application, runs the serialized editor against the same
 * set, and reports one JSON line.
 *
 * Case A  the editor wins outright: its committed edit is what publish
 *         validation then judges (and refuses, since the edit is out of
 *         bounds). Runs on both engines.
 * Case B  the publisher wins the lock: on MariaDB the child provably
 *         starts its attempt while the publish transaction is open
 *         (the parent holds the lock until the child's marker exists,
 *         then two more seconds), blocks on the real row lock, and
 *         after the commit re-reads ACTIVE and refuses — no child write
 *         lands. On SQLite there is no row lock to wait on
 *         (lockForUpdate compiles to nothing there); contention
 *         surfaces as the database-level busy state instead, and the
 *         acceptable outcomes are a busy refusal or the frozen refusal
 *         — never a stale write on an ACTIVE set.
 */
final class ValuationSerializationConcurrencyTest extends TestCase
{
    /** @var list<int> */
    private array $setIds = [];

    /** @var list<string> */
    private array $markers = [];

    protected function tearDown(): void
    {
        /*
         * Committed fixtures must not outlive this class: later suites
         * assume the shared database carries only their own rows. Raw
         * deletes are deliberate HARNESS cleanup — children cascade at
         * the database, and an active set could not be deleted through
         * the guarded model path anyway.
         */
        if ($this->setIds !== []) {
            DB::table('valuation_rule_sets')->whereIn('id', $this->setIds)->delete();
        }

        foreach ($this->markers as $marker) {
            @unlink($marker);
        }

        parent::tearDown();
    }

    /**
     * @return array{0: ValuationRuleSet, 1: ValuationQuestionOption}
     */
    private function committedDraft(string $name): array
    {
        $set = ValuationRuleSet::query()->create([
            'name' => $name,
            'scope_transaction' => ValuationRuleSet::SCOPE_TRANSACTION_SALE,
            'project_id' => null,
            'version' => (int) ValuationRuleSet::query()->max('version') + 1,
            'status' => ValuationRuleSet::STATUS_DRAFT,
        ]);

        $this->setIds[] = (int) $set->id;

        $question = ValuationQuestion::query()->create([
            'valuation_rule_set_id' => $set->id,
            'key' => 'conc_q',
            'label_ckb' => 'پرسیار conc_q',
            'label_ar' => 'سؤال conc_q',
            'label_en' => 'Question conc_q',
            'sort_order' => 0,
            'is_active' => true,
        ]);

        $option = ValuationQuestionOption::query()->create([
            'valuation_question_id' => $question->id,
            'key' => 'conc_o',
            'label_ckb' => 'هەڵبژاردە conc_o',
            'label_ar' => 'خيار conc_o',
            'label_en' => 'Option conc_o',
            'adjustment_percent' => '5.000',
            'sort_order' => 0,
            'is_active' => true,
        ]);

        return [$set, $option];
    }

    /** A marker path the child will CREATE — it must not exist yet. */
    private function markerPath(string $prefix): string
    {
        $path = sys_get_temp_dir().'/'.uniqid($prefix, true).'.marker';
        $this->markers[] = $path;

        return $path;
    }

    /**
     * Run the child edit attempt and decode its JSON report.
     *
     * @return array<string, mixed>
     */
    private function runChild(int $setId, int $optionId, string $marker): array
    {
        $script = base_path('tests/Support/locked_edit_attempt.php');

        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, $script, (string) $setId, (string) $optionId, $marker],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        $this->assertIsResource($process, 'concurrency harness: the child process failed to spawn');

        $out = $pipes[1] ?? null;
        $err = $pipes[2] ?? null;
        $this->assertIsResource($out);
        $this->assertIsResource($err);

        $stdout = (string) stream_get_contents($out);
        $stderr = (string) stream_get_contents($err);
        fclose($out);
        fclose($err);
        proc_close($process);

        $report = json_decode(trim($stdout), true);

        $this->assertIsArray($report, 'concurrency harness: no JSON from the child. stderr: '.mb_substr($stderr, 0, 300));

        return $report;
    }

    /**
     * Spawn the child mid-publish and hold the publish transaction open —
     * lock held — until the child's marker exists plus a two-second grace,
     * so the child's attempt provably starts while the lock is held.
     *
     * @return callable(): array<string, mixed>
     */
    private function spawnChildInsidePublishWindow(int $setId, int $optionId, string $marker): callable
    {
        $armed = true;
        $driver = DB::connection()->getDriverName();
        $childProcess = null;
        $childStdout = null;
        $childStderr = null;

        DB::listen(static function (QueryExecuted $event) use (&$armed, &$childProcess, &$childStdout, &$childStderr, $driver, $setId, $optionId, $marker): void {
            if (! $armed) {
                return;
            }

            $sql = strtolower($event->sql);

            if (! str_starts_with(ltrim($sql), 'select') || ! str_contains($sql, 'valuation_rule_sets')) {
                return;
            }

            if ($driver === 'mysql' && ! str_contains($sql, 'for update')) {
                return;
            }

            $armed = false;

            $script = base_path('tests/Support/locked_edit_attempt.php');
            $childPipes = [];
            $childProcess = proc_open(
                [PHP_BINARY, $script, (string) $setId, (string) $optionId, $marker],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $childPipes,
            );
            $childStdout = $childPipes[1] ?? null;
            $childStderr = $childPipes[2] ?? null;

            // Hold the lock until the child is provably attempting, then a
            // grace period so its lock/busy wait genuinely overlaps ours.
            // clearstatcache: PHP caches file_exists results, and a cached
            // "absent" would hold the lock the full deadline for nothing.
            $deadline = microtime(true) + 30;

            while (microtime(true) < $deadline) {
                clearstatcache(true, $marker);

                if (file_exists($marker)) {
                    break;
                }

                usleep(100_000);
            }

            sleep(2);
        });

        return function () use (&$childProcess, &$childStdout, &$childStderr): array {
            $this->assertIsResource($childProcess, 'concurrency harness: the child never spawned — the publish lock query was not observed');
            $this->assertIsResource($childStdout);
            $this->assertIsResource($childStderr);

            $stdout = (string) stream_get_contents($childStdout);
            $stderr = (string) stream_get_contents($childStderr);
            fclose($childStdout);
            fclose($childStderr);
            proc_close($childProcess);

            $report = json_decode(trim($stdout), true);

            $this->assertIsArray($report, 'concurrency harness: no JSON from the child. stderr: '.mb_substr($stderr, 0, 300));

            return $report;
        };
    }

    public function test_case_a_an_editor_that_wins_is_seen_by_publish_validation(): void
    {
        [$set, $option] = $this->committedDraft('Concurrency case A');

        $marker = $this->markerPath('conc-a-');

        // The editor (a REAL second process and connection) wins outright:
        // its out-of-bounds edit commits while the set is still a draft.
        $report = $this->runChild((int) $set->id, (int) $option->id, $marker);

        $this->assertSame('wrote', $report['outcome'] ?? null, 'the child edit should have succeeded on a draft: '.(string) json_encode($report));
        $this->assertSame(
            '30.000',
            (string) ValuationQuestionOption::query()->findOrFail($option->id)->adjustment_percent,
        );

        // Publish must judge the FINAL committed content and refuse it.
        $publisher = app(ValuationRulePublisher::class);

        try {
            $publisher->publish($set);
            $this->fail('publish blessed content it never validated');
        } catch (ValuationRulePublishException $e) {
            $this->assertSame('adjustment_out_of_bounds', $e->errorKey);
        }

        $this->assertSame(
            ValuationRuleSet::STATUS_DRAFT,
            ValuationRuleSet::query()->findOrFail($set->id)->status,
        );
    }

    public function test_case_b_a_publisher_that_wins_blocks_then_refuses_the_editor_on_mariadb(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('real row-lock blocking is a MariaDB behaviour; SQLite safety is proven separately');
        }

        [$set, $option] = $this->committedDraft('Concurrency case B');

        $marker = $this->markerPath('conc-b-');

        $collectChild = $this->spawnChildInsidePublishWindow((int) $set->id, (int) $option->id, $marker);

        $publisher = app(ValuationRulePublisher::class);
        $published = $publisher->publish($set);

        $this->assertSame(ValuationRuleSet::STATUS_ACTIVE, $published->status);

        $report = $collectChild();

        // The child provably started while the lock was held (the parent
        // waited for its marker, then two more seconds, before letting the
        // publish proceed) and returned only after the commit — a genuine
        // blocked-then-resumed writer. Its locked re-check read ACTIVE and
        // refused; the elapsed time is the wait made visible.
        $this->assertSame('refused', $report['outcome'] ?? null, 'child report: '.(string) json_encode($report));
        $this->assertSame('frozen', $report['error'] ?? null, 'child report: '.(string) json_encode($report));
        $this->assertGreaterThanOrEqual(800, (int) ($report['elapsed_ms'] ?? 0), 'the child did not wait on the lock: '.(string) json_encode($report));

        // And no child write landed on the published set.
        $this->assertSame(
            '5.000',
            (string) ValuationQuestionOption::query()->findOrFail($option->id)->adjustment_percent,
        );
    }

    public function test_case_b_a_publisher_that_wins_is_safe_on_sqlite(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('the SQLite busy-state outcome only exists on SQLite');
        }

        /*
         * No row locks exist here — lockForUpdate compiles to nothing on
         * SQLite — so the contract under test is SAFETY, not blocking: a
         * competing write either loses to the database-level busy state or
         * arrives late and takes the frozen refusal. The parent's busy
         * timeout outlasts the child's, so the child is the deterministic
         * loser of a standoff.
         */
        DB::statement('PRAGMA busy_timeout = 2000');

        [$set, $option] = $this->committedDraft('Concurrency case B sqlite');

        $marker = $this->markerPath('conc-bs-');

        $collectChild = $this->spawnChildInsidePublishWindow((int) $set->id, (int) $option->id, $marker);

        $publisher = app(ValuationRulePublisher::class);
        $publishError = null;

        try {
            $published = $publisher->publish($set);
            $this->assertSame(ValuationRuleSet::STATUS_ACTIVE, $published->status);
        } catch (QueryException $e) {
            // The parent losing the busy standoff is a SAFE outcome too:
            // nothing activated, so whatever the child wrote was a legal
            // draft edit.
            $publishError = $e;
        }

        $report = $collectChild();

        $freshSet = ValuationRuleSet::query()->findOrFail($set->id);
        $freshPercent = (string) ValuationQuestionOption::query()->findOrFail($option->id)->adjustment_percent;

        // THE invariant: no stale write may coexist with a published set.
        $this->assertFalse(
            $freshSet->status === ValuationRuleSet::STATUS_ACTIVE && $freshPercent === '30.000',
            sprintf(
                'a stale write landed on an ACTIVE set (child: %s, publish error: %s)',
                (string) json_encode($report),
                $publishError?->getMessage() ?? 'none',
            ),
        );

        // And the contention was actually observed on one side or the
        // other: the child refused/failed busy, or the parent did.
        $this->assertTrue(
            ($report['outcome'] ?? null) !== 'wrote' || $publishError !== null,
            'no side observed the contention: '.(string) json_encode($report),
        );
    }
}
