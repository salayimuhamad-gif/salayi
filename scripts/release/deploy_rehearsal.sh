#!/usr/bin/env bash
# Rehearse the release patch deployment against a disposable copy laid out
# exactly like the Hostinger target: ~/domains/<site>/application beside
# public_html.
#
# Nothing here touches the working tree or any live system. The staged copy is
# built from a chosen BASELINE, the patch is applied the way
# DEPLOYMENT_NOTES.md says to apply it, and the result is exercised over HTTP.
#
# TWO BASELINE CONTEXTS, selected by REHEARSAL_BASELINE_MODE, because the
# question "what is production running right now" changed its answer when the
# v7 release actually shipped:
#
#   post-v7     THE AUTHORITATIVE PRODUCTION-CANDIDATE GATE. Live production
#               deployed the v7 release and now runs the post-v7 baseline with
#               its migration ledger fully applied — sixty-six Ran rows, all
#               twelve inventory migrations included. The candidate on top of
#               it is an INCREMENTAL release that adds zero migrations, so the
#               rehearsal stages the PREVIOUS release's tree
#               (REHEARSAL_PREVIOUS), migrates it fully, pins the ledger at
#               exactly 66 with the whole inventory Ran BEFORE the apply, and
#               then proves the apply changes NOTHING in the ledger: migrate
#               answers "Nothing to migrate", the count stays 66, delta zero.
#
#   sealed-v6   The historical full-upgrade compatibility gate: the sealed v6
#               baseline (REHEARSAL_BASELINE) upgraded by the whole inventory,
#               pinning the original plus-twelve arithmetic. It remains a
#               required gate — it proves the complete inventory still applies
#               cleanly from scratch — but it is NOT a model of current
#               production and must never be read as one.
#
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
MODE="${REHEARSAL_BASELINE_MODE:-sealed-v6}"

# The baseline the site is staged FROM, by mode. Each mode requires its own
# source so a missing input fails loudly instead of silently rehearsing the
# wrong history.
case "$MODE" in
    post-v7)
        BASELINE="${REHEARSAL_PREVIOUS:?set REHEARSAL_PREVIOUS to the post-v7 previously-deployed tree (repo or archive)}"
        BASELINE_COMMIT="${REHEARSAL_PREVIOUS_COMMIT:-14672f4c80be40e45896822ef32210d8ee6ccbd1}"
        ;;
    sealed-v6)
        BASELINE="${REHEARSAL_BASELINE:?set REHEARSAL_BASELINE to the v6 baseline repo or archive}"
        BASELINE_COMMIT="${REHEARSAL_BASELINE_COMMIT:-9c0188f81843cfe4786b7f72ecdc2a3fae89cd82}"
        ;;
    *)
        echo "  FAIL  unknown REHEARSAL_BASELINE_MODE: $MODE (expected post-v7 or sealed-v6)"
        exit 1
        ;;
esac

# The ledger a fully-migrated post-v7 baseline must hold: the complete
# migration inventory of the previously-deployed tree. Production was verified
# at this exact count after the v7 deployment, and every post-v7 assertion
# below pins against it.
POST_V7_LEDGER=66
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

echo "== staging the $MODE baseline =="
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

[ -f "$SITE/application/artisan" ]; check "baseline staged from the $MODE commit" $?

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
# upgrade of a running site.
#
# sealed-v6: the v7 inventory adds exactly TWELVE migrations above this
# baseline (return handoffs, permanent verification tokens, password recovery
# challenges, optional profile columns, the presence column, the knowledge
# evidence-class column, the three data-only backfills, the WhatsApp
# verification table with its users column, the valuation rule engine's five
# tables with their four additive valuation columns, and the rule-set
# family-uniqueness key), and "exactly twelve" can only be demonstrated
# against a database that is already migrated to the baseline.
#
# post-v7: the baseline IS the fully-deployed previous release, so migrating
# it lands the ledger at the production count with the whole inventory Ran —
# the state live production was verified in — and the candidate on top must
# then change none of it.
( cd "$SITE/application" && "$PHP_BIN" artisan migrate --force >/dev/null 2>&1 )
check "baseline schema migrated before the patch is applied" $?

