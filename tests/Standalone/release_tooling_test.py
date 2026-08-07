#!/usr/bin/env python3
"""
Regression tests for the release-tooling defects the audits reproduced.

Each test corresponds to a real defect that shipped. Source-string matching
would prove only that a message exists, so where possible these execute the
tooling and assert on behaviour — every one of these defects passed a syntax
check and failed at runtime.

Usage: python3 tests/Standalone/release_tooling_test.py
"""
from __future__ import annotations

import hashlib
import json
import os
import re
import shutil
import subprocess
import sys
import tempfile
import zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
RELEASE = ROOT / 'scripts' / 'release'
sys.path.insert(0, str(RELEASE))

PASSED = 0
FAILED = 0
TREE = 'a' * 64


def check(name: str, ok: bool, why: str = '') -> None:
    global PASSED, FAILED
    if ok:
        PASSED += 1
        print(f'  pass {name}')
    else:
        FAILED += 1
        print(f'  FAIL {name}' + (f'  ({why})' if why else ''))


def run(*args: str, cwd: Path | None = None) -> subprocess.CompletedProcess:
    return subprocess.run([sys.executable, *args], capture_output=True,
                          text=True, cwd=str(cwd) if cwd else None)


print('Release tooling regressions')

# ---- the fabricating appender must stay deleted ---------------------------
check('the retrospective ledger appender is gone',
      not (RELEASE / 'append_ledger_entries.py').is_file(),
      'it wrote exit_code 0 and zero duration for commands it never ran')

# ---- real ledger timing and exit-code recording ---------------------------
with tempfile.TemporaryDirectory() as tmp:
    evidence = Path(tmp) / 'evidence'
    ledger = evidence / 'command-ledger.json'

    run(str(RELEASE / 'record_command.py'),
        '--ledger', str(ledger), '--evidence-dir', str(evidence),
        '--tree-manifest-sha256', TREE, '--label', 'pint',
        '--', 'bash', '-c', 'sleep 1; echo out; echo err >&2; exit 0')

    entry = json.loads(ledger.read_text())['entries'][0]

    check('the recorder captures a real duration', entry['duration_seconds'] >= 1)
    check('start and finish differ', entry['started_at'] != entry['finished_at'])
    check('stdout and stderr are both captured', entry['raw_output_bytes'] >= 8)
    check('the log resolves relative to the evidence root',
          (evidence / entry['raw_output_file']).is_file())

    result = run(str(RELEASE / 'record_command.py'),
                 '--ledger', str(ledger), '--evidence-dir', str(evidence),
                 '--tree-manifest-sha256', TREE, '--label', 'phpstan',
                 '--', 'bash', '-c', 'exit 7')

    entries = {e['label']: e for e in json.loads(ledger.read_text())['entries']}

    check('a nonzero exit is recorded truthfully',
          entries['phpstan']['exit_code'] == 7,
          'a post-hoc recorder wrote 0 for everything')
    check('the wrapper propagates the real exit code', result.returncode == 7)

    unknown = run(str(RELEASE / 'record_command.py'),
                  '--ledger', str(ledger), '--evidence-dir', str(evidence),
                  '--tree-manifest-sha256', TREE, '--label', 'not-a-real-gate',
                  '--', 'true')

    check('an unregistered gate label is refused', unknown.returncode != 0)

# ---- ledger archive path resolution ---------------------------------------
with tempfile.TemporaryDirectory() as tmp:
    evidence = Path(tmp) / 'evidence'
    ledger = evidence / 'command-ledger.json'

    run(str(RELEASE / 'record_command.py'),
        '--ledger', str(ledger), '--evidence-dir', str(evidence),
        '--tree-manifest-sha256', TREE, '--label', 'full-sqlite',
        '--', 'bash', '-c', 'echo ok')

    archive = Path(tmp) / 'evidence.zip'
    with zipfile.ZipFile(archive, 'w') as zf:
        for path in evidence.rglob('*'):
            if path.is_file():
                zf.write(path, str(path.relative_to(evidence)))

    result = run(str(RELEASE / 'validate_command_ledger.py'),
                 '--ledger', str(ledger), '--evidence-zip', str(archive),
                 '--tree-manifest-sha256', TREE, '--require-labels', 'full-sqlite')

    check('recorded log paths resolve inside the evidence archive',
          result.returncode == 0,
          'recording under <evidence>/ledger produced unresolvable paths')

# ---- zero-shared parity must be rejected ----------------------------------
with tempfile.TemporaryDirectory() as tmp:
    empty_a, empty_b = Path(tmp) / 'a', Path(tmp) / 'b'
    empty_a.mkdir()
    empty_b.mkdir()

    result = run(str(RELEASE / 'build_v7_evidence.py'),
                 '--project-root', str(ROOT), '--output-dir', tmp,
                 '--evidence-dir', tmp, '--source-dir', str(empty_a),
                 '--runtime-root', str(empty_b), '--tree-manifest-sha256', TREE)

    check('the evidence builder rejects zero-file parity',
          result.returncode != 0
          and 'zero shared files' in result.stderr,
          'comparing zero files reported PASS; an earlier edition of this '
          'test passed a stale --runtime-dir flag and only proved argparse '
          'rejects unknown arguments')

    missing = run(str(RELEASE / 'build_v7_evidence.py'),
                  '--project-root', str(ROOT), '--output-dir', tmp,
                  '--evidence-dir', tmp)

    check('source and runtime are required arguments, not env defaults',
          missing.returncode != 0)

# ---- malformed runtime checksum -------------------------------------------
with tempfile.TemporaryDirectory() as tmp:
    target = Path(tmp) / 'runtime.zip'
    target.write_bytes(b'PK\x03\x04 not really')

    broken = subprocess.run(
        f'sha256sum {target} | sed "s|.*/||" > {tmp}/broken.sha256',
        shell=True, capture_output=True)
    broken_text = (Path(tmp) / 'broken.sha256').read_text()

    check('the old sed form destroys the hash',
          len(broken_text.split()) == 1,
          'this is why the rehearsal sha256sum -c failed')

    subprocess.run(f'cd {tmp} && sha256sum runtime.zip > good.sha256',
                   shell=True, capture_output=True)
    good = subprocess.run(f'cd {tmp} && sha256sum -c good.sha256',
                          shell=True, capture_output=True)

    check('the corrected form verifies', good.returncode == 0)

# ---- archive audit catches unsafe entries ---------------------------------
with tempfile.TemporaryDirectory() as tmp:
    hostile = Path(tmp) / 'hostile.zip'
    with zipfile.ZipFile(hostile, 'w') as zf:
        zf.writestr('../escape.txt', 'x')
        zf.writestr('ok.txt', 'y')

    result = run(str(RELEASE / 'audit_archive.py'), str(hostile))

    check('the archive auditor rejects traversal entries', result.returncode != 0)
    check('the auditor prints a computed result marker',
          'archive audit problems:' in result.stdout)

# ---- gate-ID agreement -----------------------------------------------------
from release_gates import (  # noqa: E402
    BY_LABEL, EVIDENCE_INDEX_SCHEMA, RELEASE_EVIDENCE_LABELS, RELEASE_INDEX_SCHEMA,
    REQUIRED_INDEX_KEYS, SELF_REFERENCE_EXCLUSIONS, index_key,
)

verifier = (RELEASE / 'verify_final_delivery.py').read_text()
validator = (RELEASE / 'validate_command_ledger.py').read_text()

check('the verifier consumes the shared gate registry',
      'REQUIRED_INDEX_KEYS' in verifier,
      'it used to hardcode different spellings than the ledger')
check('gate ids round-trip', index_key('full-sqlite') == 'phpunit_sqlite_full')
check('every required index key has a gate',
      all(key in {g.index_key for g in BY_LABEL.values()} for key in REQUIRED_INDEX_KEYS))
check('the ledger validator exists for delivery', bool(validator))

# ---- producer/verifier schema agreement -----------------------------------
runner = (RELEASE / 'run_final_release_ci.sh').read_text()

# ---- ordering: evidence zip must come after every gate ---------------------
zip_at = runner.index('myhawler-account-first-registration-evidence.zip')

check('the evidence ZIP is created after the archive-audit gate',
      runner.index('record component-archive-audit') < zip_at,
      'zipping earlier meant final logs could not be inside it')
check('the evidence ZIP is created after report generation',
      runner.index('generate_release_reports.py') < zip_at)
check('inner checksums precede master assembly',
      runner.index('sha256sum "$f" > "$f.sha256"')
      < runner.index('"myhawler-account-first-registration-FINAL-DELIVERY.zip" .'))
check('the delivered verifier is copied before indexing',
      runner.index('Deliver the verifier and its dependencies before indexing')
      < runner.index('build_indexes.py'))
check('every gate runs through the real recorder',
      'record_command.py' in runner and 'record_command_ledger' not in runner)
check('the runner offers an offline stub mode', '--offline-stub' in runner)

# ---- mandatory reports ------------------------------------------------------
check('a report generator exists',
      (RELEASE / 'generate_release_reports.py').is_file(),
      'the four mandatory reports were generated by nothing')

# ---- failed-run artifact naming --------------------------------------------
workflow = (ROOT / '.github' / 'workflows' / 'myhawler-final-release.yml').read_text()

check('the final delivery uploads only on success',
      'name: Upload the final delivery' in workflow
      and workflow.split('name: Upload the final delivery')[1].lstrip().startswith('if: success()'))
check('failed runs upload under an unmistakable name',
      'myhawler-FAILED-RUN-diagnostics' in workflow)
check('the source archive is verified before its locks are read',
      workflow.index('sha256sum -c myhawler-current-working-tree.zip.sha256')
      < workflow.index('Read the dependency lock'))
