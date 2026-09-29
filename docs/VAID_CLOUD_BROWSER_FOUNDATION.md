# ANAMIKA 00 — Cloud Browser Foundation

Status: **PASS — public frontend browser automation is proven, hardened,
and reusable from this cloud environment.** (Hardening pass applied on
top of the original proof — see §6.)

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
- H1: `Score 300+ in Anthropology Optional with Vaid Sir` (originally
  extracted as `Score 300+ inAnthropology Optionalwith Vaid Sir` via raw
  `textContent`; fixed by the §6 hardening pass to use rendered-text
  (`innerText`) semantics)
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

A scope-guarded, read-only Playwright/Chromium audit script:

```
tools/vaid-browser-audit/
├── audit.js                          # the tool
├── package.json
├── README.md                         # full usage reference
├── test/
│   └── audit-hardening.test.js       # deterministic, network-free tests
└── out/                               # gitignored — generated JSON reports + screenshots
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

- **Scope guard is hardcoded in `audit.js`, not a CLI flag**: both the
  requested URL and the final top-level URL after navigation must start
  with `https://vaidsics.com/anthropology/`. A requested URL outside this
  prefix (e.g. bare `https://vaidsics.com/`, or an unrelated domain) is
  rejected before a browser is even launched. A redirect or later
  top-level navigation that would leave this prefix is actively blocked,
  reported as `ok: false` / `error: "final_url_out_of_scope"`, and no
  screenshot is taken (verified in testing — see §6 and §7).
- **This guard restricts top-level navigation only.** It does not, and
  cannot, restrict third-party subresources the authorized page itself
  loads (e.g. web fonts from `fonts.gstatic.com`) — those are fetched
  normally, the same as they would be for anyone else visiting the page.
- **`--ignore-certificate-errors` / `ignoreHTTPSErrors` genuinely disable
  TLS certificate validation** inside this tool's ephemeral Chromium
  process/context (see §2.2) — this is a deliberate cloud-proxy
  compatibility tradeoff, not a claim that certificates are still
  verified. It is scoped to that one process/context, discarded when the
  browser closes at the end of each run, and does not affect any other
  tool, host, or session.
- No authentication support of any kind — no login flows, no credential
  handling.
- No cookies or storage state persist between runs — each invocation opens
  a fresh `browser.newContext()` and closes the browser at the end.
- No PII, lead data, or form submissions are ever collected or triggered.
- No secrets are read, stored, or required by the tool.
- Generated output (`tools/vaid-browser-audit/out/`) is gitignored by
  default — screenshots and JSON reports are local artifacts, not
  committed.
- **This public tool must not be reused unchanged for authenticated WP
  Admin automation** — it has no login support by design, and its
  certificate-validation tradeoff is acceptable for read-only public
  inspection but not for any flow that would carry credentials or session
  cookies (see §4/ANAMIKA 04).

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

## 5. Original safe test-run verification

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

## 6. Hardening pass (post-proof, pre-`main`)

Applied to commit `ce4a261` on branch `claude/wizardly-darwin-gi1gsy`
before other workstreams were allowed to depend on this tool. Four fixes:

1. **Final-URL scope guard (P1).** The original tool only validated the
   *requested* URL. It now also validates the final top-level URL after
   navigation, and actively blocks (via Playwright request interception,
   not just after-the-fact detection) any redirect or later top-level
   navigation that would leave `https://vaidsics.com/anthropology/`. A
   violation is reported as `ok: false` / `error:
   "final_url_out_of_scope"`, with no further page extraction and no
   screenshot — and no further navigation is attempted.
2. **Retry metrics isolation (P1).** The original tool kept a single set
   of request/console counters alive across up to 3 navigation-retry
   attempts, so a failed attempt could inflate the final report's request
   counts, font-resource list, or console warning/error counts. Each
   retry attempt now gets its own `Page`, with its own fresh counters and
   listeners; a failed attempt's `Page` is closed and discarded, and only
   the winning attempt's data is ever kept.
3. **H1 rendered-spacing extraction (P2).** H1 text is now extracted via
   `innerText` (rendered-text semantics) instead of raw
   `textContent.trim()`, then whitespace-normalized to single spaces. This
   fixed a real concatenation bug on `optional-coaching`'s H1 — see §2.2.
4. **Corrected security documentation (P2).** Prior wording overstated two
   things: that the tool "never touches any URL outside the allowed
   prefix" (it does — third-party subresources like Google Fonts are
   unrestricted), and implicitly that certificate handling was harmless
   (it genuinely disables certificate validation inside this tool's
   Chromium process/context, as a deliberate cloud-proxy tradeoff). Both
   `README.md` and this document have been corrected — see "Security
   notes" / "Security boundary" above.

No WordPress writes, no WP Admin login, no scope expansion beyond these
four fixes were made in this pass.

## 7. Hardening regression tests

All required cases were re-run after the hardening changes:

1. ✅ `mains-pyq` desktop 1440×900 — real site, `ok: true`, 200, no
   overflow
2. ✅ `mains-pyq` mobile 390×844 — real site, `ok: true`, 200, no overflow
3. ✅ `optional-coaching` desktop 1440×900 — real site, `ok: true`, 200,
   no overflow, H1 now correctly spaced: `Score 300+ in Anthropology
   Optional with Vaid Sir`
4. ✅ Initial URL outside the authorized root rejected — both
   `https://vaidsics.com/` and `https://example.com/`, `ok: false`, no
   browser launched, exit code 1
5. ✅ Controlled redirect / final-URL-out-of-scope test — deterministic,
   network-free, against a local fixture server: a page that redirects
   outside the authorized prefix is blocked, reported as `ok: false` /
   `error: "final_url_out_of_scope"`, and produces no screenshot
6. ✅ H1 rendered-spacing test — deterministic, network-free: a fixture
   H1 with a `display:none` node and a `display:block` inline child
   confirms `innerText`-based extraction excludes hidden text and
   correctly spaces across the block boundary, normalized to single
   spaces
7. ✅ Retry-metrics-isolation test — deterministic, network-free: a
   fixture server that drops the first connection attempt and succeeds on
   the second confirms the final report's image request count and
   console-warning count reflect only the successful attempt

Tests 4–7 are captured as a runnable, repeatable suite at
`tools/vaid-browser-audit/test/audit-hardening.test.js` (see its README
section, "Testing"). Tests 1–3 were run live against the real site via the
CLI as in §2.2/§5.

Also verified for this pass specifically:

- No horizontal-overflow regression on any real page
- Screenshots and JSON reports remain gitignored (`out/` untracked)
- No form submission, no authentication, at any point
- No new runtime dependencies were added (tests use only Node's built-in
  `http`/`assert`/`fs`/`os`/`path` plus the already-installed Playwright)
- No secrets or PII collected
- No `ANAMIKA`-prefixed software artifact names were introduced (tool,
  file, and function names remain `vaid-*` / plain descriptive names)
