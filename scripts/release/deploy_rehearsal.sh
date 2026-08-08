#!/usr/bin/env bash
# Rehearse the v7 patch deployment against a disposable copy laid out exactly
# like the Hostinger target: ~/domains/<site>/application beside public_html.
#
# Nothing here touches the working tree or any live system. The staged copy is
# built from the v6 baseline commit, the patch is applied the way
# DEPLOYMENT_NOTES.md says to apply it, and the result is exercised over HTTP.
# NOT `set -e`: every step below is a CHECK whose failure must be recorded and
# reported, not swallowed by the shell aborting mid-rehearsal. The exit status
# at the end is what decides pass/fail.
set -uo pipefail

# Database credentials come from the environment, never from this file. A
# rehearsal harness that carries a committed password is a credential in the
# repository, and "it is only a disposable local database" is exactly how that
# habit spreads.
REHEARSAL_DB_USER="${REHEARSAL_DB_USER:-myh}"
REHEARSAL_DB_HOST="${REHEARSAL_DB_HOST:-127.0.0.1}"
REHEARSAL_DB_PORT="${REHEARSAL_DB_PORT:-3306}"
REHEARSAL_DB_NAME="${REHEARSAL_DB_NAME:-myh_rehearse}"
MYSQLDUMP_BIN="${REHEARSAL_MYSQLDUMP:-mysqldump}"
REHEARSAL_DB_PASSWORD="${REHEARSAL_DB_PASSWORD:?set REHEARSAL_DB_PASSWORD before running the rehearsal}"

# Every path is supplied, with defaults relative to the current directory. A
# rehearsal harness that hardcodes one developer's home directory cannot be run
# by anyone else, which is the only situation where it matters.
#
#   $1 / REHEARSAL_STAGE     scratch directory for the disposable site
#   $2 / REHEARSAL_RUNTIME   runtime artifact, WITHOUT the .zip suffix
#   REHEARSAL_BASELINE       git repo OR tar/zip archive of the v6 baseline
#   REHEARSAL_EVIDENCE       where JSON/log evidence is written
#   REHEARSAL_PORT           loopback port for the staged site
STAGE="${1:-${REHEARSAL_STAGE:-./rehearsal}}"
RUNTIME="${2:-${REHEARSAL_RUNTIME:?set REHEARSAL_RUNTIME or pass the runtime path as $2}}"
BASELINE="${REHEARSAL_BASELINE:?set REHEARSAL_BASELINE to the v6 baseline repo or archive}"
BASELINE_COMMIT="${REHEARSAL_BASELINE_COMMIT:-9c0188f81843cfe4786b7f72ecdc2a3fae89cd82}"
EV="${REHEARSAL_EVIDENCE:-$STAGE/evidence}"
PHP_BIN="${REHEARSAL_PHP:-php}"
SITE="$STAGE/domains/myhawler.test"
PORT="${REHEARSAL_PORT:-8123}"

mkdir -p "$EV"

pass=0
fail=0
check() {
    if [ "$2" = "0" ]; then
        printf '  pass  %s\n' "$1"
        pass=$((pass + 1))
    else
        printf '  FAIL  %s\n' "$1"
        fail=$((fail + 1))
    fi
}

rm -rf "$STAGE"
mkdir -p "$SITE"

echo "== staging the v6 baseline =="
# The baseline may be a git repository OR a plain archive, because a machine
# rehearsing a delivered release will not necessarily have the repository.
mkdir -p "$SITE/application"

if [ -d "$BASELINE/.git" ] || [ -d "$BASELINE/refs" ]; then
    git -C "$BASELINE" archive "$BASELINE_COMMIT" | tar -x -C "$SITE/application"
elif [ -f "$BASELINE" ]; then
    case "$BASELINE" in
        *.zip) unzip -q "$BASELINE" -d "$SITE/application" ;;
        *)     tar -xf "$BASELINE" -C "$SITE/application" ;;
    esac
    # Tolerate a single wrapping directory inside the archive.
    if [ ! -f "$SITE/application/artisan" ]; then
        inner=$(find "$SITE/application" -maxdepth 2 -name artisan -printf '%h\n' | head -1)
        [ -n "$inner" ] && [ "$inner" != "$SITE/application" ] && \
            (cd "$inner" && tar -cf - .) | tar -xf - -C "$SITE/application"
    fi
