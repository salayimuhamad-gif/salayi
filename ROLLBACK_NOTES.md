# ROLLBACK_NOTES.md — MyHawler v7 account-first registration

This procedure is designed to be run under `env -i` — no inherited environment,
no warmed cache, nothing left from the deployment — because that is the
situation a rollback happens in: somebody opens a new SSH session on a site that
is misbehaving. A procedure that only works with the deployer's shell still open
is not a procedure.

> **Rehearsal status — read before relying on this.**
> `scripts/release/rollback_rehearsal_v7.sh` was CORRECTED in this round (exact
> path-targeted rollback, configurable PHP and database inputs, restore from the
> documented backup rather than a private git repository) and has **not yet been
> executed**. Any earlier "29 checks passed, 0 failed" belonged to the previous,
> now-rejected harness, which used `migrate:rollback --step=1`. Treat that count
> as **historical and invalid for this document**. It will be regenerated from
> the new raw output once the corrected harness runs.

**This patch migrates.** It added one table, `telegram_return_handoffs`. That
matters for the ORDER below, and it is the one thing people get wrong: restoring
a `mysqldump` does **not** drop a table created after that dump was taken.
Restoring the dump alone leaves the table behind and reverted code running
against a schema it does not know. The migration must be rolled back
deliberately, and its file must still be on disk when you do it.

Written to be followed by someone who was not present for the deployment.

## 1. Identify and verify the backups, knowing nothing

```bash
cd ~/domains/myhawler.com
ls -dt ~/patch-backup-* | head -5
```

Pick the newest backup that predates the deployment, then set it once:

```bash
BACKUP=~/patch-backup-YYYYmmdd-HHMMSS
ls "$BACKUP"    # expect: Identity Operations lang mulkihawler.php build database.sql
sha256sum "$BACKUP/build/manifest.json"
```

Record that manifest hash; §8 checks against it. If no such directory exists the
deployment never reached its file-copy step, the old release is still live, and
there is nothing to roll back — investigate `~/patch-v7` instead.

## 2. Count what the patched period created

```bash
cd ~/domains/myhawler.com/application
/opt/alt/php83/usr/bin/php artisan tinker --execute='echo App\Modules\Identity\Models\User::whereNull("telegram_verified_at")->whereNull("deleted_at")->count();'
```

Write the number down. These are account-first registrations that never linked.
§11 decides what happens to them, and after a database restore you cannot count
them any more.

## 3. Maintenance mode FIRST

```bash
/opt/alt/php83/usr/bin/php artisan down
```

Before any schema or file change. Everything below assumes the site is down.

## 4. Verify the handoff migration is currently Ran

```bash
/opt/alt/php83/usr/bin/php artisan migrate:status | grep telegram_return_handoffs
```

Expect `Ran`. If it says `Pending` or is absent, the migration never applied and
you skip §5 and §6 — but check why the deployment reported success.

## 5. Roll back THAT migration, while its file still exists

`migrate:rollback` needs the migration file, and §7 is about to remove it. Roll
back one step and then prove it was the right one — never assume the last batch
contained only what you think it did:

Use a **path-targeted** rollback. `--step=1` reverses whatever the last batch
happens to contain, and if anything ran after this patch it reverses that
instead — detected only after the destructive command has already executed.
Naming the file removes the guess:

```bash
cd ~/domains/myhawler.com/application

# Prove the state BEFORE anything is reversed:
/opt/alt/php83/usr/bin/php artisan migrate:status | grep telegram_return_handoffs   # expect Ran
/opt/alt/php83/usr/bin/php artisan migrate:status | grep -c Ran                     # record this number

/opt/alt/php83/usr/bin/php artisan migrate:rollback \
  --path=app/Modules/Identity/Database/Migrations/2026_08_06_000100_telegram_return_handoffs.php \
  --force
```

`--realpath` is not needed: the path is resolved relative to the application
root. Verified against Laravel 12 / MariaDB 10.11.

The command prints `Migration not found` for other members of the batch. That
is informational, not an error — Laravel walks the batch but can only resolve
files under `--path`, and it does not reverse the ones it cannot resolve.

Prove the state AFTER:

```bash
/opt/alt/php83/usr/bin/php artisan migrate:status | grep telegram_return_handoffs   # expect Pending
/opt/alt/php83/usr/bin/php artisan migrate:status | grep -c Ran                     # exactly one fewer
```

The count must have dropped by **exactly one**. If it dropped by more, an
unrelated migration was reversed: stop, re-apply with `migrate --force`, and get
help rather than continuing to unwind migrations blindly.

Running the same command a second time is a safe no-op — it exits 0 and the
count stays where it is.

## 6. Prove the table is gone

```bash
mysql -u <db_user> -p -N -B <db_name> \
  -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_name='telegram_return_handoffs';"
```

Expect `0`. The rehearsal failed on exactly this check until the ordering above
was corrected, which is why it is a separate, explicit step.

## 7. Restore the application, keeping the failed release

```bash
TS=$(date +%Y%m%d-%H%M%S)
mkdir -p ~/failed-$TS
cp -a ~/domains/myhawler.com/application/app/Modules/Identity ~/failed-$TS/Identity

cd ~/domains/myhawler.com
rm -rf application/app/Modules/Identity
cp -a "$BACKUP/Identity" application/app/Modules/Identity

rm -rf application/app/Modules/Operations
cp -a "$BACKUP/Operations" application/app/Modules/Operations

rm -rf application/lang
cp -a "$BACKUP/lang" application/lang

cp -a "$BACKUP/mulkihawler.php" application/config/mulkihawler.php
```

