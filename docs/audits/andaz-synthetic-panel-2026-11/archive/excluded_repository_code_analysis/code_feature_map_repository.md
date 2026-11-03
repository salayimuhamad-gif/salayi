# خريطة الميزات العامة المستخرجة من شيفرة المستودع (Mulkihawler 4.0 / MyHawler)

> **فئة الدليل: A-code (مُفحوص في شيفرة المستودع المحلي، وليس مُلاحَظًا على الموقع الحي).**
> لم يُتحقق بعد من أن https://andaz.app/ يشغّل هذه الشيفرة. لا يُستخدم أي بند هنا كاستنتاج عن الموقع الحي قبل مطابقته بالملاحظة المباشرة.
> أُنتج هذا الملف بواسطة وكيل استكشاف شيفرة فرعي (code-explorer) في 2026-11-03 بتكليف من الفريق الرئيسي، ورُوجع يدويًا لاحقًا. المسارات نسبية إلى جذر المستودع.

---

# Public feature and journey map: Mulkihawler 4.0 (/home/user/salayi)

**Write limitation.** I had no file-write tool in this session (only Read, Grep, Glob and web tools). I could not create `/tmp/claude-0/-home-user-salayi/ea09f26f-39f4-56c9-a451-7e3804c756c7/scratchpad/code_feature_map.md`. The full deliverable is below, in the sections you specified. No files were modified and no servers were started. Paths are relative to `/home/user/salayi`. "Not determined" means I did not find it.

---

## 0. Headline: the default deployment is nearly empty

- Every public-surface flag defaults OFF in `config/features.php:15-94`. The only ON default is `projects.wizard` (:44), which is admin-only.
- `database/seeders/FeatureFlagSeeder.php:25-30` seeds each flag from those defaults using `firstOrCreate`.
- A visitor to a freshly seeded site can reach only these pages:
  - Home (`/`), `/projects`, `/projects/{slug}`, `/offline`, `/unsubscribe/{token}`.
  - `/login`, `/register`, `/forgot-password`.
  - Account pages after sign-up.
- Navigation then shows one item, "Projects". The mobile bottom bar shows Home, Projects and Account.
- Everything else returns a plain 404 while its flag is off. `app/Modules/Core/Http/Middleware/EnsureFeatureEnabled.php:128-138` throws `NotFoundHttpException` for public surfaces, so a disabled feature does not reveal that it exists.
- The live flag values live in the `feature_flags` table, so the audited site may differ from these defaults. Flag state in production is **not determined** from code.

---

## 1. Product purpose as stated in code and copy

**Home hero** (`lang/*/home.php:6-7`, rendered in `resources/js/Components/Public/AiAdvisorHero.vue:27,47,58`)

| Key | ckb | ar | en |
|---|---|---|---|
| `home.hero_title` (the only h1) | زانیاریی بازاڕی خانووبەرەی هەولێر | ذكاء سوق العقارات في أربيل | Erbil property market intelligence |
| `home.hero_sub` | داتای پشتڕاستکراو، بە بەڵگەوە | بيانات موثّقة بالأدلة | Verified data, with its evidence |
| `home.eyebrow` (:50) | زانیاریی خانووبەرەی هەولێر | معلومات عقارات أربيل | Erbil property intelligence |

- The footer reuses `home.hero_sub` as the site tagline (`resources/js/Layouts/PublicLayout.vue:235`).
- `home.advisor_lede` (the "truthful" advisor line):
  - ckb: ڕاوێژکارەکە تەنها لەسەر بنەمای داتای بڵاوکراوەی پلاتفۆرمەکە وەڵام دەداتەوە
  - ar: يجيب المستشار استناداً إلى بيانات المنصة المنشورة فقط
  - en: The advisor answers only from data published on this platform
- `home.insight_title`, shown in the AI strip (`Home.vue:338`):
  - ckb: ڕاوێژکاری زیرەکی بازاڕ
  - ar: مستشار السوق الذكي
  - en: AI market advisor
- `home.insight_body`:
  - ckb: وەڵامی پاڵپشتیکراو لە داتای پشتڕاستکراوی بازاڕی هەولێرەوە — بەڵگەکانیش دەردەکەون.
  - ar: إجابات مستندة إلى بيانات موثّقة لسوق أربيل — مع عرض الأدلة.
  - en: Grounded answers from verified Erbil market data — with the evidence shown.
- `market.public.intro` (`lang/*/market.php:18`):
  - ckb: ئەم ژمارانە لە فرۆشتنی پشتڕاستکراوەوە دەرهێنراون. هەر ژمارەیەک نموونە و ئاستی متمانەی خۆی لەگەڵدایە.
  - ar: هذه الأرقام مشتقة من صفقات موثقة. كل رقم يحمل حجم عينته ومستوى ثقته.
  - en: These figures are derived from verified transactions. Each carries its sample size and confidence.
- Brand and tagline strings, each with a different name:
  - `lang/ckb/app.php:12-13`: name مولکی هەولێر, tagline زانیاریی بازاڕی خانووبەرە لە هەولێر.
  - `lang/en/app.php:6-7`: name Mulkihawler, tagline Erbil real estate market intelligence.
  - `database/seeders/SiteSettingSeeder.php:23-24`: `branding.site_name` = "Mulkihawler", `branding.tagline_ckb` = زانیاریی بازاڕی خانووبەرە لە هەولێر.
  - `SiteSettingSeeder.php:43`: `seo.default_title_ckb` = مولکی هەولێر.
  - `composer.json` and `README.md:1`: "Mulkihawler 4.0 — Sorani-first real estate intelligence platform for Erbil".
- Meta description: `seo.default_description_ckb` is seeded as an empty string (`SiteSettingSeeder.php:44`) and I found no code that reads it.
- The Blade `<title inertia>` falls back to `settings('branding.site_name')` only (`resources/views/app.blade.php:34`). The generic `<head>` has no `<meta name="description">`.
- Only the project, offer and market pages emit `seo.title`, canonical and hreflang. Only the project page also emits a description and JSON-LD (`resources/js/Pages/Public/Projects/Show.vue:182-198`).
- The tagline settings (`branding.tagline_*`) are never rendered on a public page. I found them only in the admin Branding screen.
- Positioning is evidence-first market intelligence for Erbil, not a classifieds listing site. `app/Modules/Core/Http/Controllers/HomeController.php:18-31` states "Do not display invented or demo market data".

---

## 2. Public URL inventory

`/`, `/ar`, `/en` are the three locale forms. `LocalizedRoutes::group()` registers each module `web.php` once per enabled locale. ckb is unprefixed and `/ckb/...` returns a 301 to the unprefixed path (`routes/web.php:26-31`).

**Not localized** (single registration, bare paths only):
- `/login`, `/register`, `/forgot-password`, `/reset-password/{token}`, `/recover/{token}`, `/login/telegram` (`routes/auth.php`).
- `/locale/{locale}` (`routes/web.php:69`).
- `/manifest.webmanifest` (`Branding/Routes/web.php:18`).
- `/install/*` (`Install/Routes/web.php`).
- `/webhooks/telegram/updates`.
- `/api/v1/health` (`routes/api.php:13-19`). This is the only API route, with no public domain API.
- `/__testing__/*` exist only in the testing and local environments.

| Path | Page component | Flag (default) | Auth | Shows |
|---|---|---|---|---|
| `/` | `Public/Home` (`routes/web.php:57`) | none | guest | Hero, project search, flag-gated chips, KPI strip of up to 4 published indices, market pulse, project map teaser, area list, location card, 6 newest projects, AI strip, methodology and confidence legend |
| `/offline` | `Public/Offline` (`routes/web.php:60`) | none | guest | Inlined 3-locale offline message |
| `/projects` | `Public/Projects/Index` (`Projects/Routes/web.php:13`) | none | guest | Text search only; 12 per page; newest first |
| `/projects/{slug}` | `Public/Projects/Show` (:14) | none | guest | Full profile (journey b) |
| `/market` | `Public/Market/Index` | `market.intelligence` (OFF) | guest | Index cards plus market pulse |
| `/market/movement`, `/map/market` | JSON | `market.intelligence` (OFF) | guest | Movement data, throttled |
| `/map`, `/map/features`, `/map/search`, `/map/compare` | `Public/Map/Explorer` | `map.explorer` (OFF) | guest | Layered explorer with area compare |
| `/invest`, `/invest/features`, `/invest/search` | `Public/Map/Invest` | `map.investment` (OFF) | guest | Investment-project map |
| `/location/resolve` | JSON | none (always on) | guest | Area or price lookup for the home card and map area card; `no-store` |
| `/areas`, `/areas/{slug}` | `Public/Areas/Index`, `Show` | `geography.areas` (OFF) | guest | Area directory and profile |
| `/places`, `/places/{slug}` | `Public/Places/Index`, `Show` | `places.database` (OFF) | guest | Place directory and profile |
| `/companies`, `/companies/{slug}` | `Public/Companies/Index`, `Show` | `companies.portal` (OFF) | guest | Company directory and profile |
| `/offers`, `/offers/{publicId}` | `Public/Offers/Index`, `Show` | `marketplace.offers` (OFF) | guest | Offer browse and detail |
| `/advisor` (+ POST `reply`, `amend`, `recommend`, `reset`, `request`) | `Public/Advisor/Chat` | `advisor.residential` (OFF) | auth + `telegram.linked` | Interview, recommendations, send to team |
| `/lifestyle` (GET and POST) | `Public/Lifestyle/Edit` | `lifestyle.matching` (OFF) | auth + `telegram.linked` | Profile form |
| `/lifestyle/results` | `Public/Lifestyle/Results` | `lifestyle.matching` (OFF) | guest at the route level | Ranked projects; a guest sees "no profile" |
| `/unsubscribe/{token}` (GET, POST, `/undo`) | `Public/Unsubscribe` | none | guest | Stop a notification kind |
| `/login` | `Auth/Login` | none | guest-only | Phone-or-email plus password |
| `/register` (+ `poll`, `cancel`) | `Auth/Register` | none | guest-only | Registration form |
| `/forgot-password`, `/reset-password/{token}`, `/recover/{token}` | `Auth/*` | none | guest-only | Email reset and Telegram password recovery |
| `/login/telegram` (+ `poll`) | `Auth/TelegramLogin` | none | guest-only | Legacy Telegram sign-in |
| `/account/verify`, `/account/verify/whatsapp`, `/account/telegram/link` | `Account/VerifyChoice`, `VerifyWhatsApp`, `TelegramLink` | none | auth + `account.active` only | Verification doors |
| `/account/onboarding`, `/account/profile`, `/account/privacy` | `Account/*` | none | auth + `telegram.linked` | Self-service account pages |
| `/account/requests` | `Account/Requests` | none | auth + `telegram.linked` | The person's submitted requests |
| `/account/portfolio` (+ `/{id}`, `export`, media) | `Account/Portfolio/*` | `portfolio` (OFF) | auth + `telegram.linked` | Owned-property tracker with valuation |
| `/account/return/{token}`, `/account/return-expired`, `/account/return-to-tab` | Telegram handoff pages | none | guest-reachable, throttled 10 per minute | Neutral handoff pages |
| `/locale/{locale}` (POST) | none | none | any | Sets session locale, and `users.preferred_locale` if signed in |

Route sources: `Identity/Routes/web.php:24-47` (gated group), `:55-109` (verify and link, no `telegram.linked`), `:119-140` (handoff); `Advisor/Routes/web.php:27-34` and `:53-68`; `Portfolio/Routes/web.php:19`; `Geography/Routes/web.php:23-28`, `:38-63`, `:72-80`, `:94-96`, `:105-110`.

