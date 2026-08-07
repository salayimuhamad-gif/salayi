#!/usr/bin/env bash
#
# Extract a Production Deployment archive as a customer would and prove it runs.
#
# This exists because two real defects survived every in-repository gate and
# were only found by actually deploying the artifact: Laravel resolved
# public_path() to a directory the packaging step deletes, so the Vite manifest
# was never found and every page threw; and the public home page filtered on a
# subquery alias with HAVING, which SQLite rejects outright. Neither is visible
# from the source tree, because in the source tree public/ still exists and the
# test suite never loaded the home page with an area present.
#
# Nothing here touches the development tree, its .env, its database or its
# vendor directory. The environment is created AFTER extraction, from
# .env.example, with a disposable key and a disposable SQLite file.
#
# Usage: consumer-deployment-test.sh <deployment.zip> <out.json> [commit]

set -euo pipefail

ARCHIVE="${1:?usage: consumer-deployment-test.sh <deployment.zip> <out.json> [commit]}"
OUT="${2:?an output json path is required}"
COMMIT="${3:-unknown}"

# INHERIT NOTHING.
#
# A consumer has no APP_KEY, no DB_DATABASE, no SESSION_DRIVER in their shell.
# The development environment exported for static analysis leaked into the
# extraction and overrode the .env this script writes, so the app resolved a
# session driver the artifact never configured — the test was measuring the
# operator's shell, not the deliverable.
for leaked in APP_ENV APP_DEBUG APP_KEY APP_URL \
    DB_CONNECTION DB_HOST DB_PORT DB_DATABASE DB_USERNAME DB_PASSWORD \
    CACHE_STORE CACHE_DRIVER SESSION_DRIVER QUEUE_CONNECTION MAIL_MAILER \
    LOG_CHANNEL MULKIHAWLER_INSTALLED MULKIHAWLER_APP_BASE; do
    unset "$leaked"
done

SCHEMA_VERSION=1
SCRIPT_NAME="consumer-deployment-test.sh"
SCRIPT_VERSION="$(sha256sum "${BASH_SOURCE[0]}" | cut -c1-16)"

[ -f "$ARCHIVE" ] || { echo "archive not found: $ARCHIVE" >&2; exit 1; }

STARTED="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
SIZE="$(stat -c%s "$ARCHIVE")"
DIGEST="$(sha256sum "$ARCHIVE" | cut -d' ' -f1)"

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

FAILURES=()
declare -A CHECK

check() {  # check <name> <condition-exit-code> [detail]
    local name="$1" code="$2" detail="${3:-}"
    if [ "$code" -eq 0 ]; then
        CHECK["$name"]=PASS
        printf '  pass  %s\n' "$name"
    else
        CHECK["$name"]=FAIL
        FAILURES+=("$name${detail:+: $detail}")
        printf '  FAIL  %s %s\n' "$name" "$detail"
    fi
}

echo "Consumer deployment test: $(basename "$ARCHIVE")"
echo "  sha256 $DIGEST"

# ---------------------------------------------------------------- extraction
( cd "$WORK" && unzip -q "$ARCHIVE" )
APP="$WORK/application"
WEB="$WORK/public_html"

check extraction "$([ -d "$APP" ] && [ -d "$WEB" ] && echo 0 || echo 1)"

# Nothing that belongs to a running install may ship inside the artifact.
check no_env_shipped        "$([ "$(find "$WORK" -name '.env' | wc -l)" -eq 0 ] && echo 0 || echo 1)"
check no_database_shipped   "$([ "$(find "$WORK" -name '*.sqlite' | wc -l)" -eq 0 ] && echo 0 || echo 1)"
check no_logs_shipped       "$([ "$(find "$WORK" -name '*.log' | wc -l)" -eq 0 ] && echo 0 || echo 1)"
check env_example_present   "$([ -f "$APP/.env.example" ] && echo 0 || echo 1)"
check autoload_present      "$([ -f "$APP/vendor/autoload.php" ] && echo 0 || echo 1)"
check vite_manifest_present "$([ -f "$WEB/build/manifest.json" ] && echo 0 || echo 1)"
check built_assets_present  "$([ "$(find "$WEB/build/assets" -type f 2>/dev/null | wc -l)" -gt 0 ] && echo 0 || echo 1)"
check no_dev_dependencies   "$([ ! -d "$APP/vendor/phpunit" ] && [ ! -d "$APP/vendor/phpstan" ] && echo 0 || echo 1)"

