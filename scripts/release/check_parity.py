#!/usr/bin/env python3
"""
Compare two directory trees exactly, in both directions.

Two modes, because the release compares two different things:

    code    the frozen source against the runtime's application/ directory,
            excluding material the runtime deliberately omits.
    assets  the source's built frontend against the runtime's public_html/build,
            with no exclusions — these must be byte-identical.

Both directions matter. An intersection-only comparison misses a runtime file
with no source counterpart, which is exactly how unreviewed material reaches
production. A comparison over zero shared files is a failure, not a pass.
"""
from __future__ import annotations

import argparse
import hashlib
import os
import sys

import sys as _sys
from pathlib import Path as _Path

_sys.path.insert(0, str(_Path(__file__).resolve().parent))
from release_gates import runtime_excluded  # noqa: E402

# The parity skip set IS the runtime exclusion policy. Keeping a second list
# here made parity flag files the runtime was correct to omit.
GENERATED = {'RUNTIME_MANIFEST.txt', 'DELETE_FILES.txt'}


def digest(path: str) -> str:
    h = hashlib.sha256()
    with open(path, 'rb') as fh:
        for chunk in iter(lambda: fh.read(1 << 20), b''):
            h.update(chunk)
    return h.hexdigest()


def collect(root: str, skip: tuple[str, ...]) -> dict[str, str]:
    found: dict[str, str] = {}
    for dirpath, _dirs, files in os.walk(root):
        rel_dir = os.path.relpath(dirpath, root).replace(os.sep, '/')
        rel_dir = '' if rel_dir == '.' else rel_dir
        for name in files:
            rel = f'{rel_dir}/{name}' if rel_dir else name
            if rel in GENERATED:
                continue
            if skip and runtime_excluded(rel):
                continue
            found[rel] = digest(os.path.join(dirpath, name))
    return found


def main() -> int:
    parser = argparse.ArgumentParser(description='Compare two trees exactly.')
    parser.add_argument('--mode', choices=('code', 'assets'), required=True)
    parser.add_argument('--source', required=True)
    parser.add_argument('--runtime', required=True)
    args = parser.parse_args()

    for path in (args.source, args.runtime):
        if not os.path.isdir(path):
            raise SystemExit(f'FAIL: not a directory: {path}')

    skip = args.mode == 'code'
    src = collect(args.source, skip)
    run = collect(args.runtime, skip)
    problems: list[str] = []

    for rel in sorted(set(src) - set(run)):
        problems.append(f'in source, absent from runtime: {rel}')
    for rel in sorted(set(run) - set(src)):
        problems.append(f'in runtime, absent from source: {rel}')
    for rel in sorted(set(src) & set(run)):
        if src[rel] != run[rel]:
            problems.append(f'content differs: {rel}')

    shared = len(set(src) & set(run))

    if shared == 0:
        problems.append('zero shared files; an empty comparison is not a pass')

    for problem in problems[:20]:
        print(f'  FAIL  {problem}', file=sys.stderr)

    print(f'{args.mode} parity: source={len(src)} runtime={len(run)} '
          f'shared={shared} problems={len(problems)}')

    return 1 if problems else 0


if __name__ == '__main__':
    sys.exit(main())