Notes:
- `/account/portfolio` is "My properties", a personal tracker for owned properties with a market-evidence valuation. It is not a saved or favourite projects list. I found no favourites, wishlist, bookmarks or saved searches in `app`, `resources/js` or `routes`.
- `/news` has no public route. The nav item is gated behind `content.news` (OFF) (`config/features.php:54`).
- Other flags default OFF: `advertising`, `public.reviews`, `partner.api`, `alerts.*`, `pwa`, `analytics.product`, `market.indices`, `companies.branches`, `marketplace.owner_listings`.
  - `requires_super_admin` covers `advertising`, `marketplace.owner_listings`, `partner.api`, `public.reviews`, `translations.ai_suggest`, `advisor.voice` (`config/features.php:101-108`).
  - A Super Admin can preview those on the public surface while they are OFF (`EnsureFeatureEnabled.php:85-97`).
  - `advisor.investment`, `advisor.market` and `advisor.voice` have no public route. I found only `advisor.residential` wired to routes.

### Gate middleware

- **`telegram.linked`** is an alias for `App\Modules\Identity\Http\Middleware\EnsureTelegramLinked` (`bootstrap/app.php:98-99`). The name is historical. It does not require Telegram specifically.
  - It passes any user for whom `User::hasVerifiedAccount()` is true: `telegram_verified_at` OR `whatsapp_verified_at` (`EnsureTelegramLinked.php:18-30,67`).
  - A guest is redirected to `localized_route('register.show')`, or gets JSON 401 `identity.errors.telegram_link_required` (:52-58).
  - A signed-in but unverified user is redirected to `localized_route('account.verify')`, or gets JSON 403 (:90-92). The attempt is audited.
  - It does not read `phone_verified` (:33-39).
- **`account.active`** (`EnsureAccountActive`) refuses suspended or inactive accounts. Messages: `identity.errors.account_suspended` and `account_inactive` (en lines 78-79). Logout is deliberately outside this gate (`routes/auth.php:46-55`).
- **`SetLocale`** resolves the locale in this order (`config/localization.php:76`): route default (URL), signed-in user's `preferred_locale`, session key `app.locale`, `Accept-Language` (with `ku` mapped to ckb), then ckb (`app/Http/Middleware/SetLocale.php:29-75`). It sets a `Content-Language` header.
- **`password_min_length`** defaults to 12 (`RegistrationController.php:72`).

---

## 3. Navigation structure

**Primary items.** One definition drives the top bar (home chrome only), the left rail, the mobile drawer and the footer (`PublicLayout.vue:90-99`). Order and flag gating:

| Order | Key | Route | Flag | ckb | ar | en |
|---|---|---|---|---|---|---|
| 1 | market | `/market` | `market.intelligence` | بازاڕ | السوق | Market |
| 2 | map | `/map` | `map.explorer` | نەخشە | الخريطة | Map |
| 3 | invest | `/invest` | `map.investment` | نەخشەی وەبەرهێنان | خريطة الاستثمار | Investment Map |
| 4 | projects | `/projects` | always | پڕۆژەکان | المشاريع | Projects |
| 5 | areas | `/areas` | `geography.areas` | ناوچەکان | المناطق | Areas |
| 6 | advisor (emphasised, spark icon) | `/advisor` | `advisor.residential` | ڕاوێژکار | المستشار | Advisor |
| 7 | offers | `/offers` | `marketplace.offers` | پێشکەشکراوەکان | العروض | Offers |
| 8 | news | `/news` | `content.news` | هەواڵ | الأخبار | News |

Labels come from `lang/*/nav.php:51-61`. Hrefs pass through `localized()`.

- **Absent from all navigation**: `/companies`, `/places`, `/lifestyle`. `PublicLayout.vue:83-89` records this as deliberate. They are reachable only through in-page links, such as the home "Lifestyle search" chip when `lifestyle.matching` is on, or by typing the URL.
- `/account/requests` is not in the account menu. It is linked only from the advisor "sent" message (`Chat.vue:309-312`).

**Top bar** (`PublicTopbar.vue`):
- A mobile hamburger labelled `nav.toggle_navigation`: ckb کردنەوە/داخستنی لیست, ar فتح/إغلاق القائمة, en Toggle navigation.
- The brand link, showing the `branding.site_name` text, which is "Mulkihawler" (Latin) by default. On phones in the home chrome the wordmark is `sr-only` and only a letter chip shows (:281-284).
- The inline primary nav, home chrome only. Overflow goes into a "more" menu, `nav.more`: زیاتر, المزيد, More.
- A theme toggle, `nav.toggle_theme`: گۆڕینی ڕووکار, تبديل المظهر, Toggle theme. It shows only if `branding.dark_mode_enabled` is true (seeded '1').
- The language menu, labelled `nav.language`: زمان, اللغة, Language. Options use native names: **کوردی**, **العربية**, **English** (`config/localization.php:32,47,59`).
- The account entry.
  - Guest: a "Sign in" link to `/login`, labelled `identity.auth.login`: چوونەژوورەوە, تسجيل الدخول (key not re-read in ar; ar `login_identifier` confirms the file), Sign in.
  - Signed in: a menu with Profile (`identity.profile.title`: پرۆفایلەکەت, ملفك الشخصي, Your profile), "My properties" (`portfolio.title`: موڵکەکانم, عقاراتي, My properties; only if `portfolio` is on), Advisor (only if `advisor.residential` is on), an Admin link if `is_admin`, and Sign out (`nav.sign_out`: دەرچوون, تسجيل الخروج, Sign out). Source: `Components/Public/AccountMenu.vue:61-132`.
- There is deliberately no search field or notifications bell in the top bar (`PublicTopbar.vue:13-27`).
- The left rail appears on non-home pages from lg up. The drawer uses the same items.

**Bottom nav** (<lg, `Components/Public/MobileBottomNav.vue:38-63`):

| Tab | Label (ckb / ar / en) | Target | Condition |
|---|---|---|---|
| Home | سەرەتا / الرئيسية / Home (`home.nav.home`) | `localized('/')` | always |
| Explore | پڕۆژەکان / المشاريع / Projects | `/projects` | always |
| Map | نەخشە / الخريطة / Map | `/map` | `map.explorer` |
| Advisor | ڕاوێژکار / المستشار / Advisor | `/advisor` | `advisor.residential` |
| Account | هەژمار / الحساب / Account (`home.nav.account`) | `/account/profile` if signed in, else `/login` | always |

- Bottom nav account and login targets are unlocalized, so a visitor on `/ar/...` lands on the bare `/login` or `/account/profile`. The profile route is registered per locale and the bare form is the ckb URL.
- The locale switcher is in the header only. There is none in the footer or bottom bar (`MobileBottomNav.vue:17-18`, `PublicLayout.vue:225-228`).

**Footer** (`PublicLayout.vue:230-264`):
- The site name, the `home.hero_sub` line, and the same flag-gated nav links in a grid.
- `map.distance.straight_line_notice`:
  - ckb: هەموو دوورییەکان بە هێڵی ڕاست پێوراون، نەک بە ڕێگا. دووری ڕاستەقینەی گەشت زیاترە.
  - ar: جميع المسافات مقيسة بخط مستقيم وليس عبر الطرق. مسافة السفر الفعلية أطول.
  - en: All distances are measured in a straight line, not along roads. Actual travel distance is longer.
- `{siteName} · v{version}`.
- **No** About, Contact, Terms, Privacy, Help, social links, phone, email or address in the footer.

---

## 4. Core journeys

### (a) Find a project: `/projects`

- The only filter is one text field.
  - Label (sr-only): `home.search_label`: گەڕان بۆ پڕۆژەکان / البحث في المشاريع / Search projects.
  - Placeholder: `home.search_placeholder`: بە ناو بگەڕێ بۆ پڕۆژەکان… / ابحث عن المشاريع بالاسم… / Search projects by name…
  - Submit is Enter only. The `/projects` form has no button. The home form has "گەڕان / بحث / Search".
- It sends GET `?q=`. The server matches `search_key LIKE %sorani_search_key(q)%` (`ProjectProfileController.php:41-43`).
- There is **no** filter by area, type, status, price or developer. There is **no** sort control. Order is `published_at DESC`, 12 per page (:44-45).
- Pagination: `props.projects.data` is read, but I found no pagination component in `Projects/Index.vue`. I found no paging links on this page, so page 2 appears reachable only by URL. This is not determined beyond reading the template.
- Card fields:
  - Area, name (h2), developer.
  - Construction-status pill. The label comes from `projects.construction_statuses.*`; stalled or cancelled shows in a caution colour.
  - Completion %.
  - **No price, image or unit count.**
- The page heading is `t('nav.projects')`: پڕۆژەکان, المشاريع, Projects.
- Empty states (`lang/*/projects.php:102-105`):
  - `none_published` ckb هیچ پڕۆژەیەکی بڵاوکراوە نییە / ar لا توجد مشاريع منشورة / en No published projects.
  - `none_published_hint` ckb بەم زووانە زیاد دەکرێن / ar ستُضاف قريباً / en They will be added soon.
  - `search_none` ckb هیچ پڕۆژەیەک لەگەڵ ئەم گەڕانە ناگونجێت / ar لا توجد مشاريع تطابق هذا البحث / en No projects match this search.
  - `search_none_hint` ckb ناوێکی تر تاقی بکەرەوە یان گەڕانەکە پاک بکەرەوە / ar جرّب اسماً آخر أو امسح البحث / en Try a different name or clear the search.
- Another search path is the Investment Map project search (`map.invest.search_*`), when `map.investment` is on.

### (b) Project profile: `/projects/{slug}` (`Public/Projects/Show.vue`)

Sections, in order:
1. **Header.**
   - Area label, h1 name.
   - `projects.translation_fallback` if the name is not in the current locale: ckb ئەم ناوە بە زمانی داواکراو بەردەست نییە / ar هذا الاسم غير متوفر باللغة المطلوبة / en This name is not available in the requested language.
   - **ProvenanceChip** (`Components/ProvenanceChip.vue`): "Source: x" or "No source recorded", a freshness phrase, a verified date, and a confidence level. Labels:
     - `app.meta.source`: سەرچاوە / المصدر / Source.
     - `app.meta.no_source`: سەرچاوە تۆمار نەکراوە / لم يُسجل مصدر / No source recorded.
     - `app.meta.never_verified`: هەرگیز پشتڕاست نەکراوەتەوە / لم يتم التحقق مطلقاً / Never verified.
     - Freshness (`projects.freshness.*`): recent, months, over six months, over a year. For example, en "Verified recently / Verified months ago / Verified over six months ago / Verified over a year ago".
     - `app.meta.confidence`: ئاستی متمانە / مستوى الثقة / Confidence level.
     - Confidence values (`market.confidence.*`): ناتەواو, نزم, مامناوەند, بەرز / غير كافٍ, منخفض, متوسط, عالٍ / Insufficient, Low, Moderate, High.
2. **Adverse warning** if stalled or delayed: `projects.adverse_notice`:
   - ckb ئەم پڕۆژەیە ڕاگیراوە یان دواخراوە. پێش هەر بڕیارێک پشتڕاستی بکەرەوە
   - ar هذا المشروع متوقف أو متأخر. تحقق قبل اتخاذ أي قرار
   - en This project is stalled or delayed. Verify before making any decision.
3. **Media gallery** (images with alt and credit), only if present.
4. **About** (`projects.about`: دەربارەی پڕۆژە / عن المشروع / About this project), the description.
5. **Status card** (`projects.status_section`: دۆخ / الحالة / Status):
   - Construction status (`projects.fields.construction_status`: دۆخی بیناسازی / حالة البناء / Construction status).
   - Delivery status (`delivery_status`: دۆخی تحویل / حالة التسليم / Delivery status).
   - Completion % (`completion_percent`: ڕێژەی تەواوبوون / نسبة الإنجاز / Completion percent), with "(implied)" (`projects.implied`: (خەمڵێنراو) / (مُستنتَج) / (implied)) when derived.
   - Dates: launch date and expected delivery.
   - Construction status values (en): Announced, Planning, Licensed, Site preparation, Foundation, Under construction, Structure complete, Finishing, Completed, Stalled, Cancelled. ckb equivalents are at `lang/ckb/projects.php:26-38`.
   - Delivery status values (en): Not started, Scheduled, Partially delivered, Delivered, Delayed, Indefinite.
