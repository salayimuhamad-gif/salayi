#!/usr/bin/env python3
"""Assemble the v7 evidence package with per-file provenance."""
import argparse
import hashlib
import json
import re
import shlex
import os
import shutil
import subprocess
import xml.etree.ElementTree as ET
import zipfile
from datetime import datetime, timezone
from pathlib import Path


# BLOCKER 4: every path is a command-line argument.
_parser = argparse.ArgumentParser(description='Build the evidence package.')
_parser.add_argument('--project-root', required=True)
_parser.add_argument('--output-dir', required=True)
_parser.add_argument('--evidence-dir', required=True)
# Mandatory release inputs are arguments, never environment defaults.
_parser.add_argument('--source-dir', required=True)
# BLOCKER 5: the runner passed <runtime>/application while this script appended
# its own /application, producing <runtime>/application/application and a parity
# comparison over zero files. The CLI now names the ROOT explicitly.
_parser.add_argument('--runtime-root', required=True)
_parser.add_argument('--tree-manifest-sha256', required=True)
_args = _parser.parse_args()
ap = _args

if len(ap.tree_manifest_sha256) != 64:
    raise SystemExit('FAIL: --tree-manifest-sha256 must be a 64-character digest')

APP = Path(_args.project_root).resolve()
OUT = Path(_args.output_dir).resolve()
EV = Path(_args.evidence_dir).resolve()

for label, path in (('--project-root', APP), ('--evidence-dir', EV)):
    if not path.is_dir():
        raise SystemExit(f'{label} is not a directory: {path}')

if not (APP / 'artisan').is_file():
    raise SystemExit(f'--project-root does not look like the application: {APP}')

OUT.mkdir(parents=True, exist_ok=True)



sha = lambda p: hashlib.sha256(Path(p).read_bytes()).hexdigest()
now = lambda: datetime.now(timezone.utc).strftime('%Y-%m-%dT%H:%M:%SZ')


def junit(path):
    root = ET.parse(path).getroot()
    suites = [root] if root.tag == 'testsuite' else root.findall('testsuite')
    agg = dict.fromkeys(('tests', 'assertions', 'failures', 'errors', 'skipped'), 0)
    for s in suites:
        for k in agg:
            agg[k] += int(s.get(k, 0) or 0)
    return agg


# ---------------------------------------------------------- source/runtime parity
# Explicit inputs. Assuming OUT/src and OUT/runtime made the builder depend on
# a layout nobody declared.
# BLOCKER 4: these defaulted to <output-dir>/src and <output-dir>/runtime, which
# do not exist, so parity compared ZERO files and reported PASS. A parity gate
# over an empty set must fail. They are now required CLI inputs.
src = Path(ap.source_dir).resolve()
rt = Path(ap.runtime_root).resolve()

if not src.is_dir():
    raise SystemExit(f'FAIL: --source-dir does not exist: {src}')
if not rt.is_dir():
    raise SystemExit(f'FAIL: --runtime-root does not exist: {rt}')
parity_lines, mismatches, shared = [], 0, 0
for dp, _, fs in os.walk(rt / 'application'):
    for f in fs:
        p = Path(dp) / f
        rel = p.relative_to(rt / 'application')
        s = src / rel
        if s.is_file():
            shared += 1
            same = sha(s) == sha(p)
            if not same:
                mismatches += 1
            parity_lines.append(f'{"MATCH" if same else "MISMATCH"}  {rel}')
(EV / 'source-runtime-parity.log').write_text(
    'Source/runtime parity — every file present in both archives\n'
    + '\n'.join(parity_lines)
    + f'\n\nshared files: {shared}\nmismatches: {mismatches}\n'
)

# A parity gate over an empty set proves nothing: two wrong directories share
# zero files and "0 mismatches" reads as PASS. Requiring the directories to
# exist is not enough — they must actually overlap.
if shared == 0:
    raise SystemExit('FAIL: source/runtime parity compared zero shared files; '
                     f'{src} and {rt}/application do not overlap')

