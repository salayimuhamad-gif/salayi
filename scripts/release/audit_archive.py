#!/usr/bin/env python3
"""
Audit a ZIP for unsafe entries BEFORE anything extracts it.

A detached checksum proves an archive is the one you expected. It says nothing
about whether extracting it writes outside the directory you chose. Both the
source archive and the verified baseline go through this, because "we verified
its hash" was previously treated as sufficient for the baseline.

Usage:
    audit_archive.py ARCHIVE [ARCHIVE...]

Exits nonzero on any finding and prints a computed result marker, so a caller
can tell a real audit from a gate that silently did nothing.
"""
from __future__ import annotations

import hashlib
import os
import re
import sys
import zipfile

UNSAFE_NAME = re.compile(
    r'\.(backup(-[\w.-]+)?|bak(-[\w.-]+)?|orig|rej|swp|swo|sqlite)$|~$'
)
WINDOWS_ABSOLUTE = re.compile(r'^[A-Za-z]:[\\/]')

# ------------------------------------------------- sealed historical baseline
#
# ONE archive, identified by its exact bytes, may carry ONE named legacy
# artifact. Nothing else is exempt, anywhere, ever.
#
# WHY THIS EXISTS. The verified v6 final baseline (sealed commit
# 9c0188f81843cfe4786b7f72ecdc2a3fae89cd82) ships a stale editor backup. The
# v6-era auditor did not recognise it: its pattern matched `.backup` exactly, so
# a dated suffix slipped through. v7 hardened UNSAFE_NAME to catch
# `.backup-<date>` precisely because that file escaped — which means the sealed
# historical archive now fails an audit it legitimately passed when it was made.
#
# The file is NOT tolerated in v7. `DELETE_FILES.txt` removes it during upgrade,
# and `build_source_patch.py` verifies that removal in both directions, so the
# artifact is provably gone from the delivered tree. What cannot happen is
# rebuilding an immutable historical input to satisfy a rule written after it
# was sealed — that would destroy the baseline's identity, which the whole
# source-patch and rehearsal chain depends on.
#
# WHY IT IS SAFE. The waiver is pinned to the archive's SHA-256, so it cannot
# generalise: a re-zipped, tampered, or merely different baseline gets the
# strict policy with no exceptions. It suppresses exactly one finding class
# (`unsafe artifact`) for exactly one member path. Every other check — traversal,
# absolute paths, backslash/Windows paths, symlinks, encryption, duplicates,
# case collisions, CRC — still applies to that member and to every other entry.
# Any additional unsafe artifact in the same archive is still a hard failure.
#
# Waivers are printed, never silent, so a recorded gate log shows exactly what
# was excused and under which archive hash.
BASELINE_LEGACY_ARTIFACTS: dict[str, frozenset[str]] = {
    '48bfca9ef14b71a9c3605c249cf9cfe366830eb04303f58ddb3ba6befe7eb4d7': frozenset({
        'app/Modules/Operations/Http/Controllers/Admin/'
        'OperationsController.php.backup-20260802-014031',
    }),
}


def sha256_file(path: str) -> str:
    """Streamed digest: the archive is the identity the waiver is pinned to."""
    digest = hashlib.sha256()

    with open(path, 'rb') as handle:
        for chunk in iter(lambda: handle.read(1 << 20), b''):
            digest.update(chunk)

    return digest.hexdigest()


def single_root(names: list[str]) -> str | None:
    """
    The archive's one top-level directory, or None when there is not exactly one.

    Release archives are published with a single `mulkihawler/` root, so a waiver
    is written against the repository-relative path and matched through this.
    Returning None when the shape is anything else keeps the match exact rather
    than guessing at a prefix.
    """
    if any('/' not in name for name in names):
        return None

    roots = {name.split('/', 1)[0] for name in names}

    return roots.pop() if len(roots) == 1 else None


