#!/usr/bin/env bash
# Fresh-session rollback rehearsal for the INCREMENTAL post-v7 release.
#
# Run under `env -i`: no inherited environment, no warmed caches, no variables
# left over from the deployment — the situation a rollback actually happens in.
#
# THE CONTRACT THIS REVERSES: the production candidate is an incremental
# release on top of the deployed post-v7 baseline. It ships ZERO migrations,
# so its rollback is CODE AND RUNTIME ONLY — application code, lang, config,
# routes, the HTTP bootstrap, the public build, and the Composer trio
# (composer.json, composer.lock, vendor) restored coherently from the backup
# the deployment took, with the generated package-discovery manifests
# invalidated between the vendor restore and the first artisan boot.
#
# THE DATABASE IS NOT TOUCHED. The ledger holds the production count before
# the rollback and must hold exactly the same count after it: no migration
# reversal of any kind, no step-based or batch walk, no database restore.
# Every one of the twelve inventory migrations — the protected five included —
# stays Ran, because none of them belongs to the release being reversed.
#
# This reverses a REAL deployed tree produced by deploy_rehearsal.sh in
# post-v7 mode. It does not reverse a git diff.
set -uo pipefail

#   $1 / REHEARSAL_STAGE     the staged site produced by the post-v7 deploy
#                            rehearsal
#   REHEARSAL_EVIDENCE       where JSON/log evidence is written
#   REHEARSAL_BACKUP         the backup directory to restore FROM; defaults to
#                            the one the deployment rehearsal recorded
STAGE="${1:-${REHEARSAL_STAGE:-./rehearsal}}"
EV="${REHEARSAL_EVIDENCE:-$STAGE/evidence}"
PHP_BIN="${REHEARSAL_PHP:-php}"
PHP="$PHP_BIN"

mkdir -p "$EV"
SITE="$STAGE/domains/myhawler.test"
PORT="${REHEARSAL_PORT:-8125}"

# The ledger a post-v7 production host holds, before AND after this rollback.
POST_V7_LEDGER=66

INVENTORY_RE='telegram_return_handoffs|telegram_verification_tokens|password_recovery_challenges|profile_optional_details|add_last_seen_to_users|add_evidence_class_to_knowledge_events|backfill_knowledge_event_search_keys|backfill_offer_search_keys|whatsapp_account_verification|backfill_price_record_scope_ids|valuation_rule_engine|valuation_rule_set_family_uniqueness'
PROTECTED_RE='telegram_return_handoffs|telegram_verification_tokens|password_recovery_challenges|profile_optional_details|add_last_seen_to_users'

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

echo "== 0. identify the backups, knowing nothing =="
if [ -n "${REHEARSAL_BACKUP:-}" ]; then
    BACKUP="$REHEARSAL_BACKUP"
elif [ -f "$STAGE/LAST_BACKUP_PATH" ]; then
    BACKUP=$(cat "$STAGE/LAST_BACKUP_PATH")
else
    BACKUP=$(ls -dt "$STAGE"/backup-* 2>/dev/null | head -1)
fi
[ -n "$BACKUP" ] && [ -d "$BACKUP" ]
check "found the pre-deployment backup by listing, not by remembering a variable" $?

[ -d "$BACKUP/app" ] && [ -d "$BACKUP/lang" ] && [ -d "$BACKUP/config" ] \
    && [ -d "$BACKUP/routes" ] && [ -f "$BACKUP/bootstrap-app.php" ] && [ -d "$BACKUP/build" ] \
    && [ -f "$BACKUP/composer.json" ] && [ -f "$BACKUP/composer.lock" ] && [ -d "$BACKUP/vendor" ]
check "backup contains the code, config, routes, bootstrap, build and the Composer trio" $?

# The dump exists because the deployment takes one unconditionally — but this
# rollback NEVER restores it. It is the emergency artifact of a different,
# explicitly-authorized procedure, not a step of this one.
[ -s "$BACKUP/database.sql" ]
check "the mandatory database dump exists in the backup (and stays unused here)" $?

BASELINE_MANIFEST=$(sha256sum "$BACKUP/build/manifest.json" | cut -d' ' -f1)
echo "  BASELINE_MANIFEST=$BASELINE_MANIFEST"

echo "== 1. the ledger BEFORE the rollback — the number that must not move =="
RAN_BEFORE=$( cd "$SITE/application" && "$PHP" artisan migrate:status 2>/dev/null | grep -c ' Ran' )
[ "$RAN_BEFORE" = "$POST_V7_LEDGER" ]
check "ledger holds the production count before the rollback ($RAN_BEFORE of $POST_V7_LEDGER)" $?

