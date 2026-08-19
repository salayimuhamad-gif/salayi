# Map picker slice

Boundary and coordinate entry by drawing, rather than by pasting WKT.

## Read this before trusting the slice

**This slice has a materially weaker verification story than the three before
it.** A form that type-checks is quite likely to work. A map component that
type-checks may be completely broken — the tile loading, the click handling,
the drag behaviour and the visual result are all unverified. Nothing has
rendered a map.

What *is* verified is the part that would corrupt data if wrong, and that is
verified properly. See §2.

## 1. What actually ran

| Check | Result |
| --- | --- |
| `php -l`, whole tree | **PASS** 253/253 |
| `vue-tsc --noEmit` | **PASS** — 0 errors, first pass |
| `vite build` | **PASS** — 33 entries; maplibre-gl is a 981 KB lazy chunk (257 KB gzipped) |
| Standalone logic suite | **PASS** — 1,028 assertions |
| Translation parity | **PASS** — 644 keys |
| Secret scan / migration guard | **PASS** |

## 2. The cross-implementation check

The picker writes WKT in TypeScript because round-tripping every drag through
the server would be unusable on an Erbil mobile connection. That means two
implementations of the same format, which is a real risk: if they disagree, an
editor draws one shape and the database stores another.

So they were checked against each other, executed:

```
browser emits : POLYGON((44 36.1, 44.1 36.1, 44.1 36.2, 44 36.2, 44 36.1))
PHP validates : yes (POLYGON)

browser centroid : 36.15,44.05
PHP centroid     : 36.15,44.05      ← identical
```

The TypeScript side also refuses what it should: a `POINT` returns null, and a
ring of fewer than three points emits nothing rather than an invalid polygon.

The division of authority is deliberate: the browser side is the **narrower**
of the two. It reads and writes only `POLYGON`, and everything it emits is
re-parsed and re-validated by the PHP request on submit. It can be permissive
about what it *displays* and never about what it *emits*.

## 3. Decisions worth reviewing

**The style URL is shared with the browser; provider credentials are not.**
Spec 17.1 and 37.5 keep keys out of anything a browser receives — a Google Maps
key shared in the Inertia payload would be readable in page source.

**With no style URL configured the map still works.** It renders a plain
background, and clicking, drawing and emitting all function without tiles.
Spec 10.1: "the application must remain usable when one provider fails." A
component that requires a configured tile server to let someone type a
coordinate would fail that.

**The map and the text fields are two views of the same values**, bound both
ways. An editor may draw, or paste WKT from an import, and each updates the
other. Removing the text fields would have been cleaner and would have made
import-derived boundaries uneditable.

**The centroid is offered only when no point was set by hand.** An editor who
dropped a pin on a project's entrance meant that, not the mathematical centre
of its plot.

**A map that fails to initialise does not take the form down.** The error is
caught, a notice replaces the canvas, and the coordinate and WKT fields remain
editable.

## 4. Also in this slice

`project_count` on the area list — flagged as the one placeholder in the
previous slice — is now a real `withCount`, with an `Area::projects()` relation
counting **direct children only**. A district's count that silently included its
neighbourhoods would not reconcile with the list beneath it.

## 5. Remaining issues

1. **Nothing has rendered.** No map has drawn, no click has been handled. This
   is the weakest-verified code in the project.
2. **Polygon editing is add-and-undo only.** There is no vertex dragging and no
   mid-ring insertion, so correcting a boundary means redrawing it.
3. **No multipolygon.** Areas made of disjoint parts must be entered as WKT by
   hand; the picker reads only the exterior ring of the first polygon.
4. **No holes.** An area with an excluded interior cannot be drawn.
5. **maplibre-gl adds 981 KB** (257 KB gzipped) as a lazy chunk loaded only on
   the two pages with a picker. Acceptable for an admin tool on a desk; it
   should not be pulled into any public page without reconsidering.
6. **No geocoding.** There is no address search, so finding a location means
   panning manually.

## 6. Rollback

Additive; no migration.

```bash
rm -f resources/js/Components/MapPicker.vue resources/js/lib/geometry.ts
npm uninstall maplibre-gl
git checkout -- resources/js/Pages/Admin/Areas/Form.vue \
                resources/js/Pages/Admin/Projects/Form.vue \
                app/Http/Middleware/HandleInertiaRequests.php lang/*/geography.php
npm run build
```

Keep `app/Modules/Geography/Models/Area.php` and `AreaController.php` — they
carry the independent `project_count` fix.
