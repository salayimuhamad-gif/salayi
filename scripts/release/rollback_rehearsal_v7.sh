#!/usr/bin/env bash
# Fresh-session rollback rehearsal for the v7 account-first patch.
#
# Run under `env -i`: no inherited environment, no warmed caches, no variables
# left over from the deployment. That is the situation a rollback actually
# happens in — somebody opens a new SSH session on a site that is misbehaving —
# and a procedure that only works with the deployer's shell still open is not a
# procedure.
#
# This reverses a REAL deployed tree produced by deploy_rehearsal.sh. It does
# not reverse a git diff.
set -uo pipefail

# Paths are supplied, with defaults relative to the current directory.
#   $1 / REHEARSAL_STAGE     the staged site produced by deploy_rehearsal.sh
#   REHEARSAL_EVIDENCE       where JSON/log evidence is written
#   REHEARSAL_BACKUP         the backup directory to restore FROM; defaults to
#                            the one the deployment rehearsal recorded
STAGE="${1:-${REHEARSAL_STAGE:-./rehearsal}}"
EV="${REHEARSAL_EVIDENCE:-$STAGE/evidence}"
PHP_BIN="${REHEARSAL_PHP:-php}"

mkdir -p "$EV"
SITE="$STAGE/domains/myhawler.test"
PORT="${REHEARSAL_PORT:-8124}"

# BLOCKER 4: PHP_BIN was declared and then ignored in favour of a hardcoded
# /usr/bin/php, and every database call hardcoded `-u root` against a fixed
# schema name. A harness that announces configurable inputs and then ignores
# them is worse than one that never offered them.
PHP="$PHP_BIN"
REHEARSAL_DB_HOST="${REHEARSAL_DB_HOST:-127.0.0.1}"
REHEARSAL_DB_PORT="${REHEARSAL_DB_PORT:-3306}"
REHEARSAL_DB_NAME="${REHEARSAL_DB_NAME:-myh_rehearse}"
REHEARSAL_DB_USER="${REHEARSAL_DB_USER:-myh}"
REHEARSAL_DB_PASSWORD="${REHEARSAL_DB_PASSWORD:?set REHEARSAL_DB_PASSWORD before running the rollback rehearsal}"
MYSQL_BIN="${REHEARSAL_MYSQL:-mysql}"

# One place that knows how to reach the database.
db() {
    "$MYSQL_BIN" -h "$REHEARSAL_DB_HOST" -P "$REHEARSAL_DB_PORT" \
        -u "$REHEARSAL_DB_USER" -p"$REHEARSAL_DB_PASSWORD" "$@"
}

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
# Prefer the exact backup the deployment rehearsal recorded; fall back to the
# newest. Picking by mtime alone silently restores the wrong one when a stage
# directory has been reused.
if [ -n "${REHEARSAL_BACKUP:-}" ]; then
    BACKUP="$REHEARSAL_BACKUP"
elif [ -f "$STAGE/LAST_BACKUP_PATH" ]; then
    BACKUP=$(cat "$STAGE/LAST_BACKUP_PATH")
else
    BACKUP=$(ls -dt "$STAGE"/backup-* 2>/dev/null | head -1)
fi
[ -n "$BACKUP" ] && [ -d "$BACKUP" ]
check "found the pre-deployment backup by listing, not by remembering a variable" $?

[ -d "$BACKUP/Identity" ] && [ -d "$BACKUP/lang" ] && [ -d "$BACKUP/build" ]
check "backup contains the application, language and public-build copies" $?

[ -s "$BACKUP/database.sql" ]
check "backup contains the mandatory database dump" $?

BASELINE_MANIFEST=$(sha256sum "$BACKUP/build/manifest.json" | cut -d' ' -f1)
echo "  BASELINE_MANIFEST=$BASELINE_MANIFEST"

echo "== 1. count what the patched period created, BEFORE reversing =="
UNLINKED=$( cd "$SITE/application" && "$PHP" artisan tinker --execute='echo App\Modules\Identity\Models\User::whereNull("telegram_verified_at")->whereNull("deleted_at")->count();' 2>/dev/null | tr -dc '0-9' )
UNLINKED=${UNLINKED:-unknown}
echo "  UNLINKED_ACCOUNTS_AT_ROLLBACK=$UNLINKED"
[ "$UNLINKED" != "unknown" ]
check "unlinked accounts created during the patched period counted ($UNLINKED)" $?

echo "== 2. maintenance mode first =="
( cd "$SITE/application" && "$PHP" artisan down >/dev/null 2>&1 )
[ -f "$SITE/application/storage/framework/down" ] || [ -f "$SITE/application/storage/framework/maintenance.php" ]
check "site placed in maintenance mode before anything is moved" $?

