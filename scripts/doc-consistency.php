<?php

declare(strict_types=1);

/*
 * Reject documentation that contradicts itself or the evidence.
 *
 * The audited release shipped a README claiming 413 tests and 40 migrations
 * against an evidence file recording 433 and 41, and a final report that listed
 * three gates as PASS in a table and NOT RUN in its prose two screens later.
 * Both were true when written. Neither was true when shipped, and no gate
 * noticed, because every gate was checking whether commands succeeded rather
 * than whether the documents agreed with each other.
 *
 * Usage: php scripts/doc-consistency.php [--external=DIR]
 */

require __DIR__.'/support/EvidencePath.php';

use Mulkihawler\Tooling\EvidencePath;

$root = dirname(__DIR__);
$failures = [];
$notes = [];

/*
 * `--doc-root` lets the regression fixtures point this checker at a temporary
 * tree containing a deliberately contradictory document and assert the exit
 * code. Testing a checker by reading its source proves only that a function
 * exists; running it proves it rejects what it is supposed to reject.
 */
$options = getopt('', ['external::', 'doc-root::']);

if (isset($options['doc-root']) && $options['doc-root'] !== false) {
    $root = rtrim((string) $options['doc-root'], '/');
}
$externalDir = isset($options['external']) ? rtrim((string) $options['external'], '/') : null;

/**
 * Split a document into sentences, with markdown line wrapping removed.
 *
 * @return list<string>
 */
/**
 * Whether a sentence is talking about a gate, allowing for paraphrase.
 *
 * "independent audit" and "consumer deployment test" both refer to gates named
 * slightly differently. Common words are dropped so the match rests on the
 * words that actually identify the gate.
 */
function mentionsGate(string $sentence, string $gateName): bool
{
    $stopwords = ['the', 'a', 'an', 'of', 'and', 'from', 'result', 'results',
        'script', 'test', 'tests', 'check', 'checks', 'suite', 'semantic'];

    $words = array_values(array_filter(
        preg_split('/[^a-z]+/i', strtolower($gateName)) ?: [],
        static fn (string $w): bool => $w !== '' && ! in_array($w, $stopwords, true),
    ));

    if ($words === []) {
        return stripos($sentence, $gateName) !== false;
    }

    $lower = strtolower($sentence);
    $present = 0;

    foreach ($words as $word) {
        if (str_contains($lower, $word)) {
            $present++;
        }
    }

    // Every identifying word but at most one: enough to survive paraphrase
    // ("independent audit" for "independent archive audit") without matching
    // an unrelated sentence that happens to share a single word.
    return $present >= max(1, count($words) - 1);
}

function sentencesOf(string $body): array
{
    $sentences = [];

    foreach (preg_split('/\n\s*\n/', $body) ?: [] as $paragraph) {
        // Unwrap: a newline inside a paragraph is presentation, not meaning.
        $flat = trim((string) preg_replace('/\s*\n\s*/', ' ', $paragraph));

        if ($flat === '') {
            continue;
        }

        foreach (preg_split('/(?<=[.;:])\s+/', $flat) ?: [] as $sentence) {
            if (trim($sentence) !== '') {
                $sentences[] = $sentence;
            }
        }
    }

    return $sentences;
}

$evidencePath = EvidencePath::evidenceFile($root, $argv);

/*
 * Fixtures pass --doc-root and keep their reports beside their evidence; a real
 * run reads them from <evidence-dir>/reports.
 */
$reportRoot = isset($options['doc-root']) && $options['doc-root'] !== false
    ? rtrim((string) $options['doc-root'], '/').'/docs'
    : EvidencePath::directory($root, $argv).'/reports';

if (! is_file($evidencePath)) {
    fwrite(STDERR, "docs/release-evidence.json is missing.\n");
    exit(1);
}

/** @var array<string, mixed> $evidence */
$evidence = json_decode((string) file_get_contents($evidencePath), true, 512, JSON_THROW_ON_ERROR);
$gates = $evidence['gates'];
$phpunit = $evidence['phpunit'];

// ---------------------------------------------------------------- 1. totals
/*
 * Any document that states a test, assertion or migration count as CURRENT must
 * agree with the evidence. CHANGELOG is exempt where the entry is explicitly
 * historical — a changelog that cannot record past numbers is not a changelog.
 */
/*
 * The migration count is only meaningful in a real tree. A fixture root has no
 * `app/`, and a checker that fataled there could not be tested against the
 * documents it exists to reject.
 */
$migrations = 0;

foreach (is_dir($root.'/app')
    ? new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/app'))
    : [] as $file) {
    if ($file->isFile()
        && $file->getExtension() === 'php'
        && str_contains(str_replace('\\', '/', $file->getPathname()), '/Database/Migrations/')) {
        $migrations++;
    }
}

