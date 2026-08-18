#!/usr/bin/env python3
"""
Build the FULL-SOURCE archive: the complete final project source tree.

Two artifacts ship, named for what they are:

    FULL-SOURCE   the complete tree, standalone
    SOURCE-PATCH  the overlay for the sealed baseline

## What changed, and why

The previous edition took its file list from `git ls-files` plus `git status`.
A delivered FULL-SOURCE carries no `.git`, so running this builder from the very
archive it produces yielded an empty file list — and it still exited 0, printing
`FULL-SOURCE: 0 project files` and `standalone check: MISSING [...]` beside a
two-entry ZIP. A build that produces nothing must fail, loudly.

It also loaded the release helpers from a hardcoded `/home/claude/work`, so the
archive it assembled depended on one person's laptop.

Both are fixed here:

  * the file list comes from a deterministic tree walk under an explicit
    exclusion policy, or from a supplied canonical manifest;
  * every path is derived from --project-root and --output-dir;
  * helpers load from <project-root>/scripts/release;
  * the build fails nonzero on a missing required file, on a file count below
    --min-files, and on any standalone-check failure;
  * --self-test rebuilds from a clean extraction of the archive just built and
    proves the result is complete.

Usage:
    build_full_source.py --project-root DIR --output-dir DIR
                         [--manifest FILE] [--min-files N] [--self-test]
"""
import argparse
import datetime
import hashlib
import os
import re
import shutil
import stat
import subprocess
import sys
import tempfile
import zipfile
from pathlib import Path


def zip_date_time(path: Path | None = None):
    """
    The entry timestamp: SOURCE_DATE_EPOCH when the environment provides it
    (the release cycle derives it from the verified input archive, so two
    runs from the same input stamp identical times), otherwise the input
    file's own mtime — the historical behavior for standalone invocations.
    zipfile refuses years before 1980, so an epoch below that clamps up.
    """
    epoch = os.environ.get('SOURCE_DATE_EPOCH')
    if epoch and epoch.isdigit():
        moment = datetime.datetime.fromtimestamp(int(epoch), datetime.timezone.utc)
    elif path is not None:
        moment = datetime.datetime.fromtimestamp(path.stat().st_mtime, datetime.timezone.utc)
    else:
        moment = datetime.datetime(1980, 1, 1, tzinfo=datetime.timezone.utc)
    if moment.year < 1980:
        moment = datetime.datetime(1980, 1, 1, tzinfo=datetime.timezone.utc)
    return (moment.year, moment.month, moment.day,
            moment.hour, moment.minute, moment.second)


def zip_add_file(zf: zipfile.ZipFile, path: Path, arcname: str) -> None:
    """zf.write with a pinned timestamp; permissions still come from disk."""
    info = zipfile.ZipInfo(arcname, date_time=zip_date_time(path))
    info.external_attr = (stat.S_IMODE(path.stat().st_mode) | stat.S_IFREG) << 16
    info.compress_type = zipfile.ZIP_DEFLATED
    zf.writestr(info, path.read_bytes())


def zip_add_text(zf: zipfile.ZipFile, arcname: str, text: str) -> None:
    """writestr with a pinned timestamp instead of the wall clock."""
    info = zipfile.ZipInfo(arcname, date_time=zip_date_time())
    info.external_attr = (0o644 | stat.S_IFREG) << 16
    info.compress_type = zipfile.ZIP_DEFLATED
    zf.writestr(info, text)

NAME = 'myhawler-account-first-registration-FULL-SOURCE'
DEFAULT_MIN_FILES = 800

# --------------------------------------------------------------- exclusions
# The policy is explicit and lives here, because the archive is only
# reproducible if the next person can apply the same rules.
EXCLUDE_PREFIXES = (
    '.git/', 'vendor/', 'node_modules/',
    'storage/framework/cache/', 'storage/framework/sessions/',
    'storage/framework/views/', 'storage/framework/testing/',
    'storage/logs/', 'storage/app/public/', 'storage/app/private/',
    'storage/browser-acceptance/', 'storage/installer/',
    'public/build/',            # generated; ships in the runtime archive
    'bootstrap/cache/',
    '.phpunit.cache/', 'dist/',
    'docs/release/',            # generated: the reports are injected below
)

