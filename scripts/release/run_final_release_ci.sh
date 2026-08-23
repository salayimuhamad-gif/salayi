#!/usr/bin/env bash
#
# MyHawler v7 — final release cycle.
#
# This script IS the finalization job. GitHub Actions only supplies an
# environment and calls it, so the identical cycle runs on a VPS or a
# self-hosted runner.
#
# Two invariants shape everything below.
#
#   1. THE SOURCE IS FROZEN ONCE AND NEVER WRITTEN TO AGAIN. Every log, report,
#      index and archive goes to an external directory. The final step
#      re-derives the tree manifest and requires it to equal the freeze hash.
#
#   2. EVERY GATE IS EXECUTED THROUGH record_command.py, which captures real
#      start time, finish time, duration, exit code and raw output as the
#      command runs. Nothing is reconstructed afterwards from log files — an
#      earlier design did exactly that and manufactured `exit_code: 0` for
#      commands it never executed.
#
# Gate identities come from scripts/release/release_gates.py. There is no second
# list; a label the registry does not define is a hard error inside the recorder.
#
# Usage:
#   run_final_release_ci.sh --source-archive PATH --source-sha256 PATH
#                           [--work DIR] [--baseline-commit SHA]
#                           [--offline-stub] [--min-files N]
#
# --offline-stub runs the SAME orchestration with external toolchain commands
# (composer, npm, artisan, playwright, the rehearsals) replaced by stubs. The
# freeze, recorder, builders, indexes, packaging and verification are all real,
# so the orchestration can be proved without PHP extensions, MariaDB or
# Chromium. It is not a release mode and says so in its own output.

set -Eeuo pipefail

trap 'rc=$?; echo ""; echo "FINAL RELEASE FAILED at line ${LINENO} (exit ${rc})"; exit "${rc}"' ERR

STEP=0
step() { STEP=$((STEP + 1)); echo ""; echo "=== ${STEP}. $* ==="; }
fail() { echo "FAIL: $*" >&2; exit 1; }

# Canonicalise a path against the CURRENT working directory.
#
# Every external input is put through this once, at startup, because almost
# nothing in this script runs where the script was invoked. record() executes
# each gate through record_command.py with `--cwd "$SOURCE"`, other steps run
# inside disposable workspaces, and the rehearsals run inside a staged site. A
# relative input therefore means one thing at parse time and something else
# entirely by the time a gate receives it.
#
# Final release run #2 failed on exactly that. The workflow passes
# `--source-archive release-input/myhawler-current-working-tree.zip`; the
# recorded source-archive-audit resolved it against the extracted source and
# reported "not a readable ZIP ... No such file or directory". The gate was
# real and doing its job — it was handed a path that no longer pointed at
# anything. Resolving once, here, is what makes every later use correct by
# construction rather than by luck about which directory a step happens to run
# in. `abspath` rather than `realpath`: symlinked inputs stay the path the
# caller named, they just stop being relative.
absolute() {
    python3 -c 'import os,sys; print(os.path.abspath(os.path.expanduser(sys.argv[1])))' "$1"
}

SOURCE_ARCHIVE=""
SOURCE_SHA256_FILE=""
WORK="${WORK:-$(pwd)/final-release-run}"
BASELINE_COMMIT="${BASELINE_COMMIT:-9c0188f81843cfe4786b7f72ecdc2a3fae89cd82}"
OFFLINE_STUB=0
MIN_FILES=""

while [ $# -gt 0 ]; do
    case "$1" in
        --source-archive)  SOURCE_ARCHIVE="$2"; shift 2 ;;
        --source-sha256)   SOURCE_SHA256_FILE="$2"; shift 2 ;;
        --work)            WORK="$2"; shift 2 ;;
        --baseline-commit) BASELINE_COMMIT="$2"; shift 2 ;;
        --min-files)       MIN_FILES="$2"; shift 2 ;;
        --offline-stub)    OFFLINE_STUB=1; shift ;;
        *) fail "unknown argument: $1" ;;
    esac
done

[ -n "$SOURCE_ARCHIVE" ]     || fail "--source-archive is required"
[ -n "$SOURCE_SHA256_FILE" ] || fail "--source-sha256 is required"

BASELINE_ARCHIVE="${MYHAWLER_BASELINE_ARCHIVE:?set MYHAWLER_BASELINE_ARCHIVE}"
BASELINE_SHA256="${MYHAWLER_BASELINE_SHA256:?set MYHAWLER_BASELINE_SHA256}"

# Resolved BEFORE the existence checks below, so both the checks and every
# later use see the same absolute path — and so a failure names the path that
# was actually looked for rather than a relative fragment.
SOURCE_ARCHIVE=$(absolute "$SOURCE_ARCHIVE")
SOURCE_SHA256_FILE=$(absolute "$SOURCE_SHA256_FILE")
BASELINE_ARCHIVE=$(absolute "$BASELINE_ARCHIVE")

[ -f "$SOURCE_ARCHIVE" ]     || fail "source archive not found: $SOURCE_ARCHIVE"
[ -f "$SOURCE_SHA256_FILE" ] || fail "detached checksum not found: $SOURCE_SHA256_FILE"
[ -f "$BASELINE_ARCHIVE" ]   || fail "baseline archive not found: $BASELINE_ARCHIVE"

# TWO SETS OF DATABASE CREDENTIALS, AND THEY MUST NOT SHARE NAMES.
#
# The administrative account creates databases, creates the test user and
# grants it privileges. The test/application account is what Laravel connects
# as. They are different identities with different passwords, and the runner
# needs both alive at the same time.
#
# They used to collide. The administrative password lived in DB_PASSWORD, and
# the runtime section later ran a GLOBAL `export ... DB_PASSWORD=...` to give
# the recorded gates their Laravel environment — DB_PASSWORD being the name
# Laravel requires. That silently overwrote the administrative password with
# the test user's for the rest of the run, so the next administrative command
# (the browser database preparation) connected as root with the test password:
#
#   ERROR 1045 (28000): Access denied for user 'root'@'172.18.0.1'
#
# Everything before the export worked, which is what made it look like a
# MariaDB or networking problem rather than a shell-variable one.
#
# The administrative credentials therefore live under names Laravel will never
# claim, and are marked readonly: a future `export DB_PASSWORD=` cannot touch
# them, and any attempt to reassign these aborts the run instead of quietly
# changing which account the next GRANT authenticates as. They are deliberately
# NOT exported — administrative credentials have no business in the environment
# of every recorded gate.
if [ "$OFFLINE_STUB" = "0" ]; then
    ADMIN_DB_PASSWORD="${MYHAWLER_DB_PASSWORD:?set MYHAWLER_DB_PASSWORD}"
    APP_KEY_VALUE="${MYHAWLER_APP_KEY:?set MYHAWLER_APP_KEY}"
else
    ADMIN_DB_PASSWORD="${MYHAWLER_DB_PASSWORD:-stub}"
    APP_KEY_VALUE="${MYHAWLER_APP_KEY:-base64:stub}"
fi

ADMIN_DB_USER="${MYHAWLER_DB_USER:-myhawler}"
readonly ADMIN_DB_USER ADMIN_DB_PASSWORD

DB_HOST="${MYHAWLER_DB_HOST:-127.0.0.1}"
DB_PORT="${MYHAWLER_DB_PORT:-3306}"
DB_TEST="${MYHAWLER_DB_TEST:-myh_test}"
DB_REHEARSE="${MYHAWLER_DB_REHEARSE:-myh_rehearse}"

PHP_BIN="${PHP_BIN:-php}"
MYSQL_BIN="${MYSQL_BIN:-mariadb}"

WORK=$(absolute "$WORK")

case "$WORK" in
    /|/root|/home|/usr|/etc|/var|/tmp|/bin|/sbin|/lib|/opt|/boot|/dev|/proc|/sys)
        fail "refusing to use $WORK as a work directory" ;;
esac
[ "$WORK" != "$HOME" ] || fail "refusing to use the home directory as a work directory"
[ "$(dirname "$WORK")" != "/" ] || fail "refusing a top-level work directory: $WORK"

ARCHIVE_PARENT=$(cd "$(dirname "$SOURCE_ARCHIVE")" && pwd)
[ "$WORK" != "$ARCHIVE_PARENT" ] || fail "the work directory is the source archive directory"

if [ -e "$WORK" ] && [ ! -f "$WORK/.myhawler-work" ]; then
    [ -z "$(ls -A "$WORK" 2>/dev/null)" ] \
        || fail "$WORK exists, is not empty, and was not created by this script"
fi

SOURCE="$WORK/source"
STAGE="$WORK/staged"
EVIDENCE="$WORK/evidence"
# The documentation gates need a COLLECTED evidence document and the reports
# generated from it. Both share filenames with the authoritative release
# evidence, so they are collected into their own directory OUTSIDE $EVIDENCE —
# see the collection step for what would collide otherwise.
DOC_EVIDENCE="$WORK/documentation-evidence"
DELIVERY="$WORK/delivery"
CLEANROOM="$WORK/cleanroom"
REHEARSAL="$WORK/rehearsal"
RUNTIME_DIR="$WORK/runtime"
RUNTIME_BASE="$WORK/myhawler-account-first-registration-corrected-runtime"
TRACE="$WORK/gate-trace.txt"
LEDGER="$EVIDENCE/command-ledger.json"

