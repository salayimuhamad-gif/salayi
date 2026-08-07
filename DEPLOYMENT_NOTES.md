# DEPLOYMENT_NOTES.md — MyHawler v7 account-first registration

The exact reversible procedure for this release.

> **Rehearsal status — read before relying on this.**
> The rehearsal harness (`scripts/release/deploy_rehearsal.sh`) was CORRECTED in
> this round and has **not yet been executed** against a staged site. Any
> earlier "28/28 checks passed" result belonged to the previous, now-rejected
> procedure — it did not run these steps, took four of the six required backups,
> and reversed migrations with `--step=1`. Treat those historical counts as
> **invalid for this document**. This section will be regenerated from the new
> raw rehearsal output once the corrected harness runs.

**This patch DOES change the schema.** It ships exactly one forward-only
migration:

```text
app/Modules/Identity/Database/Migrations/2026_08_06_000100_telegram_return_handoffs.php
```

That table backs the secure Telegram return handoff. Copying the code without
running the migration leaves the application querying a table that does not
exist, and the first person who presses the Telegram return button gets an
error. The migration step below is mandatory, not optional.

**This patch also DELETES a file.** A ZIP overlay cannot remove anything, so the
deletion is applied from `DELETE_FILES.txt` in step 6.

**Do not deploy automatically.** Deploy when you can watch it.

## 0. Paths

```text
Project root:  ~/domains/myhawler.com
Application:   ~/domains/myhawler.com/application
Public root:   ~/domains/myhawler.com/public_html
CLI PHP:       /opt/alt/php83/usr/bin/php
```

Use the CLI PHP path explicitly. Hostinger's default shell `php` is not
necessarily the version the site runs.

## 1. Verify the artifact before touching anything

```bash
cd ~
sha256sum -c myhawler-account-first-registration-corrected-runtime.zip.sha256
```

Stop if this fails. A mismatched archive is not a deployment candidate and every
later step assumes this one passed.

## 2. Mandatory backups

```bash
TS=$(date +%Y%m%d-%H%M%S)
cd ~/domains/myhawler.com
mkdir -p ~/patch-backup-$TS

cp -a application/app/Modules/Identity   ~/patch-backup-$TS/Identity
cp -a application/app/Modules/Operations ~/patch-backup-$TS/Operations
cp -a application/lang                   ~/patch-backup-$TS/lang
cp -a application/config/mulkihawler.php ~/patch-backup-$TS/mulkihawler.php
cp -a public_html/build                  ~/patch-backup-$TS/build

mysqldump -u <db_user> -p <db_name> > ~/patch-backup-$TS/database.sql
```

All six are mandatory. `Operations` is included because step 6 deletes a file
from it. The database dump is mandatory because this release migrates.

## 3. Record the migration count BEFORE anything changes

```bash
cd ~/domains/myhawler.com/application
/opt/alt/php83/usr/bin/php artisan migrate:status | grep -c Ran
```

Write the number down — call it `BEFORE`. Step 8 proves it went up by exactly
one.

```bash
/opt/alt/php83/usr/bin/php artisan migrate:status | grep telegram_return_handoffs
```

Expect **nothing**, or a line reading `Pending`. If it already says `Ran`, this
patch is already partly applied; stop and investigate rather than continuing.

## 4. Stage the runtime in a NEW directory

```bash
mkdir -p ~/patch-v7 && cd ~/patch-v7
unzip -q ~/myhawler-account-first-registration-corrected-runtime.zip
ls application public_html/build/manifest.json DELETE_FILES.txt
```

Never unzip over the live tree. Staging is what makes rollback possible.

## 5. Maintenance mode

```bash
cd ~/domains/myhawler.com/application
/opt/alt/php83/usr/bin/php artisan down --render="errors::503"
```

Enter maintenance **before** any file or schema changes. Half-applied code
serving live traffic is the failure this prevents.

## 6. Apply the deletions

