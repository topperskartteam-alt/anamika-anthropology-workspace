#!/usr/bin/env node
/**
 * VAID public-browser audit utility.
 *
 * Read-only Playwright/Chromium audit of a single public VAID anthropology
 * page: typography, DOM landmarks, console/network summary, overflow check,
 * and a screenshot. No auth, no cookies persisted, no form submission.
 *
 * Usage:
 *   node audit.js --url=https://vaidsics.com/anthropology/mains-pyq/ \
 *                  --width=1440 --height=900 \
 *                  [--out=./out] [--name=mains-pyq-desktop]
 *
 * Scope guard: the CLI always audits against
 * https://vaidsics.com/anthropology/ — both the requested URL and the
 * final top-level URL after navigation must start with it. This cannot be
 * overridden by a CLI flag. Third-party subresources (fonts, etc.) that
 * the authorized page itself loads are not restricted by this guard — see
 * "Security notes" in README.md.
 */

'use strict';

const path = require('path');
const fs = require('fs');

const ALLOWED_PREFIX = 'https://vaidsics.com/anthropology/';

function parseArgs(argv) {
  const args = {};
  for (const raw of argv.slice(2)) {
    const m = raw.match(/^--([^=]+)=(.*)$/);
    if (m) args[m[1]] = m[2];
  }
  return args;
}

// requireHttps exists only so the internal test harness (test/*.test.js)
// can point this at a local http:// fixture server. The CLI never sets it,
// so every real invocation still requires https and the vaidsics.com
// anthropology prefix.
function assertAllowedUrl(url, allowedPrefix = ALLOWED_PREFIX, requireHttps = true) {
  let parsed;
  try {
    parsed = new URL(url);
  } catch (e) {
    throw new Error(`Invalid URL: ${url}`);
  }
  if (requireHttps && parsed.protocol !== 'https:') {
    throw new Error(`Refusing non-HTTPS URL: ${url}`);
  }
  if (!url.startsWith(allowedPrefix)) {
    throw new Error(
      `Domain guard: URL must start with "${allowedPrefix}". Got: ${url}`
    );
  }
  return parsed;
}

function classifyRequest(resourceType) {
  // Normalize Playwright resourceType values into the report buckets.
  const known = ['document', 'stylesheet', 'script', 'font', 'image', 'xhr', 'fetch'];
  const map = { stylesheet: 'css', script: 'js' };
  const bucket = map[resourceType] || resourceType;
  return known.includes(resourceType) ? bucket : (known.includes(bucket) ? bucket : 'other');
}

function emptyRequestCounts() {
  return { document: 0, css: 0, js: 0, font: 0, image: 0, xhr: 0, fetch: 0, other: 0 };
}