$migrations += count(glob($root.'/database/migrations/*.php') ?: []);

/*
 * Only documents that purport to state the CURRENT PROJECT totals are checked
 * for them.
 *
 * The `*_SLICE.md` notes record what one slice's own suite reported while that
 * slice was built — "141 assertions" there is a scoped historical fact about a
 * subset, not a claim about the release, and rewriting them to match today's
 * global total would make them false. They are still checked for contradicted
 * gate statuses below, where the rule does apply to every document.
 */
$totalsDocuments = array_values(array_filter(array_merge(
    [$root.'/README.md'],
    /*
     * The four run-derived reports live in the external evidence directory now,
     * not in the source tree. Reading them from there keeps this gate honest
     * without making evidence collection write inside the authenticated source.
     */
    glob($reportRoot.'/VERIFICATION.md') ?: [],
    glob($reportRoot.'/ROADMAP_STATUS.md') ?: [],
    glob($reportRoot.'/RELEASE_DECISION.md') ?: [],
    glob($reportRoot.'/FINAL_RELEASE_VERIFICATION.md') ?: [],
    glob($root.'/deploy/*.txt') ?: [],
    $externalDir === null ? [] : (glob($externalDir.'/*.md') ?: []),
), 'is_file'));

$documents = array_merge(
    glob($root.'/*.md') ?: [],
    glob($root.'/docs/*.md') ?: [],
    glob($root.'/deploy/*.txt') ?: [],
    $externalDir === null ? [] : (glob($externalDir.'/*.md') ?: []),
);

foreach ($totalsDocuments as $path) {
    $relative = str_replace($root.'/', '', $path);
    $body = (string) file_get_contents($path);
    $isChangelog = str_ends_with($relative, 'CHANGELOG.md');

    foreach (explode("\n", $body) as $number => $line) {
        // "Historical" and "superseded" lines are allowed to carry old numbers.
        $lower = strtolower($line);

        if (str_contains($lower, 'historical') || str_contains($lower, 'superseded')
            || str_contains($lower, 'not repeated here')) {
            continue;
        }

        if (preg_match('/(?<![=\w])(\d[\d,]{1,7})\s+tests?\b/i', $line, $m)) {
            $stated = (int) str_replace(',', '', $m[1]);

            if ($stated !== $phpunit['tests'] && ! $isChangelog) {
                $failures[] = sprintf(
                    '%s:%d states %d tests; the evidence records %d',
                    $relative, $number + 1, $stated, $phpunit['tests'],
                );
            }
        }

        /*
         * v6: the character class allowed a TRAILING COMMA, so a summary
         * reading "Tests: 467, Assertions: 1772" matched "467," followed by
         * "Assertions" and the checker reported 467 assertions — the test
         * count in the assertion field. Requiring the run to END on a digit
         * makes "467," fail to match, and the real assertion figure is read.
         */
        if (preg_match('/(?<![=\w])(\d[\d,]*\d|\d)\s+assertions?\b/i', $line, $m)) {
            $stated = (int) str_replace(',', '', $m[1]);

            if ($stated !== $phpunit['assertions'] && ! $isChangelog) {
                $failures[] = sprintf(
                    '%s:%d states %d assertions; the evidence records %d',
                    $relative, $number + 1, $stated, $phpunit['assertions'],
                );
            }
        }

        if (preg_match('/(?<![=\w])(\d{1,4})\s+migrations?\b/i', $line, $m)) {
            $stated = (int) $m[1];

            if ($stated !== $migrations && ! $isChangelog) {
                $failures[] = sprintf(
                    '%s:%d states %d migrations; the tree has %d',
                    $relative, $number + 1, $stated, $migrations,
                );
            }
        }
    }
}

// ------------------------------------------------------- 2. gate contradiction
/*
 * A document must not call a gate PASS in one place and NOT RUN in another.
 */
