# Offer administration and moderation

Step 5's eleven-state workflow and moderator gate, driven by a request for the
first time.

## The invariant this protects

**A company cannot publish its own listing.** Every path to Published runs
through Approved, and only a moderator can set Approved. Without that the
marketplace is a self-service billboard, and the platform's judgement — which is
the only thing it is selling — is worth nothing.

Asserted three ways: a company user cannot publish from Approved, cannot approve
from Under Review, and **cannot forge the moderator flag in the request body**.
That last one matters because the flag is derived from the permission inside the
controller and never read from input; posting `actor_is_moderator=true` changes
nothing.

A draft also cannot skip the pipeline, even for a moderator.

## Other decisions

**Every transition appends to `offer_status_history`**, recording who acted and
whether they acted as a moderator. Append-only, so the trail survives archiving
and any later overwrite of the moderation notes.

**An undisclosed advertisement cannot exist.** The model throws, the request
validates, and the form reveals the disclosure field the moment sponsorship is
switched on — three layers, because spec 19.5 and 18.4 are the rules a
commercial partner has the most incentive to route around.

**An exact location claim requires an exact location.** Publishing "exact" with
no coordinate would put a pin nowhere and label it precise. Approximate remains
available, because an owner listing an occupied home should not have to publish
their address to appear on a map (spec 19.4).

**Unverified companies are marked in the picker** with a warning glyph rather
than hidden. An offer from an unverified company is allowed; a moderator should
simply know before approving it.

## Verification

| Check | Result |
| --- | --- |
| `php -l`, whole tree | **PASS** 305/305 |
| `vue-tsc --noEmit` | **PASS** — 0 errors, first pass |
| `vite build` | **PASS** — 49 entries |
| Standalone logic suite | **PASS** — 1090 assertions, 0 failures |
| Translation parity | **PASS** — 861 keys, ckb/ar/en |
| Secret scan / migration guard | **PASS** |

143 feature tests exist; 10 are new. 33 Vue pages. **None has run.**

## Remaining issues

1. **Nothing has run.**
2. **`OfferRanker` is still unused.** The separation of organic and sponsored
   results — 30 assertions, the strongest commercial guarantee in the system —
   has no surface. It needs the public offers page, which does not exist.
3. **No public offers page.** Offers can be created, moderated and published,
   and nobody outside the admin can see one.
4. **No media.** `offer_media` has a schema with moderation status; there is no
   upload.
5. **No expiry job.** `expires_at` is stored and nothing moves a lapsed offer to
   Expired, so an expired listing stays published indefinitely.
6. **No company portal**, so a company still cannot submit an offer itself — an
   administrator does it on their behalf.

Item 2 is the one worth doing next: the ranker is the most consequential
untested-in-place thing left in the system.

## Rollback

Additive; no migration.

```bash
rm -rf resources/js/Pages/Admin/Offers
rm -f  app/Modules/Marketplace/Http/Controllers/Admin/OfferController.php        app/Modules/Marketplace/Http/Requests/OfferRequest.php        app/Modules/Marketplace/Routes/admin.php tests/Feature/OfferModerationTest.php
git checkout -- app/Modules/Core/Support/AdminNavigation.php                 app/Http/Middleware/HandleInertiaRequests.php lang/*/marketplace.php
npm run build
```