6. **Scale card** (`projects.scale_section`: قەبارە / الحجم / Scale), if facts exist: land area (m²), buildings, units. These are counts only.
7. **Developer card** (`projects.fields.developer`: گەشەپێدەر / المطور / Developer): the developer name plus a "Verified" pill (`companies.verification.verified`: پشتڕاستکراو / تم التحقق (not re-read) / Verified). It is **not** a link to the company page.
8. **Knowledge timeline** (`knowledge.timeline_title`), published events only, up to 25, with source and confidence.
9. **Ratings**, if any: `projects.ratings.title` (هەڵسەنگاندنەکان / التقييمات / Ratings) plus `provenance_notice`:
   - ckb هەر سەرچاوەیەک بە جیا پیشان دەدرێت. جۆرەکان تێکەڵ ناکرێن
   - Rating sources shown separately include internal expert, verified resident survey, company submitted, public user, calculated market score and AI summary.
   - Ratings are read-only. I found no public rating submission route and no reviews UI. `public.reviews` is OFF and has no public UI.
10. **Price history** (`market.history.title`: مێژووی نرخ / تاريخ الأسعار / Price history), via `PriceHistory.vue`, with series by price type and asking-versus-verified separation.
    - `market.history.asking_only_notice`:
      - ckb هەموو ئەم ژمارانە نرخی داواکراون. هیچ فرۆشتنێکی پشتڕاستکراو بۆ ئەم پڕۆژەیە تۆمار نەکراوە
      - en Every figure here is an asking price. No verified transactions have been recorded for this project.
    - Price types: sale_asking, rent_asking, sale_verified, rent_verified, official_snapshot (`market.price_types.*`).
11. **Map** (card titled `nav.public.map`), shown only if lat and lng exist, via `ErbilMapPreview`.
12. **Nearby places** (`geography.nearby.title`), with a category chip filter and distance rounded to 10 m or to 0.1 km.
    - `geography.nearby.straight_line_notice`.
    - A "last updated" stamp shows the oldest calculation.
    - A stale marker (`geography.nearby.stale`) can appear.
13. **"Not yet available"** card (`projects.not_yet_available`: هێشتا بەردەست نییە / غير متوفر بعد / Not yet available), listing missing sections:
    - ckb: شوێنە نزیکەکان / مێژووی نرخ / هەڵسەنگاندنەکان / وێنە و ڤیدیۆ.
    - en: Nearby places / Price history / Ratings / Photos and video.
14. **Footer**: a link, "Official website" (`projects.fields.official_url`: ماڵپەڕی فەرمی / الموقع الرسمي / Official website), with `rel="noopener nofollow external"`.

**Absent on the project page** (confirmed by the controller props and template):
- Unit types, unit availability, prices per unit, payment plans or installments, down payment, handover payment terms.
- Any contact, inquiry, lead or call button.
- Any "save" or "share" control.
- Developer or company link.
- A "published at" stamp. `published_at` is in the props but not rendered.
- Any operator disclaimer on the page. The caution notes come from the component strings only.
- The only commercial figures anywhere on the page are the price-history series.
- There is no phone or WhatsApp link anywhere in the frontend. A search of `resources/js` for `tel:`, `mailto:`, `wa.me`, `t.me/` found no contact links.
- SEO: canonical, hreflang for all locales, and JSON-LD "asserts only what the page shows" (`Show.vue:194-197`).

### (c) Offers: `/offers` (flag `marketplace.offers`, OFF)

- **Filters in the UI**: a text field (`marketplace.public.search`: گەڕان بە ناو... / ابحث بالاسم... / Search by name…) and a "budget" text field (`marketplace.public.budget`: بەرزترین بودجە / أعلى ميزانية / Max budget). The input is debounced 300 ms and replaced into the history (`Offers/Index.vue:36-41`).
- The server also accepts `type` (property type) and `area` (`OfferBrowseController.php:39-58`), but the page has **no UI controls** for them. The `filters.type` prop is passed through but never rendered.
- **Sort**: none to choose. Offers are ranked by `OfferRanker`/`OfferScorer`, with at most 120 results considered. Weights are "unreviewed product judgements" (`docs/PUBLIC_OFFERS_SLICE.md:71-74`).
- Sections:
  - **Sponsored section**, when sponsored offers exist (`advertising` flag, OFF). Heading `marketplace.sponsorship.sponsored_section`:
    - ckb بەشی ڕیکلام / ar قسم الإعلانات / en Sponsored section.
    - Notice `disclosure_notice`:
      - ckb ئەم ئەنجامانە پارەیان بۆ دراوە و جیاکراونەتەوە لە ئەنجامە سروشتییەکان
      - ar هذه النتائج مدفوعة ومفصولة عن النتائج الطبيعية
      - en These results are paid for and are separated from organic results.
  - **Organic results**: heading `organic_results`: ئەنجامە سروشتییەکان / النتائج الطبيعية / Organic results, with a count. Footer line `not_ranked_by_payment`: ئەنجامە سروشتییەکان بە پارە ڕیزبەندی ناکرێن / النتائج الطبيعية لا تُرتب بالدفع / Organic results are not ranked by payment.
- **Card fields** (`Components/OfferCard.vue`):
  - Cover image, an optional disclosure pill, area, title, price (number and currency), property type (`market.property_types.*`), rooms ("N rooms", `marketplace.fields.rooms`: ژمارەی ژوور / عدد الغرف / Rooms), size in m², company name, "Verified" pill, and a location-precision label when not exact.
  - Precision values (`marketplace.precision.*`): exact وردی / دقيق / Exact; approximate نزیکەیی / تقريبي / Approximate; area_only تەنها ناوچە / المنطقة فقط / Area only.
  - **No posted/updated date** on the card, though `published_at` is in the data. `offer_type` (sale, rent, investment, pre_launch) is in the data but is not rendered on cards or the detail page.
- **Offer detail** (`Offers/Show.vue`):
  - A sponsored warning if sponsored.
  - Area label, h1, price in large type, an approved-images gallery.
  - "About" (reusing `projects.about`), a Details card (type, rooms, size, location precision).
  - A company card titled `nav.companies` with the company name and a "Verified" pill. It is plain text, not a link.
  - A link to the related project, and a Terms card (`marketplace.public.terms`: مەرجەکان / الشروط / Terms).
- **Who posted it**: the company's name (`company->name()`), so offers are posted by companies, with admin moderation, not by individual owners. `marketplace.owner_listings` is OFF and super-admin-gated.
- **Contact route**: **none rendered.** `contact_method` is sent in the props (`OfferBrowseController.php:119`, declared at `Show.vue:25`) but the template never uses it. There is no phone, WhatsApp or inquiry form. I found no public lead-creation route. `lang/*/companies.php` `public.contact_via` reads "Contact through the platform" (ckb پەیوەندی لە ڕێگەی پلاتفۆرمەوە / en Contact through the platform) on the company profile (`Companies/Show.vue:130`), but no platform channel exists in the public routes.
- **Language issue**: the offer title uses `title_ckb`, the description `description_ckb`, and the terms the raw `terms` value regardless of the visitor's locale (`OfferBrowseController.php:117,124,135`). Ar and en visitors see Sorani offer text.
- **Label reuse issues** on the detail page:
  - Offer size is labelled with `projects.fields.land_area`, "Land area (m²)" / ڕووبەری زەوی (م²) (`Show.vue:74`).
  - Offer property type is labelled with `projects.fields.type`, "Project type" / جۆری پڕۆژە (`Show.vue:66`).
- **Terminology inconsistency (ckb)**:
  - The nav label is **پێشکەشکراوەکان** (`nav.public.offers`).
  - The page title and admin use **پێشنیارەکان** (`marketplace.public.title`).
  - The map layer uses پێشکەشکراوەکان.
  - Home CTA `home.offers_cta` is بینینی پێشکەشکراوەکان.
- **Empty state**: `marketplace.public.none`: هیچ پێشنیارێک نەدۆزرایەوە / لا توجد عروض / No offers found; hint فلتەرەکان بگۆڕە / غيّر عوامل التصفية / Try changing the filters.
- Docs note: no expiry job. A lapsed offer is filtered from queries, but its status stays Published (`docs/PUBLIC_OFFERS_SLICE.md:66-68`). That doc also says there are no saved searches and no comparison for offers.

### (d) Map explorer `/map` and invest map `/invest`

**Explorer** (`map.explorer`, OFF). I read the script, template keys and lang; I did not exercise the page.
- **Layers** (`map.layers_title`: چینەکان / الطبقات / Layers): Projects, Areas, Places, Offers, Companies and branches, Market prices. Layers shown depend on their own flags. `map.states.no_layers`: هیچ چینێک بەردەست نییە / لا توجد طبقات متاحة / No layers are available.
- **Place category filters** (`map.categories_title`: جۆری شوێنەکان / فئات الأماكن / Place categories; `map.all_categories`; `map.clear_filters`: سڕینەوەی پاڵێوەرەکان / مسح عوامل التصفية / Clear filters).
- **Spatial search**:
  - Radius search (`map.search.radius`: گەڕان بە دووری / بحث بنصف قطر / Radius search), with centre pick and a distance in km. A "use my location" control (`map.use_my_location`: شوێنی من) is listed in the keys; geolocation denial copy is in `map.states.permission_denied(_hint)`.
  - "Search this view" (`map.search.bounds`: گەڕان لەم دیمەنەدا / بحث في هذا العرض / Search this view).
  - Draw an area (`map.search.draw`: کێشانی ناوچە / رسم منطقة / Draw an area), with at least 3 points.
- **Unified search box** (`map.discovery.label`: دۆزینەوە لە نەخشەدا / البحث في الخريطة / Find on the map; placeholder گەڕان بۆ ناوچە، پڕۆژە و شوێنەکان… / ابحث عن مناطق ومشاريع وأماكن… / Search areas, projects and places…). It searches the platform's own areas, projects and places (`/map/search`). It is not a geocoder.
- **Mode switch** (`map.market.mode_label`: دۆخی نەخشە / وضع الخريطة / Map mode): Explore (گەڕان / استكشاف), Market (بازاڕ / السوق), Compare (بەراورد / مقارنة). Market mode shows heat per area polygon, with a Legend (`map.market.legend`).
- **Compare** is area-only: 2 to 3 areas (`map.compare.full`: بەراوردکردن زۆرترین سێ ناوچە لەخۆدەگرێت / تتسع المقارنة لثلاث مناطق كحد أقصى / A comparison holds at most three areas.). Rows are Market movement, Current price evidence and Services. Facts are generated sentences, for example `map.compare.facts.price_higher`. There is **no project-to-project compare** on the map. `compare.need_two`: ckb بۆ بەراوردکردن لانیکەم دوو ناوچە هەڵبژێرە — گەڕانەکەی سەرەوە بەکاربهێنە / en Choose at least two areas to compare — use the search above.
- **Area card**: clicking an area polygon fetches `/location/resolve?area=` and shows area intelligence. "View the full area" (`map.area_card.view_full`: بینینی ناوچەکە بە تەواوی / عرض المنطقة كاملة / View the full area) links to `/areas/{slug}` only when `geography.areas` is on.
- **List fallback**: a list always renders beside the map (primary on narrow screens), toggled with `map.list_view` (لیست / قائمة / List) and `map.map_view` (نەخشە / خريطة / Map). Results count is `map.results` (ئەنجامەکان (:count) / النتائج (:count) / Results (:count)).
- **Provider**: MapLibre by default, with an optional Google Maps provider that falls back (`map.states.provider_fallback` strings).
- **Limits**: viewport fetches are throttled 60 per minute per IP (`map-features`), search 30 per minute (`map-search`), location-resolve 30 per minute. `map.states.rate_limited`: داواکاری زۆرە — کەمێک چاوەڕوان بە؛ ئەو داتایەی بارکراوە هەر ماوە. / en Too many requests — wait a moment; the data already loaded stays shown.
- **Distances** are straight-line only. `map.distance.travel_time_unavailable`: ckb کاتی گەشت بەردەست نییە — هیچ دابینکەرێکی ڕێگا ڕێکنەخراوە / en Travel time is unavailable — no routing provider is configured.