rm -rf "$WORK"
mkdir -p "$SOURCE" "$EVIDENCE" "$DELIVERY" "$CLEANROOM" "$REHEARSAL"
: > "$WORK/.myhawler-work"
: > "$TRACE"

echo "MyHawler v7 final release cycle"
echo "work directory: $WORK"
[ "$OFFLINE_STUB" = "1" ] && echo "MODE: offline stub (orchestration only; NOT a release)"

FROZEN=""

# Every gate goes through here. The trace records order and label so a test can
# compare the real execution sequence against the canonical registry rather than
# trusting a description of it.
record() {
    local label="$1"; shift
    local server=""
    if [ "$1" = "--server" ]; then server="$2"; shift 2; fi

    echo "$label" >> "$TRACE"

    if [ -n "$server" ]; then
        python3 "$SOURCE/scripts/release/record_command.py" \
            --ledger "$LEDGER" --evidence-dir "$EVIDENCE" \
            --tree-manifest-sha256 "$FROZEN" --label "$label" \
            --server-configuration "$server" --cwd "$SOURCE" -- "$@"
    else
        python3 "$SOURCE/scripts/release/record_command.py" \
            --ledger "$LEDGER" --evidence-dir "$EVIDENCE" \
            --tree-manifest-sha256 "$FROZEN" --label "$label" \
            --cwd "$SOURCE" -- "$@"
    fi
}

# In stub mode the external toolchain is replaced, but the gate is still really
# executed and really recorded — the stub IS the command, and the ledger says so.
stub_or() {
    if [ "$OFFLINE_STUB" = "1" ]; then
        printf 'bash\n-c\noffline-stub gate\n'
    else
        printf '%s\n' "$@"
    fi
}

# Same recorder, executed in a different working directory. Used for gates that
# must not run inside the frozen source.
record_in() {
    local cwd="$1"; shift
    local label="$1"; shift

    echo "$label" >> "$TRACE"

    if [ "$OFFLINE_STUB" = "1" ]; then
        set -- bash -c "echo 'offline-stub: $label'"
    fi

    python3 "$SOURCE/scripts/release/record_command.py" \
        --ledger "$LEDGER" --evidence-dir "$EVIDENCE" \
        --tree-manifest-sha256 "$FROZEN" --label "$label" \
        --cwd "$cwd" -- "$@"
}

# Same recorder, in a chosen directory, with DB_* explicitly passed through.
record_in_db() {
    local cwd="$1"; shift
    local label="$1"; shift

    echo "$label" >> "$TRACE"

    if [ "$OFFLINE_STUB" = "1" ]; then
        set -- bash -c "echo 'offline-stub: $label'"
    fi

    python3 "$SOURCE/scripts/release/record_command.py" \
        --ledger "$LEDGER" --evidence-dir "$EVIDENCE" \
        --tree-manifest-sha256 "$FROZEN" --label "$label" \
        --database --cwd "$cwd" -- "$@"
}

# A test-only .env for a disposable workspace. Never written into the frozen
# source: the secret scan forbids a .env there, and the tree must not change.
write_test_env() {
    local workspace="$1"
    local database="$2"

    cat > "$workspace/.env" <<ENVEOF
APP_ENV=testing
APP_KEY=$APP_KEY_VALUE
APP_DEBUG=true
APP_URL=$BROWSER_ORIGIN
MULKIHAWLER_INSTALLED=true
MULKIHAWLER_FORCE_HTTPS=false
SESSION_SECURE_COOKIE=false
SESSION_DRIVER=database
QUEUE_CONNECTION=database
CACHE_STORE=database
DB_CONNECTION=mysql
DB_HOST=$DB_HOST
DB_PORT=$DB_PORT
DB_DATABASE=$database
DB_USERNAME=$TEST_DB_USER
DB_PASSWORD=$TEST_DB_PASSWORD
TELEGRAM_BOT_USERNAME=$TELEGRAM_BOT_USERNAME
TELEGRAM_WEBHOOK_SECRET=$TELEGRAM_WEBHOOK_SECRET
MULKIHAWLER_BLIND_INDEX_KEY=$BLIND_INDEX_KEY
MULKIHAWLER_PII_KEY=$PII_KEY
ENVEOF
}

# A gate that must run in a FRESH environment, not the workflow's.
#
# Both rehearsals model an operator on a real host: one deploying a patch, one
# recovering a broken site. Neither has this run's environment, and inheriting
# it proves the procedure works only where it is never needed.
#
# --clean-env IS that guarantee: the recorder strips the child to
# PATH/HOME/LANG/TERM plus REHEARSAL_*, exactly as `env -i` would, and records
# the allow-list it applied in the ledger. Secrets reach the child only through
# the environment, never as argv — an earlier revision spelled the REHEARSAL_*
# assignments out as an `env -i` argv, which wrote REHEARSAL_DB_PASSWORD's
# literal value into the shipped ledger once the recorder began storing exact
# argv.
#
# This exists as a helper because the deployment rehearsal did NOT have it.
# Only the rollback did, and the difference was invisible at the call site: one
# read `record_tool deployment-rehearsal ...`, the other a raw record_command.py
# invocation twenty lines below. Final release run #4 failed on exactly that
# gap. Routing both through one function makes the isolation contract a
# property of the helper rather than of whoever last edited the call.
record_isolated() {
    local label="$1"; shift
    local server="$1"; shift

    if [ "$OFFLINE_STUB" = "1" ]; then
        record "$label" --server "$server" bash -c "echo 'offline-stub: $label'"
        return
    fi

    echo "$label" >> "$TRACE"

    python3 "$SOURCE/scripts/release/record_command.py" \
        --ledger "$LEDGER" --evidence-dir "$EVIDENCE" \
        --tree-manifest-sha256 "$FROZEN" --label "$label" \
        --server-configuration "$server" \
        --clean-env --database --cwd "$SOURCE" -- "$@"
}

record_tool() {
    local label="$1"; shift
    local server=""
    if [ "$1" = "--server" ]; then server="$2"; shift 2; fi

    if [ "$OFFLINE_STUB" = "1" ]; then
        if [ -n "$server" ]; then
            record "$label" --server "$server" bash -c "echo 'offline-stub: $label'"
        else
            record "$label" bash -c "echo 'offline-stub: $label'"
        fi
    else
        if [ -n "$server" ]; then
            record "$label" --server "$server" "$@"
        else
            record "$label" "$@"
        fi
    fi
}

step "Verify the source and baseline archives"

# Read from its absolute path, but CHECKED from the archive's directory: the
# detached file names the archive by bare filename, so that is where the name
# inside it has to resolve. Taking the checksum file by absolute path rather
# than by basename means it no longer has to sit beside the archive.
( cd "$ARCHIVE_PARENT" && sha256sum -c "$SOURCE_SHA256_FILE" ) \
    || fail "the supplied source archive does not match its detached checksum"

ACTUAL_BASELINE=$(sha256sum "$BASELINE_ARCHIVE" | cut -d' ' -f1)
[ "$ACTUAL_BASELINE" = "$BASELINE_SHA256" ] \
    || fail "baseline archive hash mismatch: expected $BASELINE_SHA256, got $ACTUAL_BASELINE"
echo "$ACTUAL_BASELINE" > "$EVIDENCE/BASELINE_ARCHIVE_SHA256"
sha256sum "$SOURCE_ARCHIVE" | cut -d' ' -f1 > "$EVIDENCE/SOURCE_ARCHIVE_SHA256"

# SOURCE_DATE_EPOCH — the deterministic timestamp for everything this run
# packages, derived from the VERIFIED INPUT rather than the wall clock: the
# newest member timestamp inside the source archive. Two runs from the same
# frozen input therefore stamp identical times into every product archive
# and generated document, which is what makes the delivery byte-for-byte
# reproducible. Same variable, same semantics as scripts/package-release.sh
# (there it comes from the commit date; a verified release input has no git,
# so its own newest member is the honest equivalent). Operational evidence —
# the command ledger, gate logs, environment capture — deliberately keeps
# REAL wall-clock times: those record what happened during THIS run, and
# invariant 2 above forbids reconstructing them.
SOURCE_DATE_EPOCH=$(python3 - "$SOURCE_ARCHIVE" <<'PY'
import sys, zipfile, datetime
newest = max(i.date_time for i in zipfile.ZipFile(sys.argv[1]).infolist())
print(int(datetime.datetime(*newest, tzinfo=datetime.timezone.utc).timestamp()))
PY
)
export SOURCE_DATE_EPOCH
echo "SOURCE_DATE_EPOCH: $SOURCE_DATE_EPOCH ($(date -u -d "@$SOURCE_DATE_EPOCH" +'%Y-%m-%dT%H:%M:%SZ'))"
echo "$SOURCE_DATE_EPOCH" > "$EVIDENCE/SOURCE_DATE_EPOCH"