INVENTORY_RE='telegram_return_handoffs|telegram_verification_tokens|password_recovery_challenges|profile_optional_details|add_last_seen_to_users|add_evidence_class_to_knowledge_events|backfill_knowledge_event_search_keys|backfill_offer_search_keys|whatsapp_account_verification|backfill_price_record_scope_ids|valuation_rule_engine|valuation_rule_set_family_uniqueness'

if [ "$MODE" = "post-v7" ]; then
    # The production-baseline proofs, BEFORE anything is applied. These are
    # the facts the incremental release rests on, pinned fail-closed: a
    # baseline that is not at exactly the production ledger is not a model of
    # production, and continuing would rehearse a fiction.
    BASELINE_RAN=$( cd "$SITE/application" && "$PHP_BIN" artisan migrate:status 2>/dev/null | grep -c ' Ran' )
    [ "$BASELINE_RAN" = "$POST_V7_LEDGER" ]
    check "post-v7 baseline ledger holds exactly the production count ($BASELINE_RAN of $POST_V7_LEDGER)" $?

    BASELINE_INVENTORY_RAN=$( cd "$SITE/application" && "$PHP_BIN" artisan migrate:status 2>/dev/null \
        | grep -E "$INVENTORY_RE" | grep -c ' Ran' )
    [ "$BASELINE_INVENTORY_RAN" = "12" ]
    check "all twelve inventory migrations already Ran on the post-v7 baseline (found $BASELINE_INVENTORY_RAN)" $?

    BASELINE_PENDING=$( cd "$SITE/application" && "$PHP_BIN" artisan migrate:status 2>/dev/null | grep -c ' Pending' )
    [ "$BASELINE_PENDING" = "0" ]
    check "the post-v7 baseline has nothing Pending before the apply (found $BASELINE_PENDING)" $?
fi

echo "== 1. checksum verification =="
( cd "$(dirname "$RUNTIME")" && sha256sum -c "$(basename "$RUNTIME").zip.sha256" ) >/dev/null 2>&1
check "runtime archive checksum verifies before anything is touched" $?

echo "== 2. mandatory backups =="
# DEPLOYMENT_NOTES.md §2 requires TEN backups. This release touches several
# modules (Identity, Geography, Core, Projects) plus the route files, the HTTP
# bootstrap and two config files, so the backup is the WHOLE application-code
# directory rather than a per-module selection — a cherry-picked list tuned to
# one release is exactly how the rollback ends up missing the file it needs.
# The Composer trio (composer.json, composer.lock, vendor) is backed up as ONE
# unit: a rollback that restores old code under a new dependency tree — or the
# reverse — is exactly the mismatch this release exists to close.
TS=$(date +%Y%m%d-%H%M%S)
BACKUP="$STAGE/backup-$TS"
mkdir -p "$BACKUP"

cp -a "$SITE/application/app"               "$BACKUP/app"
cp -a "$SITE/application/lang"              "$BACKUP/lang"
cp -a "$SITE/application/config"            "$BACKUP/config"
cp -a "$SITE/application/routes"            "$BACKUP/routes"
cp -a "$SITE/application/bootstrap/app.php" "$BACKUP/bootstrap-app.php"
cp -a "$SITE/public_html/build"             "$BACKUP/build"
# public_html/map-styles ships with the map program; a pre-map production web
# root legitimately lacks it. Captured when present, its absence otherwise
# recorded by omission — the rollback restores whichever state was true.
if [ -d "$SITE/public_html/map-styles" ]; then
    cp -a "$SITE/public_html/map-styles"    "$BACKUP/map-styles"
