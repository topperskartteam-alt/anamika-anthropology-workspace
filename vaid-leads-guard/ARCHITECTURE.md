# VAID Leads Guard v0.1.1 — Architecture

v0.1.1 is an adversarial red-team pass over v0.1.0, run against the
same shadow-mode brief. This document folds the v0.1.0 architecture
notes together with the defect table, evidence, and verdicts from that
pass. Nothing here is a redesign — v0.1.1 stays a post-insert-only,
non-blocking, fingerprint-only shadow observer for the same two forms.

## Red-team defect table

| ID | Severity | Evidence | Repair |
|---|---|---|---|
| P0-1 | **High** | v0.1.0 registered on only `fluentform_submission_inserted` (underscore). Multiple independent search-engine-retrieved summaries of Fluent Forms' own official docs (fluentforms.com/docs, developers.fluentforms.com — see "Hook contract evidence" below) agree: all FF hooks were renamed to slash form in FF 5.0; the canonical current hook is `fluentform/submission_inserted`; the underscore name still fires only via `do_action_deprecated()` for back-compat and is scheduled for **removal in FF 7.0**. On a site already past that removal, v0.1.0 would silently observe nothing, forever, with no error surfaced anywhere. | Register on **both** `fluentform/submission_inserted` and `fluentform_submission_inserted`. Made double-fire-safe by a new `UNIQUE KEY (form_id, entry_id)` (see P0-3) rather than by trying to guess which single name is "the" right one for an unknown live FF version. |
| P0-2 | Medium | v0.1.0 caught `Exception` only in `on_submission_inserted()`. A PHP 7+ `Error` (e.g. a `TypeError` from an unexpected Fluent Forms data shape) would NOT have been caught, and would have propagated out of the hook callback into Fluent Forms' own post-insert code path — a real, if narrow, violation of the "shadow mode can never interfere" guarantee. | Catch `Throwable`, not `Exception`. Verified structurally by `tests/test-static-checks.php` (the entire method body must open with `try {` and close with `catch ( Throwable $e )`). |
| P0-3 | Medium | Concurrency review: two genuinely simultaneous submissions from two visitors can both run their "find prior match" query before either has inserted its own row, so each misses the other as a match (see "Concurrency" below). Separately, P0-1's dual-hook registration means the SAME entry_id can be processed twice in one request if a live FF version fires both hook names for one event. | For the *same-entry* case: `UNIQUE KEY (form_id, entry_id)` makes a second insert for the same real submission impossible at the database level; the rejection is recognized and logged as expected, not as an error. For the *two-different-visitors* race: documented as an accepted shadow-mode limitation (see below) rather than "fixed" — serializing it would need real cross-request locking, which a non-blocking observability feature does not justify. Proven non-corrupting (no crash, no wrong data, only a missed cross-reference) by `tests/test-concurrency.php`. |
| P0-4 | Low | Fingerprint key lifecycle review: v0.1.0's approach (generate once on activation, non-autoloaded option, never regenerated on reactivation, never logged/displayed) was already sound. No code defect found. | Documentation only — see "Fingerprint key lifecycle" below, added because the brief required the lifecycle to be *explicitly* documented, not just correctly implemented. |
| P1-1 | Low | Schema/index review: `find_prior_by_fingerprint()`'s actual query is `WHERE fingerprint = X AND created_at < Y ORDER BY created_at DESC LIMIT 1`. v0.1.0 indexed `phone_fingerprint`, `email_fingerprint`, `pair_fingerprint`, and `created_at` as four *separate* single-column indexes, which MySQL cannot combine into an efficient single range-scan-plus-order-by for that query shape. | Replaced with composite indexes: `(phone_fingerprint, created_at)`, `(email_fingerprint, created_at)`, `(pair_fingerprint, created_at)`. No live data existed to migrate (plugin was never deployed), so this is a clean pre-launch schema correction. |
| P1-2 | Low | Time semantics review: v0.1.0 stored `current_time('mysql')` (WordPress site-local time) and parsed both sides of a delta with a bare `new DateTime($string)` (PHP-default-timezone, not necessarily the same as WP's configured timezone). Elapsed-seconds math was accidentally safe as long as both reads used the same effective timezone in the same request — but naive local-time string diffing is not safe in general across a DST transition. India (this site's timezone) observes no DST, so real-world impact here is near zero, but the code was not written to be correct independent of that fact. | Store and compare in UTC explicitly: `current_time( 'mysql', true )` for writes, `new DateTime( $string, new DateTimeZone( 'UTC' ) )` for both sides of every comparison. Verified by `tests/test-static-checks.php` (greps for the old unsafe call pattern) and exercised at each classification boundary by `tests/test-classifier.php`. |
| P1-3 | Low | Admin security review: settings save and CSV export both already had capability checks (`manage_options`) and nonces (`check_admin_referer`), and all admin-screen output was already escaped. One real gap: CSV export did not defend against formula/CSV injection (a cell value starting with `=`, `+`, `-`, or `@` being interpreted as a spreadsheet formula on open). No current export column can actually start with those characters (numeric IDs, hex fingerprint prefixes, a fixed set of classification/action strings, a MySQL datetime) — but the brief required defense-in-depth regardless of current column safety. | Added `VAID_Leads_Guard_Csv_Sanitizer`, applied to every exported cell generically. Directly unit-tested (21 assertions) independent of the admin screen. |
| P2-1 | Low | Operational review: a submission with neither a valid phone nor a valid email (e.g. the malformed/junk entries found in the original audit — free text, 9-digit typos) still produced an observation row in v0.1.0, contributing pure noise to the `new_identity` count with nothing to ever match against. | Observer now returns early (no row inserted) when both fingerprints are null. Nothing is lost — there is nothing identifiable to have recorded. |
| P2-2 | Low | Operational review: v0.1.0 logged nothing when `$wpdb->insert()` failed (e.g. table missing/corrupt). Shadow mode already failed open correctly (nothing thrown, nothing blocked) — but a real insert failure was invisible even to an admin checking server logs. | Insert failures are now logged (with the expected UNIQUE-KEY-duplicate case from P0-3 explicitly excluded from the log, since that one is routine, not an error). |

