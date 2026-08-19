# Locale-prefixed routing — defect fix

Closes the defect flagged at the end of the public profile slice: hreflang
alternates advertised URLs that were never routed.

## The defect

The public profile emitted alternates at `/ar/projects/…` and `/en/projects/…`
while only `/projects/…` existed. Every alternate pointed at a 404.

That is worse than emitting none. A search engine told to index three URLs and
served one page and two errors will distrust all three, so the page ends up
ranking worse than if it had never claimed to be multilingual — on a product
whose entire premise is being the Sorani-first source for Erbil.

The underlying cause is more interesting than the symptom.
`config/localization.php` has declared `'url_strategy' => 'prefix_except_default'`
since Step 1. Nothing ever honoured it, and the SEO payload was written to match
the *config* while the router was written to ignore it. Two implementations of
one rule, drifting apart with nothing to catch it.

## The fix

`LocalizedRoutes` now owns the strategy, and both the router and the SEO payload
derive from it. They cannot disagree because there is only one of them.

```
/projects/ankawa-sky        ckb   (default, unprefixed)
/ar/projects/ankawa-sky     ar
/en/projects/ankawa-sky     en
x-default                 → the unprefixed Sorani URL
```

**Sorani takes the bare URL.** Relegating the product's own language to a prefix
while English sits at the root is exactly the afterthought-Sorani arrangement
spec 7.1 exists to prevent.

**`x-default` points at Sorani, not English.** The convention is to aim it at a
language selector or the primary audience's language; for this product those are
the same and it is not English.

## The subtler problem it exposed

Fixing the routes surfaced a second issue that had been latent. `SetLocale`
resolves `url > user > session > browser > default`, and `/projects/x` has no
locale segment — so a visitor whose session was Arabic would have been served
**Arabic content at the Sorani canonical URL**. Two languages under one address,
which makes the canonical tag a false statement and splits indexing.

Each localized route now declares its own locale as a route default, and
`SetLocale` reads that before anything else. On a public page **the URL is
authoritative**; the session still governs everywhere else.

`/ckb/projects/x` redirects **301** to `/projects/x` rather than 404ing — the
URL is a reasonable guess, a shared link may already carry it, and a permanent
redirect consolidates accumulated authority onto the canonical form instead of
discarding it.

## Verification

| Check | Result |
| --- | --- |
| `php -l`, whole tree | **PASS** 258/258 |
| `vue-tsc --noEmit` | **PASS** — 0 errors |
| `vite build` | **PASS** — 36 entries |
| Standalone logic suite | **PASS** — 1,028 assertions |
| Translation parity | **PASS** — 663 keys |
| Alternate generation | **Executed** — see below |

Executed directly rather than only asserted:

```
ckb        https://mulkihawler.krd/projects/ankawa-sky
ar         https://mulkihawler.krd/ar/projects/ankawa-sky
en         https://mulkihawler.krd/en/projects/ankawa-sky
x-default  https://mulkihawler.krd/projects/ankawa-sky
canonical  https://mulkihawler.krd/projects/ankawa-sky
```

70 feature tests now exist. The one that matters here iterates every advertised
alternate and asserts it resolves — a direct guard against this defect
recurring. **It has not run.**

## Remaining issues

1. **The locale switcher still uses a session POST.** On a public page it should
   navigate to the sibling URL instead, or switching language leaves the visitor
   on a URL whose prefix contradicts their choice. The route now wins, so the
   switch silently does nothing on public pages. **This is a new defect
   introduced by this fix** and is the first thing to address.
2. **Only module `web.php` routes are localized.** `routes/web.php` — home and
   offline — is still registered once, unprefixed.
3. **No sitemap.** hreflang in markup is half of it; a sitemap with `xhtml:link`
   alternates is the other half.
4. **Nothing has rendered or been requested.**

## Rollback

```bash
rm -f app/Modules/Core/Support/LocalizedRoutes.php tests/Feature/LocalizedRoutingTest.php
git checkout -- app/Modules/Core/Support/ModuleServiceProvider.php \
                app/Http/Middleware/SetLocale.php routes/web.php \
                app/Modules/Projects/Http/Controllers/Public/ProjectProfileController.php
npm run build
```

Reverting restores the broken alternates. Prefer removing the `alternates` key
from the SEO payload over reverting this wholesale.