fi
cp -a "$SITE/application/composer.json"     "$BACKUP/composer.json"
cp -a "$SITE/application/composer.lock"     "$BACKUP/composer.lock"
cp -a "$SITE/application/vendor"            "$BACKUP/vendor"
"$MYSQLDUMP_BIN" -h "$REHEARSAL_DB_HOST" -P "$REHEARSAL_DB_PORT" \
    -u "$REHEARSAL_DB_USER" -p"$REHEARSAL_DB_PASSWORD" "$REHEARSAL_DB_NAME" \
    > "$BACKUP/database.sql" 2>/dev/null

# Taking a backup and having a backup are different claims. Prove each item
# exists AND is readable AND is not empty.
backup_failures=0

for item in app lang config routes bootstrap-app.php build database.sql \
            composer.json composer.lock vendor; do
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
check "all ten documented backups taken, readable and non-empty" $?

printf '%s\n' "$BACKUP" > "$STAGE/LAST_BACKUP_PATH"

echo "== 3. staged in a NEW directory, not over the live tree =="
rm -rf "$STAGE/patch"
mkdir -p "$STAGE/patch"
unzip -q "$RUNTIME.zip" -d "$STAGE/patch"
[ -d "$STAGE/patch/application" ] && [ -d "$STAGE/patch/public_html/build" ]
check "patch unpacked to a staging directory" $?

[ -f "$STAGE/patch/public_html/map-styles/mulk-dark.json" ]
check "the patch ships the static map-styles web assets" $?

echo "== 4. maintenance mode =="
( cd "$SITE/application" && "$PHP_BIN" artisan down --render="errors::503" >/dev/null 2>&1 )
check "site placed in maintenance mode before files move" $?

echo "== 5. apply =="
# The staged BASELINE runs on a stand-in vendor (REHEARSAL_VENDOR — the CI dev
# tree, standing in for production's own old dependencies). Prove the stand-in
# is really there with its dev-only marker BEFORE the overlay, so the absence
# of that marker afterwards demonstrates a real replacement rather than a
# vacuous check.
[ -d "$SITE/application/vendor/phpunit" ]
check "baseline runs on the stand-in vendor (dev marker present before apply)" $?

# routes/ and bootstrap/app.php are part of this release's delta: the Telegram
# password-recovery routes live in routes/auth.php and the presence middleware
# is registered in bootstrap/app.php. Copying app/ alone would deploy
# controllers whose routes never arrive.
cp -a "$STAGE/patch/application/app/." "$SITE/application/app/"
cp -a "$STAGE/patch/application/lang/." "$SITE/application/lang/"
cp -a "$STAGE/patch/application/config/." "$SITE/application/config/" 2>/dev/null || true
cp -a "$STAGE/patch/application/routes/." "$SITE/application/routes/"
cp -a "$STAGE/patch/application/bootstrap/app.php" "$SITE/application/bootstrap/app.php"

# The Composer trio travels with the code, from the SAME checksum-verified
# runtime archive, and the vendor tree is REPLACED, never merged — a merged
# vendor keeps orphaned classes beside a new autoloader exactly the way a
# merged build directory keeps stale chunks. No Composer, no network: the
# dependency bytes were installed --no-dev from the frozen lock inside the
# release cycle and tested there.
cp -a "$STAGE/patch/application/composer.json" "$SITE/application/composer.json"
cp -a "$STAGE/patch/application/composer.lock" "$SITE/application/composer.lock"
rm -rf "$SITE/application/vendor"
cp -a "$STAGE/patch/application/vendor" "$SITE/application/vendor"

# Rehearsal #36's root cause: the baseline phase booted on the dev stand-in
# vendor and Laravel wrote bootstrap/cache/{packages.php,services.php} with
# the dev-only Collision provider in them. After the swap above, the very
# next artisan command boots THROUGH those stale manifests and dies on the
# provider class the production vendor deliberately lacks — package:discover
# included, so the state can never repair itself. The discovery manifests
# are invalidated the moment the tree changes, BEFORE any artisan runs.
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
bash "$SCRIPT_DIR/invalidate_package_manifests.sh" "$SITE/application"
[ ! -e "$SITE/application/bootstrap/cache/packages.php" ] \
    && [ ! -e "$SITE/application/bootstrap/cache/services.php" ]
