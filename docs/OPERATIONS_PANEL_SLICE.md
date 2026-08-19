# Operations panel — audit log and system health

Closes a gap that had been open since Step 1.

## The gap

Every step of this build wrote to `audit_logs`. Nothing ever read it.

An append-only audit trail that cannot be inspected provides no accountability —
it provides the *feeling* of accountability, which is worse, because it stops
anyone asking for the real thing. The same was true of `failed_jobs`: on a
cron-driven worker a job that exhausts its retries fails silently, the queue
drains, the site looks healthy, and the work simply never happened.

## What it shows

**Audit log**, filterable by action, severity, subject type and actor, with the
stored context expandable per entry. The actor is the denormalised label rather
than a join, so the log stays readable after an account is erased under a
data-rights request (spec 30.3).

**Scheduler heartbeat** per key. A silently stopped cron is the most common
shared-hosting incident and nothing else in the product reveals it.

**Queue depth per queue name.** Depth growing on `maintenance` while
`critical` stays empty is the signal that the worker is running but saturated —
which looks identical to "healthy" from anywhere else.

**Failed jobs**, with the exception truncated server-side. A full stack trace on
an admin page is a disclosure risk: it names file paths, package versions and
sometimes query fragments containing user data.

**Data-quality gaps** across entities — published projects with no source, places
with no source, areas with no boundary, stale nearby snapshots. These are the
things that raise no error and silently do not work: a published project with no
source shows no facts at all, and nothing anywhere says why.

Only non-zero gaps are listed. A wall of zeroes trains people to stop looking.

## Decisions worth reviewing

**Audit access is its own permission** (`audit.logs.view`), not implied by
administrative rank. A Sales Agent is an administrator and has no business
reading the security log.

**Redacted context is served exactly as stored.** `Redactor` scrubbed it on
write; the reader never re-derives it, because re-deriving would mean holding
the unredacted value to derive from.

**Filter facets are windowed to 30 days and capped.** `audit_logs` grows without
bound, and a `SELECT DISTINCT` over the whole table gets slower every week on a
shared host.

## Verification

| Check | Result |
| --- | --- |
| `php -l`, whole tree | **PASS** 274/274 |
| `vue-tsc --noEmit` | **PASS** — 0 errors, first pass |
| `vite build` | **PASS** — 40 entries |
| Standalone logic suite | **PASS** — 1,028 assertions |
| Translation parity | **PASS** — 705 keys, ckb/ar/en |
| Secret scan / migration guard | **PASS** |

97 feature tests exist; 7 are new. **None has run.**

## Remaining issues

1. **Nothing has run.**
2. **No retry or delete for failed jobs.** They are visible and not actionable;
   clearing them still needs `php artisan queue:flush`.
3. **No audit export.** Spec 24.5 lists export as a bulk operation; this is
   read-only in the browser.
4. **No retention policy.** `audit_logs` grows without bound and nothing prunes
   it. On shared hosting with a finite disk quota that is a real operational
   risk, and silently deleting audit rows is not a decision code should make
   unprompted — it needs an administrator's explicit policy.
5. **Data-quality counts are computed per request.** Six `COUNT` queries on
   every page load; they should be cached once the tables are large.

## Rollback

Additive; no migration.

```bash
rm -rf resources/js/Pages/Admin/Operations
rm -f  app/Modules/Operations/Http/Controllers/Admin/OperationsController.php        app/Modules/Operations/Routes/admin.php        tests/Feature/OperationsPanelTest.php lang/*/operations.php
git checkout -- app/Modules/Core/Support/AdminNavigation.php                 app/Http/Middleware/HandleInertiaRequests.php lang/*/nav.php
npm run build
```