check('the Playwright version is validated before interpolation',
      'read_playwright_version.py' in workflow,
      'validation moved into a real script with a strict semver regex')

# ---- real-mode CI contracts ------------------------------------------------
# These cover interfaces the offline stub cannot exercise, because the stub
# replaces exactly the commands that depend on them. Each corresponds to a
# defect that would only have surfaced on the real runner.

check('the runner creates the MariaDB user phpunit.mariadb.xml connects as',
      'CREATE USER IF NOT EXISTS' in runner and 'TEST_DB_USER' in runner,
      'the service container only creates root')

phpunit_mariadb = (ROOT / 'phpunit.mariadb.xml').read_text()
db_user = re.search(r'DB_USERNAME"\s+value="([^"]+)"', phpunit_mariadb)
check('the runner provisions the exact user phpunit.mariadb.xml names',
      db_user is not None and f'MYHAWLER_TEST_DB_USER:-{db_user.group(1)}' in runner,
      f'phpunit wants {db_user.group(1) if db_user else "?"}')
check('the runner proves that user can connect before the gates run',
      'cannot connect to' in runner)

for variable in ('REHEARSAL_BASELINE', 'REHEARSAL_DB_PASSWORD', 'REHEARSAL_DB_USER',
                 'REHEARSAL_DB_NAME', 'REHEARSAL_DB_HOST', 'REHEARSAL_DB_PORT',
                 'REHEARSAL_PHP', 'REHEARSAL_MYSQL', 'REHEARSAL_MYSQLDUMP',
                 'REHEARSAL_RUNTIME'):
    check(f'the runner exports {variable} for the rehearsals',
          f'export {variable}=' in runner)

check('the runner refuses an incomplete rehearsal environment',
      'the rehearsal environment is incomplete' in runner)

npm_ci_at = runner.index('record_in "$NPM_WORKSPACE" npm-ci')
build_at = runner.index('record_in "$NPM_WORKSPACE" build')
freeze_at = runner.index('chmod -R a-w "$SOURCE"')

check('npm ci runs in a disposable workspace, not the frozen source',
      npm_ci_at > freeze_at and '--cwd "$cwd"' in runner,
      'npm ci recreates node_modules and needs write access to its parent')
check('npm run build runs in a disposable workspace',
      build_at > freeze_at)
check('no npm gate is recorded with the frozen source as its working directory',
      'record_tool npm-ci' not in runner and 'record_tool build npm' not in runner)
check('the build gate is proved reproducible against the frozen assets',
      'build-reproducibility' in runner)

for project in ('mobile-360x800', 'mobile-390x844', 'tablet-768x1024',
                'laptop-1366x768', 'desktop-1440x900'):
    check(f'the browser matrix covers {project}', project in runner)

check('each Playwright project names its own JSON destination',
      'PLAYWRIGHT_JSON_OUTPUT_NAME="$EVIDENCE/browser/${project}.json"' in runner,
      'one fixed path would be overwritten by each project')
check('each Playwright project names its own JUnit destination',
      'PLAYWRIGHT_JUNIT_OUTPUT_NAME="$EVIDENCE/browser/${project}.xml"' in runner)

merger = (RELEASE / 'merge_playwright.py').read_text()
check('the merger reads exactly those destinations',
      "'*.json'" in merger and "'*.xml'" in merger)
check('the merger requires exactly five projects and 100 tests',
      'PLAYWRIGHT_TESTS_TOTAL' in merger and 'PLAYWRIGHT_PROJECTS' in merger)

check('the canonical checksum gate has no --allow-missing',
      '--directory "$DELIVERY" --allow-missing' not in runner,
      'that flag let the gate pass before any checksum existed')
check('the canonical checksum gate runs after component checksums exist',
      runner.index('sha256sum "$f" > "$f.sha256"')
      < runner.index('--label sha256sums-verification'))
check('the final archive audit covers the evidence ZIP and the master',
      '--label final-archive-audit' in runner
      and 'FINAL-DELIVERY.zip"' in runner)
check('the pre-index audit is named for what it covers',
      'record component-archive-audit' in runner and 'record archive-audit ' not in runner)

check('the attestation derives its gate set from the canonical registry',
      'ATTESTATION_ONLY_LABELS' in (RELEASE / 'write_attestation.py').read_text(),
      'a private label list here could drift from release_gates.py')

check('the workflow uploads the external attestation on success',
      'myhawler-final-attestation' in workflow
      and 'final-verification.log' in workflow)
check('the attestation upload fails if the proof is missing',
      workflow.split('myhawler-final-attestation')[1].split('retention-days')[0]
      .count('if-no-files-found: error') == 1)
check('the Playwright version is validated by a strict parser, not a shell glob',
      'read_playwright_version.py' in workflow
      and '[0-9]*.[0-9]*.[0-9]*' not in workflow)

# ---- real-mode blockers found on the 00:34 audit --------------------------

check('the generated browser credential file is absent from the source',
      not (ROOT / 'tests' / 'Browser' / 'support' / 'fixtures.json').exists(),
      'it carried a test password and two MFA seeds inside the authenticated tree')

manifest = (ROOT / 'TREE_MANIFEST.txt').read_text()
check('the credential file is absent from the tree manifest',
      'tests/Browser/support/fixtures.json' not in manifest)

identity = (ROOT / 'scripts' / 'support' / 'SourceIdentity.php').read_text()
check('the shared policy excludes the credential file',
      "'tests/Browser/support/fixtures.json'" in identity)
check('.gitignore covers the credential file',
      'tests/Browser/support/fixtures.json' in (ROOT / '.gitignore').read_text())
check('staging purges the credential file rather than skipping it',
      'tests/Browser/support/fixtures.json' in
      (RELEASE / 'stage_tree.php').read_text())

# Behavioural: the secret scan must FAIL when the file is present.
fixture_path = ROOT / 'tests' / 'Browser' / 'support' / 'fixtures.json'
fixture_path.write_text('{"password":"known","mfa":"seed"}')
try:
    scan = subprocess.run(['php', str(ROOT / 'scripts' / 'secret-scan.php')],
                          capture_output=True, text=True, cwd=str(ROOT),
                          env={**os.environ, 'APP_KEY': 'base64:' + 'A' * 43})
    check('the secret scan fails when the credential file is present',
          scan.returncode != 0,
          'this is what makes a re-entry loud instead of silent')
finally:
    fixture_path.unlink(missing_ok=True)

# Per-gate database policy.
recorder = (RELEASE / 'record_command.py').read_text()
sys.path.insert(0, str(RELEASE))
from record_command import DATABASE_GATES  # noqa: E402

for label in ('queue-check', 'route-check', 'middleware-check', 'scheduler-check',
              'browser-database-prepare', 'browser-fixtures-seed',
              'playwright-mobile-360x800', 'playwright-desktop-1440x900'):
    check(f'{label} is permitted its database environment',
          label in DATABASE_GATES,
          'the old rule stripped DB_* from every label without "mariadb" in it')

check('the default PHPUnit suites still have DB_* stripped',
      'full-sqlite' not in DATABASE_GATES and 'focused-sqlite' not in DATABASE_GATES,
      'phpunit.xml must decide the SQLite connection')
check('the mariadb suites keep their database environment',
      {'full-mariadb', 'focused-mariadb', 'handoff-concurrency-mariadb'}
      <= DATABASE_GATES)

# Browser environment preparation.
for value in ('MULKIHAWLER_INSTALLED=true', 'APP_ENV=testing',
              'SESSION_SECURE_COOKIE=false', 'MULKIHAWLER_FORCE_HTTPS=false',
              'TELEGRAM_BOT_USERNAME=', 'TELEGRAM_WEBHOOK_SECRET='):
    check(f'the test environment sets {value.rstrip("=")}', value in runner)

check('a dedicated browser database is created and migrated',
      'DB_BROWSER' in runner and 'browser-database-prepare' in runner)
check('the browser fixtures are seeded through the guarded seeder',
      '--confirm-disposable-database' in runner)
check('the seeded credentials land in the disposable workspace only',
      'generated browser credentials appeared inside the frozen source' in runner)
check('Playwright runs from the browser workspace with its database',
      'record_in_db "$BROWSER_WORKSPACE" "playwright-${project}"' in runner)

# Middleware command contract.
executable_runner = '\n'.join(
    line for line in runner.splitlines() if not line.lstrip().startswith('#'))
check('the invalid --columns option is gone from the executed commands',
      '--columns=middleware' not in executable_runner,
      'Laravel 12 RouteListCommand has no --columns option')
middleware = (RELEASE / 'check_middleware.py').read_text()
check('the middleware gate uses route:list --json',
      "'route:list', '--json'" in middleware)
check('the middleware gate asserts gated routes carry their middleware',
      'DEFAULT_REQUIREMENTS' in middleware and 'unresolved middleware' in middleware)

# Fresh-session rollback. --clean-env provides the env -i guarantee INSIDE the
# recorder; spelling the REHEARSAL_* assignments out as an `env -i` argv wrote
# REHEARSAL_DB_PASSWORD's literal value into the shipped ledger the moment the
# recorder started storing exact argv.
check('the rollback is recorded under the recorder\'s clean environment',
      '--clean-env' in runner and 'rollback_rehearsal_v7.sh' in runner)
check('no recorded invocation embeds environment assignments in its argv',
      '-- env -i' not in runner,
      'secrets reach a child through the environment, never as argv')
check('the recorder can strip the inherited environment',
      "keep = {'PATH', 'HOME', 'LANG', 'TERM'}" in recorder)

