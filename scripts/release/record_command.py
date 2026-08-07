#!/usr/bin/env python3
"""
Record ONE command by actually running it.

This replaces `append_ledger_entries.py`, which was deleted. That script walked
the evidence directory looking for log files and wrote a ledger entry for each
one with `exit_code: 0`, `duration_seconds: 0` and both timestamps set to the
moment the appender happened to run. It never executed anything. A log file
existing is not evidence that a command succeeded, that the log is complete, or
that the command named in the ledger is the one that produced it — so those
entries were manufactured, not recorded.

Everything here is measured from a real execution:

    start time -> run the exact command -> capture stdout+stderr -> real exit
    code -> finish time and duration -> hash the bytes -> append immediately

The entry is written as soon as the command finishes, so a run that dies part
way through leaves a truthful partial ledger rather than a fabricated complete
one.

THE WRAPPER'S EXIT CODE IS ALWAYS THE CHILD'S. An earlier `--allow-nonzero`
mode recorded the child's failure in the ledger and then exited 0 itself; every
caller that captured `$?` after such an invocation read the wrapper's 0 where
the child had exited 7, so a failed post-seal verifier could not stop the
release. That mode is gone. A caller that wants to continue past a failing
child must capture the propagated exit code explicitly (`set +e` ... `$?` ...
`set -e`) and is expected to cross-check it against the ledger's measured
`exit_code` — the two must be the same number.

The entry records the exact `argv` as a JSON array — `command` is only a
shell-quoted rendering for humans, and cannot round-trip argument boundaries —
plus a redacted map of the actual child environment. Values of secret-bearing
keys (passwords, tokens, application keys, blind-index and PII keys, Telegram
and database secrets) are never stored; the key's presence is the record. The
same rule applies to a `KEY=VALUE` argv token whose KEY is secret-bearing —
an `env`-style invocation would otherwise write the literal credential into
the shipped ledger through the exact-argv record. When `--clean-env` is used,
the exact environment-key allow-list that was applied is recorded alongside
the surviving environment.

A child killed by a signal is recorded and propagated as `128 + N`, the number
a shell parent actually observes, with the raw signal kept in
`termination_signal` — recording subprocess's negative form would make the
captured-equals-measured invariant unsatisfiable for every signal death.

Usage:
    record_command.py --ledger FILE --evidence-dir DIR
                      --tree-manifest-sha256 SHA --label LABEL
                      [--server-configuration TEXT] [--cwd DIR]
                      [--database] [--clean-env] -- COMMAND [ARGS...]

Exit code is the command's own, so callers can react to it normally.
"""
from __future__ import annotations

import argparse
import datetime
import hashlib
import json
import os
import platform
import shlex
import subprocess
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

from release_gates import BY_LABEL, log_path  # noqa: E402

# Gates that legitimately need a live database connection. Everything else has
# DB_* removed so phpunit.xml's own <env> entries decide the connection.
DATABASE_GATES = frozenset({
    'focused-mariadb', 'full-mariadb', 'handoff-concurrency-mariadb',
    'route-check', 'middleware-check', 'scheduler-check', 'queue-check',
    'browser-database-prepare', 'browser-fixtures-seed',
    'playwright-mobile-360x800', 'playwright-mobile-390x844',
    'playwright-tablet-768x1024', 'playwright-laptop-1366x768',
    'playwright-desktop-1440x900',
    'deployment-rehearsal', 'rollback-rehearsal',
})

REQUIRED_FIELDS = (
    'label', 'argv', 'command', 'working_directory', 'started_at',
    'finished_at', 'duration_seconds', 'exit_code', 'termination_signal',
    'environment', 'child_environment', 'clean_env', 'clean_env_allow_list',
    'server_configuration', 'raw_output_file', 'raw_output_sha256',
    'raw_output_bytes', 'final_tree_manifest_sha256',
)

# A key whose name matches any of these carries a secret, and its VALUE must
# never reach the ledger. The key's presence (as '[REDACTED]') is the record.
# Substring matching errs toward redaction: losing GIT_AUTHOR_NAME from the
# evidence is harmless; storing MYHAWLER_DB_PASSWORD is not.
SENSITIVE_ENV_MARKERS = (
    'PASSWORD', 'PASSWD', 'SECRET', 'TOKEN', 'PRIVATE', 'CREDENTIAL',
    'APIKEY', 'API_KEY', 'ACCESS_KEY', 'AUTH',
)


