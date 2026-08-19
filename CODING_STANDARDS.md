# Coding standards

Enforced by `vendor/bin/pint --test`, `vendor/bin/phpstan analyse` (level 6)
and `npm run lint` in CI. What follows is the reasoning behind the settings
that are not self-explanatory.

## PHP

- `declare(strict_types=1)` in every file. Pint adds it; there is no opt-out.
  A silent `"12abc"` to `12` coercion in a price import is a data-quality
  incident, not a convenience.
- `strict_comparison` and `strict_param`. `in_array($needle, $haystack, true)`
  everywhere — the two-argument form matches `0 == 'projects.view'`.
- Classes are `final` unless a subclass exists today. Inheritance is opened
  deliberately, not left open by default.
- Constructor property promotion with `private readonly` for dependencies.
- Return types on everything, including `void`.

## Money and numbers

- Any value that could appear next to a currency symbol, an area unit or a
  market index uses `Decimal`. Never `float`, never `round()`.
- `Decimal::toFloat()` exists for charting and sorting only. It is named to be
  visible in review; if you see it near a stored value, that is a bug.
- Imported figures go through `Decimal::tryParse()`, which returns `null` on
  bad input so the row becomes a reviewable error rather than a silent zero.

## Sorani and Arabic text

- Store `SoraniText::normalize()`. Index `SoraniText::searchKey()`. Never store
  the search key over the source — the two exist precisely because folding
  ه/ە is right for matching and wrong for storage.
- Never `substr()` a user-facing string; use `mb_*` or `Str::safeLimit()`.
  Byte-slicing Arabic script produces mojibake at the cut point.
- Numerals inside RTL text need the `.numeral` class or `data-numeral`, which
  applies `unicode-bidi: isolate`. Without it the bidi algorithm can reorder
  `240,000` on screen.

## Modules

- A module may read another module through a **contract** resolved from the
  container, never by importing its Eloquent models. `SettingsRepository` and
  `FeatureFlagRepository` are declared in `Core` and implemented in `Branding`
  for exactly this reason.
- Migrations, routes and translations live inside the module that owns them.
- New polymorphic types are **appended** to the morph map in
  `CoreServiceProvider`. Never renamed once shipped — the stored string is data.

## Frontend

- Logical CSS properties (`ms-`, `me-`, `ps-`, `pe-`, `start-`, `end-`).
  `postcss-rtlcss` is the safety net for what slips through, not the mechanism.
- No `localStorage` for anything that belongs on the server.
- Feature-flagged navigation is **filtered out of the array**, not hidden with
  CSS. An OFF flag means the surface is absent from the DOM.

## Comments

Comment the *why*, never the *what*. A comment that restates the line below it
is noise; a comment explaining why the session check sits above the browser
check in `SetLocale` saves the next reader twenty minutes.