echo "== 2b. reverse the patch's migration WHILE its file is still present =="
# Order matters and this is the whole reason for doing it here. Restoring the
# pre-deployment dump does NOT drop a table created after that dump was taken —
# mysqldump only recreates what it contains — so the handoff table would
# survive a "full database restore" and leave reverted code running against a
# schema it does not know. `migrate:rollback` needs the migration FILE, which
# section 3 is about to remove, so it runs first.
# BLOCKER 2: `--step=1` reverses whatever the last batch happens to contain. If
# anything ran after this patch it reverses that instead, and the mistake is
# only visible after the destructive command has executed. The rehearsal must
# run the SAME path-targeted command ROLLBACK_NOTES.md documents.
HANDOFF_MIGRATION=app/Modules/Identity/Database/Migrations/2026_08_06_000100_telegram_return_handoffs.php

RAN_BEFORE=$( cd "$SITE/application" && "$PHP" artisan migrate:status 2>/dev/null | grep -c ' Ran' )
( cd "$SITE/application" && "$PHP" artisan migrate:status 2>/dev/null \
    | grep telegram_return_handoffs | grep -q ' Ran' )
check "the handoff migration is Ran before the rollback" $?

STATUS_BEFORE=$( cd "$SITE/application" && "$PHP" artisan migrate:status 2>/dev/null \
    | grep -v telegram_return_handoffs | sha256sum | cut -d' ' -f1 )

( cd "$SITE/application" && "$PHP" artisan migrate:rollback \
    --path="$HANDOFF_MIGRATION" --force >/dev/null 2>&1 )
check "the handoff migration was reversed by exact path, before its code was removed" $?

RAN_AFTER=$( cd "$SITE/application" && "$PHP" artisan migrate:status 2>/dev/null | grep -c ' Ran' )
[ "$RAN_AFTER" = "$((RAN_BEFORE - 1))" ]
check "exactly one migration was reversed ($RAN_BEFORE -> $RAN_AFTER)" $?

STATUS_AFTER=$( cd "$SITE/application" && "$PHP" artisan migrate:status 2>/dev/null \
    | grep -v telegram_return_handoffs | sha256sum | cut -d' ' -f1 )
[ "$STATUS_BEFORE" = "$STATUS_AFTER" ]
check "no unrelated migration changed status" $?

( cd "$SITE/application" && "$PHP" artisan migrate:status 2>/dev/null \
    | grep telegram_return_handoffs | grep -q ' Ran' )
[ $? -ne 0 ]
check "the handoff migration is no longer Ran" $?