def sensitive_key(key: str) -> bool:
    upper = key.upper()

    return (
        upper == 'KEY'
        or upper.endswith('_KEY')
        or any(marker in upper for marker in SENSITIVE_ENV_MARKERS)
    )


def redacted_environment(env: dict[str, str]) -> dict[str, str]:
    return {
        key: '[REDACTED]' if sensitive_key(key) else value
        for key, value in sorted(env.items())
    }


def redacted_argv(argv: list[str]) -> list[str]:
    """
    The stored argv, with the VALUE of any secret-bearing KEY=VALUE token
    replaced. An `env REHEARSAL_DB_PASSWORD=... command` invocation carries the
    literal credential as an argv token, and the exact-argv record must not
    become the leak the environment redaction exists to prevent. Everything
    that is not such an assignment is stored exactly as executed.
    """
    stored = []

    for token in argv:
        key, sep, _ = token.partition('=')
        if sep and key and sensitive_key(key):
            stored.append(f'{key}=[REDACTED]')
        else:
            stored.append(token)

    return stored


def now() -> datetime.datetime:
    return datetime.datetime.now(datetime.timezone.utc)


def environment_label() -> str:
    def first_line(command: str) -> str:
        try:
            result = subprocess.run(command, shell=True, capture_output=True,
                                    text=True, timeout=60)
            return result.stdout.strip().splitlines()[0] if result.stdout.strip() else 'unknown'
        except Exception:
            return 'unknown'

    # Kept outside the f-string so the recorder also runs on Python < 3.12,
    # which rejects backslashes inside f-string expressions.
    php_version_probe = "php -r 'echo PHP_VERSION;'"

    return (
        f'{platform.system()} {platform.release()}; '
        f'php {first_line(php_version_probe)}; '
        f'node {first_line("node -v")}'
    )