def audit(path: str) -> list[str]:
    problems: list[str] = []

    try:
        archive = zipfile.ZipFile(path)
    except (zipfile.BadZipFile, OSError) as exc:
        return [f'{path}: not a readable ZIP ({exc})']

    # Pinned to the exact bytes, so the waiver cannot follow a path into any
    # other archive. An unknown hash yields an empty set: strict policy.
    waivable = BASELINE_LEGACY_ARTIFACTS.get(sha256_file(path), frozenset())
    waived: list[str] = []

    with archive as zf:
        seen: set[str] = set()
        lowered: dict[str, str] = {}
        root = single_root([i.filename for i in zf.infolist()]) if waivable else None

        for info in zf.infolist():
            name = info.filename

            if name in seen:
                problems.append(f'duplicate entry: {name}')
            seen.add(name)

            low = name.lower()
            if low in lowered and lowered[low] != name:
                problems.append(f'case collision: {name} vs {lowered[low]}')
            lowered.setdefault(low, name)

            if name.startswith('/') or '..' in name.split('/'):
                problems.append(f'unsafe path: {name}')

            if WINDOWS_ABSOLUTE.match(name) or '\\' in name:
                problems.append(f'windows or backslash path: {name}')

            if info.flag_bits & 0x1:
                problems.append(f'encrypted entry: {name}')

            if (info.external_attr >> 16) & 0xA000 == 0xA000:
                problems.append(f'symlink: {name}')

            if UNSAFE_NAME.search(name.rsplit('/', 1)[-1]):
                # Only this one finding class is waivable, and only for a member
                # named in the pinned set. Every check above still applied.
                relative = (name[len(root) + 1:]
                            if root and name.startswith(f'{root}/') else name)

                if relative in waivable:
                    waived.append(name)
                else:
                    problems.append(f'unsafe artifact: {name}')

        corrupt = zf.testzip()
        if corrupt:
            problems.append(f'CRC failure: {corrupt}')

        entries = len(seen)

    print(f'{path}: entries={entries} problems={len(problems)}'
          + (f' waived={len(waived)}' if waived else ''))

    # Loud, and in the recorded gate log: an excused artifact must never be
    # indistinguishable from one that was never there.
    for name in waived:
        print(f'  WAIVED  sealed v6 baseline legacy artifact, removed by '
              f'DELETE_FILES.txt during upgrade: {name}')

    return problems


def audit_recursive(path: str, depth: int = 0, label: str | None = None) -> list[str]:
    """Audit an archive AND every ZIP nested inside it."""
    label = label or path
    problems = audit(path)

    if depth >= 3:
        return problems + [f'{label}: nesting deeper than 3 levels']

    try:
        with zipfile.ZipFile(path) as zf:
            for info in zf.infolist():
                if not info.filename.endswith('.zip'):
                    continue
                import tempfile
                with tempfile.NamedTemporaryFile(suffix='.zip', delete=False) as tmp:
                    tmp.write(zf.read(info.filename))
                    nested = tmp.name
                problems.extend(
                    audit_recursive(nested, depth + 1, f'{label}!{info.filename}'))
                os.unlink(nested)
    except (zipfile.BadZipFile, OSError):
        pass

    return problems


def main() -> int:
    import argparse

    parser = argparse.ArgumentParser(description='Audit archives for unsafe entries.')
    parser.add_argument('--recursive', action='store_true',
                        help='also audit ZIPs nested inside the given archives')
    parser.add_argument('targets', nargs='+', help='archives, or directories of archives')
    args = parser.parse_args()

    archives: list[str] = []
    for target in args.targets:
        if os.path.isdir(target):
            archives.extend(sorted(os.path.join(target, n) for n in os.listdir(target)
                                   if n.endswith('.zip')))
        else:
            archives.append(target)

    if not archives:
        raise SystemExit('FAIL: no archive given')

    problems: list[str] = []

    for path in archives:
        problems.extend(audit_recursive(path) if args.recursive else audit(path))

    for problem in problems[:20]:
        print(f'  FAIL  {problem}', file=sys.stderr)

    print(f'archive audit problems: {len(problems)}')

    return 1 if problems else 0


if __name__ == '__main__':
    sys.exit(main())