EARLY_TABLE=$(db -N -B "$REHEARSAL_DB_NAME" -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$REHEARSAL_DB_NAME' AND table_name='telegram_return_handoffs';" 2>/dev/null)
[ "$EARLY_TABLE" = "0" ]
check "telegram_return_handoffs is absent immediately after the exact rollback (found ${EARLY_TABLE:-?})" $?

# A second exact rollback must be a safe no-op, not a further unwind.
( cd "$SITE/application" && "$PHP" artisan migrate:rollback \
    --path="$HANDOFF_MIGRATION" --force >/dev/null 2>&1 )
SECOND_EXIT=$?
RAN_REPEAT=$( cd "$SITE/application" && "$PHP" artisan migrate:status 2>/dev/null | grep -c ' Ran' )
[ "$SECOND_EXIT" = "0" ] && [ "$RAN_REPEAT" = "$RAN_AFTER" ]
check "a repeated exact rollback is a safe no-op (still $RAN_REPEAT Ran)" $?

echo "== 3. restore the application =="
FAILED_DIR="$STAGE/failed-$(date +%Y%m%d-%H%M%S)"
mkdir -p "$FAILED_DIR"
cp -a "$SITE/application/app/Modules/Identity" "$FAILED_DIR/Identity"
check "the failed release is kept for diagnosis" $?

rm -rf "$SITE/application/app/Modules/Identity"
cp -a "$BACKUP/Identity" "$SITE/application/app/Modules/Identity"
check "pre-deployment application code restored" $?

rm -rf "$SITE/application/lang"
cp -a "$BACKUP/lang" "$SITE/application/lang"
check "pre-deployment language files restored" $?

# BLOCKER 4: configuration is restored from THE BACKUP THE PROCEDURE TOOK, not
# from a git repository. Production has no repository; if the rehearsal restores
# from one, it is rehearsing something the operator cannot do, and the backup
# that production actually depends on is never exercised.
[ -f "$BACKUP/mulkihawler.php" ]
check "configuration backup exists to restore from" $?

cp -a "$BACKUP/mulkihawler.php" "$SITE/application/config/mulkihawler.php"
check "pre-deployment configuration restored from the backup" $?

# Operations was backed up because the release DELETES a file from it. Restoring
# it is the only way the deletion is genuinely undone.
[ -d "$BACKUP/Operations" ]
check "Operations backup exists to restore from" $?

rm -rf "$SITE/application/app/Modules/Operations"
cp -a "$BACKUP/Operations" "$SITE/application/app/Modules/Operations"
check "pre-deployment Operations module restored from the backup" $?

echo "== 4. restore the public build (replaced, never merged) =="
rm -rf "$SITE/public_html/build"
cp -a "$BACKUP/build" "$SITE/public_html/build"
check "pre-deployment public build restored" $?

RESTORED_MANIFEST=$(sha256sum "$SITE/public_html/build/manifest.json" | cut -d' ' -f1)
echo "  RESTORED_MANIFEST=$RESTORED_MANIFEST"
[ "$RESTORED_MANIFEST" = "$BASELINE_MANIFEST" ]
check "restored manifest is byte-identical to the baseline" $?

# A merged build directory is the classic half-rollback: the old manifest with
# new chunks still beside it.
STALE=$(grep -oE '"file":"assets/[^"]+' "$SITE/public_html/build/manifest.json" | sed 's/.*assets\///' | while read -r f; do
    [ -f "$SITE/public_html/build/assets/$f" ] || echo "$f"
done | wc -l)
[ "$STALE" = "0" ]
check "every restored manifest entry resolves ($STALE missing)" $?

echo "== 5. database restore decision =="
# BLOCKER 3: this release DOES add a migration — the return-handoff table,
# reversed by exact path above. The database restore is therefore not merely a
# proof that the dump is good; it also returns the schema to its pre-deployment
# state. The decision itself is documented in ROLLBACK_NOTES.md.
db "$REHEARSAL_DB_NAME" < "$BACKUP/database.sql" 2>/dev/null
check "database backup restores cleanly when the decision requires it" $?

echo "== 5b. the patch's migration is reversed =="
# v7 ships one forward-only migration (the return-handoff table). Restoring the
# pre-deployment dump removes it along with everything else; this asserts the
# table is genuinely gone, because reverted code that still sees the table
# would be a half-rollback.
TABLE=$(db -N -B "$REHEARSAL_DB_NAME" -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$REHEARSAL_DB_NAME' AND table_name='telegram_return_handoffs';" 2>/dev/null)
[ "$TABLE" = "0" ]
check "the return-handoff table is gone after the database restore (found ${TABLE:-?})" $?

echo "== 6. rebuild caches =="
( cd "$SITE/application" && "$PHP" artisan config:clear >/dev/null 2>&1 && "$PHP" artisan route:clear >/dev/null 2>&1 \
    && "$PHP" artisan view:clear >/dev/null 2>&1 && "$PHP" artisan config:cache >/dev/null 2>&1 \
    && "$PHP" artisan route:cache >/dev/null 2>&1 && "$PHP" artisan view:cache >/dev/null 2>&1 )
check "caches cleared and rebuilt against the restored code" $?

echo "== 7. the application still boots =="
ROUTES=$( cd "$SITE/application" && "$PHP" artisan route:list 2>/dev/null | grep -c . )
[ "$ROUTES" -gt 100 ]
check "route table resolves ($ROUTES lines)" $?

( cd "$SITE/application" && "$PHP" artisan route:list 2>/dev/null | grep -q "account/registration/abandon" )
if [ $? -eq 0 ]; then
    check "the patched recovery route is GONE after rollback" 1
else
    check "the patched recovery route is gone after rollback" 0
fi

( cd "$SITE/application" && "$PHP" artisan route:list 2>/dev/null | grep -q "account/telegram/link" )
check "the pre-patch Telegram linking route is present" $?


( cd "$SITE/application" && "$PHP" artisan schedule:list >/dev/null 2>&1 )
check "scheduler registers" $?

( cd "$SITE/application" && "$PHP" artisan schedule:list 2>/dev/null | grep -q "prune-unlinked" )
if [ $? -eq 0 ]; then
    check "the patched cleanup command is no longer scheduled" 1
else
    check "the patched cleanup command is no longer scheduled" 0
fi

( cd "$SITE/application" && "$PHP" artisan queue:work --stop-when-empty --max-time=10 >/dev/null 2>&1 )
check "queue worker runs" $?

echo "== 8. bring the site back and smoke it =="
( cd "$SITE/application" && "$PHP" artisan up >/dev/null 2>&1 )
check "maintenance mode lifted" $?

( cd "$SITE/public_html" && MULKIHAWLER_APP_BASE="$SITE/application" nohup "$PHP" -S 127.0.0.1:$PORT index.php > "$EV/rollback-server.log" 2>&1 & )
sleep 4

CODE=$(curl -sS -o /dev/null -w '%{http_code}' "http://127.0.0.1:$PORT/" || echo 000)
[ "$CODE" = "200" ]
check "home page responds after rollback (got $CODE)" $?

ASSET=$(curl -sS "http://127.0.0.1:$PORT/" | grep -oE 'build/assets/[^"]+' | head -1)
ACODE=$(curl -sS -o /dev/null -w '%{http_code}' "http://127.0.0.1:$PORT/$ASSET" || echo 000)
[ "$ACODE" = "200" ]
check "an asset from the restored build is served (got $ACODE)" $?

# Middleware aliases are registered on the HTTP kernel and resolve when a
# request is handled, so a console bootstrap sees an empty alias map — probing
# it that way reported a missing alias that was present all along. A route
# BEHIND those aliases is requested instead: an unresolvable alias raises
# "Target class does not exist" and returns 500, so any redirect proves they
# resolved.
GCODE=$(curl -sS -o /dev/null -w '%{http_code}' "http://127.0.0.1:$PORT/account/onboarding" || echo 000)
[ "$GCODE" = "302" ] || [ "$GCODE" = "403" ]
check "account.active and telegram.linked resolve on a gated route (got $GCODE)" $?

echo "== 9. the PREVIOUS registration flow is what is live again =="
CJ=$(mktemp)
TOKEN=$(curl -sS -c "$CJ" "http://127.0.0.1:$PORT/register" | grep -oE 'csrf-token" content="[^"]+' | head -1 | cut -d'"' -f3)
LOC=$(curl -sS -b "$CJ" -c "$CJ" -o /dev/null -w '%{redirect_url}' -X POST "http://127.0.0.1:$PORT/register" \
    -d "name=Rollback Person" -d "phone=07511122334" -d "locale=ckb" -d "accept_terms=1" -d "_token=$TOKEN")
printf '%s' "$LOC" > "$EV/rollback-registration-redirect.txt"

# Telegram-first is back: the form mints an intent and returns to the pending
# screen. It must NOT create an account or land on the linking page.
printf '%s' "$LOC" | grep -q "account/telegram/link"
if [ $? -eq 0 ]; then
    check "registration no longer behaves account-first" 1
else
    check "registration no longer behaves account-first (Telegram-first restored)" 0
fi

CREATED=$( cd "$SITE/application" && "$PHP" artisan tinker --execute='echo App\Modules\Identity\Models\User::where("name","Rollback Person")->count();' 2>/dev/null | tr -dc '0-9' )
[ "${CREATED:-1}" = "0" ]
check "the reverted form created no account (${CREATED:-?} found)" $?

echo "== 10. unlinked accounts from the patched period =="
STRANDED=$( cd "$SITE/application" && "$PHP" artisan tinker --execute='echo App\Modules\Identity\Models\User::whereNull("telegram_verified_at")->whereNull("deleted_at")->count();' 2>/dev/null | tr -dc '0-9' )
echo "  STRANDED_AFTER_ROLLBACK=${STRANDED:-unknown}"
[ -n "${STRANDED:-}" ]
check "stranded unlinked accounts counted after rollback (${STRANDED:-unknown})" $?

pkill -f "php -S 127.0.0.1:$PORT" >/dev/null 2>&1 || true

cat > "$EV/rollback-rehearsal.json" <<JSONEOF
{
  "result_type": "rollback_rehearsal",
  "shell": "env -i (fresh session, no inherited environment)",
  "target": "$SITE",
  "baseline_commit": "9c0188f81843cfe4786b7f72ecdc2a3fae89cd82",
  "baseline_manifest_sha256": "$BASELINE_MANIFEST",
  "restored_manifest_sha256": "$RESTORED_MANIFEST",
  "manifest_byte_identical": $( [ "$RESTORED_MANIFEST" = "$BASELINE_MANIFEST" ] && echo true || echo false ),
  "routes_resolved": "$ROUTES",
  "unlinked_accounts_at_rollback": "$UNLINKED",
  "stranded_unlinked_after_rollback": "${STRANDED:-unknown}",
  "checks_passed": $pass,
  "checks_failed": $fail,
  "result": "$( [ "$fail" = "0" ] && echo PASS || echo FAIL )"
}
JSONEOF

echo
echo "ROLLBACK REHEARSAL: $pass passed, $fail failed"
[ "$fail" = "0" ]
