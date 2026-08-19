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

[ -d "$BACKUP/app" ] && [ -d "$BACKUP/lang" ] && [ -d "$BACKUP/config" ] \
    && [ -d "$BACKUP/routes" ] && [ -f "$BACKUP/bootstrap-app.php" ] && [ -d "$BACKUP/build" ]
check "backup contains the application, config, routes, bootstrap and public-build copies" $?

[ -s "$BACKUP/database.sql" ]
check "backup contains the mandatory database dump" $?

BASELINE_MANIFEST=$(sha256sum "$BACKUP/build/manifest.json" | cut -d' ' -f1)
echo "  BASELINE_MANIFEST=$BASELINE_MANIFEST"

echo "== 1. count what the patched period created, BEFORE reversing =="
# Both verification doors are live in the patched period, so "never verified"
# means BOTH stamps are null: an account verified through WhatsApp alone is
# verified, not stranded — until the rollback drops its column, which is why
# the number is recorded now.
UNLINKED=$( cd "$SITE/application" && "$PHP" artisan tinker --execute='echo App\Modules\Identity\Models\User::whereNull("telegram_verified_at")->whereNull("whatsapp_verified_at")->whereNull("deleted_at")->count();' 2>/dev/null | tr -dc '0-9' )
UNLINKED=${UNLINKED:-unknown}
echo "  UNLINKED_ACCOUNTS_AT_ROLLBACK=$UNLINKED"
[ "$UNLINKED" != "unknown" ]
check "unlinked accounts created during the patched period counted ($UNLINKED)" $?

echo "== 2. maintenance mode first =="
( cd "$SITE/application" && "$PHP" artisan down >/dev/null 2>&1 )
[ -f "$SITE/application/storage/framework/down" ] || [ -f "$SITE/application/storage/framework/maintenance.php" ]
check "site placed in maintenance mode before anything is moved" $?

echo "== 2b. reverse the patch's migrations WHILE their files are still present =="
# Order matters and this is the whole reason for doing it here. Restoring the
# pre-deployment dump does NOT drop a table created after that dump was taken —
# mysqldump only recreates what it contains — so the patch's tables would
# survive a "full database restore" and leave reverted code running against a
# schema it does not know. `migrate:rollback` needs the migration FILES, which
# section 3 is about to remove, so it runs first.
# BLOCKER 2: `--step=N` reverses whatever the last batches happen to contain.
# If anything ran after this patch it reverses that instead, and the mistake is
# only visible after the destructive command has executed. The rehearsal must
# run the SAME path-targeted commands ROLLBACK_NOTES.md documents, newest
# first.
PATCH_MIGRATIONS="
app/Modules/Identity/Database/Migrations/2026_08_19_000100_whatsapp_account_verification.php
app/Modules/Marketplace/Database/Migrations/2026_08_17_000200_backfill_offer_search_keys.php
app/Modules/Knowledge/Database/Migrations/2026_08_17_000100_backfill_knowledge_event_search_keys.php
app/Modules/Knowledge/Database/Migrations/2026_08_16_000100_add_evidence_class_to_knowledge_events.php
app/Modules/Identity/Database/Migrations/2026_08_09_000300_add_last_seen_to_users.php
app/Modules/Identity/Database/Migrations/2026_08_09_000200_profile_optional_details.php
app/Modules/Identity/Database/Migrations/2026_08_09_000200_password_recovery_challenges.php
app/Modules/Identity/Database/Migrations/2026_08_09_000100_telegram_verification_tokens.php
app/Modules/Identity/Database/Migrations/2026_08_06_000100_telegram_return_handoffs.php
"
PATCH_NAMES_RE='telegram_return_handoffs|telegram_verification_tokens|password_recovery_challenges|profile_optional_details|add_last_seen_to_users|add_evidence_class_to_knowledge_events|backfill_knowledge_event_search_keys|backfill_offer_search_keys|whatsapp_account_verification'

RAN_BEFORE=$( cd "$SITE/application" && "$PHP" artisan migrate:status 2>/dev/null | grep -c ' Ran' )
PATCH_RAN=$( cd "$SITE/application" && "$PHP" artisan migrate:status 2>/dev/null \
    | grep -E "$PATCH_NAMES_RE" | grep -c ' Ran' )
[ "$PATCH_RAN" = "9" ]
check "all nine patch migrations are Ran before the rollback (found $PATCH_RAN)" $?

