#!/usr/bin/env python3
"""Verify every asset the runtime's Vite manifest references actually exists."""
from __future__ import annotations

import argparse
import json
import os
import sys


def main() -> int:
    parser = argparse.ArgumentParser(description='Audit the runtime Vite manifest.')
    parser.add_argument('--build-dir', required=True)
    args = parser.parse_args()

    manifest_path = os.path.join(args.build_dir, 'manifest.json')
    if not os.path.isfile(manifest_path):
        print('  FAIL  no manifest.json in the runtime build', file=sys.stderr)
        return 1

    with open(manifest_path) as fh:
        manifest = json.load(fh)

    referenced: set[str] = set()
    for entry in manifest.values():
        for key in ('file', 'css', 'assets'):
            value = entry.get(key)
            if isinstance(value, str):
                referenced.add(value)
            elif isinstance(value, list):
                referenced.update(value)

    missing = [r for r in sorted(referenced)
               if not os.path.isfile(os.path.join(args.build_dir, r))]

    for rel in missing[:20]:
        print(f'  FAIL  manifest references a missing asset: {rel}', file=sys.stderr)
    print(f'vite manifest entries: {len(manifest)}, referenced: {len(referenced)}, '
          f'missing: {len(missing)}')

    return 1 if missing else 0


if __name__ == '__main__':
    sys.exit(main())
