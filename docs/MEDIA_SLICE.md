# Project media

The last of the project profile's four declared-unavailable sections.

## The upload path is a security surface first

Three checks, and the order is the point:

1. **MIME must be in the allowlist.**
2. **The extension must not be in the blocklist, whatever the MIME says.** A
   browser reports whatever the client claims, so a `.phtml` announcing itself
   as `image/jpeg` clears check 1 and fails here.
3. **The bytes must parse as an image.** `getimagesize()` reads the header, so a
   PHP script renamed to `.jpg` with a forged MIME fails even after clearing
   both lists.

Check 3 is the one that matters on shared hosting, where the uploads directory
is frequently inside the document root and a stored script is remote execution
rather than a broken thumbnail. There is a test that uploads
`<?php echo "owned";` as `shell.jpg` and asserts it is refused.

## Other decisions

**Duplicates are refused by checksum.** The same render uploaded twice is one
render, and an editor should not end up with six copies of the same image.

**The first image becomes the cover automatically.** A gallery with no cover
renders no card image anywhere, and nobody notices until a listing page looks
broken.

**Deleting the cover promotes the next image** rather than leaving the project
coverless.

**Exactly one cover, enforced in a transaction.** Two would make the card image
depend on row order, which is not a decision anyone made.

**Missing alt text is surfaced, counted and flagged.** It is the most-skipped
field in any upload form, and an image without it is invisible to a screen
reader and to a search engine. The gallery uses `alt=""` rather than a filename
when none exists — a screen reader announcing "IMG_20260714.jpg" interrupts
without informing.

## Known limitation stated plainly

**No resizing or thumbnails.** That needs Intervention Image, a Composer
dependency this build has never been able to install. Originals are stored at
full size, which is correct and wasteful: a 6 MB phone photo is served whole to
a phone on an Erbil mobile connection. `loading="lazy"` and explicit
width/height attributes mitigate layout shift, not bandwidth.

This should be the first thing added once dependencies install.

## Verification

| Check | Result |
| --- | --- |
| `php -l`, whole tree | **PASS** 320/320 |
| `vue-tsc --noEmit` | **PASS** — 0 errors, first pass |
| `vite build` | **PASS** — 54 entries |
| Standalone logic suite | **PASS** — 1090 assertions, 0 failures |
| Translation parity | **PASS** — 907 keys, ckb/ar/en |
| Secret scan / migration guard | **PASS** |

183 feature tests exist; 10 are new. **None has run** — and these are among
the ones I would most want executed, because `Storage::fake` and `UploadedFile::fake`
behaviour is exactly the kind of thing that works differently than expected.

## The project profile is now complete

All four sections it declared unavailable are built: nearby places, price
history, ratings, media. It renders provenance, a gallery, a rating panel with
separated provenance types, a price history with separated series, and nearby
places with straight-line distances labelled as such.

## Remaining issues

1. **Nothing has run.**
2. **No resizing** — see above.
3. **No offer media**, though `offer_media` has a schema with a moderation
   status. Property listings without photographs are close to useless
   commercially, so this is the more urgent of the two.
4. **No reordering.** `sort_order` is stored and set on upload; there is no
   drag-to-reorder.
5. **No video**, though `kind` allows it.
6. **Project media has no moderation status**, unlike offer media. Staff upload
   it, so the risk is lower — but the asymmetry is unexamined rather than
   decided.

## Rollback

Additive; no migration.

```bash
rm -f resources/js/Components/MediaGallery.vue       resources/js/Pages/Admin/Projects/Media.vue       app/Modules/Core/Support/MediaUploader.php       app/Modules/Projects/Http/Controllers/Admin/ProjectMediaController.php       tests/Feature/ProjectMediaTest.php lang/*/media.php
git checkout -- app/Modules/Projects/Routes/admin.php                 app/Modules/Projects/Http/Controllers/Public/ProjectProfileController.php                 app/Modules/Core/Providers/CoreServiceProvider.php                 app/Http/Middleware/HandleInertiaRequests.php                 resources/js/Pages/Public/Projects/Show.vue
npm run build
```
