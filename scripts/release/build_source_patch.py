#!/usr/bin/env python3
"""
Build the SOURCE-PATCH and enforce the deletion contract.

Both sides are collected with the SHARED eligible-file policy, so generated
identity metadata cannot leak into the comparison. The calculated removed set
must equal the canonical deletion inventory exactly — previously it was computed
and then ignored, so a removed file could be absent from both the patch and
DELETE_FILES.txt and survive an overlay deployment.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import re
import shutil
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

        old, new = collect(extracted), collect(Path(args.current))
        added = sorted(set(new) - set(old))
        modified = sorted(r for r in set(new) & set(old) if new[r] != old[r])
        removed = sorted(set(old) - set(new))

        with open(args.deletions) as fh:
            declared = sorted(line.strip() for line in fh
                              if line.strip() and not line.strip().startswith('#'))

        problems: list[str] = []
        for rel in sorted(set(removed) - set(declared)):
            problems.append(f'removed from the tree but absent from DELETE_FILES.txt: {rel}')
        for rel in sorted(set(declared) - set(removed)):
            problems.append(f'declared in DELETE_FILES.txt but not actually removed: {rel}')

        if problems:
            for problem in problems[:20]:
                print(f'  FAIL  {problem}', file=sys.stderr)
            raise SystemExit(f'FAIL: the deletion contract does not hold '
                             f'({len(problems)} problem(s))')

        patch = workspace / 'patch'
        patch.mkdir()
        for rel in added + modified:
            target = patch / rel
            target.parent.mkdir(parents=True, exist_ok=True)
            shutil.copy2(Path(args.current) / rel, target)

        shutil.copy2(args.deletions, patch / 'DELETE_FILES.txt')

        inventory = {'added': added, 'modified': modified, 'removed': removed,
                     'total': len(added) + len(modified)}
        (patch / 'CHANGED_FILES_INVENTORY.json').write_text(json.dumps(inventory, indent=2))
        Path(args.inventory).write_text(json.dumps(inventory, indent=2))

        (patch / 'PATCH_README.md').write_text(
            '# Source patch — overlay for the verified baseline\n\n'
            f'Added: {len(added)}   Modified: {len(modified)}   Removed: {len(removed)}\n\n'
            'Every added and modified file is included in full. Removals cannot be\n'
            'expressed by an overlay and must be applied from DELETE_FILES.txt;\n'
            'the removed set above is verified to equal that file exactly.\n')

        output = Path(args.output)
        output.unlink(missing_ok=True)
        subprocess.run(['zip', '-qrX', str(output), '.'], cwd=str(patch), check=True)

        print(f'source patch: added={len(added)} modified={len(modified)} '
              f'removed={len(removed)} deletion-contract=OK')

        if not (added or modified):
            raise SystemExit('FAIL: the patch is empty')

        return 0
    finally:
        shutil.rmtree(workspace, ignore_errors=True)


if __name__ == '__main__':
    sys.exit(main())