# Post-seal attestation metadata.
attestation = (RELEASE / 'write_attestation.py').read_text()
for field in ('command', 'working_directory', 'started_at', 'finished_at',
              'duration_seconds', 'environment'):
    check(f'the attestation records a measured {field}',
          f"'{field}'" in attestation)
check('the attestation rejects a gate with missing measured fields',
      'is missing measured field' in attestation)
check('post-seal timings are measured by the recorder, not reconstructed',
      'ATTEST_LEDGER' in runner and 'gate_metadata.py' not in runner)

# Counts derived from the delivered files, never written by hand.
gate_count = len(BY_LABEL)
manifest_entries = len([line for line in manifest.splitlines() if line.strip()])
check('the registry count is derivable from the delivered registry',
      gate_count == len(set(BY_LABEL)) and gate_count > 0)
print(f'  ..   derived counts: {gate_count} gates, '
      f'{len(RELEASE_EVIDENCE_LABELS)} release evidence, '
      f'{manifest_entries} manifest entries')

# ---- real browser contracts (01:04 audit) ---------------------------------

check('a disposable blind-index key is written to the test environment',
      'MULKIHAWLER_BLIND_INDEX_KEY=$BLIND_INDEX_KEY' in runner,
      'User::blindIndex() throws on an empty key, so registration never started')
check('the blind-index key is generated per run, not hardcoded',
      '/dev/urandom' in runner and 'MYHAWLER_BLIND_INDEX_KEY:-' in runner)
check('a short blind-index key is rejected',
      'the disposable blind-index key is too short' in runner)

user_model = (ROOT / 'app' / 'Modules' / 'Identity' / 'Models' / 'User.php').read_text()
check('the contract under test is real: blindIndex refuses an unkeyed index',
      'refusing to build an unkeyed index' in user_model)

# One origin: APP_URL, PLAYWRIGHT_BASE_URL and the webServer port.
playwright_config = (ROOT / 'playwright.config.ts').read_text()
check('the Playwright server port is derived from the base URL',
      'new URL(baseURL).port' in playwright_config
      and '--port=${serverPort}' in playwright_config,
      'a hardcoded port let APP_URL and the server drift apart')
check('the runner defines one browser origin',
      'BROWSER_ORIGIN="http://127.0.0.1:$BROWSER_PORT"' in runner)
check('APP_URL uses that origin', 'APP_URL=$BROWSER_ORIGIN' in runner)
check('Playwright is given the same origin',
      'PLAYWRIGHT_BASE_URL="$BROWSER_ORIGIN"' in runner)
check('the default port matches the Playwright config default',
      'MYHAWLER_BROWSER_PORT:-8100' in runner
      and '127.0.0.1:8100' in playwright_config,
      'Telegram return buttons are built from APP_URL and opened cold')

# Browser suite vs merger contract.
spec_files = sorted(p.name for p in (ROOT / 'tests' / 'Browser').glob('*.spec.ts'))
check('the browser directory holds more than the account-first spec',
      len(spec_files) > 1,
      f'{len(spec_files)} spec files; a whole-suite run cannot be exactly 20')
check('the exact matrix runs only the account-first spec',
      'npx playwright test tests/Browser/account-first-registration.spec.ts' in runner)
check('the remaining suite has its own recorded gate',
      'playwright-remaining-suite' in runner)
check('the remaining-suite gate is in the registry',
      'playwright-remaining-suite' in {g.ledger_label for g in BY_LABEL.values()})

merger = (RELEASE / 'merge_playwright.py').read_text()
check('the merger has two documented modes',
      "'account-first'" in merger and "'remaining'" in merger)
check('account-first mode still forbids skips',
      "if stats.get('skipped', 0):" in merger)
check('remaining mode permits viewport-driven skips but not failures',
      "for bad in ('unexpected', 'flaky'):" in merger)

# Single-phase evidence packaging.
evidence_builder = (RELEASE / 'build_v7_evidence.py').read_text()
check('the evidence builder no longer creates a mid-run ZIP',
      'myhawler-account-first-registration-evidence.zip' not in evidence_builder,
      'its index described the directory before later files were written')
check('one finalizer packages evidence',
      (RELEASE / 'finalize_evidence.py').is_file()
      and 'finalize_evidence.py' in runner)

finalizer = (RELEASE / 'finalize_evidence.py').read_text()
check('the finalizer requires exact entry-set parity',
      'indexed but not shipped' in finalizer and 'shipped but not indexed' in finalizer)

# Behavioural: index and archive must agree.
with tempfile.TemporaryDirectory() as tmp:
    ev = Path(tmp) / 'ev'
    (ev / 'php').mkdir(parents=True)
    (ev / 'php' / 'a.log').write_text('one')
    (ev / 'b.log').write_text('two')
    out = Path(tmp) / 'evidence.zip'

    result = run(str(RELEASE / 'finalize_evidence.py'), '--evidence', str(ev),
                 '--output', str(out), '--tree-manifest-sha256', TREE)
    check('the finalizer packages a complete directory', result.returncode == 0,
          result.stderr.strip()[-200:])

    with zipfile.ZipFile(out) as zf:
        names = set(zf.namelist())
        idx = json.loads(zf.read('evidence-index.json'))
    check('the archive entry set equals its internal index',
          set(idx['files']) == names - {'evidence-index.json', 'evidence-index.json.sha256'})

# Post-seal recording.
check('post-seal gates run through the real recorder',
      'ATTEST_LEDGER' in runner and '--label sha256sums-verification' in runner
      and '--label final-archive-audit' in runner
      and '--label final-clean-verifier' in runner,
      'their command strings used to be written by hand after execution')
check('the reconstructed-metadata helper is gone',
      not (RELEASE / 'gate_metadata.py').exists())

attestation = (RELEASE / 'write_attestation.py').read_text()
check('the attestation consumes a measured ledger',
      '--attestation-ledger' in attestation)
for field in ('raw_output_sha256', 'raw_output_bytes'):
    check(f'the attestation records the measured {field}', f"'{field}'" in attestation)

# ===========================================================================
# Post-seal trust chain (01:36 audit): child-exit propagation, ledger-derived
# attestation, uploaded raw logs, exact argv, redacted environment, canonical
# remaining-suite coverage, recorded evidence finalizer, pre-seal scope.
# ===========================================================================
from release_gates import (  # noqa: E402
    ATTESTATION_ONLY_LABELS, PLAYWRIGHT_PROJECTS,
    PLAYWRIGHT_REMAINING_INTENTIONAL_SKIPS, PLAYWRIGHT_REMAINING_SPECS,
)

# ---- BLOCKER 1: the wrapper's exit code is always the child's --------------
check('the non-propagating recording mode is gone from the recorder',
      "add_argument('--allow-nonzero'" not in recorder
      and 'args.allow_nonzero' not in recorder,
      'a wrapper that exits 0 after a failed child hides the failure from $?')
check('no executed gate asks for a non-propagating recording',
      '--allow-nonzero' not in executable_runner)
check('every post-seal capture is cross-checked against the measured ledger',
      executable_runner.count('assert_recorded_exit') >= 5,
      'the definition plus one call per attestation-phase gate')
check('the runner stops on every nonzero post-seal capture',
      '[ "$CHECKSUM_RC" = "0" ] || fail' in runner
      and '[ "$FINAL_AUDIT_RC" = "0" ] || fail' in runner
      and '[ "$VERIFY_RC" = "0" ] || fail' in runner
      and '[ "$FINALIZER_RC" = "0" ] || fail' in runner)

with tempfile.TemporaryDirectory() as tmp:
    evidence = Path(tmp) / 'attest-evidence'
    ledger = Path(tmp) / 'attestation-ledger.json'

    refused = run(str(RELEASE / 'record_command.py'),
                  '--ledger', str(ledger), '--evidence-dir', str(evidence),
                  '--tree-manifest-sha256', TREE, '--label', 'final-clean-verifier',
                  '--allow-nonzero', '--', 'true')
    check('--allow-nonzero is refused outright', refused.returncode != 0)

    # The exact capture pattern the runner uses, executed for real: set +e
    # around the recorder, $? into a variable. The variable must hold the
    # child's 7 — recorded as 7, received as 7.
    capture = subprocess.run(
        ['bash', '-c',
         f'set -e; set +e; '
         f'"{sys.executable}" "{RELEASE / "record_command.py"}" '
         f'--ledger "{ledger}" --evidence-dir "{evidence}" '
         f'--tree-manifest-sha256 {TREE} --label final-clean-verifier '
         f'-- bash -c "exit 7"; RC=$?; set -e; echo "CAPTURED=$RC"'],
        capture_output=True, text=True)
    entries = {e['label']: e for e in json.loads(ledger.read_text())['entries']}
    check('a failed post-seal child records its real exit in the ledger',
          entries['final-clean-verifier']['exit_code'] == 7)
    check('the runner-style capture receives the child exit, not a wrapper 0',
          'CAPTURED=7' in capture.stdout, capture.stdout.strip()[-120:])

