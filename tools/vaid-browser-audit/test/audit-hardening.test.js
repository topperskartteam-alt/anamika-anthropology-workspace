#!/usr/bin/env node
/**
 * Deterministic, network-free regression tests for the hardening applied
 * to audit.js:
 *   - retry attempts never leak partial request/console data into the
 *     final report (only the winning attempt's data is kept)
 *   - a top-level redirect out of the authorized prefix is blocked and
 *     reported as final_url_out_of_scope, with no screenshot written
 *   - H1 extraction uses rendered-text spacing (innerText), not a raw
 *     textContent concatenation of styled spans
 *   - the domain guard rejects an out-of-root URL before any browser
 *     is launched
 *
 * Runs entirely against a local http fixture server (127.0.0.1) — no
 * network access to vaidsics.com is required or performed. Uses the
 * test-only overrides on runAudit() (allowedPrefix / requireHttps /
 * navTimeoutMs / useEnvProxy); the CLI (main() in audit.js) never sets
 * these, so the production domain guard and proxy usage are untouched by
 * this file.
 *
 * Run with: node test/audit-hardening.test.js
 */

'use strict';

const assert = require('assert');
const http = require('http');
const fs = require('fs');
const os = require('os');
const path = require('path');

const { runAudit, assertAllowedUrl } = require('../audit.js');

const ONE_PX_PNG = Buffer.from(
  'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
  'base64'
);

function startFixtureServer() {
  const state = { attempts: 0, imageHits: 0 };

  const server = http.createServer((req, res) => {
    const url = req.url;

    if (url === '/anthropology/pixel.png') {
      state.imageHits += 1;
      res.writeHead(200, { 'Content-Type': 'image/png' });
      res.end(ONE_PX_PNG);
      return;
    }

    if (url === '/anthropology/index.html') {
      state.attempts += 1;
      if (state.attempts === 1) {
        // Simulate a dropped first connection attempt: destroy the socket
        // before any response is sent. page.goto() rejects quickly.
        req.socket.destroy();
        return;
      }
      res.writeHead(200, { 'Content-Type': 'text/html' });
      // Deliberately exercises the two real differences between innerText
      // and raw textContent: a hidden node's text must NOT appear, and a
      // block-level child must produce a rendered line break (whitespace)
      // at its boundary even though there is no literal space character
      // in the source markup.
      res.end(`<!doctype html>
<html><head><title>Fixture</title></head>
<body>
  <h1>Score 300+ in<span style="display:none">HIDDEN</span><span style="display:block">Anthropology Optional</span>with Vaid Sir</h1>
  <img src="/anthropology/pixel.png">
  <script>console.warn('test-warning-from-fixture');</script>
</body></html>`);
      return;
    }

    if (url === '/anthropology/redirect') {
      res.writeHead(302, { Location: `http://127.0.0.1:${server.address().port}/outside/` });
      res.end();
      return;
    }

    if (url === '/outside/') {
      res.writeHead(200, { 'Content-Type': 'text/html' });
      res.end('<!doctype html><html><body><h1>Outside scope</h1></body></html>');
      return;
    }

    res.writeHead(404);
    res.end();
  });

  return new Promise((resolve) => {
    server.listen(0, '127.0.0.1', () => resolve({ server, state }));
  });
}

async function withFixtureServer(fn) {
  const { server, state } = await startFixtureServer();
  const port = server.address().port;
  const prefix = `http://127.0.0.1:${port}/anthropology/`;
  try {
    await fn({ prefix, port, state });
  } finally {
    await new Promise((resolve) => server.close(resolve));
  }
}

async function testDomainGuardRejectsOutOfRoot() {
  assert.throws(
    () => assertAllowedUrl('https://vaidsics.com/', undefined, true),
    /Domain guard/,
    'bare root domain must be rejected by assertAllowedUrl'
  );
  assert.throws(
    () => assertAllowedUrl('https://example.com/', undefined, true),
    /Domain guard/,
    'unrelated domain must be rejected by assertAllowedUrl'
  );
  console.log('PASS  domain guard rejects out-of-root URLs');
}