`Operations` is restored because the deployment deleted a file from it; this puts
the pre-deployment tree back exactly as it was. Configuration is restored because
the patch added a `registration` block that reverted code never defined.

Keep the failed release. It costs nothing and it is the only evidence of what
went wrong.

## 8. Restore the public build — replaced, never merged

```bash
cd ~/domains/myhawler.com
rm -rf public_html/build
cp -a "$BACKUP/build" public_html/build

sha256sum public_html/build/manifest.json
```

This must equal the hash recorded in §1. A merged directory is the classic
half-rollback: the old manifest with new chunks still beside it, so the browser
requests assets the restored manifest never names.

## 9. Database restore decision

The schema is already correct after §5 — the table is gone and nothing else
changed. So this decision is about DATA, not schema:

| Situation | Action |
|---|---|
| Rolling back within minutes; §2 count near zero | **Do not restore.** Keep the data; handle stranded accounts per §11. |
| Account-first ran long enough to create real accounts and you want the database exactly as it was | Restore. Every account created since the deployment disappears. |
| The unlinked-account cleanup ran and reclaimed rows you want back | Restore. |

If restoring:

```bash
mysql -u <db_user> -p <db_name> < "$BACKUP/database.sql"
```

Restore the dump taken immediately before the deployment; a newer one contains
the state you are leaving. In the rehearsal the restore was exercised and the
stranded count afterwards was 0, because restoring the dump removes exactly
those accounts.

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

## 11. Verify routes, middleware, scheduler and queue — CLI only, still offline

Maintenance mode is still ON for all of this. These are CLI checks only.

```bash
/opt/alt/php83/usr/bin/php artisan route:list | wc -l
/opt/alt/php83/usr/bin/php artisan route:list | grep account/registration/abandon   # expect NOTHING
/opt/alt/php83/usr/bin/php artisan route:list | grep account/return                 # expect NOTHING
/opt/alt/php83/usr/bin/php artisan route:list | grep account/telegram/link          # expect the route
/opt/alt/php83/usr/bin/php artisan schedule:list | grep prune-unlinked              # expect NOTHING
/opt/alt/php83/usr/bin/php artisan schedule:list | grep prune-return-handoffs       # expect NOTHING
/opt/alt/php83/usr/bin/php artisan queue:work --stop-when-empty --max-time=50
```

The "expect nothing" checks are what prove the patch is genuinely gone rather
than partially removed.

**No HTTP checks here.** The site returns 503 to every request while `artisan
down` is in force, so a `curl` expecting 200 at this point cannot succeed. The
public checks move to §14, after maintenance is lifted.

## 12. Unlinked accounts from the patched period

If you did **not** restore the database, the accounts counted in §2 still exist:
unlinked, reachable only from a session that no longer exists, each holding its
phone number under a unique index. Under the reverted code they cannot finish
registration through the form, and there is no scheduled cleanup any more — it
went with the patch.

```bash
/opt/alt/php83/usr/bin/php artisan tinker --execute='echo App\Modules\Identity\Models\User::whereNull("telegram_verified_at")->whereNull("deleted_at")->count();'
```

Options, in order of preference:

1. **Leave them and tell support.** They can still finish through
   `/account/telegram/link`, which the reverted code also serves, if the person
   still holds their browser session.
2. **Release the numbers by hand** for anyone who contacts support.
3. **Restore the database** (§9) if the count is large and none of them matter.

Do not leave this undecided: each row silently blocks a real phone number from
registering.

## 13. Lift maintenance — the online phase begins here

Every offline check above must have passed first: migration reversed, table
absent, application and build restored, caches rebuilt, CLI checks clean.

```bash
/opt/alt/php83/usr/bin/php artisan up
```

## 14. Public rollback smoke — run immediately

```bash
curl -sS -o /dev/null -w '%{http_code}\n' https://myhawler.com/
curl -sS -o /dev/null -w '%{http_code}\n' https://myhawler.com/register
ASSET=$(curl -sS https://myhawler.com/ | grep -o 'build/assets/[^"]*' | head -1)
curl -sS -o /dev/null -w '%{http_code}\n' "https://myhawler.com/$ASSET"
```

Expect 200, 200, 200. A working gated redirect also proves `account.active` and
`telegram.linked` resolved, because an unresolvable alias returns 500.

Then confirm the **previous** registration flow is live again:

```text
open /register, submit with a spare number
expect: the Telegram pending screen — NOT /account/telegram/link
expect: no account is created by the form
```

**If the public smoke fails, go back to maintenance mode before anything else:**

```bash
/opt/alt/php83/usr/bin/php artisan down
```

A half-restored site serving traffic is worse than a site that is honestly
down. Diagnose from behind the maintenance page, using the failed release
preserved in §7.

### Rollback success criteria

```text
handoff migration reversed and telegram_return_handoffs absent
restored manifest hash equals the §1 hash; every entry resolves
route:list shows NO abandon route and NO return routes
schedule:list shows NEITHER cleanup
queue worker runs
home 200 and a referenced asset 200
/register shows the Telegram pending screen and creates no account
the §2 count has been acted on per §12
```

These are the conditions the corrected rehearsal asserts. They have **not yet
been demonstrated by an execution of the corrected harness** — see the rehearsal
status note at the top of this document. No current raw evidence exists for
them; `rollback-rehearsal.log` and `rollback-rehearsal.json` from the previous
round describe the rejected procedure and must not be cited for this one.
