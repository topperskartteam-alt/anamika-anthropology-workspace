# QA Checklist — VAID Anthropology Glossary v0.4.1

For manual execution on a real WordPress test/staging install. None of
this could be run inside the cloud repository build (no live WordPress
available there) — see BUILD-REPORT.md for what *was* verified in-repo.

## 0. Round 3.1 red-team repairs (v0.4.1 additions)
- [ ] `/wp-json/wp/v2/vaid-glossary-terms` (or any glossary REST route)
      returns 404/no route when requested anonymously — confirms REST
      exposure is actually closed, not just configured.
- [ ] Adding a term via the classic Add/Edit screen with a title that
      exactly matches an existing term (any case/whitespace variant)
      shows a WordPress die screen with a "go back" link, and confirms
      via the database that **no new post row was created** (check the
      All Terms count before/after).
- [ ] Quick Edit (inline, from the list table) on a term, renaming it to
      collide with another existing term's title, does NOT hard-crash
      the inline editor — confirms the AJAX-path fallback still works
      without corrupting the list-table UI.
- [ ] Uploading a non-CSV file (e.g. a renamed `.txt` or `.php` file) to
      Import CSV is rejected with a clear "must be a .csv file" error
      before any parsing happens.
- [ ] Importing a CSV containing a short_definition or title that starts
      with `-`, `+`, `@`, or `=` (e.g. "-5 degree adaptation") stores the
      value **exactly as entered**, with no stray leading apostrophe —
      confirms the formula-injection guard no longer corrupts import
      data. Exporting that same term SHOULD show the leading-apostrophe
      mitigation applied in the downloaded CSV.
- [ ] Assign a page as the Hub in Settings, then also paste
      `[vaid_glossary_hub]` into that same page's body — confirm only
      ONE Hub (and one breadcrumb) renders, not two.
- [ ] Add a term with an accented title (e.g. "Lévi-Strauss") and search
      for it using a lowercase, unaccented-adjacent query — confirm
      search still matches case-insensitively as expected.

## 1. Activation
- [ ] Plugin activates with no PHP notices/warnings/fatals (`WP_DEBUG` on).
- [ ] `Glossary` top-level admin menu appears with All Terms / Add New /
      Import CSV / Export CSV / Settings.
- [ ] Deactivate then reactivate — confirm all previously entered terms,
      options, and import batch log rows are still present (deactivation
      must not delete anything).

## 2. Admin CRUD
- [ ] Add New Term: Short Definition required (form blocks submit or
      shows validation without it — confirm actual WP behavior since
      HTML5 `required` alone may not be enough on all setups).
- [ ] Aliases save and reload correctly (comma-separated in, array out).
- [ ] First Letter field shows correctly after save and cannot be
      hand-edited.
- [ ] Bulk edit (status, topic) works on multiple selected terms.
- [ ] All Terms list shows Letter and Aliases columns; sorting by Letter
      works.
- [ ] Admin search box in All Terms finds a term by an alias, not just
      by title.

## 3. Duplicate governance
- [ ] Exact duplicate title (case-insensitive) is blocked on save
      (entry is force-saved as Draft, error notice shown, not published).
- [ ] CSV import: exact-title duplicate row is blocked, not overwritten.
- [ ] CSV import: alias exactly matching an existing term's title is
      blocked with a clear message.
- [ ] CSV import: alias matching another existing term's alias is
      flagged as a warning but still importable.
- [ ] CSV import: near-duplicate/similar title is flagged as a warning
      only, and does still import when confirmed.

## 4. CSV dry-run / import / rollback
- [ ] Uploading a CSV shows the dry-run report with no DB writes yet
      (confirm by checking All Terms count is unchanged before Confirm).
- [ ] Confirm Import creates only the OK/Warning rows; Blocked rows are
      skipped and reported as skipped.
- [ ] A batch ID is shown after import and appears in "Recent Import
      Batches".
- [ ] Rollback on that batch trashes exactly the terms it created and no
      others (spot-check a pre-existing term is untouched).
- [ ] Rolled-back terms appear in Trash (not permanently deleted) and can
      be restored.

## 5. Export / re-import round trip
- [ ] Export CSV downloads with columns: title, short_definition,
      aliases, topic, status.
- [ ] Re-importing the exported file (unmodified) reports every row as
      Blocked (exact-title duplicates) and creates zero new terms.