async function runAudit({
  url,
  width,
  height,
  outDir,
  name,
  // Test-only overrides — never set by the CLI. Keeping them as explicit
  // parameters (rather than reading env vars) means the production path
  // (main() below) provably always uses the real https vaidsics.com scope.
  allowedPrefix = ALLOWED_PREFIX,
  requireHttps = true,
  navTimeoutMs = 30000,
  useEnvProxy = true,
}) {
  assertAllowedUrl(url, allowedPrefix, requireHttps);

  const { chromium } = require('playwright');

  // This cloud environment routes all outbound HTTPS through a local
  // policy-enforcing proxy that re-terminates TLS with its own CA
  // (see /root/.ccr/README.md). Chromium does not pick up HTTPS_PROXY
  // automatically, so it is passed explicitly. --ignore-certificate-errors
  // and ignoreHTTPSErrors genuinely disable certificate validation inside
  // this ephemeral Chromium process/context — this is a deliberate
  // cloud-proxy compatibility tradeoff, not a claim that TLS is still
  // fully verified. See "Security notes" in README.md.
  // useEnvProxy is a test-only override: the local test fixture server
  // (127.0.0.1) is not reachable through this cloud environment's egress
  // proxy (it only relays HTTPS CONNECT to real external hosts), so the
  // test harness runs with useEnvProxy: false. The CLI never sets this,
  // so every real invocation still goes through the sanctioned proxy.
  const proxyServer = useEnvProxy ? (process.env.HTTPS_PROXY || process.env.https_proxy || null) : null;
  const browser = await chromium.launch({
    headless: true,
    proxy: proxyServer ? { server: proxyServer } : undefined,
    args: proxyServer ? ['--ignore-certificate-errors'] : [],
  });
  let result = {
    ok: false,
    url,
    viewport: { width, height },
    timestamp: new Date().toISOString(),
  };

  try {
    const context = await browser.newContext({
      viewport: { width, height },
      // Trust this environment's TLS-terminating egress proxy CA for this
      // session only (see the comment on chromium.launch above — this
      // does weaken certificate validation for this context).
      // No storage state, no cookies persisted, no auth.
      ignoreHTTPSErrors: true,
    });

    // This environment's egress proxy occasionally drops the first
    // connection attempt to a given host. Retry navigation a few times
    // before treating it as a real failure. Each attempt gets its own
    // Page with fresh counters/listeners, and a failed attempt's Page is
    // closed and discarded — so a partial/failed attempt can never leak
    // request counts, font resources, or console messages into the final
    // report. Only the winning attempt's data is ever kept.
    const NAV_ATTEMPTS = 3;
    let page = null;
    let response = null;
    let lastNavError = null;
    let requestCounts = null;
    let fontResources = null;
    let consoleErrors = null;
    let consoleWarnings = null;
    let scopeViolationUrl = null;

    for (let attempt = 1; attempt <= NAV_ATTEMPTS; attempt += 1) {
      const attemptRequestCounts = emptyRequestCounts();
      const attemptFontResources = new Set();
      const attemptConsoleErrors = [];
      const attemptConsoleWarnings = [];
      let attemptOutOfScopeUrl = null;

      const attemptPage = await context.newPage();

      attemptPage.on('console', (msg) => {
        const type = msg.type();
        if (type === 'error') attemptConsoleErrors.push(msg.text());
        else if (type === 'warning') attemptConsoleWarnings.push(msg.text());
      });

      attemptPage.on('requestfinished', (req) => {
        const bucket = classifyRequest(req.resourceType());
        if (Object.prototype.hasOwnProperty.call(attemptRequestCounts, bucket)) {
          attemptRequestCounts[bucket] += 1;
        } else {
          attemptRequestCounts.other += 1;
        }
        if (req.resourceType() === 'font') {
          attemptFontResources.add(req.url());
        }
      });

      // Block (and record) any top-level navigation — the initial one or
      // a later redirect/client-side navigation — that would take the
      // audited page outside the authorized prefix. This does not affect
      // subresource requests (fonts, CSS, JS, images, xhr/fetch), which
      // the authorized page may legitimately load from third-party
      // domains such as fonts.gstatic.com.
      await attemptPage.route('**/*', (route) => {
        const req = route.request();
        if (
          req.isNavigationRequest() &&
          req.frame() === attemptPage.mainFrame() &&
          !req.url().startsWith(allowedPrefix)
        ) {
          attemptOutOfScopeUrl = req.url();
          return route.abort('blockedbyclient');
        }
        return route.continue();
      });

      try {
        response = await attemptPage.goto(url, { waitUntil: 'networkidle', timeout: navTimeoutMs });
        if (attemptOutOfScopeUrl) {
          // Defensive: a blocked navigation request should make goto()
          // reject (handled below), but if a future engine version ever
          // resolves it instead, still treat this as a scope violation
          // rather than a successful audit.
          scopeViolationUrl = attemptOutOfScopeUrl;
          await attemptPage.close().catch(() => {});
          break;
        }
        page = attemptPage;
        requestCounts = attemptRequestCounts;
        fontResources = attemptFontResources;
        consoleErrors = attemptConsoleErrors;
        consoleWarnings = attemptConsoleWarnings;
        lastNavError = null;
        break;
      } catch (navErr) {
        if (attemptOutOfScopeUrl) {
          // The navigation failed because we deliberately blocked a
          // redirect/navigation outside the authorized prefix. This is a
          // scope violation, not a transient network failure — do not
          // retry it.
          scopeViolationUrl = attemptOutOfScopeUrl;
          await attemptPage.close().catch(() => {});
          break;
        }
        lastNavError = navErr;
        await attemptPage.close().catch(() => {});
      }
    }

    // P1 scope guard: whether the block happened on the initial request,
    // a redirect, or a later top-level navigation, stop here — no further
    // page extraction, no screenshot, and no additional navigation is
    // attempted.
    if (scopeViolationUrl) {
      result = {
        ok: false,
        requestedUrl: url,
        viewport: { width, height },
        error: 'final_url_out_of_scope',
        outOfScopeUrl: scopeViolationUrl,
        timestamp: new Date().toISOString(),
      };
      return result;
    }

    if (lastNavError) throw lastNavError;

    const httpStatus = response ? response.status() : null;
    const finalUrl = page.url();

    // Belt-and-suspenders: confirm the final top-level URL is still
    // in-scope even if no navigation request was ever flagged (e.g. a
    // same-document history API navigation the route handler can't see).
    if (!finalUrl.startsWith(allowedPrefix)) {
      result = {
        ok: false,
        requestedUrl: url,
        finalUrl,
        viewport: { width, height },
        error: 'final_url_out_of_scope',
        outOfScopeUrl: finalUrl,
        timestamp: new Date().toISOString(),
      };
      return result;
    }

    const title = await page.title();

    // Rendered-text semantics (innerText), not raw textContent, so styled
    // spans inside the heading don't get concatenated without whitespace.
    const h1Text = await page.evaluate(() => {
      const h1 = document.querySelector('h1');
      if (!h1) return null;
      return h1.innerText.replace(/\s+/g, ' ').trim();
    });

    const canonical = await page.evaluate(() => {
      const link = document.querySelector('link[rel="canonical"]');
      return link ? link.href : null;
    });

    const bodyFontFamily = await page.evaluate(() => {
      return getComputedStyle(document.body).fontFamily;
    });

    const h1Typography = await page.evaluate(() => {
      const h1 = document.querySelector('h1');
      if (!h1) return null;
      const cs = getComputedStyle(h1);
      return {
        fontFamily: cs.fontFamily,
        fontSize: cs.fontSize,
        fontWeight: cs.fontWeight,
        lineHeight: cs.lineHeight,
      };
    });

    const ctaTypography = await page.evaluate(() => {
      const selectors = [
        'a.button', 'a.btn', 'button', '.wp-block-button__link',
        'a[class*="button"]', 'a[class*="cta"]', '[class*="chip"]',
      ];
      let el = null;
      for (const sel of selectors) {
        el = document.querySelector(sel);
        if (el) break;
      }
      if (!el) return null;
      const cs = getComputedStyle(el);
      return {
        selectorMatched: el.tagName.toLowerCase() + (el.className ? '.' + String(el.className).split(' ').join('.') : ''),
        fontFamily: cs.fontFamily,
        fontSize: cs.fontSize,
        fontWeight: cs.fontWeight,
        lineHeight: cs.lineHeight,
        borderRadius: cs.borderRadius,
      };
    });

    const overflow = await page.evaluate(() => {
      return document.documentElement.scrollWidth > document.documentElement.clientWidth;
    });

    fs.mkdirSync(outDir, { recursive: true });
    const screenshotPath = path.join(outDir, `${name}.png`);
    await page.screenshot({ path: screenshotPath, fullPage: false });

    result = {
      ok: true,
      requestedUrl: url,
      finalUrl,
      httpStatus,
      title,
      h1Text,
      canonical,
      viewport: { width, height },
      typography: {
        bodyFontFamily,
        h1: h1Typography,
        cta: ctaTypography,
      },
      fontResources: Array.from(fontResources),
      fontDomains: Array.from(new Set(Array.from(fontResources).map((u) => {
        try { return new URL(u).hostname; } catch (e) { return null; }
      }).filter(Boolean))),
      requestCounts,
      console: {
        errorCount: consoleErrors.length,
        warningCount: consoleWarnings.length,
        errors: consoleErrors.slice(0, 20),
        warnings: consoleWarnings.slice(0, 20),
      },
      horizontalOverflow: overflow,
      screenshotPath,
      timestamp: new Date().toISOString(),
    };
  } catch (err) {
    result = {
      ok: false,
      requestedUrl: url,
      viewport: { width, height },
      error: String(err && err.message ? err.message : err),
      timestamp: new Date().toISOString(),
    };
  } finally {
    await browser.close();
  }

  return result;
}

