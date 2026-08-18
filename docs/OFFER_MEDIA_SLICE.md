# Offer media

Property listings without photographs are close to useless commercially. This
adds them — with the moderation that project media does not need.

## Why offer media is moderated and project media is not

Project images are uploaded by staff about projects the platform tracks. Offer
images are uploaded by **companies about property they are selling**, and an
unreviewed one is a photograph of a different building, a competitor's render,
or something that should not be on the site at all — appearing beside a price
with the platform's name above it.

So `offer_media.moderation_status` gates public display, and the query filters
on it server-side rather than the template hiding anything.

## Consequences of that, worked through

**An upload is not auto-cover.** Project media promotes the first image
immediately; here that would be the exact path by which an unreviewed
photograph reaches a buyer. Uploads land `pending` and coverless.

**The first approval becomes the cover.** Approving and then separately choosing
a cover would leave listings imageless whenever the second step is forgotten —
and a listing with approved images showing no picture is a worse failure than
one with no images at all, because nobody looks for it.

**A rejected image cannot remain the cover**, and rejecting one promotes the
next approved image.

**Moderation is its own permission.** A company user uploading to its own offer
cannot approve it — asserted.

**A cross-offer queue exists**, because per-offer review means an image on a
quiet listing waits unseen indefinitely.

## Verification

| Check | Result |
| --- | --- |
| `php -l`, whole tree | **PASS** 322/322 |
| `vue-tsc --noEmit` | **PASS** — 0 errors |
| `vite build` | **PASS** — 57 entries |
| Standalone logic suite | **PASS** — 1090 assertions, 0 failures |
| Translation parity | **PASS** — 918 keys, ckb/ar/en |
| Secret scan / migration guard | **PASS** |

193 feature tests exist; 11 are new. The first three assert the central
guarantee directly: pending and rejected media are **absent from the public
payload**, not merely hidden by a template. **None has run.**

## Remaining issues

1. **Nothing has run.**
2. **Still no resizing.** Same Composer constraint as project media, and it
   matters more here: a company uploading eight phone photographs of a flat
   produces ~48 MB served whole to buyers on mobile connections.
3. **No reordering**, no video, no floor plans as a distinct kind.
4. **No bulk approve** in the queue. Reviewing forty images one at a time is
   how a moderation step becomes a rubber stamp.
5. **No notification to the company** when an image is rejected, so a seller
   sees an image vanish with no explanation.
6. **Offer media has no alt-text requirement at upload**, only a warning. For a
   company-facing form that is probably too lenient.

## Rollback

Additive; no migration.

```bash
rm -f resources/js/Pages/Admin/Offers/Media.vue       resources/js/Pages/Admin/Offers/MediaQueue.vue       app/Modules/Marketplace/Http/Controllers/Admin/OfferMediaController.php       tests/Feature/OfferMediaTest.php
git checkout -- app/Modules/Marketplace/Routes/admin.php                 app/Modules/Marketplace/Http/Controllers/Public/OfferBrowseController.php                 app/Modules/Core/Support/AdminNavigation.php                 resources/js/Components/OfferCard.vue                 resources/js/Pages/Public/Offers/Show.vue                 lang/*/media.php lang/*/nav.php
npm run build
```
