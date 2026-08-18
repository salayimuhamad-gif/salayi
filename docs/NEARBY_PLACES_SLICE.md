# Places and nearby-place calculation

Turns the public profile's "nearby places — not yet available" into a real
section, and gives `NearbyPlaceRanker` its first real data.

## What was already true, and what was missing

Step 2 built the *judgement* — `NearbyPlaceRanker`, proven against 33 standalone
assertions including the one that matters: a hospital 4 km away outranks a café
200 m away, because relevance is not distance. It has never seen a database row.

Missing was everything around it: no place administration, so nothing to rank;
no candidate query; no snapshot writing. This slice supplies those.

## Verification

| Check | Result |
| --- | --- |
| `php -l`, whole tree | **PASS** 262/262 |
| `vue-tsc --noEmit` | **PASS** — 0 errors, first pass |
| `vite build` | **PASS** — 38 entries |
| Standalone logic suite | **PASS** — 1,028 assertions |
| Translation parity | **PASS** — 680 keys |
| Secret scan / migration guard | **PASS** |

82 feature tests exist; 10 are new here. **None has run.**

## Decisions worth reviewing

**One bounding box per category, sized to that category's own radius.** A
pharmacy at 20 km is noise; an airport at 20 km is a selling point. Querying one
global radius would either flood the panel with distant cafés or hide the
airport. The box is a coarse pre-filter — four indexed range predicates on the
DECIMAL lat/lng columns, an index scan on both engines — and exact great-circle
distance is computed in PHP over the survivors, which is what makes the corners
of the box get discarded rather than silently included.

**Travel distance stays null.** Spec 10.5 step 3 makes it conditional on a
routing provider. Copying the straight-line figure into it would be a
fabrication: a school 400 m away across a motorway with no crossing is not a
four-hundred-metre walk. The public section labels the figure as straight-line.

**A moved place flags snapshots; it does not rewrite them.** Spec 10.5 step 7.
A published distance that silently changes under someone who already acted on it
is worse than a stale one that says it is stale. There is a test asserting the
frozen figure is unchanged after a move.

**Manual pins and hidden rows survive recalculation.** An editor's override
disappearing on the next refresh would be experienced as the system fighting
them.

**Unsourced places are excluded from the public profile**, the same rule the
project facts follow. A distance is a claim about the world like any other.

**`unavailable.nearby_places` is now computed**, not hardcoded — a project with
nothing nearby still declares the section rather than omitting it silently.

## Remaining issues

1. **Nothing has run.** The calculator has never touched a database.
2. **No recalculation trigger.** Nothing calls `recalculate()` automatically —
   not on project save, not on place publish, not on a schedule. It must be
   invoked manually until a job or observer is added. **This is the gap that
   matters most: the feature exists and nothing starts it.**
3. **No place import.** Spec 11.1 lists Excel, CSV and API ingestion; entry is
   one at a time.
4. **No duplicate detection.** The schema has `duplicate_group`; nothing
   populates it.
5. **No nearby-places admin panel.** Overrides are respected but there is no
   screen to set them — `is_manual` and `is_hidden` are only reachable by direct
   database edit.
6. **No place quality scoring.** Spec 11.4's five dimensions have columns and no
   calculator.

## Rollback

Additive; no migration.

```bash
rm -rf resources/js/Pages/Admin/Places
rm -f  app/Modules/Geography/Services/NearbyPlaceCalculator.php \
       app/Modules/Geography/Http/Controllers/Admin/PlaceController.php \
       app/Modules/Geography/Http/Requests/PlaceRequest.php \
       tests/Feature/NearbyPlacesTest.php
git checkout -- app/Modules/Geography/Routes/admin.php \
                app/Modules/Geography/Providers/GeographyServiceProvider.php \
                app/Modules/Core/Support/AdminNavigation.php \
                app/Modules/Projects/Http/Controllers/Public/ProjectProfileController.php \
                resources/js/Pages/Public/Projects/Show.vue lang/*/geography.php
npm run build
```
