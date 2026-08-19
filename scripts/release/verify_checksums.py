#!/usr/bin/env python3
"""
Verify every detached checksum in a directory, with no fallback.

The earlier shell form ended in `|| echo "no checksums yet"`, so a genuine
mismatch exited 0 and was recorded as PASS.
"""
from __future__ import annotations

import argparse
import hashlib
import os
import sys


def main() -> int:
    parser = argparse.ArgumentParser(description='Verify detached checksums.')
    parser.add_argument('--directory', required=True)
    parser.add_argument('--allow-missing', action='store_true',
                        help='permit an empty set (used before checksums are generated)')
    args = parser.parse_args()

    directory = args.directory
    checksums = sorted(f for f in os.listdir(directory) if f.endswith('.sha256'))
    problems: list[str] = []

    if not checksums and not args.allow_missing:
        problems.append('no detached checksum files found')

    for name in checksums:
        with open(os.path.join(directory, name)) as fh:
            line = fh.read().strip()
        parts = line.split('  ', 1)
        if len(parts) != 2 or len(parts[0]) != 64:
            problems.append(f'{name}: malformed checksum line')
            continue
        expected, target = parts
        path = os.path.join(directory, target)
        if not os.path.isfile(path):
            problems.append(f'{name}: target missing ({target})')
            continue
        h = hashlib.sha256()
        with open(path, 'rb') as fh:
            for chunk in iter(lambda: fh.read(1 << 20), b''):
                h.update(chunk)
        if h.hexdigest() != expected:
            problems.append(f'{name}: checksum mismatch')

    for problem in problems[:20]:
        print(f'  FAIL  {problem}', file=sys.stderr)
    print(f'checksums verified: {len(checksums)}, problems: {len(problems)}')

    return 1 if problems else 0


if __name__ == '__main__':
    sys.exit(main())
