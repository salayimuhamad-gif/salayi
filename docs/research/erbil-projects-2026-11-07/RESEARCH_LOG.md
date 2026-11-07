# Research Log — Erbil city projects (accessed 2026-11-07)

## 1. Primary-site discovery outcome (in the mandated order)

| # | Site | How projects were enumerated | Iraq-wide project pages | Erbil project pages read | Notes |
|---|---|---|---|---|---|
| 1 | homele.com | `sitemaps/sitemap_projects.xml` + `sitemap_areas.xml` (Erbil areas) | 56 | 11 project pages + 179 project-like Erbil area listing pages | robots.txt allows path filters; project pages give price range, project area, status, completion date, payment plans, unit sizes |
| 2 | paya-realestate.com | `.well-known/sitemaps/sitemap-projects.xml` (EN/AR/CKB URLs) | 267 | 107 tagged Erbil (1 excluded: Chiwa Tower, Shaqlawa) | pages give status, price range, area range, developer, description, amenities, unit list |
| 3 | baghyshaqlawa.net | `/en/projects/city/erbil?p=0..9` | 110 (Erbil list) + Shaqlawa/Sulaymaniyah/Turkey lists not used | 110 | Cloudflare 'One moment, please…' challenge on some requests; pages give address, property types, features; no unit sizes |
| 4 | hajisalam.com | `/projects/` pages 1–7 (WordPress) + property-city taxonomy (216 terms) | 62 | 62 fetched; 2 old URLs 404 (replaced), 1 page empty (crawl-delay 60 s respected) | pages give address, project type, unit count, unit areas, floors, bedrooms + historic listings (2019–2020) |
| 5 | miranestate.com | `sitemap.xml` → `/projects/<id>` | 53 | 48 Erbil (5 unclear/other) | pages give developer, status, updated date, unit types with m², buildings/floors/units, features, payment methods |

## 2. Secondary sources used

* roya-iq.com — developer directory (15 developers) and projects listing (9 Erbil cards with status, developer, price range, date). Project detail pages returned 'Project Not Found' on 2026-11-07; 'areas' pages exist for ~45 Erbil projects.
* b-arrow.com (Blue Arrow Real Estate) — 9 Erbil project pages (Lalav Airport View, Empire Royal, Dream City, Star Towers, Quattro Towers, The Boulevard, Rami Towers, Lalav City).
* en.wikipedia.org — Aura Erbil, Downtown Erbil, English Village (Erbil), List of tallest buildings in Iraq, Erbil.
* Web search (Google-class index via the WebSearch tool) — used for discovery of projects missing from the five sites (Downtown Erbil/Emaar, Aura Erbil, M Towers, Future City Towers, Dee C Towers = DC Towers, Lavender Skyline) and for developer identification until the per-turn search budget (200) was exhausted.

## 3. Consolidation statistics

* Raw project names collected across sources: 235 (normalised keys) → **206 canonical entries** (Tier 1: 186, Tier 2: 20).
* Entries with evidence from ≥2 independent sites: 151; single-site entries: 55; entries with no fetched source record (web-search/secondary only): 0.

## 4. Canonical entries and their source coverage