else
    echo "  FAIL  REHEARSAL_BASELINE is neither a git repository nor an archive: $BASELINE"
    exit 1
fi

[ -f "$SITE/application/artisan" ]; check "baseline staged from the sealed commit" $?

# Hostinger splits the public root out of the application directory.
mv "$SITE/application/public" "$SITE/public_html"
check "public root split from the application directory" $?

# Dependencies come from a supplied vendor directory, or are installed.
VENDOR_SRC="${REHEARSAL_VENDOR:-}"

if [ -n "$VENDOR_SRC" ] && [ -d "$VENDOR_SRC" ]; then
    cp -a "$VENDOR_SRC" "$SITE/application/vendor"
else
    ( cd "$SITE/application" && composer install --no-interaction --no-progress --no-scripts >/dev/null 2>&1 )
fi

[ -f "$SITE/application/vendor/autoload.php" ]
check "dependencies present" $?

# Generate the disposable rehearsal key without booting Laravel. `artisan
# key:generate` is the wrong primitive here because this exact rehearsal is
# supposed to prove that a fresh staged application can boot from an otherwise
# empty environment. Final release run #9 showed the circular failure mode:
# key:generate left APP_KEY empty, the baseline migration never established its
# schema, and every HTTP smoke request then died with MissingAppKeyException.
# A raw PHP random_bytes() call has no application bootstrap dependency.
REHEARSAL_APP_KEY=$("$PHP_BIN" -r 'echo "base64:".base64_encode(random_bytes(32));')

cat > "$SITE/application/.env" <<'ENVEOF'
APP_NAME=MyHawler
APP_KEY=__APP_KEY__
APP_ENV=production
APP_DEBUG=false
APP_URL=http://127.0.0.1:8123
DB_CONNECTION=mysql
DB_HOST=__DB_HOST__
DB_PORT=__DB_PORT__
DB_DATABASE=__DB_NAME__
DB_USERNAME=__DB_USER__
DB_PASSWORD=__DB_PASS__
CACHE_STORE=file
SESSION_DRIVER=file
QUEUE_CONNECTION=database
MULKIHAWLER_INSTALLED=true
# The rehearsal serves over plain HTTP on loopback, so a secure-only session
# cookie would never be sent back and every authenticated check would look
# like a broken session. Production runs behind TLS and keeps the default.
SESSION_SECURE_COOKIE=false
# Production forces HTTPS (correctly). The rehearsal has no TLS listener on
# loopback, so redirects would point at a scheme nothing is serving. Disabled
# HERE ONLY; the shipped default stays true and is what production uses.
MULKIHAWLER_FORCE_HTTPS=false
MULKIHAWLER_BLIND_INDEX_KEY=0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef
TELEGRAM_BOT_TOKEN=123456:rehearsal-token
TELEGRAM_BOT_USERNAME=myhawler_test_bot
TELEGRAM_WEBHOOK_SECRET=rehearsal-secret
ENVEOF
sed -i "s|__APP_KEY__|$REHEARSAL_APP_KEY|; s|__DB_USER__|$REHEARSAL_DB_USER|; s|__DB_PASS__|$REHEARSAL_DB_PASSWORD|; s|__DB_NAME__|$REHEARSAL_DB_NAME|; s|__DB_HOST__|$REHEARSAL_DB_HOST|; s|__DB_PORT__|$REHEARSAL_DB_PORT|" "$SITE/application/.env"
awk -F= '/^APP_KEY/ { exit (length($2) > 20 ? 0 : 1) } END { }' "$SITE/application/.env"
check "environment prepared with a generated key" $?

# The baseline is INSTALLED before the patch is applied: this rehearsal is an
# upgrade of a running site. This release adds exactly ONE migration
# (2026_08_06_000100_telegram_return_handoffs), and "exactly one" can only be
# demonstrated against a database that is already migrated to the baseline.
( cd "$SITE/application" && "$PHP_BIN" artisan migrate --force >/dev/null 2>&1 )
check "baseline schema migrated before the patch is applied" $?