- [ ] Editing one row's title in the exported file before re-import
      creates exactly one new term.
- [ ] A cell starting with `=`, `+`, `-`, or `@` in the source data is
      neutralized (prefixed) in both the export and on re-import — open
      the CSV in a spreadsheet app and confirm it is not treated as a
      formula.

## 6. Hub assignment
- [ ] With no Hub page assigned: an admin notice appears on the Glossary
      screens; no page anywhere on the site shows the Hub automatically.
- [ ] Assign an existing page in Glossary → Settings; that page now
      renders the Hub inside the theme's normal header/footer.
- [ ] Alternative path: insert `[vaid_glossary_hub]` into a different
      page/post and confirm it renders identically.
- [ ] Confirm the plugin did not create or publish any page on its own
      at any point in this process.

## 7. Responsive layout (Figma frame widths are QA targets, not CSS
      breakpoints — confirm the fluid layout still reads correctly at
      each, not that a specific breakpoint fires)
- [ ] 1440px wide — hero, search, A-Z nav, multi-column card grid.
- [ ] 1366px wide — no horizontal overflow, grid still multi-column.
- [ ] 1024px wide — sensible column count, no overlap.
- [ ] 768px wide — sensible column count, A-Z nav usable (scrollable if
      needed).
- [ ] 402px wide — single-column card stack, hero left-aligned reads
      correctly, search field full-width.
- [ ] 390px wide and 360px wide — no clipped text, no horizontal page
      scroll.

## 8. Search
- [ ] Typing a term's exact title filters the grid to that card only.
- [ ] Typing an alias filters correctly (not shown on the card, but
      matches).
- [ ] Typing a phrase from the short definition matches.
- [ ] Debounce: rapid typing does not visibly flicker/lag; filtering
      settles ~150–300ms after the user stops typing.
- [ ] Clear button appears once text is entered, clears the field, and
      restores all cards.
- [ ] No network request fires while typing (check browser dev tools
      Network tab — confirm zero XHR/fetch calls from this feature).

## 9. A–Z navigation
- [ ] Clicking a letter with existing terms filters the grid to that
      letter's cards, and shows that tab as visually Active.
- [ ] Clicking the same active letter again deselects it and restores
      all cards.
- [ ] Clicking a letter with zero terms keeps that tab's Active visual
      and shows the minimal no-results text — confirm no separate
      "disabled" visual appears (none exists in the design).
- [ ] Search text and A–Z selection combine correctly (both filters
      apply together).

## 10. No-results
- [ ] Search with no matches shows the plain text "No terms found."
      (or the localized equivalent) — confirm no illustration/icon is
      shown, matching the "no custom empty-state design" scope decision.
- [ ] Zero published terms site-wide shows the empty Hub message
      correctly (not a PHP notice or blank space).

## 11. Yoast breadcrumb / fallback
- [ ] With Yoast SEO active and breadcrumbs enabled: confirm only ONE
      breadcrumb renders on the Hub page (no duplicate from the theme).
- [ ] Confirm it reads "Home / Glossary" (adjust the Hub page's Yoast
      breadcrumb title/parent setting if it does not, since Yoast's
      breadcrumb reflects page hierarchy/titles).
- [ ] With Yoast breadcrumbs unavailable/disabled: confirm the plugin's
      own fallback breadcrumb renders instead, also reading "Home /
      Glossary", and that it is not a second, duplicate breadcrumb.
- [ ] Confirm the breadcrumb never reads "Home / Interview Guidance" —
      the Figma content defect identified in Round 1 must not appear
      anywhere in the live output.

## 12. SEO / canonical / sitemap
- [ ] Hub page has normal, editable Yoast title/meta description.
- [ ] Hub page appears exactly once in the XML sitemap.
- [ ] No individual glossary term post appears in the XML sitemap.
- [ ] No individual glossary term post is reachable by direct URL
      (should 404 or not resolve, since public/publicly_queryable are
      false).

## 13. Existing PYQ regression
- [ ] PYQ pages/admin screens load and behave identically before/after
      activating this plugin (no shared query slowdowns, no admin menu
      collisions, no PHP notices attributable to this plugin).

## 14. Existing header/footer regression
- [ ] Every other page on the site still renders its normal
      header/footer unchanged.
- [ ] The Hub page's header/footer are pixel-identical to any other
      ordinary page on the same theme (confirming true inheritance, not
      a divergent copy).
