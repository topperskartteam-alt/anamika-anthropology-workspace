# Build Report — VAID Anthropology Glossary

Covers Round 3 (v0.4.0 pilot) and Round 3.1 (v0.4.1 red-team repair).
Repository-only work throughout. Nothing was deployed to, or changed on,
any live WordPress site at any point.

---

# Round 3.1 — Red-Team Audit + Bounded Repair (v0.4.1)

## v0.4.0 RED-TEAM VERDICT

The v0.4.0 pilot was **not production-safe as shipped**. Two findings
were genuine security exposure (anonymous REST read access; a duplicate
guard that still wrote a duplicate row), one was silent data corruption
(formula-injection prefixing applied to imported content, not just
exported), and the rest were integrity/consistency/waste issues rather
than security holes. All are repaired in v0.4.1 below. This audit
reviewed the actual code, not the v0.4.0 build report's claims —
several v0.4.0 claims did not hold up (see findings 2, 3, 5).

## Findings table

| # | Severity | Evidence (file:method) | Repair |
|---|---|---|---|
| 1 | Low | `vaid-anthropology-glossary.php`: `activate_plugin()` and `deactivate_plugin()` both called `flush_rewrite_rules()`, but `CPT::register_post_type()`/`register_taxonomy()` register `rewrite => false`, `has_archive => false` everywhere — no rewrite rule exists for this plugin to flush. | Removed both calls entirely. |
| 2 | **High** | `class-cpt.php::register_post_type()`: `show_in_rest => true` with `rest_base => 'vaid-glossary-terms'`. WordPress's default `WP_REST_Posts_Controller::get_items_permissions_check()` does not require any capability for anonymous GET requests against published content of a `show_in_rest => true` post type, regardless of `public => false`. Anonymous users could read `/wp-json/wp/v2/vaid-glossary-terms` and every published term's title/content, contradicting the "one canonical Hub" goal. | `show_in_rest => false` on the CPT, the `vaid_glossary_topic` taxonomy, and all six registered meta fields (`class-meta-fields.php`). Confirmed nothing in the admin UI (classic `$_POST` metabox, no Gutenberg/REST dependency) needed it. |
| 3 | **High** | `class-duplicate-guard.php::block_exact_duplicate_on_save()` (v0.4.0): on an exact-title collision it set `$data['post_status'] = 'draft'` and returned — `wp_insert_post()` still proceeded to insert that duplicate row, just as a draft. The v0.4.0 build report itself already flagged this as a known limitation. Not a true "HARD BLOCK" per the product rule. | Now calls `wp_die()` (400 response, back-link) before the DB write completes, for the primary interactive (non-AJAX, non-REST) save path — genuinely zero duplicate rows created. AJAX/inline-edit paths (`wp_doing_ajax()`) keep the non-disruptive Draft-downgrade fallback, so Quick Edit/autosave flows are not corrupted (autosaves/revisions were already excluded pre-fix, since WordPress stores both as `post_type = 'revision'`, which fails this method's own type check regardless). |
| 4 | Medium | `class-csv-import.php::handle_dry_run()` (v0.4.0): no file-extension or MIME check before `fopen()`/`fgetcsv()` parsing — any uploaded file type was accepted and blindly parsed as CSV. | Added `validate_uploaded_file()` using `wp_check_filetype_and_ext()` (content-sniffs + extension-checks), rejecting non-CSV uploads with a clear error before parsing. |
| 5 | **High** | `functions.php::vaid_glossary_sanitize_csv_cell()` (v0.4.0) was called from BOTH `CSV_Import::parse_csv()` (import) AND `CSV_Export::handle_export()` (export). Applying the leading-apostrophe formula-injection guard on **import** permanently mutated legitimate stored WordPress data — e.g. a term titled "-5 degree adaptation" or a definition starting with "@" would be saved to the database with a stray leading apostrophe baked in forever. This is exactly the "do not silently destroy legitimate content" failure mode the brief warned about. | Removed the call from the import path entirely (`parse_csv()` now only does tag-stripping/`sanitize_textarea_field`). Kept it, unchanged, on the export path — the correct place per OWASP CSV-injection guidance, since the risk only exists when a human later opens an exported CSV in a spreadsheet app. |
| 6 | Medium | `class-csv-import.php::handle_confirm()` (v0.4.0): when `wp_insert_post()` returned a `WP_Error` for a row, the loop did `continue` without incrementing any counter — that row vanished from both the "created" and "skipped" totals, silently undercounting what was reported to the admin. | Added a `$failed` counter, incremented on `is_wp_error( $post_id )`, included in the completion notice ("N created, N skipped, N failed to insert"). |
| 7 | Low | `class-csv-import.php`: `check_admin_referer( self::NONCE_CONFIRM )` and `check_admin_referer( self::NONCE_ROLLBACK )` used a fixed action string, not bound to the specific `vaid_token` / `batch_id` being acted on — a valid nonce for one pending report/batch was technically valid for confirming/rolling back a different one (practical exploitability is low: import capability is Administrator-only, and tokens/batch IDs have meaningful entropy, but this is cheap to close properly). | Nonce actions now include the token/batch ID: `NONCE_CONFIRM . '_' . $token`, `NONCE_ROLLBACK . '_' . $batch_id`, on both the form field and the `check_admin_referer()` call. |
| 8 | Medium | `class-hub-page.php::inject_on_assigned_page()` (priority 10 on `the_content`) and `class-hub-shortcode.php` (expanded by core's `do_shortcode` at priority 11 on `the_content`) both funnel into `Hub_Renderer::render()` with no de-duplication guard. If an admin assigned a page as the Hub in Settings AND that same page's body also contained the literal `[vaid_glossary_hub]` shortcode text, the Hub — and its `Breadcrumb::render()` call — rendered twice on one page. This also directly answers red-team item 7 ("verify code cannot output two breadcrumbs"): it could, transitively, through this same bug. | Added a static one-render-per-request guard to `Hub_Renderer::render()`; the second call in the same request now returns an empty string. |
| 9 | Low | `templates/partials/term-card.php` (v0.4.0) emitted `data-title`, `data-aliases`, and `data-definition` attributes in addition to `data-haystack`/`data-letter` — confirmed by grep that `assets/js/glossary-search.js` only ever reads `data-haystack` and `data-letter`. The other three were pure dead weight, roughly doubling each card's per-instance payload for zero functional benefit, directly relevant to the scale-sanity item. | Removed the three unused attributes. |
| 10 | Low | `functions.php::vaid_glossary_normalize_title()` and `templates/partials/term-card.php` both used plain `strtolower()`, which is byte-based and does not lowercase non-ASCII characters — an anthropology term like "Lévi-Strauss" would not case-fold consistently with JavaScript's Unicode-aware `String.toLowerCase()` used in the search script, producing non-deterministic case-insensitive matching for accented titles. | Added `vaid_glossary_mb_strtolower()` (uses `mb_strtolower(..., 'UTF-8')` with a graceful fallback) and switched both call sites to it. |
| 11 | None (verified, not a defect) | Items audited with no proven issue: capability + nonce checks on export/rollback (already present); dry-run writing zero DB records (confirmed — no `wp_insert_post`/`wp_insert_term` call anywhere in `handle_dry_run()`); duplicate validation against DB and within-CSV (`Duplicate_Guard::evaluate_import_row()`, already correct); no silent overwrite (import only ever calls `wp_insert_post` to create, never `wp_update_post` on an existing ID); rollback cannot touch pre-existing records (`Import_Batch_Log::rollback()` only acts on IDs it logged at creation time, and uses `wp_trash_post()`, never a hard delete); A-Z letter count (`Hub_Renderer::az_letters()` still returns exactly `range('A','Z')` — the 27th Figma cell remains unverified, so no "All" cell was added); client-side search makes no network call (confirmed by reading `glossary-search.js` — DOM-only); no VPE/PYQ, theme, or WPCode files touched anywhere in this plugin; no secrets/PII; no outbound network calls. | No code change. |

## Exact files changed (v0.4.0 → v0.4.1)

```
 vaid-anthropology-glossary/includes/class-cpt.php             | 23 +--
 vaid-anthropology-glossary/includes/class-csv-import.php      | 89 ++++++++--
 vaid-anthropology-glossary/includes/class-duplicate-guard.php | 64 +++++--
 vaid-anthropology-glossary/includes/class-hub-renderer.php    | 26 ++-
 vaid-anthropology-glossary/includes/class-meta-fields.php     | 42 ++---
 vaid-anthropology-glossary/includes/functions.php             | 47 ++++--
 vaid-anthropology-glossary/readme.txt                         |  2 +-
 vaid-anthropology-glossary/templates/partials/hero.php        |  1 +
 vaid-anthropology-glossary/templates/partials/term-card.php   | 21 ++-
 vaid-anthropology-glossary/vaid-anthropology-glossary.php     | 30 ++--
 10 files changed, 261 insertions(+), 84 deletions(-)
```
Plus `CHANGELOG.md`, `QA-CHECKLIST.md`, this file, and
`vaid-anthropology-glossary-0.4.1.zip` at the repo root. No file outside
`vaid-anthropology-glossary/` (and the round's own doc/zip artifacts) was
touched. No VPE/PYQ path, theme path, or WPCode-related file exists in
this diff.

## Tests run and results

| Check | Result |
|---|---|
| `php -l` on all 23 PHP files | All pass |
| `node --check` on the frontend JS | Pass (unchanged this round) |
| Grep for `ANAMIKA` (plugin folder + inside the v0.4.1 ZIP) | Zero matches |
| Grep for `flush_rewrite_rules(` | Zero live calls (two doc-comment mentions only) |
| Grep for outbound network APIs (`wp_remote_*`, `curl_init`, `file_get_contents('http...')`) | Zero matches |
| Grep for `show_in_rest.*true` | Zero live occurrences (two doc-comment mentions only) |
| ZIP: single top-level `vaid-anthropology-glossary/` folder | Confirmed via `unzip -Z1` |
| ZIP integrity (`unzip -t`) | No errors |
| Synthetic scale sanity check (see below) | 100/500/1000-term HTML payload measured directly from the real (trimmed) card template |

### Scale/load sanity check (offline, synthetic — Item 9)

Rendered the actual (post-fix) `term-card.php` markup pattern for
synthetic term sets and measured real byte output:

| Terms | Total card HTML | Avg/card |
|---|---|---|
| 100 | ~75 KB | ~768 bytes |
| 500 | ~375 KB | ~768 bytes |
| 1,000 | ~750 KB | ~768 bytes |

Assessment: 100 and 500 terms are unremarkable — comparable to a few
unoptimized images, no DOM-size concern (500 terms ≈ 2,500–3,000 DOM
nodes). 1,000 terms (≈750 KB of card markup, ≈5,000–6,000 DOM nodes) is
the point where this becomes the heaviest page on the site and is worth
watching, but is **not materially unsafe**: search itself stays cheap
(substring matching over ~1,000 short haystacks, debounced 250ms, is
low-single-digit milliseconds even on modest hardware), and no browser
crash/freeze risk is expected. Per the brief, no pagination/virtualized
loading UI was added — if term counts are expected to approach or exceed
~1,000 in practice, a future data/loading strategy (e.g. paginated
fetch, or a lighter search index instead of full-DOM data attributes)
should be planned before then, but that is out of scope for this bounded
repair.

## Remaining production blockers

1. **`FIGMA SEARCH ICON = PENDING EXACT ASSET`** (Item 10) — the search
   field icon is a substitute inline SVG, not Figma's actual asset. This
   remains an explicit, labeled production-fidelity blocker until the
   real asset is retrieved or an owner-approved equivalent is confirmed.
2. **A-Z 27th cell still unverified** — the Figma rate limit from Round
   1/3 was never re-attempted this round (out of scope for a code-only
   red-team pass); 26 letters ship, no "All" cell invented.
3. **Yoast breadcrumb path is code-verified only, not live-verified** —
   `Breadcrumb::render()`'s Yoast-vs-fallback branching is correct by
   inspection (confirmed: it can never emit both), but which branch
   actually fires, and whether Yoast's own breadcrumb text reads "Home /
   Glossary" on the real Hub page, depends on live theme/Yoast
   configuration this repository cannot exercise. QA-CHECKLIST.md item
   11 covers this for the real install.
4. **No PHPCS/WPCS run** — this environment still has no WordPress
   Coding Standards tooling installed; only `php -l` (syntax) and manual
   review were possible.
5. **Duplicate-title save UX for AJAX/Quick Edit paths** still falls
   back to the older Draft-downgrade behavior rather than a hard stop
   (deliberately, to avoid corrupting those flows — see finding 3) — an
   editor using Quick Edit to rename a term into a collision will not
   get a hard block, only a silently-drafted duplicate and a notice on
   next page load. Acceptable for a pilot; worth tightening later if
   Quick Edit renames turn out to be a real workflow.

## Is v0.4.1 safe for a controlled WP Admin pilot installation?

**Yes, conditionally.** The two genuine security-exposure findings
(anonymous REST read access; duplicate rows surviving the "hard block")
and the silent data-corruption finding (formula-guard on import) are all
repaired and verified by static analysis in this repository. This is
still **not** a full production sign-off: nothing here has run against
an actual WordPress database, so `dbDelta()` table creation, real
capability/nonce round-trips, and the live Yoast breadcrumb path are all
still unverified beyond code inspection. Follow QA-CHECKLIST.md
(including the new "Round 3.1 red-team repairs" section) end-to-end on a
staging install — not live — before considering this beyond "controlled
pilot."

## Branch + commit + ZIP path

- Branch: `claude/fervent-gates-s5nzlf` (working branch; not merged to
  main).
- Commit: created after this report — see the commit immediately
  following this file's addition in `git log`.
- ZIP: `vaid-anthropology-glossary-0.4.1.zip` at the repository root.

---

# Round 3 — Core Pilot Build (v0.4.0)

Repository-only build. Nothing was deployed to, or changed on, any live
WordPress site. No VPE/PYQ files or the live theme were touched. No
Figma content was modified (two read-only Figma MCP calls were made
during this round to resolve open questions from Round 1/2; both are
logged below).

## Files created

```
vaid-anthropology-glossary/
├── vaid-anthropology-glossary.php        (bootstrap, constants, autoloader, activation/deactivation)
├── uninstall.php                          (non-destructive no-op)
├── readme.txt                             (WordPress.org-style plugin readme)
├── includes/
│   ├── functions.php                      (vaid_glossary_ procedural helpers)
│   ├── class-capabilities.php
│   ├── class-cpt.php                      (vaid_glossary_term CPT + vaid_glossary_topic taxonomy)
│   ├── class-meta-fields.php              (short_definition, aliases, first_letter, future-ready fields)
│   ├── class-duplicate-guard.php          (shared save + import duplicate governance)
│   ├── class-admin-menu.php               (Glossary menu + Settings/Hub-page-assignment screen)
│   ├── class-admin-list-table.php         (Letter/Aliases columns, alias-aware admin search)
│   ├── class-admin-metabox.php            (Add/Edit Term metabox)
│   ├── class-admin-notices.php            (Hub-not-assigned notice)
│   ├── class-csv-export.php
│   ├── class-csv-import.php               (dry-run, confirm, batch commit, rollback)
│   ├── class-import-batch-log.php         (custom table for batch tracking/rollback)
│   ├── class-hub-page.php                 (renders Hub on the admin-assigned page)
│   ├── class-hub-shortcode.php            ([vaid_glossary_hub])
│   ├── class-hub-renderer.php             (shared data fetch + template render)
│   ├── class-breadcrumb.php               (Yoast-or-fallback adapter)
│   └── class-frontend-assets.php          (conditional CSS/JS enqueue)
├── templates/
│   ├── hub.php
│   └── partials/
│       ├── hero.php
│       ├── az-nav.php
│       └── term-card.php
└── assets/
    ├── css/glossary.css
    └── js/glossary-search.js

vaid-anthropology-glossary-0.4.0.zip       (packaged build)
README.md, CHANGELOG.md, QA-CHECKLIST.md, BUILD-REPORT.md   (repo root)
```

33 files inside the plugin folder; 41,340-byte ZIP.

## Architecture deviations from Round 2, and why

All of these are the Round-3 control-room corrections applied as
instructed — listed here for traceability, not because they were
independently chosen:

1. **No REST search endpoint.** Round 2 proposed `/wp-json/vaid-glossary/v1/search`.
   Round 3 replaced this with pure client-side filtering over
   server-rendered `data-*` attributes on each card
   (`templates/partials/term-card.php`, `assets/js/glossary-search.js`).
   No network call happens while typing. The REST endpoint's absence is
   intentional, not an oversight — `class-cpt.php` still sets
   `show_in_rest => true` on the CPT so a REST-backed search (or any
   other future integration) can be added later without a data
   migration, per the brief's explicit forward-compatibility instruction.
2. **CSV import rollback mechanism added**, beyond what Round 2 fully
   specified: `class-import-batch-log.php` introduces one small custom
   database table (`{prefix}vaid_glossary_import_batch_items`) purely to
   track which posts each import batch created, so a batch can be
   rolled back (trashed, never hard-deleted) without touching any
   pre-existing term. This does not reopen the CPT-vs-table decision for
   the terms themselves — terms are still a CPT; only batch membership
   bookkeeping uses a table, because that is inherently relational data
   a CPT/postmeta model handles awkwardly.
3. **CSS breakpoints are fluid, not fixed at Figma frame widths.** Per
   the correction, `assets/css/glossary.css` uses a `clamp()`-based
   fluid gutter and `grid-template-columns: repeat(auto-fit,
   minmax(320px, 1fr))` for the card grid, instead of hard `@media`
   breakpoints at 402px/1440px. This transitions smoothly across every
   width in the QA matrix without treating any one of them as a special
   case.

## Two Figma reads performed this round (read-only, logged for
   traceability)

- Re-confirmed `get_libraries` / component-set lookups from Round 2 were
  not repeated (already exhausted).
- One new call attempted: `get_design_context` on node `365:4392` (the
  27th A-Z Tab Item cell on Glossary /D), specifically to resolve
  whether that cell is labeled "All" before deciding the A-Z nav's cell
  count, per the brief's explicit instruction ("do not guess... inspect
  existing instance labels first"). **This call was blocked by the
  Figma MCP Starter-plan rate limit** (same limit hit in Round 1). No
  Figma content was changed by the attempt.
- Because that lookup could not complete, `Hub_Renderer::az_letters()`
  renders exactly the 26 unambiguous A-Z letters and does **not**
  render a 27th "All" cell — per the instruction not to guess. This is
  flagged as an owner decision below, not silently resolved either way.

## Checks run and results (this repository, no live WordPress available)

| Check | Result |
|---|---|
| `php -l` on all 23 PHP files | All pass, zero syntax errors |
| `node --check` on the frontend JS file | Pass |
| Grep for `ANAMIKA` (case-insensitive) across the plugin folder and inside the packaged ZIP | Zero matches |
| Grep for `flush_rewrite_rules()` | Only present inside `activate_plugin()` / `deactivate_plugin()`, not on any frontend request path |
| Grep for outbound network calls (`wp_remote_*`, `curl_init`, `file_get_contents('http...')`) | Zero matches |
| Manual review of every raw `$wpdb->` call | All use `$wpdb->prepare()`, `esc_like()`, or the array-based `insert()`/`delete()` helpers — no string-concatenated SQL |
| CPT visibility flags vs. brief's exact spec | Matches exactly (`public`, `publicly_queryable`, `has_archive`, `rewrite` all `false`; `show_ui`, `show_in_menu`\*, `show_in_rest` `true`; `exclude_from_search` `true`) — \*`show_in_menu` is `false` on `register_post_type` itself because the screen is instead attached to a custom top-level "Glossary" menu in `class-admin-menu.php`; the CPT screen is still reachable exactly as the brief intends |
| ZIP structure: single top-level `vaid-anthropology-glossary/` folder | Confirmed via `unzip -Z1` |
| ZIP integrity | `unzip -t` reports no errors |

Not run (require a live WordPress + MySQL environment this repository
does not have): plugin activation, admin screen rendering, CSV
import/export against a real database, `dbDelta()` table creation,
WPCS/PHPCS coding-standard linting (not installed in this environment),
JS accessibility testing with a real screen reader.

## Known limitations

1. **A-Z nav cell count** — ships with 26 letters (A-Z), not 27, because
   the 27th Figma cell's label could not be verified (Figma rate limit).
   See owner decision below.
2. **Search icon is a substitute inline SVG**, not the actual Figma
   asset (`baf7a.svg`) — that asset was never downloaded to a permanent
   local file in Round 1 (only a temporary 7-day Figma CDN URL was
   returned), and design-to-code guidance explicitly forbids leaving a
   temporary Figma asset URL in shipped code. The substitute is a plain
   magnifying-glass icon, not pixel-identical to Figma's icon.
3. **Admin alias-search SQL** (`class-admin-list-table.php::alias_search()`)
   works by appending an `OR post ID IN (...)` clause onto WordPress
   core's own generated search SQL fragment via the `posts_search`
   filter. This was chosen over hand-parsing `posts_where` (more
   fragile) but still depends on the shape of core's search SQL not
   changing; it should be exercised against the target WordPress
   version during QA (checklist item 2) before being trusted in
   production.
4. **Duplicate-title save guard** silently reverts a duplicate-titled
   save to Draft status rather than fully blocking the save action
   outright (WordPress's classic/block editor does not provide a clean
   way to hard-reject a save from `wp_insert_post_data` without a
   confusing UX) — functionally it prevents a duplicate from ever
   reaching `publish`, but an editor could still end up with an
   unpublished duplicate draft sitting in the list. Flagged, not
   silently perfect.
5. **Breadcrumb Yoast integration is untested against a real Yoast
   installation** — the brief explicitly allows this ("do not require
   this to be live-verified... make it testable/configurable"); the
   `vaid_glossary_use_yoast_breadcrumb` filter exists specifically so
   this can be verified or overridden during real QA.
6. **No PHPCS/WPCS run** — this environment does not have the WordPress
   Coding Standards ruleset installed; only `php -l` (syntax) was run.
   Recommend running PHPCS with `WordPress-Extra` before any production
   release.

## WordPress installation / test steps

1. Copy `vaid-anthropology-glossary-0.4.0.zip` to a WordPress
   **staging/test** site only (per the guardrails: no live deployment
   from this round).
2. In WP Admin → Plugins → Add New → Upload Plugin, upload the ZIP,
   then Activate.
3. Confirm no PHP notices/warnings appear (enable `WP_DEBUG` first).
4. Go to Glossary → Settings and assign an existing page as the
   Glossary Hub (the plugin will not auto-create or publish anything).
5. Go to Glossary → Add New and create 2-3 test terms with distinct
   titles, short definitions, and a couple of aliases.
6. Visit the assigned Hub page on the frontend and confirm hero, search,
   A-Z nav, and cards render.
7. Work through `QA-CHECKLIST.md` in full before considering this build
   further along than "pilot."

## Next recommended version

**v0.5.0** — SEO/schema/performance pass per the Round-2 build plan:
verify/finish the Yoast breadcrumb integration against a real
installation, add the transient-based Hub term-list caching described
in the Round-2 architecture (not yet implemented in this pilot —
`Hub_Renderer::get_published_terms()` currently queries fresh on every
request, acceptable at pilot scale but worth caching before wider
rollout), confirm sitemap/canonical behavior live, and resolve the two
flagged owner decisions below so v0.5.0 can close them out rather than
carry them forward again.

## Remaining owner decisions

1. **A-Z 27th cell** — still unresolved; the Figma rate limit blocked
   the one remaining check needed to confirm or rule out an "All" cell.
   Either re-run that single Figma lookup when quota resets, or the
   owner can simply confirm/deny an "All" cell directly by looking at
   the Figma file, and this ships in v0.5.0.
2. **Duplicate-title save UX** — confirm whether reverting to Draft
   (current behavior) is acceptable, or whether a harder block (e.g. a
   JS confirmation dialog before submit) is wanted instead.
3. **Hub term-list caching** — confirm whether v0.4.0's pilot scale
   (expected low hundreds of terms) makes the Round-2-proposed transient
   cache unnecessary for now, or whether it should be pulled forward
   into this version rather than deferred to v0.5.0.

`VAID GLOSSARY v0.4.0 PILOT BUILD = READY FOR CONTROL ROOM REVIEW`
