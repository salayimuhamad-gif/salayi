#!/usr/bin/env python3
"""
Build the SOURCE-PATCH and enforce the deletion contract.

Both sides are collected with the SHARED eligible-file policy, so generated
identity metadata cannot leak into the comparison. The calculated removed set
must equal the canonical deletion inventory exactly — previously it was computed
and then ignored, so a removed file could be absent from both the patch and
DELETE_FILES.txt and survive an overlay deployment.

One class of removal is settled by directory replacement instead of by an
individual deletion entry: see GENERATED_REPLACEMENT_ROOTS below. Nothing else
is exempt, and the exemption is earned per run, not assumed.
"""
from __future__ import annotations

import argparse
import datetime
import hashlib
import json
import os
import re
import shutil
import stat
import subprocess
import sys
import tempfile
import zipfile
from pathlib import Path

# The SHARED eligible-file policy, mirroring scripts/support/SourceIdentity.php.
# An ad-hoc list here is how regenerated Python bytecode ended up counted as
# "removed from the tree", failing a deletion contract that was actually intact.
EXCLUDE_DIRS = ('.git', 'vendor', 'node_modules', '__pycache__', '.phpunit.cache',
                'storage/framework', 'storage/logs', 'storage/app/public',
                'bootstrap/cache', 'dist')
EXCLUDE_FILES = ('TREE_MANIFEST.txt', 'TREE_MANIFEST.sha256', 'SHA256SUMS.txt',
                 '.env', '.env.local', '.env.production', 'auth.json')
EXCLUDE_SUFFIXES = ('.pyc', '.pyo', '.bak', '.orig', '.rej', '.swp', '.swo',
                    '~', '.sqlite', '.log')
BACKUP_PATTERN = re.compile(r'\.backup(-[\w.-]+)?$')

# ------------------------------------------ generated atomic-replacement roots
#
# `public/build` is Vite output. Every production build emits content-hashed
# filenames, so an upgrade "removes" a hundred files nobody authored, edited or
# reviewed — they are the previous build's chunks. Naming them individually in
# DELETE_FILES.txt would produce a list that has to be regenerated on every
# asset rebuild, and it would assert something untrue: these are not files the
# release decided to delete, they are the old contents of a directory the
# release replaces whole.
#
# Whole-directory replacement is not invented here to make a gate pass. It is
# already the deployment contract. DEPLOYMENT_NOTES.md instructs:
#
#     rm -rf public_html/build
#     cp -a <new runtime>/public_html/build public_html/
#
# deploy_rehearsal.sh performs exactly that and asserts afterwards that the
# deployed directory equals the runtime build exactly and that no pre-deployment
# chunk survived. rollback_rehearsal_v7.sh restores the backed-up directory the
# same way and asserts the mirror property. So a removal under one of these
# roots is genuinely applied on the target — by replacement rather than by an
# entry, which is the stronger of the two mechanisms because it cannot miss a
# file the list forgot.
#
# WHAT IS EXEMPT: only the requirement to name such a removal in
# DELETE_FILES.txt. Every removal outside these roots is still a hard failure,
# DELETE_FILES.txt is still verified in both directions against the real trees,
# and the current build is still checked by runtime asset parity, build
# reproducibility, the runtime manifest audit and both rehearsals.
#
# FAIL-CLOSED: a root earns the exemption only when the CURRENT tree actually
# carries a complete build for it — present, non-empty, with a manifest whose
# every reference resolves. A tree that lost or truncated its build directory
# gets no exemption at all: the removals become ordinary undeclared deletions
# and this builder refuses. That is precisely the case an exemption must never
# quietly cover, because the same run would otherwise ship a patch missing the
# assets the site needs to render.
GENERATED_REPLACEMENT_ROOTS = ('public/build',)


def generated_root_of(rel: str) -> str | None:
    """The atomic-replacement root that owns `rel`, or None."""
    for root in GENERATED_REPLACEMENT_ROOTS:
        if rel.startswith(root + '/'):
            return root
    return None