**Invest map** (`map.investment`, OFF; `Public/Map/Invest.vue`):
- Title `map.invest.title`: نەخشەی وەبەرهێنان / خريطة الاستثمار / Investment Map; intro `map.invest.intro`:
  - ckb پرۆژە پەسەندکراوەکانی وەبەرهێنان لە هەولێر، لەسەر نەخشە.
  - ar المشاريع الاستثمارية المعتمدة في أربيل، على الخريطة.
  - en Approved investment projects across Hawler, on the map.
- Controls: a project-name search (`map.invest.search_label` / `search_placeholder`: ناوی پڕۆژە… / اسم المشروع… / Project name…), a **project type filter** (`filters_label`: جۆری پڕۆژە / نوع المشروع / Project type, options from `projects.types.*`), a map/list toggle, and an "Area boundaries" layer toggle.
- Selecting a project shows a "Selected project" card with type, "From" price (`price_from_label`: لە / من / From), a price **trend label** (`map.invest.trend.*`: up, down, flat, unknown), and a "View project" link (`/projects/{slug}`).
- **No compare** on the invest map.
- Empty states: `map.invest.empty`: هیچ پڕۆژەیەکی وەبەرهێنان لەم دیمەنە نییە / لا توجد مشاريع استثمارية في هذا العرض / No investment projects in this view.
- Home teaser: `home.invest_map.title` ckb پرۆژەکانی وەبەرهێنان لە سەرانسەری هەولێر بدۆزەرەوە (spelled "پرۆژە" with ر, while the rest of the product uses "پڕۆژە").
- Home project-map teaser: `home.live_map.title` (ckb پڕۆژەکان لەسەر نەخشە، ڕاستەوخۆ / en Projects on the map, live) and `home.live_map.open_full`.

### (e) Market intelligence: `/market` (`market.intelligence`, OFF)

- H1 `market.public.title`: بازاڕی هەولێر / سوق أربيل / Erbil market, plus the intro (section 1).
- **Market pulse** panel (`market.movement.title`: جوڵەی بازاڕ / حركة السوق / Market pulse), loaded from `/market/movement` (throttled). Controls:
  - Transaction type: Sale or Rent (`market.movement.transaction.*`: فرۆشتن or کرێ / Sale or Rent).
  - Period: 7d, 30d, 1m, 3m, 6m, 1y, All (en 7D, 30D, 1M, 3M, 6M, 1Y, All).
  - Property categories: all categories, or a chosen category.
  - Output: "Top gainers" (زۆرترین بەرزبوونەوە) and "Top decliners" (زۆرترین دابەزین), for areas and projects.
  - Reasons shown when there is no data (`market.movement.reasons.*`), for example en "Not enough published history to measure movement yet".
- **Index cards**, verified first and asking last (`Market/Index.vue:57-62`). Each card shows:
  - The name.
  - The latest value in the index currency, the period, and a % change with a direction colour.
  - A sparkline of the series.
  - An `IndexExplanation` block with sample size, excluded outliers, sources, confidence, revision status and number, methodology version, effective date, and a limited-sample warning.
  - Labels (`market.explanation.*`): sample نموونە / العينة / Sample, confidence, methodology ڕێباز, excluded دەرکراو, effective, revision.
  - Qualifier notices (`market.public.qualifier.*`), for example `sale_asking`: ckb ئەمە نرخی داواکراوی فرۆشیارانە، نەک فرۆشتنی تەواوبوو / en This is what sellers are asking, not completed sales.
- Methodology banner `market.public.methodology_notice`:
  - ckb نرخە داواکراوەکان و فرۆشتنە پشتڕاستکراوەکان هەرگیز تێکەڵ ناکرێن. هەر پێوەرێک تەنها یەک جۆر نرخ بەکاردەهێنێت.
  - ar الأسعار المطلوبة والصفقات الموثقة لا تُخلط أبداً. كل مؤشر يستخدم نوعاً واحداً فقط.
  - en Asking prices and verified transactions are never mixed. Each index uses exactly one price type.
- "Not yet available" list (`market.public.pending.*`: heat_map, gainers_and_decliners, demand_movement, transaction_activity, market_digest). `docs/PUBLIC_MARKET_SLICE.md:74-86` says thirteen of the spec's eighteen dashboard elements are absent, there are no default indices, and there is no methodology page. Parts of that doc are stale because the pulse and map heat now exist.
- Empty: `market.public.none`: هێشتا هیچ پێوەرێک بڵاو نەکراوەتەوە / لم يُنشر أي مؤشر بعد / No indices published yet; hint کاتێک داتای پێویست کۆبووەوە دەردەکەون / ستظهر عند توفر بيانات كافية / They appear once enough data is gathered.
- Home KPI strip: up to 4 indices (`MarketMetricCard`). `home.no_market_data`:
  - ckb هێشتا هیچ نرخێکی پشتڕاستکراو بڵاو نەکراوەتەوە
  - ar لم تُنشر أي أسعار موثّقة بعد
  - en No verified prices have been published yet.
  - When the module is off, Home shows `home.feature_off` (ckb ئەم بەشە ناچالاککراوە / ar هذا القسم غير مفعّل / en This section is disabled).
- Home currency and number format: Latin digits everywhere (`config/localization.php:41,53,65`), and display currency USD (`SiteSettingSeeder.php:35`).

### (f) Areas and places

- **`/areas`** (`geography.areas`, OFF):
  - Title `geography.public.index_title`: ناوچەکانی هەولێر / مناطق أربيل (not re-read; ar file has the key) / Areas of Erbil.
  - Intro: ckb ناوچە بڵاوکراوەکان، بە پێی ئاستی خۆیان ڕێکخراون. تەنها ناوچەیەک دەردەکەوێت کە خۆی و هەموو باوانەکانی بڵاو کرابنەوە. / en Published areas, grouped by level. An area appears only when it and every area above it is published.
  - Area types: City, District, Nahiya, Sector, Zone, Neighbourhood, Mahalla (ckb شار, ناوچە, ناحیە, بەش, زۆن, گەڕەک, مەحەللە).
  - Capped list with a truncation notice (`geography.public.truncated`: تەنها یەکەم :count ناوچە پیشان دراون / en Showing the first :count areas only).
  - Empty: هێشتا هیچ ناوچەیەک بڵاو نەکراوەتەوە / No areas have been published yet.
- **`/areas/{slug}`** shows:
  - Name (with `name_fallback_notice`), a description or `no_description`, a lifestyle line.
  - Sub-areas, or "No published sub-areas".
  - "Projects in this area", with `no_projects` and the hint ckb ئەمە بەو مانایە نییە پڕۆژە نییە — تەنها هێشتا هیچیان بڵاو نەکراونەتەوە / en This does not mean there are none — only that none has been published yet. Also `subtree_count`.
  - "Services in this area" (`services_title`), counted from reviewed and published places only. Groups: Education, Health, Shopping, Transport, Parks and recreation, Worship, Civic services, Banks and finance, Food and hotels, Employment, Other.
  - "Notable places", the knowledge timeline, and a boundary and coordinates note (`no_coordinates`).
- **`/places`** (`places.database`, OFF): category chip filter (`all_categories`), place cards, and `unknown_category` for a bad category. `/places/{slug}` shows category, area (or `area_withheld`), address (or `no_address`), website, accessibility, opening hours (or `no_opening_hours`), **source**, and "Verified on :date" or "This information has not been verified" (`not_verified`). It also shows "Other places in the same area" (`nearby_in_area`). The 31 place categories include school, hospital, mosque, church, bank, and so on (`geography.place_categories.*`). Mosque and church are labelled neutrally.

### (g) Companies directory (`companies.portal`, OFF)

- `/companies`:
  - H1 `companies.public.directory`: کۆمپانیاکان / الشركات / Companies.
  - Search `companies.public.search`: گەڕان بۆ کۆمپانیا / Search companies.
  - Cards: name, "Verified" pill (`companies.public.verified`: پشتڕاستکراوە / Verified), counts for branches and projects.
  - Empty: `companies.public.none`: هیچ کۆمپانیایەک نەدۆزرایەوە / No companies found.
- `/companies/{slug}`:
  - Name, verified badge plus a verified date, description.
  - Licence number and issuing authority (`companies.public.license`: ژمارەی مۆڵەت / Licence number), website, specialties, languages.
  - Branches with address and languages.
  - `contact_via`: "Contact through the platform", which is text only, with no contact mechanism on the page.
  - Projects the company has, each with an optional disclosure pill (`advertising.disclosure.sponsored`).
- Verification semantics shown in admin copy: `companies.verification.hint`: ckb پشتڕاستکردنەوە واتای ئەوەیە بەڵگەمان بینیوە — نەک تەنها بڵاوکردنەوە / en Verification means we have seen evidence — not merely that it is published.
- Company types include independent brokerage and property-management partner (`companies.php:40-43`).
- `/companies` and `/places` are not in the nav (see section 3).

### (h) Register and login

**Register** (`/register`, guest-only, bare path; `Auth/Register.vue`, `RegistrationController.php`):
- Page title `identity.register.title`: هەژمارەکەت دروست بکە / أنشئ حسابك / Create your account. Subtitle `register.subtitle`:
  - ckb زانیارییەکانت بنووسە، پاشان بە تێلێگرام پشتڕاستی بکەرەوە — بێ هیچ کۆدێک.
  - ar أدخل بياناتك ثم أكّد عبر تيليجرام — بدون أي رمز.
  - en Enter your details, then confirm with Telegram — no code to type.
- Fields:

| Field | ckb | en | Mandatory |
|---|---|---|---|
| Name | ناوی تەواو | Full name | Yes (2-120 chars) |
| Phone | ژمارەی مۆبایل | Phone number | Yes, Iraqi mobile: `^(\+?964\|0)?7[0-9]{9}$` (`RegistrationController.php:100`) |
| Password | وشەی نهێنی | Password | Yes, min 12 chars default, confirmed (`PasswordRule::defaults()`) |
| Confirm password | دووبارەکردنەوەی وشەی نهێنی | Confirm password | Yes |
| Preferred language | زمانی دڵخواز | Preferred language | Yes (dropdown کوردی / العربية / English, preset from `<html lang>`) |
| Accept terms | see below | see below | Yes (`accept_terms` accepted) |
| Contact consent | see below | see below | No, optional, unchecked by default |

  - Phone hint: ckb ژمارەی عێراقی، بۆ نموونە: 0750 123 4567 / en Iraqi number, e.g. 0750 123 4567.
  - Terms checkbox (`register.accept_terms`): ckb ڕازیم بە مەرجەکانی خزمەتگوزاری و سیاسەتی تایبەتێتی / ar أوافق على شروط الخدمة وسياسة الخصوصية / en I accept the Terms of Service and Privacy Policy. **There is no Terms or Privacy page and no link behind that text.**
  - Contact consent (`consent_contact`): en MyHawler staff may contact me about property requests I submit; ar يمكن لفريق MyHawler التواصل معي بخصوص طلبات العقارات التي أرسلها.