echo "== 1. checksum verification =="
( cd "$(dirname "$RUNTIME")" && sha256sum -c "$(basename "$RUNTIME").zip.sha256" ) >/dev/null 2>&1
check "runtime archive checksum verifies before anything is touched" $?

echo "== 2. mandatory backups =="
# DEPLOYMENT_NOTES.md §2 requires SIX backups. The rehearsal used to take four,
# so the two the rollback actually depends on — Operations and
# config/mulkihawler.php — were never proven to exist before they were needed.
TS=$(date +%Y%m%d-%H%M%S)
BACKUP="$STAGE/backup-$TS"
mkdir -p "$BACKUP"

cp -a "$SITE/application/app/Modules/Identity"   "$BACKUP/Identity"
cp -a "$SITE/application/app/Modules/Operations" "$BACKUP/Operations"
cp -a "$SITE/application/lang"                   "$BACKUP/lang"
cp -a "$SITE/application/config/mulkihawler.php" "$BACKUP/mulkihawler.php"
cp -a "$SITE/public_html/build"                  "$BACKUP/build"
"$MYSQLDUMP_BIN" -h "$REHEARSAL_DB_HOST" -P "$REHEARSAL_DB_PORT" \
    -u "$REHEARSAL_DB_USER" -p"$REHEARSAL_DB_PASSWORD" "$REHEARSAL_DB_NAME" \
    > "$BACKUP/database.sql" 2>/dev/null

# Taking a backup and having a backup are different claims. Prove each item
# exists AND is readable AND is not empty.
backup_failures=0

for item in Identity Operations lang mulkihawler.php build database.sql; do
    if [ ! -e "$BACKUP/$item" ] || [ ! -r "$BACKUP/$item" ]; then
        echo "  FAIL  backup item missing or unreadable: $item"
        backup_failures=$((backup_failures + 1))
        continue
    fi

    if [ -f "$BACKUP/$item" ] && [ ! -s "$BACKUP/$item" ]; then
        echo "  FAIL  backup item is empty: $item"
        backup_failures=$((backup_failures + 1))
    fi
done

[ "$backup_failures" = "0" ]
check "all six documented backups taken, readable and non-empty" $?

printf '%s\n' "$BACKUP" > "$STAGE/LAST_BACKUP_PATH"

echo "== 3. staged in a NEW directory, not over the live tree =="
rm -rf "$STAGE/patch"
mkdir -p "$STAGE/patch"
unzip -q "$RUNTIME.zip" -d "$STAGE/patch"
[ -d "$STAGE/patch/application" ] && [ -d "$STAGE/patch/public_html/build" ]
check "patch unpacked to a staging directory" $?

echo "== 4. maintenance mode =="
( cd "$SITE/application" && "$PHP_BIN" artisan down --render="errors::503" >/dev/null 2>&1 )
check "site placed in maintenance mode before files move" $?

echo "== 5. apply =="
cp -a "$STAGE/patch/application/app/." "$SITE/application/app/"
cp -a "$STAGE/patch/application/lang/." "$SITE/application/lang/"
cp -a "$STAGE/patch/application/config/." "$SITE/application/config/" 2>/dev/null || true
rm -rf "$SITE/public_html/build"
cp -a "$STAGE/patch/public_html/build" "$SITE/public_html/"
check "runtime files applied" $?

# The build directory is REPLACED, never merged, and that replacement is what
# retires the previous build's content-hashed chunks — the source patch does not
# list them individually in DELETE_FILES.txt because this step removes all of
# them at once. So this step has to be PROVEN, not assumed.
#
# Two properties, and the manifest check in §6 implies neither: the deployed
# directory must equal the runtime build exactly, and no file from the previous
# build may survive unless the new build also ships it. A merged directory
# passes a manifest check happily, because a manifest only names files that ARE
# there — never the hundred stale chunks sitting beside them.
DEPLOYED_LIST=$(cd "$SITE/public_html/build" && find . -type f | sort)
STAGED_LIST=$(cd "$STAGE/patch/public_html/build" && find . -type f | sort)
PREVIOUS_LIST=$(cd "$BACKUP/build" && find . -type f | sort)

[ "$DEPLOYED_LIST" = "$STAGED_LIST" ]
check "the deployed build directory matches the runtime build exactly" $?