| Project ID | Canonical name | Tier | Parent | Sources with a record | Scope note |
|---|---|---|---|---|---|
| 101-complex | 101 Complex | 1 |  | homele.com, paya-realestate.com | in |
| ab-center | AB Center | 1 |  | paya-realestate.com | in — Office development in English Village area (commercial) |
| address-erbil | Address Erbil (Address City) | 1 |  | miranestate.com, paya-realestate.com | in |
| alaska-towers | Alaska Towers | 1 |  | homele.com, miranestate.com, paya-realestate.com | in |
| american-village | American Village | 1 |  | baghyshaqlawa.net, hajisalam.com, homele.com, paya-realestate.com | in |
| ankawa-4-towers | Ankawa 4 Towers | 1 |  | baghyshaqlawa.net, hajisalam.com, homele.com, paya-realestate.com | in — Verify whether Baghy 'Four Towers' is the same project |
| 92-towers | 92 Towers | 1 |  | baghyshaqlawa.net | in |
| aram-garden | Aram Garden | 1 |  | homele.com, miranestate.com, paya-realestate.com | in |
| aram-towers | Aram Towers | 1 |  | homele.com, miranestate.com, paya-realestate.com | in |
| aram-village-1 | Aram Village 1 | 1 |  | baghyshaqlawa.net, hajisalam.com, homele.com, paya-realestate.com | in — Haji 'ARAM CITY' page (/en/10051/) to be checked: may refer to Aram Village or another project |
| aram-village-2 | Aram Village 2 | 1 |  | baghyshaqlawa.net, homele.com, paya-realestate.com | in |
| asco-international-city | ASCO International City | 1 |  | baghyshaqlawa.net, homele.com, miranestate.com | in |
| asco-village | ASCO Village | 1 |  | homele.com, miranestate.com, paya-realestate.com | in |
| ashti-city-1 | Ashti City 1 | 1 |  | hajisalam.com, homele.com | in |
| ashti-city-2 | Ashti City 2 | 1 |  | baghyshaqlawa.net, hajisalam.com | in |
| asuda-city | Asuda City | 1 |  | baghyshaqlawa.net, homele.com | in |
| atlantic-city | The Atlantic (Atlantic City) | 1 |  | baghyshaqlawa.net, hajisalam.com, homele.com, paya-realestate.com | edge — Kasnazan Road, near Majidi Land |
| avenue-square | Avenue Square | 1 |  | homele.com, miranestate.com, paya-realestate.com | in — Commercial project by Alcove |
| aynda-city-1 | Aynda City 1 | 1 |  | hajisalam.com, homele.com | in |
| aynda-city-2 | Aynda City 2 | 1 |  | hajisalam.com, homele.com | in |
| bafrin-city | Bafrin City | 1 |  | baghyshaqlawa.net, hajisalam.com | in |
| bajgir-city | Bajgir City | 1 |  | hajisalam.com | in |
| bakhtiari-twin-towers | Bakhtiari Twin Towers | 1 |  | baghyshaqlawa.net, homele.com, paya-realestate.com | in |
| baz-tower | Erbil Baz Tower | 1 |  | paya-realestate.com | in |
| beverly-hills | Beverly Hills Erbil | 1 |  | homele.com, miranestate.com, paya-realestate.com | in |
| boulevard | The Boulevard | 1 |  | b-arrow.com, baghyshaqlawa.net, homele.com, miranestate.com, paya-realestate.com | in |
| british-village | British Village | 1 |  | homele.com, miranestate.com | in |
| candle-residence | Candle Residence | 1 |  | homele.com, paya-realestate.com | in |
| cavalli-tower | Cavalli Tower | 1 |  | baghyshaqlawa.net, homele.com, miranestate.com, paya-realestate.com, roya-iq.com | in |
| cbc-china-business-center | CBC – China Business Center | 1 |  | homele.com | in — Commercial (showrooms/offices) |
| central-park | Central Park | 1 |  | paya-realestate.com | in |
| centro-tower | Centro Tower | 1 |  | homele.com, paya-realestate.com | in |
| cihan-city | Cihan City | 1 |  | baghyshaqlawa.net, hajisalam.com, homele.com, paya-realestate.com | in |
| darwazay-hawler | Darwazay Hawler | 1 |  | baghyshaqlawa.net | in |
| dashty-bahasht | Dashty Bahasht | 1 |  | hajisalam.com | in |
| davos-city | Davos City | 1 |  | baghyshaqlawa.net, homele.com, paya-realestate.com | in |
| dc-towers | DC Towers | 1 |  | homele.com, miranestate.com, paya-realestate.com | in |
| diplomati-safiran | Diplomati Safiran | 1 |  | baghyshaqlawa.net, homele.com, paya-realestate.com | in |
| diyari-city | Diyari City | 1 |  | homele.com, paya-realestate.com | in |
| dream-city | Dream City | 1 |  | b-arrow.com, baghyshaqlawa.net, hajisalam.com, homele.com | in |
| dubai-city | Dubai City | 1 |  | baghyshaqlawa.net, hajisalam.com, homele.com | in |
| empire-world | Empire World | 1 |  | baghyshaqlawa.net, hajisalam.com | in — Master development; sub-projects have their own entries |
| empire-wings | Empire Wings | 1 | empire-world | homele.com, miranestate.com, paya-realestate.com | in |
| empire-square-towers | Empire Square Towers | 1 | empire-world | homele.com, miranestate.com, paya-realestate.com | in |
| empire-pearl-towers | Empire Pearl Towers | 1 | empire-world | homele.com, miranestate.com, paya-realestate.com | in |
| empire-diamond-towers | Empire Diamond Towers | 1 | empire-world | homele.com, paya-realestate.com | in |
| empire-royal | Empire Royal (Royal Apartments & Royal Villas) | 1 | empire-world | b-arrow.com, homele.com, miranestate.com | in |
| empire-business-towers | Empire Business Towers | 1 | empire-world | homele.com | in |
| english-tower | English Tower | 1 |  | baghyshaqlawa.net, homele.com, paya-realestate.com | in |
| english-village | English Village | 1 |  | baghyshaqlawa.net, en.wikipedia.org, hajisalam.com, homele.com, miranestate.com, roya-iq.com | in |
| eskan-towers | Eskan Towers | 1 |  | baghyshaqlawa.net, homele.com, paya-realestate.com | in |
| ezma-business-tower | Ezma Business Tower | 1 |  | paya-realestate.com | in — Commercial offices |
| family-land | Family Land | 1 |  | baghyshaqlawa.net, hajisalam.com | in |
| firdaws-city | Firdaws City | 1 |  | baghyshaqlawa.net, homele.com | in |
| florence-tower | Florence Tower | 1 |  | homele.com, paya-realestate.com | in |
| floria-city | Floria City | 1 |  | baghyshaqlawa.net, hajisalam.com, homele.com, paya-realestate.com | in — Master development with sub-projects: Apart Villa, Marjan Plaza, Nergiz Tower, Rose Garden Tower, SK Tower (Paya lists each separately) |
| fm-plaza | FM Plaza | 1 |  | baghyshaqlawa.net | in |
| four-season-park | Four Season Park | 1 |  | baghyshaqlawa.net, homele.com, paya-realestate.com | in |
| future-city | Future City | 1 |  | baghyshaqlawa.net, homele.com | in |
| ganjan-city | Ganjan City | 1 |  | baghyshaqlawa.net, hajisalam.com, homele.com | in |
| ganjan-life | Ganjan Life | 1 |  | baghyshaqlawa.net, homele.com | in |
| erbil-garden | Erbil Garden | 1 |  | homele.com, miranestate.com, paya-realestate.com | in |
| global-city | Global City | 1 |  | baghyshaqlawa.net, homele.com, paya-realestate.com | in |
| golden-tower | Golden Tower | 1 |  | baghyshaqlawa.net | in |
| greece-city | Greece City | 1 |  | homele.com, paya-realestate.com | in |
| green-land | Green Land (Green Hills / Erbil Heights) | 1 |  | baghyshaqlawa.net, hajisalam.com, homele.com, paya-realestate.com | edge — Pirmam/Massif Road opposite Khanzad Hotel; phases 3 (Green Hills) and 4 (Erbil Heights) |
| green-world-towers | Green World Towers | 1 |  | baghyshaqlawa.net, homele.com, paya-realestate.com | in |
| gulan-city | Gulan City | 1 |  | hajisalam.com, homele.com | in |
| gulan-park-wtc | Gulan Park – World Trade Center (Gulan Tower) | 1 |  | baghyshaqlawa.net, homele.com, paya-realestate.com | in |
| hana-city | Hana City | 1 |  | baghyshaqlawa.net | in |
| harsham-city-1 | Harsham City 1 | 1 |  | baghyshaqlawa.net, hajisalam.com, homele.com | in |
| harsham-city-2 | Harsham City 2 | 1 |  | hajisalam.com, homele.com | in |
| harsham-city-3 | Harsham City 3 | 1 |  | hajisalam.com | in |
| hawler-city | Hawler City | 1 |  | hajisalam.com, homele.com | in |
| hiran-city-1 | Hiran City 1 | 1 |  | hajisalam.com, homele.com | in |
| hiran-city-2 | Hiran City 2 | 1 |  | hajisalam.com | in |
| hiwa-city | Hiwa City | 1 |  | baghyshaqlawa.net, hajisalam.com, homele.com | in |
| italian-village-1 | Italian Village 1 (Italian City 1) | 1 |  | baghyshaqlawa.net, hajisalam.com, homele.com | in |
| italian-village-2 | Italian Village 2 (Italian City 2) | 1 |  | baghyshaqlawa.net, hajisalam.com, homele.com | in |
| justice-tower | Justice Tower | 1 |  | hajisalam.com, homele.com | in |
| kamarani-city | Kamarani City | 1 |  | hajisalam.com | in |
| kani-almany-village | Kani Almany Village (German Village) | 1 |  | hajisalam.com, homele.com, paya-realestate.com | in |
| kany-kasa | Kany Kasa | 1 |  | homele.com, paya-realestate.com | in |
| kavar-city | Kavar City | 1 |  | hajisalam.com, homele.com | in |
| korean-village | Korean Village (Kory Village) | 1 |  | hajisalam.com, homele.com | in |
| kurdistan-city | Kurdistan City | 1 |  | baghyshaqlawa.net, hajisalam.com | in |
| lalav-airport-view | Lalav Airport View | 1 |  | b-arrow.com, baghyshaqlawa.net, homele.com, miranestate.com, paya-realestate.com | in |
| lalav-city | Lalav City | 1 |  | b-arrow.com, baghyshaqlawa.net, homele.com, paya-realestate.com | in |
| lalav-city-view | Lalav City View | 1 |  | baghyshaqlawa.net, homele.com, miranestate.com, paya-realestate.com | in |
| lalav-nova-towers | Lalav Nova Towers | 1 |  | homele.com, paya-realestate.com | in |
| lalav-sky-view | Lalav Sky View | 1 |  | baghyshaqlawa.net, homele.com, miranestate.com, paya-realestate.com | in |
| lalav-sun-towers | Lalav Sun Towers | 1 |  | baghyshaqlawa.net, homele.com, miranestate.com, paya-realestate.com | in |
| lalav-twin-towers | Lalav Twin Towers | 1 |  | homele.com, paya-realestate.com | in |
| lalav-venus-tower | Lalav Venus Tower | 1 |  | paya-realestate.com | in |
| lana-city | Lana City | 1 |  | baghyshaqlawa.net, hajisalam.com, homele.com, paya-realestate.com | in |
| lavender-city | Lavender City | 1 |  | homele.com, miranestate.com, paya-realestate.com | in |
| lavender-skyline-towers | Lavender Skyline Towers | 1 |  | homele.com, miranestate.com, paya-realestate.com | in |
| lawan-city | Lawan City | 1 |  | baghyshaqlawa.net, hajisalam.com, homele.com, paya-realestate.com | in |
| lebanese-village | Lebanese Village | 1 |  | baghyshaqlawa.net, hajisalam.com, homele.com | in |
| life-towers | Life Towers (King & Queen) | 1 |  | baghyshaqlawa.net, homele.com, paya-realestate.com | in |
| life-towers-2 | Life Towers 2 | 1 |  | homele.com, miranestate.com | in |
| light-city | Light City | 1 |  | baghyshaqlawa.net, homele.com | in |
| london-towers | London Towers | 1 |  | baghyshaqlawa.net, homele.com, miranestate.com, paya-realestate.com, roya-iq.com | in |
| majidi-view | Majidi View | 1 |  | baghyshaqlawa.net, homele.com, roya-iq.com | in |
| mamostayan-city | Mamostayan City | 1 |  | baghyshaqlawa.net | in |
| manhattan-towers | Manhattan Towers | 1 |  | homele.com, miranestate.com, paya-realestate.com | in |
| marina-1 | Marina 1 | 1 |  | hajisalam.com | in |
| marina-2 | Marina 2 | 1 |  | hajisalam.com | in |
| marina-3 | Marina 3 | 1 |  | hajisalam.com | in |
| mass-city | Mass City | 1 |  | baghyshaqlawa.net, hajisalam.com, homele.com, paya-realestate.com | in |
| mass-diamond | Mass Diamond | 1 |  | miranestate.com | in |
| mass-hills | Mass Hills | 1 |  | baghyshaqlawa.net, homele.com, miranestate.com | in |
| mass-village | Mass Village | 1 |  | baghyshaqlawa.net, homele.com, miranestate.com, paya-realestate.com | in |
| mem-u-zin | Mem u Zin | 1 |  | hajisalam.com | in |
| minara-village | Minara Village 1 & 2 | 1 |  | baghyshaqlawa.net, hajisalam.com, homele.com | in |
| mini-slava | Mini Slava | 1 |  | baghyshaqlawa.net, homele.com | in |
| mirage-city | Mirage City | 1 |  | homele.com, paya-realestate.com | in |
| mnw-towers | MNW Towers | 1 |  | baghyshaqlawa.net, homele.com, miranestate.com, paya-realestate.com | in |
| mountain-view | Mountain View | 1 |  | baghyshaqlawa.net, homele.com, paya-realestate.com | in |
| mrf-towers | MRF Towers (MRF 2 / MRF 4 / MRF 5) | 1 |  | baghyshaqlawa.net, hajisalam.com, homele.com, miranestate.com | in |
| mrf-quattro-towers | MRF Quattro Towers | 1 |  | b-arrow.com, baghyshaqlawa.net, hajisalam.com, homele.com, miranestate.com | in |
| roya-towers | Roya Towers (MRF Roya Tower) | 1 |  | baghyshaqlawa.net, hajisalam.com, homele.com | in |
| nawroz-city | Nawroz City | 1 |  | baghyshaqlawa.net | in |
| naz-city | Naz City | 1 |  | baghyshaqlawa.net, hajisalam.com, homele.com | in |
| new-azadi-atconz | New Azadi (Atconz) | 1 |  | baghyshaqlawa.net, homele.com | in |
| new-bahirka | New Bahirka | 1 |  | hajisalam.com | edge — Bahirka is a sub-district north of Erbil; verify whether inside city limits |
| new-eskan | New Eskan | 1 |  | baghyshaqlawa.net, homele.com | in |
| nice-village | Nice Village | 1 |  | homele.com, miranestate.com, paya-realestate.com | in |
| noble-city | Noble City (Nobel City) | 1 |  | baghyshaqlawa.net, hajisalam.com, homele.com | in |
| nobel-towers | Nobel Towers | 1 | noble-city | homele.com, paya-realestate.com | in |
| north-holland | North Holland | 1 |  | baghyshaqlawa.net, homele.com, paya-realestate.com | in |
| oakland-villas | Oakland Villas (Oak Land) | 1 |  | homele.com, miranestate.com, paya-realestate.com | in |
| zaiton-plus-city | Zaiton Plus City (Olive City) | 1 |  | baghyshaqlawa.net, homele.com, paya-realestate.com | in |
| zaiton-city | Zaiton City | 2 |  | homele.com | in |
| olive-garden | Olive Garden | 1 |  | homele.com, miranestate.com, paya-realestate.com | in |
| erbil-one-tower | Erbil One Tower (One Tower, Zanyari) | 1 |  | baghyshaqlawa.net, homele.com, paya-realestate.com, roya-iq.com | in |
| ozal-city | Ozal City | 1 |  | hajisalam.com, homele.com | in |
| pank-village | Pank Village (Pank City) | 1 |  | baghyshaqlawa.net, hajisalam.com, homele.com, paya-realestate.com | in |
| park-view | Park View | 1 |  | baghyshaqlawa.net, hajisalam.com, homele.com, miranestate.com, paya-realestate.com | in |
| parosh-twin-towers | Parosh Twin Towers | 1 |  | baghyshaqlawa.net, paya-realestate.com | in |
| pavilion | Pavilion Erbil | 1 |  | baghyshaqlawa.net, homele.com | in |
| peshang-towers | Peshang Towers | 1 |  | baghyshaqlawa.net, homele.com, paya-realestate.com | in |
| phoenix-tower | Phoenix Tower | 1 |  | baghyshaqlawa.net, homele.com, paya-realestate.com | in |
| plus-life | Plus Life | 1 |  | baghyshaqlawa.net | in |
| qaiwan-mirador | Qaiwan Mirador | 1 |  | baghyshaqlawa.net, miranestate.com, paya-realestate.com, roya-iq.com | in |
| quartier-francais | Quartier Français | 1 |  | homele.com, paya-realestate.com | in |
| queen-tower | Queen Tower | 1 |  | baghyshaqlawa.net, homele.com, paya-realestate.com | in |
| rami-towers | Rami Towers | 1 |  | b-arrow.com, baghyshaqlawa.net, homele.com, miranestate.com, paya-realestate.com | in |
| remas-city | Remas City | 1 |  | baghyshaqlawa.net, homele.com, paya-realestate.com | in |
| rena-tower | Rena Tower | 1 |  | baghyshaqlawa.net, homele.com | in |
| rona-tower | Rona Tower | 1 |  | baghyshaqlawa.net, homele.com, paya-realestate.com | in |
| rona-village | Rona Village | 1 |  | homele.com, miranestate.com, paya-realestate.com | in |
| rose-tower | Rose Tower | 1 |  | baghyshaqlawa.net | in |
| royal-garden-view | Royal Garden View | 1 |  | homele.com, paya-realestate.com | in |
| ruby-towers | Ruby Towers | 1 |  | baghyshaqlawa.net, homele.com, paya-realestate.com | in |
| safiran-city | Safiran City | 1 |  | baghyshaqlawa.net | in |
| salam-residences | Salam Residences | 1 |  | homele.com, paya-realestate.com | in |
| sari-blind | Sari Blind | 2 |  | hajisalam.com | in — May be an area name rather than a project; verify |
| shahan-city | Shahan City | 1 |  | hajisalam.com | in |
| siraj-towers | Siraj Towers | 1 |  | homele.com, miranestate.com | verify — Developer Lazgin Company; confirm the project is in Erbil city |
| sky-towers | Sky Towers | 1 |  | baghyshaqlawa.net, homele.com, miranestate.com, paya-realestate.com, roya-iq.com | in |
| slava-city | Slava City | 1 |  | baghyshaqlawa.net, homele.com, paya-realestate.com, roya-iq.com | in |
| spanish-village | Spanish Village | 1 |  | baghyshaqlawa.net, homele.com, miranestate.com, paya-realestate.com | in |
| spanish-village-2 | Spanish Village 2 | 1 |  | homele.com, miranestate.com | in |
| star-towers | Star Towers | 1 |  | b-arrow.com, baghyshaqlawa.net, hajisalam.com, homele.com, miranestate.com, paya-realestate.com | in |
| talar-city | Talar City | 1 |  | baghyshaqlawa.net, hajisalam.com, homele.com | in |
| true-star | True Star | 1 |  | baghyshaqlawa.net | in |
| tulip-towers | Tulip Towers | 1 |  | baghyshaqlawa.net, homele.com, miranestate.com, paya-realestate.com | in |
| unique-towers | Unique Towers | 1 |  | homele.com, paya-realestate.com | in |
| vana-towers | Vana Towers | 1 |  | homele.com, miranestate.com, paya-realestate.com | in |
| var-park | Var Park | 1 |  | baghyshaqlawa.net, homele.com, paya-realestate.com | in |
| varsan | Varsan | 1 |  | baghyshaqlawa.net, homele.com | in |
| veloria-towers | Veloria Towers | 1 |  | homele.com, miranestate.com | in |
| vip-class | Vip Class | 2 |  | paya-realestate.com | in — Paya entry; identity to verify |
| vital-city | Vital City | 1 |  | hajisalam.com | in |
| voyage-mall | Voyage Mall | 1 |  | baghyshaqlawa.net | in — Commercial |
| warin-city | Warin City | 1 |  | hajisalam.com | in |
| wavey-avenue | Wavey Avenue | 1 |  | baghyshaqlawa.net, homele.com, miranestate.com, paya-realestate.com | in |
| west-park-complex | West Park Complex | 2 |  | paya-realestate.com | in — Paya entry; verify location |
| white-towers | White Towers | 1 |  | baghyshaqlawa.net, homele.com, miranestate.com, paya-realestate.com | in |
| wow-tower | Wow Tower | 1 |  | baghyshaqlawa.net, homele.com, miranestate.com, paya-realestate.com | in |
| z-center-towers | Z Center Towers | 1 |  | baghyshaqlawa.net | in |
| zaner-city | Zaner City | 1 |  | baghyshaqlawa.net, homele.com | in |
| zanko-village | Zanko Village | 1 |  | baghyshaqlawa.net, homele.com | in |
| zanyari-towers | Zanyari Towers | 1 |  | baghyshaqlawa.net, hajisalam.com, homele.com | in |
| zerin-city | Zerin City | 1 |  | baghyshaqlawa.net, homele.com | in |
| zilan-city | Zilan City | 1 |  | baghyshaqlawa.net, hajisalam.com, homele.com | in |
| zin-city | Zin City | 1 |  | baghyshaqlawa.net, homele.com | in |
| downtown-erbil | Downtown Erbil (Emaar) | 1 |  | en.wikipedia.org | in — Discovered via web search; not on the five primary sites |
| aura-erbil | Aura Erbil | 1 |  | en.wikipedia.org | in — Discovered via Wikipedia search |
| alan-city | Alan City | 2 |  | homele.com | in |
| ara-tower | Ara Tower | 2 |  | homele.com | in |
| aram-commercial-city | Aram Commercial City | 2 |  | homele.com | in |
| ashti-village | Ashti Village | 2 |  | homele.com | in |
| beirut-palace | Beirut Palace | 2 |  | homele.com | in |
| eden-park | Eden Park | 2 |  | homele.com | in |
| edition-tower | Edition Tower | 2 |  | homele.com, roya-iq.com | in |
| new-erbil-showrooms-alcove | New Erbil Showrooms by Alcove | 2 |  | homele.com | in |
| pak-land | Pak Land | 2 |  | homele.com | in |
| rasan-towers | Rasan Towers | 2 |  | homele.com | in |
| saloor-tower | Saloor Tower | 2 |  | homele.com | in |
| sheraton-city | Sheraton City | 2 |  | homele.com | in |
| smart-city | Smart City | 2 |  | homele.com | in |
| stadium-tower | Stadium Tower | 2 |  | homele.com | in |
| zagros-tower | Zagros Tower | 2 |  | homele.com | in |
| zhyan-city | Zhyan City | 2 |  | homele.com | in |