# ---- BLOCKER 4: exact argv and a redacted actual child environment ---------
with tempfile.TemporaryDirectory() as tmp:
    evidence = Path(tmp) / 'evidence'
    ledger = evidence / 'command-ledger.json'
    secret_env = {
        **os.environ,
        'MYHAWLER_DB_PASSWORD': 'super-secret-db-pass',
        'APP_KEY': 'base64:never-in-the-ledger',
        'TELEGRAM_WEBHOOK_SECRET': 'hook-secret-value',
        'TELEGRAM_BOT_TOKEN': '123456:bot-token-value',
        'MULKIHAWLER_BLIND_INDEX_KEY': 'b' * 64,
        'MULKIHAWLER_PII_KEY': 'c' * 64,
        'MYHAWLER_VISIBLE_SETTING': 'plainly-visible',
    }
    subprocess.run([sys.executable, str(RELEASE / 'record_command.py'),
                    '--ledger', str(ledger), '--evidence-dir', str(evidence),
                    '--tree-manifest-sha256', TREE, '--label', 'pint',
                    '--', 'bash', '-c', 'echo hello world'],
                   capture_output=True, text=True, env=secret_env)
    entry = json.loads(ledger.read_text())['entries'][0]

    check('argv is stored as the exact array',
          entry['argv'] == ['bash', '-c', 'echo hello world'],
          'a joined string cannot round-trip argument boundaries')
    check('the rendered command is a quoted display form, not the identity',
          entry['command'] == "bash -c 'echo hello world'")

    ledger_text = ledger.read_text()
    for name, value in (('database password', 'super-secret-db-pass'),
                        ('application key', 'base64:never-in-the-ledger'),
                        ('webhook secret', 'hook-secret-value'),
                        ('bot token', '123456:bot-token-value'),
                        ('blind-index key', 'b' * 64),
                        ('PII key', 'c' * 64)):
        check(f'the {name} value never reaches the ledger', value not in ledger_text)
    check('secret-bearing keys are recorded as [REDACTED]',
          entry['child_environment'].get('MYHAWLER_DB_PASSWORD') == '[REDACTED]'
          and entry['child_environment'].get('TELEGRAM_WEBHOOK_SECRET') == '[REDACTED]')
    check('non-secret variables keep their value in the recorded environment',
          entry['child_environment'].get('MYHAWLER_VISIBLE_SETTING') == 'plainly-visible')
    check('an inherited-environment record is not marked clean-env',
          entry['clean_env'] is False and entry['clean_env_allow_list'] is None)

    subprocess.run([sys.executable, str(RELEASE / 'record_command.py'),
                    '--ledger', str(ledger), '--evidence-dir', str(evidence),
                    '--tree-manifest-sha256', TREE, '--label', 'rollback-rehearsal',
                    '--clean-env', '--database',
                    '--', 'bash', '-c', 'echo clean'],
                   capture_output=True, text=True,
                   env={**secret_env, 'REHEARSAL_DB_PASSWORD': 'rehearsal-secret'})
    entries = {e['label']: e for e in json.loads(ledger.read_text())['entries']}
    clean = entries['rollback-rehearsal']

    check('clean-env records the exact allow-list applied',
          clean['clean_env'] is True
          and clean['clean_env_allow_list'] == {'keys': ['HOME', 'LANG', 'PATH', 'TERM'],
                                                'prefixes': ['REHEARSAL_']})
    allowed = set(clean['clean_env_allow_list']['keys'])
    check('the recorded clean environment holds only allow-listed keys',
          all(key in allowed or key.startswith('REHEARSAL_')
              for key in clean['child_environment']))
    check('a secret passing the clean-env filter is still redacted',
          clean['child_environment'].get('REHEARSAL_DB_PASSWORD') == '[REDACTED]'
          and 'rehearsal-secret' not in ledger.read_text())

# ---- BLOCKER 2: the attestation derives from the ledger and refuses --------
# ---- contradictions ---------------------------------------------------------


def build_attestation_fixture(base: Path, exits: dict | None = None):
    evidence = base / 'attestation-evidence'
    ledger = base / 'attestation-ledger.json'
    exits = exits or {}
    for label in ATTESTATION_ONLY_LABELS:
        code = exits.get(label, 0)
        run(str(RELEASE / 'record_command.py'),
            '--ledger', str(ledger), '--evidence-dir', str(evidence),
            '--tree-manifest-sha256', TREE, '--label', label,
            '--', 'bash', '-c', f'echo {label}; exit {code}')
    master = base / 'master.zip'
    with zipfile.ZipFile(master, 'w') as zf:
        zf.writestr('payload.txt', 'sealed bytes')
    return evidence, ledger, master


def write_attestation_cli(base: Path, evidence: Path, ledger: Path,
                          master: Path, observed: dict | None = None):
    if observed is None:
        observed = {label: 0 for label in ATTESTATION_ONLY_LABELS}
    argv = [str(RELEASE / 'write_attestation.py'),
            '--output', str(base / 'final-attestation.json'),
            '--tree-manifest-sha256', TREE,
            '--master', str(master),
            '--attestation-ledger', str(ledger),
            '--attestation-evidence', str(evidence)]
    for label, code in observed.items():
        argv += ['--observed-exit', f'{label}={code}']
    return run(*argv)


with tempfile.TemporaryDirectory() as tmp:
    base = Path(tmp)
    evidence, ledger, master = build_attestation_fixture(base)

    verified = write_attestation_cli(base, evidence, ledger, master)
    doc = json.loads((base / 'final-attestation.json').read_text())
    check('a consistent all-green ledger attests VERIFIED',
          verified.returncode == 0 and doc['verdict'] == 'VERIFIED',
          verified.stderr.strip()[-200:])
    check('the attestation covers every attestation-phase gate',
          set(doc['attestation_gates']) == set(ATTESTATION_ONLY_LABELS))
    gate = doc['attestation_gates']['final-clean-verifier']
    check('each attested gate carries its exact argv',
          gate['argv'][:2] == ['bash', '-c'])
    check('one measured exit code, cross-checked against the observed capture',
          gate['exit_code'] == 0 and gate['observed_exit_code'] == 0
          and 'recorded_exit_code' not in gate)
    check('the attested verifier fields are the measured ledger fields',
          doc['verifier_exit_code'] == 0
          and doc['verifier_log_sha256'] == gate['raw_output_sha256']
          and doc['verifier_argv'] == gate['argv'])
    check('the sealed master is bound by hash and size',
          doc['master_sha256'] == hashlib.sha256(master.read_bytes()).hexdigest()
          and doc['master_bytes'] == master.stat().st_size)

with tempfile.TemporaryDirectory() as tmp:
    base = Path(tmp)
    evidence, ledger, master = build_attestation_fixture(
        base, exits={'final-clean-verifier': 7})

    lying = write_attestation_cli(base, evidence, ledger, master)
    check('a capture contradicting the measured ledger refuses the attestation',
          lying.returncode != 0 and not (base / 'final-attestation.json').exists(),
          f'exit {lying.returncode}; the old writer emitted VERIFIED here')

    honest = write_attestation_cli(
        base, evidence, ledger, master,
        observed={**{label: 0 for label in ATTESTATION_ONLY_LABELS},
                  'final-clean-verifier': 7})
    doc = json.loads((base / 'final-attestation.json').read_text())
    check('a consistently-reported failure attests NOT VERIFIED and exits nonzero',
          honest.returncode != 0 and doc['verdict'] == 'NOT VERIFIED')

with tempfile.TemporaryDirectory() as tmp:
    base = Path(tmp)
    evidence, ledger, master = build_attestation_fixture(base)
    log = evidence / 'verification' / 'sha256sums-verification.log'

    original = log.read_bytes()
    log.write_bytes(original + b'tampered')
    check('a raw-log hash mismatch refuses the attestation',
          write_attestation_cli(base, evidence, ledger, master).returncode != 0
          and not (base / 'final-attestation.json').exists())
    log.write_bytes(original)

    document = json.loads(ledger.read_text())
    for entry in document['entries']:
        if entry['label'] == 'final-archive-audit':
            entry['raw_output_bytes'] += 1
    ledger.write_text(json.dumps(document))
    check('a raw-log size mismatch refuses the attestation',
          write_attestation_cli(base, evidence, ledger, master).returncode != 0)
    for entry in document['entries']:
        if entry['label'] == 'final-archive-audit':
            entry['raw_output_bytes'] -= 1
    ledger.write_text(json.dumps(document))

    check('the restored fixture attests cleanly again',
          write_attestation_cli(base, evidence, ledger, master).returncode == 0)

    finalizer_log = evidence / 'packaging' / 'evidence-finalizer.log'
    saved = finalizer_log.read_bytes()
    finalizer_log.unlink()
    check('a missing raw log refuses the attestation',
          write_attestation_cli(base, evidence, ledger, master).returncode != 0)
    finalizer_log.write_bytes(saved)

    partial = write_attestation_cli(
        base, evidence, ledger, master,
        observed={label: 0 for label in ATTESTATION_ONLY_LABELS
                  if label != 'final-archive-audit'})
    check('an unconsumed gate exit refuses the attestation',
          partial.returncode != 0,
          'the runner must capture and hand over every post-seal exit')

    unknown = write_attestation_cli(
        base, evidence, ledger, master,
        observed={**{label: 0 for label in ATTESTATION_ONLY_LABELS}, 'pint': 0})
    check('an observed exit for a non-attestation gate is refused',
          unknown.returncode != 0)

    document = json.loads(ledger.read_text())
    document['entries'] = [e for e in document['entries']
                           if e['label'] != 'evidence-finalizer']
    ledger.write_text(json.dumps(document))
    check('a gate missing from the measured ledger refuses the attestation',
          write_attestation_cli(base, evidence, ledger, master).returncode != 0)

with tempfile.TemporaryDirectory() as tmp:
    base = Path(tmp)
    evidence, ledger, master = build_attestation_fixture(base)
    (base / 'master.zip.sha256').write_text('0' * 64 + '  master.zip\n')
    check('a detached checksum contradicting the sealed bytes is refused',
          write_attestation_cli(base, evidence, ledger, master).returncode != 0)

# ---- BLOCKER 3: the attestation upload carries the real material -----------
attest_upload = workflow.split('name: myhawler-final-attestation')[1] \
                        .split('if-no-files-found')[0]