- The submit button reads "Continue with Telegram" (ckb بەردەوامبوون بە تێلێگرام / ar المتابعة عبر تيليجرام). It is **disabled when the Telegram bot is not configured** (`Register.vue:335`: `:disabled="!bot_configured"`), with the warning `identity.telegram.unavailable`: ckb تۆمارکردن لە ڕێگەی تێلێگرامەوە هێشتا ڕێکنەخراوە. تکایە دواتر هەوڵ بدەرەوە یان پەیوەندیمان پێوە بکە. / en Registration by Telegram is not configured yet. Please try again later or contact us. So registration is blocked entirely without Telegram, even if WhatsApp verification is configured.
- **Account-first flow** (v7, `RegistrationController.php:24-38,173-206`): the account is created immediately, the person is signed in, and then redirected to `/account/verify` (in their chosen locale) with a flash "Your account was created successfully" (ckb هەژمارەکەت بە سەرکەوتوویی دروست کرا). The account is "powerless" until verified. The registration form copy still describes the older Telegram-first flow, and the `pending` state in `Register.vue:176-253` is legacy, null for new visitors.
- **Verification choice** (`/account/verify`):
  - Title `verify.title`: هەژمارەکەت پشتڕاست بکەرەوە / وثّق حسابك / Verify your account. Lead: One method is enough — both verify the same account.
  - Door 1, **Verify with Telegram**: open the bot and press START. The link never expires. A poll every 2.5 s notices the verification.
  - Door 2, **Verify with WhatsApp**: a 6-digit code sent via Bird, valid for 10 minutes, shown only when the server says WhatsApp is configured (`whatsapp_available`) and the account has a phone number.
    - Send throttle: 3 per hour per user and 10 per hour per IP.
    - Confirm throttle: 10 per minute.
    - `verify.whatsapp_unavailable`: ckb پشتڕاستکردنەوە بە واتسئاپ لە ئێستادا بەردەست نییە — دەتوانیت بە تێلێگرام پشتڕاستی بکەیتەوە.
    - Errors include `wrong_code`, `too_many_attempts` (en Too many wrong tries — that code no longer works. Request a new one.), `phone_changed` and `send_failed`.
  - Later: `verify.later`: en You can do this later. Signing in brings you back to this choice. Unverified accounts are pruned: `MULKIHAWLER_UNLINKED_RETENTION_HOURS=72` (`.env.example:76`).
  - Telegram bot messages brand the product **MyHawler**, for example `telegram.bot_verified_account`.
  - Honest-claim line on the pending screen (`register.pending_note`): ckb ئەم هەنگاوە هەژماری تێلێگرامەکەت پشتڕاست دەکاتەوە؛ بە سەربەخۆیی خاوەندارێتی ژمارە نووسراوەکە پشتڕاست ناکاتەوە. / en This confirms your Telegram account. It does not independently verify ownership of the typed phone number. (A WhatsApp code does verify the phone.)
- **Onboarding** (`/account/onboarding`, after verification):
  - Title: en Welcome to MyHawler; ckb بەخێربێیت بۆ MyHawler.
  - Fields: display name; purpose (buying a home, investment, a place to live, selling, tracking my properties); optional city, area, email, gender and date of birth.
  - Exit buttons: "Start with the AI Advisor" (`onboarding.go_advisor`), "Add my properties" (`go_portfolio`), "Just take me to the site" (`go_home`).
  - Status lines show whether Telegram is verified, whether the phone is "provided (not independently verified)" or "verified", and whether contact consent was granted.
- **Login** (`/login`, guest-only, bare path):
  - One field labelled `login_identifier`: ژمارەی مۆبایل یان ئیمەیل / رقم الهاتف أو البريد الإلكتروني / Phone number or email.
  - A password field; "Remember me"; "Forgot your password?" (`auth.forgot_password`: وشەی نهێنیت لەبیرچووە؟ / Forgot your password?).
  - The login button reads Sign in.
  - **The login page subtitle is `identity.auth.login_subtitle`**: ckb بۆ چوونە ناو پانێڵی بەڕێوەبردن / ar للدخول إلى لوحة الإدارة / en Sign in to the administration panel. This text is shown to **customers** on the public sign-in page (`Login.vue:30`). The layout comment confirms the page was designed only for staff (`AuthLayout.vue:15-19`).
  - Below the form: "Create an account: Create your account" linking to `/register`.
  - Login is throttled by identifier and by IP.
- **Password recovery**: email reset (`/forgot-password`), plus Telegram recovery that sends a link to the user's Telegram chat (`recovery.*`). The Telegram link works once and expires in 15 minutes.
- **Admin MFA** (`/admin/mfa/*`) is out of scope.

### (i) AI advisor: `/advisor` (`advisor.residential`, OFF)

- **Gate**: feature flag plus `telegram.linked` (`Advisor/Routes/web.php:53`). A guest is redirected to `/register`. A signed-in unverified user is redirected to `/account/verify`. There is **no guest preview or anonymous chat**.
- **What `public/advisor-live-chat-v8.js` is**: a plain-JS overlay loaded on every page by `resources/views/app.blade.php:210` (`defer`).
  - It does nothing unless a `[data-advisor-chat]` element exists (`advisor-live-chat-v8.js:166`), which only the `/advisor` page renders (`Chat.vue:196`).
  - It enhances the same composer by POSTing to `/advisor/reply` and `/advisor/request` (:261,759,964), and adds a typing indicator, avatars, quick-reply chips and a "My requests" link to `/account/requests` (:799).
  - It contains its own inlined 3-locale copy (`COPY`, :34-74).
  - It does not provide a separate guest-facing chat and has no own login or limit logic. Its limits are those of the routes it calls.
- **Rate limits**: `advisor-reply` 10 per minute per user (this is the paid-AI call), `advisor-write` 30 per minute (`IdentityServiceProvider.php:176-199`). On 429 the message is `advisor.chat.rate_limited`:
  - en Messages were sent too quickly. Please wait a moment and try again.
  - ckb پەیامەکان زۆر بە خێرایی نێردران. تکایە کەمێک چاوەڕوان بە و پاشان دووبارە هەوڵ بدە.
  - ar دزيت رسائل بسرعة كبيرة. انتظر شوية وبعدين جرّب مرة ثانية.
  - Provider and spend caps are config-driven (`AI_MONTHLY_COST_LIMIT_USD`).
- **Interview**: one question at a time. Slots are purpose, budget, currency, property type, area, workplace, schools, family, timeframe, payment, risk, return goal.
  - Example `live.questions.purpose`: ckb موڵکەکە بۆ خۆت و خێزانەکەت دەوێت، یان بۆ وەبەرهێنان؟ / ar للسكن لو للاستثمار؟ / en For living or investment?
  - Budget amounts are parsed by a deterministic parser; the AI never sets the budget (`docs/AI_ADVISOR_IMPLEMENTATION_REPORT.md:141-144`). Ambiguous amounts trigger `budget_clarify`.
  - The page title is `advisor.chat.title`: ڕاوێژکاری خانووبەرە / المستشار العقاري / Property advisor.
  - The availability line reflects the real `ai_available`: `chat.available` (بەردەستە / متاح / Available) versus `chat.unavailable`:
    - ckb ڕوونکردنەوەی AI لە ئێستادا بەردەست نییە
    - ar شرح الذكاء الاصطناعي غير متاح حاليًا
    - en AI explanations are currently unavailable
  - The right column lists "Your answers" with Edit and "Not answered" per slot, a progress value, and a "Start over" button.
- After the interview: "Show recommendations" (`chat.recommend`: پیشنیارەکان پیشان بدە / أظهر التوصيات / Show recommendations). It stays an open chat afterwards. Follow-ups, compare and "ask cheaper" work, even with the AI off, via keyword intent detection.
- **Send-to-team** (the only inquiry channel on the site):
  - Title `submit.title`: ckb داواکارییەکەم بنێرە بۆ تیمی MyHawler / ar أرسل طلبي إلى فريق MyHawler / en Send my request to the MyHawler team.
  - A consent checkbox (`submit.consent`: ڕازیم تیمی MyHawler پەیوەندیم پێوە بکات دەربارەی ئەم داواکارییە خانووبەرەییە. / en I agree that the MyHawler team may contact me about this property request.), then a button.
  - `submit.incomplete`: ckb تکایە سەرەتا وەڵامی پرسیارە سەرەکییەکان بدەرەوە — مەبەست و بودجە و جۆری موڵک. / en Please answer the main questions first — purpose, budget and property type.
  - On success: `submit.sent` with a link to "My requests".
- **What it answers from**: published platform data only (`home.advisor_lede`). Replies carry an evidence count (`message.evidence_count`).
  - Provider chain: OpenAI, Gemini or OpenAI-compatible, with a deterministic fallback, so the advisor works without a configured AI provider (`AI_ADVISOR_IMPLEMENTATION_REPORT.md:109-117,133`).
  - Sorani turns pass a per-turn language-detector gate. A failed turn ships a deterministic template, recorded as `source=deterministic` (:30-38).
  - **Disclaimer strings** (`advisor.notices.*`):
    - `ai_generated`: ckb ئەم دەقە بە یارمەتیی AI نووسراوە / ar كُتب هذا النص بمساعدة الذكاء الاصطناعي / en This text was written with AI assistance.
    - `no_price_guarantee`: ckb هیچ گەڕانەوەیەکی نرخ گەرەنتی ناکرێت / ar لا يوجد ضمان لارتفاع الأسعار / en No price appreciation is guaranteed.
    - `scenario_not_guarantee`: ckb ئەمە سیناریۆیەکە، نەک پێشبینییەکی دڵنیا / ar هذا سيناريو وليس تنبؤاً مؤكداً / en This is a scenario, not a prediction.
    - `withheld_ungrounded`: ckb ئەم وەڵامە ڕاگیرا چونکە ژمارەکانی پشتڕاست نەکرانەوە / ar حُجبت هذه الإجابة لعدم إمكانية التحقق من أرقامها / en This answer was withheld because its figures could not be verified.
    - `insufficient_data`: ckb زانیاریی پێویست بەردەست نییە بۆ وەڵامێکی دڵنیا / ar لا توجد بيانات كافية لإجابة موثوقة / en There is not enough data for a reliable answer.
    - Content classes: Fact, Analysis, Scenario, Advertisement (ڕاستی, شیکردنەوە, سیناریۆ, ڕیکلام).
    - A withheld answer shows "Answer withheld" (`chat.withheld`: وەڵام ڕاگیرا / تم حجب الإجابة / Answer withheld).
  - No general static disclaimer is shown on the chat page itself.
- **Language**: the interface follows the URL locale. The interview content is in the locale of the conversation; switching language resets the conversation (`AdvisorConversation.status` closes "for reset/language-switch only", `AI_ADVISOR_IMPLEMENTATION_REPORT.md:130-131`).
  - **The ar live-chat and ar `advisor.php` strings use Iraqi colloquial Arabic** (for example "شكد ميزانيتك", "تكدر تكمل", "دزيت رسائل", "صار خلل مؤقت"), while the rest of the ar product is Modern Standard Arabic. `advisor-live-chat-v8.js:52,55` duplicates the same strings.
- **Anonymous advisor**: the controller computes a user avatar only for signed-in users, and `v8.js:164` mentions "anonymous advisor sessions", but the route middleware does not allow them. This is stale commentary, not a live path.
- Advisor follows `docs/AI_ADVISOR_AUDIT.md`/IMPLEMENTATION_REPORT (I read the report; the audit I only grepped).

### (j) Lifestyle matching (`lifestyle.matching`, OFF)