async function main() {
  const args = parseArgs(process.argv);
  const url = args.url;
  const width = parseInt(args.width || '1440', 10);
  const height = parseInt(args.height || '900', 10);
  const outDir = path.resolve(args.out || path.join(__dirname, 'out'));
  const name = args.name || `audit-${width}x${height}-${Date.now()}`;

  if (!url) {
    console.error('Usage: node audit.js --url=https://vaidsics.com/anthropology/... --width=1440 --height=900 [--out=./out] [--name=report-name]');
    process.exitCode = 2;
    return;
  }

  let report;
  try {
    // No test-only overrides are passed here: the CLI always enforces the
    // real https://vaidsics.com/anthropology/ scope.
    report = await runAudit({ url, width, height, outDir, name });
  } catch (err) {
    report = {
      ok: false,
      requestedUrl: url,
      viewport: { width, height },
      error: String(err && err.message ? err.message : err),
      timestamp: new Date().toISOString(),
    };
  }

  fs.mkdirSync(outDir, { recursive: true });
  const jsonPath = path.join(outDir, `${name}.json`);
  fs.writeFileSync(jsonPath, JSON.stringify(report, null, 2));

  console.log(JSON.stringify(report, null, 2));
  console.log(`\nJSON report: ${jsonPath}`);
  if (report.ok) {
    console.log(`Screenshot: ${report.screenshotPath}`);
  }

  if (!report.ok) {
    process.exitCode = 1;
  }
}

if (require.main === module) {
  main();
}

module.exports = { runAudit, assertAllowedUrl, ALLOWED_PREFIX };