step "Audit both archives before extraction, then extract"

BOOTSTRAP_AUDIT="$WORK/audit_archive.bootstrap.py"
unzip -p "$SOURCE_ARCHIVE" '*/scripts/release/audit_archive.py' > "$BOOTSTRAP_AUDIT" 2>/dev/null \
    || unzip -p "$SOURCE_ARCHIVE" 'scripts/release/audit_archive.py' > "$BOOTSTRAP_AUDIT"
[ -s "$BOOTSTRAP_AUDIT" ] || fail "the source archive carries no archive auditor"

python3 "$BOOTSTRAP_AUDIT" "$SOURCE_ARCHIVE" "$BASELINE_ARCHIVE" \
    > "$EVIDENCE/pre-extraction-audit.log" 2>&1 \
    || { cat "$EVIDENCE/pre-extraction-audit.log"; fail "an input archive failed its safety audit"; }

unzip -q "$SOURCE_ARCHIVE" -d "$SOURCE"

if [ ! -f "$SOURCE/artisan" ]; then
    inner=$(find "$SOURCE" -maxdepth 2 -name artisan -printf '%h\n' | head -1)
    [ -n "$inner" ] || fail "no artisan found in the extracted archive"
    if [ "$inner" != "$SOURCE" ]; then
        mv "$inner" "$SOURCE.inner" && rm -rf "$SOURCE" && mv "$SOURCE.inner" "$SOURCE"
    fi
fi
[ -f "$SOURCE/artisan" ] || fail "extraction did not produce an application tree"

RUNNER_SELF=$(readlink -f "${BASH_SOURCE[0]}")
RUNNER_IN_SOURCE="$SOURCE/scripts/release/run_final_release_ci.sh"
[ -f "$RUNNER_IN_SOURCE" ] || fail "the verified source carries no runner script"
SELF_HASH=$(sha256sum "$RUNNER_SELF" | cut -d' ' -f1)
SOURCE_HASH=$(sha256sum "$RUNNER_IN_SOURCE" | cut -d' ' -f1)
[ "$SELF_HASH" = "$SOURCE_HASH" ] \
    || fail "the running script does not match the verified source ($SELF_HASH vs $SOURCE_HASH)"
echo "$SELF_HASH" > "$EVIDENCE/RUNNER_SCRIPT_SHA256"

step "Install dependencies BEFORE the freeze"

# `npm ci` deletes and recreates node_modules, which needs write permission on
# the source root. It therefore runs here and never after the freeze.
if [ "$OFFLINE_STUB" = "0" ]; then
    ( cd "$SOURCE" && composer install --no-interaction --no-progress --no-scripts )
    ( cd "$SOURCE" && npm ci --no-audit --no-fund )
    ( cd "$SOURCE" && npx playwright install chromium )
else
    echo "offline stub: skipping composer, npm and browser installation"
fi

mkdir -p "$SOURCE/bootstrap/cache" "$SOURCE/storage/framework/views" \
         "$SOURCE/storage/framework/cache/data" "$SOURCE/storage/framework/sessions" \
         "$SOURCE/storage/logs" "$SOURCE/.phpunit.cache"

step "Stage the eligible tree and freeze one exact identity"

"$PHP_BIN" "$SOURCE/scripts/release/stage_tree.php" \
    --project-root="$SOURCE" --stage-dir="$STAGE" >/dev/null
FROZEN=$(cut -d' ' -f1 "$STAGE/TREE_MANIFEST.sha256")
cp "$STAGE/TREE_MANIFEST.txt" "$STAGE/TREE_MANIFEST.sha256" "$STAGE/SHA256SUMS.txt" "$SOURCE/"

"$PHP_BIN" "$SOURCE/scripts/release/stage_tree.php" \
    --project-root="$SOURCE" --stage-dir="$STAGE.confirm" >/dev/null
CONFIRM=$(cut -d' ' -f1 "$STAGE.confirm/TREE_MANIFEST.sha256")
[ "$FROZEN" = "$CONFIRM" ] || fail "staging is not a fixed point: $FROZEN vs $CONFIRM"

echo "FROZEN TREE: $FROZEN"
echo "$FROZEN" > "$EVIDENCE/FROZEN_TREE_SHA256"

step "Verify manifest and eligible-file parity in both directions"

"$PHP_BIN" -r '
require $argv[1]."/scripts/support/SourceIdentity.php";
use Mulkihawler\Tooling\SourceIdentity;
$stage = $argv[2];
$eligible = SourceIdentity::eligibleFiles($stage); sort($eligible);
$listed = [];
foreach (explode("\n", trim(file_get_contents($stage."/TREE_MANIFEST.txt"))) as $line) {
    if ($line !== "") { $listed[] = explode("  ", $line, 2)[1]; }
}
sort($listed);
printf("eligible=%d listed=%d missing=%d extra=%d\n", count($eligible), count($listed),
    count(array_diff($eligible, $listed)), count(array_diff($listed, $eligible)));
if (array_diff($eligible, $listed) !== [] || array_diff($listed, $eligible) !== []) { exit(1); }
' "$SOURCE" "$STAGE" || fail "the manifest and the eligible file set disagree"

step "Make the frozen source read-only where practical"

chmod -R a-w "$SOURCE" 2>/dev/null || true
chmod -R u+w "$SOURCE/storage" "$SOURCE/bootstrap/cache" "$SOURCE/.phpunit.cache" 2>/dev/null || true
chmod -R u+w "$SOURCE/node_modules" "$SOURCE/vendor" 2>/dev/null || true

step "Build the PRODUCTION dependency tree from the frozen lock"

# The dev vendor above serves the gates; production ships a DIFFERENT tree:
# installed --no-dev from the frozen composer.lock, so the exact dependency
# bytes the site will run travel inside the runtime artifact and no Composer
# or network access is ever needed on the production host. Installed into a
# full copy of the frozen stage so --optimize-autoloader can classmap the
# application's own namespaces, and with --no-scripts: the only Laravel hook
# (post-autoload-dump -> artisan package:discover) writes bootstrap/cache,
# not vendor, and both the runbook and the deployment rehearsal run
# package:discover on the deployed site instead — offline, against the
# shipped vendor.
PROD_DEPS="$WORK/production-deps"
rm -rf "$PROD_DEPS"
mkdir -p "$PROD_DEPS"
cp -a "$STAGE/." "$PROD_DEPS/"
chmod -R u+w "$PROD_DEPS" 2>/dev/null || true

record_tool production-vendor-install --server "Composer 2, --no-dev from the frozen lock" \
    composer install --no-dev --prefer-dist --optimize-autoloader \
    --no-interaction --no-progress --no-scripts --working-dir "$PROD_DEPS"

# BLOCKER 1: phpunit.mariadb.xml connects as a dedicated test user. The service
# container only creates root, so that user must be created and granted here or
# every MariaDB gate fails authentication. One contract, provisioned explicitly.
TEST_DB_USER="${MYHAWLER_TEST_DB_USER:-myh}"
DB_RUNTIME="${MYHAWLER_DB_RUNTIME:-myh_runtime}"
DB_BROWSER="${MYHAWLER_DB_BROWSER:-myh_browser}"
# The Playwright config defaults to 8100; APP_URL, PLAYWRIGHT_BASE_URL and the
# webServer must all be this one origin or the Telegram return buttons point at
# a dead port.
BROWSER_PORT="${MYHAWLER_BROWSER_PORT:-8100}"
BROWSER_ORIGIN="http://127.0.0.1:$BROWSER_PORT"
# The browser spec sends a real webhook using this secret; the application
# refuses a webhook when its configured secret is empty.
TELEGRAM_BOT_USERNAME="${MYHAWLER_TELEGRAM_BOT_USERNAME:-myhawler_browser_bot}"
TELEGRAM_WEBHOOK_SECRET="${MYHAWLER_TELEGRAM_WEBHOOK_SECRET:-browser-webhook-secret}"

# BLOCKER 1: account-first registration calls User::blindIndex() immediately,
# and that method throws when the key is empty — so every browser registration
# scenario failed before reaching the Telegram-link page. Generated fresh per
# run, outside the source tree, and never a production key.
BLIND_INDEX_KEY="${MYHAWLER_BLIND_INDEX_KEY:-$(head -c 32 /dev/urandom | od -An -tx1 | tr -d ' \n')}"
PII_KEY="${MYHAWLER_PII_KEY:-$(head -c 32 /dev/urandom | od -An -tx1 | tr -d ' \n')}"

[ "${#BLIND_INDEX_KEY}" -ge 64 ] || fail "the disposable blind-index key is too short"
TEST_DB_PASSWORD="${MYHAWLER_TEST_DB_PASSWORD:-ci}"

