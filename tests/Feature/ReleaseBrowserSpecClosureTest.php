<?php

declare(strict_types=1);

namespace Tests\Feature;

use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * The release browser-spec registry must stay closed over the disk.
 *
 * Final release #28 (run 31653081104) refused to package because PR #14
 * added tests/Browser/map-rtl.spec.ts without extending the canonical
 * registry in scripts/release/release_gates.py — a spec on disk that no
 * release browser gate would run. The release-side gate did its job, but
 * it fired DAYS after the spec merged; this test runs the SAME checker in
 * ordinary CI, so the next unclassified spec fails the pull request that
 * introduces it instead of the release that inherits it.
 */
class ReleaseBrowserSpecClosureTest extends TestCase
{
    private function python(): string
    {
        foreach (['python3', 'python'] as $candidate) {
            $probe = new Process([$candidate, '--version']);
            $probe->run();
            if ($probe->isSuccessful()) {
                return $candidate;
            }
        }

        $this->markTestSkipped('no python interpreter available to run the release checker');
    }

    public function test_every_browser_spec_is_classified_in_the_release_registry(): void
    {
        $process = new Process([
            $this->python(),
            base_path('scripts/release/release_gates.py'),
            '--verify-spec-closure',
            base_path('tests/Browser'),
        ]);
        $process->run();

        $this->assertSame(
            0,
            $process->getExitCode(),
            "The release browser-spec registry and tests/Browser disagree — classify the spec in\n"
            ."scripts/release/release_gates.py (PLAYWRIGHT_REMAINING_SPECS, plus\n"
            ."PLAYWRIGHT_REMAINING_INTENTIONAL_SKIPS only if its skips are reviewed):\n"
            .$process->getOutput().$process->getErrorOutput(),
        );
    }

    public function test_the_rtl_spec_is_part_of_the_release_remaining_suite(): void
    {
        $process = new Process([
            $this->python(),
            base_path('scripts/release/release_gates.py'),
            '--remaining-specs',
        ]);
        $process->run();

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertStringContainsString(
            'tests/Browser/map-rtl.spec.ts',
            $process->getOutput(),
            'The Arabic-script RTL plugin spec must be in the canonical remaining suite, '
            .'so every final release actually executes it.',
        );
    }
}
