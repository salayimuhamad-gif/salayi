#!/usr/bin/env bash
#
# Prove the final-delivery packaging is byte-for-byte reproducible.
#
# Runs the REAL final-release cycle (run_final_release_ci.sh --offline-stub:
# freeze, builders, packaging, indexes, sealing and the clean-room verifier
# are all real; only external toolchains are stubbed) TWICE from one frozen
# input, then compares the two deliveries file by file.
#
# The comparison is a CLOSED CLASSIFICATION, in the spirit of the browser
# spec registry: every file in the delivery must be named either
# REPRODUCIBLE (bytes must be identical across the two runs — the product:
# archives, manifests, identity documents, helpers and their checksums) or
# OPERATIONAL (truthful per-run evidence — the command ledger with its real
# start/finish times, the sealed evidence, the indexes and master that bind
# that evidence's hashes, and TEST_RESULTS.md with its real durations).
# A file in neither list fails the check: a new artifact must be classified
# deliberately, never absorbed silently into "allowed to differ".
#
# Usage:
#   scripts/release/verify_delivery_determinism.sh --work DIR
#       [--source-archive ZIP --source-sha256 FILE]
#
# Without --source-archive the current project tree is staged through the
# sanctioned scripts/release/stage_tree.php (twice — the fixed point is
# required) and packed with the canonical single-root recipe, so the check
# runs on the tree under test. The baseline MUST be the real sealed v6
# baseline, supplied via MYHAWLER_BASELINE_ARCHIVE/MYHAWLER_BASELINE_SHA256:
# a synthetic stand-in cannot exist, because DELETE_FILES.txt declares a
# `.backup-…` member the deletion contract requires IN the baseline while
# the archive auditor waives that name only for the sealed archive's exact
# SHA-256 — both gates are correct, and this check refuses to weaken either.

set -Eeuo pipefail

fail() { echo "FAIL: $*" >&2; exit 1; }

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
WORK=""
SOURCE_ARCHIVE=""
SOURCE_SHA256_FILE=""

while [ $# -gt 0 ]; do
    case "$1" in
        --work)           WORK="$2"; shift 2 ;;
        --source-archive) SOURCE_ARCHIVE="$2"; shift 2 ;;
        --source-sha256)  SOURCE_SHA256_FILE="$2"; shift 2 ;;
        *) fail "unknown argument: $1" ;;
    esac
done

[ -n "$WORK" ] || fail "--work DIR is required"
mkdir -p "$WORK"
WORK="$(cd "$WORK" && pwd)"

# ---------------------------------------------------------------- source
if [ -z "$SOURCE_ARCHIVE" ]; then
    echo "== staging the current tree (fixed point required)"
    php "$ROOT/scripts/release/stage_tree.php" \
        --project-root="$ROOT" --stage-dir="$WORK/stage-a" > /dev/null
    php "$ROOT/scripts/release/stage_tree.php" \
        --project-root="$WORK/stage-a" --stage-dir="$WORK/stage-b" > /dev/null
    diff -r "$WORK/stage-a" "$WORK/stage-b" > /dev/null \
        || fail "staging is not a fixed point"

    SOURCE_ARCHIVE="$WORK/myhawler-current-working-tree.zip"
    python3 - "$WORK/stage-a" "$SOURCE_ARCHIVE" <<'PY'
import os, sys, zipfile
stage, out = sys.argv[1], sys.argv[2]
entries = [('mulkihawler/', None)]
for root, dirs, files in os.walk(stage):
    dirs.sort(); files.sort()
    rel = os.path.relpath(root, stage)
    for d in dirs:
        entries.append((('mulkihawler/' + d + '/') if rel == '.'
                        else ('mulkihawler/' + rel + '/' + d + '/'), None))
    for f in files:
        entries.append(((('mulkihawler/' + f) if rel == '.'
                         else ('mulkihawler/' + rel + '/' + f)),
                        os.path.join(root, f)))
entries.sort(key=lambda e: e[0])
with zipfile.ZipFile(out, 'w', zipfile.ZIP_DEFLATED) as z:
    for arc, path in entries:
        if path is None:
            src = stage if arc == 'mulkihawler/' \
                else os.path.join(stage, arc[len('mulkihawler/'):].rstrip('/'))
            z.write(src, arc)
        else:
            z.write(path, arc)
PY
    SOURCE_SHA256_FILE="$WORK/myhawler-current-working-tree.zip.sha256"
    ( cd "$WORK" && sha256sum "$(basename "$SOURCE_ARCHIVE")" > "$SOURCE_SHA256_FILE" )
fi
[ -f "$SOURCE_ARCHIVE" ] || fail "source archive not found: $SOURCE_ARCHIVE"
[ -f "$SOURCE_SHA256_FILE" ] || fail "source sha256 file not found: $SOURCE_SHA256_FILE"

# --------------------------------------------------------------- baseline
[ -n "${MYHAWLER_BASELINE_ARCHIVE:-}" ] \
    || fail "MYHAWLER_BASELINE_ARCHIVE must point at the sealed v6 baseline (see header)"