# Interpreter droppings, not source. The release cycle itself imports
# release_gates.py and friends FROM the frozen tree (the spec-closure gate,
# the Playwright merges), and CPython writes __pycache__/*.pyc beside them —
# bytecode that embeds the absolute build path, so two runs from identical
# inputs swept different bytes into the archive, its SHA256SUMS.txt and
# FULL_SOURCE_MANIFEST. The staging exclusion policy already keeps pycache
# out of the tree identity; the sweep here must apply the same rule.
EXCLUDE_DIR_NAMES = ('__pycache__',)

# SHA256SUMS.txt is REGENERATED below from the tree actually being shipped.
# A tracked stale copy wrote a second archive entry with the same name and
# extraction picked the wrong one, so it is excluded from the input list.
# RELEASE_README.md and DELETE_FILES.txt are GENERATED into the archive. If the
# walk also collects them from an extracted tree, the rebuild writes the same
# entry twice — which the clean-extraction self-test caught on its first run.
# TREE_MANIFEST.* describe the WIDER complete-tree scope and are delivered
# externally. Shipping the detached hash without its target left a dangling
# reference inside the archive.
EXCLUDE_NAMES = ('.env', 'auth.json', '.DS_Store', 'SHA256SUMS.txt',
                 'TREE_MANIFEST.txt', 'TREE_MANIFEST.sha256',
                 'RELEASE_README.md', 'DELETE_FILES.txt')
EXCLUDE_SUFFIXES = ('.sqlite', '.log', '.orig', '.rej', '.bak', '.swp', '.swo', '~')

# `.backup-20260802-014031` is a backup too. Matching only the exact suffix
# `.backup` let a timestamped one through into a delivered archive.
EXCLUDE_PATTERNS = (
    re.compile(r'\.backup(-.*)?$'),
    re.compile(r'\.bak(-.*)?$'),
    re.compile(r'\.orig(-.*)?$'),
    re.compile(r'\.rej(-.*)?$'),
    re.compile(r'^#.*#$'),
    re.compile(r'^\..*\.sw[a-z]$'),
)

REQUIRED = (
    'composer.json', 'composer.lock', 'package.json', 'package-lock.json',
    'artisan', 'phpunit.xml', 'phpunit.mariadb.xml', 'playwright.config.ts',
    '.env.example',
    'app/Modules/Identity/Services/AbandonedAccountPolicy.php',
    'app/Modules/Identity/Services/TelegramReturnHandoff.php',
    'app/Modules/Identity/Services/TelegramPasswordRecovery.php',
    'app/Modules/Identity/Http/Middleware/TouchLastSeen.php',
    'app/Modules/Identity/Database/Migrations/'
    '2026_08_06_000100_telegram_return_handoffs.php',
    'app/Modules/Identity/Database/Migrations/'
    '2026_08_09_000100_telegram_verification_tokens.php',
    'app/Modules/Identity/Database/Migrations/'
    '2026_08_09_000200_password_recovery_challenges.php',
    'app/Modules/Identity/Database/Migrations/'
    '2026_08_09_000200_profile_optional_details.php',
    'app/Modules/Identity/Database/Migrations/'
    '2026_08_09_000300_add_last_seen_to_users.php',
    'tests/Feature/RegistrationTelegramFlowTest.php',
    'tests/Browser/account-first-registration.spec.ts',
    'scripts/support/SourceIdentity.php',
)

HELPERS = ('deploy_rehearsal.sh', 'rollback_rehearsal_v7.sh',
           'build_v7_evidence.py', 'build_full_source.py',
           'verify_final_delivery.py')

RELEASE_DOCS = ('CHANGED_FILES.md', 'FIX_REPORT.md', 'SECURITY_REVIEW.md',
                'TEST_RESULTS.md', 'DEPLOYMENT_NOTES.md', 'ROLLBACK_NOTES.md')


def sha256(path) -> str:
    h = hashlib.sha256()
    with open(path, 'rb') as fh:
        for chunk in iter(lambda: fh.read(1 << 20), b''):
            h.update(chunk)
    return h.hexdigest()