## 5. Dedup / identity decisions

* **Address Erbil (Address City)** ← merged names/keys: address ; aliases: Adress City, The Address Erbil
* **American Village** ← merged names/keys: american village ; aliases: القرية الامريكية, گوندی ئەمریکی
* **Ankawa 4 Towers** ← merged names/keys: ankawa 4 tower, 4 tower ankawa, four tower ; aliases: 4 Towers Ankawa, Four Towers, ابراج عنكاوا, 4 تاوەری عەنکاوە
* **Aram Village 1** ← merged names/keys: aram village, aram village 1, aram city ; aliases: Aram Village, قرية ارام
* **Aram Village 2** ← merged names/keys: aram village 2, aram village2 ; aliases: Aram Village2, قرية ارام 2
* **Ashti City 1** ← merged names/keys: ashty city 1 ; aliases: Ashty City 1, شاری ئاشتی 1, اشتي ستي 1
* **Ashti City 2** ← merged names/keys: ashti city 2, ashty city 2 ; aliases: Ashty City 2
* **The Atlantic (Atlantic City)** ← merged names/keys: atlantic, atlantic city ; aliases: Atlantic American Residential, اتلانتك ستي, ئەتلەنتیك
* **Aynda City 1** ← merged names/keys: aynda city 1 ; aliases: Ayenda City 1, شاری ئایندە 1
* **Bakhtiari Twin Towers** ← merged names/keys: bakhtiari twin tower, bakhtyari twin tower ; aliases: Bakhtyari Twin Towers, بختياري توين تاور
* **Erbil Baz Tower** ← merged names/keys: baz tower ; aliases: Baz Tower, باز تاوەر
* **The Boulevard** ← merged names/keys: boulevard ; aliases: Boulevard Erbil, بوليفارد
* **Cihan City** ← merged names/keys: cihan city ; aliases: Jihan City, جيهان ستي, جیهان ستی
* **DC Towers** ← merged names/keys: dc tower ; aliases: Dee C Towers, دي سي تاور
* **Diplomati Safiran** ← merged names/keys: diplomati safiran ; aliases: Diplomatic Safiran, ديبلومات السفراء
* **Diyari City** ← merged names/keys: diyari city ; aliases: دياري ستي, Diyari Towers
* **Dream City** ← merged names/keys: dream city ; aliases: شاری خەونەکان, دریم ستي
* **Dubai City** ← merged names/keys: dubai city, dubay city ; aliases: Dubay City
* **Empire World** ← merged names/keys: empire, empire world ; aliases: Empire, ئیمپایەر وۆرلد, امبایر وورلد
* **Empire Wings** ← merged names/keys: empire wing, empire wings ; aliases: Empire Wing
* **Empire Square Towers** ← merged names/keys: empire square, empire square tower ; aliases: Empire Square
* **Empire Pearl Towers** ← merged names/keys: empire pearl, empire pearl tower ; aliases: Empire Pearl
* **Empire Royal (Royal Apartments & Royal Villas)** ← merged names/keys: empire royal ; aliases: Empire Royal, Royal City
* **English Village** ← merged names/keys: english village ; aliases: گوندی ئینگلیزی, القرية الانكليزية
* **Eskan Towers** ← merged names/keys: eskan tower ; aliases: Eskan Tower, ئیسکان تاوەر
* **Floria City** ← merged names/keys: floria city, florya city, apart villa, marjan plaza, nergiz tower, rose garden tower, sk tower ; aliases: Florya City, فلۆریا ستی
* **Four Season Park** ← merged names/keys: four season park ; aliases: 4 Season Park, فور سيزن تاور
* **Future City** ← merged names/keys: future city ; aliases: Future City Towers, فیوچەر ستی
* **Green Land (Green Hills / Erbil Heights)** ← merged names/keys: green land, green hills ; aliases: Greenland, Green Hills, Erbil Heights, كرین لاند
* **Green World Towers** ← merged names/keys: green world, green world tower ; aliases: Green World, كرين ورلد تاور
* **Gulan Park – World Trade Center (Gulan Tower)** ← merged names/keys: gulan park wtc, gulan tower, gullan tower ; aliases: Gulan Tower, Gullan Tower, WTC Erbil, كولان تاور
* **Harsham City 1** ← merged names/keys: harsham 1, harsham city 1 ; aliases: Harsham 1
* **Hiwa City** ← merged names/keys: hiwa city ; aliases: Hewa City, هیوا ستی
* **Italian Village 1 (Italian City 1)** ← merged names/keys: italian city 1 ; aliases: Italian City 1, شاری ئیتالی 1, القرية الايطالية 1
* **Italian Village 2 (Italian City 2)** ← merged names/keys: italian city 2 ; aliases: Italian City 2, شاری ئیتالی 2
* **Kamarani City** ← merged names/keys: kamarani city ; aliases: Kamarany City, کامرانی
* **Kani Almany Village (German Village)** ← merged names/keys: kani almany, kany almany ; aliases: Kany Almany, کانی ئەڵمانی
* **Korean Village (Kory Village)** ← merged names/keys: kory village ; aliases: Kori Village, گوندی کۆری, قریة الکوریة
* **Lalav Airport View** ← merged names/keys: lalav airport view, airport view ; aliases: Airport View, لالاف ايربورت فيو
* **Lalav Sky View** ← merged names/keys: lalav sky view, sky view ; aliases: Sky View, لالاف سكاي فيو
* **Lalav Sun Towers** ← merged names/keys: lalav sun tower, sun tower ; aliases: Sun Towers, سن تاور
* **Lalav Venus Tower** ← merged names/keys: lalav vinus tower ; aliases: Vinus Tower, فينوس تاور
* **Lavender Skyline Towers** ← merged names/keys: lavender skyline, lavender skyline tower ; aliases: Lavender Skyline, لافيندر سكاي لاين تاورز
* **Lebanese Village** ← merged names/keys: lebanese village, lebanon city ; aliases: Lebanon City, گوندی لوبنانی, قریة لبنانیة
* **Life Towers (King & Queen)** ← merged names/keys: life tower ; aliases: Life Tower, Life Towers Podium
* **London Towers** ← merged names/keys: london tower ; aliases: London Tower, لندن تاور
* **Manhattan Towers** ← merged names/keys: manhattan tower ; aliases: Manhattan Tower, منهاتن تاورز
* **Mass Village** ← merged names/keys: mass village ; aliases: قرية ماس, ماس ڤیلیج
* **Minara Village 1 & 2** ← merged names/keys: minara village 1 and 2, mnara village ; aliases: Mnara Village, گوندی منارە, مجمع منارة
* **MNW Towers** ← merged names/keys: mnw, mnw tower ; aliases: MNW, MNW Tower
* **MRF Towers (MRF 2 / MRF 4 / MRF 5)** ← merged names/keys: mrf tower, mrf 2 tower, mrf 4 tower, mrf 5 tower ; aliases: MRF 2 Tower, MRF 4 Tower, MRF 5 Towers
* **MRF Quattro Towers** ← merged names/keys: mrf quatro tower, mrf quattro, quattro tower ; aliases: Quattro Towers, Quatro Towers, شوقەکانی کواترۆ
* **Roya Towers (MRF Roya Tower)** ← merged names/keys: mrf roya tower, roya tower ; aliases: MRF Roya Tower, ڕۆیا تاوەر
* **Naz City** ← merged names/keys: naz city ; aliases: Zakaria Apartments, ناز ستی
* **New Azadi (Atconz)** ← merged names/keys: new azadi ; aliases: Atconz New Azadi, ئەتکۆنز ئازادی نوێ
* **New Eskan** ← merged names/keys: new eskan ; aliases: Eskani Nwe, نیو ئیسکان
* **Noble City (Nobel City)** ← merged names/keys: noble city, nobel ; aliases: Nobel City, Nobel
* **North Holland** ← merged names/keys: north holland ; aliases: North Holand, نورث هولند
* **Oakland Villas (Oak Land)** ← merged names/keys: oak land, oakland ; aliases: Oak Land, ئؤكلاند
* **Zaiton Plus City (Olive City)** ← merged names/keys: olive city, olive city 2, zaytoon plus city ; aliases: Zaytoon Plus City, Olive City, Olive City 2, اولف ستي
* **Erbil One Tower (One Tower, Zanyari)** ← merged names/keys: one tower, one tower zanyari ; aliases: One Tower, ون تاور
* **Ozal City** ← merged names/keys: ozall ; aliases: Ozall, ئۆزال ستی
* **Pank Village (Pank City)** ← merged names/keys: pank, pank city, pank village ; aliases: Pank, قرية بانك, پانك ستی
* **Park View** ← merged names/keys: park view ; aliases: بارك فيو, پارك ڤیو
* **Parosh Twin Towers** ← merged names/keys: parosh tower, parosh twin tower ; aliases: Parosh Tower, باروش توين تاور
* **Peshang Towers** ← merged names/keys: peshang tower ; aliases: Peshang Plus Towers, بيشانج تاور
* **Quartier Français** ← merged names/keys: quartier fran ais, quartier francias ; aliases: Quartier Francias, Quarter Franchise, كوارتير فرنسيس
* **Queen Tower** ← merged names/keys: queen tower ; aliases: Queen Towers, كوين تاور
* **Remas City** ← merged names/keys: remas city ; aliases: ريماس ستي, Remas Towers
* **Rona Tower** ← merged names/keys: rona tower ; aliases: Rona Towers, رونا تاور
* **Royal Garden View** ← merged names/keys: royal garden view ; aliases: Royal Garden, رويال كاردن فيو
* **Salam Residences** ← merged names/keys: salam residence ; aliases: Salam Residence, سلام ريزيدنس
* **Slava City** ← merged names/keys: slava city, slava square ; aliases: Slava Square, Slava City 2, سلافا ستي
* **Star Towers** ← merged names/keys: star tower ; aliases: Star Tower, ستار تاور
* **Tulip Towers** ← merged names/keys: tulip tower ; aliases: Tulip Tower, توليب تاور
* **Zanyari Towers** ← merged names/keys: zanyari tower ; aliases: Zanyari Tower, زانیاری تاوەر