check "stale package-discovery manifests invalidated before any artisan boots" $?

rm -rf "$SITE/public_html/build"
cp -a "$STAGE/patch/public_html/build" "$SITE/public_html/"
check "runtime files applied" $?

# Static public assets: replaced whole, exactly like build — and ONLY the
# named directory, never a blind public_html copy (index.php, .htaccess and
# .user.ini are host-owned runtime files and stay untouched).
rm -rf "$SITE/public_html/map-styles"
cp -a "$STAGE/patch/public_html/map-styles" "$SITE/public_html/"
check "static map-styles web assets applied" $?

# Dependency-state proofs, in the same spirit as the build-directory ones:
# the deployed lock IS the shipped lock, the tree serving requests is the
# runtime's production tree (the stand-in's dev marker must be gone), and
# Composer's own runtime API reports the locked CommonMark — the package
# production was caught holding at a superseded version.
cmp -s "$SITE/application/composer.lock" "$STAGE/patch/application/composer.lock"
check "the deployed composer.lock matches the runtime lock byte-for-byte" $?

[ ! -d "$SITE/application/vendor/phpunit" ]
check "the deployed vendor is the production tree, not the CI dev stand-in" $?

WANT_COMMONMARK=$("$PHP_BIN" -r '$l = json_decode(file_get_contents($argv[1]), true); foreach ($l["packages"] as $p) { if ($p["name"] === "league/commonmark") { echo $p["version"]; } }' "$STAGE/patch/application/composer.lock")
GOT_COMMONMARK=$(cd "$SITE/application" && "$PHP_BIN" -r 'require "vendor/autoload.php"; echo \Composer\InstalledVersions::getPrettyVersion("league/commonmark");' 2>/dev/null)
[ -n "$WANT_COMMONMARK" ] && [ "$GOT_COMMONMARK" = "$WANT_COMMONMARK" ] && [ "$GOT_COMMONMARK" != "2.8.3" ]
check "deployed CommonMark is the locked version, not the superseded 2.8.3 ($GOT_COMMONMARK)" $?

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

# The static map-styles assets, proven with the same rigor as build: arrived,
# byte-identical to the staged runtime copy, structurally valid JSON, and
# carrying the MULK Dark identity the map explorer resolves by default.
[ -f "$SITE/public_html/map-styles/mulk-dark.json" ]
check "the MULK dark style arrived at the web root" $?

DEPLOYED_STYLES=$(cd "$SITE/public_html/map-styles" && find . -type f | sort)
STAGED_STYLES=$(cd "$STAGE/patch/public_html/map-styles" && find . -type f | sort)
[ "$DEPLOYED_STYLES" = "$STAGED_STYLES" ]
check "the deployed map-styles directory matches the staged runtime exactly" $?

cmp -s "$SITE/public_html/map-styles/mulk-dark.json" "$STAGE/patch/public_html/map-styles/mulk-dark.json"
check "the deployed MULK dark style is byte-identical to the staged runtime copy" $?

"$PHP_BIN" -r '$style = json_decode(file_get_contents($argv[1]), true); exit(json_last_error() === JSON_ERROR_NONE && is_array($style) && ($style["name"] ?? "") === "MULK Dark (OpenFreeMap vector)" && ($style["version"] ?? 0) === 8 ? 0 : 1);' "$SITE/public_html/map-styles/mulk-dark.json"
check "the deployed style is valid JSON and carries the MULK Dark identity" $?

# The basemap must stay keyless: no request the style provokes may hit an
# API-key-demanding endpoint. The style names its provider explicitly, so
# the deployed bytes are checked for both directions of the contract.
"$PHP_BIN" -r '$raw = file_get_contents($argv[1]); exit(stripos($raw, "carto") === false && strpos($raw, "basemaps/apikey") === false && strpos($raw, "API KEY REQUIRED") === false && strpos($raw, "tiles.openfreemap.org") !== false ? 0 : 1);' "$SITE/public_html/map-styles/mulk-dark.json"
check "the deployed basemap is keyless OpenFreeMap with no API-key endpoint" $?

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