for member in ('final-attestation.json', 'final-attestation.json.sha256',
               'final-verification.log', 'attestation-ledger.json',
               'attestation-evidence/'):
    check(f'the attestation upload includes {member}', member in attest_upload)
check('the workflow no longer advertises logs the runner never writes',
      '${{ env.WORK }}/sha256sums-verification.log' not in workflow
      and '${{ env.WORK }}/final-archive-audit.log' not in workflow)
check('the runner proves the attestation upload set exists before success',
      'attestation material missing' in runner)

# ---- BLOCKER 5: the remaining suite runs from the canonical inventory ------
specs_cli = run(str(RELEASE / 'release_gates.py'), '--remaining-specs')
check('the registry prints the canonical remaining inventory',
      specs_cli.stdout.split() == list(PLAYWRIGHT_REMAINING_SPECS))
check('the runner builds the remaining argv from the registry',
      '--remaining-specs' in executable_runner
      and '"${REMAINING_SPECS[@]}"' in runner)
check('title matching is gone from the executed remaining suite',
      '--grep-invert' not in executable_runner,
      'coverage must depend on the file inventory, not on test titles')
check('the remaining report is validated by the remaining-mode merger',
      '--mode remaining' in executable_runner
      and 'playwright-remaining-merge' in executable_runner)
check('the remaining merge gate is canonical',
      'playwright-remaining-merge' in {g.ledger_label for g in BY_LABEL.values()})
check('every intentional-skip spec is part of the canonical inventory',
      set(PLAYWRIGHT_REMAINING_INTENTIONAL_SKIPS) <= set(PLAYWRIGHT_REMAINING_SPECS))


def remaining_report(rows):
    """rows: (spec file basename, test title, project, status)."""
    by_file: dict = {}
    for file, title, project, status in rows:
        by_file.setdefault(file, []).append((title, project, status))
    stats = {'expected': 0, 'unexpected': 0, 'skipped': 0, 'flaky': 0}
    for tests in by_file.values():
        for _, _, status in tests:
            if status in stats:
                stats[status] += 1
    return {
        'suites': [
            {'title': file, 'file': file,
             'specs': [{'title': title, 'file': file,
                        'tests': [{'projectName': project, 'status': status}]}
                       for title, project, status in tests]}
            for file, tests in sorted(by_file.items())
        ],
        'stats': stats,
    }


def run_remaining_merge(base: Path, report=None, extra=()):
    browser = base / 'remaining'
    browser.mkdir(parents=True, exist_ok=True)
    if report is not None:
        (browser / 'remaining.json').write_text(json.dumps(report))
    return run(str(RELEASE / 'merge_playwright.py'),
               '--browser-dir', str(browser),
               '--tree-manifest-sha256', TREE, '--mode', 'remaining', *extra)


full_rows = [
    (spec.rsplit('/', 1)[-1], f'{project} scenario', project, 'expected')
    for spec in PLAYWRIGHT_REMAINING_SPECS
    for project in PLAYWRIGHT_PROJECTS
]

with tempfile.TemporaryDirectory() as tmp:
    base = Path(tmp)

    complete = run_remaining_merge(base, remaining_report(
        full_rows + [('admin.spec.ts', 'admin flow (small viewport)',
                      'mobile-360x800', 'skipped')]))
    check('a canonical, clean remaining run passes the merge gate',
          complete.returncode == 0, complete.stderr.strip()[-200:])
    merged_doc = json.loads(
        (base / 'remaining' / 'playwright-merged-remaining.json').read_text())
    check('the remaining merge writes its own merged report',
          merged_doc['mode'] == 'remaining')

    check('a canonical spec absent from the report fails the gate',
          run_remaining_merge(base, remaining_report(
              [r for r in full_rows if r[0] != 'public-home.spec.ts']
          )).returncode != 0)

    check('an account-first scenario inside the remaining suite fails the gate',
          run_remaining_merge(base, remaining_report(
              full_rows + [('account-first-registration.spec.ts', 'register',
                            'desktop-1440x900', 'expected')])).returncode != 0)

    check('a spec outside the canonical inventory fails the gate',
          run_remaining_merge(base, remaining_report(
              full_rows + [('surprise.spec.ts', 'novel scenario',
                            'desktop-1440x900', 'expected')])).returncode != 0)

    check('a failed test fails the remaining gate',
          run_remaining_merge(base, remaining_report(
              full_rows + [('auth.spec.ts', 'broken login',
                            'desktop-1440x900', 'unexpected')])).returncode != 0)

    check('a flake fails the remaining gate',
          run_remaining_merge(base, remaining_report(
              full_rows + [('auth.spec.ts', 'retried login',
                            'desktop-1440x900', 'flaky')])).returncode != 0)

    check('a skip outside the reviewed intentional set fails the gate',
          run_remaining_merge(base, remaining_report(
              full_rows + [('auth.spec.ts', 'sneaky skip',
                            'desktop-1440x900', 'skipped')])).returncode != 0)

    check('a project with nothing executed fails the gate',
          run_remaining_merge(base, remaining_report(
              [r for r in full_rows if r[2] != 'desktop-1440x900']
          )).returncode != 0)

with tempfile.TemporaryDirectory() as tmp:
    base = Path(tmp)
    check('an absent remaining report fails instead of passing vacuously',
          run_remaining_merge(base, report=None).returncode != 0,
          'an empty directory used to merge to exit 0')
    check('only the declared offline stub may merge nothing',
          run_remaining_merge(base, report=None,
                              extra=('--allow-empty',)).returncode == 0)

# ---- BLOCKER 6: the evidence finalizer is a truthful recorded gate ---------
check('the evidence finalizer is a canonical attestation-phase gate',
      'evidence-finalizer' in ATTESTATION_ONLY_LABELS
      and 'evidence-finalizer' in {g.ledger_label for g in BY_LABEL.values()})
check('the runner records the finalizer through the real recorder',
      '--label evidence-finalizer' in executable_runner)
finalizer_at = runner.index('--label evidence-finalizer')
finalizer_segment = runner[max(0, finalizer_at - 400):finalizer_at]
check('the finalizer is recorded into the attestation ledger, outside the '
      'archive it seals',
      'ATTEST_LEDGER' in finalizer_segment
      and 'ATTEST_EVIDENCE' in finalizer_segment)
check('the recorded finalizer runs before the delivered ledger is validated',
      finalizer_at < runner.index('--ledger "$DELIVERY/command-ledger.json"'))

# ---- BLOCKER 7: release-evidence.json names its exact pre-seal scope -------
evidence_writer = (RELEASE / 'write_release_evidence.py').read_text()
check('the pre-seal artifact map is named for its exact scope',
      "'pre_seal_component_artifacts'" in evidence_writer
      and "'component_artifacts'" not in evidence_writer,
      'a map generated before sealing cannot claim to bind the sealed set')
check('the pre-seal scope is documented inside the document itself',
      'pre_seal_component_artifacts_scope' in evidence_writer)
check('the sealed artifacts are bound by the external attestation instead',
      '--evidence-zip' in attestation and 'sealed_artifacts' in attestation)

# ===========================================================================
# Sealed-baseline legacy artifact: a waiver pinned to ONE archive's bytes.
#
# The verified v6 baseline (sealed commit 9c0188f8…) carries a stale editor
# backup that the v6-era auditor's `.backup`-exact pattern did not catch. v7
# hardened that pattern, so the immutable historical input now fails an audit it
# legitimately passed. The archive must not be rebuilt, and the pattern must not
# be relaxed — so exactly one member of exactly one archive is excused, and
# everything else stays strict.
# ===========================================================================
import importlib.util  # noqa: E402

_spec = importlib.util.spec_from_file_location(
    'audit_archive_under_test', RELEASE / 'audit_archive.py')
aa = importlib.util.module_from_spec(_spec)
_spec.loader.exec_module(aa)

BASELINE_SHA = '48bfca9ef14b71a9c3605c249cf9cfe366830eb04303f58ddb3ba6befe7eb4d7'
LEGACY = ('app/Modules/Operations/Http/Controllers/Admin/'
          'OperationsController.php.backup-20260802-014031')

# ---- the production waiver is pinned exactly, and does not creep -----------
check('the waiver names exactly one archive hash',
      list(aa.BASELINE_LEGACY_ARTIFACTS) == [BASELINE_SHA],
      f'table keys: {list(aa.BASELINE_LEGACY_ARTIFACTS)}')
check('the waiver names exactly one member path',
      aa.BASELINE_LEGACY_ARTIFACTS[BASELINE_SHA] == frozenset({LEGACY}))
check('the hardened suffix pattern still matches the legacy artifact itself',
      aa.UNSAFE_NAME.search(LEGACY.rsplit('/', 1)[-1]) is not None,
      'the waiver must excuse a real finding, not paper over a weakened pattern')
check('DELETE_FILES.txt removes that exact legacy path on upgrade',
      LEGACY in (ROOT / 'DELETE_FILES.txt').read_text().split())


def _zip(path, members, *, root='mulkihawler'):
    """Build a fixture archive; members is {relative name: bytes|str}."""
    with zipfile.ZipFile(path, 'w') as zf:
        for rel, body in members.items():
            zf.writestr(f'{root}/{rel}' if root else rel, body)
    return path


def _register(path):
    """Pin the fixture's real digest, as the production table pins the baseline."""
    digest = aa.sha256_file(str(path))
    aa.BASELINE_LEGACY_ARTIFACTS[digest] = frozenset({LEGACY})
    return digest


