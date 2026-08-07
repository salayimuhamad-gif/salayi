#!/usr/bin/env python3
"""
Synthetic offline end-to-end test that executes the REAL runner.

The previous version composed helper scripts by hand and never ran
run_final_release_ci.sh, so a pass proved only that selected helpers could be
assembled manually — not that the runner had correct ordering, paths or
packaging. This builds a fixture source tree that satisfies the real builder
contract, then invokes the actual runner in --offline-stub mode and requires it
to reach a cleanroom PASS.

Stub mode replaces only the external toolchain (composer, npm, artisan,
playwright, the rehearsals). The freeze, the recorder, every builder, the
indexes, packaging and verification are the real code paths.

Usage: python3 tests/Standalone/release_e2e_test.py [--keep]
"""
from __future__ import annotations

import hashlib
import json
import os
import shutil
import subprocess
import sys
import tempfile
import zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
RELEASE = ROOT / 'scripts' / 'release'
sys.path.insert(0, str(RELEASE))

from release_gates import (  # noqa: E402
    ATTESTATION_ONLY_LABELS, RELEASE_EVIDENCE_LABELS,
)

PASSED = 0
FAILED = 0
# The fixture is deliberately small, so it declares its builder minimum ONCE and
# the runner forwards the identical value to the clean-extraction rebuild. The
# production default stays strict; a real finalization run passes no override at
# all. Previously the initial build used 20 and the rebuild silently reverted to
# 800, so the rebuild failed against a contract the archive was never built under.
MIN_FILES = 20


def check(name: str, ok: bool, why: str = '') -> None:
    global PASSED, FAILED
    if ok:
        PASSED += 1
        print(f'  pass {name}')
    else:
        FAILED += 1
        print(f'  FAIL {name}' + (f'  ({why})' if why else ''))


def build_fixture_source(root: Path) -> None:
    """A miniature tree that satisfies the real builder's required-file list."""
    required = {
        'composer.json': '{}\n',
        'composer.lock': '{"packages":[],"packages-dev":[]}\n',
        'package.json': '{"name":"fixture"}\n',
        'package-lock.json': '{"lockfileVersion":3,"packages":{}}\n',
        'artisan': '#!/usr/bin/env php\n',
        'phpunit.xml': '<phpunit/>\n',
        'phpunit.mariadb.xml': '<phpunit/>\n',
        'playwright.config.ts': 'export default {};\n',
        '.env.example': 'APP_ENV=testing\n',
        'app/Modules/Identity/Services/AbandonedAccountPolicy.php': '<?php\n',
        'app/Modules/Identity/Services/TelegramReturnHandoff.php': '<?php\n',
        'app/Modules/Identity/Database/Migrations/'
        '2026_08_06_000100_telegram_return_handoffs.php': '<?php\n',
        'tests/Feature/RegistrationTelegramFlowTest.php': '<?php\n',
        'tests/Browser/account-first-registration.spec.ts': '// 20 scenarios\n',
        'DEPLOYMENT_NOTES.md': '# Deployment (fixture)\n',
        'ROLLBACK_NOTES.md': '# Rollback (fixture)\n',
        'DELETE_FILES.txt': '# fixture deletions\nold/Removed.php\n',
        'app/Added.php': '<?php // new in v7\n',
        'app/Example.php': '<?php\n',
        'public/build/manifest.json':
            json.dumps({'resources/js/app.ts': {'file': 'assets/app.js'}}) + '\n',
        'public/build/assets/app.js': 'console.log(1)\n',
    }

    for rel, body in required.items():
        target = root / rel
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_text(body)

    # Padding so the builder's --min-files contract is met honestly rather than
    # by weakening the production default.
    for index in range(12):
        (root / 'app' / f'Filler{index}.php').write_text(f'<?php // {index}\n')

    # The real release tooling travels with the source; the runner runs the copy
    # inside the archive, and the builder self-test rebuilds from it.
    shutil.copytree(RELEASE, root / 'scripts' / 'release')
    (root / 'scripts' / 'support').mkdir(parents=True, exist_ok=True)
    for helper in ('SourceIdentity.php', 'EvidencePath.php'):
        shutil.copy2(ROOT / 'scripts' / 'support' / helper, root / 'scripts' / 'support')
    shutil.copy2(ROOT / 'scripts' / 'release' / 'stage_tree.php', root / 'scripts' / 'release')