if [ "$OFFLINE_STUB" = "0" ]; then
    "$MYSQL_BIN" -h "$DB_HOST" -P "$DB_PORT" -u "$ADMIN_DB_USER" -p"$ADMIN_DB_PASSWORD" -e "
        CREATE DATABASE IF NOT EXISTS \`$DB_TEST\`
            CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
        CREATE DATABASE IF NOT EXISTS \`$DB_REHEARSE\`
            CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
        CREATE USER IF NOT EXISTS '$TEST_DB_USER'@'%' IDENTIFIED BY '$TEST_DB_PASSWORD';
        CREATE USER IF NOT EXISTS '$TEST_DB_USER'@'localhost' IDENTIFIED BY '$TEST_DB_PASSWORD';
        GRANT ALL PRIVILEGES ON \`$DB_TEST\`.* TO '$TEST_DB_USER'@'%';
        GRANT ALL PRIVILEGES ON \`$DB_TEST\`.* TO '$TEST_DB_USER'@'localhost';
        GRANT ALL PRIVILEGES ON \`$DB_REHEARSE\`.* TO '$TEST_DB_USER'@'%';
        GRANT ALL PRIVILEGES ON \`$DB_REHEARSE\`.* TO '$TEST_DB_USER'@'localhost';
        FLUSH PRIVILEGES;"

    # Prove the credentials PHPUnit will use actually work, before the gates run.
    "$MYSQL_BIN" -h "$DB_HOST" -P "$DB_PORT" -u "$TEST_DB_USER" -p"$TEST_DB_PASSWORD" \
        -D "$DB_TEST" -e "SELECT 1;" > /dev/null \
        || fail "the PHPUnit MariaDB user '$TEST_DB_USER' cannot connect to $DB_TEST"
    echo "MariaDB test user verified: $TEST_DB_USER@$DB_HOST/$DB_TEST"
fi

export APP_KEY="$APP_KEY_VALUE"

step "Record backend, frontend, guard and runtime-check gates"

record_tool focused-sqlite vendor/bin/phpunit --filter RegistrationTelegramFlow
record_tool full-sqlite vendor/bin/phpunit
record_tool focused-mariadb --server "MariaDB 10.11" \
    vendor/bin/phpunit -c phpunit.mariadb.xml --filter RegistrationTelegramFlow
record_tool full-mariadb --server "MariaDB 10.11" vendor/bin/phpunit -c phpunit.mariadb.xml
record_tool handoff-concurrency-mariadb --server "MariaDB 10.11, two concurrent processes" \
    vendor/bin/phpunit -c phpunit.mariadb.xml --filter TelegramReturnHandoffConcurrency
record_tool phpstan vendor/bin/phpstan analyse --memory-limit=1G --no-progress
record_tool pint vendor/bin/pint --test
record_tool standalone-suite "$PHP_BIN" tests/Standalone/run.php

# BLOCKERS 3 and 4: `npm ci` deletes and recreates node_modules, which needs
# write permission on the PARENT directory, and `npm run build` writes into
# public/build — which is part of the frozen source identity. Both ran under the
# read-only source, so each would either fail outright or silently change the
# authenticated tree and break the final source-unchanged proof.
#
# They now run in a disposable workspace derived from the frozen tree. The built
# assets produced there are compared against the frozen ones, so the gate still
# proves the delivered build is reproducible.
NPM_WORKSPACE="$WORK/npm-gate"
rm -rf "$NPM_WORKSPACE"
mkdir -p "$NPM_WORKSPACE"

if [ "$OFFLINE_STUB" = "0" ]; then
    cp -a "$STAGE/." "$NPM_WORKSPACE/"
    chmod -R u+w "$NPM_WORKSPACE"
fi

record_in "$NPM_WORKSPACE" npm-ci npm ci --no-audit --no-fund
record_in "$NPM_WORKSPACE" typecheck npm run typecheck
record_in "$NPM_WORKSPACE" lint npm run lint
record_in "$NPM_WORKSPACE" build npm run build

# Recorded in both modes so the gate set is identical; record_tool substitutes a
# stub when the toolchain is absent. A gate that exists only in real mode would
# make the stub run structurally different from the one it is meant to model.
record_tool build-reproducibility python3 "$SOURCE/scripts/release/check_parity.py" \
    --mode assets --source "$SOURCE/public/build" --runtime "$NPM_WORKSPACE/public/build"

record_tool security-audit "$PHP_BIN" scripts/security-audit.php
record_tool secret-scan "$PHP_BIN" scripts/secret-scan.php
record_tool language-parity "$PHP_BIN" scripts/lang-parity.php
record_tool language-usage "$PHP_BIN" scripts/lang-usage.php
record_tool migration-guard "$PHP_BIN" scripts/migration-guard.php

# The two documentation gates below read a COLLECTED evidence document and the
# four run-derived reports beside it. Nothing in this run had produced either.
#
# `docs/release-evidence.json` is deliberately gone from the tree — it recorded
# the manifest hash of the tree it lived in, which no amount of re-freezing
# could converge, so EvidencePath moved it outside. The checkers followed it
# out; the runner did not. It called them with no `--evidence-dir=`, so they
# resolved EvidencePath's default (a sibling of the source that this run never
# creates), found nothing, and stopped the release. Passing the flag alone
# would not have helped: the runner's own release-evidence.json is written from
# the COMPLETED ledger hundreds of lines below, and carries a different shape —
# ledger exit codes, not the phpunit totals and gate table these checkers
# compare documents against.
#
# WHERE COLLECTION RUNS, AND WHY NOT IN $SOURCE.
#
# The collector measures gates by running them, and one of them is
# `npm run build`, which writes public/build — part of the frozen authenticated
# tree. Inside $SOURCE that is either a hard failure against the read-only
# permissions set above or, if those were relaxed, a mutation of the very tree
# whose identity the release is binding. Neither is acceptable, so collection
# runs in $NPM_WORKSPACE: a disposable copy of the staged tree that already
# exists for the frontend gates, already carries node_modules and a completed
# production build, and has just been proved byte-identical to the frozen
# assets by build-reproducibility. $SOURCE is neither read from for writes nor
# written to at any point below.
#
# vendor is copied in because the collector runs phpunit, phpstan and pint, and
# the staged tree carries no dependencies. It is an EXCLUDED_DIRS entry, so it
# cannot move the workspace's manifest.
#
# HOW THE IDENTITY STAYS BOUND.
#
# The workspace has no .git, so SourceIdentity would fall to fromManifest and
# refuse outright — a manifest that authenticates itself proves nothing. It is
# therefore bound explicitly to the externally frozen hash: fromManifest
# verifies TREE_MANIFEST.txt against $FROZEN and then verifies the tree against
# the manifest in both directions, so collection can only proceed if the
# workspace IS the frozen tree. `--frozen` then re-derives that manifest after
# every gate has run and refuses if anything moved it — so the whole collection,
# npm build included, is proved not to have changed the frozen content rather
# than assumed not to have.
#
# WHICH EVIDENCE DIRECTORY, AND WHY NOT $EVIDENCE.
#
# The collector writes `release-evidence.json` and `reports/*.md`. Both names
# are already spoken for at the top of $EVIDENCE, by documents with different
# schemas, different producers and different authority:
#
#   $EVIDENCE/release-evidence.json   written LATER by write_release_evidence.py
#                                     from the COMPLETED ledger; the schema-v3
#                                     document the finalizer seals.
#   $EVIDENCE/reports/<four>.md       copied into $DELIVERY after
#                                     generate_release_reports.py has written
#                                     the completed-ledger versions there.
#
# Collecting into $EVIDENCE would therefore have the later authoritative
# document silently overwrite the collector's, and — worse, because it survives
# into the delivery — have the collector's earlier reports overwrite the
# completed-ledger reports through that copy loop. So collection gets its own
# directory and touches neither name.
#
# Not a recorded gate: it produces evidence for gates rather than judging the
# release, the same standing as generate_release_reports.py later. Its own
# verdict still stops the run.
#
# Real mode only, like the other steps that need the installed toolchain. In
# stub mode record_tool replaces the two gates below with a stub, and the
# workspace this needs is never populated.
if [ "$OFFLINE_STUB" = "0" ]; then
    cp -a "$SOURCE/vendor" "$NPM_WORKSPACE/vendor"
    # $SOURCE is read-only and `cp -a` preserves that, so the copy is made
    # writable exactly as the runtime and browser workspaces do after theirs.
    chmod -R u+w "$NPM_WORKSPACE/vendor"

    # Laravel's runtime directories are EXCLUDED_DIRS entries, so stage_tree.php
    # omits them and the workspace inherited none. The collector runs the full
    # suite under phpunit.xml, which points DB_DATABASE at
    # storage/framework/testing.sqlite and caches into .phpunit.cache — without
    # these the suite cannot open its database and the gate fails for a reason
    # that has nothing to do with the release. This is the same set the runner
    # already creates for $SOURCE before its own tests, and for the runtime and
    # browser workspaces before theirs.
    #
    # Being excluded is precisely why creating them is safe: none is eligible
    # for the manifest, so neither the directories nor the sqlite file written
    # into them can move the identity this collection is bound to. `--frozen`
    # re-derives that manifest afterwards and would refuse if they had.
    mkdir -p "$NPM_WORKSPACE/bootstrap/cache" \
             "$NPM_WORKSPACE/storage/framework/views" \
             "$NPM_WORKSPACE/storage/framework/cache/data" \
             "$NPM_WORKSPACE/storage/framework/sessions" \
             "$NPM_WORKSPACE/storage/logs" "$NPM_WORKSPACE/.phpunit.cache"

    ( cd "$NPM_WORKSPACE" && "$PHP_BIN" scripts/collect-release-evidence.php \
        --frozen \
        "--trusted-manifest-sha256=$FROZEN" \
        "--baseline-commit=$BASELINE_COMMIT" \
        "--evidence-dir=$DOC_EVIDENCE" ) \
        || fail "release evidence collection failed; the documentation gates have nothing to check"
fi

# Run against $SOURCE, because what these gates judge is the delivered tree's
# documentation. Neither writes into it: doc-consistency.php only reads, and
# doc-portability.php regenerates into a temporary directory with its own
# scratch evidence directory beside it. Neither resolves SourceIdentity, so
# neither needs the trusted hash.
#
# EvidencePath reads `--evidence-dir=` from a script's OWN argv, so each checker
# is told explicitly rather than left to a default that disagrees with the
# directory the collection above just wrote.
record_tool doc-consistency "$PHP_BIN" scripts/doc-consistency.php \
    "--evidence-dir=$DOC_EVIDENCE"
record_tool doc-portability "$PHP_BIN" scripts/doc-portability.php \
    "--evidence-dir=$DOC_EVIDENCE"

# RETAINED, AFTER the gates have judged it, under a namespace of its own — a
# gate verdict whose input was thrown away cannot be re-checked by anyone.
#
# The document is RENAMED on the way in. verify_final_delivery.py finds the
# authoritative evidence inside the sealed archive with
# `endswith('release-evidence.json')`, and the finalizer writes members in
# sorted order, so a member at `documentation-validation/release-evidence.json`
# would sort first, be selected instead, and fail the delivery against a
# document that never carried a final tree hash. Under a distinct filename the
# ambiguity cannot arise at all, and no verifier has to be loosened to cope
# with it. The reports keep their names: nothing matches those by suffix, and
# the copy loop that fills $DELIVERY reads $EVIDENCE/reports exactly.
if [ "$OFFLINE_STUB" = "0" ]; then
    mkdir -p "$EVIDENCE/documentation-validation"
    cp "$DOC_EVIDENCE/release-evidence.json" \
       "$EVIDENCE/documentation-validation/documentation-evidence.json"

    if [ -d "$DOC_EVIDENCE/reports" ]; then
        cp -a "$DOC_EVIDENCE/reports" "$EVIDENCE/documentation-validation/reports"
    fi

    # Proved here rather than trusted to the naming: nothing anywhere under
    # $EVIDENCE but the authoritative document may answer to its name.
    SHADOWS=$(find "$EVIDENCE" -mindepth 2 -name 'release-evidence.json' | wc -l)
    [ "$SHADOWS" = "0" ] \
        || fail "documentation evidence shadows release-evidence.json ($SHADOWS)"
fi
record_tool frontend-manifest-audit "$PHP_BIN" scripts/frontend-guard.php

# BLOCKERS 2 and 3: these commands need a real database and an application that
# believes it is installed. Previously they ran inside the frozen source with
# DB_* stripped by the recorder, so `queue:work` reached for the framework
# default database and route/middleware checks had no prepared schema.
RUNTIME_WORKSPACE="$WORK/runtime-gate"
rm -rf "$RUNTIME_WORKSPACE"
mkdir -p "$RUNTIME_WORKSPACE"

if [ "$OFFLINE_STUB" = "0" ]; then
    cp -a "$STAGE/." "$RUNTIME_WORKSPACE/"
    cp -a "$SOURCE/vendor" "$RUNTIME_WORKSPACE/vendor"
    chmod -R u+w "$RUNTIME_WORKSPACE"
    mkdir -p "$RUNTIME_WORKSPACE/storage/framework/views" \
             "$RUNTIME_WORKSPACE/storage/framework/cache/data" \
             "$RUNTIME_WORKSPACE/storage/framework/sessions" \
             "$RUNTIME_WORKSPACE/storage/logs" "$RUNTIME_WORKSPACE/bootstrap/cache"

    "$MYSQL_BIN" -h "$DB_HOST" -P "$DB_PORT" -u "$ADMIN_DB_USER" -p"$ADMIN_DB_PASSWORD" -e "
        CREATE DATABASE IF NOT EXISTS \`$DB_RUNTIME\`
            CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
        GRANT ALL PRIVILEGES ON \`$DB_RUNTIME\`.* TO '$TEST_DB_USER'@'%';
        GRANT ALL PRIVILEGES ON \`$DB_RUNTIME\`.* TO '$TEST_DB_USER'@'localhost';
        FLUSH PRIVILEGES;"

    write_test_env "$RUNTIME_WORKSPACE" "$DB_RUNTIME"
    ( cd "$RUNTIME_WORKSPACE" && "$PHP_BIN" artisan migrate --force >/dev/null )
fi

export DB_CONNECTION=mysql DB_HOST="$DB_HOST" DB_PORT="$DB_PORT" \
       DB_DATABASE="$DB_RUNTIME" DB_USERNAME="$TEST_DB_USER" \
       DB_PASSWORD="$TEST_DB_PASSWORD"

record_in_db "$RUNTIME_WORKSPACE" route-check "$PHP_BIN" artisan route:list
# BLOCKER 4: `route:list --columns=middleware` is not a Laravel 12 option, so
# this gate always exited nonzero. The helper uses --json and asserts the gated
# routes carry the middleware they should.
record_tool middleware-check python3 "$SOURCE/scripts/release/check_middleware.py" \
    --project-root "$RUNTIME_WORKSPACE" --php "$PHP_BIN"
record_in_db "$RUNTIME_WORKSPACE" scheduler-check "$PHP_BIN" artisan schedule:list
record_in_db "$RUNTIME_WORKSPACE" queue-check "$PHP_BIN" artisan queue:work \
    --stop-when-empty --max-time=10

record source-archive-audit python3 "$SOURCE/scripts/release/audit_archive.py" "$SOURCE_ARCHIVE"
record baseline-archive-audit python3 "$SOURCE/scripts/release/audit_archive.py" "$BASELINE_ARCHIVE"

step "Prepare the disposable browser workspace, database and fixtures"

# The registry drives which specs the release executes, so the registry and
# the frozen tree must agree exactly — a spec on disk that the registry does
# not name would silently never run in any release browser gate. Stubbed in
# offline mode: the fixture tree deliberately carries only the account-first
# spec.
record_tool browser-spec-closure python3 "$SOURCE/scripts/release/release_gates.py" \
    --verify-spec-closure "$SOURCE/tests/Browser"

# BLOCKER 2: the browser suite had no prepared environment at all. Without a
# migrated dedicated database the administrator, MFA and Advisor scenarios have
# no accounts; without MULKIHAWLER_INSTALLED=true every request redirects into
# the installer; without a webhook secret the application refuses the webhook
# the spec sends. None of this was visible under --offline-stub.
BROWSER_WORKSPACE="$WORK/browser-gate"
rm -rf "$BROWSER_WORKSPACE"
mkdir -p "$BROWSER_WORKSPACE" "$EVIDENCE/browser"

if [ "$OFFLINE_STUB" = "0" ]; then
    cp -a "$STAGE/." "$BROWSER_WORKSPACE/"
    cp -a "$SOURCE/vendor" "$BROWSER_WORKSPACE/vendor"
    cp -a "$NPM_WORKSPACE/node_modules" "$BROWSER_WORKSPACE/node_modules"
    cp -a "$SOURCE/public/build" "$BROWSER_WORKSPACE/public/build"
    chmod -R u+w "$BROWSER_WORKSPACE"
    mkdir -p "$BROWSER_WORKSPACE/storage/framework/views" \
             "$BROWSER_WORKSPACE/storage/framework/cache/data" \
             "$BROWSER_WORKSPACE/storage/framework/sessions" \
             "$BROWSER_WORKSPACE/storage/logs" "$BROWSER_WORKSPACE/bootstrap/cache"

    "$MYSQL_BIN" -h "$DB_HOST" -P "$DB_PORT" -u "$ADMIN_DB_USER" -p"$ADMIN_DB_PASSWORD" -e "
        DROP DATABASE IF EXISTS \`$DB_BROWSER\`;
        CREATE DATABASE \`$DB_BROWSER\`
            CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
        GRANT ALL PRIVILEGES ON \`$DB_BROWSER\`.* TO '$TEST_DB_USER'@'%';
        GRANT ALL PRIVILEGES ON \`$DB_BROWSER\`.* TO '$TEST_DB_USER'@'localhost';
        FLUSH PRIVILEGES;"

    write_test_env "$BROWSER_WORKSPACE" "$DB_BROWSER"
fi

export DB_DATABASE="$DB_BROWSER"

record_in_db "$BROWSER_WORKSPACE" browser-database-prepare \
    "$PHP_BIN" artisan migrate --force

# The seeder writes tests/Browser/support/fixtures.json — a real credential
# file. It lands in the DISPOSABLE workspace, never in the frozen source, and
# staging purges it if it ever appears there.
record_in_db "$BROWSER_WORKSPACE" browser-fixtures-seed \
    "$PHP_BIN" tests/Browser/support/seed-browser-fixtures.php \
    --confirm-disposable-database

if [ "$OFFLINE_STUB" = "0" ]; then
    [ -f "$BROWSER_WORKSPACE/tests/Browser/support/fixtures.json" ] \
        || fail "the browser fixture seeder produced no fixtures.json"
    [ ! -f "$SOURCE/tests/Browser/support/fixtures.json" ] \
        || fail "generated browser credentials appeared inside the frozen source"
fi

step "Record all five Playwright projects and the merged report"

# BLOCKER 5: `--reporter=json,junit` writes to whatever the config names — one
# fixed path, overwritten by each project. The merger looks for five
# project-specific pairs under evidence/browser, so without explicit
# destinations it finds zero usable reports and the exact 5/100 requirement
# fails. Each project now names its own JSON and JUnit output.
for project in mobile-360x800 mobile-390x844 tablet-768x1024 laptop-1366x768 desktop-1440x900; do
    export PLAYWRIGHT_JSON_OUTPUT_NAME="$EVIDENCE/browser/${project}.json"
    export PLAYWRIGHT_JUNIT_OUTPUT_NAME="$EVIDENCE/browser/${project}.xml"
    export PHP_CLI_SERVER_WORKERS=10
    export PLAYWRIGHT_BASE_URL="$BROWSER_ORIGIN"

    # BLOCKER 3: the exact 20-per-project contract describes the account-first
    # spec, not the whole directory. Running everything here made a correct
    # product fail the merge gate.
    record_in_db "$BROWSER_WORKSPACE" "playwright-${project}" \
        npx playwright test tests/Browser/account-first-registration.spec.ts \
        --project="$project" --reporter=list,json,junit
done
unset PLAYWRIGHT_JSON_OUTPUT_NAME PLAYWRIGHT_JUNIT_OUTPUT_NAME

# The remaining browser suite, under its own policy: skips are viewport-driven
# by design (admin and MFA outside desktop, touch-only accessibility checks),
# so this gate forbids failures and flakes rather than demanding an exact count.
#
# BLOCKER 5 (post-seal audit): the suite used to run as
# `--grep-invert "account-first"`, which makes coverage depend on test TITLES
# rather than on the canonical file inventory — a renamed title silently
# changes what runs. The argv is now generated from
# release_gates.PLAYWRIGHT_REMAINING_SPECS, and the generated JSON report is
# validated against that same inventory by merge_playwright --mode remaining.
export PLAYWRIGHT_JSON_OUTPUT_NAME="$EVIDENCE/browser/remaining/remaining.json"
export PLAYWRIGHT_JUNIT_OUTPUT_NAME="$EVIDENCE/browser/remaining/remaining.xml"
mkdir -p "$EVIDENCE/browser/remaining"

mapfile -t REMAINING_SPECS < <(python3 "$SOURCE/scripts/release/release_gates.py" --remaining-specs)
[ "${#REMAINING_SPECS[@]}" -gt 0 ] || fail "the canonical remaining-spec registry is empty"

record_in_db "$BROWSER_WORKSPACE" playwright-remaining-suite \
    npx playwright test "${REMAINING_SPECS[@]}" --reporter=list,json,junit

unset PLAYWRIGHT_JSON_OUTPUT_NAME PLAYWRIGHT_JUNIT_OUTPUT_NAME

# STRIP VCS METADATA BEFORE ANY OF THIS BECOMES EVIDENCE.
#
# Playwright writes its JSON straight into $EVIDENCE/browser, so these files are
# authoritative the moment they land — there is no later copy to clean. On CI it
# captures git information by default and stores the FULL commit message in
# config.metadata.gitCommit; the browser workspace sits under $WORK, inside the
# GitHub checkout, so git discovery reaches that checkout's .git even though the
# verified source is gitless.
#
# Final release #5 sealed a commit message into all five project reports that
# way and was correctly refused by the clean-directory secret scanner. The
# release identifies its source by TREE_MANIFEST.sha256; commit prose is not
# part of that contract and does not belong in the evidence.
#
# Runs over the whole tree, so the five account-first reports and the remaining
# suite are all covered. The recorded merge gates below independently refuse any
# report that still carries VCS metadata, so skipping this cannot pass unnoticed.
python3 "$SOURCE/scripts/release/normalize_browser_report.py" \
    --browser-dir "$EVIDENCE/browser" \
    || fail "browser evidence still carries version-control metadata"

if [ "$OFFLINE_STUB" = "1" ]; then
    record playwright-merge-junit python3 "$SOURCE/scripts/release/merge_playwright.py" \
        --browser-dir "$EVIDENCE/browser" --tree-manifest-sha256 "$FROZEN" \
        --mode account-first --allow-empty
    record playwright-remaining-merge python3 "$SOURCE/scripts/release/merge_playwright.py" \
        --browser-dir "$EVIDENCE/browser/remaining" --tree-manifest-sha256 "$FROZEN" \
        --mode remaining --allow-empty
else
    record playwright-merge-junit python3 "$SOURCE/scripts/release/merge_playwright.py" \
        --browser-dir "$EVIDENCE/browser" --tree-manifest-sha256 "$FROZEN" \
        --mode account-first
    record playwright-remaining-merge python3 "$SOURCE/scripts/release/merge_playwright.py" \
        --browser-dir "$EVIDENCE/browser/remaining" --tree-manifest-sha256 "$FROZEN" \
        --mode remaining
fi

step "Record the runtime build in the Hostinger layout"

# The production vendor ships INSIDE the runtime. In stub mode composer never
# ran and no tree exists, so the argument is added only when the build did.
RUNTIME_VENDOR_ARGS=()
[ -d "$PROD_DEPS/vendor" ] && RUNTIME_VENDOR_ARGS=(--vendor "$PROD_DEPS/vendor")

record runtime-builder python3 "$SOURCE/scripts/release/build_runtime.py" \
    --stage "$STAGE" --source "$SOURCE" --output "$RUNTIME_BASE" --runtime-dir "$RUNTIME_DIR" \
    ${RUNTIME_VENDOR_ARGS[@]+"${RUNTIME_VENDOR_ARGS[@]}"}

record runtime-manifest-audit python3 "$SOURCE/scripts/release/audit_runtime_manifest.py" \
    --build-dir "$RUNTIME_DIR/public_html/build"

# The runtime the operator deploys must carry the EXACT production dependency
# state of the frozen lock: same composer.json and composer.lock bytes, every
# locked package at its locked version and reference, nothing from
# packages-dev, and never the superseded CommonMark production was caught
# holding. Then Composer itself confirms the shipped tree satisfies the
# platform requirements without dev packages.
record_tool dependency-parity python3 "$SOURCE/scripts/release/check_vendor_parity.py" \
    --lock "$STAGE/composer.lock" --composer "$STAGE/composer.json" \
    --runtime-app "$RUNTIME_DIR/application" \
    --forbid league/commonmark=2.8.3

record_tool platform-requirements composer check-platform-reqs --no-dev \
    --working-dir "$RUNTIME_DIR/application"

step "Record both rehearsals against those exact runtime bytes"

mkdir -p "$EVIDENCE/rehearsal"

# BLOCKER 2: both scripts require REHEARSAL_* variables and exit immediately
# without them. The runner exported only MYHAWLER_* and never mapped them, so
# the real deployment rehearsal could not start. The stub path hid this because
# both commands were replaced.
export REHEARSAL_STAGE="$REHEARSAL/stage"
export REHEARSAL_RUNTIME="$RUNTIME_BASE"
export REHEARSAL_BASELINE="$BASELINE_ARCHIVE"
export REHEARSAL_BASELINE_COMMIT="$BASELINE_COMMIT"
export REHEARSAL_EVIDENCE="$EVIDENCE/rehearsal"
export REHEARSAL_PHP="$PHP_BIN"
export REHEARSAL_MYSQL="$MYSQL_BIN"
export REHEARSAL_MYSQLDUMP="${MYSQLDUMP_BIN:-mariadb-dump}"
export REHEARSAL_DB_HOST="$DB_HOST"
export REHEARSAL_DB_PORT="$DB_PORT"
export REHEARSAL_DB_USER="$TEST_DB_USER"
export REHEARSAL_DB_PASSWORD="$TEST_DB_PASSWORD"
export REHEARSAL_DB_NAME="$DB_REHEARSE"
# The BASELINE stand-in only. The staged "before" site needs some vendor to
# boot the v6 baseline, standing in for production's own old tree; the
# deployment overlay then REPLACES it with the vendor shipped inside the
# runtime artifact, and the rehearsal proves the replacement (the dev-only
# markers of this stand-in must be gone after the apply step). Nothing after
# the apply step may run on this tree.
export REHEARSAL_VENDOR="$SOURCE/vendor"
export REHEARSAL_PORT="${MYHAWLER_REHEARSAL_PORT:-8123}"

for required in REHEARSAL_BASELINE REHEARSAL_DB_PASSWORD REHEARSAL_DB_USER \
                REHEARSAL_DB_NAME REHEARSAL_PHP REHEARSAL_RUNTIME; do
    [ -n "${!required:-}" ] || fail "the rehearsal environment is incomplete: $required"
done

# BLOCKER 5 (rollback) and final release run #4 (deployment): exporting
# REHEARSAL_* is necessary but not sufficient. Both rehearsals run isolated.
#
# What run #4 proved. The deployment rehearsal went through ordinary
# record_tool, so it inherited this run's environment — including
# `export APP_KEY=...` and the browser gate's `export DB_DATABASE="$DB_BROWSER"`
# — and `deployment-rehearsal` is in the recorder's DATABASE_GATES, so the DB_*
# values were kept rather than stripped. Two assertions failed as a result:
#
#   FAIL  environment prepared with a generated key
#         deploy_rehearsal.sh writes a blank `APP_KEY=` and runs
#         `key:generate --force`. Laravel derives the line it replaces from the
#         CONFIGURED app.key, so an inherited APP_KEY meant the blank line was
#         never the value it expected to replace and the disposable .env stayed
#         keyless.
#
#   FAIL  exactly one migration applied (55 -> 55)
#         an inherited DB_DATABASE outranks the .env, so artisan operated on
#         myh_browser — already migrated from the CURRENT source earlier in the
#         run — instead of the clean myh_rehearse baseline. The count could not
#         move, and telegram_return_handoffs already showed as Ran, which is why
#         the following assertion still passed and masked the cause.
#
# Neither assertion was wrong. The rehearsal was measuring the wrong database
# with the wrong key.
record_isolated deployment-rehearsal "disposable staged site" \
    bash "$SOURCE/scripts/release/deploy_rehearsal.sh" "$REHEARSAL/stage" "$RUNTIME_BASE"

record_isolated rollback-rehearsal "fresh session (env -i)" \
    bash "$SOURCE/scripts/release/rollback_rehearsal_v7.sh" "$REHEARSAL/stage"

record deletion-apply-restore python3 "$SOURCE/scripts/release/deletion_check.py" \
    --tree "$STAGE" --manifest "$SOURCE/DELETE_FILES.txt" --work "$WORK/deletion-test"

step "Record the FULL-SOURCE and SOURCE-PATCH builders"

cp "$SOURCE/DELETE_FILES.txt" "$DELIVERY/DELETE_FILES.txt"

if [ -n "$MIN_FILES" ]; then
    record full-source-builder python3 "$SOURCE/scripts/release/build_full_source.py" \
        --project-root "$SOURCE" --output-dir "$DELIVERY" --self-test --min-files "$MIN_FILES"
else
    record full-source-builder python3 "$SOURCE/scripts/release/build_full_source.py" \
        --project-root "$SOURCE" --output-dir "$DELIVERY" --self-test
fi

record source-patch-builder python3 "$SOURCE/scripts/release/build_source_patch.py" \
    --baseline "$BASELINE_ARCHIVE" --current "$STAGE" \
    --deletions "$SOURCE/DELETE_FILES.txt" \
    --output "$DELIVERY/myhawler-account-first-registration-SOURCE-PATCH.zip" \
    --inventory "$EVIDENCE/CHANGED_FILES_INVENTORY.json"

cp "$RUNTIME_BASE.zip" "$DELIVERY/myhawler-account-first-registration-corrected-runtime.zip"
cp "$RUNTIME_DIR/RUNTIME_MANIFEST.txt" "$DELIVERY/RUNTIME_MANIFEST.txt"
cp "$STAGE/TREE_MANIFEST.txt" "$STAGE/TREE_MANIFEST.sha256" "$DELIVERY/"

step "Record parity, evidence and archive gates"

record source-runtime-parity python3 "$SOURCE/scripts/release/check_parity.py" \
    --mode code --source "$STAGE" --runtime "$RUNTIME_DIR/application"

record runtime-asset-parity python3 "$SOURCE/scripts/release/check_parity.py" \
    --mode assets --source "$SOURCE/public/build" --runtime "$RUNTIME_DIR/public_html/build"

record evidence-builder python3 "$SOURCE/scripts/release/build_v7_evidence.py" \
    --project-root "$SOURCE" --output-dir "$DELIVERY" --evidence-dir "$EVIDENCE" \
    --source-dir "$STAGE" --runtime-root "$RUNTIME_DIR" \
    --tree-manifest-sha256 "$FROZEN"

# Named for what it actually covers: the component artifacts that exist at this
# point. The final evidence ZIP and the master do not exist yet, and the earlier
# label `archive-audit` implied it had seen them.
record component-archive-audit python3 "$SOURCE/scripts/release/audit_archive.py" \
    --recursive "$DELIVERY"

step "Generate the mandatory reports from the completed ledger"

cp "$SOURCE/DEPLOYMENT_NOTES.md" "$SOURCE/ROLLBACK_NOTES.md" "$DELIVERY/"

python3 "$SOURCE/scripts/release/generate_release_reports.py" \
    --ledger "$LEDGER" \
    --inventory "$EVIDENCE/CHANGED_FILES_INVENTORY.json" \
    --tree-manifest-sha256 "$FROZEN" \
    --baseline-commit "$BASELINE_COMMIT" \
    --output-dir "$DELIVERY" || fail "mandatory report generation failed"

for report in VERIFICATION.md ROADMAP_STATUS.md RELEASE_DECISION.md FINAL_RELEASE_VERIFICATION.md; do
    [ -f "$EVIDENCE/reports/$report" ] && cp "$EVIDENCE/reports/$report" "$DELIVERY/"
done

step "Deliver the verifier and its dependencies before indexing"

for helper in verify_final_delivery.py validate_command_ledger.py release_gates.py audit_archive.py; do
    cp "$SOURCE/scripts/release/$helper" "$DELIVERY/"
done

step "Finalize the ledger, build the evidence ZIP, then index"

cp "$LEDGER" "$DELIVERY/command-ledger.json"

# The attestation phase has its OWN ledger and evidence root, outside the
# evidence directory it seals and judges, so no gate here ever needs to write
# into the archive that contains its result. Declared before the finalizer
# because the finalizer itself is the first attestation-phase gate.
ATTEST_LEDGER="$WORK/attestation-ledger.json"
ATTEST_EVIDENCE="$WORK/attestation-evidence"
mkdir -p "$ATTEST_EVIDENCE"

# The recorder always propagates the child's exit code, so the status a caller
# captures and the ledger's measured exit_code must be the same number. A
# disagreement — including a missing entry — means the wrapper's own failure
# was about to be mistaken for the child's result.
assert_recorded_exit() {
    python3 - "$ATTEST_LEDGER" "$1" "$2" <<'PY' || fail "captured exit does not match the measured ledger"
import json, sys
ledger, label, captured = sys.argv[1], sys.argv[2], int(sys.argv[3])
try:
    entries = {e['label']: e for e in json.load(open(ledger)).get('entries', [])}
except (OSError, ValueError):
    sys.exit(f'FAIL: {label}: no readable attestation ledger at {ledger}; '
             f'the captured exit {captured} is the wrapper\'s, not the child\'s')
entry = entries.get(label)
if entry is None:
    sys.exit(f'FAIL: {label}: never reached the attestation ledger; '
             f'the captured exit {captured} is the wrapper\'s, not the child\'s')
if entry['exit_code'] != captured:
    sys.exit(f'FAIL: {label}: ledger measured exit {entry["exit_code"]}, '
             f'the caller captured {captured}')
PY
}

# The verifier requires release-evidence.json inside the authoritative archive,
# so it is written BEFORE that archive is created — never after.
STUB_FLAG=""
[ "$OFFLINE_STUB" = "1" ] && STUB_FLAG="--offline-stub"

python3 "$SOURCE/scripts/release/write_release_evidence.py" \
    --evidence "$EVIDENCE" --delivery "$DELIVERY" \
    --tree-manifest-sha256 "$FROZEN" --baseline-commit "$BASELINE_COMMIT" \
    $STUB_FLAG || fail "release-evidence.json could not be written"

# BLOCKER 4: one component packages evidence, once, after everything else is
# final — and proves the archive entry set equals its own index exactly.
# BLOCKER 6 (post-seal audit): this is the command that actually creates the
# authoritative evidence ZIP and its internal index, so it runs through the
# real recorder under the canonical `evidence-finalizer` gate. Its raw log
# lives in the attestation evidence root — never inside the archive it
# creates, which is why this gate belongs to the attestation ledger.
# `|| RC=$?` rather than `set +e`: the ERR trap fires regardless of errexit,
# so a plain failing command inside a set +e block would still abort the
# script at that line and skip the cross-check and the tailored diagnosis.
# As the right-hand side of `||` the failure is exempt from both.
FINALIZER_RC=0
python3 "$SOURCE/scripts/release/record_command.py" \
    --ledger "$ATTEST_LEDGER" --evidence-dir "$ATTEST_EVIDENCE" \
    --tree-manifest-sha256 "$FROZEN" --label evidence-finalizer \
    --cwd "$SOURCE" \
    -- python3 "$SOURCE/scripts/release/finalize_evidence.py" \
       --evidence "$EVIDENCE" \
       --output "$DELIVERY/myhawler-account-first-registration-evidence.zip" \
       --tree-manifest-sha256 "$FROZEN" \
    || FINALIZER_RC=$?
assert_recorded_exit evidence-finalizer "$FINALIZER_RC"
[ "$FINALIZER_RC" = "0" ] || fail "the evidence package and its index disagree"

python3 "$SOURCE/scripts/release/validate_command_ledger.py" \
    --ledger "$DELIVERY/command-ledger.json" \
    --evidence-zip "$DELIVERY/myhawler-account-first-registration-evidence.zip" \
    --tree-manifest-sha256 "$FROZEN" \
    || fail "the completed ledger is not valid for the frozen tree"

python3 "$SOURCE/scripts/release/build_indexes.py" \
    --delivery "$DELIVERY" --evidence "$EVIDENCE" \
    --tree-manifest-sha256 "$FROZEN" --baseline-commit "$BASELINE_COMMIT" \
    --browser-spec "$SOURCE/tests/Browser/account-first-registration.spec.ts" \
    || fail "index generation reported missing or failed gates"

step "Generate component checksums, verify them, then assemble the master"

( cd "$DELIVERY" && for f in *; do
      case "$f" in
          *.sha256) continue ;;
          myhawler-account-first-registration-FINAL-DELIVERY.zip) continue ;;
      esac
      sha256sum "$f" > "$f.sha256"
  done )

# BLOCKER 7: the canonical checksum gate runs HERE, after every component
# checksum exists, and with no --allow-missing. The earlier recorded gate ran
# before the set existed and could pass vacuously.
# BLOCKER 5: these were executed directly and then DESCRIBED afterwards with
# hand-written command strings and placeholder paths. They now run through the
# same recorder as every other gate, into a separate attestation ledger, so
# argv, cwd, environment, timings, exit code and output hash are all measured.
# BLOCKER 1 (post-seal audit): the recorder used to be invoked here with
# --allow-nonzero, so the captured status below was the WRAPPER's 0 while the
# child had exited 7 — a failed verifier could not stop the release. The
# recorder now always propagates the child's exit, and every capture is
# cross-checked against the ledger's measured exit_code before it is trusted.
CHECKSUM_RC=0
python3 "$SOURCE/scripts/release/record_command.py" \
    --ledger "$ATTEST_LEDGER" --evidence-dir "$ATTEST_EVIDENCE" \
    --tree-manifest-sha256 "$FROZEN" --label sha256sums-verification \
    --cwd "$DELIVERY" \
    -- python3 "$SOURCE/scripts/release/verify_checksums.py" --directory "$DELIVERY" \
    || CHECKSUM_RC=$?
assert_recorded_exit sha256sums-verification "$CHECKSUM_RC"
CHECKSUM_LOG="$ATTEST_EVIDENCE/verification/sha256sums-verification.log"
cat "$CHECKSUM_LOG"
[ "$CHECKSUM_RC" = "0" ] || fail "a component detached checksum does not verify"

( cd "$DELIVERY" && zip -qrX "myhawler-account-first-registration-FINAL-DELIVERY.zip" . \
    -x 'myhawler-account-first-registration-FINAL-DELIVERY.zip' )
( cd "$DELIVERY" && sha256sum myhawler-account-first-registration-FINAL-DELIVERY.zip \
    > myhawler-account-first-registration-FINAL-DELIVERY.zip.sha256 )

step "Audit the final authoritative bytes: evidence ZIP and master delivery"

# BLOCKER 6: the earlier component audit could not have covered these — they did
# not exist. This one runs on the sealed package and is recorded in the external
# attestation, because its result cannot live inside the archive it judges.
FINAL_AUDIT_RC=0
python3 "$SOURCE/scripts/release/record_command.py" \
    --ledger "$ATTEST_LEDGER" --evidence-dir "$ATTEST_EVIDENCE" \
    --tree-manifest-sha256 "$FROZEN" --label final-archive-audit \
    --cwd "$DELIVERY" \
    -- python3 "$SOURCE/scripts/release/audit_archive.py" --recursive \
       "$DELIVERY/myhawler-account-first-registration-evidence.zip" \
       "$DELIVERY/myhawler-account-first-registration-FINAL-DELIVERY.zip" \
    || FINAL_AUDIT_RC=$?
assert_recorded_exit final-archive-audit "$FINAL_AUDIT_RC"
FINAL_AUDIT_LOG="$ATTEST_EVIDENCE/verification/final-archive-audit.log"
cat "$FINAL_AUDIT_LOG"
[ "$FINAL_AUDIT_RC" = "0" ] || fail "the final archive audit failed"

step "Verify from a clean directory using the delivered verifier"

rm -rf "$CLEANROOM" && mkdir -p "$CLEANROOM"
cp "$DELIVERY"/* "$CLEANROOM/"

VERIFY_RC=0
MYHAWLER_BUILDER_MIN_FILES="${MIN_FILES:-}" \
python3 "$SOURCE/scripts/release/record_command.py" \
    --ledger "$ATTEST_LEDGER" --evidence-dir "$ATTEST_EVIDENCE" \
    --tree-manifest-sha256 "$FROZEN" --label final-clean-verifier \
    --cwd "$CLEANROOM" \
    -- python3 ./verify_final_delivery.py . \
    || VERIFY_RC=$?
assert_recorded_exit final-clean-verifier "$VERIFY_RC"
VERIFY_LOG="$ATTEST_EVIDENCE/verification/final-clean-verifier.log"
cp "$VERIFY_LOG" "$WORK/final-verification.log"
[ "$VERIFY_RC" = "0" ] || fail "the clean-directory final verifier did not exit 0"

step "Write the external final attestation"

# The verifier proves the package, so its own result cannot live inside it.
# This is the formally defined second phase, written outside the delivery.
# BLOCKER 2 (post-seal audit): the writer derives every gate from the MEASURED
# attestation ledger and re-hashes the raw logs itself. The captured exits are
# passed only as --observed-exit cross-checks; any contradiction between the
# ledger, the logs on disk and the captured results refuses the attestation.
python3 "$SOURCE/scripts/release/write_attestation.py" \
    --output "$WORK/final-attestation.json" \
    --tree-manifest-sha256 "$FROZEN" \
    --master "$DELIVERY/myhawler-account-first-registration-FINAL-DELIVERY.zip" \
    --evidence-zip "$DELIVERY/myhawler-account-first-registration-evidence.zip" \
    --attestation-ledger "$ATTEST_LEDGER" \
    --attestation-evidence "$ATTEST_EVIDENCE" \
    --observed-exit "evidence-finalizer=$FINALIZER_RC" \
    --observed-exit "sha256sums-verification=$CHECKSUM_RC" \
    --observed-exit "final-archive-audit=$FINAL_AUDIT_RC" \
    --observed-exit "final-clean-verifier=$VERIFY_RC" \
    || fail "the final attestation could not be written"

step "Prove the attestation upload set exists"

# BLOCKER 3 (post-seal audit): the workflow used to advertise two log paths the
# runner never created, so a successful run uploaded an attestation without the
# raw logs that verify two of its gates. Everything the workflow uploads as
# attestation material is asserted here, in the runner, so a missing file fails
# the cycle instead of silently thinning the proof.
for artifact in \
    "$WORK/final-attestation.json" \
    "$WORK/final-attestation.json.sha256" \
    "$WORK/final-verification.log" \
    "$ATTEST_LEDGER" \
    "$ATTEST_EVIDENCE/packaging/evidence-finalizer.log" \
    "$ATTEST_EVIDENCE/verification/sha256sums-verification.log" \
    "$ATTEST_EVIDENCE/verification/final-archive-audit.log" \
    "$ATTEST_EVIDENCE/verification/final-clean-verifier.log"; do
    [ -f "$artifact" ] || fail "attestation material missing: $artifact"
done

step "Prove the frozen source never changed"

chmod -R u+w "$SOURCE" 2>/dev/null || true
"$PHP_BIN" "$SOURCE/scripts/release/stage_tree.php" \
    --project-root="$SOURCE" --stage-dir="$STAGE.final" >/dev/null
FINAL=$(cut -d' ' -f1 "$STAGE.final/TREE_MANIFEST.sha256")
[ "$FINAL" = "$FROZEN" ] || fail "the source tree changed during the cycle: $FROZEN -> $FINAL"

echo ""
echo "========================================================"
if [ "$OFFLINE_STUB" = "1" ]; then
    echo " OFFLINE STUB RUN COMPLETE - orchestration only, NOT a release"
else
    echo " FINAL RELEASE CYCLE COMPLETE"
fi
echo " frozen tree : $FROZEN"
echo " delivery    : $DELIVERY"
echo " evidence    : $EVIDENCE"
echo " attestation : $WORK/final-attestation.json"
echo "========================================================"