with tempfile.TemporaryDirectory() as tmp:
    base = Path(tmp)

    # ---- 1. the verified baseline shape is accepted ------------------------
    ok = _zip(base / 'baseline-ok.zip', {'artisan': '#!/usr/bin/env php\n',
                                         LEGACY: 'stale backup\n'})
    _register(ok)
    problems = aa.audit(str(ok))
    check('a pinned baseline carrying only the named legacy artifact is accepted',
          problems == [], str(problems))

    # ---- 2. the SAME path in a differently-hashed baseline is rejected -----
    other = _zip(base / 'baseline-other.zip', {'artisan': '#!/usr/bin/env php\n',
                                               LEGACY: 'different bytes entirely\n'})
    problems = aa.audit(str(other))
    check('the same legacy path in a differently-hashed archive is rejected',
          any('unsafe artifact' in p and LEGACY in p for p in problems),
          'the waiver must be pinned to bytes, never to a path')

    # ---- 3. another .backup file is rejected even in the pinned archive ----
    extra = LEGACY.replace('OperationsController.php', 'SomethingElse.php')
    two = _zip(base / 'baseline-two.zip', {'artisan': '#!/usr/bin/env php\n',
                                           LEGACY: 'stale\n', extra: 'also stale\n'})
    _register(two)
    problems = aa.audit(str(two))
    check('an ADDITIONAL unsafe artifact in the pinned archive still fails',
          any(extra in p for p in problems)
          and not any(f'unsafe artifact: mulkihawler/{LEGACY}' == p for p in problems),
          str(problems))

    # ---- 4. the source archive stays strictly audited ----------------------
    src = _zip(base / 'source.zip', {'artisan': '#!/usr/bin/env php\n',
                                     'app/Thing.php.backup-20260101-000000': 'x\n'})
    problems = aa.audit(str(src))
    check('an unpinned (source) archive is audited strictly, with no waiver',
          any('unsafe artifact' in p for p in problems), str(problems))

    # ---- 5. structural attacks remain rejected in the PINNED archive ------
    hostile = base / 'baseline-hostile.zip'
    with zipfile.ZipFile(hostile, 'w') as zf:
        zf.writestr('mulkihawler/artisan', '#!/usr/bin/env php\n')
        zf.writestr(f'mulkihawler/{LEGACY}', 'stale\n')
        zf.writestr('mulkihawler/../escape.txt', 'x')
        zf.writestr('/absolute.txt', 'x')
        zf.writestr('mulkihawler\\windows.txt', 'x')
        zf.writestr('mulkihawler/dup.txt', 'a')
        zf.writestr('mulkihawler/dup.txt', 'b')
        link = zipfile.ZipInfo('mulkihawler/link')
        link.external_attr = 0xA1FF << 16
        zf.writestr(link, 'target')
    _register(hostile)
    problems = aa.audit(str(hostile))
    joined = ' | '.join(problems)
    for label, needle in (('path traversal', 'unsafe path'),
                          ('absolute path', 'unsafe path: /absolute.txt'),
                          ('backslash path', 'windows or backslash'),
                          ('duplicate entry', 'duplicate entry'),
                          ('symlink', 'symlink')):
        check(f'{label} is still rejected inside the pinned baseline',
              needle in joined, joined[:200])
    # Fail closed: a structurally anomalous archive gets NO waiver at all. The
    # root cannot be established (one entry carries no '/'), so the named
    # artifact is reported too rather than excused on a malformed layout.
    check('a structurally hostile archive forfeits the waiver entirely',
          any(f'unsafe artifact: mulkihawler/{LEGACY}' == p for p in problems),
          'a waiver must never survive a layout it cannot verify')

# ===========================================================================
# Adversarial-review round: signal deaths, argv secret hygiene, spec-inventory
# closure, ERR-trap-safe captures, and report-label drift.
# ===========================================================================

# A signal death reports -N from subprocess while a shell parent observes
# 128+N. Both the ledger and the propagated exit must be the same number a
# capture actually sees, or the impersonation cross-checks would raise a false
# tamper diagnosis for an ordinary OOM kill.
with tempfile.TemporaryDirectory() as tmp:
    evidence = Path(tmp) / 'evidence'
    ledger = evidence / 'command-ledger.json'

    signalled = run(str(RELEASE / 'record_command.py'),
                    '--ledger', str(ledger), '--evidence-dir', str(evidence),
                    '--tree-manifest-sha256', TREE, '--label', 'final-clean-verifier',
                    '--', 'bash', '-c', 'kill -9 $$')
    entry = json.loads(ledger.read_text())['entries'][0]

    check('a signal-killed child is recorded in the shell convention',
          entry['exit_code'] == 137 and entry['termination_signal'] == 9,
          f'recorded {entry["exit_code"]}, signal {entry.get("termination_signal")}')
    check('the propagated exit equals the recorded exit for a signal death',
          signalled.returncode == entry['exit_code'],
          f'wrapper {signalled.returncode} vs ledger {entry["exit_code"]}')
    check('a normal exit records no termination signal',
          'termination_signal' in recorder and "'termination_signal'" in recorder)

# The exact-argv record must not become the leak the environment redaction
# exists to prevent: a KEY=VALUE argv token with a secret-bearing KEY is
# stored redacted, in both the argv array and the rendered command.
with tempfile.TemporaryDirectory() as tmp:
    evidence = Path(tmp) / 'evidence'
    ledger = evidence / 'command-ledger.json'

    run(str(RELEASE / 'record_command.py'),
        '--ledger', str(ledger), '--evidence-dir', str(evidence),
        '--tree-manifest-sha256', TREE, '--label', 'rollback-rehearsal',
        '--', 'env', 'REHEARSAL_DB_PASSWORD=SuperSecret123',
        'REHEARSAL_STAGE=/tmp/stage', 'true')
    entry = json.loads(ledger.read_text())['entries'][0]
    ledger_text = ledger.read_text()

    check('a secret-bearing KEY=VALUE argv token is stored redacted',
          'REHEARSAL_DB_PASSWORD=[REDACTED]' in entry['argv']
          and 'SuperSecret123' not in ledger_text,
          'the rollback gate once shipped its DB password through exact argv')
    check('the rendered command uses the same redacted argv',
          'SuperSecret123' not in entry['command']
          and 'REHEARSAL_DB_PASSWORD=[REDACTED]' in entry['command'])
    check('non-secret argv tokens stay exact',
          'REHEARSAL_STAGE=/tmp/stage' in entry['argv']
          and entry['argv'][0] == 'env' and entry['argv'][-1] == 'true')

# The delivered-package secret scan decides placeholder status ONLY by value
# shape; a name-keyed exemption suppressed exactly the lines that would carry
# REHEARSAL_DB_PASSWORD's literal value.
verifier_source = (RELEASE / 'verify_final_delivery.py').read_text()
check('the secret scan has no name-keyed exemption',
      "'REHEARSAL_DB_PASSWORD' in line" not in verifier_source,
      'a scan keyed on the key name is blind to that key\'s literal value')

with tempfile.TemporaryDirectory() as tmp:
    hostile = Path(tmp) / 'delivery.zip'
    with zipfile.ZipFile(hostile, 'w') as zf:
        zf.writestr('command-ledger.json',
                    '{"command": "env REHEARSAL_DB_PASSWORD=LiteralLeak42 true"}')
    # Behavioural: run the delivered verifier's inspect() against a ZIP whose
    # ledger carries a literal REHEARSAL_DB_PASSWORD value.
    probe = subprocess.run(
        [sys.executable, '-c', f'''
import io, sys, zipfile
sys.argv = ["verify_final_delivery.py", {str(Path(tmp))!r}]
source = open({str(RELEASE / "verify_final_delivery.py")!r}).read()
namespace = {{}}
exec(source.split("master = ")[0], namespace)
findings = namespace["inspect"]("delivery.zip", open({str(hostile)!r}, "rb").read())
print("FINDINGS:", findings)
'''],
        capture_output=True, text=True)
    check('a literal REHEARSAL_DB_PASSWORD value is reported by the scan',
          'DB_PASSWORD=LiteralLeak' in probe.stdout,
          probe.stdout.strip()[-200:] + probe.stderr.strip()[-200:])

# Spec-inventory closure: the remaining suite executes exactly the registry,
# so the on-disk inventory and the registry must be proven equal both ways.
check('the spec-closure gate is canonical',
      'browser-spec-closure' in {g.ledger_label for g in BY_LABEL.values()})
check('the runner records the spec closure before the browser suites',
      'record_tool browser-spec-closure' in runner
      and runner.index('record_tool browser-spec-closure')
      < runner.index('record_in_db "$BROWSER_WORKSPACE" "playwright-${project}"'))

closure_ok = run(str(RELEASE / 'release_gates.py'),
                 '--verify-spec-closure', str(ROOT / 'tests' / 'Browser'))
check('the shipped tree satisfies spec closure',
      closure_ok.returncode == 0, closure_ok.stderr.strip()[-200:])

with tempfile.TemporaryDirectory() as tmp:
    fixture = Path(tmp) / 'Browser'
    fixture.mkdir()
    from release_gates import PLAYWRIGHT_ACCOUNT_FIRST_SPEC  # noqa: E402
    all_specs = (PLAYWRIGHT_ACCOUNT_FIRST_SPEC, *PLAYWRIGHT_REMAINING_SPECS)
    for spec in all_specs:
        (fixture / spec.rsplit('/', 1)[-1]).write_text('// spec\n')

    check('a complete inventory passes closure',
          run(str(RELEASE / 'release_gates.py'),
              '--verify-spec-closure', str(fixture)).returncode == 0)

    (fixture / 'payments.spec.ts').write_text('// unregistered\n')
    rogue = run(str(RELEASE / 'release_gates.py'),
                '--verify-spec-closure', str(fixture))
    check('an unregistered on-disk spec fails closure',
          rogue.returncode != 0 and 'silently never run' in rogue.stderr,
          'a new spec must be registered or the release must stop')
    (fixture / 'payments.spec.ts').unlink()

    (fixture / 'auth.spec.ts').unlink()
    check('a canonical spec missing from disk fails closure',
          run(str(RELEASE / 'release_gates.py'),
              '--verify-spec-closure', str(fixture)).returncode != 0)
    (fixture / 'auth.spec.ts').write_text('// spec\n')

    (fixture / 'smoke').mkdir()
    (fixture / 'smoke' / 'auth.spec.ts').write_text('// duplicate basename\n')
    check('a duplicate spec basename fails closure',
          run(str(RELEASE / 'release_gates.py'),
              '--verify-spec-closure', str(fixture)).returncode != 0,
          'report matching is by basename, so basenames must stay unique')