- `/lifestyle` (edit and save) requires `telegram.linked`. The route comment says it is unauthenticated "on purpose" (`Advisor/Routes/web.php:16-20`), but the actual middleware at :27 overrides that, citing §7.11, which treats workplace and school as sensitive data. So lifestyle is **not** open to guests.
- `/lifestyle/results` is open to guests, but a guest has no profile, so the page shows "You have not created a profile yet" (`advisor.lifestyle.no_profile`: هێشتا پرۆفایلێکت دروست نەکردووە / لم تنشئ ملفًا بعد). In practice the guest result is always the empty state.
- Form content: title `advisor.lifestyle.title`: گەڕان بەپێی شێوازی ژیان / البحث حسب نمط الحياة / Lifestyle search. Budget card, property type checkboxes, and "Add an important place" with kinds (workplace, spouse workplace, school x2, university, parents, relatives, hospital, mall, park, airport, city centre, place of worship, custom). Each priority has importance 1-5, max distance in metres, and a "Required" flag.
- **Hardcoded English labels in the form** (not translated in any locale): "min", "max", "currency" (`Public/Lifestyle/Edit.vue:117,125,134`) and "kind" (:165). Budget currency options are USD and IQD. The max-distance field is in metres.
- Results explain the match with eight weighted components (budget fit 25, commute fit, school fit, service coverage, property type fit, family proximity 8, lifestyle fit, risk adjustment), a match score, and "Why?". The weighting is printed on the page.
  - Disqualified matches show "A required condition was not met" (`advisor.lifestyle.disqualified`: مەرجێکی پێویست جێبەجێ نەکراوە / شرط إلزامي غير محقق).
  - The score is calculated, not AI: `advisor.notices.score_is_calculated`: ckb ئەم خاڵە بە ژمێریاری دەرهێنراوە، نەک لەلایەن AIـەوە.

### (k) Portfolio ("My properties"; `portfolio`, OFF; auth + verified)

- Title `portfolio.title`: موڵکەکانم / عقاراتي / My properties. This is a personal tracker, not saved listings. Properties are added with these fields (`portfolio.form.*`): label, property type, unit type, project (or "not in a registered project"), area, size, rooms, floor, purchase price and date, ownership percent, occupancy (lives here, rented, vacant), notes, optional private location with precision, currency.
- Private label and notes are encrypted ("only you can see it", `portfolio.form.label_hint`).
- Valuation per property:
  - A consent checkbox (`consent_valuation`: ڕازیم بە خەمڵاندنی موڵکەکەم / أوافق على تقييم عقاري / I agree to my property being valued). `form.consent_valuation` copy: ckb نرخی ئەم موڵکە لە داتای بازاڕی بڵاوکراوە خەمڵێنە. ئەمە خەمڵاندنە، نەک نرخاندنی فەرمی.
  - A range, confidence, history, trend and a "Market baseline" breakdown.
  - **Disclaimer** (`valuation_actions.disclaimer`):
    - ckb ئەم ژمارەیە خەمڵاندنێکە لە بەڵگەی بازاڕی بڵاوکراوە — ناوەندی تۆمارە بەراوردکراوەکانە، نە نرخاندنی فەرمییە و نە دەستەبەرییە. نرخی ڕاستەقینە ئەوەیە کە کڕیار ڕازی دەبێت بیدات.
    - en This figure is an estimate from published market evidence — a median of comparable records, not an appraisal and not a guarantee. The real price is whatever a buyer agrees to pay.
    - "Asking prices excluded" is a stated rule (`portfolio.excluded_asking`).
  - Insufficient-evidence reasons: no transaction evidence, insufficient comparables at every match level, and adjustments exceeding the baseline.
- Valuation requests are limited to 6 per minute (`portfolio-valuation`).
- Summary band: per-currency totals, with "amounts in different currencies are never added together" (`summary.multi_currency_note`). Export is available (`portfolio.export`, "Export" / دەرهێنان / تصدير).
- Media: files up to 12 per property, images up to 5 MB and documents up to 10 MB, stored privately.
- Home shortcut: `home.portfolio_cta` (موڵکەکانم / عقاراتي / My properties) as a chip when `portfolio` is on, linked to the **unlocalized** `/account/portfolio` (`Home.vue:77`). The account menu uses `localized()`. A "Know Your Property Value" CTA is in the home location card (`home.location.valuation_cta`).
- `valuation_rules` (question-driven adjustments) is a separate OFF flag.

### (l) Leads and requests

- The **only inquiry path** in the public product is the advisor "Send my request to the MyHawler team" (`/advisor/request`). There is no project, offer or company contact form, call button, WhatsApp button, or email link anywhere.
- `/account/requests` (`Leads/Routes/web.php:14-16`; `Account/Requests.vue`) shows submitted requests.
  - Title `leads.requests.title`: داواکارییەکانم / طلباتي / My requests. Subtitle: ئەو داواکارییە خانووبەرەییانەی ناردووتن بۆ تیمی MyHawler. / en Property requests you sent to the MyHawler team.
  - Each card shows a stage pill, "Submitted" with a date, the summary text, and the budget with currency (or "Currency not stated" when the currency is unknown).
  - Stages: New = "Received" (وەرگیراوە), Qualified = "Being reviewed" (لە پێداچوونەوەدایە), Contacted = "We contacted you" (پەیوەندیمان پێوە کردیت), Won = "Completed" (تەواو بووە), Lost = "Closed" (داخراوە), Unknown = "In progress" (لە جێبەجێکردندایە).
  - 20 per page.
  - Empty: `empty_title` ckb هێشتا هیچ داواکارییەک نییە; `empty_body` ckb گفتوگۆیەکی ڕاوێژکاری AI تەواو بکە و داواکارییەکەت بنێرە — لێرە دەردەکەوێت. / en No requests yet; Finish an AI Advisor conversation and send your request — it will appear here.
  - **This page uses `AuthLayout`**, a minimal single-column layout with no site navigation (`Requests.vue:3,49`).
- `/account/privacy` lets the user withdraw or re-grant the team's permission to contact them (`identity.privacy.*`):
  - `withdrawn_confirmation`: en Contact permission withdrawn. Our team can no longer see your phone number.
  - `not_account_deletion`: en Withdrawing contact permission does not delete your account and does not unlink your Telegram.
  - `history_kept`: past decisions are kept as a record.
  - **No account-deletion or data-export self-service** exists, other than portfolio export.
- Phone-number handling: phone numbers are stored with PII encryption and a blind index. Staff see them only through a reveal ceremony in the Leads workspace (admin, out of scope).
- Contact copy on the account profile: "How should we contact you?", with options Phone call, Telegram, Either (`identity.profile.contact_*`).

### (m) Locale switch

- The `/locale/{locale}` POST (`routes/web.php:69-81`) sets the session key, and persists `preferred_locale` for a signed-in user, then redirects back. It 404s for a locale not enabled (`enabled_locales()`).
- In practice, on **localized public routes** the UI does **not** use POST. `useLocale.switchTo` (`Composables/useLocale.ts:32-62`) navigates the browser to the sibling URL with a full page load (`window.location.assign`), preserving path, query and hash. On non-localized routes (admin, `/login`, `/register`) it POSTs `/locale/{code}` and reloads. The `LOCALE_SWITCHER_SLICE.md` and `LOCALIZED_ROUTING_SLICE.md` docs describe an earlier defect that this fixed.
- UI: `Components/LanguageMenu.vue`, one control in the header (public layout) and one below the form in `AuthLayout`. It shows a flag and a caret (the native name shows only from xl). Options show the native name and a flag; the active one has a check and `aria-current`. Each option sets its own `lang` and `dir`. The aria label is `nav.language`.
- Locale precedence: URL, then the user's `preferred_locale`, then the session, then `Accept-Language`, then ckb (`config/localization.php:76`). The session comes before the browser header on purpose.
- Enabled locales come from `APP_ENABLED_LOCALES` (default `ckb,ar,en`). `ckb` cannot be disabled. `ckb` and `ar` are RTL with Noto Kufi Arabic; `en` is LTR with Noto Sans. Numerals are Latin; date format is `Y-m-d` (ckb, ar) and `d M Y` (en).
- The register form includes its own language select that sets the session locale server-side.
- The homepage and `/offline` are registered per locale (`routes/web.php:56-61`).
- Content fallbacks: an area or project name that is missing in the current language shows a different language plus a notice (`name_fallback_notice`, `translation_fallback`).
- Offline error copy, `Error.vue` and the Offline page contain inlined three-locale strings. `Error.vue:57-59` renders a bare link whose text is the hardcoded Latin string "Mulkihawler" pointing at `/` (the bare ckb home) for every locale.

### (n) PWA and offline

- Gated by `pwa` (OFF). With the flag on, `app.blade.php:23-31` adds the manifest link and Apple meta tags; `InstallPrompt.vue` is mounted in the public layout (`PublicLayout.vue:267`).
- Manifest (`/manifest.webmanifest`, `ManifestController.php`): built at request time from branding. `start_url` and `scope` are `/`, display is standalone, `dir`/`lang` follow `config('app.locale')` (not the visitor), and the manifest 404s if `pwa` is off. Shortcuts for Market, Map and Advisor appear only when each flag is on. **The icons array is empty unless an admin uploads icons** (`:93-134`), which browsers treat as not installable. `short_name` is `branding.short_name` or the first 12 characters of the site name. `description` is `branding.description`, empty by default.
- **Service worker**: `vite-plugin-pwa` (generateSW) builds `public/build/sw.js`, but `docs/REDESIGN_RECONCILIATION.md:42` records "the SW is built but never registered". I searched `resources` and `vite.config.ts` for `registerSW`, `pwa-register`, `navigator.serviceWorker` and found none. So **no service worker is registered**, `/offline` is only an ordinary route, and "Pages you opened earlier are still available" would not be true. Whether a production build registers it some other way is **not determined**.
- Install prompt (`InstallPrompt.vue`): shown after at least 2 visits (`VISIT_KEY`), with a 30-day quiet period after a dismissal; it depends on the browser's `beforeinstallprompt` event. Copy (`lang/*/pwa.php`): `install_title` ckb ئەم ماڵپەڕە وەک ئەپ دابمەزرێنە / ar ثبّت هذا الموقع كتطبيق / en Install this site as an app; `install` دامەزراندن / تثبيت / Install; `not_now` ئێستا نا / ليس الآن / Not now.
- Offline page copy (inlined, `Offline.vue:14-30`): ckb title بێ ئینتەرنێت, body ناتوانرێت پەیوەندی بکرێت. ئەو پەڕانەی پێشتر کردوونەتەوە هێشتا بەردەستن., action دووبارە هەوڵدانەوە; ar غير متصل بالإنترنت / تعذّر الاتصال. الصفحات التي فتحتها سابقًا لا تزال متاحة. / إعادة المحاولة; en Offline / No connection. Pages you opened earlier are still available. / Retry.
- Maps and invest pages show their own offline banner (`map.states.offline`: پەیوەندی ئینتەرنێت نییە / لا يوجد اتصال بالإنترنت / No internet connection, and the hint that the list shows the last data loaded).
- Fonts load from `fonts.bunny.net` (third-party CDN, `app.blade.php:36-45`), so first paint depends on that host for the Kufi and Sans faces; fallbacks exist.

---

## 5. Guest limitations and hard gates

| Capability | Gate | Behaviour |
|---|---|---|
| Browse home, projects (list and detail), market, maps, areas, places, companies, offers | none beyond flags | Open to guests when the flag is on, otherwise 404 |
| Advisor | flag + `telegram.linked` | Guest redirected to `/register`; JSON gets 401 `identity.errors.telegram_link_required` |
| Lifestyle form, portfolio, profile, onboarding, privacy, requests | `auth` + `account.active` + `telegram.linked` (+ flag for portfolio) | Guest to `/register`; unverified user to `/account/verify` |
| Submit a property request to the team | via advisor, so verified account | consent checkbox required |
| Contact an offer owner, company or project | **no route exists** | not available to anyone |
| Rate or review a project | **no route exists** | read-only ratings |
| Save or favourite a project or offer | **no feature exists** | not available |
| Valuation | portfolio consent per property | needs verified account |

