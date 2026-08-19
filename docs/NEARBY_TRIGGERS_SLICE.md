# Nearby-place recalculation triggers

Closes the gap flagged at the end of the previous slice: the calculator existed
and nothing started it.

## The gap

`NearbyPlaceCalculator` was complete and tested, and had to be invoked by hand.
A feature nobody can start is not a feature — an editor publishing a new school
would have seen nothing change on any project page, indefinitely, with no
indication that anything was pending.

## The design, and why it is shaped by the host

Hostinger gives one cron-driven worker with roughly fifty usable seconds per
tick, and no daemon. That constrains every choice here.

**Queued, on the `maintenance` queue.** `routes/console.php` orders the worker
`critical,notifications,default,imports,ai,maintenance`. Publishing fifty places
can therefore queue fifty recalculations without delaying a single login
notification. On a host with one worker, queue ordering is the only scheduling
control that exists.

**Unique per project, for two minutes.** The triggers overlap by design — saving
a project fires one, publishing a nearby place fires another for the same
project. Without `ShouldBeUnique` an afternoon of editing would queue the same
expensive calculation dozens of times and starve everything behind it.

**Bounded blast radius.** A place change queues only projects within the widest
category radius (25 km), found by bounding box and then filtered by exact
distance. Queueing every project would make publishing one café O(projects).

**The sweep is hourly, not per-minute.** The observers handle the common cases
in near-real time; the scheduled command is the safety net for what they miss —
a direct import, a job that exhausted its three attempts, a manual database
correction. A stale distance is not worth a cron tick every sixty seconds, and
`--limit=25` keeps each run inside the worker's window rather than being killed
mid-flight every minute and never completing.

## Ordering that matters

On a moved place: **flag stale first, queue second.** Between the two, a reader
sees the old figure marked as needing refresh rather than a silently different
number. There is a test asserting the row is stale before the job is pushed.

## What does not trigger

Renaming a project, correcting its unit count, editing its description. Only
`latitude`, `longitude` and `boundary_wkt` changes queue work — asserted, because
recalculating on every save would make routine editing expensive on this host.

## Manual trigger

An editor who has just imported places, or who is looking at a stale badge,
should not wait on an hourly sweep they cannot see. The project form shows the
calculated count, the amenity score, a stale warning, and a **Recalculate**
button. The job is unique per project, so pressing it repeatedly costs nothing.

## Verification

| Check | Result |
| --- | --- |
| `php -l`, whole tree | **PASS** 268/268 |
| `vue-tsc --noEmit` | **PASS** — 0 errors, first pass |
| `vite build` | **PASS** — 38 entries |
| Standalone logic suite | **PASS** — 1,028 assertions |
| Translation parity | **PASS** — 680 keys |
| Secret scan / migration guard | **PASS** |

90 feature tests exist; 8 are new. **None has run** — and these are the ones I
would most want to see run, because observer registration, queue naming and
`ShouldBeUnique` behaviour are all things that either work at runtime or fail
silently.

## Remaining issues

1. **Nothing has run.** Observers registering correctly, jobs landing on the
   right queue, and uniqueness actually deduplicating are all runtime
   behaviours. The tests assert them; nothing has executed the tests.
2. **No failure surface.** A job that exhausts three attempts leaves the
   snapshot stale with nothing to tell an administrator. `failed_jobs` records
   it; no screen reads that table.
3. **No progress indication.** After pressing Recalculate the page says
   "queued" and nothing reports completion — on a cron-driven worker that can
   be up to a minute.
4. **The 25 km influence radius is hardcoded** as a constant rather than derived
   from the maximum of the category radii. Correct today; it will drift if a
   category with a wider radius is added.

## Rollback

Additive; no migration.

```bash
rm -rf app/Modules/Geography/Jobs app/Modules/Geography/Observers app/Modules/Geography/Console
rm -f  app/Modules/Geography/Routes/console.php tests/Feature/NearbyRecalculationTriggerTest.php
git checkout -- app/Modules/Geography/Providers/GeographyServiceProvider.php \
                app/Modules/Projects/Http/Controllers/Admin/ProjectController.php \
                app/Modules/Projects/Routes/admin.php \
                resources/js/Pages/Admin/Projects/Form.vue lang/*/geography.php
npm run build
```