# ERR-trap-safe capture: a plain failing command inside `set +e` still fires
# the ERR trap, aborting before the cross-check and tailored diagnosis run.
# The captures are therefore `|| RC=$?`, which is exempt from both.
check('post-seal captures are ERR-trap-safe',
      executable_runner.count('|| FINALIZER_RC=$?') == 1
      and executable_runner.count('|| CHECKSUM_RC=$?') == 1
      and executable_runner.count('|| FINAL_AUDIT_RC=$?') == 1
      and executable_runner.count('|| VERIFY_RC=$?') == 1)
check('no post-seal capture relies on set +e around a bare command',
      'set +e' not in executable_runner)

# Report-label drift: the SECURITY_REVIEW table must name gates that exist.
reports_source = (RELEASE / 'generate_release_reports.py').read_text()
check('SECURITY_REVIEW names the component archive audit by its real label',
      "'component-archive-audit'" in reports_source
      and "'archive-audit')" not in reports_source)

# ---- generated build output is retired by replacement, not by 100 entries ---
#
# Vite renames every chunk on every build, so an upgrade "removes" ~100 files
# nobody wrote. DEPLOYMENT_NOTES.md, deploy_rehearsal.sh and
# rollback_rehearsal_v7.sh all replace the build directory whole, which retires
# those chunks more completely than a hand-maintained list could. These tests
# hold the exemption to exactly that: the replacement root and nothing else, and
# only while the current tree really carries a complete build.
BUILDER = str(RELEASE / 'build_source_patch.py')
APP_FILE = 'app/Modules/Identity/Http/Controllers/RegistrationController.php'
OLD_CHUNKS = {f'public/build/assets/Page{i}-OLDHASH{i:03d}.js': f'old chunk {i}\n'
              for i in range(120)}
NEW_CHUNKS = {f'public/build/assets/Page{i}-NEWHASH{i:03d}.js': f'new chunk {i}\n'
              for i in range(118)}
# Byte-identical in both trees: neither added nor modified, and still required
# for the site to render after the directory is replaced.
UNCHANGED_CHUNK = {'public/build/assets/vendor-STABLE.js': 'unchanged vendor\n'}
MANIFEST = ('public/build/manifest.json',
            '{"resources/js/app.js": {"file": "assets/vendor-STABLE.js", '
            '"css": ["assets/app-STABLE.css"]}}')
STYLESHEET = {'assets/app-STABLE.css': ':root{}\n'}


def build_tree(root: Path, files: dict[str, str]) -> Path:
    root.mkdir(parents=True, exist_ok=True)
    for rel, body in files.items():
        target = root / rel
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_text(body)
    return root


def source_patch(base: Path, tag: str, old: dict, new: dict,
                 deletions: list[str]) -> tuple:
    """Run the REAL builder over fixture trees; returns (proc, patch, inventory)."""
    work = base / tag
    baseline = work / 'baseline.zip'
    work.mkdir(parents=True)

    with zipfile.ZipFile(baseline, 'w') as zf:
        for rel, body in old.items():
            zf.writestr(f'mulkihawler/{rel}', body)

    current = build_tree(work / 'current', new)
    declared = work / 'DELETE_FILES.txt'
    declared.write_text('# fixture manifest\n' + ''.join(f'{d}\n' for d in deletions))
    patch, inventory = work / 'patch.zip', work / 'inventory.json'

    proc = run(BUILDER, '--baseline', str(baseline), '--current', str(current),
               '--deletions', str(declared), '--output', str(patch),
               '--inventory', str(inventory))

    return proc, patch, inventory


def base_files(chunks: dict) -> dict:
    files = {'artisan': '#!/usr/bin/env php\n',
             APP_FILE: 'class RegistrationController {}\n',
             MANIFEST[0]: MANIFEST[1]}
    files.update(chunks)
    files.update(UNCHANGED_CHUNK)
    files.update({f'public/build/{rel}': body for rel, body in STYLESHEET.items()})
    return files


with tempfile.TemporaryDirectory() as tmp:
    base = Path(tmp)

    OLD_TREE = base_files(OLD_CHUNKS) | {'docs/stale-report.md': 'retired\n'}
    NEW_TREE = base_files(NEW_CHUNKS) | {APP_FILE: 'class RegistrationController { }\n'}

    # ---- 1. a hundred retired chunks need no deletion entries --------------
    proc, patch, inventory = source_patch(
        base, 'replaced', OLD_TREE, NEW_TREE, ['docs/stale-report.md'])
    check('120 retired Vite chunks do not require 120 deletion entries',
          proc.returncode == 0,
          proc.stderr.strip()[-400:] or proc.stdout.strip()[-200:])

    inv = json.loads(inventory.read_text()) if inventory.is_file() else {}
    check('the retired chunks are counted and attributed to the replacement root',
          len(inv.get('removed_generated', [])) == len(OLD_CHUNKS)
          and inv.get('replaced_roots') == ['public/build'],
          f'removed_generated={len(inv.get("removed_generated", []))}, '
          f'roots={inv.get("replaced_roots")}')
    check('the retired chunks are still reported, never silently dropped',
          len(inv.get('removed', [])) == len(OLD_CHUNKS) + 1
          and 'REPLACED  120 previous-build file(s)' in proc.stdout,
          proc.stdout.strip()[-200:])
    check('the run reports zero undeclared removals outside the root',
          'undeclared removals outside the replacement roots: 0' in proc.stdout)

    # ---- 2. the patch carries the COMPLETE current build ------------------
    with zipfile.ZipFile(patch) as zf:
        carried = {i.filename for i in zf.infolist() if not i.is_dir()}

    expected_build = {rel for rel in NEW_TREE if rel.startswith('public/build/')}
    check('the patch carries the complete current build, not only its changes',
          expected_build <= carried,
          f'missing: {sorted(expected_build - carried)[:3]}')
    check('an unchanged chunk is carried too, because the directory is replaced',
          'public/build/assets/vendor-STABLE.js' in carried,
          'replacing a directory with a changed-files-only copy loses every '
          'chunk that happened not to change')
    check('the patch ships the replacement instruction beside the files',
          'REPLACE_DIRS.txt' in carried
          and b'public/build' in zipfile.ZipFile(patch).read('REPLACE_DIRS.txt'))

    # ---- 3. nothing outside the replacement root is exempt ----------------
    dropped_app = {k: v for k, v in NEW_TREE.items() if k != APP_FILE}
    dropped_app['app/Modules/Identity/Support/Keeper.php'] = 'class Keeper {}\n'
    with_extra = OLD_TREE | {'app/Modules/Identity/Support/Keeper.php': 'class Keeper {}\n'}
    proc = source_patch(base, 'undeclared', with_extra, dropped_app,
                        ['docs/stale-report.md'])[0]
    check('an undeclared removal OUTSIDE public/build is still a hard failure',
          proc.returncode != 0
          and f'removed from the tree but absent from DELETE_FILES.txt: {APP_FILE}'
          in proc.stderr,
          proc.stderr.strip()[-300:])

    # ---- 4. DELETE_FILES.txt stays authoritative for real deletions -------
    proc = source_patch(base, 'undeclared-doc', OLD_TREE, NEW_TREE, [])[0]
    check('a real application deletion still has to be declared',
          proc.returncode != 0 and 'docs/stale-report.md' in proc.stderr)

    proc = source_patch(base, 'not-removed', OLD_TREE, NEW_TREE,
                        ['docs/stale-report.md', APP_FILE])[0]
    check('declaring a file that is still present is still a failure',
          proc.returncode != 0
          and f'still present in the tree: {APP_FILE}' in proc.stderr,
          proc.stderr.strip()[-300:])

    proc = source_patch(base, 'never-existed', OLD_TREE, NEW_TREE,
                        ['docs/stale-report.md', 'app/Never/Existed.php'])[0]
    check('declaring a file the baseline never had is a failure',
          proc.returncode != 0
          and 'absent from the baseline: app/Never/Existed.php' in proc.stderr,
          proc.stderr.strip()[-300:])

    # An entry the patch comparison deliberately drops — the sealed baseline's
    # editor backup — must still verify, against the trees rather than the sets.
    legacy = f'{APP_FILE}.backup-20260802-014031'
    proc = source_patch(base, 'legacy-entry', OLD_TREE | {legacy: 'stale\n'},
                        NEW_TREE, ['docs/stale-report.md', legacy])[0]
    check('a declared path the eligibility policy filters out still verifies',
          proc.returncode == 0,
          'set arithmetic called this "declared but not actually removed"; '
          'presence on disk is the real question — ' + proc.stderr.strip()[-200:])

    # ---- 5. the exemption is FAIL-CLOSED ----------------------------------
    no_build = {k: v for k, v in NEW_TREE.items() if not k.startswith('public/build/')}
    proc = source_patch(base, 'no-build', OLD_TREE, no_build, ['docs/stale-report.md'])[0]
    check('a tree with no build directory forfeits the exemption entirely',
          proc.returncode != 0
          and 'generated replacement root is missing from the tree: public/build'
          in proc.stderr
          and 'public/build/assets/Page0-OLDHASH000.js' in proc.stderr,
          'the retired chunks must become ordinary undeclared removals — '
          + proc.stderr.strip()[-300:])

    truncated = {k: v for k, v in NEW_TREE.items()
                 if k != 'public/build/assets/vendor-STABLE.js'}
    proc = source_patch(base, 'truncated', OLD_TREE, truncated, ['docs/stale-report.md'])[0]
    check('a build whose manifest does not resolve forfeits the exemption',
          proc.returncode != 0
          and 'manifest entry does not resolve under public/build: '
              'assets/vendor-STABLE.js' in proc.stderr,
          'a patch that would deploy an incomplete build must never pass — '
          + proc.stderr.strip()[-300:])

    no_manifest = {k: v for k, v in NEW_TREE.items() if k != MANIFEST[0]}
    proc = source_patch(base, 'no-manifest', OLD_TREE, no_manifest,
                        ['docs/stale-report.md'])[0]
    check('a build with no manifest forfeits the exemption',
          proc.returncode != 0 and 'has no manifest.json' in proc.stderr)

    # ---- 6. the replacement really does retire the old chunks -------------
    #
    # The rehearsal itself needs MariaDB and a server, so what runs here is the
    # documented sequence applied to fixture directories. The assertions that
    # the SHIPPED rehearsal performs this sequence, and checks it, are below.
    site = build_tree(base / 'site/public_html/build',
                      {rel.split('public/build/', 1)[1]: body
                       for rel, body in OLD_TREE.items() if rel.startswith('public/build/')})
    runtime = build_tree(base / 'runtime/public_html/build',
                         {rel.split('public/build/', 1)[1]: body
                          for rel, body in NEW_TREE.items() if rel.startswith('public/build/')})
    backup = base / 'backup/build'
    backup.parent.mkdir(parents=True, exist_ok=True)
    shutil.copytree(site, backup)

    shutil.rmtree(site)
    shutil.copytree(runtime, site)

    deployed = {str(p.relative_to(site)) for p in site.rglob('*') if p.is_file()}
    shipped = {str(p.relative_to(runtime)) for p in runtime.rglob('*') if p.is_file()}
    kept = {str(p.relative_to(backup)) for p in backup.rglob('*') if p.is_file()}

    check('replacing the directory leaves exactly the new runtime build',
          deployed == shipped, f'difference: {sorted(deployed ^ shipped)[:3]}')
    check('every retired chunk is gone after the replacement',
          not [rel for rel in kept - shipped if (site / rel).exists()],
          f'{len(kept - shipped)} chunks were retired; none may survive')
    check('the replacement retires the chunks the patch did not declare',
          len(kept - shipped) == len(OLD_CHUNKS),
          f'{len(kept - shipped)} retired, expected {len(OLD_CHUNKS)}')

    deployed_manifest = json.loads((site / 'manifest.json').read_text())
    unresolved = [entry['file'] for entry in deployed_manifest.values()
                  if 'file' in entry and not (site / entry['file']).is_file()]
    check('every manifest entry resolves in the deployed build',
          not unresolved, f'unresolved: {unresolved}')

    # ---- 7. rollback puts the backed-up directory back, whole -------------
    shutil.rmtree(site)
    shutil.copytree(backup, site)
    restored = {str(p.relative_to(site)) for p in site.rglob('*') if p.is_file()}
    check('rollback restores the backed-up build directory exactly',
          restored == kept, f'difference: {sorted(restored ^ kept)[:3]}')
    check('rollback leaves no chunk from the failed release behind',
          not [rel for rel in shipped - kept if (site / rel).exists()])