STATUS_BEFORE=$( cd "$SITE/application" && "$PHP" artisan migrate:status 2>/dev/null \
    | grep -vE "$PATCH_NAMES_RE" | sha256sum | cut -d' ' -f1 )

for migration in $PATCH_MIGRATIONS; do
    ( cd "$SITE/application" && "$PHP" artisan migrate:rollback \
        --path="$migration" --force >/dev/null 2>&1 )
    check "reversed by exact path: $(basename "$migration" .php)" $?
done

RAN_AFTER=$( cd "$SITE/application" && "$PHP" artisan migrate:status 2>/dev/null | grep -c ' Ran' )
[ "$RAN_AFTER" = "$((RAN_BEFORE - 9))" ]
check "exactly the patch's nine migrations were reversed ($RAN_BEFORE -> $RAN_AFTER)" $?

STATUS_AFTER=$( cd "$SITE/application" && "$PHP" artisan migrate:status 2>/dev/null \
    | grep -vE "$PATCH_NAMES_RE" | sha256sum | cut -d' ' -f1 )
[ "$STATUS_BEFORE" = "$STATUS_AFTER" ]
check "no unrelated migration changed status" $?

PATCH_STILL_RAN=$( cd "$SITE/application" && "$PHP" artisan migrate:status 2>/dev/null \
    | grep -E "$PATCH_NAMES_RE" | grep -c ' Ran' )
[ "$PATCH_STILL_RAN" = "0" ]
check "none of the patch migrations is still Ran (found $PATCH_STILL_RAN)" $?