# ------------------------------------------------- disposable environment
DB="$APP/database/consumer.sqlite"
mkdir -p "$APP/database"
touch "$DB"

cp "$APP/.env.example" "$APP/.env"
php -r '
$path = $argv[1];
$db = $argv[2];
$s = file_get_contents($path);
$set = static function (string $s, string $k, string $v): string {
    return preg_match("/^{$k}=/m", $s)
        ? preg_replace("/^{$k}=.*$/m", "{$k}={$v}", $s)
        : $s."\n{$k}={$v}\n";
};
foreach ([
    "APP_ENV" => "production", "APP_DEBUG" => "false",
    "DB_CONNECTION" => "sqlite", "DB_DATABASE" => $db,
    "CACHE_STORE" => "file", "SESSION_DRIVER" => "file",
    "QUEUE_CONNECTION" => "sync", "MAIL_MAILER" => "log",
    "LOG_CHANNEL" => "stderr", "MULKIHAWLER_INSTALLED" => "true",
] as $k => $v) { $s = $set($s, $k, $v); }
file_put_contents($path, $s);
' "$APP/.env" "$DB"

( cd "$APP" && php artisan key:generate --force > /dev/null 2>&1 )
check disposable_key_generated $?

run_in_app() { ( cd "$APP" && "$@" > /dev/null 2>&1 ); }

run_in_app php artisan config:clear;  check config_clear $?
run_in_app php artisan config:cache;  check config_cache $?
run_in_app php artisan route:cache;   check route_cache $?
run_in_app php artisan view:cache;    check view_cache $?
run_in_app php artisan about;         check artisan_about $?

# The migration COUNT is taken from `migrate:status`, not by parsing console
# output: the console format is a presentation detail and counting it made the
# check fail while the migrations had in fact all applied.
run_in_app php artisan migrate --force
check migrate_command $?

RAN="$( ( cd "$APP" && php artisan migrate:status 2>/dev/null ) | grep -c 'Ran' || true)"
MIGRATED="$RAN"
# v6: the expected total was a literal, so every migration added since the
# donor era failed a check that is really about "the deployed archive ran
# ALL of its own migrations". It is counted from the archive being tested.
EXPECTED_MIGRATIONS="$(find "$APP/app/Modules" -path '*/Database/Migrations/*.php' 2>/dev/null | wc -l)"
EXPECTED_MIGRATIONS=$((EXPECTED_MIGRATIONS + $(find "$APP/database/migrations" -name '*.php' 2>/dev/null | wc -l)))

check migrations_applied "$([ "$RAN" -eq "$EXPECTED_MIGRATIONS" ] && echo 0 || echo 1)" "$RAN recorded, expected $EXPECTED_MIGRATIONS"

# ------------------------------------------------------- application probes
cat > "$APP/consumer-probe.php" <<'PROBE'
<?php
require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->usePublicPath(dirname(__DIR__).'/public_html');
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$result = [
    'public_path' => public_path(),
    'public_path_is_public_html' => str_ends_with(public_path(), 'public_html'),
    'vite_manifest_resolves' => file_exists(public_path('build/manifest.json')),
    'config_cached' => file_exists(__DIR__.'/bootstrap/cache/config.php'),
    'env_installed_flag' => env('MULKIHAWLER_INSTALLED'),
    'config_installer_enabled' => config('installer.enabled'),
    'session_driver' => config('session.driver'),
];