def excluded(rel: str) -> bool:
    name = rel.rsplit('/', 1)[-1]
    if name in EXCLUDE_NAMES or name.endswith(EXCLUDE_SUFFIXES):
        return True
    if any(pat.search(name) for pat in EXCLUDE_PATTERNS):
        return True
    if name.startswith('.env') and name != '.env.example':
        return True
    if name.endswith('.pyc') or any(
        part in EXCLUDE_DIR_NAMES for part in rel.rstrip('/').split('/')
    ):
        return True
    return rel.startswith(EXCLUDE_PREFIXES)


def walk_tree(app: Path) -> list:
    """Deterministic file list. No git, no network, no machine-specific state."""
    found = []
    for dirpath, dirnames, filenames in os.walk(app):
        rel_dir = os.path.relpath(dirpath, app)
        rel_dir = '' if rel_dir == '.' else rel_dir.replace(os.sep, '/')

        # Prune excluded directories in place so os.walk does not descend.
        dirnames[:] = sorted(
            d for d in dirnames
            if not excluded(f'{rel_dir}/{d}/' if rel_dir else f'{d}/')
        )

        for filename in sorted(filenames):
            rel = f'{rel_dir}/{filename}' if rel_dir else filename
            path = app / rel
            if path.is_symlink():
                raise SystemExit(f'FAIL: symlink in the source tree: {rel}')
            if not excluded(rel) and path.is_file():
                found.append(rel)
    return sorted(set(found))


def read_manifest(manifest: Path, app: Path, allow_symlinks: bool = False) -> list:
    """
    Consume and validate a supplied canonical manifest.

    Validation is deliberately as strict as the provenance resolver. The earlier
    version deduplicated silently through sorted(set(...)), never compared the
    manifest against what is actually on disk, and accepted any path shape — so
    a manifest could omit files, list one twice, or name `../outside` and still
    be treated as canonical.
    """
    listed, problems = {}, []

    for number, line in enumerate(manifest.read_text().splitlines(), 1):
        if not line.strip():
            continue

        parts = line.split('  ', 1)

        if len(parts) != 2:
            problems.append(f'line {number}: unreadable entry')
            continue

        digest, rel = parts[0], parts[1].strip()

        if len(digest) != 64 or any(c not in '0123456789abcdef' for c in digest):
            problems.append(f'line {number}: digest is not a 64-character lowercase hex value')
            continue

        why = unsafe_manifest_path(rel)

        if why:
            problems.append(f'line {number}: refused path ({why}): {rel!r}')
            continue

        if rel in listed:
            problems.append(f'{rel}: duplicate manifest entry')
            continue

        listed[rel] = digest
        target = app / rel

        if target.is_symlink():
            if not allow_symlinks:
                problems.append(f'{rel}: symlink')
            continue

        if not target.is_file():
            problems.append(f'{rel}: missing')
            continue

        real = target.resolve()

        if not str(real).startswith(str(app.resolve()) + os.sep):
            problems.append(f'{rel}: resolves outside the project root')
            continue

        if sha256(target) != digest:
            problems.append(f'{rel}: checksum mismatch')

    # Both directions. A manifest that omits a file on disk is not canonical.
    on_disk = set(walk_tree(app))

    for extra in sorted(on_disk - set(listed)):
        problems.append(f'{extra}: present in the tree but absent from the manifest')

    if problems:
        for problem in problems[:12]:
            print(f'  {problem}', file=sys.stderr)
        raise SystemExit(f'FAIL: the supplied manifest does not describe this tree '
                         f'({len(problems)} problem(s))')

    return sorted(listed)


def unsafe_manifest_path(rel: str):
    """Reason this path is unsafe, or None. Mirrors SourceIdentity."""
    if not rel:
        return 'empty path'
    if '\0' in rel:
        return 'contains a NUL byte'
    if rel.startswith('/') or re.match(r'^[A-Za-z]:[\\/]', rel):
        return 'absolute path'
    if '\\' in rel:
        return 'backslash separator'
    for segment in rel.split('/'):
        if segment == '..':
            return 'parent-directory traversal'
        if segment == '':
            return 'empty path segment'
    return None