if [ "$retired" -gt "0" ]; then
    [ "$stale_kept" = "0" ]
    check "every retired build chunk is gone ($retired retired, $stale_kept survived)" $?
else
    # A tooling-only release rebuilds byte-identical assets: nothing retires,
    # so nothing can survive. That verdict is only trustworthy when the
    # comparison genuinely ran over the real lists and the two builds are the
    # SAME set — assert exactly that, instead of assuming every release
    # changes the build. A zero-retired result with differing or empty lists
    # still fails: it would mean the comparison broke, not that the
    # deployment is clean.
    [ -n "$PREVIOUS_LIST" ] && [ "$PREVIOUS_LIST" = "$STAGED_LIST" ]
    check "no chunk retired: the staged build is the same set as the previous build" $?
fi

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
# package:discover FIRST: the vendor tree just changed, the stale discovery
# manifests were invalidated in §5, and this rebuilds them from the SHIPPED
# vendor. It is the offline stand-in for the composer post-autoload-dump
# hook the production build deliberately skipped (--no-scripts). Its output
# is EVIDENCE, not noise — rehearsal #36 threw the real exception away with
# >/dev/null and left a bare FAIL nobody could act on.
( cd "$SITE/application" && "$PHP_BIN" artisan package:discover ) \
    > "$EV/package-discover.log" 2>&1
DISCOVER_RC=$?
if [ "$DISCOVER_RC" != "0" ]; then
    echo "  ---- package:discover output (see rehearsal evidence: package-discover.log) ----"
    tail -n 40 "$EV/package-discover.log" | sed 's/^/    /'
fi
[ "$DISCOVER_RC" = "0" ]
check "package manifest rediscovered against the shipped vendor" $?

( cd "$SITE/application" && "$PHP_BIN" artisan config:clear && "$PHP_BIN" artisan route:clear \
    && "$PHP_BIN" artisan view:clear && "$PHP_BIN" artisan config:cache \
    && "$PHP_BIN" artisan route:cache && "$PHP_BIN" artisan view:cache ) \
    > "$EV/cache-rebuild.log" 2>&1
CACHES_RC=$?
if [ "$CACHES_RC" != "0" ]; then
    echo "  ---- cache rebuild output (see rehearsal evidence: cache-rebuild.log) ----"
    tail -n 40 "$EV/cache-rebuild.log" | sed 's/^/    /'
fi
[ "$CACHES_RC" = "0" ]
check "configuration, route and view caches rebuilt" $?

