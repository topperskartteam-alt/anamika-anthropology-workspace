# Changelog

All notable changes to the VAID Anthropology Glossary plugin.

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