## 6. Final verification outcome

* Profiles written: 206 of 206 canonical entries.
* Verification status: {'unverified': 54, 'partially_verified': 103, 'verified': 49}.
* Overall confidence: {'low': 81, 'medium': 108, 'high': 17}.
* Construction status: {'unknown': 45, 'under_construction': 52, 'disputed': 10, 'completed': 99}.
* Coordinates confidence: {None: 55, 'low': 100, 'medium': 43, 'high': 5, 'disputed': 3} (None = no usable map pin).
* Not plainly inside Erbil city: American Village (edge); Darwazay Hawler (edge); Dubai City (edge); Green Land (edge); Korean Village (Kory Village) (edge); Lana City (edge); New Bahirka (edge); Talar City (edge); The Atlantic (Atlantic City) (edge); VIP Class (outside); Var Park (edge); Warin City (edge)
* Second verification pass: 92 projects re-checked with up to two web searches each (181 searches in total); results {'new evidence': 18, 'no new evidence': 74}.

## 7. Projects still needing developer or on-site confirmation

Tier 1 projects that remain `unverified` or low confidence after both passes. The next step for these is a developer call, a developer-site check or a site visit:

* 101 Complex (101-complex) — partially_verified, low confidence
* 92 Towers (92-towers) — unverified, low confidence
* AB Center (ab-center) — partially_verified, low confidence
* Asuda City (asuda-city) — partially_verified, low confidence
* Aura Erbil (aura-erbil) — partially_verified, low confidence
* Aynda City 1 (aynda-city-1) — unverified, low confidence
* Aynda City 2 (aynda-city-2) — unverified, low confidence
* Bafrin City (bafrin-city) — unverified, low confidence
* Bajgir City (bajgir-city) — unverified, low confidence
* Erbil Baz Tower (baz-tower) — unverified, low confidence
* CBC (China Business Center) (cbc-china-business-center) — partially_verified, low confidence
* Central Park (central-park) — unverified, low confidence
* Centro Tower (centro-tower) — partially_verified, low confidence
* Darwazay Hawler (darwazay-hawler) — unverified, low confidence
* Downtown Erbil (downtown-erbil) — partially_verified, low confidence
* Dubai City (dubai-city) — unverified, low confidence
* Family Land (family-land) — partially_verified, low confidence
* Firdaws City (firdaws-city) — partially_verified, low confidence
* FM Plaza (fm-plaza) — unverified, low confidence
* Future City (future-city) — partially_verified, low confidence
* Ganjan Life (ganjan-life) — partially_verified, low confidence
* Golden Tower (golden-tower) — unverified, low confidence
* Greece City (greece-city) — unverified, low confidence
* Gulan City (gulan-city) — unverified, low confidence
* Hana City (hana-city) — partially_verified, low confidence
* Harsham City 2 (harsham-city-2) — partially_verified, low confidence
* Harsham City 3 (harsham-city-3) — unverified, low confidence
* Hawler City (hawler-city) — unverified, low confidence
* Hiran City 1 (hiran-city-1) — unverified, low confidence
* Hiran City 2 (hiran-city-2) — unverified, low confidence
* Kamarani City (kamarani-city) — unverified, low confidence
* Kavar City (kavar-city) — partially_verified, low confidence
* Korean Village (Kory Village) (korean-village) — partially_verified, low confidence
* Lalav Nova Towers (lalav-nova-towers) — partially_verified, low confidence
* Lalav Venus Tower (lalav-venus-tower) — unverified, low confidence
* Light City (light-city) — partially_verified, low confidence
* Mamostayan City (mamostayan-city) — unverified, low confidence
* Marina 1 (marina-1) — unverified, low confidence
* Marina 2 (marina-2) — unverified, low confidence
* Marina 3 (marina-3) — unverified, low confidence
* Mem u Zin (mem-u-zin) — unverified, low confidence
* Minara Village (minara-village) — partially_verified, low confidence
* Mini Slava (mini-slava) — partially_verified, low confidence
* Nawroz City (nawroz-city) — unverified, low confidence
* New Bahirka (new-bahirka) — unverified, low confidence
* Ozal City (ozal-city) — unverified, low confidence
* Plus Life (plus-life) — partially_verified, low confidence
* Rena Tower (rena-tower) — partially_verified, low confidence
* Rose Tower (rose-tower) — unverified, low confidence
* Royal Garden View (royal-garden-view) — partially_verified, low confidence
* Safiran City (safiran-city) — unverified, low confidence
* Shahan City (shahan-city) — partially_verified, low confidence
* Talar City (talar-city) — partially_verified, low confidence
* True Star (true-star) — unverified, low confidence
* Unique Towers (unique-towers) — partially_verified, low confidence
* Varsan (varsan) — unverified, low confidence
* Vital City (vital-city) — unverified, low confidence
* Voyage Mall (voyage-mall) — unverified, low confidence
* Warin City (warin-city) — unverified, low confidence
* Z Center Towers (z-center-towers) — unverified, low confidence
* Zilan City (zilan-city) — partially_verified, low confidence

