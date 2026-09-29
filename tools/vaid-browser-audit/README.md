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

It never submits forms and never logs in. See "Security notes" below for
the precise scope of what is and isn't restricted.

## Scope guard

Hardcoded in `audit.js` — cannot be overridden by CLI flags:

```
https://vaidsics.com/anthropology/
```

- The **requested URL** and the **final top-level URL after navigation**
  must both start with this prefix, or the audit fails with
  `ok: false` / `error: "final_url_out_of_scope"` and no screenshot is
  taken. Any other requested URL (including bare `https://vaidsics.com/`)
  is rejected before the browser even launches.
- A redirect, or a later top-level navigation, that would take the page
  outside this prefix is actively blocked (not just detected after the
  fact) via Playwright request interception.
- This guard applies to **top-level navigation only**. It does not, and
  cannot, restrict third-party subresources the authorized page itself
  loads — e.g. web fonts from `fonts.gstatic.com`. See "Security notes".

## Usage

```bash
node tools/vaid-browser-audit/audit.js \
  --url=https://vaidsics.com/anthropology/mains-pyq/ \
  --width=1440 --height=900 \
  --name=mains-pyq-desktop \
  --out=tools/vaid-browser-audit/out
```

Flags:

- `--url` (required) — must start with `https://vaidsics.com/anthropology/`;
  the final URL after navigation must too, or the audit reports
  `final_url_out_of_scope`
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

## Testing

```bash
NODE_PATH=/opt/node22/lib/node_modules PLAYWRIGHT_BROWSERS_PATH=/opt/pw-browsers \
  node tools/vaid-browser-audit/test/audit-hardening.test.js
```

Deterministic, network-free regression tests (a local `127.0.0.1` fixture
server, no requests to vaidsics.com) covering:

- retry attempts never leak partial request/console data into the final
  report
- a redirect outside the authorized prefix is blocked and reported as
  `final_url_out_of_scope`, with no screenshot written
- H1 extraction uses rendered-text spacing (`innerText`), not raw
  `textContent`
- the scope guard rejects an out-of-root URL before any browser launches

These use test-only parameters on `runAudit()` (`allowedPrefix`,
`requireHttps`, `useEnvProxy`, `navTimeoutMs`) that the CLI never sets —
`main()` always calls `runAudit()` with just `{ url, width, height, outDir,
name }`, so the real domain guard, HTTPS requirement, and proxy usage are
unaffected by the existence of this test-only surface.

## Failure behavior

On navigation/browser failure, the tool still writes a JSON report with
`"ok": false` and an `error` message, and exits with a non-zero status
code. It does not throw an unhandled exception or leave a half-written
report.

## Security notes

- **Top-level audited navigation is restricted** to
  `https://vaidsics.com/anthropology/` — both the requested URL and the
  final URL after any redirect. This guard does **not** extend to
  third-party subresources the authorized page legitimately fetches (web
  fonts, CDN-hosted assets, etc.) — those are loaded normally, unrestricted
  by this tool, exactly as they would be in a regular browser visiting the
  page.
- **`--ignore-certificate-errors` and `ignoreHTTPSErrors` genuinely
  disable TLS certificate validation** inside this tool's ephemeral
  Chromium process/context. This is a deliberate compatibility tradeoff
  for this cloud environment, whose sanctioned egress proxy re-terminates
  TLS with its own CA (see `docs/VAID_CLOUD_BROWSER_FOUNDATION.md`) — it
  is not a claim that certificates are still being verified. The weakened
  validation is scoped to this one process/context and is discarded when
  the browser closes at the end of each run; it does not persist or
  affect any other tool, host, or session.
- No authentication support of any kind.
- No cookies or storage state are persisted between runs (fresh
  `browser.newContext()` per invocation, closed at the end).
- No PII or lead data is collected — only page structure/typography/network
  metadata.
- No secrets are read, stored, or required.
- **This tool must not be reused unchanged for authenticated WP Admin
  automation.** It has no login support by design, and disabling
  certificate validation the way it does is acceptable for read-only
  public-page inspection but not for a flow that would ever carry
  credentials or session cookies. A separate, secure-auth solution is
  required for that (see ANAMIKA 04 in
  `docs/VAID_CLOUD_BROWSER_FOUNDATION.md`).
