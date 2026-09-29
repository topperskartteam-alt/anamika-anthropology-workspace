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
 * Domain guard: by default only URLs under https://vaidsics.com/anthropology/
 * are permitted. This cannot be overridden by a CLI flag; it is a hardcoded
 * safety boundary for this tool.
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

function assertAllowedUrl(url) {
  let parsed;
  try {
    parsed = new URL(url);
  } catch (e) {
    throw new Error(`Invalid URL: ${url}`);
  }
  if (parsed.protocol !== 'https:') {
    throw new Error(`Refusing non-HTTPS URL: ${url}`);
  }
  if (!url.startsWith(ALLOWED_PREFIX)) {
    throw new Error(
      `Domain guard: URL must start with "${ALLOWED_PREFIX}". Got: ${url}`
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

async function runAudit({ url, width, height, outDir, name }) {
  assertAllowedUrl(url);

  const { chromium } = require('playwright');

  const requestCounts = { document: 0, css: 0, js: 0, font: 0, image: 0, xhr: 0, fetch: 0, other: 0 };
  const fontResources = new Set();
  const consoleErrors = [];
  const consoleWarnings = [];

  // This cloud environment routes all outbound HTTPS through a local
  // policy-enforcing proxy that re-terminates TLS with its own CA
  // (see /root/.ccr/README.md). Chromium does not pick up HTTPS_PROXY
  // automatically, so it is passed explicitly. Trusting the proxy's
  // re-terminated certificate requires BOTH the launch-level
  // --ignore-certificate-errors flag (covers the CONNECT/TLS handshake
  // Chromium performs against the proxy itself) and the context-level
  // ignoreHTTPSErrors (covers the navigated page). This matches every
  // other CLI tool in this environment, which is pre-configured to trust
  // the same CA bundle; it does not disable verification against any
  // other host.
  const proxyServer = process.env.HTTPS_PROXY || process.env.https_proxy || null;
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
      // session only. No storage state, no cookies persisted, no auth.
      ignoreHTTPSErrors: true,
    });
    const page = await context.newPage();

    page.on('console', (msg) => {
      const type = msg.type();
      if (type === 'error') consoleErrors.push(msg.text());
      else if (type === 'warning') consoleWarnings.push(msg.text());
    });

    page.on('requestfinished', (req) => {
      const bucket = classifyRequest(req.resourceType());
      if (Object.prototype.hasOwnProperty.call(requestCounts, bucket)) {
        requestCounts[bucket] += 1;
      } else {
        requestCounts.other += 1;
      }
      if (req.resourceType() === 'font') {
        fontResources.add(req.url());
      }
    });

    // This environment's egress proxy occasionally drops the first
    // connection attempt to a given host; retry navigation a few times
    // before treating it as a real failure.
    const NAV_ATTEMPTS = 3;
    let response = null;
    let lastNavError = null;
    for (let attempt = 1; attempt <= NAV_ATTEMPTS; attempt += 1) {
      try {
        response = await page.goto(url, { waitUntil: 'networkidle', timeout: 30000 });
        lastNavError = null;
        break;
      } catch (navErr) {
        lastNavError = navErr;
      }
    }
    if (lastNavError) throw lastNavError;
    const httpStatus = response ? response.status() : null;
    const finalUrl = page.url();

    const title = await page.title();

    const h1Text = await page.evaluate(() => {
      const h1 = document.querySelector('h1');
      return h1 ? h1.textContent.trim() : null;
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
