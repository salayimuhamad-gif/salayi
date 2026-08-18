# Areas and developers slice

The prerequisite slice. Built ahead of the public project profile because
without it a fresh installation cannot publish a project at all: `area_missing`
blocks publication and the developer dropdown is empty.

## Verification — what actually ran

| Check | Result |
| --- | --- |
| `php -l`, whole tree | **PASS** 253/253 |
| **`vue-tsc --noEmit`** | **PASS** — 0 errors, first pass |
| **`vite build`** | **PASS** — 32 precached entries, 362 KB |
| Standalone logic suite | **PASS** — 1,028 assertions |
| Translation parity | **PASS** — 635 keys, ckb/ar/en |
| Secret scan / migration guard | **PASS** |

51 feature tests now exist. **None has run** — Composer is unreachable.

## What this unblocks

Before this slice, the end-to-end path was broken at two points. A project
could be created but never published, because no area existed to satisfy
`area_missing`, and it could not be attributed to a developer because no screen
created one. Both are now reachable, so the sequence

> create city → create district → create developer → create project → send to
> review → publish

is complete in source for the first time.

## Decisions worth reviewing

**Areas are listed by materialised path, not by name.** Ordering by `path`
renders the hierarchy correctly nested as a flat list with no recursive query —
which matters because the shared-hosting MySQL version cannot be assumed to
handle recursive CTEs well. Depth becomes indentation.

**The parent dropdown is filtered, not merely validated.** Only areas strictly
coarser than the selected type appear. The model already throws on an inverted
hierarchy; filtering means an editor never reaches that error. The request
*also* validates it, because the model guard is the real boundary and a
`RuntimeException` escaping a form submission is a 500 page rather than
feedback.

**Developer verification is separate from publication, and separately
permissioned.** Publishing a developer profile makes it visible; verifying it
asserts the platform checked who they are. A buyer reading "verified developer"
on a project page relies on the second claim, so it cannot be a side effect of
an editor saving a description. Changing it requires `companies.verify` and is
audited at `warning` severity.

**No Erbil geography is seeded.** I could have shipped a seeder with Erbil's
districts and their coordinates. I have no way to verify those boundaries, and
a seeded area with an invented centroid would feed the valuation engine's
wider-area fallback and the nearby-place calculator with data nobody entered
and nobody can source. The screens exist so an administrator enters what they
can attest to.

## Database changes

**None.** Every column was created in Step 2.

## Remaining issues

1. **No screenshots.** Rendering needs a booted Laravel.
2. **No map picker.** Coordinates and WKT boundaries are typed by hand and
   validated, but drawing one needs MapLibre, which is not wired.
3. **Boundaries must be pasted as WKT.** Realistic for an import, painful for
   manual entry — this is the strongest argument for the map picker being the
   next piece of work rather than the public profile.
4. **No place administration.** `places` has a schema and the 31 seeded
   categories, but no CRUD; nearby-place calculation therefore has nothing to
   calculate against.
5. **Area deletion is not exposed.** Soft deletes exist on the model; the UI
   offers no delete, deliberately — deleting an area with descendants needs a
   reparenting decision this slice does not make.
6. **`project_count` on the area list is hardcoded to zero.** The relation
   exists; counting it per row needs a `withCount` that I did not add, and
   showing a wrong number would be worse than showing none. **This is the one
   placeholder in the slice and it should be fixed before the list is trusted.**

## Rollback

Additive; no migration.

```bash
rm -rf resources/js/Pages/Admin/Areas resources/js/Pages/Admin/Developers
rm -rf app/Modules/Geography/Http/Controllers/Admin app/Modules/Geography/Http/Requests
rm -f  app/Modules/Geography/Routes/admin.php \
       app/Modules/Projects/Http/Controllers/Admin/DeveloperController.php \
       tests/Feature/GeographyAdminTest.php
git checkout -- app/Modules/Core/Support/AdminNavigation.php \
                app/Modules/Projects/Routes/admin.php \
                lang/*/geography.php lang/*/projects.php lang/*/companies.php
npm run build
```