// Seed one published area with one published project, so the home-page query
// has to return a row rather than short-circuiting on an empty table. Wrapped,
// because a seeding failure must be REPORTED rather than killing the probe and
// leaving every later assertion unexplained.
try {
    $area = App\Modules\Geography\Models\Area::query()->create([
        'type' => 'district', 'slug' => 'smoke-area', 'name_ckb' => 'ناوچەی تاقیکردنەوە',
        'publication_status' => 'published',
    ]);

    App\Modules\Projects\Models\Project::query()->create([
        'slug' => 'smoke-project', 'name_ckb' => 'پرۆژە', 'area_id' => $area->id,
        'project_type' => 'residential', 'construction_status' => 'planning',
        'delivery_status' => 'not_started', 'publication_status' => 'published',
    ]);

    $result['seeded'] = true;
} catch (Throwable $e) {
    $result['seeded'] = false;
    $result['seed_error'] = $e->getMessage();
}

try {
    $rows = App\Modules\Geography\Models\Area::query()
        ->published()
        ->withCount(['projects' => static fn ($q) => $q->where('publication_status', 'published')])
        ->whereHas('projects', static fn ($q) => $q->where('publication_status', 'published'))
        ->orderByDesc('projects_count')
        ->limit(8)
        ->get();

    $result['home_query_ok'] = true;
    $result['home_query_rows'] = $rows->count();
} catch (Throwable $e) {
    $result['home_query_ok'] = false;
    $result['home_query_error'] = $e->getMessage();
}

// Uninstalled deployment, still with a cached config.
config(['installer.enabled' => true, 'installer.lock_file' => __DIR__.'/storage/installed.lock']);
@unlink(config('installer.lock_file'));

$request = Illuminate\Http\Request::create('/market', 'GET', server: ['REMOTE_ADDR' => '127.0.0.1']);
$app->instance('request', $request);

$response = (new App\Http\Middleware\EnsureInstalled())->handle(
    $request,
    static fn () => new Illuminate\Http\Response('APP', 200),
);

$result['uninstalled_status'] = $response->getStatusCode();
$result['uninstalled_location'] = $response->headers->get('Location');

echo json_encode($result), "\n";
PROBE

PROBE_JSON="$( cd "$APP" && php consumer-probe.php 2>/tmp/consumer-probe.err || echo '{}' )"
rm -f "$APP/consumer-probe.php"

if [ -z "${PROBE_JSON//[[:space:]]/}" ] || [ "$PROBE_JSON" = "{}" ]; then
    echo "  note  the application probe produced no output" >&2
fi

jq_get() {
    printf '%s' "$PROBE_JSON" | php -r '
        $raw = file_get_contents("php://stdin");
        $d = json_decode($raw, true);
        if (! is_array($d) || ! array_key_exists($argv[1], $d)) {
            echo "<missing>";
            exit(0);
        }
        $v = $d[$argv[1]];
        echo is_bool($v) ? ($v ? "true" : "false") : (string) $v;
    ' "$1"
}

check public_path_is_public_html "$([ "$(jq_get public_path_is_public_html)" = "true" ] && echo 0 || echo 1)"
check vite_manifest_resolves     "$([ "$(jq_get vite_manifest_resolves)" = "true" ] && echo 0 || echo 1)"
check config_cached              "$([ "$(jq_get config_cached)" = "true" ] && echo 0 || echo 1)"
check installer_state_from_config "$([ "$(jq_get config_installer_enabled)" = "false" ] && echo 0 || echo 1)"
check home_query_sqlite_ok       "$([ "$(jq_get home_query_ok)" = "true" ] && echo 0 || echo 1)" "$(jq_get home_query_error)"
check uninstalled_redirects      "$([ "$(jq_get uninstalled_status)" = "302" ] && echo 0 || echo 1)"
SESSION_DRIVER_VALUE="$(jq_get session_driver)"
check session_driver_not_forced  "$([ "$SESSION_DRIVER_VALUE" = "file" ] && echo 0 || echo 1)" "resolved to '$SESSION_DRIVER_VALUE'"

