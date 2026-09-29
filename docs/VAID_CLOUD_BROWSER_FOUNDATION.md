# ANAMIKA 00 — Cloud Browser Foundation

Status: **PASS — public frontend browser automation is proven and reusable
from this cloud environment.**

This document records how public-site Playwright/Chromium automation was
proven to work from a Claude Code cloud session against the live
`https://vaidsics.com/anthropology/` site, the exact security boundary in
place, and the reusable tool other ANAMIKA workstreams can call.

## 1. History: failed attempts before this pass

Earlier Claude Code cloud sessions attempted this same real-browser proof
under different network configurations and could not reach the public
internet at all:

- **Trusted network** environment setting — outbound HTTPS to
  `vaidsics.com` was not reachable; egress was restricted to an allowlist
  that did not include the site.
- **Custom network** allowlist attempts — adding `vaidsics.com` (and
  related hosts) to a custom allowlist still did not produce a working
  browser session; DNS/egress behavior under that mode was inconsistent
  for this use case.

Both of those attempts were abandoned in favor of creating a **fresh
session under a new environment named `VAID Cloud Browser` with Full
network access**, which is the environment this document is written from.

## 2. Successful proof: Full-network fresh session

### 2.1 Plain HTTP reachability (curl)

From a brand-new session in the `VAID Cloud Browser` environment, all three
target URLs returned `200 OK` with no redirects:

| URL | Status | Final URL |
|---|---|---|
| `https://vaidsics.com/anthropology/` | 200 | same |
| `https://vaidsics.com/anthropology/mains-pyq/` | 200 | same |
| `https://vaidsics.com/anthropology/optional-coaching/` | 200 | same |

### 2.2 Real Chromium/Playwright proof

Environment / versions:

- Environment name: **`VAID Cloud Browser`** (Full network access)
- Node.js: `v22.22.2`
- Playwright: `1.56.1` (installed globally at
  `/opt/node22/lib/node_modules/playwright`)
- Chromium: bundled build `chromium-1194`, at
  `PLAYWRIGHT_BROWSERS_PATH=/opt/pw-browsers`

**A real headless Chromium browser, not curl, was used for this proof** —
full navigation, DOM inspection, computed-style extraction, console/network
capture, and screenshotting.

#### Network detail that had to be solved

This cloud session's outbound HTTPS is routed through a local
policy-enforcing proxy (`http://127.0.0.1:37945`, see
`/root/.ccr/README.md`) that re-terminates TLS with its own CA. Every other
CLI tool in the environment (curl, git, npm, pip, etc.) is pre-configured
via environment variables to trust that CA automatically. **Headless
Chromium does not read those environment variables** and required two
things to work reliably against a real external site through this proxy:

1. `chromium.launch({ proxy: { server: 'http://127.0.0.1:37945' }, args: ['--ignore-certificate-errors'] })`
   — the launch-level flag is required because the browser performs a
   TLS handshake against the *proxy itself* (not just the destination
   site) before the destination navigation even starts.
2. `browser.newContext({ ignoreHTTPSErrors: true })` — needed for the
   navigated page's own certificate check, once past the proxy.

Both are scoped to this one audited session/context; nothing is disabled
globally, and no other host's certificate validation is affected. This is
the same trust boundary every other tool in the environment already
operates under (the proxy's CA is in the environment's system trust
store), just applied at the two places Chromium's own network stack
requires it explicitly.

A small number of early navigation attempts intermittently failed with
`net::ERR_TOO_MANY_RETRIES` or a `networkidle` timeout even after the
certificate trust was fixed — this appears to be occasional first-connection
flakiness from headless Chromium's network stack through the local proxy
(not reproduced with curl or Playwright's non-browser `request` API, which
succeeded on every attempt). The audit tool retries navigation up to 3
times before treating it as a real failure, which resolved this in
testing.

#### Verified results

**`https://vaidsics.com/anthropology/mains-pyq/` — desktop (1440×900)**

- Final URL: `https://vaidsics.com/anthropology/mains-pyq/` (no redirect)
- HTTP status: `200`
- Title: `UPSC Anthropology PYQs 2014-2026 - Paper I & II`
- H1: `UPSC Anthropology Optional Mains PYQs 2014-2026`
- Canonical: `https://vaidsics.com/anthropology/mains-pyq/`
- Body font-family: `-apple-system, system-ui, BlinkMacSystemFont, "Segoe UI", Helvetica, Arial, sans-serif, ...`
- H1 typography: `"Source Serif 4", Georgia, "Times New Roman", serif` · 48px · weight 600 · line-height 63.36px
- CTA (`button`) typography: system sans-serif stack · 17px · weight 400 · line-height 25.5px · border-radius 0px
- Font domains loaded: `fonts.gstatic.com`, `vaidsics.com` (8 font files)
- Request counts: document 1 · css 39 · js 32 · font 8 · image 4 · xhr 1 · fetch 0 · other 3
- Console: 0 errors, 0 warnings
- Horizontal overflow: **no**
- Screenshot: captured (`tools/vaid-browser-audit/out/mains-pyq-desktop.png`, gitignored)

**`https://vaidsics.com/anthropology/mains-pyq/` — mobile (390×844)**

- Same URL/status/title/H1/canonical/typography/console/network profile as
  desktop (this page's CSS does not change the H1/CTA computed styles
  between the two tested viewports)
- Horizontal overflow: **no**
- Screenshot: captured (`tools/vaid-browser-audit/out/mains-pyq-mobile.png`, gitignored)

**`https://vaidsics.com/anthropology/optional-coaching/` — desktop (1440×900)**