# The shipped rehearsals must perform that sequence, and prove it.
deploy_sh = (RELEASE / 'deploy_rehearsal.sh').read_text()
rollback_sh = (RELEASE / 'rollback_rehearsal_v7.sh').read_text()

check('the deployment rehearsal replaces the build directory, never merges it',
      'rm -rf "$SITE/public_html/build"\ncp -a "$STAGE/patch/public_html/build" '
      '"$SITE/public_html/"' in deploy_sh)
check('the deployment rehearsal asserts the deployed build equals the runtime build',
      'the deployed build directory matches the runtime build exactly' in deploy_sh)
check('the deployment rehearsal asserts no stale chunk survived',
      'stale chunk survived the replacement' in deploy_sh
      and 'every retired build chunk is gone' in deploy_sh)
check('the deployment rehearsal still proves every manifest entry resolves',
      'every manifest entry resolves to a shipped file' in deploy_sh)
check('the rollback rehearsal restores the backed-up build directory whole',
      'rm -rf "$SITE/public_html/build"\ncp -a "$BACKUP/build" '
      '"$SITE/public_html/build"' in rollback_sh
      and 'the restored build directory matches the backup exactly' in rollback_sh)

# Runtime asset parity is what proves the delivered runtime build IS the
# authenticated tree's build. The replacement policy leans on it, so it must
# stay mandatory and stay exclusion-free.
check('runtime asset parity remains a required release gate',
      'runtime-asset-parity' in RELEASE_EVIDENCE_LABELS)
check('build reproducibility remains a required release gate',
      'build-reproducibility' in RELEASE_EVIDENCE_LABELS)
check('the runner still checks asset parity against the delivered runtime',
      '--mode assets --source "$SOURCE/public/build" '
      '--runtime "$RUNTIME_DIR/public_html/build"' in runner)

with tempfile.TemporaryDirectory() as tmp:
    left = build_tree(Path(tmp) / 'source', {'assets/a.js': 'x\n'})
    right = build_tree(Path(tmp) / 'runtime', {'assets/a.js': 'x\n'})
    parity = run(str(RELEASE / 'check_parity.py'), '--mode', 'assets',
                 '--source', str(left), '--runtime', str(right))
    check('asset parity passes on identical build directories',
          parity.returncode == 0, parity.stderr.strip()[-200:])

    (right / 'assets' / 'stale.js').write_text('left over\n')
    parity = run(str(RELEASE / 'check_parity.py'), '--mode', 'assets',
                 '--source', str(left), '--runtime', str(right))
    check('asset parity fails on one extra file in the runtime build',
          parity.returncode != 0 and 'stale.js' in parity.stderr,
          'assets mode must carry no exclusions — ' + parity.stderr.strip()[-200:])

# CHANGED_FILES.md has to explain the arithmetic it prints, or a reader sees a
# hundred removals beside a seven-line DELETE_FILES.txt and cannot tell whether
# ninety-three were forgotten.
with tempfile.TemporaryDirectory() as tmp:
    fixture = Path(tmp)
    from release_gates import log_path  # noqa: E402

    (fixture / 'ledger.json').write_text(json.dumps({'entries': [
        {'label': label, 'exit_code': 0, 'final_tree_manifest_sha256': TREE,
         'raw_output_file': log_path(label), 'raw_output_sha256': '0' * 64,
         'server_configuration': 'fixture', 'started_at': '2026-08-07T00:00:00Z',
         'finished_at': '2026-08-07T00:00:01Z', 'duration_seconds': 1}
        for label in RELEASE_EVIDENCE_LABELS]}))

    def render(inventory: dict, out: str) -> str:
        (fixture / f'{out}.json').write_text(json.dumps(inventory))
        proc = run(str(RELEASE / 'generate_release_reports.py'),
                   '--ledger', str(fixture / 'ledger.json'),
                   '--inventory', str(fixture / f'{out}.json'),
                   '--tree-manifest-sha256', TREE,
                   '--baseline-commit', '9c0188f81843cfe4786b7f72ecdc2a3fae89cd82',
                   '--output-dir', str(fixture / out))
        if proc.returncode != 0:
            return f'GENERATOR FAILED: {proc.stderr.strip()[-300:]}'
        return (fixture / out / 'CHANGED_FILES.md').read_text()

    split = render({'added': ['a.php'], 'modified': ['b.php'],
                    'removed': ['docs/old.md', 'public/build/assets/x-OLD.js'],
                    'removed_generated': ['public/build/assets/x-OLD.js'],
                    'replaced_roots': ['public/build'], 'total': 2}, 'split')

    check('the changed-files report separates declared removals from replaced ones',
          '### Declared in `DELETE_FILES.txt`' in split
          and '### Retired by replacing a generated directory' in split
          and 'removed   2  (1 declared, 1 retired by directory replacement)' in split,
          split[-400:])
    check('the report still lists every replaced file by name',
          '- `public/build/assets/x-OLD.js`' in split,
          'the exemption is from an entry in DELETE_FILES.txt, not from the record')

    legacy = render({'added': ['a.php'], 'modified': ['b.php'],
                     'removed': ['docs/old.md'], 'total': 2}, 'legacy')
    check('an inventory with no replacement roots still renders',
          'GENERATOR FAILED' not in legacy
          and '_None: every removal in this release is a declared entry._' in legacy,
          legacy[-300:])

check('DELETE_FILES.txt declares the v6 browser fixture credential file',
      'tests/Browser/support/fixtures.json'
      in (ROOT / 'DELETE_FILES.txt').read_text().split())
check('no Vite chunk was ever added to DELETE_FILES.txt',
      'public/build/' not in (ROOT / 'DELETE_FILES.txt').read_text())

print()

if FAILED == 0:
    print(f'ALL {PASSED} RELEASE TOOLING REGRESSIONS PASSED')
    sys.exit(0)

print(f'{FAILED} RELEASE TOOLING REGRESSION FAILURE(S)')
sys.exit(1)
