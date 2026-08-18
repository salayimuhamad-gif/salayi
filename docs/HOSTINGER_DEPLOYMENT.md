# Hostinger deployment

Target: Hostinger shared hosting. No root, no SSH on the cheaper plans, no
Redis, no long-running process. Every architectural choice below follows from
one of those four constraints.

## 1. Directory layout

Hostinger serves `public_html`. Laravel expects the framework root to be
*above* the document root, otherwise `.env`, `storage/` and `vendor/` are
web-reachable.

```
/home/ACCOUNT/
  application/        <- the whole Laravel tree except public/
    app/ bootstrap/ config/ database/ lang/ resources/ routes/
    storage/ vendor/ artisan .env
  public_html/        <- contents of public/ only
    index.php .htaccess robots.txt build/ storage/
```

`public/index.php` resolves the application root from
`MULKIHAWLER_APP_BASE`, falling back to `dirname(__DIR__)` for a conventional
single-root install. The same artifact therefore deploys to both layouts with
no code edit.

If the path is wrong the front controller prints a plain-text message naming
the path it tried, rather than failing with a class-not-found several layers
down. This is the single most common first-deploy failure and it is worth the
eight lines.

Set the base in `public_html/.user.ini`:

```ini
; Hostinger reads .user.ini per directory
env[MULKIHAWLER_APP_BASE] = /home/ACCOUNT/application
```

If your plan does not honour `.user.ini` for env, edit the fallback constant at
the top of `public_html/index.php` instead — it is one line and it is the only
file in the artifact intended to be edited on the server.

## 2. Why the database does everything

| Concern | Driver | Reason |
| --- | --- | --- |
| Cache | `database` | No Redis, and the file driver on shared storage is slow and contends under concurrent requests. |
| Sessions | `database` | Same, plus sessions must survive across the pool of PHP workers. |
| Queue | `database` | No daemon may run. |

`config/mulkihawler.php` records this as `hosting.profile = shared`, which is
what the installer reads to decide whether a missing Redis extension is a
failure or a non-issue.

## 3. The single cron entry

Shared hosting cannot run `queue:work` as a daemon. One cron line drives
everything:

```
* * * * * /usr/bin/php /home/ACCOUNT/application/artisan schedule:run >> /dev/null 2>&1
```

`routes/console.php` then runs a **bounded** worker every minute:

```
queue:work --stop-when-empty --max-time=50 --tries=3
           --queue=critical,notifications,default,imports,ai,maintenance
```

- `--max-time=50` keeps each invocation inside the host's execution limit.
- `--stop-when-empty` returns immediately when there is nothing to do.
- `withoutOverlapping(2)` stops two cron ticks processing the same job.
- The queue order means a 20,000-row import can never delay a login
  notification.

### Confirm the cron is actually running

A silently stopped cron is the most common shared-hosting production incident
and the hardest to notice — alerts simply stop arriving. `scheduler_heartbeats`
exists for this. After deploying:

```sql
SELECT key, last_success_at FROM scheduler_heartbeats WHERE key = 'scheduler';
```

If `last_success_at` is more than five minutes old, the cron is not running.
`SchedulerHeartbeat::isStale()` encodes that check for the admin dashboard.

## 4. PHP settings

Set in hPanel → Advanced → PHP Configuration:

| Setting | Value | Why |
| --- | --- | --- |
| PHP version | 8.3 or 8.4 | Enforced by `composer.json` |
| `memory_limit` | ≥ 256M | Image processing and spreadsheet imports |
| `max_execution_time` | ≥ 60 | The migration step |
| `upload_max_filesize` | ≥ 20M | Project media and Excel imports |
| `post_max_size` | ≥ 24M | Must exceed `upload_max_filesize` |

Required extensions: `bcmath ctype curl dom fileinfo intl json mbstring openssl
pdo pdo_mysql tokenizer xml zip`. The installer checks each individually and
names the missing one, because "requirements not met" is not actionable in
hPanel.

`intl` and `bcmath` are the two that are sometimes off by default, and both are
load-bearing: `intl` for locale formatting, `bcmath` for every price in the
system.

## 5. Database

Create the database and user in hPanel first — the installer cannot create
them, because the MySQL user it is given does not have `CREATE DATABASE`.

Character set must be `utf8mb4` with `utf8mb4_unicode_ci`. `utf8mb3` silently
truncates Sorani text and emoji in user-submitted content.

## 6. Deploying an update

Because there is no SSH, an update is a file upload plus the installer's
upgrade mode. The rules that matter:

1. **`APP_KEY` is never regenerated on an upgrade.** `EnvWriter` refuses to
   overwrite it, and `config/installer.php` lists it under `upgrade.preserve`
   alongside `MULKIHAWLER_PII_KEY` and `MULKIHAWLER_BLIND_INDEX_KEY`.
   Regenerating any of these makes every encrypted phone number, MFA secret and
   recovery code in the database permanently unreadable, and the damage is
   invisible until a user tries to sign in.
2. `storage/app/public`, `storage/app/private` and `.env` are never touched.
3. Take a database backup before the migrate step. On Step 8 this becomes
   automatic; until then, do it from hPanel.

> **Superseded for v6.** This page documents FIRST INSTALLATION. The upgrade
> path is no longer "manual, see the roadmap": `DEPLOY_HOSTINGER.md` is the
> rehearsed procedure for deploying a release over an existing installation,
> and `ROLLBACK_HOSTINGER.md` reverses it from a fresh shell. Both were
> executed against the exact shipped artifact (27/27 and 11/11 checks). Back
> up first regardless — both documents make that step 2.

## 7. After installation

The installer writes `storage/installer/installed.lock` and deletes its own
state file. From that point `/install` returns **404**, not a redirect — an
installed site should not advertise that an installer ever existed.

Reopening it requires `MULKIHAWLER_INSTALL_RESET_TOKEN` in `.env` (minimum 32
characters) passed as a query parameter, compared in constant time. That token
only exists for someone with filesystem access, which is the intended bar.

Finally, tighten permissions:

```
.env                     600
storage/                 755
storage/installer/*.lock 440
```