INVENTORY_BEFORE=$( cd "$SITE/application" && "$PHP" artisan migrate:status 2>/dev/null \
    | grep -E "$INVENTORY_RE" | grep -c ' Ran' )
[ "$INVENTORY_BEFORE" = "12" ]
check "all twelve inventory migrations Ran before the rollback (found $INVENTORY_BEFORE)" $?

STATUS_BEFORE=$( cd "$SITE/application" && "$PHP" artisan migrate:status 2>/dev/null | sha256sum | cut -d' ' -f1 )

echo "== 2. maintenance mode first =="
( cd "$SITE/application" && "$PHP" artisan down >/dev/null 2>&1 )
[ -f "$SITE/application/storage/framework/down" ] || [ -f "$SITE/application/storage/framework/maintenance.php" ]
check "site placed in maintenance mode before anything is moved" $?

echo "== 3. restore the application code (no migration is touched) =="
FAILED_DIR="$STAGE/failed-$(date +%Y%m%d-%H%M%S)"
mkdir -p "$FAILED_DIR"
cp -a "$SITE/application/app" "$FAILED_DIR/app"
check "the failed release is kept for diagnosis" $?

rm -rf "$SITE/application/app"
cp -a "$BACKUP/app" "$SITE/application/app"
check "pre-deployment application code restored" $?

rm -rf "$SITE/application/lang"
cp -a "$BACKUP/lang" "$SITE/application/lang"
check "pre-deployment language files restored" $?

rm -rf "$SITE/application/config"
cp -a "$BACKUP/config" "$SITE/application/config"
check "pre-deployment configuration restored from the backup" $?

rm -rf "$SITE/application/routes"
cp -a "$BACKUP/routes" "$SITE/application/routes"
check "pre-deployment routes restored from the backup" $?

cp -a "$BACKUP/bootstrap-app.php" "$SITE/application/bootstrap/app.php"
check "pre-deployment HTTP bootstrap restored from the backup" $?

# The Composer trio moves as ONE unit with the code, and the vendor tree is
# REPLACED, never merged — restored code under the candidate's dependency
# tree, or the reverse, is exactly the mismatch the trio rule exists to close.
cp -a "$BACKUP/composer.json" "$SITE/application/composer.json"
cp -a "$BACKUP/composer.lock" "$SITE/application/composer.lock"
rm -rf "$SITE/application/vendor"
cp -a "$BACKUP/vendor" "$SITE/application/vendor"
check "pre-deployment composer.json, composer.lock and vendor restored as one unit" $?

cmp -s "$SITE/application/composer.lock" "$BACKUP/composer.lock"
check "the restored composer.lock matches the backup byte-for-byte" $?

RESTORED_VENDOR_LIST=$(cd "$SITE/application/vendor" && find . -type f | sort | sha256sum | cut -d' ' -f1)
BACKUP_VENDOR_LIST=$(cd "$BACKUP/vendor" && find . -type f | sort | sha256sum | cut -d' ' -f1)
[ "$RESTORED_VENDOR_LIST" = "$BACKUP_VENDOR_LIST" ]
check "the restored vendor tree matches the backup exactly" $?

# The dependency tree just changed direction (new -> old): the generated
# discovery manifests written for the candidate's vendor must not survive
# under the restored one, and they go BEFORE the first artisan command boots
# on the restored tree. Then package:discover rebuilds them from the restored
# vendor, with its output kept as evidence.
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
bash "$SCRIPT_DIR/invalidate_package_manifests.sh" "$SITE/application"
[ ! -e "$SITE/application/bootstrap/cache/packages.php" ] \
    && [ ! -e "$SITE/application/bootstrap/cache/services.php" ]
check "candidate-vendor discovery manifests invalidated before any artisan boots" $?

( cd "$SITE/application" && "$PHP" artisan package:discover ) \
    > "$EV/production-rollback-package-discover.log" 2>&1
DISCOVER_RC=$?
if [ "$DISCOVER_RC" != "0" ]; then
    echo "  ---- package:discover output (see rehearsal evidence: production-rollback-package-discover.log) ----"
    tail -n 40 "$EV/production-rollback-package-discover.log" | sed 's/^/    /'
fi
[ "$DISCOVER_RC" = "0" ]
check "package manifest rediscovered against the restored vendor" $?

echo "== 4. restore the public build (replaced, never merged) =="
rm -rf "$SITE/public_html/build"
cp -a "$BACKUP/build" "$SITE/public_html/build"
check "pre-deployment public build restored" $?

RESTORED_LIST=$(cd "$SITE/public_html/build" && find . -type f | sort)
BACKUP_LIST=$(cd "$BACKUP/build" && find . -type f | sort)
[ "$RESTORED_LIST" = "$BACKUP_LIST" ]
check "the restored build directory matches the backup exactly" $?