if [ "$MODE" = "post-v7" ]; then
    echo "== 8. migrations (this candidate adds exactly ZERO) =="
    # The incremental contract, pinned in both directions and with the
    # command's own words kept as evidence: the candidate ships no new
    # migration files, so running the standard migrate step against the
    # fully-migrated production baseline must answer "Nothing to migrate",
    # the ledger must hold the production count on both sides, and the delta
    # must be exactly zero. A positive delta means a migration file arrived
    # that the release does not declare; a negative one is impossible short
    # of corruption; either is a hard stop.
    BEFORE=$( cd "$SITE/application" && "$PHP_BIN" artisan migrate:status 2>/dev/null | grep -c ' Ran' )
    [ "$BEFORE" = "$POST_V7_LEDGER" ]
    check "ledger before the migrate step is the production count ($BEFORE of $POST_V7_LEDGER)" $?

    ( cd "$SITE/application" && "$PHP_BIN" artisan migrate --force ) \
        > "$EV/production-migrate.log" 2>&1
    MIGRATE_RC=$?
    if [ "$MIGRATE_RC" != "0" ]; then
        echo "  ---- migrate output (see rehearsal evidence: production-migrate.log) ----"
        tail -n 40 "$EV/production-migrate.log" | sed 's/^/    /'
    fi
    [ "$MIGRATE_RC" = "0" ]
    check "the standard migrate step exits cleanly on the incremental candidate" $?

    grep -qi "Nothing to migrate" "$EV/production-migrate.log"
    check "the migrate step reports Nothing to migrate (captured as evidence)" $?

    AFTER=$( cd "$SITE/application" && "$PHP_BIN" artisan migrate:status 2>/dev/null | grep -c ' Ran' )
    DELTA=$(( AFTER - BEFORE ))
    [ "$AFTER" = "$POST_V7_LEDGER" ] && [ "$DELTA" = "0" ]
    check "ledger unchanged by the incremental candidate ($BEFORE -> $AFTER, delta $DELTA)" $?

    POST_PENDING=$( cd "$SITE/application" && "$PHP_BIN" artisan migrate:status 2>/dev/null | grep -c ' Pending' )
    [ "$POST_PENDING" = "0" ]
    check "the applied candidate introduces no Pending migrations (found $POST_PENDING)" $?

    INVENTORY_STILL_RAN=$( cd "$SITE/application" && "$PHP_BIN" artisan migrate:status 2>/dev/null \
        | grep -E "$INVENTORY_RE" | grep -c ' Ran' )
    [ "$INVENTORY_STILL_RAN" = "12" ]
    check "all twelve inventory migrations still Ran after the apply (found $INVENTORY_STILL_RAN)" $?
else
    echo "== 8. migrations (this patch adds exactly twelve) =="
    BEFORE=$( cd "$SITE/application" && "$PHP_BIN" artisan migrate:status 2>/dev/null | grep -c Ran )
    ( cd "$SITE/application" && "$PHP_BIN" artisan migrate --force >/dev/null 2>&1 )
    AFTER=$( cd "$SITE/application" && "$PHP_BIN" artisan migrate:status 2>/dev/null | grep -c Ran )
    DELTA=$(( AFTER - BEFORE ))

    # This release ships TWELVE forward-only migrations above the v6 baseline:
    # the five v7 identity migrations, the hardening program's three (the
    # knowledge evidence-class column and the two data-only search-key
    # backfills), the dual-verification WhatsApp table with its users
    # column, the data-only price-record scope_id backfill that makes
    # historical imported prices visible to the scoped market indices,
    # the Wave 6 valuation rule engine (five additive rule/answer/snapshot
    # tables plus four nullable portfolio_valuations columns), and the
    # rule-set family-uniqueness key (a generated project_family column with
    # its unique index, so duplicate global versions die at the database).
    # Asserting the exact number matters in both directions: a
    # smaller delta means a table or column never arrived (and if it is the
    # verification-token table, nobody can finish a registration), while a
    # larger one would mean something unintended came with it.
    [ "$DELTA" = "12" ]
    check "exactly twelve migrations applied ($BEFORE -> $AFTER)" $?

    # Then prove each named migration is the one that ran — a count alone cannot
    # tell twelve expected arrivals apart from eleven expected and one stranger.
    for migration in \
        2026_08_06_000100_telegram_return_handoffs \
        2026_08_09_000100_telegram_verification_tokens \
        2026_08_09_000200_password_recovery_challenges \
        2026_08_09_000200_profile_optional_details \
        2026_08_09_000300_add_last_seen_to_users \
        2026_08_16_000100_add_evidence_class_to_knowledge_events \
        2026_08_17_000100_backfill_knowledge_event_search_keys \
        2026_08_17_000200_backfill_offer_search_keys \
        2026_08_19_000100_whatsapp_account_verification \
        2026_08_21_000100_backfill_price_record_scope_ids \
        2026_08_22_000100_valuation_rule_engine \
        2026_08_22_000200_valuation_rule_set_family_uniqueness; do
        ( cd "$SITE/application" && "$PHP_BIN" artisan migrate:status 2>/dev/null \
            | grep "$migration" | grep -q "Ran" )
        check "migration ran: $migration" $?
    done