EARLY_TABLE=$(db -N -B "$REHEARSAL_DB_NAME" -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$REHEARSAL_DB_NAME' AND table_name IN ('telegram_return_handoffs','telegram_verification_tokens','password_recovery_challenges','whatsapp_otps');" 2>/dev/null)
[ "$EARLY_TABLE" = "0" ]
check "all four patch tables are absent immediately after the exact rollback (found ${EARLY_TABLE:-?})" $?

EARLY_COLS=$(db -N -B "$REHEARSAL_DB_NAME" -e "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='$REHEARSAL_DB_NAME' AND table_name='users' AND column_name IN ('gender','date_of_birth','last_seen_at','whatsapp_verified_at');" 2>/dev/null)
[ "$EARLY_COLS" = "0" ]
check "all four patch columns are gone from users (found ${EARLY_COLS:-?})" $?

EVIDENCE_COL=$(db -N -B "$REHEARSAL_DB_NAME" -e "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='$REHEARSAL_DB_NAME' AND table_name='knowledge_events' AND column_name='evidence_class';" 2>/dev/null)
[ "$EVIDENCE_COL" = "0" ]
check "the knowledge evidence-class column is gone (found ${EVIDENCE_COL:-?})" $?

# A second exact rollback of every file must be a safe no-op, not a further
# unwind.
SECOND_EXIT=0
for migration in $PATCH_MIGRATIONS; do
    ( cd "$SITE/application" && "$PHP" artisan migrate:rollback \
        --path="$migration" --force >/dev/null 2>&1 ) || SECOND_EXIT=$?
done
RAN_REPEAT=$( cd "$SITE/application" && "$PHP" artisan migrate:status 2>/dev/null | grep -c ' Ran' )
[ "$SECOND_EXIT" = "0" ] && [ "$RAN_REPEAT" = "$RAN_AFTER" ]
check "a repeated exact rollback is a safe no-op (still $RAN_REPEAT Ran)" $?

echo "== 3. restore the application =="
FAILED_DIR="$STAGE/failed-$(date +%Y%m%d-%H%M%S)"
mkdir -p "$FAILED_DIR"
cp -a "$SITE/application/app" "$FAILED_DIR/app"
check "the failed release is kept for diagnosis" $?

# BLOCKER 4: everything is restored from THE BACKUP THE PROCEDURE TOOK, not
# from a git repository. Production has no repository; if the rehearsal
# restores from one, it is rehearsing something the operator cannot do, and
# the backup that production actually depends on is never exercised.
#
# The WHOLE application-code directory moves, mirroring the backup: this
# release touches several modules and deletes files, and a whole-directory
# restore is the only shape that undoes both without a per-release list.
rm -rf "$SITE/application/app"
cp -a "$BACKUP/app" "$SITE/application/app"
check "pre-deployment application code restored" $?

rm -rf "$SITE/application/lang"
cp -a "$BACKUP/lang" "$SITE/application/lang"
check "pre-deployment language files restored" $?

rm -rf "$SITE/application/config"
cp -a "$BACKUP/config" "$SITE/application/config"
check "pre-deployment configuration restored from the backup" $?

# routes/ and bootstrap/app.php were part of the patch, and the restored
# baseline code no longer defines the classes the patched routes point at —
# leaving either behind is the half-rollback where route:cache crashes on a
# controller that does not exist.
rm -rf "$SITE/application/routes"
cp -a "$BACKUP/routes" "$SITE/application/routes"
check "pre-deployment routes restored from the backup" $?

cp -a "$BACKUP/bootstrap-app.php" "$SITE/application/bootstrap/app.php"
check "pre-deployment HTTP bootstrap restored from the backup" $?

echo "== 4. restore the public build (replaced, never merged) =="
rm -rf "$SITE/public_html/build"
cp -a "$BACKUP/build" "$SITE/public_html/build"
check "pre-deployment public build restored" $?

# The mirror of the deployment's replacement, and the reason the source patch
# can retire old chunks by directory rather than by deletion entry: whichever
# direction the build directory moves, it moves WHOLE. The restored tree must
# therefore equal the backup file-for-file — a merged restore leaves the failed
# release's chunks beside the restored manifest, and the two checks below cannot
# see them, because both only follow manifest entries.
RESTORED_LIST=$(cd "$SITE/public_html/build" && find . -type f | sort)
BACKUP_LIST=$(cd "$BACKUP/build" && find . -type f | sort)

[ "$RESTORED_LIST" = "$BACKUP_LIST" ]
check "the restored build directory matches the backup exactly" $?

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

echo "== 5b. the patch's migrations are reversed =="
# This release ships nine forward-only migrations. Restoring the
# pre-deployment dump removes their tables and columns along with everything
# else; this asserts they are genuinely gone, because reverted code that still
# sees them would be a half-rollback.
TABLE=$(db -N -B "$REHEARSAL_DB_NAME" -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$REHEARSAL_DB_NAME' AND table_name IN ('telegram_return_handoffs','telegram_verification_tokens','password_recovery_challenges','whatsapp_otps');" 2>/dev/null)
[ "$TABLE" = "0" ]
check "the patch's four tables are gone after the database restore (found ${TABLE:-?})" $?

COLS=$(db -N -B "$REHEARSAL_DB_NAME" -e "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='$REHEARSAL_DB_NAME' AND table_name='users' AND column_name IN ('gender','date_of_birth','last_seen_at','whatsapp_verified_at');" 2>/dev/null)
[ "$COLS" = "0" ]
check "the patch's four users columns are gone after the database restore (found ${COLS:-?})" $?

RESTORE_EVIDENCE_COL=$(db -N -B "$REHEARSAL_DB_NAME" -e "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='$REHEARSAL_DB_NAME' AND table_name='knowledge_events' AND column_name='evidence_class';" 2>/dev/null)
[ "$RESTORE_EVIDENCE_COL" = "0" ]
check "the knowledge evidence-class column is gone after the database restore (found ${RESTORE_EVIDENCE_COL:-?})" $?

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
    check "the patched abandon route is GONE after rollback" 1
else
    check "the patched abandon route is gone after rollback" 0
fi

( cd "$SITE/application" && "$PHP" artisan route:list 2>/dev/null | grep -q "forgot-password/telegram" )
if [ $? -eq 0 ]; then
    check "the patched Telegram recovery routes are GONE after rollback" 1
else
    check "the patched Telegram recovery routes are gone after rollback" 0
fi

( cd "$SITE/application" && "$PHP" artisan route:list 2>/dev/null | grep -q "invest/features" )
if [ $? -eq 0 ]; then
    check "the patched investment-map routes are GONE after rollback" 1
else
    check "the patched investment-map routes are gone after rollback" 0
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

( cd "$SITE/application" && "$PHP" artisan schedule:list 2>/dev/null | grep -q "recovery:prune" )
if [ $? -eq 0 ]; then
    check "the patched recovery pruner is no longer scheduled" 1
else
    check "the patched recovery pruner is no longer scheduled" 0
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
# The RESTORED baseline predates the WhatsApp door: its schema has no
# whatsapp_verified_at column, so this count is telegram-only BY NECESSITY —
# adding the second whereNull here would crash against the restored schema.
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
