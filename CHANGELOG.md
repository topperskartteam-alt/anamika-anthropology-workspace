# Changelog

All notable changes to the VAID Anthropology Glossary plugin.

## [0.4.1] — Round 3.1 red-team repair

Repository-only bounded repair following a code-level red-team audit of
the actual v0.4.0 implementation. No new features, no redesign — every
change below repairs a proven issue found in the audit. Full findings
table in BUILD-REPORT.md.

### Fixed
- **No unnecessary rewrite flushing.** Removed `flush_rewrite_rules()`
  from both activation and deactivation — the plugin registers no
  rewrite rule of any kind (`rewrite => false` everywhere), so there was
  nothing to justify flushing.
- **REST exposure closed.** `show_in_rest` set to `false` on the
  `vaid_glossary_term` CPT, the `vaid_glossary_topic` taxonomy, and all
  registered post meta. v0.4.0's `show_in_rest => true` let anonymous
  users read every published term (and its meta) via
  `/wp-json/wp/v2/vaid-glossary-terms` — WordPress's default REST
  controller does not gate this on the `public` argument. Nothing in the
  plugin's admin UI depended on REST.
- **Genuine hard block on exact-title duplicates.** The classic Add/Edit
  Term save path now calls `wp_die()` before the database write when a
  duplicate is detected — zero duplicate row is created — instead of
  v0.4.0's behavior of still inserting the duplicate as a Draft.
  AJAX/inline-edit paths keep the non-disruptive Draft-downgrade
  fallback to avoid corrupting Quick Edit/autosave flows.
- **CSV import: file type validated** before parsing
  (`wp_check_filetype_and_ext()`), closing the "no MIME/extension
  handling" gap.
- **CSV formula-injection mitigation moved to export-only.** v0.4.0
  applied the leading-apostrophe guard on *import*, permanently
  corrupting legitimate stored content that happened to start with `-`,
  `+`, `@`, or `=` (e.g. "-5 degree adaptation"). It now only runs when
  generating a CSV for a human to open in a spreadsheet app.
- **CSV import failure accounting fixed.** A row whose `wp_insert_post()`
  call failed was previously silently dropped from both the "created"
  and "skipped" counts. Now counted and shown in the completion notice.
- **Import Confirm/Rollback nonces bound to their specific token/batch
  ID**, not just a generic action name.
- **Hub double-render fixed.** If a page both is assigned as the Hub
  (Settings) and separately contains the `[vaid_glossary_hub]`
  shortcode, the Hub — and its breadcrumb — rendered twice. `Hub_Renderer`
  now guards against rendering more than once per request.
- **Diacritic-consistent search/duplicate matching.** Replaced
  byte-based `strtolower()` with Unicode-aware `mb_strtolower()` for the
  search haystack and duplicate-title normalization, matching
  JavaScript's `String.toLowerCase()` behavior for accented terms (e.g.
  "Lévi-Strauss").
- **Removed unused `data-title` / `data-aliases` / `data-definition`**
  attributes from each term card — the search script only ever read
  `data-haystack` and `data-letter`; the other three roughly doubled
  each card's markup for no functional benefit.
- **Search icon fidelity explicitly labeled** `FIGMA SEARCH ICON =
  PENDING EXACT ASSET` in the template — a standing production blocker,
  not resolved this round.

### Not changed (verified, no proven issue)
Capability/nonce checks across CSV import/export/rollback; dry-run
writing zero records; duplicate validation against DB and within-CSV;
no silent overwrite on import; rollback's inability to touch
pre-existing records; A-Z letter count (still 26, no invented "All");
client-side search having no network calls; header/footer/theme
inheritance. See BUILD-REPORT.md for evidence per item.

## [0.4.0] — Round 3 core pilot build

Initial in-repository pilot build. Covers architecture milestones v0.1
through v0.4 from the Round-2 build plan in a single pass, per the
Round-3 brief.

### Added
- Plugin scaffold: bootstrap file, constants, minimal manual autoloader,
  activation/deactivation hooks, version constant `0.4.0`.
- `vaid_glossary_term` custom post type — admin/CMS-only in this version
  (`public => false`, `publicly_queryable => false`, `has_archive =>
  false`, `rewrite => false`, `exclude_from_search => true`,
  `show_in_rest => true`).
- Future-ready, non-public `vaid_glossary_topic` taxonomy.
- Post meta: short definition, aliases, auto-derived first letter,
  future-ready related-term IDs / examples / sources (none rendered
  publicly in v0.4.0).
- Duplicate governance: hard block on exact-title collision (save and
  import), warn on alias-to-existing-title (import blocks the row),
  warn-only on alias-to-alias and fuzzy-title similarity.
- Admin UX: grouped "Glossary" menu, All Terms list with Letter/Aliases
  columns and alias-aware search, Add/Edit metabox with required Short
  Definition, CSV Export, CSV Import (dry-run report -> explicit Confirm
  Import -> batch-logged commit -> rollback-to-Trash action), Settings
  page to assign the Hub page.
- Frontend: Glossary Hub (hero, search field, A-Z navigation, term
  cards) rendered on an admin-assigned page or via the
  `[vaid_glossary_hub]` shortcode; inherits the active theme's
  header/footer. Breadcrumb adapter (Yoast if available, otherwise a
  single plugin fallback) rendering "Home / Glossary".
- Client-side search + A-Z filter (no network requests), keyboard
  accessible, ARIA-labelled, `prefers-reduced-motion` safe.
- Non-destructive uninstall (`uninstall.php` is a documented no-op).

### Not included in v0.4.0 (by design)
Filters, topic-filter UI, public term-detail pages, glossary-scoped
related-terms UI, pagination/load-more UI, illustrated empty-result
state, custom Glossary-specific header/footer. See `BUILD-REPORT.md`.
