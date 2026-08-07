# Project admin slice — create and publish

The second vertical slice. Builds on the admin shell and puts Step 2's
publication gate in front of a user for the first time.

## Verification — what actually ran

| Check | Result |
| --- | --- |
| `php -l`, whole tree | **PASS** 248/248 |
| **`vue-tsc --noEmit`** | **PASS** — 0 errors |
| **`vite build`** | **PASS** — 27 precached entries, 345 KB |
| Standalone logic suite | **PASS** — 1,028 assertions |
| Translation parity | **PASS** — 612 keys, ckb/ar/en |
| Secret scan / migration guard | **PASS** |

42 feature tests now exist across the suite. **None has run** — Composer is
unreachable, so Laravel cannot boot.

## What this slice does

**List** with search over the Sorani `search_key` (so a query typed with an
Arabic yeh still matches), status filter, pagination, and data-quality badges
so a missing location or a missing translation is visible without opening the
record.

**Form** in five sections rather than a fourteen-screen wizard. Spec 12.3 lists
fourteen steps and permits saving a draft at any stage; a single scrolling form
with anchored sections satisfies both and is markedly faster for the common
case, which is an editor correcting two fields on an existing project rather
than creating one from nothing. The wizard shape helps first entry and slows
every edit after it.

**Publication gate** — the reason this slice matters. Step 2 wrote
`Project::publicationReadiness()` and `transitionTo()` and nothing ever called
them. The readiness result is now sent to the browser on every edit, so an
administrator sees *which* fields block publication while the form is still
open, rather than pressing Publish and receiving a refusal they have to
decode.

## Decisions worth reviewing

**Validation is permissive about completeness, strict about correctness.**
Almost every field is nullable, because a draft is saved across sections. But
whatever *was* entered is checked hard: a transposed lat/lng, a Baghdad
coordinate, null island, a malformed or non-polygon WKT boundary, and a
delivery date preceding launch are all rejected at entry rather than stored and
discovered later by the map.

**A transposed coordinate gets its own message.** `Coordinates::looksSwapped()`
distinguishes "you reversed the fields" from "this is outside Erbil", because
the first is auto-correctable and the message can say so. Erbil sits at 36.19 N,
44.01 E — both plausible in either slot, so a range check alone catches
neither.

**The slug is derived once and never auto-updated.** A published URL that
shifts because someone corrected a spelling breaks every inbound link and every
shared WhatsApp message.

## Database changes

**None.** Every column this slice writes was created in Step 2.

## Remaining issues

1. **No screenshots.** Rendering needs a booted Laravel. The frontend compiles;
   nothing has drawn a pixel. I will not simulate them.
2. **No map picker.** Coordinates and WKT are typed by hand. The geometry is
   validated, but an editor drawing a boundary needs MapLibre, which is not
   wired.
3. **No media, phases, unit types or ratings.** Spec 12.1 lists them and the
   schema holds them; this slice covers identity, location, delivery, scale and
   sources only.
4. **No developer creation.** The developer dropdown reads existing rows; there
   is no screen to add one, so it is empty on a fresh install.
5. **Areas must exist first.** The area dropdown reads `areas`, which no
   seeder populates — so `area_missing` will block publication until an area
   exists, and there is no screen to create one.
6. **Delete and archive are not exposed.** The workflow supports archiving; the
   UI offers only forward transitions.

Items 4 and 5 mean **a fresh install cannot yet publish a project end to end**,
even once dependencies are installed. Areas and developers are the next
prerequisite, ahead of the public profile.

## Type errors this slice surfaced

Four, all invisible to `php -l` and to review:

1. A `never`-returning field helper that silently satisfied every assignment
   and failed only at the first call site the compiler checked.
2. An inline object type assertion in a Vue template — template expressions are
   not full TypeScript.
3. `AppInput` declared `modelValue: string` while numeric fields legitimately
   model as `number | null`.
4. An unused `props` binding in `AppSelect`.

## Rollback

Additive; no migration.

```bash
rm -rf resources/js/Pages/Admin/Projects
rm -f  resources/js/Components/PublicationGate.vue resources/js/Components/ui/AppSelect.vue
rm -rf app/Modules/Projects/Http/Controllers/Admin app/Modules/Projects/Http/Requests
rm -f  app/Modules/Projects/Routes/admin.php tests/Feature/ProjectAdminTest.php
git checkout -- app/Modules/Core/Support/AdminNavigation.php lang/*/projects.php \
                resources/js/Components/ui/AppInput.vue
npm run build
```
