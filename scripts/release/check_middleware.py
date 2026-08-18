#!/usr/bin/env python3
"""
Verify route middleware using a command Laravel 12 actually supports.

The gate previously ran `php artisan route:list --columns=middleware`.
`RouteListCommand` in Laravel 12.64 has no `--columns` option, so that gate was
guaranteed to exit nonzero before the browser phase ever started.

`--json` is supported, so the route table is parsed as data instead of scraped
from a formatted column. That also lets the gate assert something meaningful:
that the gated routes carry the middleware they are supposed to, and that no
alias failed to resolve.

Usage:
    check_middleware.py --project-root DIR [--php PHP] [--require ROUTE=MIDDLEWARE ...]
"""
from __future__ import annotations

import argparse
import json
import subprocess
import sys

# Routes whose protection is load-bearing for this release.
DEFAULT_REQUIREMENTS = (
    ('account/telegram/link', 'account.active'),
    ('account/onboarding', 'telegram.linked'),
)


def main() -> int:
    parser = argparse.ArgumentParser(description='Verify route middleware.')
    parser.add_argument('--project-root', required=True)
    parser.add_argument('--php', default='php')
    parser.add_argument('--require', action='append', default=[],
                        help='ROUTE=MIDDLEWARE, repeatable')
    parser.add_argument('--allow-empty', action='store_true',
                        help='offline stub: the application cannot boot')
    args = parser.parse_args()

    result = subprocess.run(
        [args.php, 'artisan', 'route:list', '--json'],
        cwd=args.project_root, capture_output=True, text=True,
    )

    if result.returncode != 0:
        print(result.stdout[-2000:])
        print(result.stderr[-2000:], file=sys.stderr)
        print('  FAIL  route:list --json exited nonzero', file=sys.stderr)
        return 1

    try:
        routes = json.loads(result.stdout)
    except json.JSONDecodeError as exc:
        print(f'  FAIL  route:list did not return JSON: {exc}', file=sys.stderr)
        return 1

    problems: list[str] = []

    if not routes:
        if not args.allow_empty:
            problems.append('route:list returned no routes')
        print(f'routes inspected: 0, problems: {len(problems)}')
        return 1 if problems else 0

    def middleware_of(route: dict) -> list[str]:
        value = route.get('middleware', [])
        if isinstance(value, str):
            return [line for line in value.splitlines() if line.strip()]
        return list(value)

    # An alias Laravel could not resolve surfaces in the listing rather than
    # failing at request time, which is the failure mode worth catching early.
    for route in routes:
        for entry in middleware_of(route):
            if 'Unresolvable' in entry or 'does not exist' in entry:
                problems.append(f'{route.get("uri")}: unresolved middleware {entry!r}')

    requirements = list(DEFAULT_REQUIREMENTS)
    for pair in args.require:
        if '=' not in pair:
            problems.append(f'malformed --require: {pair!r}')
            continue
        uri, name = pair.split('=', 1)
        requirements.append((uri, name))

    for uri, required in requirements:
        matching = [r for r in routes if uri in str(r.get('uri', ''))]
        if not matching:
            problems.append(f'no route matching {uri!r}')
            continue
        if not any(any(required in entry for entry in middleware_of(route))
                   for route in matching):
            problems.append(f'{uri}: no route carries middleware {required!r}')

    for problem in problems[:20]:
        print(f'  FAIL  {problem}', file=sys.stderr)

    print(f'routes inspected: {len(routes)}, requirements: {len(requirements)}, '
          f'problems: {len(problems)}')

    return 1 if problems else 0


if __name__ == '__main__':
    sys.exit(main())