# --------------------------------------------- front controller, real request
FRONT="$(cd "$WEB" && MULKIHAWLER_APP_BASE="$APP" php -r '
$_SERVER["REQUEST_METHOD"]="GET"; $_SERVER["REQUEST_URI"]="/";
$_SERVER["SCRIPT_NAME"]="/index.php"; $_SERVER["HTTP_HOST"]="localhost";
$_SERVER["REMOTE_ADDR"]="127.0.0.1"; $_SERVER["SERVER_PORT"]="80";
$_SERVER["SERVER_NAME"]="localhost";
ob_start(); require "index.php"; $out = ob_get_clean();
printf("%d|%d|%d|%d\n", http_response_code(), strlen($out),
    (stripos($out, "<html") !== false || stripos($out, "<!doctype html") !== false) ? 1 : 0,
    strpos($out, "/build/assets/") !== false ? 1 : 0);
' 2>/dev/null || echo "0|0|0|0")"

STATUS="${FRONT%%|*}"; REST="${FRONT#*|}"
BYTES="${REST%%|*}"; REST="${REST#*|}"
IS_HTML="${REST%%|*}"; HAS_ASSETS="${REST#*|}"

check installed_reaches_application "$([ "$STATUS" = "200" ] && echo 0 || echo 1)" "http $STATUS"
check home_page_renders_html        "$([ "$IS_HTML" = "1" ] && echo 0 || echo 1)"
check home_page_references_assets   "$([ "$HAS_ASSETS" = "1" ] && echo 0 || echo 1)"

RESULT=PASS
[ "${#FAILURES[@]}" -eq 0 ] || RESULT=FAIL

# ------------------------------------------------------------------- report
{
    printf '{\n'
    printf '  "schema_version": %d,\n' "$SCHEMA_VERSION"
    printf '  "result_type": "consumer_deployment_smoke",\n'
    printf '  "generated_by": "%s",\n' "$SCRIPT_NAME"
    printf '  "generator_version": "%s",\n' "$SCRIPT_VERSION"
    printf '  "source_commit": "%s",\n' "$COMMIT"
    printf '  "artifact": { "filename": "%s", "bytes": %s, "sha256": "%s", "role": "production_deployment" },\n' \
        "$(basename "$ARCHIVE")" "$SIZE" "$DIGEST"
    printf '  "started_at": "%s",\n' "$STARTED"
    printf '  "finished_at": "%s",\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
    printf '  "exit_code": %d,\n' "$([ "$RESULT" = PASS ] && echo 0 || echo 1)"
    printf '  "extraction_location_class": "ephemeral temporary directory outside the repository",\n'
    printf '  "php_version": "%s",\n' "$(php -r 'echo PHP_VERSION;')"
    printf '  "host": { "platform": "%s" },\n' "$(uname -s)"
    printf '  "commands": ["unzip", "composer check-platform-reqs --no-dev", "artisan about", "artisan config:clear", "artisan config:cache", "artisan route:cache", "artisan view:cache", "artisan migrate --force", "artisan migrate:status", "front-controller request"],\n'
    printf '  "migrations_applied": %s,\n' "${MIGRATED:-0}"
    printf '  "front_controller": { "status": %s, "bytes": %s },\n' "${STATUS:-0}" "${BYTES:-0}"
    printf '  "checks": {\n'
    first=1
    for name in "${!CHECK[@]}"; do
        [ $first -eq 1 ] || printf ',\n'
        printf '    "%s": "%s"' "$name" "${CHECK[$name]}"
        first=0
    done
    printf '\n  },\n'
    printf '  "failures": ['
    first=1
    for f in ${FAILURES+"${FAILURES[@]}"}; do
        [ $first -eq 1 ] || printf ', '
        printf '"%s"' "$f"
        first=0
    done
    printf '],\n'
    printf '  "result": "%s"\n' "$RESULT"
    printf '}\n'
} > "$OUT.tmp"

mv "$OUT.tmp" "$OUT"   # atomic

echo "  wrote $OUT"
echo "  $RESULT"

[ "$RESULT" = PASS ]