No defect required a redesign. All ten repairs are additive/corrective within the existing architecture, per the brief's "do not redesign unless a defect requires it."

## Hook contract evidence

**Claim:** the current, canonical Fluent Forms post-insert hook is
`fluentform/submission_inserted`, firing `do_action( 'fluentform/submission_inserted', $entryId, $formData, $form )` strictly after the
submission row is written to Fluent Forms' own table; the pre-5.0 name
`fluentform_submission_inserted` still fires today via
`do_action_deprecated()` but is scheduled for removal in FF 7.0; and a
WordPress *action* hook (as opposed to a *filter*) never uses a
callback's return value for anything.

**Evidence chain and its limits:** this environment's network egress
policy blocks direct HTTPS access to `fluentforms.com`,
`developers.fluentforms.com`, `wordpress.org`, `github.com`'s code
search, and `grep.app` — every attempt to fetch the primary source or
official docs directly returned `EGRESS_BLOCKED` or `403`. The evidence
above instead comes from three independent web-search queries (via this
session's search tool, which is not subject to the same egress block),
each returning search-engine-generated summaries that quote and cite:
`fluentforms.com/docs/fluentform_submission_inserted/`,
`developers.fluentforms.com/hooks/actions/submission/`,
`developers.fluentforms.com/upgrade-guide/6.2.0/`, and a WordPress.org
support-forum thread. All three searches agreed with each other on the
hook name, parameter order (`$entryId, $formData, $form`), the
post-insert timing, and the v5.0 underscore→slash rename with a v7.0
removal date for the deprecated alias — independently rediscovered
across different queries rather than repeated from a single source.
This is real evidence, not memory, and it is what the brief's "verify,
don't guess" requirement asks for — but it is **not** the same as
reading Fluent Forms' own PHP source directly, which this environment
cannot reach. The repair (register both names, make double-processing
safe by construction) is deliberately chosen so that it is correct
**even if this evidence chain turns out to be subtly wrong** — the
plugin does not depend on having picked the one true hook name, only on
having covered the plausible set and made overlap harmless.

**What is proven independent of Fluent Forms specifics:** that a WP
*action* hook never consults a callback's return value is a core,
version-stable WordPress Plugin API guarantee (this is what
distinguishes `do_action`/`add_action` from `apply_filters`/`add_filter`
in WordPress core itself), not something that needed Fluent-Forms-
specific verification.

**What remains a genuine blocker, not resolved by this round:** the
exact Fluent Forms version installed on `vaidsics.com/anthropology` was
never confirmed (no site access in this environment). The dual-hook
registration is the mitigation for that unknown, not a substitute for
confirming it — see "Deployment recommendation."

## Concurrency

Two distinct scenarios, not to be conflated:

1. **Same submission, hook fired twice in one request** (a consequence
   of the P0-1 repair, on any FF version where both the current and
   deprecated hook names fire for one event): sequential, not a true
   race, and made harmless by `UNIQUE KEY (form_id, entry_id)` — see
   defect P0-3 above.
2. **Two different visitors submit near-simultaneously**: a true race
   between two PHP-FPM workers. Both Fluent Forms entries are inserted
   independently (by Fluent Forms itself, outside this plugin's
   control) before either observer callback runs. If both callbacks'
   "find prior match" `SELECT` execute before either callback's
   `INSERT` commits, each sees the other's identity as if it doesn't
   exist yet — the classification for that pair does not cross-reference
   correctly. `tests/test-concurrency.php` simulates exactly this
   ordering and proves the outcome is bounded: no crash, no data
   corruption, no duplicate row for the same entry — only a missed
   `strong_mechanical_repeat` classification that instead reads
   `new_identity` for both sides of that particular pair. **This is
   accepted as a documented shadow-mode limitation, not fixed.**
   Serializing it correctly would require real cross-request locking
   (e.g. `SELECT ... FOR UPDATE` inside a transaction spanning both the
   lookup and the insert), which is disproportionate machinery for a
   non-blocking observability feature and was explicitly the kind of
   overengineering the red-team brief said not to do. A future
   *blocking* dedupe/OTP version, where correctness under concurrency
   actually gates a real decision, must not inherit this shadow-mode
   assumption and will need to address it properly at that point.

## Fingerprint key lifecycle

- **Source:** a 64-character value from `wp_generate_password( 64, true, true )` (letters, numbers, and special characters — WordPress' own CSPRNG-backed password generator), generated exactly once.
- **Never hardcoded, never committed:** generated at runtime on first activation only; does not exist anywhere in source control.
- **Stable across requests/restarts:** stored in `wp_options` (`vaid_leads_guard_hmac_secret`), which persists in the database exactly like any other WordPress option — unaffected by PHP process restarts, deploys, or cache flushes (it is not cached in an ephemeral object cache path that could evict it, and even if an object cache did evict it, `get_option()` falls back to the database).
- **Not regenerated on every activation:** `vaid_leads_guard_activate()` only calls `add_option()` when the option does not already exist (`false === get_option(...)`); deactivating and reactivating the plugin does not touch it.
- **Autoload:** stored with `autoload = 'no'` — it is only ever needed inside the Fluent Forms submission hook, not on every WordPress page load, so there is no reason to pay the autoload cost site-wide.
- **Never printed:** the admin report and CSV export show only `VAID_Leads_Guard_Fingerprint::short()` (a 10-character prefix of a *derived* fingerprint, not the secret itself); the secret is never referenced in any admin screen, error message, or log line anywhere in the codebase (verified by `tests/test-static-checks.php`'s secret-pattern grep).
- **WordPress salts (`AUTH_KEY` etc.) are deliberately NOT used.** Those are configured in `wp-config.php`, are visible to anyone with filesystem/hosting-panel access, and — critically — can be rotated by an administrator or a security tool independent of this plugin, silently breaking every stored fingerprint with no warning. A dedicated `wp_options` value that only this plugin manages is more stable and keeps the consequence of rotation inside this plugin's own explicit lifecycle.
- **Rotation behavior (explicit):** there is no automated rotation in v0.1.x. If the secret is ever regenerated (deliberately, or via `uninstall.php` + reinstall — uninstall removes the secret option, see "Rollback / removal plan"), every fingerprint computed afterward will differ from fingerprints computed before, for the same real phone/email. This is a hard reset of matching continuity, not data loss — old observation rows remain in the table, they simply stop matching new ones. This is treated as an acceptable, documented consequence of never storing plaintext, not a defect.

## Data model (v1.1.0 schema)

Table: `{wp_prefix}vaid_leads_guard_observations`

| Column | Type | Notes |
|---|---|---|
| id | BIGINT UNSIGNED PK | |
| form_id | SMALLINT UNSIGNED | 9 or 1 |
| entry_id | BIGINT UNSIGNED | Fluent Forms' own entry ID |
| prior_entry_id | BIGINT UNSIGNED, nullable | matched prior entry, if any — a reference INTO Fluent Forms' own PII-bearing entry, not PII itself (see "Privacy verdict") |
| prior_form_id | SMALLINT UNSIGNED, nullable | form of the prior match (enables cross-form detection) |
| phone_fingerprint | CHAR(64), nullable | HMAC-SHA256 hex of normalized phone |
| email_fingerprint | CHAR(64), nullable | HMAC-SHA256 hex of normalized email |
| pair_fingerprint | CHAR(64), nullable | HMAC-SHA256 hex of `phone\|email` |
| match_type | VARCHAR(16), nullable | `pair` \| `phone` \| `email` \| null |
| time_delta_seconds | INT UNSIGNED, nullable | gap to the matched prior entry, UTC basis |
| classification | VARCHAR(32) | see Classification model below |
| is_cross_form | TINYINT(1) | 1 if the match came from the other supported form |
| action | VARCHAR(20) | always `shadow_only` in v0.1.x |
| created_at | DATETIME | UTC |

Keys: `PRIMARY KEY (id)`, `UNIQUE KEY (form_id, entry_id)` (P0-3),
`KEY (phone_fingerprint, created_at)`, `KEY (email_fingerprint, created_at)`,
`KEY (pair_fingerprint, created_at)` (P1-1), `KEY (classification)`,
`KEY (created_at)`. Charset/collation via `$wpdb->get_charset_collate()`.
`entry_id`/`prior_entry_id` are `BIGINT UNSIGNED`, consistent with
WordPress' own auto-increment ID convention. Varchar lengths are bounded
to the longest real value in each enum (`classification` VARCHAR(32)
comfortably fits `strong_mechanical_repeat`, the longest label at 24
chars; `action` VARCHAR(20) fits `shadow_only` at 11 chars).

No `phone`, `email`, or `name` column exists anywhere in this schema.
Schema is versioned via `VAID_LEADS_GUARD_DB_VERSION` (now `1.1.0`) +
`dbDelta()`; the upgrade gate is `VAID_Leads_Guard_DB::needs_upgrade()`,
a pure, directly unit-tested comparison (`tests/test-db-version.php`)
extracted specifically so activation/upgrade idempotency is provable
without a live WordPress install.

## Identity fingerprinting

Unchanged from v0.1.0: every fingerprint is
`hash_hmac('sha256', $normalized_value, $secret)` — see "Fingerprint key
lifecycle" above for the full lifecycle review. Because HMAC is
deterministic, the same normalized phone always produces the same
fingerprint on this install, which is exactly what duplicate-matching
needs (equality lookup) without ever storing or displaying the
plaintext value.

## Classification model

Matching against the *most recent* prior observation with an equal
fingerprint (checked in the order pair → phone → email — the strongest
available signal wins), now computed on a consistent UTC basis (P1-2):

| Gap | Label |
|---|---|
| ≤ 2 minutes | `strong_mechanical_repeat` |
| 2–10 minutes | `short_repeat` |
| 10 minutes – 24 hours | `repeat_same_day` |
| > 24 hours | `returning_enquiry` |
| no prior match | `new_identity` |
| match is in the *other* supported form | `cross_form_repeat` (overrides the time-bucket label) |

**Language calibration verdict:** reviewed against the brief's explicit
requirement that `short_repeat` (2–10m) never be reported as *proven*
mechanical, and that no aggregate ("66% mechanical") be presented as a
factual classification rather than a likelihood-labeled observation. No
defect found — the v0.1.0 label names and this document's own wording
already reflected that calibration; this round only re-confirms it
holds after the P1-2 UTC change.

## Known limitations

1. **No historical backfill.** The shadow log only observes submissions
   made *after* activation. The 1,079-entry audit dataset from the
   full-data audit is not, and cannot be, retroactively loaded into this
   table — it was analyzed offline precisely because no live
   database/export automation exists yet.
2. **Field-name coupling is fragile.** `VAID_Leads_Guard_Form_Map` hardcodes
   the phone/email field *names* observed in the September 2026 form
   schema exports. If a form's fields are renamed in the Fluent Forms
   builder, this map goes stale silently. The `vaid_leads_guard_field_map`
   filter exists to override this without editing plugin files.
3. **Secret rotation breaks continuity.** See "Fingerprint key
   lifecycle" above — a deliberate, documented trade-off.
4. **Concurrent-submission race can miss a cross-reference.** See
   "Concurrency" above — accepted, bounded, tested, not fixed.
5. **Two-form scope only.** Any other Fluent Forms form on the site
   (including the separate Sampoorna `vaid_ts_lead`/`vaid_ts_registration`
   pipeline) is explicitly out of scope and is never touched or observed.
6. **Single-site matching.** Fingerprints are salted per-install; they
   cannot be compared across a staging/production pair or after a site
   migration without carrying the same secret forward.
7. **Exact live Fluent Forms version unconfirmed.** The hook-contract
   evidence (above) is strong but is drawn from official documentation
   via search summaries, not from reading the installed plugin's own
   source on the live site — which this environment cannot reach. See
   "Deployment recommendation."
8. **No report pagination yet.** The admin Shadow Report screen shows
   only the most recent 50 rows; CSV export caps at 5,000 rows. Fine at
   current/expected volume (the audited baseline was ~1,079 submissions
   over several months across both forms), but will need real pagination
   before the table grows into the tens of thousands of rows. Not
   implemented this round — the brief explicitly said not to add growth
   machinery unless clearly necessary yet.
9. **No automated data-retention/deletion policy.** Per the brief,
   deliberately not added this round; growth is documented (limitation
   8) rather than auto-pruned.
10. **A site-wide database *connection* failure could still surface a
    WordPress-level `wp_die()`**, via `$wpdb`'s own `dead_db()` handling
    — but this applies to every plugin and page on the entire site
    identically; it is not something this plugin causes, amplifies, or
    could suppress, and is out of scope to "fix" here.

## Privacy verdict

Confirmed absent from every row in the schema: raw phone, raw email,
name, IP address, user agent, full form payload, and any URL/query
string. No OTP data of any kind exists in v0.1.x (OTP is not
implemented). This was true in v0.1.0 and remains true in v0.1.1
(re-verified by `tests/test-static-checks.php`'s schema grep and by
manual review against the brief's explicit "verify DB rows contain NO..."
checklist).

**One nuance made explicit in this round, not previously documented as
a caveat:** `entry_id` and `prior_entry_id` ARE references into Fluent
Forms' own entries table, which DOES hold raw PII. For an authorized
`manage_options` administrator — who already has direct access to
Fluent Forms' own entries screen — this is not a new information
disclosure; it is expected and acceptable. It does mean these two
columns should not be treated as safe to hand to someone who does *not*
already have Fluent Forms admin access, since they are a re-identification
path. The admin-facing Shadow Report screen and the CSV export both
already only ever show these as bare numeric IDs (never resolved to a
name/phone/email inline), and the CSV export additionally now runs every
cell through the formula-injection guard (P1-3). No code change was
needed for the entry_id nuance itself — only this explicit
documentation, per the brief's requirement.

## Rollback / removal plan

- **Deactivate** (Plugins screen): stops hook registration immediately.
  No data is touched; settings and the observation table remain intact.
  Reactivating resumes observation with full history preserved. Fluent
  Forms is entirely unaffected by deactivation — WordPress simply stops
  loading this plugin's PHP file, so there is nothing left to unhook.
- **Delete/uninstall** (Plugins screen, after deactivation): runs
  `uninstall.php`, which removes only the plugin's own options
  (`vaid_leads_guard_settings`, the HMAC secret). It deliberately does
  **not** drop the `{prefix}vaid_leads_guard_observations` table or its
  rows — that data holds no raw PII, so there is no privacy reason to
  force its deletion, and silently destroying an audit trail on
  uninstall is exactly what the brief prohibits.
- **Full manual removal** (if ever needed): an administrator can drop
  the table directly (`DROP TABLE {prefix}vaid_leads_guard_observations;`)
  and delete the `vaid_leads_guard_db_version` option — not automated by
  the plugin itself, by design.

## Testing strategy

`Normalizer`, `Classifier`, `Fingerprint`, `Csv_Sanitizer`, `Form_Map`,
and `DB::needs_upgrade()` are pure PHP with zero WordPress dependency,
guarded by `VAID_LEADS_GUARD_TEST_MODE` so they can be required directly
from the CLI. The rest of `DB`, `Observer`, and `Admin` depend on
`$wpdb`/WordPress hooks and are exercised indirectly:
`tests/test-scenarios.php` and `tests/test-concurrency.php` re-implement
the observer's exact match-then-classify decision path against a shared
in-memory fake table (`tests/helpers/fake-observation-store.php`),
covering every required scenario (same-phone/same-form at ≤2m, 2–10m,
≤24h, >24h; a distinct new identity; cross-form repeat; the concurrent-
submission race) without needing a live WordPress+MySQL environment.
`tests/test-static-checks.php` source-scans the shipped code for what
can't be captured as a runtime assertion: that neither the current nor
deprecated pre-insert/validation hook (in either naming form) is
referenced, that both required post-insert hooks ARE registered, that
`on_submission_inserted()`'s entire body is a single
`try { } catch ( Throwable $e ) { }`, that timestamps are handled in UTC
throughout, that the schema's concurrency-defense UNIQUE KEY exists,
that no plaintext OTP/secret pattern or raw phone/email column appears
anywhere, and that the chat-session-only codename never leaks into
shipped code. Run everything with `bash tests/run-all.sh`, which also
lints every PHP file, greps for secret/PII patterns, greps for both
hook-name forms of every forbidden pre-insert hook, and confirms no
files outside `vaid-leads-guard/` changed.

## Deployment recommendation

**PILOT-SAFE, with one explicit precondition: confirm the live site's
Fluent Forms version before activating**, even in shadow mode.

Everything this plugin *does* — post-insert only, dual-hook-registered,
fail-open on `Throwable`, no blocking, no PII storage, no network calls,
formula-injection-guarded export, concurrency-reviewed — is now
evidenced and tested to the extent this environment allows (187 test
assertions plus lint/grep/hook checks, all passing). The one input this
round could not verify directly is which Fluent Forms version
`vaidsics.com/anthropology` actually runs, because this environment has
no access to that site. The dual-hook registration means the plugin
should observe correctly across a wide range of plausible versions
without that confirmation — but "should, across a wide range" is not
the same as "confirmed," and the brief was explicit that an unconfirmed
contract should be reported as a blocker rather than guessed past
silently. Recommend: activate on a staging copy or during a low-traffic
window first, watch the Shadow Report screen for observations
accumulating as expected within the first few real submissions, THEN
consider it clear for a full pilot. This is not a HOLD — nothing found
in this round rises to "do not activate" — it is a "activate, but verify
it's actually observing before trusting the numbers."