[ -n "${MYHAWLER_BASELINE_SHA256:-}" ] \
    || fail "MYHAWLER_BASELINE_SHA256 must carry the sealed baseline's SHA-256"
[ -f "$MYHAWLER_BASELINE_ARCHIVE" ] \
    || fail "baseline archive not found: $MYHAWLER_BASELINE_ARCHIVE"

# ------------------------------------------------------------- two cycles
for run in a b; do
    echo "== full offline release cycle: run $run"
    rm -rf "$WORK/run-$run"
    bash "$ROOT/scripts/release/run_final_release_ci.sh" \
        --source-archive "$SOURCE_ARCHIVE" \
        --source-sha256 "$SOURCE_SHA256_FILE" \
        --work "$WORK/run-$run" --offline-stub \
        > "$WORK/run-$run.log" 2>&1 \
        || { tail -40 "$WORK/run-$run.log"; fail "release cycle run $run failed"; }
done

# ------------------------------------------------------------- comparison
python3 - "$WORK/run-a/delivery" "$WORK/run-b/delivery" <<'PY'
import hashlib, sys
from pathlib import Path

a, b = Path(sys.argv[1]), Path(sys.argv[2])
NAME = 'myhawler-account-first-registration'

REPRODUCIBLE = {
    'CHANGED_FILES.md', 'CHANGED_FILES.md.sha256',
    'DELETE_FILES.txt', 'DELETE_FILES.txt.sha256',
    'DEPLOYMENT_NOTES.md', 'DEPLOYMENT_NOTES.md.sha256',
    'FIX_REPORT.md', 'FIX_REPORT.md.sha256',
    'FULL_SOURCE_MANIFEST.sha256',
    'FULL_SOURCE_MANIFEST.txt', 'FULL_SOURCE_MANIFEST.txt.sha256',
    'ROLLBACK_NOTES.md', 'ROLLBACK_NOTES.md.sha256',
    'RUNTIME_MANIFEST.txt', 'RUNTIME_MANIFEST.txt.sha256',
    'SECURITY_REVIEW.md', 'SECURITY_REVIEW.md.sha256',
    'TREE_MANIFEST.sha256', 'TREE_MANIFEST.txt', 'TREE_MANIFEST.txt.sha256',
    'audit_archive.py', 'audit_archive.py.sha256',
    'release_gates.py', 'release_gates.py.sha256',
    'validate_command_ledger.py', 'validate_command_ledger.py.sha256',
    'verify_final_delivery.py', 'verify_final_delivery.py.sha256',
    f'{NAME}-FULL-SOURCE.zip', f'{NAME}-FULL-SOURCE.zip.sha256',
    f'{NAME}-SOURCE-PATCH.zip', f'{NAME}-SOURCE-PATCH.zip.sha256',
    f'{NAME}-corrected-runtime.zip', f'{NAME}-corrected-runtime.zip.sha256',
}

# Truthful per-run evidence: the ledger's real times, the sealed evidence
# of THIS run, the indexes and master that bind those hashes, and
# TEST_RESULTS.md whose duration column is genuine measurement.
OPERATIONAL = {
    'TEST_RESULTS.md', 'TEST_RESULTS.md.sha256',
    'command-ledger.json', 'command-ledger.json.sha256',
    'evidence-index.json', 'evidence-index.json.sha256',
    'release-index.json', 'release-index.json.sha256',
    f'{NAME}-FINAL-DELIVERY.zip', f'{NAME}-FINAL-DELIVERY.zip.sha256',
    f'{NAME}-evidence.zip', f'{NAME}-evidence.zip.sha256',
}

def sha(p: Path) -> str:
    return hashlib.sha256(p.read_bytes()).hexdigest()

problems = []
names_a = {p.name for p in a.iterdir() if p.is_file()}
names_b = {p.name for p in b.iterdir() if p.is_file()}

for only, run in ((names_a - names_b, 'a'), (names_b - names_a, 'b')):
    for name in sorted(only):
        problems.append(f'{name}: present only in run {run}')

for name in sorted(names_a & names_b):
    if name in REPRODUCIBLE:
        if sha(a / name) != sha(b / name):
            problems.append(f'{name}: REPRODUCIBLE artifact differs between runs')
    elif name not in OPERATIONAL:
        problems.append(f'{name}: not classified as reproducible or operational '
                        f'— classify it deliberately')

for name in sorted(REPRODUCIBLE | OPERATIONAL):
    if name not in names_a:
        problems.append(f'{name}: classified but absent from the delivery')

identical = sum(1 for n in sorted(names_a & names_b)
                if n in REPRODUCIBLE and sha(a / n) == sha(b / n))
print(f'reproducible artifacts byte-identical: {identical}/{len(REPRODUCIBLE)}')

if problems:
    print(f'DELIVERY DETERMINISM FAILED — {len(problems)} problem(s)')
    for p in problems:
        print('  FAIL ', p)
    sys.exit(1)
print('DELIVERY DETERMINISM PASSED — two clean packaging runs, '
      'every reproducible artifact byte-identical')
PY