fi

echo "== 9. runtime checks =="
( cd "$SITE/application" && "$PHP_BIN" artisan route:list >/dev/null 2>&1 )
check "route table resolves" $?

# The Telegram ownership-transfer service — part of deployed production since
# Release #38, and of every candidate above it — must load through the
# DEPLOYED autoloader (its class map was dumped inside the release cycle) and
# its confirmation endpoint must resolve — both against the already-migrated
# schema, because the transfer ships no migration of its own and operates
# entirely on columns and tables the ledger already holds.
( cd "$SITE/application" && "$PHP_BIN" -r 'require "vendor/autoload.php"; exit(class_exists("App\\Modules\\Identity\\Services\\TelegramOwnershipTransfer") ? 0 : 1);' )
check "the ownership-transfer service loads through the deployed autoloader" $?

( cd "$SITE/application" && "$PHP_BIN" artisan route:list 2>/dev/null | grep -q "account/telegram/link/confirm" )
check "the transfer confirmation endpoint resolves on the migrated schema" $?

( cd "$SITE/application" && "$PHP_BIN" artisan route:list 2>/dev/null | grep -q "account/registration/abandon" )
check "the registration-abandon route is present" $?

( cd "$SITE/application" && "$PHP_BIN" artisan route:list 2>/dev/null | grep -q "forgot-password/telegram" )
check "the Telegram password-recovery routes are present" $?

( cd "$SITE/application" && "$PHP_BIN" artisan route:list 2>/dev/null | grep -q "invest/features" )
check "the investment-map routes arrived (feature-flag gated at request time)" $?

( cd "$SITE/application" && "$PHP_BIN" artisan schedule:list 2>/dev/null | grep -q "prune-unlinked" )
check "the reclaim command is scheduled" $?

( cd "$SITE/application" && "$PHP_BIN" artisan schedule:list 2>/dev/null | grep -q "recovery:prune" )
check "the recovery-challenge pruner is scheduled" $?

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

STYLE_CODE=$(curl -sS -o /dev/null -w '%{http_code}' "http://127.0.0.1:$PORT/map-styles/mulk-dark.json" || echo 000)
[ "$STYLE_CODE" = "200" ]
check "the MULK dark style is served over HTTP (got $STYLE_CODE)" $?

echo "== 11. the new registration flow, end to end on the deployed copy =="
CJ=$(mktemp)
TOKEN=$(curl -sS -c "$CJ" "http://127.0.0.1:$PORT/register" | grep -oE 'csrf-token" content="[^"]+' | head -1 | cut -d'"' -f3)
# Two steps, because curl -L re-POSTs to the redirect target and the app
# answers 405. The browser does a GET here; so does this.
#
# Registration REQUIRES a password under account-first: the form posts one and
# confirms it, so the rehearsal does too. The value only has to satisfy the
# platform rule (minimum length, mixed case, numbers, symbols) — it belongs to
# a throwaway account inside a disposable database.
REHEARSAL_PASSWORD='Rehearse-MyHawler-2026!x7Kq'
LOC=$(curl -sS -b "$CJ" -c "$CJ" -o /dev/null -w '%{redirect_url}' -X POST "http://127.0.0.1:$PORT/register" \
    -d "name=Rehearsal Person" -d "phone=07519876543" \
    -d "password=$REHEARSAL_PASSWORD" -d "password_confirmation=$REHEARSAL_PASSWORD" \
    -d "locale=ar" -d "accept_terms=1" -d "_token=$TOKEN")

# Registration is CHOICE-FIRST since the dual-verification release: the form
# creates the account and lands on the verification choice, where the person
# picks Telegram or WhatsApp. Landing directly on the Telegram linking page
# is the pre-choice contract and must FAIL here.
printf '%s' "$LOC" | grep -q "/ar/account/verify$"
check "registration creates an account and redirects to the Arabic verification choice" $?

BODY=$(curl -sS -b "$CJ" -c "$CJ" "$LOC")
printf '%s' "$BODY" > "$EV/rehearsal-registration-body.html"