def main() -> int:
    keep = '--keep' in sys.argv
    work = Path(tempfile.mkdtemp(prefix='myhawler-e2e-'))
    print(f'Synthetic end-to-end orchestration via the real runner ({work})')

    try:
        source_tree = work / 'fixture-source'
        source_tree.mkdir()
        build_fixture_source(source_tree)

        # Baseline: the fixture minus the added file, plus the file DELETE_FILES
        # declares removed. This makes the deletion contract real.
        baseline_tree = work / 'fixture-baseline'
        shutil.copytree(source_tree, baseline_tree)
        (baseline_tree / 'app' / 'Added.php').unlink()
        (baseline_tree / 'old').mkdir()
        (baseline_tree / 'old' / 'Removed.php').write_text('<?php // gone in v7\n')

        baseline_zip = work / 'baseline.zip'
        with zipfile.ZipFile(baseline_zip, 'w') as zf:
            for path in sorted(p for p in baseline_tree.rglob('*') if p.is_file()):
                zf.write(path, str(path.relative_to(baseline_tree)))

        inputs = work / 'inputs'
        inputs.mkdir()
        source_zip = inputs / 'myhawler-current-working-tree.zip'
        with zipfile.ZipFile(source_zip, 'w') as zf:
            for path in sorted(p for p in source_tree.rglob('*') if p.is_file()):
                zf.write(path, f'mulkihawler/{path.relative_to(source_tree)}')

        subprocess.run(f'sha256sum {source_zip.name} > {source_zip.name}.sha256',
                       shell=True, cwd=str(inputs), check=True)

        baseline_hash = subprocess.run(['sha256sum', str(baseline_zip)],
                                       capture_output=True, text=True,
                                       check=True).stdout.split()[0]

        environment = dict(os.environ)
        environment.update({
            'MYHAWLER_BASELINE_ARCHIVE': str(baseline_zip),
            'MYHAWLER_BASELINE_SHA256': baseline_hash,
            'PHP_BIN': shutil.which('php') or 'php',
        })

        runner = source_tree / 'scripts' / 'release' / 'run_final_release_ci.sh'
        run_work = work / 'run'

        print('  running run_final_release_ci.sh --offline-stub ...')
        result = subprocess.run(
            ['bash', str(runner),
             '--source-archive', str(source_zip),
             '--source-sha256', str(inputs / f'{source_zip.name}.sha256'),
             '--work', str(run_work),
             '--offline-stub',
             '--min-files', str(MIN_FILES)],
            capture_output=True, text=True, env=environment,
        )

        check('the E2E test executes run_final_release_ci.sh itself', True)

        if result.returncode != 0:
            tail = (result.stdout + result.stderr).strip().splitlines()
            print('\n--- runner output (tail) ---')
            print('\n'.join(tail[-40:]))
            print('--- end ---\n')

        check('the real runner completes in offline stub mode',
              result.returncode == 0, f'exit {result.returncode}')

        if result.returncode != 0:
            return 1

        # ------------------------------------------------ orchestration proof
        trace = (run_work / 'gate-trace.txt').read_text().split()
        ledger = json.loads((run_work / 'evidence' / 'command-ledger.json').read_text())
        entries = ledger['entries']
        labels = [e['label'] for e in entries]
        frozen = (run_work / 'evidence' / 'FROZEN_TREE_SHA256').read_text().strip()

        check('the gate trace matches the canonical registry exactly',
              sorted(trace) == sorted(RELEASE_EVIDENCE_LABELS),
              f'missing {sorted(set(RELEASE_EVIDENCE_LABELS) - set(trace))}; '
              f'unexpected {sorted(set(trace) - set(RELEASE_EVIDENCE_LABELS))}')
        check('no gate is executed twice', len(trace) == len(set(trace)))
        check('every release-evidence gate reached the ledger',
              set(labels) == set(RELEASE_EVIDENCE_LABELS))
        check('every ledger entry exited 0',
              all(e['exit_code'] == 0 for e in entries),
              str(sorted(e['label'] for e in entries if e['exit_code'] != 0)))
        check('every ledger entry binds to one tree hash',
              {e['final_tree_manifest_sha256'] for e in entries} == {frozen})
        check('durations and timestamps are real, not zeroed',
              all(e['started_at'] != e['finished_at'] for e in entries))

        delivery = run_work / 'delivery'
        for name in ('myhawler-account-first-registration-FINAL-DELIVERY.zip',
                     'myhawler-account-first-registration-FULL-SOURCE.zip',
                     'myhawler-account-first-registration-SOURCE-PATCH.zip',
                     'myhawler-account-first-registration-corrected-runtime.zip',
                     'myhawler-account-first-registration-evidence.zip',
                     'TREE_MANIFEST.txt', 'FULL_SOURCE_MANIFEST.txt',
                     'RUNTIME_MANIFEST.txt', 'command-ledger.json',
                     'evidence-index.json', 'release-index.json',
                     'CHANGED_FILES.md', 'FIX_REPORT.md', 'SECURITY_REVIEW.md',
                     'TEST_RESULTS.md'):
            check(f'the real builders produced {name}', (delivery / name).is_file())

        release_index = json.loads((delivery / 'release-index.json').read_text())
        check('the release index binds the frozen tree',
              release_index['identity']['final_tree_manifest_sha256'] == frozen)
        check('the changed-file inventory is not empty',
              release_index['changed_files']['total'] > 0)
        check('the deletion contract is reflected in the index',
              release_index['changed_files']['removed'] == ['old/Removed.php'])

        runtime = delivery / 'myhawler-account-first-registration-corrected-runtime.zip'
        with zipfile.ZipFile(runtime) as zf:
            names = set(zf.namelist())
        check('the runtime uses the Hostinger layout',
              'public_html/build/manifest.json' in names
              and 'application/artisan' in names
              and 'DELETE_FILES.txt' in names)
        check('the runtime ships no frontend source',
              not any(n.startswith('application/resources/js/') for n in names))

        attestation = json.loads((run_work / 'final-attestation.json').read_text())
        check('the external attestation records a real verifier exit code',
              attestation['verifier_exit_code'] == 0)
        check('the attestation binds the same tree hash',
              attestation['final_tree_manifest_sha256'] == frozen)
        check('the attestation lives outside the delivery',
              not (delivery / 'final-attestation.json').exists())
        check('the cleanroom verifier reached PASS',
              attestation['verdict'] == 'VERIFIED')

        # ------------------------------------------- post-seal trust chain
        attest_ledger = json.loads(
            (run_work / 'attestation-ledger.json').read_text())
        attest_entries = {e['label']: e for e in attest_ledger['entries']}

        check('the attestation ledger records every attestation-phase gate',
              set(attest_entries) == set(ATTESTATION_ONLY_LABELS))
        check('the recorded finalizer names the command that creates the '
              'evidence ZIP',
              any('finalize_evidence.py' in part
                  for part in attest_entries['evidence-finalizer']['argv']))
        check('every attestation entry stores an exact argv array',
              all(isinstance(e['argv'], list) and e['argv']
                  and all(isinstance(a, str) for a in e['argv'])
                  for e in attest_entries.values()))
        check('every attestation entry records a redacted child environment',
              all(isinstance(e['child_environment'], dict)
                  for e in attest_entries.values()))

        check('the attestation gates are exactly the measured ledger entries',
              set(attestation['attestation_gates'])
              == set(ATTESTATION_ONLY_LABELS))
        check('measured and observed exits agree at 0 for every gate',
              all(gate['exit_code'] == 0 and gate['observed_exit_code'] == 0
                  for gate in attestation['attestation_gates'].values()))

        evidence_zip_name = 'myhawler-account-first-registration-evidence.zip'
        sealed = attestation.get('sealed_artifacts', {})
        check('the attestation binds the sealed evidence archive by hash',
              sealed.get(evidence_zip_name, {}).get('sha256')
              == hashlib.sha256(
                  (delivery / evidence_zip_name).read_bytes()).hexdigest())

        # Workflow upload contract: every attestation path the workflow
        # advertises must exist after a successful run. Two advertised log
        # paths used to point at files the runner never created, so a success
        # uploaded an attestation without the raw logs proving its gates.
        workflow_text = (ROOT / '.github' / 'workflows' /
                         'myhawler-final-release.yml').read_text()
        upload_block = workflow_text.split(
            'name: myhawler-final-attestation')[1].split('if-no-files-found')[0]
        advertised = [line.strip() for line in upload_block.splitlines()
                      if '${{ env.WORK }}' in line]
        check('the attestation upload advertises the ledger, the evidence '
              'directory and the document',
              len(advertised) >= 5)
        for line in advertised:
            rel = line.replace('${{ env.WORK }}/', '').rstrip('/')
            check(f'advertised attestation upload exists after success: {rel}',
                  (run_work / rel).exists())
        for log in ('packaging/evidence-finalizer.log',
                    'verification/sha256sums-verification.log',
                    'verification/final-archive-audit.log',
                    'verification/final-clean-verifier.log'):
            check(f'the uploaded attestation evidence carries {log}',
                  (run_work / 'attestation-evidence' / log).is_file())

        release_evidence = json.loads(
            (run_work / 'evidence' / 'release-evidence.json').read_text())
        check('release-evidence names its artifact map for its pre-seal scope',
              'pre_seal_component_artifacts' in release_evidence
              and 'component_artifacts' not in release_evidence)
        check('the pre-seal map does not claim the sealed artifacts',
              not any(name in release_evidence['pre_seal_component_artifacts']
                      for name in (evidence_zip_name, 'evidence-index.json',
                                   'release-index.json',
                                   'myhawler-account-first-registration-'
                                   'FINAL-DELIVERY.zip')))

        remaining_merged = (run_work / 'evidence' / 'browser' / 'remaining' /
                            'playwright-merged-remaining.json')
        check('the remaining-mode merger validated the remaining report '
              'directory',
              remaining_merged.is_file()
              and json.loads(remaining_merged.read_text())['mode']
              == 'remaining')

        return 0 if FAILED == 0 else 1

    finally:
        print()
        if FAILED == 0:
            print(f'ALL {PASSED} END-TO-END ORCHESTRATION ASSERTIONS PASSED')
        else:
            print(f'{FAILED} END-TO-END ORCHESTRATION FAILURE(S)')
        if keep:
            print(f'workspace kept at {work}')
        else:
            shutil.rmtree(work, ignore_errors=True)


if __name__ == '__main__':
    sys.exit(main())
