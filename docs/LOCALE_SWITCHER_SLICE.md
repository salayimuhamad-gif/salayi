# Locale switcher fix

Closes the defect introduced by the locale-prefixed routing slice.

## The defect

Locale-prefixed routing made the URL authoritative on public pages: each route
declares its own locale and `SetLocale` reads that before the session. Correct
for SEO — but the switcher still POSTed to set a session value, which on those
routes is now ignored entirely.

The result: a visitor on `/ar/projects/ankawa-sky` tapping **کوردی** got no URL
change and no content change. The control appeared to work and did nothing.

That is a worse failure than a visible error. A button that silently does
nothing reads as a broken site, and on the language control specifically it
reads as *this product does not really support my language* — on a product
whose whole premise is Sorani-first.

## The fix

Two mechanisms, because there are two kinds of page.

**Localized public routes** — navigate to the sibling URL. `/ar/projects/x` →
`/projects/x` for Sorani, `/en/projects/x` for English.

**Everywhere else** — the admin is not locale-routed, so the session remains
the only signal and the POST stays correct.

Which one applies is decided by `locale.route_is_localized` in the shared
payload, set from whether the matched route declared a locale. The browser does
not guess.

## Applying the lesson from the last defect

The hreflang defect came from two implementations of one rule drifting apart.
The switcher needs prefix logic in the browser, so this creates the same risk.

Mitigated the same way as the WKT writer in the map picker:

- The TypeScript side **consumes the strategy the server sends** rather than
  assuming it. If `prefix_except_default` becomes `prefix_all`, both follow with
  no code change.
- The pair was **checked against each other, executed**:

```
/projects/ankawa-sky      -> ar   = /ar/projects/ankawa-sky     (both)
/ar/projects/ankawa-sky   -> en   = /en/projects/ankawa-sky     (both)
/en/projects/ankawa-sky   -> ckb  = /projects/ankawa-sky        (both)
/                         -> ar   = /ar                         (both)
/en/                      -> ckb  = /                           (both)
```

Identical on all eight shared cases. The TypeScript side additionally preserves
query strings and fragments (`/projects?q=sky` → `/ar/projects?q=sky`,
`/ar/projects/x#gallery` → `/en/projects/x#gallery`), which the server never
needs to handle.

Switching twice does not accumulate prefixes: the current prefix is stripped
before the new one is applied.

## Verification

| Check | Result |
| --- | --- |
| `php -l`, whole tree | **PASS** 258/258 |
| `vue-tsc --noEmit` | **PASS** — 0 errors, first pass |
| `vite build` | **PASS** |
| Standalone logic suite | **PASS** — 1,028 assertions |
| Translation parity | **PASS** — 663 keys |
| TS/PHP sibling-URL agreement | **Executed** — identical on 8 cases |

72 feature tests exist. Two new ones guard the switcher's contract with the
browser: if those three shared fields stop being sent, the switcher silently
reverts to the broken behaviour. **None has run.**

## Remaining issues

1. **`routes/web.php` is still unprefixed.** Home and offline are registered
   once, so `route_is_localized` is false there and the switcher uses the
   session — correct today, but those pages should become localized too, at
   which point they get the navigation path automatically.
2. **No sitemap** with `xhtml:link` alternates.
3. **Nothing has rendered.** No switch has been clicked.

## Rollback

```bash
rm -f resources/js/lib/localeUrl.ts
git checkout -- resources/js/Composables/useLocale.ts \
                resources/js/Types/inertia.d.ts \
                app/Http/Middleware/HandleInertiaRequests.php \
                tests/Feature/LocalizedRoutingTest.php
npm run build
```

Reverting restores a switcher that does nothing on public pages.