def manifest_references(manifest: dict) -> set[str]:
    """Every build file a Vite manifest points at, relative to the build root."""
    refs: set[str] = set()

    for entry in manifest.values():
        if not isinstance(entry, dict):
            continue
        if isinstance(entry.get('file'), str):
            refs.add(entry['file'])
        for key in ('css', 'assets'):
            for ref in entry.get(key) or ():
                if isinstance(ref, str):
                    refs.add(ref)

    return refs


def replacement_faults(current: Path, root: str) -> list[str]:
    """
    Why `root` may NOT be treated as a complete generated replacement.

    Empty means the current tree carries a build good enough to replace the
    target's directory wholesale. Anything else disqualifies the exemption, and
    the caller then treats removals under the root as ordinary deletions.
    """
    directory = current / root

    if not directory.is_dir():
        return [f'generated replacement root is missing from the tree: {root}']

    if not any(p.is_file() for p in directory.rglob('*')):
        return [f'generated replacement root is empty: {root}']

    manifest_path = directory / 'manifest.json'

    if not manifest_path.is_file():
        return [f'generated replacement root has no manifest.json: {root}']

    try:
        manifest = json.loads(manifest_path.read_text())
    except (json.JSONDecodeError, OSError, UnicodeDecodeError) as exc:
        return [f'generated replacement root has an unreadable manifest: {root} ({exc})']

    if not isinstance(manifest, dict) or not manifest:
        return [f'generated replacement root has an empty manifest: {root}']

    # A manifest entry that does not resolve means the directory about to
    # replace the live one cannot serve the pages it claims to.
    return [f'manifest entry does not resolve under {root}: {ref}'
            for ref in sorted(manifest_references(manifest))
            if not (directory / ref).is_file()]


def eligible(rel: str) -> bool:
    if rel in EXCLUDE_FILES or rel.endswith(EXCLUDE_SUFFIXES):
        return False
    if BACKUP_PATTERN.search(rel):
        return False
    for excluded in EXCLUDE_DIRS:
        if rel == excluded or rel.startswith(excluded + '/'):
            return False
        if '/' not in excluded and f'/{excluded}/' in f'/{rel}':
            return False
    return True


def digest(path: Path) -> str:
    h = hashlib.sha256()
    with open(path, 'rb') as fh:
        for chunk in iter(lambda: fh.read(1 << 20), b''):
            h.update(chunk)
    return h.hexdigest()


def collect(root: Path) -> dict[str, str]:
    found: dict[str, str] = {}
    for path in root.rglob('*'):
        if not path.is_file():
            continue
        rel = str(path.relative_to(root))
        if eligible(rel):
            found[rel] = digest(path)
    return found