**Verified means** `telegram_verified_at` or `whatsapp_verified_at`. A typed phone does not count (`EnsureTelegramLinked.php:33-39`). Company portal and admin use different, stricter gates (`verified`, MFA) and are out of scope.

**Gate messages** (`identity.errors.*`):
- `telegram_link_required`
  - ckb: تکایە بەستنەوەی هەژماری تێلێگرامەکەت تەواو بکە بۆ بەکارهێنانی ئەم تایبەتمەندییە
  - ar: يرجى إكمال ربط حساب تيليجرام لاستخدام هذه الميزة
  - en: Please finish linking your Telegram account to use this feature
  - This text reaches users only through JSON 401/403 responses (for example from the advisor fetch calls). A normal page navigation is a silent redirect with no message of its own; the target page title is "Verify your account".
- `verification_required`: ckb پێویستە ژمارەکەت پشتڕاست بکەیتەوە / ar يجب التحقق من رقم هاتفك / en Your phone number must be verified.
- `account_suspended`: ckb هەژمارەکەت ڕاگیراوە / ar تم تعليق حسابك / en Your account has been suspended.
- `account_inactive`: ckb هەژمارەکەت چالاک نییە / ar حسابك غير مفعّل / en Your account is not active.
- `missing_permission`: ckb ڕێگەپێدانت نییە بۆ ئەم کردارە / ar ليس لديك صلاحية لهذا الإجراء / en You do not have permission for this action.
- Hard gate on registration: a Telegram bot must be configured, as described under (h).
- Rate limits a guest can hit:
  - login (per identifier and per IP), registration (5 per 10 minutes per IP), password reset 5 per hour per email, Telegram recovery 3 per hour per phone, map viewport 60 per minute, map search 30 per minute, location-resolve 30 per minute, market-movement and map-market (own limiters).
  - Error 429: ckb داواکاریی زۆر زۆرە، تکایە کەمێک چاوەڕێ بکە / ar طلبات كثيرة جدًا، يرجى الانتظار قليلًا / en Too many requests, please wait a moment.
  - Login throttle: `identity.auth.throttled`: ckb هەوڵی زۆر زۆر. تکایە دوای :seconds چرکە هەوڵبدەرەوە / en Too many attempts. Please try again in :seconds seconds.

---

## 6. Empty states and error copy a visitor can hit

**Home** (`lang/*/home.php`; ckb, ar, en)
- `no_market_data`: هێشتا هیچ نرخێکی پشتڕاستکراو بڵاو نەکراوەتەوە / لم تُنشر أي أسعار موثّقة بعد / No verified prices have been published yet. Followed by `market.public.none_hint`.
- `no_projects`: هێشتا هیچ پڕۆژەیەک بڵاو نەکراوەتەوە / لم تُنشر أي مشاريع بعد / No projects have been published yet.
- `no_areas`: هێشتا هیچ ناوچەیەک نییە / لا توجد مناطق بعد / No areas yet.
- `feature_off`: ئەم بەشە ناچالاککراوە / هذا القسم غير مفعّل / This section is disabled.
- `advisor_disabled`: ckb ڕاوێژکاری خانووبەرە لە ئێستادا ناچالاککراوە / ar مستشار العقارات غير مُفعَّل حالياً / en The property advisor is not enabled at the moment. It shows as a status line under the hero while the advisor is OFF.
- `live_map.empty`: ckb هێشتا هیچ پڕۆژەیەک لەسەر نەخشە نییە — پڕۆژە بڵاوکراوەکان کاتێک دەردەکەون کە ناوونیشانی جوگرافییان تۆمار بکرێت. / en No mapped projects yet — published projects appear here once they carry coordinates.
- Location card (`home.location.*`):
  - `denied`: ckb ڕێگەپێدانی شوێن ڕەتکرایەوە — دەتوانیت خۆت ناوچەکە هەڵبژێریت. / en Location permission was declined — you can choose your area manually instead.
  - `unavailable`: ckb وێبگەڕەکەت نەیتوانی شوێنەکەت دیاری بکات — دەتوانیت خۆت ناوچەکە هەڵبژێریت. / en Your browser could not provide a location — you can choose your area manually instead.
  - `outside`: ckb ئەم شوێنە لە دەرەوەی ئەو ناوچانەیە کە تاکو ئێستا نەخشەکراون. / ar هذا الموقع خارج المناطق المرسومة على الخريطة حتى الآن. / en This location is outside the areas mapped so far.
  - `no_price_data`: ckb هێشتا هیچ زانیارییەکی نرخی بڵاوکراوە بۆ ئەم ناوچەیە نییە / en No published price intelligence for this area yet.
  - `rate_limited`: ckb هەوڵدانەکان زۆر بوون — تکایە کەمێک چاوەڕوان بە و دووبارە هەوڵ بدەرەوە. / en Too many attempts — please wait a moment and try again.
  - `error`: ckb هەڵەیەک ڕوویدا — تکایە دووبارە هەوڵ بدەرەوە. / en Something went wrong — please try again.
  - `areas_unavailable`: ckb لیستی ناوچەکان بارنەکرا — تکایە دووبارە هەوڵ بدەرەوە. / en The area list could not be loaded — please try again.
  - `privacy_hint`: ckb شوێنەکەت تەنها بۆ ناسینەوەی ناوچە نەخشەکراوەکە بەکاردەهێنرێت. / en Your location is used only to identify the mapped area.
  - `pricing_map.pricing_unavailable`: ckb توێژی نرخەکان لە ئێستادا بەردەست نییە — پڕۆژەکان بێ نرخ پیشان دەدرێن. / en The pricing layer is currently unavailable — projects are shown without prices.
- Home movement labels (`home.pricing_map.*`): Rising, Falling, Steady, "No comparable data" (ckb بەرزبوونەوە, دابەزین, جێگیر, داتای بەراوردکردن نییە).

**Projects**: see (a). **Offers**: see (c). **Market and map**: see (e) and (d). **Areas, places, companies**: see (f) and (g).

**Advisor and lifestyle**: `advisor.chat.send_failed`:
- ckb کێشەیەکی کاتی ڕوویدا و وەڵامەکە نەگەیشت. دووبارە هەوڵ بدە.
- ar صار خلل مؤقت وما وصل الرد. جرّب مرة ثانية.
- en Something went wrong and the reply did not arrive. Please try again.

**Generic app copy** (`lang/*/app.php`; ckb, ar, en):
- `states.loading`: بارکردن... / (ar ar-file line 43 not re-read) / Loading…
- `states.empty`: هیچ زانیارییەک نییە / en No data available.
- `states.error`: هەڵەیەک ڕوویدا / حدث خطأ / Something went wrong.
- `states.offline`: بێ ئینتەرنێت / غير متصل بالإنترنت / Offline.
- `states.not_found`: نەدۆزرایەوە / غير موجود / Not found.
- `errors.401`: پێویستە بچیتە ژوورەوە / يجب تسجيل الدخول / You need to sign in.
- `errors.403`: ڕێگەپێدانت نییە / ليس لديك صلاحية / You do not have permission.
- `errors.404`: ئەم پەڕەیە نەدۆزرایەوە / الصفحة غير موجودة / This page was not found.
- `errors.419`: دانیشتنەکەت بەسەرچووە، تکایە دووبارە هەوڵبدەرەوە / انتهت الجلسة، يرجى المحاولة مرة أخرى / Your session expired, please try again.
- `errors.429`: see section 5.
- `errors.500`: هەڵەیەکی ناوخۆیی ڕوویدا / حدث خطأ داخلي / An internal error occurred.
- `errors.503`: سیستەم لە دۆخی چاککردندایە / النظام قيد الصيانة / The system is under maintenance.
- The full-page error screen (`resources/js/Pages/Error.vue`) uses shorter inlined strings, for example 419 is ckb دانیشتنەکەت بەسەرچووە / en Your session expired. It shows the number and the line, with no layout or navigation. The home link text is the Latin word "Mulkihawler".
- Disabled flagged pages are a plain 404, not a feature-off message. The "feature disabled" admin message (`errors.feature_disabled`) is not shown to visitors.

**Auth and account**: see (h). The registration conflict refusal is deliberately vague: ckb نەتوانرا تۆمارکردن بەم زانیارییانە تەواو بکرێت. ئەگەر پێشتر دەستت بە تۆمارکردن کردووە، لەسەر ئەو ئامێرەی بەکارت هێناوە بەردەوام بە، یان پەیوەندی بە پشتگیری بکە. / en We could not complete registration with these details. If you started registering earlier, continue on the device you used, or contact support. It says "contact support", but **no support contact exists anywhere** (see section 7).
- Login failure: `identity.auth.failed`: ckb ئەم زانیارییانە لەگەڵ تۆمارەکانمان یەک ناگرنەوە / en These credentials do not match our records.
- Telegram unavailable copy also tells users to "contact us": `identity.telegram.unavailable`.
- Telegram link conflict copy (`link.conflict_help`) also says "contact us and we will untangle it".

**Unsubscribe** (`notifications.unsubscribe.*`): en Stop these notifications; Yes, stop these; Stopped. You will not receive messages of this kind again.; This link is not valid.; "Account security notices, moderation outcomes and legal notices are still sent, because they are not marketing." Notification copy was not otherwise read.

---

## 7. Trust and attribution signals (present and absent)

**Present**
- **Provenance chip** on every project profile: source, freshness (age phrased relative: recent, months, over six months, over a year), verified date, confidence (`ProvenanceChip.vue`). Stale or never-verified data is coloured caution or negative.
- **Asking versus verified price separation** with explicit qualifiers and the methodology banner (market page, price history). Each index discloses sample size, outliers excluded, sources, methodology version and revision status.
- **Company "Verified" badge** (`companies.verification.verified`) on project developer cards, offers and company profiles; the company page also shows the verified date and the licence number and authority. Admin copy defines the meaning: "we have seen evidence — not merely that it is published".
- **Sponsored versus organic** separation and a paid-placement disclosure on offers and on company-project lists; the "not ranked by payment" statement.
- **Location precision labels** on offers (exact, approximate, area only). Straight-line distance notices everywhere distances appear; the site footer repeats this.
- **Nearby places**: last-calculated date and a stale marker. **Places**: source, a "verified on" date or "not verified" notice.
- **Adverse project status** warning for stalled or delayed projects. "(implied)" marker on derived completion percentages.
- **AI disclosures** in the advisor (section 4i) and the portfolio valuation disclaimer (section 4k). The lifestyle score is labelled as calculated, not AI.
- **Name or translation fallback disclosure** on projects and areas.
- **Currency honesty**: amounts without a stated currency show "currency not stated"; portfolio totals are never summed across currencies.
- **Consent and privacy**: the `/account/privacy` page (signed-in only), optional unchecked contact-consent checkbox on the register form, and the unsubscribe page that works without login.
- **Version**: the footer shows `v{app.version}` beside the site name.
- Honest empty states throughout; "no demo data" rule (`HomeController.php:18-31`).
- JSON-LD and hreflang on project, offer and market pages.