def build(args) -> int:
    app = Path(args.project_root).resolve()
    out = Path(args.output_dir).resolve()

    if not (app / 'artisan').is_file():
        raise SystemExit(f'FAIL: --project-root does not look like the application: {app}')

    out.mkdir(parents=True, exist_ok=True)

    files = (read_manifest(Path(args.manifest).resolve(), app, args.allow_symlinks)
             if args.manifest else walk_tree(app))

    # A build that produced nothing is a failed build, not a small one.
    if len(files) < args.min_files:
        raise SystemExit(
            f'FAIL: {len(files)} project file(s) collected, below the approved '
            f'minimum of {args.min_files}. Refusing to write an incomplete archive.'
        )

    missing_required = [r for r in REQUIRED if r not in files]
    if missing_required:
        for r in missing_required:
            print(f'  missing required file: {r}', file=sys.stderr)
        raise SystemExit(f'FAIL: {len(missing_required)} required project file(s) absent')

    zip_path = out / f'{NAME}.zip'
    if zip_path.exists():
        zip_path.unlink()

    sums_text = ''.join(f'{sha256(app / f)}  {f}\n' for f in files)

    helper_dir = app / 'scripts' / 'release'
    shipped_helpers = []

    # Every entry goes in with a pinned timestamp (zip_date_time), because the
    # ZIP header stores mtimes and writestr would stamp the wall clock: two
    # otherwise-identical builds differed only in header metadata. Ordering
    # was already deterministic (the sorted walk).
    with zipfile.ZipFile(zip_path, 'w', zipfile.ZIP_DEFLATED) as zf:
        for f in files:
            zip_add_file(zf, app / f, f'mulkihawler/{f}')

        zip_add_text(zf, 'mulkihawler/SHA256SUMS.txt', sums_text)

        deletions = out / 'DELETE_FILES.txt'
        if deletions.is_file():
            zip_add_file(zf, deletions, 'mulkihawler/DELETE_FILES.txt')

        for doc in RELEASE_DOCS:
            if (out / doc).is_file():
                zip_add_file(zf, out / doc, f'mulkihawler/docs/release/{doc}')

        # Helpers come from the project, never from a developer's home
        # directory. Anything already collected by the walk is skipped so the
        # archive cannot contain the same path twice.
        for helper in HELPERS:
            rel = f'scripts/release/{helper}'
            if rel in files:
                shipped_helpers.append(helper)
                continue
            candidate = helper_dir / helper
            if candidate.is_file():
                zip_add_file(zf, candidate, f'mulkihawler/{rel}')
                shipped_helpers.append(helper)

        zip_add_text(zf, 'mulkihawler/RELEASE_README.md', f"""# MyHawler v7 — complete source tree

This archive is the COMPLETE final project source, standalone. It is not an
overlay: everything needed to develop, test and rebuild is here.

Baseline commit this work started from: `9c0188f81843cfe4786b7f72ecdc2a3fae89cd82`

That commit is the ANCESTOR. It is not the identity of this tree, which carries
uncommitted work on top of it.

The identity of THIS archive is `SHA256SUMS.txt`, listing every project file it
contains. The same bytes are delivered externally as `FULL_SOURCE_MANIFEST.txt`
with a detached `FULL_SOURCE_MANIFEST.sha256`.

The complete-tree manifest (`TREE_MANIFEST.txt`) covers a WIDER scope than this
archive — it includes `public/build`, which ships in the runtime rather than
here — so it is delivered externally and is deliberately absent from this ZIP.
Neither it nor its detached hash is included, because a detached hash whose
target is missing is worse than no hash at all.

## What is deliberately absent

```text
vendor/            restore with: composer install
node_modules/      restore with: npm ci
public/build/      rebuild with: npm run build (the built assets ship in the
                   runtime archive, which is what production deploys)
.env               copy .env.example and fill it in; no key, token or
                   credential is present anywhere in this archive
storage caches, logs, sessions, uploads, databases
```

## Getting to a green suite

```bash
composer install
npm ci
php artisan migrate      # includes the five Identity migrations new since the baseline
vendor/bin/phpunit
vendor/bin/phpunit -c phpunit.mariadb.xml            # the same suite, real MariaDB
vendor/bin/phpstan analyse --memory-limit=1G
vendor/bin/pint --test
npm run typecheck && npm run lint && npm run build
npx playwright test
```

Run the gates with no `.env` on disk — `scripts/secret-scan.php` treats one as a
committed secret. Supply `APP_KEY` and any `DB_*` values through the
environment instead. Test totals are volatile numbers and live in the external
evidence package, not here.

This release adds FIVE forward-only migrations above the baseline: the
single-use Telegram return handoff, the permanent verification tokens, the
fifteen-minute password-recovery challenges, two optional profile columns and
the presence column. All are required; DEPLOYMENT_NOTES.md names each file and
what breaks without it.

The rehearsal harness and release tooling are in `scripts/release/`. Release
reports are delivered externally with the evidence package, not inside this
archive.

Files in this archive: {len(files)} project files.
""")

    (out / f'{NAME}.zip.sha256').write_text(f'{sha256(zip_path)}  {NAME}.zip\n')

    # ------------------------------------------------------- tree identity
    # Deterministic: sorted POSIX paths, each with its content hash, hashed as
    # one document. The detached hash is written beside it and must be carried
    # OUTSIDE the archive for it to prove anything.
    # This manifest describes the FULL-SOURCE scope only — narrower than the
    # complete tree, because public/build ships in the runtime. Writing it as
    # TREE_MANIFEST.txt overwrote the complete-tree identity with a different
    # file set under the same name, recreating the exact scope conflation this
    # release exists to remove.
    manifest_text = sums_text
    full_source_sha = hashlib.sha256(manifest_text.encode()).hexdigest()
    (out / 'FULL_SOURCE_MANIFEST.txt').write_text(manifest_text)
    (out / 'FULL_SOURCE_MANIFEST.sha256').write_text(
        f'{full_source_sha}  FULL_SOURCE_MANIFEST.txt\n'
    )

    print(f'FULL-SOURCE: {len(files)} project files')
    print('sha256:', sha256(zip_path))
    print('bytes:', zip_path.stat().st_size)
    print('full_source_manifest_sha256:', full_source_sha)
    print('helpers shipped:', ', '.join(shipped_helpers) or 'NONE')

    # ------------------------------------------------------ standalone check
    with zipfile.ZipFile(zip_path) as zf:
        names = set(zf.namelist())
        missing = [r for r in REQUIRED if f'mulkihawler/{r}' not in names]
        if 'mulkihawler/SHA256SUMS.txt' not in names:
            missing.append('SHA256SUMS.txt')
        duplicates = len(zf.namelist()) != len(names)

    if missing or duplicates:
        for r in missing:
            print(f'  standalone check missing: {r}', file=sys.stderr)
        if duplicates:
            print('  standalone check: duplicate entries in the archive', file=sys.stderr)
        raise SystemExit('FAIL: the archive is not standalone')

    print('standalone check: PASS')

    if args.self_test:
        clean_extraction_self_test(zip_path, args, manifest_text)

    return 0


