# Public market page — the vertical, complete

Index values could not be published and there was nothing public to show them
on. Both are closed, so the market chain now runs from a spreadsheet to a
public figure.

```
CSV -> preview -> accept -> DRAFT prices -> review -> PUBLISHED prices
    -> observer -> queued rebuild -> DRAFT index values
    -> review -> PUBLISHED index values -> public market page
```

Every link in that chain existed in isolation before this slice. None of it
connected.

## What the public page commits to

Spec 15.3 lists eight things that must accompany any public index value:
period, effective date, source summary, sample size, confidence, revision
status, methodology version, and a warning when limited. All eight travel with
the figure, rendered **beside** it rather than behind a link — a disclosure a
reader has to go looking for is one most readers never see, and the ones who
most need it are the least likely to look.

**An asking-price index carries its qualifier above the number**, not below.
Placed after, the reader has already absorbed the figure as a market rate; the
gap between what sellers ask and what buyers pay is the specific thing this
product exists to measure, and burying that distinction would make the whole
Step 3 separation ornamental.

**Verified indices sort first.** An asking-price figure placed at the top reads
as the headline market number regardless of its label.

**An index with no published values is omitted, not rendered empty.** A card
reading "—" tells a reader the product is broken. Absence tells them nothing,
which is accurate.

**A revised figure says so.** A published number that quietly changed is
indistinguishable from one that was always different.

## What is withheld

Asserted directly, because these are the failures that matter: a draft value
never appears, an unpublished index never appears even when its values are
published, and a value with no figure cannot be published at all — publishing a
null would route around the calculator's publication floor.

## Verification

| Check | Result |
| --- | --- |
| `php -l`, whole tree | **PASS** 297/297 |
| `vue-tsc --noEmit` | **PASS** — 0 errors |
| `vite build` | **PASS** — 45 entries |
| Standalone logic suite | **PASS** — 1090 assertions, 0 failures |
| Translation parity | **PASS** — 810 keys, ckb/ar/en |
| Secret scan / migration guard | **PASS** |

123 feature tests exist; 9 are new. 29 Vue pages. **None of the feature
tests has run.**

The type checker caught one real error here: the explanation payload was typed
as a loose record, which would have silently accepted a response missing half
of what spec 15.3 requires. On this side of the wire the compiler is the only
thing checking that contract.

## The sparkline

Twenty-four points drawn as an inline SVG polyline rather than a chart library.
A public page should not pull a charting dependency to draw a line, and the
alternative would have added more to the bundle than every other component on
the page combined.

## Remaining issues

1. **Nothing has run.**
2. **Thirteen of spec 15.1's eighteen dashboard elements are absent** — heat
   map, gainers and decliners, demand movement, transaction activity, market
   digest and the rest. All are declared on the page rather than silently
   missing.
3. **No index value chart page.** The sparkline is indicative; there is no view
   of a full series with its per-period explanations.
4. **No default indices**, so the page is empty on a fresh install until an
   analyst defines one.
5. **No methodology page.** Spec 15.1 lists methodology links; the text is
   stored per index and nothing renders it.

## Rollback

Additive; no migration.

```bash
rm -rf resources/js/Pages/Public/Market
rm -f  resources/js/Components/IndexExplanation.vue        app/Modules/Market/Http/Controllers/Admin/IndexValueController.php        app/Modules/Market/Http/Controllers/Public/MarketController.php        app/Modules/Market/Routes/web.php tests/Feature/PublicMarketTest.php
git checkout -- app/Modules/Market/Routes/admin.php lang/*/market.php
npm run build
```
