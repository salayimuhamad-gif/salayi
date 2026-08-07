#!/usr/bin/env python3
"""Apply every DELETE_FILES entry to a scratch copy, then restore it."""
from __future__ import annotations

import argparse
import os
import shutil
import sys


def main() -> int:
    parser = argparse.ArgumentParser(description='Verify DELETE_FILES apply/restore.')
    parser.add_argument('--tree', required=True)
    parser.add_argument('--manifest', required=True)
    parser.add_argument('--work', required=True)
    args = parser.parse_args()

    shutil.rmtree(args.work, ignore_errors=True)
    shutil.copytree(args.tree, args.work)

    with open(args.manifest) as fh:
        entries = [line.strip() for line in fh
                   if line.strip() and not line.strip().startswith('#')]

    backup = args.work + '-deleted'
    os.makedirs(backup, exist_ok=True)
    problems: list[str] = []

    for rel in entries:
        if rel.startswith('/') or '..' in rel.split('/') or '\\' in rel:
            problems.append(f'unsafe deletion path: {rel}')
            continue
        target = os.path.join(args.work, rel)
        if not os.path.exists(target):
            print(f'  absent (already applied): {rel}')
            continue
        keep = os.path.join(backup, rel)
        os.makedirs(os.path.dirname(keep), exist_ok=True)
        shutil.move(target, keep)
        print(f'  deleted: {rel}')

    for rel in entries:
        kept = os.path.join(backup, rel)
        if os.path.exists(kept):
            restored = os.path.join(args.work, rel)
            os.makedirs(os.path.dirname(restored), exist_ok=True)
            shutil.move(kept, restored)
            if not os.path.exists(restored):
                problems.append(f'restore failed: {rel}')
            else:
                print(f'  restored: {rel}')

    for problem in problems[:20]:
        print(f'  FAIL  {problem}', file=sys.stderr)
    print(f'deletion entries: {len(entries)}, problems: {len(problems)}')

    return 1 if problems else 0


if __name__ == '__main__':
    sys.exit(main())