## 8. Cross-project findings reported by the research agents

Possible duplicates, phase relationships and conflicts noticed while researching neighbouring projects. These are leads for editors, not resolved facts.

### V05 (global-city … justice-tower)
- hiran-city-2: no source anywhere; homele has a single "Hiran City" area → possible duplicate of hiran-city-1; both unverified, location unknown.
- global-city vs homele "Global Hills" area: not merged; unresolved.
- green-land: in_erbil_city "edge"; phases split (apartments, villa phases 1–2, Green Hills, Erbil Heights); phase 4 status disputed (Paya completed vs homele 2024 under construction); 250 (Haji) vs 120+120 (homele) villas.
- gulan-park-wtc: the only "verified" record in V05.
- Italian City 1 & 2: separate Hemn Group sites. Unit conflicts 740 vs 705; 1,561 vs 1,563.
- Justice Tower: 35 floors (Haji) vs 40 (round-city.com / Wikipedia); developer Nasri Group, 2016 – web-search summaries only.
- Inferred statuses from listing tags: Italian 1/2 completed; Greece City, Hiwa City under construction.
- golden-tower: Zeren City, Shaqlawa road; kept in scope, exact position unknown.
### V07 (lavender-skyline-towers … mass-village)
- Mass Diamond is a section of Mass City (also Mass Palace, Mass Jumeira sections). Mass Village / Mass Hills: same developer, relation to Mass City unstated; Miran tags Mass Village listings "Diamond Zone".
- Life Towers 2: separate later Tanj Group project next to Life Towers (King & Queen), 32 Park. Units: 869 apartments + 25 villas (Miran text) vs "33" (Miran stats box) → 894 used.
- London Towers: status Completed (Paya) vs Under Construction (Miran, homele, Roya) → under construction; completion date disputed. Only "verified" record in V07.
- Majidi View developer: Hiwa Holding (Baghy) vs Majidi Group (Roya).
- Lavender Skyline: 3 buildings (Miran stats) vs one 32-storey tower (Paya); 7,700 m² land from a homele search snippet only.
- No developer found: Lavender Skyline, Light City, Mamostayan City; Mamostayan single-source (Baghy) → unverified.
### V06 (kamarani-city … lavender-city)
- korean-village = Kory/Kori Village: in_erbil_city "edge" (Shaqlawa road, exact position unstated).
- kamarani-city: built from Haji page (Kasnazan Road behind Majdi Mall; 201 houses + 7 apartment buildings).
- 1,795 units appears for both Lalav City (b-arrow) and Lalav City View (Miran) → Lalav City 1,680 (Baghy blocks); City View figure low confidence.
- Lavender City (villas, Bahrka approach outside 150m road, kept in scope) ≠ Lavender Skyline Towers (Kirkuk road).
- Same projects: Airport View = Lalav Airport View; Sky View = Lalav Sky View.
- Sky View: land area disputed 41,000 vs 20,000 m²; homele shows ready rentals though pages say under construction.
- Sun Towers 18 vs 20 floors, 1,188 vs 1,254 units; Airport View 1,135 vs 1,155 units; Kany Kasa status disputed.
- Venus Tower: Paya only, status unknown. No developer found: Kamarani, Korean Village, Kany Kasa, Lavender City.
### V02 (atlantic-city … centro-tower)
- verified: boulevard, cavalli-tower. unverified: aynda-city-1, aynda-city-2, bafrin-city, bajgir-city (bajgir: no evidence at all).
- atlantic-city: "edge"; Completed (Paya) vs Ongoing (homele 2026-08-31, buildings 22/23 off-plan) → under_construction (partly completed). 1,539 units = derived arithmetic (28×32 + 643), not stated.
- Possible extra project: Roya card "Cavalli Tower Erbil" (Sardar Group, off-plan, USD 290K–1.2M, 2027-06-30) does not match the finished Shahan Group Cavalli Tower; kept apart. Cavalli floors 47/48/50, buildings 1 vs 2.
- avenue-square: part of Alcove's Erbil Avenue masterplan.
- Boulevard developer: 4Bridges (Miran) vs Premier Real Estate (Paya).
- british-village: "Cambridge View" is the developer, not an alias.
### V01 (101-complex … ashti-city-1)
- american-village: "edge" (Paya coords ≈19 km NE of citadel, Shaqlawa/Pirmam road, beyond 150m road).
- ankawa-4-towers: Baghy "Four Towers" (Bahrka Road) probably same, unconfirmed; separate from MRF 4 / Quattro. Haji page calls it Office.
- Paya pages carry schema.org coordinates (used for 8 projects; 101 Complex has 0,0 placeholder).
- ashti-city-1 uses Haji page (Kasnazan Road, 1,545 houses, 250 m²); ashti-city-2 (v1 file) did NOT use Haji /ashty-city-2/.
- ASCO International ≠ ASCO Village (both Diamond Zone opposite Grand Majidi Mall); ASCO Intl units 1,390 vs 2,390 on the same Miran page → disputed.
- Aram Garden: follow-on to Aram Village 2 but Miran names Dutch Flower Co. Aram Towers ~700 units (Paya/Miran text) vs 900 (Miran stats).
- 92 Towers unverified (Baghy only); 101 Complex no location/developer. Developers null: 101 Complex, Address Erbil, ASCO International. Ashti City developer (Eskan) brand-level only (c.2011 article).
### V03 (cihan-city … empire-diamond-towers)
- darwazay-hawler (Erbil–Shaqlawa road) and dubai-city (Erbil–Koya road): "edge"; single-source (Baghy), no developer/status.
- Empire World: Falcon Group, ~750,000 m², 2017 target — 2014 news via search summaries. Sub-project pages name "Empire Company" (Paya) / "B.C./BC Group" (Miran) → developer disputed on Wings & Square.
- Empire sub-project "areas" (Royal Villas 565,000; Royal Apts 151,000; Square 168,497; Pearl 234,000 m²) exceed the 750,000 m² master → cannot all be land areas; kept Low confidence as possible built-up areas.
- Empire Wings: 26 buildings / 2,008 apts (Baghy, Haji) vs 30 / 3,856–3,865 (Miran); Square 720 (Paya) vs 702 (Miran).
- Cihan City: 16 (Paya) vs 14 (Baghy) buildings. Empire Diamond 2-bed sizes on Paya copy the Pearl text verbatim. homele "Diyari Towers" link to Diyari City unconfirmed.
### V04 (empire-royal … erbil-garden)
- Family Land (Pirmam/Shaqlawa road), FM Plaza and Floria City (Koya Road): distance from ring roads unstated; FM Plaza single source, unverified.
- Empire Royal & Empire Business Towers built mainly from Empire World Baghy/Haji pages. Business Towers T1: 33 floors (Wikipedia) vs 27 (two primary sites). Empire Royal 804 vs 320 apartments.
- Floria City phases: Marjan Plaza, Rose Garden Tower, Nergiz Tower, SK Tower, Apart Villa. Possible duplicates: SK Tower ↔ "Sky Tower" (964media); Nergiz ↔ MEED "Nergis Plaza"; Roya "Rose Garden phase" (807 units) ↔ rose-tower entry. Builder Rad Construction (MEED); >2,000 units (Kurdistan24).
- Future City: Partner Studio/Parsha "Future City Towers" (2 × 138 m, 46 floors) recorded as a proposed phase; link to the Shaqlawa-road site unconfirmed.
- English Village 410 vs 420 villas; 100 acres vs 26 ha. Eskan Towers 7×30 vs 6×26 floors. Erbil Garden status Constructing (Paya) vs Completed (Miran) → completed.
### V14 (beirut-palace … zhyan-city) — all Tier 2, homele listing areas only
- 11 unverified, all low confidence; Edition Tower partially_verified (Roya card too). No developer found for any; "Alcove" for New Erbil Showrooms only from the area name.
- Status inferred from listing tags: under construction for Eden Park, Smart City, Zagros, Alcove showrooms; unknown for the rest. Edition Tower status disputed (Roya off-plan vs homele ready rentals); possible link to an "Edition" project on Gulan Street opposite Naz City unconfirmed.
- Zagros Tower alias "Zagros Towers"; Zhyan City ≠ Paya's Zhyanawa City (Sulaymaniyah). Sheraton City: one 200 m² plot listing; may be a neighbourhood label, not a project.
### V10 (phoenix-tower … royal-garden-view)
- royal-garden-view: no location beyond "Erbil"; in_erbil_city=true is an assumption.
- rose-tower (Baghy, Koya Road) probably duplicates Paya "Rose Garden Tower" inside Floria City (same road, same feature list); kept separate, unverified, probable parent Floria City.
- Rona Tower (Kirkuk Street, Kirmanj, 2 × 30 floors) ≠ Rona Village (Doli Razhan villas, Green Belt/150m). Rena Tower kept separate (Kirkuk Road, different sizes).
- Remas City: homele "Remas Towers" off-plan may be a phase (unconfirmed); Roya snippets 410 vs 141 villas, builder "Artima" (low reliability).
- Qaiwan Mirador: 9 towers (descriptions) vs 10 buildings (Miran stats). Queen Tower ≠ Life Towers (King & Queen). Phoenix Tower use disputed (apartments+commercial vs offices).
### V13 (z-center-towers … ashti-village)
- Z Center Towers is inside Zerin City (Baghy). Golden Tower ("Zeren city", pin ~0.3 km from Zerin) probably inside Zerin City; Zeren/Ziren = Zerin spellings.
- Ara Tower is inside English Village (Baghy "English Village (Ara Tower)", 150 m² flats) — may duplicate English Village apartment buildings.
- Zilan City location disputed: Koya Road (Baghy) vs Kasnazan Road (Haji: 456 houses, 167 m², 1 floor, 2 bedrooms).
- Downtown Erbil (Emaar, 541,000 m², launched 2013): status disputed (Completed vs Phase 2 complete). Aura Erbil (Zardman, 200,000 m², 269 flats): under construction per 2013–2015 sources — flagged outdated.
- Completed statuses for Zaner/Zanko/Zerin/Zilan/Zin and Tier 2 inferred from homele "Ready" tags only.
- Kept separate (no linking source): Zanko Village vs Haji "Zanko City"; Ashti Village vs Ashti City 1/2; Aram Commercial City vs other Aram projects.
### V08 (minara-village … nice-village)
- mrf-towers: MRF 2/4/5 as sub-buildings; 11 towers / 880 units = sum of Miran per-group counts (derived). Developer MRF Group (Baghy) vs Lalav Group (Miran); same for mrf-quattro-towers.
- roya-towers: Haji "MRF Roya Tower" vs Baghy New Krmanj (2014–late 2017).
- minara-village: only homele "Minara City 1"; no source for Minara 2.
- new-eskan builder: Shaxa Barza & 99 (own Baghy page) vs BC Group (Nawroz City page).
- new-azadi-atconz units: 648 (Roya) vs 1,565 planned (Building.co.uk) + "four phases" (Marcopolis) — search summaries only.
- Status: mountain-view disputed; nice-village completed (Miran) vs Constructing (Paya); mini-slava completed vs older Roya under construction. Mini Slava 4 towers / 592 units / 10,390 m² from unopened Roya page → Low.
- No developer: Minara, Mirage City, MNW. Nawroz City single source → unverified.
### V12 (tulip-towers … wow-tower)
- verified: Tulip, Wavey, White, Wow. unverified: Varsan, VIP Class, Vital City, Voyage Mall, West Park.
- vip-class is in Shaqlawa → in_erbil_city=false; status disputed (only towers A, B finished). var-park "edge" (Massif/Pirmam road near Shaways). Voyage Mall on Bahrka Road (Baghy only). West Park: rent-only office/shop complex near Sami Abdulrahman Park (in city).
- Veloria: Miran 3 buildings/40 floors/1,088 units vs homele X to 31st, Y/Z to 36th + 4 basements → floors null; completion 31/05/2028 (Miran) vs Q2 2029 (homele, used).
- Tulip floors 44/42/40 (44), units 1,243 vs 1,220; 2026 targets passed, still under construction. White Towers 2 (Paya) vs 4 (Miran) towers; Wavey floors 21/18/16; Wow floors 16 vs 15+2 basements → left null.
- Vital City possibly = "Vital Village" near English Village (unconfirmed). Wavey Avenue credited to "MRF Avenue" and Lalav. Unique Towers: Roya summary 6 towers / 2,580 units / Shaqlawa road (not used); developer "Unique Developers" (Roya directory, low).
### V09 (nobel-towers … peshang-towers)
- ozal-city, zaiton-city: homele listings only → status unknown, unverified; ozal-city location unconfirmed.
- zaiton-plus-city includes Olive City (completed, 129 units), Olive City 2, homele "Zaiton Plus VIP"; a homele Olive City listing is off-plan (conflict). zaiton-city kept separate.
- Peshang Plus Towers kept inside peshang-towers as a possible phase (unconfirmed).
- Nobel Towers: Paya files it under Nobel City but says the towers overlook it → may be adjacent rather than part.
- Pavilion developer disputed: Lalav Group (owner) vs "Pavilion by RAMS"; start early 2022 (Baghy) vs 2024 (RAMS page).
- Park View units 816 (Miran) vs 960 (Haji). Erbil One Tower = Wikipedia "E1 Tower"; Roya price range USD 220K–720K conflicts with other sources.
### V11 (ruby-towers … true-star)
- verified: Sky Towers, Star Towers, Spanish Village. unverified (single source): Safiran City, True Star, Sari Blind.
- talar-city "edge" (Koya Road, Baghy pin ~9 km east of citadel). siraj-towers is in Erbil (Miran "near Ankawa", listings "Kuran Ankawa"; homele under Erbil); Lazgin base unconfirmed.
- Slava City phases: 2016 complex, 2020 villas, Slava Square, homele "Slava City 2"; a 121 m² "Slava City" apartment may belong to Mini Slava.
- Spanish Village 2: Miran page reuses Spanish Village 1 copy; status disputed (Miran under construction vs homele ready houses). Safiran City ≠ Diplomati Safiran (pins ~4 km apart).
- Developer conflicts: Sky Towers Galiawa vs Sardar; Spanish Village Redan vs Darin; Star Towers MRF vs Lalav. Sky Towers tower count 5 vs 3 disputed.
- No developer: Ruby, Safiran, Talar, True Star, Sari Blind.
### Haji supplement (ashti-city-2, ganjan-city)
- ashti-city-2: Haji adds 345 houses + 890 villas (1,235 total, low confidence); Baghy's "1,700" sits under a "Global City" heading and its villa names (Bronze/Silver/Zumrud/Gold) match Eskan's Global City on Paya → removed from descriptions, kept in conflicts. c.2011 article: 5,000 units for Ashti City overall. Developer Eskan low confidence.
- ganjan-city: Haji adds 2,391 units, 250–1,000 m², "between Shaqlawa and Bahrka roads"; bedroom/size conflicts with homele listings (6–8 beds; 485/550/900/330 m²).
### L02 (marina-1 … zanyari-towers)
- new-bahirka, warin-city: "edge" (Bahirka / Shaqlawa road addresses only). Mem u Zin kept in city on "Mosul Road" address alone.
- Marina 1, 2, 3 kept separate; Marina 1 & 2 identical Haji figures (140 units, 336 m²) — possibly copied; Marina 3: 148 villas, 354 m².
- Noble City (Haji: 465 apartments 67/100 m², old Bahirka road) vs Nobel Towers (69–118 m², Ankawa–New Bahirka road): relation unclear. Baghy pin for Noble City lies near the citadel → rejected.
- Zanyari Towers: Haji "Zanyari Tower" 25–49 floors / 800 units probably includes Erbil One (E1, "formerly Zaniary Tower"); kept separate; units null.
- Pank Village verified; Paya developer field "Kani Almany Village" is another project's name → developer null. Naz City: weak undated lead to Eskan (Galala group).
### L01 (dashty-bahasht … lebanese-village)
- verified: Lawan City. unverified: Dashty Bahasht, Harsham City 3, Hawler City (no location), Gulan City.
- Lana City "edge" (Koya Road behind Atlantic City); Haji says Kasnazan Road; Paya Completed vs "developing" text; Roya snippet links builder Artima (unconfirmed).
- Kavar City (Shaqlawa road, next to the Russian consulate) and Kani Almany ("outskirts" per Paya) kept in scope.
- Harsham 1: old Bahrka road, Ankawa side, 600 houses + 150 apartments (Baghy) vs 500 units (Haji), builder Kamel Ahmed Agha (Baghy). Harsham 2: 550 houses, old road. Harsham 3: 500 houses, new road. Unnumbered homele "Harsham City" area placed under phase 1 (unconfirmed).
- "Lawan 2" mentioned on Haji pages of nearby projects — relation to Lawan City unresolved.
- Lebanese Village: area 240,000 / 225,000 / 300,000 m²; units 3,400 vs 3,480; developer HARCO from a search summary (low); Baghy Zones B and E may describe the same G+24 tower.
- Paya "developer" field for Kani Almany and Lana City is a parsing artefact → null.
### Second verification pass (2026-11-07)
#### S5
- 22 searches; 2/12 gained evidence. Lavender Skyline: homele (search summary) confirms 3 towers (now Medium); its 30 floors conflict with 32 on two pages (32 kept). Mamostayan City: weak lead (2017 REACH map label "MAMOSTAYAN"). Lalav Nova / Venus: only confirms Lalav Group is a real developer.
#### S7
- 24 searches; 2/12 gained evidence. shahan-city: UNHCR Erbil map lists "Shahan City" near Kasnazan, Heran City, Zheyan → partially_verified (low). unique-towers: Roya listing (roya-iq.com/?p=9741) confirms Diamond Zone, Shaqlawa Road; 6 towers / 2,580 units / 39 floors single-listing only (not in typed fields).
- Rose Tower still a probable duplicate of Floria City's Rose Garden Tower; "Vital Village" not confirmed as Vital City.
#### S6
- 24 searches; 2/12 gained evidence. naz-city: Roya developer-partners page calls it "mixed-use" (category conflict logged, residential kept); ANBA article: apartments occupied mainly by foreigners. plus-life: OpenSooq ad (192 m², 3 bedrooms, Koya Road view) → partially_verified (low).
- minara-village: Erbil Governorate road notice dated January 2023 (road between Hana City and Minara City). Mirage City searches return an unrelated compound in New Cairo, Egypt.
#### S1
- 24 searches; 2/12 gained evidence. aram-village-2: an agency developer page names Falcon Group for the Aram Village projects → disputed with Sakar Group (Paya, AV1) and Dutch Flower Co. (Aram Garden); developer left empty. Another page suggests the 662-villa / 345,745 m² plan is Aram Village 1. aura-erbil: architect page confirms 40,000 m² shopping and 12 residential buildings.
- bajgir-city: possible lead to gazetteer place "Bajêr" (~70 km NE of Erbil), unlinked.
#### S4
- 23 searches; 3/12 gained evidence (summaries). hana-city → partially_verified (2023 governorate road notice, 2017 REACH map). harsham-city-2/-3: UNHCR map summary lists Harsham_2/_3 (not reproduced); harsham-city-3 kept unverified.
- hiran-city-1/-2 may relate to Hiran sub-district of Shaqlawa (~36.28N, 44.49E), unlinked; hiran-city-2 still a likely duplicate. "Harsham" results mostly about the displaced-persons camp (different site).
#### S8
- 16 searches; 2/8 gained evidence. zerin-city → verified (2021 flood coverage by Kurdistan24, Rudaw, Esta; 2023 Iraqi Geological Journal study); ~17 km from centre but described by the governor as part of the city. zilan-city: 2017 REACH/IMPACT Erbil City map lists it; road still disputed.
#### S3
- 24 searches; 2/12 gained evidence. empire-business-towers → verified: SkyscraperPage (tower B4 finished 2019, developer Empire World), Roya page (27/23 floors, 6 buildings), news on leasing (snippets); developer "Empire World" (low) disputed vs Wikipedia's BCM Holding citation. ganjan-life: OpenSooq ads confirm name/apartments, add Building L (16th-floor 158.48 m²); bedrooms 2 vs 3 logged as conflict.
- Same-name Baghdad projects exist for Future City (Modon/Al Handal) and Golden Tower (Golden Towers 1–4) — noted to avoid confusion.
#### S2
- 24 searches; 3/12 gained evidence. dashty-bahasht → partially_verified (medium): Rudaw Sorani news (summaries) — eastern Erbil, houses/villas/apartments, licensed 2010, due 2013, left unfinished by its investor, restart with a new investor announced January 2026; 427 villas + 1,122 apartments vs agency 500 units (conflict, units null). davos-city: extra Roya page (low). downtown-erbil: 2013 launch coverage confirms Emaar, 541,000 m², twin towers, three-stage plan; no later progress found → status still disputed; NGC = Nasri Group of Companies.


## 9. Access & compliance notes

* All five primary sites were reachable on 2026-11-07 after the session's network allow-list was extended; no site was fully inaccessible. baghyshaqlawa.net `/ku` pages and some `/en` requests returned a JavaScript challenge page; English/Arabic pages were used.
* robots.txt: homele.com disallows query-string URLs (not used); baghyshaqlawa.net Crawl-delay 10 (respected); hajisalam.com Crawl-delay 60 (respected); miranestate.com Content-Signal `search=yes, ai-train=no, use=reference` (content used for reference only).