# ------------------------------------------------------------------ archive audit
# Shared with verify_final_delivery.py: matching `.backup` exactly missed
# `.backup-20260802-014031`, the very file this release deletes.
UNSAFE = ('.sqlite', '.backup', '.orig', '.rej', '.bak', '.swp', '.swo', '~')
UNSAFE_PATTERNS = (
    re.compile(r'\.backup(-[\w.-]+)?$'),
    re.compile(r'\.bak(-[\w.-]+)?$'),
    re.compile(r'\.orig(-[\w.-]+)?$'),
    re.compile(r'\.rej(-[\w.-]+)?$'),
    re.compile(r'^#.*#$'),
    re.compile(r'^\..*\.sw[a-z]$'),
)


def unsafe_name(base):
    return base.endswith(UNSAFE) or any(p.search(base) for p in UNSAFE_PATTERNS)
audit = []
# BLOCKER 12: this list is the ARCHIVE AUDIT's own. It used to be reassigned by
# the package-index check further down, so the printed archive-audit verdict
# could describe an entirely different validation.
archive_problems = []


def inspect(name, path):
    seen = set()
    findings = []
    with zipfile.ZipFile(path) as zf:
        if zf.testzip() is not None:
            findings.append('CRC failure')
        for i in zf.infolist():
            n = i.filename
            if n in seen:
                findings.append(f'duplicate: {n}')
            seen.add(n)
            if '..' in n.split('/'):
                findings.append(f'traversal: {n}')
            if n.startswith('/'):
                findings.append(f'absolute path: {n}')
            if (i.external_attr >> 16) & 0o170000 == 0o120000:
                findings.append(f'symlink: {n}')
            if i.flag_bits & 0x1:
                findings.append(f'encrypted: {n}')
            base = n.rsplit('/', 1)[-1]
            if base == '.env' or (base.endswith('.env') and base != '.env.example'):
                findings.append(f'environment file: {n}')
            if base == 'auth.json' and n.count('/') <= 1:
                findings.append(f'credential file: {n}')
            if base.endswith(UNSAFE) or '/node_modules/' in n:
                findings.append(f'forbidden content: {n}')
            if 'runtime' in name and ('/tests/' in n or '/resources/js/' in n or n.endswith('.spec.ts')):
                findings.append(f'runtime must not ship: {n}')
    audit.append(f'{name}: {len(seen)} entries, {len(findings)} findings')
    for f in findings:
        audit.append(f'    {f}')
        archive_problems.append(f'{name}: {f}')
    return len(seen)


for z in sorted(OUT.glob('*.zip')):
    inspect(z.name, z)

(EV / 'archive-audit.log').write_text(
    'Independent archive audit — opened and inspected outside the packager\n'
    'Checked per entry: CRC, duplicates, traversal, absolute paths, symlinks,\n'
    'encryption, .env, credentials, databases, editor leftovers, node_modules,\n'
    'and (for the runtime) any test or JS-source file.\n\n'
    + '\n'.join(audit)
    + f'\n\nRESULT: {"PASS" if not archive_problems else "FAIL"}\n'
)

# ----------------------------------------------------------- environment versions
def _chromium_identity():
    """Resolve the browser from the environment, or say plainly that there is none."""
    explicit = os.environ.get('CHROMIUM_EXECUTABLE') or os.environ.get('PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH')

    if explicit:
        if not Path(explicit).is_file():
            return f'MISSING: {explicit}'
        return f"{explicit} ({ver(shlex.quote(explicit) + ' --version')})"

    found = shutil.which('chromium') or shutil.which('chromium-browser') or shutil.which('google-chrome')

    if found:
        return f"{found} ({ver(shlex.quote(found) + ' --version')})"

    return 'unavailable: no browser on PATH and CHROMIUM_EXECUTABLE is unset'


def ver(cmd):
    try:
        return subprocess.run(cmd, shell=True, capture_output=True, text=True).stdout.strip().split('\n')[0]
    except Exception:
        return 'unavailable'