```bash
cd ~/domains/myhawler.com/application

while IFS= read -r rel; do
    case "$rel" in ''|\#*) continue ;; esac
    case "$rel" in /*|*..*) echo "REFUSED unsafe path: $rel"; continue ;; esac
    rm -f "./$rel"
    [ -e "./$rel" ] && echo "FAILED to delete: $rel" || echo "deleted: $rel"
done < ~/patch-v7/DELETE_FILES.txt
```

The two `case` guards are the point: only relative, allow-listed paths inside
the application directory are ever removed. Anything absolute or containing
`..` is refused rather than deleted.

## 7. Copy the runtime files

```bash
cd ~/domains/myhawler.com

cp -a ~/patch-v7/application/app/.    application/app/
cp -a ~/patch-v7/application/lang/.   application/lang/
cp -a ~/patch-v7/application/config/. application/config/

# REPLACED, never merged: Vite content-hashes filenames, so a merged directory
# leaves old chunks beside a new manifest and the browser requests assets the
# manifest never names.
rm -rf public_html/build
cp -a ~/patch-v7/public_html/build public_html/
```

## 8. Run the migration — mandatory

```bash
cd ~/domains/myhawler.com/application
/opt/alt/php83/usr/bin/php artisan migrate --force
```

Then prove exactly what you expect:

```bash
/opt/alt/php83/usr/bin/php artisan migrate:status | grep telegram_return_handoffs
```

Expect the line to read **`Ran`** — it was `Pending` or absent in step 3.

```bash
/opt/alt/php83/usr/bin/php artisan migrate:status | grep -c Ran
```

Expect exactly `BEFORE + 1`. Both directions matter: no increase means the
handoff table never arrived and the return button will fail; more than one means
something unintended came along and you should stop and look at it.

## 9. Verify the manifest resolves

```bash
cd ~/domains/myhawler.com/public_html/build
/opt/alt/php83/usr/bin/php -r '
$m = json_decode(file_get_contents("manifest.json"), true);
$missing = 0;
foreach ($m as $e) { if (isset($e["file"]) && !file_exists($e["file"])) { $missing++; } }
echo $missing === 0 ? "manifest OK\n" : "MISSING: $missing\n";'
```

Anything other than `manifest OK` means an incomplete copy. **Do NOT continue to
§13** — §13 lifts maintenance and would expose the incomplete release. Stay in
maintenance mode and go to §15 failure handling, which routes to
`ROLLBACK_NOTES.md`.

## 10. Rebuild caches

```bash
cd ~/domains/myhawler.com/application
/opt/alt/php83/usr/bin/php artisan config:clear
/opt/alt/php83/usr/bin/php artisan route:clear
/opt/alt/php83/usr/bin/php artisan view:clear
/opt/alt/php83/usr/bin/php artisan config:cache
/opt/alt/php83/usr/bin/php artisan route:cache
/opt/alt/php83/usr/bin/php artisan view:cache
```

A cached route table from before the patch keeps serving the old registration
behaviour.

## 11. Routes, middleware, scheduler, queue

```bash
/opt/alt/php83/usr/bin/php artisan route:list | grep account/registration/abandon
/opt/alt/php83/usr/bin/php artisan route:list | grep account/return
/opt/alt/php83/usr/bin/php artisan schedule:list | grep prune-unlinked
/opt/alt/php83/usr/bin/php artisan schedule:list | grep prune-return-handoffs
/opt/alt/php83/usr/bin/php artisan queue:work --stop-when-empty --max-time=50
```

Expect the abandon route, both return routes, and **both** scheduled cleanups.
Middleware aliases are proven behaviourally in §12: an unresolvable alias
returns 500, so a working gated redirect proves they resolved.

Confirm the single cron entry is unchanged:

```text
* * * * * cd ~/domains/myhawler.com/application && /opt/alt/php83/usr/bin/php artisan schedule:run >> /dev/null 2>&1
```

See what the cleanups would do, without doing it:

```bash
/opt/alt/php83/usr/bin/php artisan mulkihawler:accounts:prune-unlinked --dry-run
/opt/alt/php83/usr/bin/php artisan mulkihawler:telegram:prune-return-handoffs --dry-run
```

On a site that has never run account-first, both report zero.

## 12. Offline gate — maintenance mode STAYS ON