RESTORED_MANIFEST=$(sha256sum "$SITE/public_html/build/manifest.json" | cut -d' ' -f1)
echo "  RESTORED_MANIFEST=$RESTORED_MANIFEST"
[ "$RESTORED_MANIFEST" = "$BASELINE_MANIFEST" ]
check "restored manifest is byte-identical to the pre-deployment build" $?

# Static map-styles assets: BASELINE-RELATIVE, the backup decides. Present in
# the backup: restored to match it exactly. Absent (the pre-deployment web
# root had none): the candidate's copy is removed, restoring that absence —
# either direction, the web root ends exactly as it was before the deploy.
if [ -d "$BACKUP/map-styles" ]; then
    rm -rf "$SITE/public_html/map-styles"
    cp -a "$BACKUP/map-styles" "$SITE/public_html/map-styles"
    RESTORED_STYLES=$(cd "$SITE/public_html/map-styles" && find . -type f | sort)
    BACKUP_STYLES=$(cd "$BACKUP/map-styles" && find . -type f | sort)
    [ "$RESTORED_STYLES" = "$BACKUP_STYLES" ]
    check "pre-deployment map-styles restored to match the backup exactly" $?
else
    rm -rf "$SITE/public_html/map-styles"
    [ ! -e "$SITE/public_html/map-styles" ]
    check "the candidate's map-styles removed (absent from the pre-deployment web root)" $?
fi

echo "== 5. rebuild caches against the restored code =="
( cd "$SITE/application" && "$PHP" artisan config:clear && "$PHP" artisan route:clear \
    && "$PHP" artisan view:clear && "$PHP" artisan config:cache \
    && "$PHP" artisan route:cache && "$PHP" artisan view:cache ) \
    > "$EV/production-rollback-cache-rebuild.log" 2>&1
CACHES_RC=$?
if [ "$CACHES_RC" != "0" ]; then
    echo "  ---- cache rebuild output (see rehearsal evidence: production-rollback-cache-rebuild.log) ----"
    tail -n 40 "$EV/production-rollback-cache-rebuild.log" | sed 's/^/    /'
fi
[ "$CACHES_RC" = "0" ]
check "caches cleared and rebuilt against the restored code" $?

echo "== 6. the ledger AFTER — identical, to the row =="
RAN_AFTER=$( cd "$SITE/application" && "$PHP" artisan migrate:status 2>/dev/null | grep -c ' Ran' )
[ "$RAN_AFTER" = "$POST_V7_LEDGER" ]
check "ledger still holds the production count after the rollback ($RAN_AFTER of $POST_V7_LEDGER)" $?

STATUS_AFTER=$( cd "$SITE/application" && "$PHP" artisan migrate:status 2>/dev/null | sha256sum | cut -d' ' -f1 )
[ "$STATUS_BEFORE" = "$STATUS_AFTER" ]
check "the migration status table is byte-identical before and after" $?

INVENTORY_AFTER=$( cd "$SITE/application" && "$PHP" artisan migrate:status 2>/dev/null \
    | grep -E "$INVENTORY_RE" | grep -c ' Ran' )
[ "$INVENTORY_AFTER" = "12" ]
check "all twelve inventory migrations still Ran (found $INVENTORY_AFTER)" $?

PROTECTED_AFTER=$( cd "$SITE/application" && "$PHP" artisan migrate:status 2>/dev/null \
    | grep -E "$PROTECTED_RE" | grep -c ' Ran' )
[ "$PROTECTED_AFTER" = "5" ]
check "the protected five are untouched (found $PROTECTED_AFTER Ran)" $?

PENDING_AFTER=$( cd "$SITE/application" && "$PHP" artisan migrate:status 2>/dev/null | grep -c ' Pending' )
[ "$PENDING_AFTER" = "0" ]
check "nothing reads Pending after the rollback (found $PENDING_AFTER)" $?

echo "== 7. the restored application boots on the untouched schema =="
ROUTES=$( cd "$SITE/application" && "$PHP" artisan route:list 2>/dev/null | grep -c . )
[ "$ROUTES" -gt 100 ]
check "route table resolves ($ROUTES lines)" $?