def main() -> int:
    parser = argparse.ArgumentParser(description='Build the SOURCE-PATCH.')
    parser.add_argument('--baseline', required=True)
    parser.add_argument('--current', required=True)
    parser.add_argument('--deletions', required=True)
    parser.add_argument('--output', required=True)
    parser.add_argument('--inventory', required=True)
    args = parser.parse_args()

    workspace = Path(tempfile.mkdtemp(prefix='source-patch-'))
    try:
        extracted = workspace / 'baseline'
        extracted.mkdir()
        baseline = Path(args.baseline)

        if baseline.suffix == '.zip':
            with zipfile.ZipFile(baseline) as zf:
                zf.extractall(extracted)
        else:
            subprocess.run(['tar', '-xf', str(baseline), '-C', str(extracted)], check=True)

        if not (extracted / 'artisan').is_file():
            inner = next((p.parent for p in extracted.rglob('artisan')), None)
            if inner is None:
                raise SystemExit('FAIL: no artisan found in the baseline archive')
            extracted = inner

        current = Path(args.current)
        old, new = collect(extracted), collect(current)
        added = sorted(set(new) - set(old))
        modified = sorted(r for r in set(new) & set(old) if new[r] != old[r])
        removed = sorted(set(old) - set(new))

        with open(args.deletions) as fh:
            declared = sorted(line.strip() for line in fh
                              if line.strip() and not line.strip().startswith('#'))

        problems: list[str] = []

        # A root earns its exemption in THIS run, against THIS tree.
        replaced_roots: list[str] = []
        for root in GENERATED_REPLACEMENT_ROOTS:
            faults = replacement_faults(current, root)
            problems.extend(faults)
            if not faults:
                replaced_roots.append(root)

        # Removals settled by replacing the whole directory, and everything
        # else. A root that failed the check above contributes nothing here, so
        # its removals fall through to the strict path below.
        removed_generated = [r for r in removed
                             if generated_root_of(r) in replaced_roots]
        removed_source = sorted(set(removed) - set(removed_generated))

        for rel in sorted(set(removed_source) - set(declared)):
            problems.append(f'removed from the tree but absent from DELETE_FILES.txt: {rel}')

        # Checked against the TREES, not against the eligibility-filtered sets.
        # DELETE_FILES.txt legitimately names paths the patch comparison drops —
        # the sealed baseline's editor backup is one — and set arithmetic
        # reported those as "declared but not actually removed", a false failure
        # on an entry doing exactly its job. Presence on disk answers the real
        # question, and answers it in both directions: the path must have
        # existed in the baseline (or there is nothing to delete) and must be
        # gone from the current tree (or the release is not deleting it).
        for rel in declared:
            if rel.startswith('/') or '..' in rel.split('/') or '\\' in rel:
                problems.append(f'unsafe deletion path in DELETE_FILES.txt: {rel}')
                continue
            if not (extracted / rel).exists():
                problems.append(
                    f'declared in DELETE_FILES.txt but absent from the baseline: {rel}')
            if (current / rel).exists():
                problems.append(
                    f'declared in DELETE_FILES.txt but still present in the tree: {rel}')

        if problems:
            for problem in problems[:20]:
                print(f'  FAIL  {problem}', file=sys.stderr)
            raise SystemExit(f'FAIL: the deletion contract does not hold '
                             f'({len(problems)} problem(s))')

        patch = workspace / 'patch'
        patch.mkdir()

        # A replacement root is copied WHOLE, not merely its changed files.
        # The target directory is deleted before this one is copied over it, so
        # a patch carrying only the changed chunks would produce a build
        # directory missing every file that happened not to change — the exact
        # "SOURCE-PATCH missing required current assets" failure. Four of the
        # current build's files are byte-identical to the baseline's; they are
        # still required for the site to render.
        carried = sorted(set(added) | set(modified) | {
            rel for rel in new if generated_root_of(rel) in replaced_roots})

        for rel in carried:
            target = patch / rel
            target.parent.mkdir(parents=True, exist_ok=True)
            shutil.copy2(current / rel, target)

        shutil.copy2(args.deletions, patch / 'DELETE_FILES.txt')

        # Ships WITH the patch, because the instruction has to travel with the
        # thing it applies to. An operator who merges these directories instead
        # of replacing them ends up with the previous build's chunks beside the
        # new ones and a deletion contract that was never really applied.
        if replaced_roots:
            (patch / 'REPLACE_DIRS.txt').write_text(
                '# Directories this patch REPLACES WHOLE.\n'
                '#\n'
                '# Delete the target directory first, then copy; never merge into\n'
                '# it. These hold content-hashed build output, so merging leaves\n'
                '# the previous build\'s chunks in place forever. Removals under\n'
                '# these roots are settled by this replacement and are therefore\n'
                '# not listed individually in DELETE_FILES.txt.\n'
                + ''.join(f'{root}\n' for root in replaced_roots))

        inventory = {'added': added, 'modified': modified, 'removed': removed,
                     'removed_generated': removed_generated,
                     'replaced_roots': replaced_roots,
                     'total': len(added) + len(modified)}
        (patch / 'CHANGED_FILES_INVENTORY.json').write_text(json.dumps(inventory, indent=2))
        Path(args.inventory).write_text(json.dumps(inventory, indent=2))

        (patch / 'PATCH_README.md').write_text(
            '# Source patch — overlay for the verified baseline\n\n'
            f'Added: {len(added)}   Modified: {len(modified)}   Removed: {len(removed)}\n\n'
            'Every added and modified file is included in full. Removals cannot be\n'
            'expressed by an overlay and must be applied from DELETE_FILES.txt;\n'
            'every entry there is verified to exist in the baseline and to be gone\n'
            'from this release, and every removal outside the replacement roots\n'
            'below is verified to appear in it.\n\n'
            '## Replacement roots\n\n'
            + (''.join(f'- `{root}` — {len([r for r in removed_generated])} file(s) '
                       'from the previous build are retired by replacing this\n'
                       '  directory, not by individual deletion entries.\n'
                       for root in replaced_roots) if replaced_roots else
               '_None: every removal in this release is an individual entry._\n')
            + '\nDirectories named in `REPLACE_DIRS.txt` are copied here IN FULL and\n'
              'must be applied by deleting the target directory first. Merging them\n'
              'leaves the previous build\'s content-hashed chunks in place.\n')

        output = Path(args.output)
        output.unlink(missing_ok=True)
        # Deterministic replacement for `zip -qrX <output> .`: the external
        # zip recursed in readdir order and stamped each entry with its
        # on-disk mtime — and the whole patch tree is copied/written fresh
        # under a mkdtemp every run, so two runs from identical inputs
        # produced metadata-differing bytes. Sorted traversal, pinned
        # timestamps (SOURCE_DATE_EPOCH from the release cycle, else the
        # file's own mtime), permissions from disk, no extra fields.
        epoch = os.environ.get('SOURCE_DATE_EPOCH')

        def entry_time(p: Path):
            if epoch and epoch.isdigit():
                moment = datetime.datetime.fromtimestamp(int(epoch), datetime.timezone.utc)
            else:
                moment = datetime.datetime.fromtimestamp(p.stat().st_mtime, datetime.timezone.utc)
            if moment.year < 1980:
                moment = datetime.datetime(1980, 1, 1, tzinfo=datetime.timezone.utc)
            return (moment.year, moment.month, moment.day,
                    moment.hour, moment.minute, moment.second)

        with zipfile.ZipFile(output, 'w', zipfile.ZIP_DEFLATED) as zf:
            for sub in sorted(patch.rglob('*'), key=lambda p: p.relative_to(patch).as_posix()):
                rel = sub.relative_to(patch).as_posix()
                info_mode = stat.S_IMODE(sub.stat().st_mode)
                if sub.is_dir():
                    info = zipfile.ZipInfo(rel + '/', date_time=entry_time(sub))
                    info.external_attr = ((info_mode | stat.S_IFDIR) << 16) | 0x10
                    zf.writestr(info, b'')
                else:
                    info = zipfile.ZipInfo(rel, date_time=entry_time(sub))
                    info.external_attr = (info_mode | stat.S_IFREG) << 16
                    info.compress_type = zipfile.ZIP_DEFLATED
                    zf.writestr(info, sub.read_bytes())

        print(f'source patch: added={len(added)} modified={len(modified)} '
              f'removed={len(removed)} deletion-contract=OK')
        print(f'  undeclared removals outside the replacement roots: '
              f'{len(set(removed_source) - set(declared))}')

        # Loud, and in the recorded gate log. A removal settled by replacement
        # must never be indistinguishable from one that was never noticed.
        if removed_generated:
            print(f'  REPLACED  {len(removed_generated)} previous-build file(s) '
                  f'retired by whole-directory replacement of '
                  f'{", ".join(replaced_roots)} — see REPLACE_DIRS.txt')

        if not (added or modified):
            raise SystemExit('FAIL: the patch is empty')

        return 0
    finally:
        shutil.rmtree(workspace, ignore_errors=True)


if __name__ == '__main__':
    sys.exit(main())
