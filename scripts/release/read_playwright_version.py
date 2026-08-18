#!/usr/bin/env python3
"""
Read the Playwright version from a package-lock and validate it strictly.

The value is interpolated into a shell command that installs a browser, so a
permissive check is a command-injection surface. The previous shell glob
`[0-9]*.[0-9]*.[0-9]*` accepts "1.2.3;echo INJECT" and "1.2.3 extra"; this
requires a full semantic-version match and prints nothing on failure.
"""
from __future__ import annotations

import argparse
import json
import re
import sys

SEMVER = re.compile(r'^[0-9]+\.[0-9]+\.[0-9]+([-.][0-9A-Za-z.]+)?$')


def main() -> int:
    parser = argparse.ArgumentParser(description='Read the locked Playwright version.')
    parser.add_argument('--lock', required=True)
    args = parser.parse_args()

    try:
        with open(args.lock) as fh:
            packages = json.load(fh).get('packages', {})
    except (OSError, json.JSONDecodeError) as exc:
        print(f'unreadable package lock: {exc}', file=sys.stderr)
        return 1

    entry = (packages.get('node_modules/@playwright/test')
             or packages.get('node_modules/playwright') or {})
    version = entry.get('version', '')

    if not SEMVER.fullmatch(version):
        print(f'unusable Playwright version from the lock: {version!r}', file=sys.stderr)
        return 1

    print(version)
    return 0


if __name__ == '__main__':
    sys.exit(main())