foreach ($documents as $path) {
    $relative = str_replace($root.'/', '', $path);
    $body = (string) file_get_contents($path);

    foreach ($gates as $gate) {
        $name = $gate['name'];

        if (! str_contains($body, $name)) {
            continue;
        }

        $saysPass = (bool) preg_match('/'.preg_quote($name, '/').'[^\n|]*\|\s*PASS/', $body);
        $saysNotRun = (bool) preg_match('/'.preg_quote($name, '/').'[^\n|]*\|\s*NOT RUN/', $body);

        if ($saysPass && $saysNotRun) {
            $failures[] = "{$relative}: {$name} is recorded both PASS and NOT RUN";
        }

        /*
         * A VOCABULARY, not one phrase.
         *
         * This looked only for the literal "NOT RUN", so a document could say
         * a passing gate's results "remain pending" and still be reported
         * consistent. English has many ways to say a thing has not happened,
         * and a checker that knows one of them gives a false PASS — which is
         * exactly what happened, on a document that contradicted its own
         * table two screens up.
         */
        $notPerformed = '(?:not run|not yet run|remain(?:s|ing)? pending|still pending|'
            .'pending|outstanding|not yet (?:performed|executed|verified|available|carried out)|'
            .'awaiting|to be (?:performed|executed|verified|carried out)|have not been (?:run|executed)|'
            // "the results do not exist yet" says the same thing again, and was
            // the phrasing that survived the previous two repairs.
            .'do(?:es)? not (?:yet )?exist|have not been produced|are not available|'
            .'is not available|cannot be claimed|have yet to be|until [^.]{0,60}report)';

        if ($gate['status'] !== 'PASS') {
            continue;
        }

        /*
         * Only the SUBJECT matters. "the independent audit ... remains pending"
         * contradicts a passing audit; "the FINAL artifacts' audit is pending"
         * is a different subject and must not be flagged, so a document is
         * required to say which artifacts it means.
         */
        /*
         * SENTENCES, not lines.
         *
         * Markdown wraps prose at column 80, so the claim that contradicted the
         * table was spread over three lines: one named the gate, another said
         * "remain pending", and no single line contained both. A line-based
         * check reported the document consistent. Paragraphs are therefore
         * unwrapped before the comparison, which is how a reader sees them.
         */
        foreach (sentencesOf($body) as $sentence) {
            if (str_starts_with(trim($sentence), '|')) {
                continue;   // table rows carry their own status column
            }

            /*
             * Match the gate's DISTINCTIVE WORDS, not its exact name.
             *
             * The contradicting sentence said "independent audit" where the
             * gate is called "Independent archive audit", so an exact-name
             * check saw nothing. Prose paraphrases; a checker that only
             * recognises the canonical string will keep reporting PASS while a
             * reader sees a plain contradiction.
             */
            if (! mentionsGate($sentence, $name)) {
                continue;
            }

            if (! preg_match('/'.$notPerformed.'/i', $sentence)) {
                continue;
            }

            /*
             * A sentence may legitimately say the DELIVERED artifacts' checks
             * are still to come — but only if it says so unmistakably, and only
             * if it does not reuse the bare gate name that the table has just
             * marked PASS. Reusing the name is the ambiguity, whatever the
             * author meant.
             */
            $failures[] = sprintf(
                '%s: "%s" is PASS in the gate table, but the prose says its results are not yet '
                    ."performed:\n        \"%s\"",
                $relative, $name, trim(preg_replace('/\s+/', ' ', $sentence) ?? ''),
            );
        }
    }
}

// ------------------------------------- 2a. structural state agreement
/*
 * STRUCTURE FIRST, prose second.
 *
 * Reading English is how three consecutive releases shipped a document that
 * disagreed with its own table: each repair taught the checker one more phrase.
 * The generator now emits its state as a marker, derived from the same value
 * that produces the table and the verdict, so the primary check compares
 * structure against structure and cannot be defeated by a synonym.
 */
$decisionFile = $reportRoot.'/RELEASE_DECISION.md';

if (is_file($decisionFile)) {
    $decisionBody = (string) file_get_contents($decisionFile);

    $artifactKeys = ['packaging', 'content_audit', 'smoke'];
    $artifactGates = array_intersect_key($gates, array_flip($artifactKeys));

    $failedArtifact = array_filter($artifactGates, static fn (array $g): bool => $g['status'] === 'FAIL');
    $notRunArtifact = array_filter($artifactGates, static fn (array $g): bool => $g['status'] === 'NOT RUN');

    $expectedState = $failedArtifact !== [] ? 'ARTIFACT_FAILED'
        : ($notRunArtifact !== [] || $artifactGates === [] ? 'ARTIFACT_NOT_RUN' : 'PHASE_A_PASS');

    if (! preg_match('/<!-- RELEASE_ARTIFACT_STATE: (\w+) -->/', $decisionBody, $marker)) {
        $failures[] = 'docs/RELEASE_DECISION.md carries no generated artifact-state marker';
    } elseif ($marker[1] !== $expectedState) {
        $failures[] = sprintf(
            'docs/RELEASE_DECISION.md declares state %s, but the evidence gates say %s',
            $marker[1],
            $expectedState,
        );
    }

    // The table must agree with the evidence, row by row.
    foreach ($artifactGates as $gate) {
        $row = '/\|\s*'.preg_quote($gate['name'], '/').'\s*\|\s*(PASS|FAIL|NOT RUN)\s*\|/';

        if (preg_match($row, $decisionBody, $cell) && $cell[1] !== $gate['status']) {
            $failures[] = sprintf(
                'docs/RELEASE_DECISION.md tabulates %s as %s; the evidence records %s',
                $gate['name'],
                $cell[1],
                $gate['status'],
            );
        }
    }

    /*
     * Inside the current-state region, no claim that the artifact work is still
     * to come may survive when the gates say it is done. This does not require
     * a canonical gate name: the region describes the current state and nothing
     * else, so any such claim there is wrong.
     */
    if ($expectedState === 'PHASE_A_PASS'
        && preg_match('/<!-- CURRENT_RELEASE_STATE_START -->(.*?)<!-- CURRENT_RELEASE_STATE_END -->/s', $decisionBody, $region)) {
        $current = (string) preg_replace('/\s+/', ' ', $region[1]);

        $forbidden = [
            'until the packaging gates',
            'until packaging',
            'do not exist yet',
            'does not exist yet',
            'packaging has not',
            'archive checks are not run',
            'results are unavailable',
            'still need to be carried out',
        ];

        foreach ($forbidden as $claim) {
            if (stripos($current, $claim) === false) {
                continue;
            }

            /*
             * Allowed only when the subject is unmistakably the FINAL rebuilt
             * artifacts and the text also records that Phase A passed.
             */
            $aboutFinal = stripos($current, 'final artifact') !== false
                && preg_match('/phase a[^.]{0,80}pass/i', $current);

            if (! $aboutFinal) {
                $failures[] = sprintf(
                    'docs/RELEASE_DECISION.md: the current-state section says "%s" while the '
                        .'artifact gates are PASS',
                    $claim,
                );
            }
        }
    }
}