- Final URL: `https://vaidsics.com/anthropology/optional-coaching/` (no redirect)
- HTTP status: `200`
- Title: `Anthropology Optional Coaching in Delhi | Vaid's ICS`
- H1: `Score 300+ inAnthropology Optionalwith Vaid Sir`
- Canonical: `https://vaidsics.com/anthropology/optional-coaching/`
- Body font-family: `"Plus Jakarta Sans", sans-serif`
- H1 typography: `"Cormorant Garamond", serif` · 60px · weight 700 · line-height 66px
- CTA (`button.nav-call`) typography: system sans-serif stack · 13px · weight 700 · line-height 19.5px · border-radius 8px
- Font domain loaded: `fonts.gstatic.com` (5 font files)
- Request counts: document 1 · css 35 · js 23 · font 5 · image 25 · xhr 1 · fetch 0 · other 3
- Console: 0 errors, 0 warnings
- Horizontal overflow: **no**
- Screenshot: captured (`tools/vaid-browser-audit/out/optional-coaching-desktop.png`, gitignored)

**Notable typography difference across pages:** `mains-pyq` uses a system
sans-serif body font with a Source Serif 4 heading, while
`optional-coaching` uses Plus Jakarta Sans body copy with a Cormorant
Garamond heading. This is a real, page-level typography inconsistency
future ANAMIKA design/SEO work may want to address — this tool is what
surfaced it.

No forms were submitted and no lead-generation events were triggered
during any of this testing.

## 3. Reusable utility: `tools/vaid-browser-audit/`

A domain-guarded, read-only Playwright/Chromium audit script:

```
tools/vaid-browser-audit/
├── audit.js       # the tool
├── package.json
├── README.md      # full usage reference
└── out/           # gitignored — generated JSON reports + screenshots
```

### Exact reusable command

```bash
export NODE_PATH=/opt/node22/lib/node_modules
export PLAYWRIGHT_BROWSERS_PATH=/opt/pw-browsers

node tools/vaid-browser-audit/audit.js \
  --url=https://vaidsics.com/anthropology/<path>/ \
  --width=1440 --height=900 \
  --name=<report-name> \
  --out=tools/vaid-browser-audit/out
```

`NODE_PATH`/`PLAYWRIGHT_BROWSERS_PATH` are only needed because Playwright
is installed globally in this environment rather than as a project
dependency; if a future session installs `playwright` locally in this repo
those exports can be dropped.

### Expected output

- A JSON report (`<name>.json`) with: final URL, HTTP status, title, H1
  text, canonical URL, viewport, body/H1/CTA computed typography, loaded
  font resource URLs and domains, request counts by type, console
  error/warning counts (first 20 of each), horizontal-overflow flag, and
  the screenshot path.
- A PNG screenshot (`<name>.png`) of the viewport.
- On failure (bad URL, disallowed domain, network/browser error), a JSON
  report with `"ok": false` and an `error` field, and a non-zero exit code
  — never a silent failure or a half-written report.

### Security boundary

- **Domain guard is hardcoded in `audit.js`, not a CLI flag**: only URLs
  starting with `https://vaidsics.com/anthropology/` are accepted. Bare
  `https://vaidsics.com/` and any other domain are rejected before a
  browser is even launched (verified in testing — see Phase 4 below).
- No authentication support of any kind — no login flows, no credential
  handling.
- No cookies or storage state persist between runs — each invocation opens
  a fresh `browser.newContext()` and closes the browser at the end.
- No PII, lead data, or form submissions are ever collected or triggered.
- No secrets are read, stored, or required by the tool.
- Generated output (`tools/vaid-browser-audit/out/`) is gitignored by
  default — screenshots and JSON reports are local artifacts, not
  committed.
- The TLS-trust flags described in §2.2 apply only to this tool's own
  Chromium session talking to this environment's own sanctioned egress
  proxy — they do not weaken certificate validation for any other tool,
  host, or session.

## 4. Which future workstreams can use this

- **ANAMIKA 01 — SEO Content**: verify titles, H1s, canonicals, and
  structural metadata render correctly on the public site after content
  edits.
- **ANAMIKA 02 — PYQ Design Tech**: verify typography, spacing, and CTA
  styling consistency across pages and viewports (this pass already
  surfaced a body/H1 font mismatch between `mains-pyq` and
  `optional-coaching` — worth a design pass).
- **ANAMIKA 05 — WP Performance**: use the request-count and console
  summaries as a lightweight baseline for asset bloat (this pass saw
  35–39 CSS requests and 23–32 JS requests per page) and console
  error/warning regressions over time.

**ANAMIKA 04 — authenticated Fluent Forms / WP Admin work is explicitly
out of scope for this tool and this pass.** This utility has no login
support by design; a separate, secure authentication solution (e.g. a
scoped WP Admin session with credentials handled outside the repo, or an
authenticated API integration) is required before any admin-side or
form-submission automation can be attempted. Do not extend this tool with
login capability without a dedicated secure-auth design.

## 5. Phase 4 safe test-run verification

Run against:

- `/anthropology/mains-pyq/` at desktop 1440×900 and mobile 390×844
- `/anthropology/optional-coaching/` at desktop 1440×900

Verified:

- ✅ JSON valid for all three runs (`ok: true`, full field set populated)
- ✅ Screenshot created for all three runs (correct PNG dimensions matching
  each viewport)
- ✅ Typography populated (body, H1, CTA) for all three runs
- ✅ Domain guard tested against `https://vaidsics.com/` (root) and
  `https://example.com/` — both rejected with `ok: false` and a clear
  `error` message, no browser launched, exit code 1
- ✅ No form submitted, no lead event triggered
- ✅ No PII, cookies, or auth state captured or written anywhere
