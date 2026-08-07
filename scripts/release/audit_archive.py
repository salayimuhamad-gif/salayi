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

import os
import re
import sys
import zipfile

UNSAFE_NAME = re.compile(
    r'\.(backup(-[\w.-]+)?|bak(-[\w.-]+)?|orig|rej|swp|swo|sqlite)$|~$'
)
WINDOWS_ABSOLUTE = re.compile(r'^[A-Za-z]:[\\/]')


def audit(path: str) -> list[str]:
    problems: list[str] = []

    try:
        archive = zipfile.ZipFile(path)
    except (zipfile.BadZipFile, OSError) as exc:
        return [f'{path}: not a readable ZIP ({exc})']

    with archive as zf:
        seen: set[str] = set()
        lowered: dict[str, str] = {}

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
                problems.append(f'unsafe artifact: {name}')

        corrupt = zf.testzip()
        if corrupt:
            problems.append(f'CRC failure: {corrupt}')

        entries = len(seen)

    print(f'{path}: entries={entries} problems={len(problems)}')

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
