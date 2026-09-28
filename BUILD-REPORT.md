# Build Report — VAID Anthropology Glossary v0.4.0 (Round 3 Pilot)

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