# BASELINE-RELATIVE: the restored tree must MATCH the pre-deployment backup,
# so the BACKUP decides whether the ownership-transfer service exists after
# the rollback — never a hard-coded era. Since Release #38 the service IS
# part of deployed production, so a faithful rollback preserves it
# byte-for-byte; against an older backup that predates it, the same contract
# proves it gone. A candidate-only file surviving and a baseline file
# vanishing are the same failure: a partial restore.
OWNERSHIP_REL="app/Modules/Identity/Services/TelegramOwnershipTransfer.php"
if [ -f "$BACKUP/$OWNERSHIP_REL" ]; then
    OWNERSHIP_IN_BACKUP=true
    cmp -s "$SITE/application/$OWNERSHIP_REL" "$BACKUP/$OWNERSHIP_REL"
    check "the ownership-transfer service is restored byte-identical to the backup (present in the previous release)" $?

    ( cd "$SITE/application" && "$PHP" -r 'require "vendor/autoload.php"; exit(class_exists("App\\Modules\\Identity\\Services\\TelegramOwnershipTransfer") ? 0 : 1);' )
    check "the restored ownership-transfer service loads through the restored autoloader" $?
else
    OWNERSHIP_IN_BACKUP=false
    [ ! -e "$SITE/application/$OWNERSHIP_REL" ]
    check "the candidate's ownership-transfer service file is gone after the rollback (absent from the previous release)" $?

    ( cd "$SITE/application" && "$PHP" -r 'require "vendor/autoload.php"; exit(class_exists("App\\Modules\\Identity\\Services\\TelegramOwnershipTransfer") ? 1 : 0);' )
    check "the candidate's ownership-transfer service does not load after the rollback" $?
fi

# The restored previous release still serves its own full surface — these
# routes predate the candidate and must survive a code-only rollback intact.
( cd "$SITE/application" && "$PHP" artisan route:list 2>/dev/null | grep -q "account/verify" )
check "the verification-choice routes are still present on the restored code" $?

( cd "$SITE/application" && "$PHP" artisan route:list 2>/dev/null | grep -q "account/telegram/link/confirm" )
check "the pre-candidate confirmation endpoint is still present" $?

( cd "$SITE/application" && "$PHP" artisan schedule:list >/dev/null 2>&1 )
check "scheduler registers" $?

( cd "$SITE/application" && "$PHP" artisan queue:work --stop-when-empty --max-time=10 >/dev/null 2>&1 )
check "queue worker runs" $?

echo "== 8. bring the site back and smoke it =="
( cd "$SITE/application" && "$PHP" artisan up >/dev/null 2>&1 )
check "maintenance mode lifted" $?

( cd "$SITE/public_html" && MULKIHAWLER_APP_BASE="$SITE/application" nohup "$PHP" -S 127.0.0.1:$PORT index.php > "$EV/production-rollback-server.log" 2>&1 & )
sleep 4

CODE=$(curl -sS -o /dev/null -w '%{http_code}' "http://127.0.0.1:$PORT/" || echo 000)
[ "$CODE" = "200" ]
check "home page responds after rollback (got $CODE)" $?

REG=$(curl -sS -o /dev/null -w '%{http_code}' "http://127.0.0.1:$PORT/register" || echo 000)
[ "$REG" = "200" ]
check "registration page responds after rollback (got $REG)" $?

ASSET=$(curl -sS "http://127.0.0.1:$PORT/" | grep -oE 'build/assets/[^"]+' | head -1)
ACODE=$(curl -sS -o /dev/null -w '%{http_code}' "http://127.0.0.1:$PORT/$ASSET" || echo 000)
[ "$ACODE" = "200" ]
check "an asset from the restored build is served (got $ACODE)" $?

pkill -f "php -S 127.0.0.1:$PORT" >/dev/null 2>&1 || true

cat > "$EV/production-rollback-rehearsal.json" <<JSONEOF
{
  "result_type": "production_rollback_rehearsal",
  "shell": "env -i (fresh session, no inherited environment)",
  "target": "$SITE",
  "model": "post-v7 incremental: code and runtime only, database untouched",
  "ownership_transfer_in_backup": $OWNERSHIP_IN_BACKUP,
  "ledger_before": "$RAN_BEFORE",
  "ledger_after": "$RAN_AFTER",
  "ledger_expected": "$POST_V7_LEDGER",
  "status_table_identical": $( [ "$STATUS_BEFORE" = "$STATUS_AFTER" ] && echo true || echo false ),
  "inventory_ran_after": "$INVENTORY_AFTER",
  "protected_five_ran_after": "$PROTECTED_AFTER",
  "baseline_manifest_sha256": "$BASELINE_MANIFEST",
  "restored_manifest_sha256": "$RESTORED_MANIFEST",
  "manifest_byte_identical": $( [ "$RESTORED_MANIFEST" = "$BASELINE_MANIFEST" ] && echo true || echo false ),
  "checks_passed": $pass,
  "checks_failed": $fail,
  "result": "$( [ "$fail" = "0" ] && echo PASS || echo FAIL )"
}
JSONEOF

echo
echo "PRODUCTION ROLLBACK REHEARSAL: $pass passed, $fail failed"
[ "$fail" = "0" ]