**Absent**
- **No About page, Terms page, Privacy Policy page, Cookie notice, Contact page, FAQ or Help.** I found no such routes in `routes` or any module `web.php`. A regex search for `/terms`, `/privacy`, `/about`, `/contact`, `/help`, `/faq`, `/legal`, `/support` in `routes`, `app` and `resources` found no matches. `app.nav.about` and `app.nav.contact` (ckb دەربارە, پەیوەندی) exist as translation keys but nothing uses them.
- The register checkbox claims acceptance of "Terms of Service and Privacy Policy" with no links and no destination page.
- `legal.privacy_updated_at` and `legal.terms_updated_at` are seeded as empty strings and never read by any code I searched.
- **No operator identity or contact details**: `contact.email`, `contact.phone` and `contact.address_ckb` settings are seeded empty (`SiteSettingSeeder.php:31-33`) and I found no code or page that renders them. No company name, legal entity, licence, address, phone, email or social link appears in any public page, the footer, the manifest or the error pages. No `tel:`, `mailto:`, `wa.me`, or `t.me/` links exist in `resources/js`.
- Several user-facing messages tell the visitor to "contact us" or "contact support", which has no destination.
- **No "published at" or "updated at" stamp** on a project profile, offer card, offer detail or company page (the data exists in props for projects and offers but is not rendered). Index periods and place verification dates are the only date stamps on public data.
- No data-source attribution page and no methodology page (`docs/PUBLIC_MARKET_SLICE.md:85-86`). The market page shows per-index methodology version only.
- **No robots.txt or sitemap** in `public/` (the glob for `public/*.txt` returned nothing). `seo.indexing_enabled` (default 0) and `seo.default_description_ckb` are seeded but I found no reading code, so what blocks or allows indexing is **not determined**. `docs/LOCALIZED_ROUTING_SLICE.md:93` also lists "No sitemap".
- No Open Graph or share metadata (docs/PUBLIC_PROFILE_SLICE.md:81; not contradicted by `app.blade.php`).
- No breadcrumbs, no related projects, no media-credit page.
- No public review or rating submission, no user-generated content, and no company-claim or report-a-problem flow.
- No accessibility statement. (The code does show skip-link, 44 px touch targets and `aria-current`, but those are implementation details, not a signal in the UI.)
- No account-deletion or data-export self-service beyond the consent-withdraw page and portfolio export.

---

## 8. Known limitations and TODOs

- `release-delivery/REMAINING_LIMITATIONS.md` covers release engineering only. Nothing has been proven in a real browser (Playwright), MariaDB gates, deployment or rollback rehearsals, the GitHub Actions final-release run, or PHPUnit/PHPStan/Pint/vue-tsc/ESLint in that environment (items 1-6, lines 6-35). It says explicitly "does NOT constitute a release" (:4-5). There are no product-feature limitations in that file.
- `docs/AI_ADVISOR_IMPLEMENTATION_REPORT.md:306-317`:
  - Spend accounting needs rate variables set, otherwise cost records as zero.
  - The monthly spend counter is cache-backed and a cache flush resets it.
  - One model call per turn for understanding plus one for phrasing.
  - The Sorani quality gate is per-turn validation, not a native-reviewed corpus ("future work").
  - `AdvisorAnswerComposer` has no live caller.
  - The advisor surface needs `advisor.residential` ON and an AI provider configured (:319-326).
  - The branch deploys with no credentials.
- `docs/PUBLIC_MARKET_SLICE.md:74-86`: thirteen of eighteen planned dashboard elements absent, no default indices (the page is empty until an analyst defines one), no full-series chart view, no methodology page. Partly stale.
- `docs/PUBLIC_OFFERS_SLICE.md:60-74`: no expiry job, no saved searches or alerts, no comparison, unreviewed scorer weights. Its "no media" item is stale (offers now render approved images).
- `docs/PUBLIC_PROFILE_SLICE.md:68-83`: the "no map, no nearby, no price history, no ratings, no media" items are stale (all now implemented, with a missing-section declaration card). The locale-prefixed-routes defect there is fixed. "No breadcrumbs, related projects or share metadata" still stands.
- `docs/RATINGS_SLICE.md:53-59`, `docs/NEARBY_PLACES_SLICE.md:58`, `docs/LOCALE_SWITCHER_SLICE.md:77` and `docs/LOCALIZED_ROUTING_SLICE.md:84-95` list remaining issues I only located by line number; their content is **not determined** by me, apart from the routing doc, which I read. Its "switcher still POSTs" issue is fixed (`useLocale.ts`), "home/offline unlocalized" is fixed (`routes/web.php:56-61`), and "no sitemap" still stands.
- `docs/REDESIGN_RECONCILIATION.md:42`: service worker built but never registered.
- Code-level findings:
  - `config/features.php:30-37,46-54` comments: `geography.areas` is built but OFF; `content.news` is unbuilt and must stay OFF.
  - `Advisor/Routes/web.php:16-20` comment says lifestyle is unauthenticated while the middleware at :27 requires verification. The comment is stale.
  - `AuthLayout.vue:15-19` says only company staff reach the layout, yet the register, login and requests pages all use it.
  - `Register.vue` copy and flow mismatch (Telegram-first text, account-first flow).
- No `TODO` or `FIXME` comments appear in `resources/js` (grep returned nothing).

---

## 9. Brand and domain evidence

- **"andaz"**: no match anywhere in the repository (outside `node_modules`, `vendor`, `public/build`). Not determined.
- **"mulkihawler.krd"**: no match. `.env.example:12` sets `APP_URL=https://example.com`. `MULKIHAWLER_FORCE_HTTPS=true` (:78). No deployed domain is stated in code. The `docs/ROLLBACK_HOSTINGER.md:69` reference suggests Hostinger shared hosting (`public_html/`, `sw.js` copied). That is the only hosting hint.
- **Product and site-name variants found**:
  - Code and config: `Mulkihawler` (`config/app.php:6` default, `.env.example:2,8`, `composer.json` name `mulkihawler/platform`, `package.json` name `mulkihawler-frontend`, `README.md:1` "Mulkihawler 4.0", seeded `branding.site_name`, `ManifestController.php:35` default, `Error.vue:58`).
  - ckb: `app.name` مولکی هەولێر (`lang/ckb/app.php:12`) and `seo.default_title_ckb` مولکی هەولێر (`SiteSettingSeeder.php:43`).
  - **MyHawler**: used in end-user copy across `lang/*/identity.php`, `advisor.php`, `leads.php`. 55 matches of "MyHawler", "MULK" or the Sorani variants across 11 lang files. Examples: `identity.register.consent_contact`, `advisor.submit.title`, `identity.onboarding.title` ("Welcome to MyHawler"), `telegram.return_button` ("Return to MyHawler"), `leads.requests.subtitle`, `release-delivery/REMAINING_LIMITATIONS.md:1` ("MyHawler v7"), and the global advisor widget key `__myHawlerAdvisorLiveChatV8` (`public/advisor-live-chat-v8.js:4`).
  - **MULK**: `lang/ckb/identity.php:127` and its en counterpart ("older MULK account"), plus `Public/Offline` comments; the Arabic and Sorani Telegram copy uses "مای هەولێر" (`lang/ckb/identity.php:181`) and "مووڵکی هەولێر" (:187) for the same product.
- So the public site can present up to five names to one user: the header "Mulkihawler", the page-title and manifest default, the Sorani app name "مولکی هەولێر", the register, advisor and account copy "MyHawler", and the Telegram messages that say "MyHawler", "مای هەولێر" or "مووڵکی هەولێر". The effective `branding.site_name` in production is **not determined**.
- `site_name` usages: `PublicLayout.vue:53,234,261`, `PublicTopbar.vue` (brand link and letter chip), `AuthLayout.vue:10,26`, `app.blade.php:34`, `ManifestController.php:35`.
- `config/localization.php:32` and `:26-31` record that the language control shows simply "کوردی" as a product decision.

---

## Summary: 15 audit-relevant facts

1. **Default state is nearly empty.** Every public module flag defaults OFF (`config/features.php:15-94`) and a disabled flag returns a plain 404 (`EnsureFeatureEnabled.php:128-138`). Out of the box a visitor sees only Home, `/projects`, project profiles and login or register. Nav shows just "Projects"; bottom bar is Home, Projects, Account.
2. **`telegram.linked` really means "verified account".** It passes either `telegram_verified_at` or `whatsapp_verified_at` (`EnsureTelegramLinked.php:67`). Advisor, lifestyle form, portfolio, profile, onboarding and requests all need it. A guest is redirected to `/register` and an unverified user to `/account/verify` with no gate message on page loads.
3. **No guest advisor, and `advisor-live-chat-v8.js` is not a guest chat.** It is a plain-JS enhancer of the `/advisor` page only (`[data-advisor-chat]`), loaded on every page. It adds no login or limit logic of its own. Server limits: 10 replies per minute and 30 writes per minute per user.
4. **No inquiry channel except the advisor.** Offers, projects and companies have no contact button, form, phone, WhatsApp or email. `contact_method` is sent to the offer page and never rendered. The company page says "Contact through the platform" with no mechanism. Nothing links to `tel:`, `mailto:`, `wa.me` or `t.me`.
5. **Trust and legal pages are missing.** No About, Terms, Privacy Policy, Contact, FAQ, cookie notice, sitemap or robots.txt. Register requires accepting "Terms of Service and Privacy Policy" with no link or page. Messages say "contact support" with nowhere to go. `contact.*`, `legal.*` and `seo.default_description_ckb` settings are seeded empty or unread.
6. **No operator identity anywhere**: no legal entity, address, licence, phone or email in the footer, manifest or error pages. The footer shows only a site name, a tagline, nav links, the straight-line-distance notice and a version.
7. **Provenance is strong on projects and the market**: source, freshness, verified date and confidence on projects; asking-versus-verified separation; sample size, methodology version, sponsored-versus-organic labels; company Verified badge. But there is **no published or updated date** on projects or offers, and the project profile has **no units, availability, payment plan or installment data** and no developer link.
8. **Login page tells customers "Sign in to the administration panel"** (`identity.auth.login_subtitle`, all three locales). `AuthLayout` is a staff-era layout that the register, login and "My requests" pages also use (no site navigation).
9. **Registration is blocked if the Telegram bot is unconfigured** (`Register.vue:335`), even if WhatsApp OTP is available. The form copy ("then confirm with Telegram — no code to type", button "Continue with Telegram") describes an old flow; the real flow is account-first then a choice of Telegram or a WhatsApp code. Password minimum is 12 (default). `/login` and `/register` are not locale-prefixed.
10. **Brand inconsistency.** Header and footer show Latin "Mulkihawler"; Sorani app name مولکی هەولێر; user-facing copy and Telegram bot messages say "MyHawler", "MULK", "مای هەولێر", "مووڵکی هەولێر". The error page link is hardcoded "Mulkihawler".
11. **No deployed domain or "andaz" evidence.** "andaz" and "mulkihawler.krd" appear nowhere; `.env.example` has `APP_URL=https://example.com`. Only a Hostinger rollback doc hints at hosting.
12. **Offers**: Sorani text only for title, description and terms regardless of locale (`title_ckb`, `description_ckb`). Only two filters in the UI (name search, max budget); the server also takes `type` and `area` with no controls; sort is hidden ranking. The offer detail labels size as "Land area" and type as "Project type". Terminology split in Sorani: nav "پێشکەشکراوەکان" versus page title "پێشنیارەکان".
13. **Lifestyle form has hardcoded English labels** ("min", "max", "currency", "kind") and is gated to verified accounts, so a guest only ever sees the empty "no profile" result. Lifestyle, Companies and Places are not in any navigation.
14. **PWA is half-wired.** Flag OFF by default; manifest has empty icons unless the admin uploads some; the service worker is built but there is no registration code, so `/offline` and install prompts cannot behave as the copy promises. Fonts load from fonts.bunny.net.
15. **Arabic register mix.** The Arabic advisor and live-chat strings use Iraqi colloquial ("شكد ميزانيتك", "تكدر تكمل", "دزيت رسائل") while the rest of Arabic is formal MSA. Several Sorani spellings are inconsistent (پرۆژە versus پڕۆژە). Maps and invest provide list fallbacks, 3-area compare, and rate-limit copy; the explorer is the only compare feature (areas only).