Everything above (§6–§11) runs behind maintenance mode and must all pass before
the site is exposed to anyone. Confirm each, in order:

```text
runtime checksum verified                    §1
all six backups taken and readable           §2
deletions applied and verified absent        §6
telegram_return_handoffs moved Pending -> Ran §8
migration count increased by exactly one     §8
public/build manifest resolves               §9
caches rebuilt without error                 §10
abandon + both return routes present         §11
both cleanups scheduled; dry runs report zero §11
queue drains                                 §11
```

Do not continue while any of these is unresolved. A failure here costs nothing:
the site is still showing the maintenance page and the previous release is
intact.

**Do not run HTTP smoke tests yet.** A Laravel application under `artisan down`
returns 503 to every public request, so `GET /` cannot return 200 while
maintenance is on. Any procedure that expects a 200 before `artisan up` is
describing something that cannot happen — earlier revisions of this document
did exactly that, and the rehearsal quietly did something different.

## 13. Lift maintenance — the controlled online phase begins here

```bash
cd ~/domains/myhawler.com/application
/opt/alt/php83/usr/bin/php artisan up
```

From this moment the release is live and the clock is running. Have
`ROLLBACK_NOTES.md` open before you type this. Do not walk away between §13 and
§14.

## 14. Online smoke tests — run these immediately

```bash
curl -sS -o /dev/null -w '%{http_code}\n' https://myhawler.com/
curl -sS -o /dev/null -w '%{http_code}\n' https://myhawler.com/register
ASSET=$(curl -sS https://myhawler.com/ | grep -o 'build/assets/[^"]*' | head -1)
curl -sS -o /dev/null -w '%{http_code}\n' "https://myhawler.com/$ASSET"
```

Expect 200, 200, 200. The third one matters: a manifest that resolves on disk
(§9) does not prove the asset is actually reachable over HTTP.

Then the full journey by hand, with a spare number:

```text
1. open /register, choose العربية, submit
2. you land on /ar/account/telegram/link
3. the page is right-to-left and shows: تم إنشاء حسابك بنجاح
4. press Open Telegram, then /start in the bot
5. the chat says to confirm in your browser, with a العودة إلى MyHawler
   button. Opening that button in a DIFFERENT browser must show the neutral
   "go back to your original tab" page — it must not sign anyone in.
6. back in the original tab, the candidate appears on its own; confirm it
7. the chat confirms success with a العودة إلى MyHawler button. Opening THAT
   button in a different browser signs you in once and lands on
   /ar/account/onboarding
8. reopening the same button a second time shows the neutral expired page
```

Step 7 is the migration's payload: if the handoff table is missing, this is
where it fails.

## 15. Success criteria and failure handling

**Success — all true:**

```text
every offline gate in §12 passed
home 200, /register 200, referenced asset 200
the §14 walkthrough completes in Arabic, including the cold-browser handoff
the one-time handoff is genuinely one-time (step 8 shows the expired page)
```

Only then is the deployment complete. Leave the site up and watch the error log
for a few minutes.

**Failure during the OFFLINE phase (§1–§12)** — maintenance is still on, so
nothing is user-visible:

```text
checksum mismatch at §1              -> do not proceed; the artifact is wrong
migration count unchanged            -> the table is missing; roll back
migration count up by more than one   -> stop and investigate before continuing
manifest reports MISSING             -> incomplete copy; roll back
routes or schedules absent           -> incomplete copy; roll back
```

**Failure during the ONLINE phase (§13–§14)** — the site is live and broken.
Take it down first, then roll back:

```bash
cd ~/domains/myhawler.com/application
/opt/alt/php83/usr/bin/php artisan down --render="errors::503"
```

```text
5xx on / or /register                -> down, then roll back
referenced asset 404                 -> down, then roll back
registration does not reach the
    linking page                     -> down, then roll back
the cold-browser handoff fails       -> down, then roll back
```

Do not debug on a live site while a working release sits one restore away. Go
straight to `ROLLBACK_NOTES.md`; the failed release is preserved there for
diagnosis afterwards. Never leave a failed release serving traffic while you
investigate.