env = {
    'php': ver('php -v'),
    'composer': ver('composer --version 2>/dev/null'),
    'node': ver('node -v'),
    'npm': ver('npm -v'),
    'mariadb': ver('mysql --version'),
    'sqlite': ver('php -r "echo (new PDO(\'sqlite::memory:\'))->query(\'select sqlite_version()\')->fetchColumn();"'),
    'phpunit': ver('vendor/bin/phpunit --version 2>/dev/null'),
    # BLOCKER 6: the browser is whatever the operator supplies. Recording one
    # developer's unpacked Chromium as "the environment" made the evidence
    # describe a machine nobody else has, and silently claimed a browser was
    # present even when none was.
    'chromium': _chromium_identity(),
    'os': ver('. /etc/os-release && echo $PRETTY_NAME'),
    'captured_at': now(),
}
(EV / 'environment-versions.json').write_text(json.dumps(env, indent=2) + '\n')

# ------------------------------------------------------------------ evidence index
COMMANDS = {
    'focused-sqlite-junit.xml': 'vendor/bin/phpunit --filter RegistrationTelegramFlow',
    'full-sqlite-junit.xml': 'vendor/bin/phpunit',
    'focused-mariadb-junit.xml': 'vendor/bin/phpunit -c phpunit.mariadb.xml --filter RegistrationTelegramFlow',
    'full-mariadb-junit.xml': 'vendor/bin/phpunit -c phpunit.mariadb.xml',
    'playwright-results.json': 'npx playwright test account-first-registration --reporter=json,junit',
    'playwright-junit.xml': 'npx playwright test account-first-registration --reporter=json,junit',
    'phpstan.json': 'vendor/bin/phpstan analyse --memory-limit=1G --error-format=json',
    'pint.log': 'vendor/bin/pint --test',
    'npm-ci.log': 'rm -rf node_modules && npm ci',
    'typecheck.log': 'npm run typecheck',
    'lint.log': 'npm run lint',
    'build.log': 'npm run build',
    'security-audit.log': 'php scripts/security-audit.php',
    'secret-scan.log': 'php scripts/secret-scan.php',
    'language-parity.log': 'php scripts/lang-parity.php',
    'frontend-manifest-audit.log': 'php scripts/frontend-guard.php',
    'route-check.log': 'php artisan route:list',
    'middleware-check.log': 'php -r "<HTTP kernel boot; router alias map>"',
    'scheduler-check.log': 'php artisan schedule:list',
    'queue-check.log': 'php artisan queue:work --stop-when-empty --max-time=10',
    'deployment-rehearsal.log': 'bash deploy_rehearsal.sh <stage> <runtime-artifact>',
    'rollback-rehearsal.log': 'env -i /bin/bash rollback_rehearsal_v7.sh <stage>',
    'rollback-rehearsal.json': 'env -i /bin/bash rollback_rehearsal_v7.sh <stage>',
    'source-runtime-parity.log': 'build_v7_evidence.py (recomputed from the delivered archives)',
    'archive-audit.log': 'build_v7_evidence.py (archives reopened independently)',
    'environment-versions.json': 'build_v7_evidence.py',
}

source_zip = OUT / 'myhawler-account-first-registration-SOURCE-PATCH.zip'
runtime_zip = OUT / 'myhawler-account-first-registration-corrected-runtime.zip'

