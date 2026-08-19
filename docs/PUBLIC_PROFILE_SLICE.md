# Public Sorani project profile slice

The last item from the original slice list. Completes the end-to-end path.

## Verification

| Check | Result |
| --- | --- |
| `php -l`, whole tree | **PASS** 256/256 |
| `vue-tsc --noEmit` | **PASS** — 0 errors, first pass |
| `vite build` | **PASS** — 36 entries |
| Standalone logic suite | **PASS** — 1,028 assertions |
| Translation parity | **PASS** — 663 keys, ckb/ar/en |
| Secret scan / migration guard | **PASS** |

62 feature tests now exist. **None has run.**

## What this page is actually for

Spec 37.2 requires that the "public profile displays evidence and dates", and
spec 5 requires every public fact to carry a source and a freshness marker.
That is the product's central claim — that its numbers can be trusted because
their provenance travels with them — so the payload is shaped around it rather
than having provenance appended as a footer.

**A fact without a source is omitted, not shown bare.** This is the half that
matters. An unsourced unit count rendered identically to a sourced one is
precisely the quiet unreliability that costs a market intelligence platform its
credibility, and nobody notices it happening. The controller drops the entire
facts block when `source` is empty, and there is a test for it.

**Freshness is an age, not only a date.** "Verified 2026-01-15" makes a reader
do arithmetic before they can judge it. "Verified over six months ago" *is* the
judgement, and the chip's tone shifts as it decays — muted under six months,
caution beyond, negative beyond a year — so a stale figure looks stale.

**An implied completion percentage is labelled implied.** Step 2's
`ConstructionStatus::impliedCompletionPercent()` derives a figure when none was
recorded. Presenting a derived number identically to an observed one would be a
small dishonesty repeated on every project.

**A stalled or delayed project is stated plainly** in a warning, not left in a
status field a reader has to interpret.

**Sections the product has not populated are declared, not silently absent.**
Nearby places, price history, ratings and media are all listed as "not yet
available". Spec 17.5's first rule is "say when data is insufficient"; a page
that simply lacks a nearby-places section reads as a project with no nearby
places.

## Other decisions

**A draft returns 404, not 403.** A 403 confirms the slug is taken and leaks
the editorial pipeline.

**Structured data asserts only what the visible page shows.** JSON-LD claiming
more than the rendered page is the definition of misleading markup. hreflang
alternates cover every enabled locale (spec 31.2).

**A translation fallback is disclosed to the reader.** When a Sorani name is
missing and English is shown instead, the page says so rather than quietly
serving another language.

## Database changes

**None.** Every column was created in Step 2.

## Remaining issues

1. **Nothing has rendered.** No page has been served.
2. **No map on the profile.** Geometry is in the payload; the public page does
   not draw it. The picker exists but pulling 981 KB of maplibre into a public
   page needs the lazy-loading decision made deliberately, not by default.
3. **No nearby places, price history, ratings or media.** All four are declared
   unavailable rather than faked. Each needs work that does not exist: places
   CRUD, the Step 3 importer, a ratings screen, media upload.
4. **No locale-prefixed routes.** `seo.alternates` emits `/ckb/projects/…`
   URLs, but only `/projects/…` is routed. **The alternates currently point at
   404s** — either the routes gain a locale prefix or the alternates should be
   dropped until they do. This is a real defect, not a limitation.
5. **No breadcrumbs, no related projects, no share metadata** (`og:` tags).

Item 4 is the one to fix first.

## Rollback

Additive; no migration.

```bash
rm -rf resources/js/Pages/Public/Projects
rm -f  resources/js/Components/ProvenanceChip.vue \
       app/Modules/Projects/Http/Controllers/Public/ProjectProfileController.php \
       app/Modules/Projects/Routes/web.php \
       tests/Feature/PublicProjectProfileTest.php
git checkout -- lang/*/projects.php lang/*/app.php
npm run build
```