stale_kept=0
retired=0

while IFS= read -r rel; do
    [ -n "$rel" ] || continue

    # Shipped by the new build too: not a stale chunk, it is a current one.
    if printf '%s\n' "$STAGED_LIST" | grep -qxF "$rel"; then
        continue
    fi

    retired=$((retired + 1))

    if [ -e "$SITE/public_html/build/$rel" ]; then
        echo "    stale chunk survived the replacement: $rel"
        stale_kept=$((stale_kept + 1))
    fi
done <<PREVIOUS_BUILD
$PREVIOUS_LIST
PREVIOUS_BUILD

[ "$stale_kept" = "0" ] && [ "$retired" -gt "0" ]
check "every retired build chunk is gone ($retired retired, $stale_kept survived)" $?

echo "== 5b. apply the deletion manifest =="
# A ZIP overlay cannot delete. Without this step a file removed by the release
# survives on the server, and the deployment "succeeds" with it still there.
DELETIONS="$STAGE/patch/DELETE_FILES.txt"

if [ -f "$DELETIONS" ]; then
    deletion_failures=0

    while IFS= read -r rel; do
        case "$rel" in
            ''|\#*) continue ;;
        esac

        # Allow-listed, relative, and inside the application directory only.
        case "$rel" in
            /*|*..*)
                echo "    refused unsafe deletion path: $rel"
                deletion_failures=$((deletion_failures + 1))
                continue
                ;;
        esac

        rm -f "$SITE/application/$rel"

        if [ -e "$SITE/application/$rel" ]; then
            deletion_failures=$((deletion_failures + 1))
        else
            echo "    deleted: $rel"
        fi
    done < "$DELETIONS"

    [ "$deletion_failures" = "0" ]
    check "every listed deletion applied and verified absent" $?
else
    check "deletion manifest present in the runtime package" 1
fi

echo "== 6. manifest consistency =="
"$PHP_BIN" -r '
$m = json_decode(file_get_contents($argv[1]), true);
$missing = 0;
foreach ($m as $entry) {
    if (!isset($entry["file"])) { continue; }
    if (!file_exists(dirname($argv[1])."/".$entry["file"])) { $missing++; }
}
exit($missing === 0 ? 0 : 1);' "$SITE/public_html/build/manifest.json"
check "every manifest entry resolves to a shipped file" $?

echo "== 7. caches rebuilt =="
( cd "$SITE/application" && "$PHP_BIN" artisan config:clear >/dev/null 2>&1 && "$PHP_BIN" artisan route:clear >/dev/null 2>&1 \
    && "$PHP_BIN" artisan view:clear >/dev/null 2>&1 && "$PHP_BIN" artisan config:cache >/dev/null 2>&1 \
    && "$PHP_BIN" artisan route:cache >/dev/null 2>&1 && "$PHP_BIN" artisan view:cache >/dev/null 2>&1 )
check "configuration, route and view caches rebuilt" $?

echo "== 8. migrations (this patch adds exactly one) =="
BEFORE=$( cd "$SITE/application" && "$PHP_BIN" artisan migrate:status 2>/dev/null | grep -c Ran )
( cd "$SITE/application" && "$PHP_BIN" artisan migrate --force >/dev/null 2>&1 )
AFTER=$( cd "$SITE/application" && "$PHP_BIN" artisan migrate:status 2>/dev/null | grep -c Ran )
DELTA=$(( AFTER - BEFORE ))

# The v7 return handoff needs a durable, lockable table, so this patch ships
# ONE forward-only migration. Asserting the exact number matters in both
# directions: a zero would mean the handoff table never arrived, and a larger
# number would mean something unintended came with it.
[ "$DELTA" = "1" ]
check "exactly one migration applied ($BEFORE -> $AFTER)" $?

( cd "$SITE/application" && "$PHP_BIN" artisan migrate:status 2>/dev/null | grep -q "telegram_return_handoffs" )
check "the return-handoff table migration is the one that ran" $?

echo "== 9. runtime checks =="
( cd "$SITE/application" && "$PHP_BIN" artisan route:list >/dev/null 2>&1 )
check "route table resolves" $?

( cd "$SITE/application" && "$PHP_BIN" artisan route:list 2>/dev/null | grep -q "account/registration/abandon" )
check "the new recovery route is present" $?

( cd "$SITE/application" && "$PHP_BIN" artisan schedule:list 2>/dev/null | grep -q "prune-unlinked" )
check "the reclaim command is scheduled" $?

( cd "$SITE/application" && "$PHP_BIN" artisan queue:work --stop-when-empty --max-time=10 >/dev/null 2>&1 )
check "queue worker runs" $?

echo "== 10. bring the site back and smoke it =="
( cd "$SITE/application" && "$PHP_BIN" artisan up >/dev/null 2>&1 )
check "maintenance mode lifted" $?

( cd "$SITE/public_html" && MULKIHAWLER_APP_BASE="$SITE/application" nohup "$PHP_BIN" -S 127.0.0.1:$PORT index.php > "$EV/rehearsal-server.log" 2>&1 & )
sleep 4

CODE=$(curl -sS -o /dev/null -w '%{http_code}' "http://127.0.0.1:$PORT/" || echo 000)
[ "$CODE" = "200" ]
check "home page responds 200 (got $CODE)" $?

REG=$(curl -sS -o /dev/null -w '%{http_code}' "http://127.0.0.1:$PORT/register" || echo 000)
[ "$REG" = "200" ]
check "registration page responds 200 (got $REG)" $?

ASSET=$(curl -sS "http://127.0.0.1:$PORT/" | grep -oE 'build/assets/[^"]+' | head -1)
if [ -n "$ASSET" ]; then
    ACODE=$(curl -sS -o /dev/null -w '%{http_code}' "http://127.0.0.1:$PORT/$ASSET" || echo 000)
    [ "$ACODE" = "200" ]
    check "a referenced asset is served (got $ACODE)" $?
else
    check "home page references a built asset" 1
fi

echo "== 11. the new registration flow, end to end on the deployed copy =="
CJ=$(mktemp)
TOKEN=$(curl -sS -c "$CJ" "http://127.0.0.1:$PORT/register" | grep -oE 'csrf-token" content="[^"]+' | head -1 | cut -d'"' -f3)
# Two steps, because curl -L re-POSTs to the redirect target and the app
# answers 405. The browser does a GET here; so does this.
LOC=$(curl -sS -b "$CJ" -c "$CJ" -o /dev/null -w '%{redirect_url}' -X POST "http://127.0.0.1:$PORT/register" \
    -d "name=Rehearsal Person" -d "phone=07519876543" -d "locale=ar" -d "accept_terms=1" -d "_token=$TOKEN")

printf '%s' "$LOC" | grep -q "/ar/account/telegram/link"; 
check "registration creates an account and redirects to the Arabic linking page" $?

BODY=$(curl -sS -b "$CJ" -c "$CJ" "$LOC")
printf '%s' "$BODY" > "$EV/rehearsal-registration-body.html"

grep -q 'dir="rtl"' "$EV/rehearsal-registration-body.html"
check "the linking page is served right-to-left for Arabic" $?

# The flash lives inside Inertia's data-page attribute, HTML-escaped and then
# JSON-escaped, so a literal grep cannot see it. Decode both layers and
# compare the actual value.
"$PHP_BIN" -r '
$html = file_get_contents($argv[1]);
if (!preg_match("/data-page=\"([^\"]+)\"/", $html, $m)) { exit(1); }
$page = json_decode(html_entity_decode($m[1], ENT_QUOTES, "UTF-8"), true);
$status = $page["props"]["flash"]["status"] ?? "";
exit($status === "تم إنشاء حسابك بنجاح" ? 0 : 1);
' "$EV/rehearsal-registration-body.html"
check "the localized account-created message reached the page" $?

GATED=$(curl -sS -b "$CJ" -o /dev/null -w '%{http_code}' "http://127.0.0.1:$PORT/ar/account/onboarding")
[ "$GATED" = "302" ]
check "an unlinked account is still refused by a protected page (got $GATED)" $?

pkill -f "php -S 127.0.0.1:$PORT" >/dev/null 2>&1 || true

echo
echo "DEPLOYMENT REHEARSAL: $pass passed, $fail failed"
[ "$fail" = "0" ]
