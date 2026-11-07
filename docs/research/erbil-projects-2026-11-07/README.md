# Andaz — Erbil City Real-Estate Project Research (2026-11-07)

This folder is the complete delivery of the **Andaz AI Real Estate Project Research & Verification** run for
Erbil city (Hawler), Kurdistan Region, Iraq. It contains one verified, trilingual (Sorani Kurdish / Arabic /
English) profile per discovered project plus a single import-ready JSON array.

| File | Content |
|---|---|
| `INDEX.md` | Table of every profile with Kurdish/Arabic names, developer, category, status, confidence and city-scope flag |
| `projects/<project-id>.md` | Full profile per project: Parts A–H (data sheet, unit types & sizes, facilities, prices, location, trilingual descriptions, research log, verification report) |
| `andaz_erbil_projects.json` | One JSON array; one object per project (Part G schema) — import-ready for the Andaz Projects module |
| `RESEARCH_LOG.md` | Site-by-site discovery log, coverage statistics, dedup/scope decisions and known limitations |
| `canonical_projects.json` | The canonical identity map used to merge duplicate names across the five primary sites |

## Method (what was actually done)
1. **Discovery on the five mandatory sites, in the required order** (all reachable on 2026-11-07):
   homele.com (project sitemap: 11 Erbil project pages; area sitemap: 179 project-like Erbil listing areas read),
   paya-realestate.com (project sitemap: 107 Erbil project pages read), baghyshaqlawa.net (Erbil project list, 10 pages:
   110 project pages read), hajisalam.com (projects pages 1–7: 62 project pages read at the site's 60-second crawl delay),
   miranestate.com (sitemap: 53 project pages read, 48 in Erbil).
2. **Secondary sources.** roya-iq.com (developer directory and 9 Erbil project cards; its project detail pages returned
   "Project Not Found"), b-arrow.com (9 project pages), en.wikipedia.org (Aura Erbil, Downtown Erbil, English Village,
   tallest-buildings list), and web search for discovery and for developer, status and location checks.
3. **Consolidation.** 235 raw names were normalised and merged into **206 canonical entries** (Tier 1: a project page on at
   least one primary site; Tier 2: known only from listing areas or secondary sources). Phases and sub-projects are kept
   distinct and linked by `parent_project_id` where a parent exists (Empire World, Floria City, Noble City).
4. **Per-project research and writing.** Research agents worked from an evidence pack per project containing every
   fetched record with its URL, applied the evidence-priority and conflict rules, and wrote one structured JSON file per
   project, including the Sorani, Arabic and English descriptions. Most Markdown reports are rendered from that JSON by a
   script, so report and import data cannot drift apart; 13 early reports were written directly by agents in the same layout.
5. **Coordinates.** Three primary sites publish map pins: Paya (schema.org geo), homele (project map) and Baghy Shaqlawa
   (Google Maps marker). A pin is used only if it lies inside Erbil and is not shared with another project (placeholder
   check). Confidence: High = three pins within 1.5 km, Medium = two, Low = a single pin, Disputed = pins disagree
   (coordinates left null). See `coordinates_confidence` in the JSON.
6. **QA.** Every file was checked for required keys, enum values, numeric types, source URLs, Sorani-specific letters in
   `description_ku`, Arabic script leaking into `description_en`, and description length. Figures an agent calculated
   from stated values (for example 28 buildings × 32 flats + 643 villas) are listed in `derived_fields`; figures
   described as estimates are removed from the import data.

## Scope decisions
* "Erbil city" = the municipal urban area incl. its ring-road belt (60m/100m/120m/150m roads), Ankawa, New Erbil
  (Hawler Nwe), Kasnazan Road, Pirmam/Massif Road, Bahrka Road and Kirkuk Road approaches. Projects whose evidence
  places them in Shaqlawa, Soran, Koya, Duhok, Sulaymaniyah, etc. are flagged `in_erbil_city: false` (Paya's
  "Chiwa Tower", Shaqlawa, was excluded at discovery).
* Pure commercial projects found on the project directories (CBC, Avenue Square, AB Center, Ezma Business Tower,
  Voyage Mall, FM Plaza) are included and categorised `commercial`.

## Limitations (read before importing)
* **Wider-web verification was limited.** The session's web-search budget (200 searches per turn, shared by all agents)
  ran out during the first research pass, and the page-fetch tool could not reach hosts outside the five primary sites.
  In the final pass each agent used at most one search per project. Every profile states this in Part H item 6, and
  `verification_status` is `verified` only where two independent primary sites agree on name, location, category and
  status. A second pass on the projects listed in `RESEARCH_LOG.md` is recommended.
* **Agency data, not developer documents.** No developer website could be opened. Facts labelled developer/sales-centre
  come from sales-centre listings on the primary sites; news and search-result facts are marked as such.
* **Status inferred from listings.** Where no project page states a status, agents inferred it from listing tags
  ("Ready", "Off Plan"); the data sheet says so.
* **Historic listings.** hajisalam.com listings date mostly from 2019–2020 and are treated as dated market evidence.
* **Bot protection.** baghyshaqlawa.net and hajisalam.com sometimes serve a "One moment, please…" challenge page;
  affected pages were retried until real content was received.
* **Prices** are dated to the evidence and typed (developer / resale / rent). They are not official current price lists.

## Import mapping to this repository's `projects` table
`andaz_erbil_projects.json` is research data, not a seeder. Import every record as `publication_status = draft`
with `confidence` taken from `confidence_overall`, and let an editor review before publishing.

| Research JSON field | `projects` column (app/Modules/Projects) | Note |
|---|---|---|
| `project_id` | `external_id` and `slug` | stable kebab-case id |
| `project_name_ku` / `project_name_ar` / `project_name` | `name_ckb` / `name_ar` / `name_en` | `name_ckb` is required: fall back to `project_name` where `project_name_ku` is null |
| `alternative_names` | `aliases` (json) | |
| `description_ku` / `description_ar` / `description_en` | `description_ckb` / `description_ar` / `description_en` | |
| `project_category` | `project_type` | `residential`→`residential`, `commercial`→`commercial`, `mixed-use`→`mixed_use`, `other`→ choose manually |
| `construction_status` | `construction_status` | `completed`→`completed`, `under_construction`→`under_construction`, `planned`→`planning`, `unknown`/`disputed`→ editor decision |
| — | `delivery_status` | required column not covered by research: suggest `delivered` for completed projects, `scheduled` when `completion_date` is set, otherwise `not_started` |
| `latitude` / `longitude` | `latitude` / `longitude` | null for most projects by design (no unsourced coordinates) |
| `launch_date` / `completion_date` | `launch_date` / `expected_delivery` or `actual_delivery` | free-text dates must be normalised to `YYYY-MM-DD` |
| `project_total_area_m2` | `land_area_sqm` | only when `project_total_area_source` is set |
| `number_of_buildings` / `total_units` | `building_count` / `unit_count` | |
| `verified_facilities` (+ `planned_facilities`) | `facilities` (json) | keep planned items flagged |
| `prices` (developer rows) | `payment_plans` (json) / `project_prices` table | resale/rent rows are market evidence, not developer prices |
| `developer` | `developer_id` → `developers.name_en` | create or match developer records by name |
| `sources` | `data_sources` (json) | `source` = first primary-site URL |
| `parent_project_id` | — | sub-project link (Empire World, Floria City, Noble City); no column exists yet |
