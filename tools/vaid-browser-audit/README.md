# vaid-browser-audit

Reusable, read-only Playwright/Chromium audit utility for the public
`https://vaidsics.com/anthropology/` site. Built for cloud Claude Code
sessions running in the `VAID Cloud Browser` environment (Full network
access).

## What it does

For a single URL + viewport, it:

- Navigates headlessly with Chromium (no cookies/auth, fresh context per run)
- Captures final URL, HTTP status, `<title>`, `<h1>` text, canonical URL
- Extracts computed typography for `body`, the first `<h1>`, and one
  representative CTA/button/chip (font-family, size, weight, line-height,
  border-radius)
- Lists loaded font resource URLs/domains
- Summarizes network requests by type (document/css/js/font/image/xhr/fetch)
- Summarizes console error/warning counts (first 20 messages of each)
- Detects horizontal overflow (`scrollWidth > clientWidth`)
- Saves a screenshot and a JSON report to an output directory

It never submits forms, never logs in, never persists cookies/storage state,
and never touches any URL outside the allowed prefix.

## Domain guard

Hardcoded in `audit.js` — cannot be overridden by CLI flags:

```
https://vaidsics.com/anthropology/
```

Any other URL (including bare `https://vaidsics.com/`) is rejected before
the browser navigates.

## Usage

```bash
node tools/vaid-browser-audit/audit.js \
  --url=https://vaidsics.com/anthropology/mains-pyq/ \
  --width=1440 --height=900 \
  --name=mains-pyq-desktop \
  --out=tools/vaid-browser-audit/out
```

Flags:

- `--url` (required) — must start with `https://vaidsics.com/anthropology/`
- `--width`, `--height` — viewport size in px (defaults 1440x900)
- `--name` — base filename for the `.json` report and `.png` screenshot
  (default: auto-generated from viewport + timestamp)
- `--out` — output directory (default: `tools/vaid-browser-audit/out`,
  gitignored)

## Example commands used for verification

```bash
# Desktop
node tools/vaid-browser-audit/audit.js \
  --url=https://vaidsics.com/anthropology/mains-pyq/ \
  --width=1440 --height=900 --name=mains-pyq-desktop

# Mobile
node tools/vaid-browser-audit/audit.js \
  --url=https://vaidsics.com/anthropology/mains-pyq/ \
  --width=390 --height=844 --name=mains-pyq-mobile

# Second page, desktop only
node tools/vaid-browser-audit/audit.js \
  --url=https://vaidsics.com/anthropology/optional-coaching/ \
  --width=1440 --height=900 --name=optional-coaching-desktop
```

## Runtime requirements

- Node.js (tested on v22)
- Playwright installed globally in this environment
  (`/opt/node22/lib/node_modules/playwright`), Chromium at
  `/opt/pw-browsers` (`PLAYWRIGHT_BROWSERS_PATH=/opt/pw-browsers`)
- If `playwright` is not resolvable from this directory, run with:
  `NODE_PATH=/opt/node22/lib/node_modules node tools/vaid-browser-audit/audit.js ...`

## Output

Each run writes to the output directory:

- `<name>.json` — full structured report
- `<name>.png` — viewport screenshot (not full-page)

The output directory is gitignored by default — reports/screenshots are
local artifacts, not committed.

## Failure behavior

On navigation/browser failure, the tool still writes a JSON report with
`"ok": false` and an `error` message, and exits with a non-zero status
code. It does not throw an unhandled exception or leave a half-written
report.

## Safety notes

- No authentication support of any kind.
- No cookies or storage state are persisted between runs (fresh
  `browser.newContext()` per invocation, closed at the end).
- No PII or lead data is collected — only page structure/typography/network
  metadata.
- No secrets are read, stored, or required.