def main() -> int:
    parser = argparse.ArgumentParser(description='Record one command execution.')
    parser.add_argument('--ledger', required=True)
    parser.add_argument('--evidence-dir', required=True)
    parser.add_argument('--tree-manifest-sha256', required=True)
    parser.add_argument('--label', required=True)
    parser.add_argument('--server-configuration', default=None)
    parser.add_argument('--cwd', default=None)
    parser.add_argument('--database', action='store_true',
                        help='pass DB_* through to this gate (see DATABASE_GATES)')
    parser.add_argument('--clean-env', action='store_true',
                        help='run under a minimal environment, as `env -i` would')
    parser.add_argument('command', nargs=argparse.REMAINDER)
    args = parser.parse_args()

    command = args.command[1:] if args.command and args.command[0] == '--' else args.command

    if not command:
        raise SystemExit('FAIL: no command given after --')

    tree = args.tree_manifest_sha256

    if len(tree) != 64 or any(c not in '0123456789abcdef' for c in tree):
        raise SystemExit('FAIL: --tree-manifest-sha256 must be a 64-character lowercase hex digest')

    if args.label not in BY_LABEL:
        raise SystemExit(
            f'FAIL: {args.label!r} is not in the canonical gate registry. '
            'Add it to release_gates.py rather than inventing a label here.'
        )

    evidence = Path(args.evidence_dir).resolve()
    relative = log_path(args.label)
    destination = evidence / relative
    destination.parent.mkdir(parents=True, exist_ok=True)

    ledger_path = Path(args.ledger).resolve()
    ledger_path.parent.mkdir(parents=True, exist_ok=True)

    working_directory = Path(args.cwd).resolve() if args.cwd else Path.cwd()

    # The default PHPUnit suite must not inherit a DB_CONNECTION that overrides
    # phpunit.xml — that once made a "SQLite" run report MariaDB figures.
    #
    # But the rule was "strip DB_* unless the label says mariadb", which also
    # stripped them from the browser and runtime gates, whose whole purpose is
    # to run against a prepared database. Those gates then silently fell back to
    # the framework default. The policy is now explicit per gate rather than
    # inferred from a substring.
    child_env = dict(os.environ)
    DB_KEYS = ('DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE',
               'DB_USERNAME', 'DB_PASSWORD')

    database_env_passed = args.database or args.label in DATABASE_GATES

    if not database_env_passed:
        for key in DB_KEYS:
            child_env.pop(key, None)

    clean_env_allow_list = None

    if args.clean_env:
        # A rollback happens in a NEW shell on a misbehaving site. Inheriting the
        # deploy-time environment would prove the procedure works only where it
        # is never needed.
        keep = {'PATH', 'HOME', 'LANG', 'TERM'}
        prefixes = ('REHEARSAL_',)
        child_env = {k: v for k, v in child_env.items()
                     if k in keep or k.startswith(prefixes)}
        clean_env_allow_list = {'keys': sorted(keep), 'prefixes': list(prefixes)}

    started = now()
    result = subprocess.run(
        command, cwd=str(working_directory), capture_output=True,
        text=False, env=child_env,
    )
    finished = now()

    # subprocess reports a signal death as -N while a shell parent observes
    # 128+N. One number must appear in the ledger, the propagated exit and the
    # caller's capture, so the shell's convention wins and the raw signal is
    # kept as its own field.
    exit_code = (result.returncode if result.returncode >= 0
                 else 128 + (-result.returncode))
    termination_signal = -result.returncode if result.returncode < 0 else None

    payload = (result.stdout or b'') + (result.stderr or b'')
    destination.write_bytes(payload)

    stored_argv = redacted_argv(list(command))

    entry = {
        'label': args.label,
        # argv is the authoritative execution identity, exact except that the
        # VALUE of a secret-bearing KEY=VALUE token is redacted. 'command' is
        # only a shell-quoted rendering of the same stored argv for humans; a
        # joined string cannot round-trip argument boundaries, so nothing may
        # consume it as an argv.
        'argv': stored_argv,
        'command': shlex.join(stored_argv),
        'working_directory': str(working_directory),
        'started_at': started.isoformat(),
        'finished_at': finished.isoformat(),
        'duration_seconds': round((finished - started).total_seconds(), 3),
        'exit_code': exit_code,
        'termination_signal': termination_signal,
        'environment': environment_label(),
        'child_environment': redacted_environment(child_env),
        'clean_env': bool(args.clean_env),
        'clean_env_allow_list': clean_env_allow_list,
        'database_env_passed': database_env_passed,
        'server_configuration': args.server_configuration,
        'raw_output_file': relative,
        'raw_output_sha256': hashlib.sha256(payload).hexdigest(),
        'raw_output_bytes': len(payload),
        'final_tree_manifest_sha256': tree,
    }

    missing = [field for field in REQUIRED_FIELDS if field not in entry]

    if missing:
        raise SystemExit(f'FAIL: entry is missing {", ".join(missing)}')

    if ledger_path.is_file():
        document = json.loads(ledger_path.read_text())
    else:
        document = {
            'schema': 'myhawler-command-ledger/v3',
            'final_tree_manifest_sha256': tree,
            'entries': [],
        }

    if document.get('final_tree_manifest_sha256') != tree:
        raise SystemExit(
            'FAIL: the ledger is bound to '
            f'{document.get("final_tree_manifest_sha256")}, not {tree}'
        )

    # Re-running a label replaces its entry, so a retried gate records its real
    # latest execution rather than accumulating stale duplicates.
    document['entries'] = [
        existing for existing in document['entries']
        if existing.get('label') != args.label
    ]
    document['entries'].append(entry)
    document['entries'].sort(key=lambda e: e['label'])

    ledger_path.write_text(json.dumps(document, indent=2) + '\n')

    # Echo the command's own output so the surrounding job log stays readable.
    sys.stdout.write(payload.decode('utf-8', 'replace'))
    sys.stdout.flush()

    print(
        f'[ledger] {args.label}: exit={exit_code} '
        f'{entry["duration_seconds"]}s {entry["raw_output_bytes"]}B -> {relative}',
        file=sys.stderr,
    )

    # The child's own exit code, unconditionally. The ledger records the same
    # number, and callers that capture past a failure must compare the two.
    return exit_code


if __name__ == '__main__':
    sys.exit(main())
