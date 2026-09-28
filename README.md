# anamika-anthropology-workspace

Repository workspace for the VAID Anthropology Glossary WordPress plugin.

> `ANAMIKA` is the Claude session/workstream name only. All product, plugin,
> file, and code artifacts in this repository use **VAID** naming.

## Contents

- [`vaid-anthropology-glossary/`](./vaid-anthropology-glossary/) — the plugin
  source (display name **VAID Anthropology Glossary**, slug
  `vaid-anthropology-glossary`).
- [`vaid-anthropology-glossary-0.4.0.zip`](./vaid-anthropology-glossary-0.4.0.zip) —
  the packaged v0.4.0 pilot build, ready for manual installation on a
  WordPress test/staging site.
- [`CHANGELOG.md`](./CHANGELOG.md) — version history.
- [`QA-CHECKLIST.md`](./QA-CHECKLIST.md) — manual QA checklist for WordPress
  installation (this repository build cannot run WordPress itself).
- [`BUILD-REPORT.md`](./BUILD-REPORT.md) — what was built, deviations,
  checks run, known limitations, and installation/test steps.

## What this plugin does (v0.4.0 pilot)

Admin-managed Anthropology & UPSC glossary terms (`vaid_glossary_term`
custom post type, not individually public in this version), a CSV
import/export workflow with dry-run validation and batch rollback, and a
single public **Glossary Hub** page — hero, search field, A-Z navigation,
term cards — built to match the verified Figma design (`Vaids-ICS` file,
Glossary /D `365:4538` / Glossary /M `453:8439`).

See `BUILD-REPORT.md` for the full architecture, scope decisions, and
what is intentionally **not** built in this pilot (filters, term-detail
pages, related terms, pagination, illustrated empty states — none of
these are defined in Figma yet).

## Status

Built in-repository only. **Not deployed to any live WordPress site.**
See `QA-CHECKLIST.md` before any real installation.