async function testRetryMetricsIsolation() {
  await withFixtureServer(async ({ prefix, state }) => {
    const outDir = fs.mkdtempSync(path.join(os.tmpdir(), 'vaid-audit-test-'));
    const report = await runAudit({
      url: `${prefix}index.html`,
      width: 390,
      height: 844,
      outDir,
      name: 'retry-isolation',
      allowedPrefix: prefix,
      requireHttps: false,
      useEnvProxy: false,
      navTimeoutMs: 8000,
    });

    assert.strictEqual(report.ok, true, `expected ok:true, got: ${JSON.stringify(report)}`);
    assert.strictEqual(state.attempts, 2, 'expected exactly one failed attempt then one successful attempt');
    assert.strictEqual(report.requestCounts.image, 1, 'image count must reflect only the successful attempt (no leakage from the destroyed first attempt)');
    assert.strictEqual(state.imageHits, 1, 'the server must have served the pixel exactly once (only the winning attempt fetched it)');
    assert.strictEqual(report.console.warningCount, 1, 'console warning count must reflect only the successful attempt');
    console.log('PASS  retry metrics isolation (failed attempt data does not leak into final report)');
  });
}

async function testH1RenderedSpacing() {
  await withFixtureServer(async ({ prefix }) => {
    const outDir = fs.mkdtempSync(path.join(os.tmpdir(), 'vaid-audit-test-'));
    const report = await runAudit({
      url: `${prefix}index.html`,
      width: 1440,
      height: 900,
      outDir,
      name: 'h1-spacing',
      allowedPrefix: prefix,
      requireHttps: false,
      useEnvProxy: false,
      navTimeoutMs: 8000,
    });

    assert.strictEqual(report.ok, true, `expected ok:true, got: ${JSON.stringify(report)}`);
    // textContent.trim() (the old behavior) would have included the
    // display:none "HIDDEN" text and produced "inHIDDENAnthropology
    // Optionalwith" with no spacing at the block boundary. innerText
    // (rendered-text semantics) excludes hidden text and inserts
    // whitespace at the block-level child's boundary; normalizing that to
    // single spaces must yield a clean, correctly spaced string.
    assert.ok(!report.h1Text.includes('HIDDEN'), `h1Text must not include display:none text, got: "${report.h1Text}"`);
    assert.ok(!report.h1Text.includes('inAnthropology'), `h1Text must not concatenate words across the block boundary without whitespace, got: "${report.h1Text}"`);
    assert.ok(!report.h1Text.includes('Optionalwith'), `h1Text must not concatenate words across the block boundary without whitespace, got: "${report.h1Text}"`);
    assert.ok(!/\s{2,}/.test(report.h1Text), `h1Text must be normalized to single spaces, got: "${report.h1Text}"`);
    assert.strictEqual(report.h1Text, 'Score 300+ in Anthropology Optional with Vaid Sir', `unexpected h1Text: "${report.h1Text}"`);
    console.log(`PASS  H1 rendered-spacing extraction ("${report.h1Text}")`);
  });
}

async function testFinalUrlOutOfScopeRedirect() {
  await withFixtureServer(async ({ prefix }) => {
    const outDir = fs.mkdtempSync(path.join(os.tmpdir(), 'vaid-audit-test-'));
    const report = await runAudit({
      url: `${prefix}redirect`,
      width: 1440,
      height: 900,
      outDir,
      name: 'scope-violation',
      allowedPrefix: prefix,
      requireHttps: false,
      useEnvProxy: false,
      navTimeoutMs: 8000,
    });

    assert.strictEqual(report.ok, false, `expected ok:false for an out-of-scope redirect, got: ${JSON.stringify(report)}`);
    assert.strictEqual(report.error, 'final_url_out_of_scope', `expected error code final_url_out_of_scope, got: ${JSON.stringify(report)}`);
    assert.ok(report.outOfScopeUrl && report.outOfScopeUrl.includes('/outside/'), `expected outOfScopeUrl to name the blocked target, got: ${JSON.stringify(report)}`);

    const screenshotPath = path.join(outDir, 'scope-violation.png');
    assert.ok(!fs.existsSync(screenshotPath), 'no screenshot should be written when the final URL is out of scope');
    console.log('PASS  final-URL-out-of-scope redirect is blocked, reported, and produces no screenshot');
  });
}

async function main() {
  await testDomainGuardRejectsOutOfRoot();
  await testRetryMetricsIsolation();
  await testH1RenderedSpacing();
  await testFinalUrlOutOfScopeRedirect();
  console.log('\nAll audit-hardening tests passed.');
}

main().catch((err) => {
  console.error('FAIL', err);
  process.exitCode = 1;
});
