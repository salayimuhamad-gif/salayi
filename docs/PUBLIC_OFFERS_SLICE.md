# Public offers — OfferRanker finally has a surface

The most consequential untested-in-place thing in the system now has one.

## What was true before this slice

`OfferRanker` was written in Step 5 and proved across 30 standalone assertions:
organic and sponsored returned as two lists that cannot be merged, sponsorship
absent from the score inputs, the same offer scoring identically at a bid of
zero and a bid of 999,999. All of it against synthetic arrays. No offer had ever
reached it, and nobody outside the admin could see one.

## The two halves

**`OfferScorer`** reads an offer and produces the seven merit signals. It does
not read the sponsorship flag at all — not to weight, not to break a tie, not to
skip a penalty. The ranker's guarantee only holds if the scores handed to it
were computed without reference to who paid, so the two are separate classes
with separate bindings and the absence is inspectable in one file.

One deliberate omission inside it: **company quality uses median response time,
not subscription tier.** Paying for a better plan must not improve an organic
position, which is the same rule the ranker enforces one layer up.

**The controller** hands the ranker its rows and receives two collections. It
cannot flatten them by accident, because it is never given them together.

## What the page commits to

The sponsored block is a separate `<section>` with its own heading, its own
border and tint, and its own `aria-label`, placed above the organic results and
never among them. Spec 19.5: "organic and sponsored results must be visually
distinguished."

**The disclosure label sits first on a sponsored card**, not below it. Placed
after, a reader has already judged the listing as an organic result.

**The detail page discloses sponsorship too.** Disclosing only in the results
list would mean anyone arriving from a shared WhatsApp link never sees it — and
shared links are how property listings actually circulate in Erbil.

**An approximate location says so** rather than implying a precise pin
(spec 19.4).

## Verification

| Check | Result |
| --- | --- |
| `php -l`, whole tree | **PASS** 309/309 |
| `vue-tsc --noEmit` | **PASS** — 0 errors, first pass |
| `vite build` | **PASS** — 51 entries |
| Standalone logic suite | **PASS** — 1090 assertions, 0 failures |
| Translation parity | **PASS** — 867 keys, ckb/ar/en |
| Secret scan / migration guard | **PASS** |

152 feature tests exist; 9 are new and they assert the guarantee survives the
HTTP layer — including a deliberately poor listing with a 999,999 bid, which
must not displace a good unpaid one. **None has run.**

## Remaining issues

1. **Nothing has run.** The separation is asserted at unit level and at request
   level; neither suite has executed.
2. **No media.** Cards and detail pages carry no images, which for property
   listings is a substantial gap.
3. **No expiry job.** `published()` filters lapsed offers out of the query, so
   an expired listing disappears correctly — but its status stays Published
   forever and the admin list shows it as live.
4. **No saved searches or alerts** on the browse page, though the schema exists.
5. **No comparison** (spec 12.4).
6. **The scorer's weights are unreviewed product judgements.** Completeness at
   15 and freshness at 15 against user match at 30 is defensible and arbitrary;
   these want an opinion from someone who knows how Erbil buyers actually
   search.

## Rollback

Additive; no migration.

```bash
rm -rf resources/js/Pages/Public/Offers
rm -f  resources/js/Components/OfferCard.vue        app/Modules/Marketplace/Services/OfferScorer.php        app/Modules/Marketplace/Http/Controllers/Public/OfferBrowseController.php        app/Modules/Marketplace/Routes/web.php tests/Feature/PublicOffersTest.php
git checkout -- app/Modules/Marketplace/Providers/MarketplaceServiceProvider.php lang/*/marketplace.php
npm run build
```