grep -q 'dir="rtl"' "$EV/rehearsal-registration-body.html"
check "the choice page is served right-to-left for Arabic" $?

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

# Decode the same attribute to prove WHAT the page offers. This rehearsal
# never carries Bird credentials — deliberately, exactly like a host where the
# operator has not configured WhatsApp — so the deployed contract is: the
# verification-choice component, Telegram offered, WhatsApp marked unavailable
# rather than erroring. Availability is configuration, not code.
"$PHP_BIN" -r '
$html = file_get_contents($argv[1]);
if (!preg_match("/data-page=\"([^\"]+)\"/", $html, $m)) { exit(1); }
$page = json_decode(html_entity_decode($m[1], ENT_QUOTES, "UTF-8"), true);
exit(($page["component"] ?? "") === "Account/VerifyChoice" ? 0 : 1);
' "$EV/rehearsal-registration-body.html"
check "the landing is the verification-choice component" $?

"$PHP_BIN" -r '
$html = file_get_contents($argv[1]);
if (!preg_match("/data-page=\"([^\"]+)\"/", $html, $m)) { exit(1); }
$page = json_decode(html_entity_decode($m[1], ENT_QUOTES, "UTF-8"), true);
exit(($page["props"]["telegram_available"] ?? false) === true ? 0 : 1);
' "$EV/rehearsal-registration-body.html"
check "the Telegram door is offered on the choice page" $?

"$PHP_BIN" -r '
$html = file_get_contents($argv[1]);
if (!preg_match("/data-page=\"([^\"]+)\"/", $html, $m)) { exit(1); }
$page = json_decode(html_entity_decode($m[1], ENT_QUOTES, "UTF-8"), true);
exit(($page["props"]["whatsapp_available"] ?? true) === false ? 0 : 1);
' "$EV/rehearsal-registration-body.html"
check "the WhatsApp door is marked unavailable while Bird is unconfigured" $?

# Choosing Telegram is a plain link out of the choice page. Follow it with
# the same session and prove the Arabic linking page is what answers.
LINK_BODY=$(curl -sS -b "$CJ" -c "$CJ" "http://127.0.0.1:$PORT/ar/account/telegram/link")
printf '%s' "$LINK_BODY" > "$EV/rehearsal-telegram-link-body.html"

"$PHP_BIN" -r '
$html = file_get_contents($argv[1]);
if (!preg_match("/data-page=\"([^\"]+)\"/", $html, $m)) { exit(1); }
$page = json_decode(html_entity_decode($m[1], ENT_QUOTES, "UTF-8"), true);
exit(($page["component"] ?? "") === "Account/TelegramLink" ? 0 : 1);
' "$EV/rehearsal-telegram-link-body.html"
check "choosing Telegram reaches the linking page" $?

grep -q 'dir="rtl"' "$EV/rehearsal-telegram-link-body.html"
check "the linking page is served right-to-left for Arabic" $?

GATED=$(curl -sS -b "$CJ" -o /dev/null -w '%{http_code}' "http://127.0.0.1:$PORT/ar/account/onboarding")
[ "$GATED" = "302" ]
check "an unverified account is still refused by a protected page (got $GATED)" $?

# The refusal must point BACK at the verification choice: the gate's redirect
# target is part of the dual-verification contract, and a gate that still
# pointed at the old direct linking page would strand the WhatsApp door.
GATED_LOC=$(curl -sS -b "$CJ" -o /dev/null -w '%{redirect_url}' "http://127.0.0.1:$PORT/ar/account/onboarding")
printf '%s' "$GATED_LOC" | grep -q "/ar/account/verify$"
check "the refusal sends the unverified account to the verification choice" $?

pkill -f "php -S 127.0.0.1:$PORT" >/dev/null 2>&1 || true

echo
echo "DEPLOYMENT REHEARSAL: $pass passed, $fail failed"
[ "$fail" = "0" ]