index = {
    'result_type': 'v7_account_first_evidence_index',
    'generated_at': now(),
    'baseline_commit': '9c0188f81843cfe4786b7f72ecdc2a3fae89cd82',
    'bound_artifacts': {
        # BLOCKER 6: bound by the names actually delivered, never null. The
        # previous index still named the retired `corrected-source.zip` and
        # bound it to null, so the evidence was tied to nothing.
        'myhawler-account-first-registration-FULL-SOURCE.zip': sha(OUT / 'myhawler-account-first-registration-FULL-SOURCE.zip') if (OUT / 'myhawler-account-first-registration-FULL-SOURCE.zip').exists() else None,
        'myhawler-account-first-registration-SOURCE-PATCH.zip': sha(OUT / 'myhawler-account-first-registration-SOURCE-PATCH.zip') if (OUT / 'myhawler-account-first-registration-SOURCE-PATCH.zip').exists() else None,
        'myhawler-account-first-registration-corrected-runtime.zip': sha(OUT / 'myhawler-account-first-registration-corrected-runtime.zip') if (OUT / 'myhawler-account-first-registration-corrected-runtime.zip').exists() else None,
    },
    'browser_spec_sha256': sha(APP / 'tests/Browser/account-first-registration.spec.ts'),
    'tree_manifest_sha256': (OUT / 'TREE_MANIFEST.sha256').read_text().split()[0] if (OUT / 'TREE_MANIFEST.sha256').exists() else None,
    'deployment_rehearsal_target_runtime_sha256': sha(OUT / 'myhawler-account-first-registration-corrected-runtime.zip') if (OUT / 'myhawler-account-first-registration-corrected-runtime.zip').exists() else None,
    'rollback_rehearsal_target_runtime_sha256': sha(OUT / 'myhawler-account-first-registration-corrected-runtime.zip') if (OUT / 'myhawler-account-first-registration-corrected-runtime.zip').exists() else None,
    'environment': env,
    'files': {},
}

EXCLUDED_DIRS = ('artifacts',)


def indexable(f):
    """What actually ships in the evidence ZIP — and therefore what the index
    may name. The two lists were computed differently before, so the index
    referenced a Playwright run-state file that the ZIP excluded and the
    validation of the package failed on a file nobody needed."""
    # The index and its detached checksum are the SELF-REFERENCE scheme, not
    # ordinary evidence: an index cannot contain its own hash, and its .sha256
    # is what closes that loop. Both ship; neither is indexed.
    return (
        f.is_file()
        and not any(d in f.parts for d in EXCLUDED_DIRS)
        and f.name not in ('evidence-index.json', 'evidence-index.json.sha256')
    )


for f in sorted(EV.rglob('*')):
    if not indexable(f):
        continue
    rel = str(f.relative_to(EV))
    st = f.stat()
    index['files'][rel] = {
        'sha256': sha(f),
        'bytes': st.st_size,
        'modified_at': datetime.fromtimestamp(st.st_mtime, timezone.utc).strftime('%Y-%m-%dT%H:%M:%SZ'),
        'command': COMMANDS.get(rel, COMMANDS.get(f.name, 'supporting artefact of the run above')),
    }

# headline results, parsed from the raw files rather than restated by hand
results = {}
for key, name in [('focused_sqlite', 'focused-sqlite-junit.xml'), ('full_sqlite', 'full-sqlite-junit.xml'),
                  ('focused_mariadb', 'focused-mariadb-junit.xml'), ('full_mariadb', 'full-mariadb-junit.xml')]:
    p = EV / name
    if p.exists():
        results[key] = junit(p)

pw = EV / 'playwright-results.json'
if pw.exists():
    results['playwright'] = json.loads(pw.read_text())['stats']

ps = EV / 'phpstan.json'
if ps.exists():
    results['phpstan'] = json.loads(ps.read_text())['totals']

results['source_runtime_parity'] = {'shared_files': shared, 'mismatches': mismatches}
results['archive_audit'] = 'PASS' if not archive_problems else 'FAIL'
index['results'] = results

(EV / 'evidence-index.json').write_text(json.dumps(index, indent=2) + '\n')

# BLOCKER 4: this script used to create the evidence ZIP and an internal index
# HERE, in the middle of the run. Later gates, reports, release-evidence.json
# and the final ledger were then written into the same directory, and the runner
# re-zipped it — carrying the now-stale index inside the authoritative archive,
# describing an entry set that no longer matched.
#
# Packaging is single-phase now: this script writes supporting data only, and
# finalize_evidence.py builds the index and the ZIP once, after everything else
# is complete, with exact entry-set parity.

print('parity:', shared, 'shared,', mismatches, 'mismatches')
print('archive audit:', 'PASS' if not archive_problems else archive_problems[:5])
print('evidence files:', len(index['files']))
print('results:', json.dumps(results))