def clean_extraction_self_test(zip_path: Path, args, original_manifest: str) -> None:
    """
    Rebuild from a clean extraction of the archive just built.

    This is the case that used to fail silently: no `.git`, a fresh output
    directory, nothing from the developer's machine.

    The comparison is EXACT. An entry-count tolerance would accept a rebuild
    that quietly dropped or added files, which is the class of defect the test
    exists to detect — so the rebuilt manifest must equal the original byte for
    byte.
    """
    print('\n--- clean-extraction self-test ---')
    workspace = Path(tempfile.mkdtemp(prefix='fullsrc-selftest-'))
    try:
        with zipfile.ZipFile(zip_path) as zf:
            zf.extractall(workspace / 'extracted')

        extracted = workspace / 'extracted' / 'mulkihawler'
        if (extracted / '.git').exists():
            raise SystemExit('FAIL: the extraction contains .git; the test proves nothing')

        builder = extracted / 'scripts' / 'release' / 'build_full_source.py'
        if not builder.is_file():
            raise SystemExit('FAIL: the archive does not carry its own builder')

        # DELETE_FILES.txt is injected from the OUTPUT directory, so the
        # rebuild needs the same input or the comparison is not like for like.
        rebuild_out = workspace / 'out'
        rebuild_out.mkdir(parents=True, exist_ok=True)
        deletions = Path(args.output_dir).resolve() / 'DELETE_FILES.txt'
        if deletions.is_file():
            shutil.copy2(deletions, rebuild_out / 'DELETE_FILES.txt')

        result = subprocess.run(
            [sys.executable, str(builder),
             '--project-root', str(extracted),
             '--output-dir', str(rebuild_out),
             '--min-files', str(args.min_files)],
            capture_output=True, text=True,
        )
        print(result.stdout.strip())
        if result.returncode != 0:
            print(result.stderr.strip(), file=sys.stderr)
            raise SystemExit(f'FAIL: rebuilding from a clean extraction exited {result.returncode}')

        rebuilt_manifest_path = rebuild_out / 'FULL_SOURCE_MANIFEST.txt'
        if not rebuilt_manifest_path.is_file():
            raise SystemExit('FAIL: the rebuild wrote no FULL_SOURCE_MANIFEST.txt')

        rebuilt_manifest = rebuilt_manifest_path.read_text()

        if rebuilt_manifest != original_manifest:
            original_lines = {l.split('  ', 1)[1]: l.split('  ', 1)[0]
                              for l in original_manifest.splitlines() if '  ' in l}
            rebuilt_lines = {l.split('  ', 1)[1]: l.split('  ', 1)[0]
                             for l in rebuilt_manifest.splitlines() if '  ' in l}

            for missing in sorted(set(original_lines) - set(rebuilt_lines))[:10]:
                print(f'  rebuild dropped: {missing}', file=sys.stderr)
            for added in sorted(set(rebuilt_lines) - set(original_lines))[:10]:
                print(f'  rebuild added: {added}', file=sys.stderr)
            for common in sorted(set(original_lines) & set(rebuilt_lines)):
                if original_lines[common] != rebuilt_lines[common]:
                    print(f'  content differs: {common}', file=sys.stderr)
                    break

            raise SystemExit('FAIL: the clean-extraction rebuild does not reproduce the '
                             'delivered manifest exactly')

        rebuilt = rebuild_out / f'{NAME}.zip'
        if not rebuilt.is_file():
            raise SystemExit('FAIL: the rebuild produced no archive')

        with zipfile.ZipFile(rebuilt) as rz, zipfile.ZipFile(zip_path) as oz:
            rebuilt_names = {n for n in rz.namelist() if not n.endswith('/')}
            original_names = {n for n in oz.namelist() if not n.endswith('/')}

        if rebuilt_names != original_names:
            for missing in sorted(original_names - rebuilt_names)[:10]:
                print(f'  rebuild archive missing: {missing}', file=sys.stderr)
            for added in sorted(rebuilt_names - original_names)[:10]:
                print(f'  rebuild archive added: {added}', file=sys.stderr)
            raise SystemExit('FAIL: the rebuilt archive entry set differs from the delivered one')

        print(f'clean-extraction self-test: PASS '
              f'({len(rebuilt_names)} entries, manifest byte-identical, no git)')
    finally:
        shutil.rmtree(workspace, ignore_errors=True)


def main() -> int:
    parser = argparse.ArgumentParser(description='Build the FULL-SOURCE archive.')
    parser.add_argument('--project-root', required=True, help='the application directory')
    parser.add_argument('--output-dir', required=True, help='where artifacts are written')
    parser.add_argument('--manifest', help='consume and validate this canonical manifest '
                                           'instead of walking the tree')
    parser.add_argument('--min-files', type=int, default=DEFAULT_MIN_FILES,
                        help=f'fail below this many files (default {DEFAULT_MIN_FILES})')
    parser.add_argument('--allow-symlinks', action='store_true',
                        help='permit symlinks in the tree (they are refused by default)')
    parser.add_argument('--self-test', action='store_true',
                        help='rebuild from a clean extraction of the archive just built')
    return build(parser.parse_args())


if __name__ == '__main__':
    sys.exit(main())