// ------------------------------------------------------------ 3. verdict sanity
$decisionPath = $root.'/docs/RELEASE_DECISION.md';

if (is_file($decisionPath)) {
    $decision = (string) file_get_contents($decisionPath);
    $failedGates = array_filter($gates, static fn (array $g): bool => $g['status'] === 'FAIL');

    if (str_contains($decision, 'READY FOR PRODUCTION')
        && ! str_contains($decision, 'READY TO BUILD')
        && $failedGates !== []) {
        $failures[] = 'docs/RELEASE_DECISION.md declares readiness while a mandatory gate has failed';
    }

    /*
     * Tracked documentation must not claim production readiness: the delivered
     * artifacts are rebuilt from the commit that carries this file, so their
     * bytes cannot exist when it is written.
     */
    if (preg_match('/Verdict:\s*\*?\*?READY FOR PRODUCTION/', $decision)) {
        $failures[] = 'tracked documentation declares READY FOR PRODUCTION; that belongs in the '
            .'external release manifest, bound to the final artifact hashes';
    }
}

// ------------------------------------------------- 4. commit roles, if external
if ($externalDir !== null) {
    $manifestPath = $externalDir.'/RELEASE_MANIFEST.json';

    if (! is_file($manifestPath)) {
        $failures[] = 'the external release manifest is missing';
    } else {
        /** @var array<string, mixed> $manifest */
        $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);

        /*
         * Roles live under `commit_roles`, which is where they belong: a flat
         * key was easy to read past, and the point of naming four commits is
         * that they are visibly a SET of distinct roles.
         */
        $roles = is_array($manifest['commit_roles'] ?? null) ? $manifest['commit_roles'] : $manifest;

        foreach (['phase_a_metadata_commit', 'final_artifact_source_commit', 'release_tooling_commit', 'evidence_measurement_commit'] as $role) {
            if (! isset($roles[$role]) || ! preg_match('/^[0-9a-f]{40}$/', (string) $roles[$role])) {
                $failures[] = "the external manifest has no valid {$role}";
            }
        }

        /*
         * The evidence inside the archives measured one commit; the artifacts
         * were built from another. Both are legitimate and DIFFERENT — what is
         * not legitimate is presenting one as the other.
         */
        if (isset($roles['evidence_measurement_commit'], $evidence['commit'])
            && $roles['evidence_measurement_commit'] !== $evidence['commit']) {
            $failures[] = 'the external manifest records an evidence_measurement_commit that '
                .'disagrees with docs/release-evidence.json';
        }

        if (isset($roles['final_artifact_source_commit'], $roles['evidence_measurement_commit'])
            && $roles['final_artifact_source_commit'] === $roles['evidence_measurement_commit']) {
            $notes[] = 'the artifacts were built from the same commit the evidence measured';
        }
    }
}

echo "Documentation consistency\n";
echo str_repeat('─', 68)."\n";
printf("  %-46s %d\n", 'documents inspected', count($documents));
printf("  %-46s %d\n", 'gates cross-checked', count($gates));

foreach ($notes as $note) {
    echo "  note  {$note}\n";
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        echo "  FAIL  {$failure}\n";
    }

    echo "\n  DOCUMENTATION CONSISTENCY FAILED\n";
    exit(1);
}

echo "  PASS  no stale totals, no contradicted gate, no premature verdict\n";
exit(0);
