# VAID Leads Guard v0.1.0 — Architecture

## Hook choice and why

**Chosen hook:** `fluentform_submission_inserted( $insertId, $formData, $form )`

This fires *after* Fluent Forms has already written the submission to
its own `{prefix}fluentform_submissions` table. For a shadow-mode
observer that must guarantee zero interference with lead capture, this
is the only defensible choice:

- The lead already exists by the time our code runs, so nothing this
  plugin does — an exception, a slow query, a bug — can prevent, delay,
  or corrupt the real submission. `on_submission_inserted()` wraps its
  entire body in a try/catch and swallows all errors to `error_log()`
  rather than ever surfacing to the visitor.
- A pre-insert/validation hook (e.g. `fluentform_before_insert_submission`,
  `fluentform_validation_errors`) is explicitly *not* used here. Those
  hooks run before the entry exists and are the right place for a
  future *blocking* dedupe or OTP-gate version — but using one in v0.1
  would blur the shadow-mode guarantee and risk fabricating a pre-insert
  contract shape this plugin hasn't actually validated against a live
  Fluent Forms install. `tests/test-static-checks.php` enforces this
  hook boundary by grepping the observer source for any pre-insert hook
  name.

**Not used, and why:** any hook, filter, or `wp_die()`/`wp_send_json_error()`
call that could alter Fluent Forms' own response to the browser. Static
tests assert none of these appear in the observer.

## Data model

Table: `{wp_prefix}vaid_leads_guard_observations`

| Column | Type | Notes |
|---|---|---|
| id | BIGINT UNSIGNED PK | |
| form_id | SMALLINT UNSIGNED | 9 or 1 |
| entry_id | BIGINT UNSIGNED | Fluent Forms' own entry ID |
| prior_entry_id | BIGINT UNSIGNED, nullable | matched prior entry, if any |
| prior_form_id | SMALLINT UNSIGNED, nullable | form of the prior match (enables cross-form detection) |
| phone_fingerprint | CHAR(64), nullable | HMAC-SHA256 hex of normalized phone |
| email_fingerprint | CHAR(64), nullable | HMAC-SHA256 hex of normalized email |
| pair_fingerprint | CHAR(64), nullable | HMAC-SHA256 hex of `phone\|email` |
| match_type | VARCHAR(16), nullable | `pair` \| `phone` \| `email` \| null |
| time_delta_seconds | INT UNSIGNED, nullable | gap to the matched prior entry |
| classification | VARCHAR(32) | see Classifier below |
| is_cross_form | TINYINT(1) | 1 if the match came from the other supported form |
| action | VARCHAR(20) | always `shadow_only` in v0.1 |
| created_at | DATETIME | |

No `phone`, `email`, or `name` column exists anywhere in this schema.
Schema is versioned via `VAID_LEADS_GUARD_DB_VERSION` + `dbDelta()`, so
future versions can add columns (e.g. a blocking decision, an OTP
status) without a destructive migration.

## Identity fingerprinting

Every fingerprint is `hash_hmac('sha256', $normalized_value, $secret)`,
where `$secret` is a 64-character random value generated once on
activation and stored in `wp_options` (never displayed in the admin UI,
never logged). Because HMAC is deterministic, the same normalized phone
always produces the same fingerprint on this install — which is exactly
what duplicate-matching needs (equality lookup) without ever storing or
displaying the plaintext value. This directly answers the brief's "if a
reliable lookup absolutely requires normalized plaintext, stop and
justify" instruction: it doesn't — HMAC equality is sufficient, and this
is why plaintext is never stored.

## Classification model

Matching against the *most recent* prior submission with an equal
fingerprint (checked in the order pair → phone → email — the strongest
available signal wins):

| Gap | Label |
|---|---|
| ≤ 2 minutes | `strong_mechanical_repeat` |
| 2–10 minutes | `short_repeat` |
| 10 minutes – 24 hours | `repeat_same_day` |
| > 24 hours | `returning_enquiry` |
| no prior match | `new_identity` |
| match is in the *other* supported form | `cross_form_repeat` (overrides the time-bucket label) |

Per the v0.1 brief, a short gap is treated as a *likelihood signal*, not
proof of accidental resubmission — the label names reflect that
(`strong_mechanical_repeat`, not `confirmed_duplicate`).

## Known limitations

1. **No historical backfill.** The shadow log only observes submissions
   made *after* activation. The 1,079-entry audit dataset is not, and
   cannot be, retroactively loaded into this table by v0.1 — it was
   analyzed offline (see the control-room audit report) precisely
   because no live database/export automation exists yet.
2. **Field-name coupling is fragile.** `VAID_Leads_Guard_Form_Map` hardcodes
   the phone/email field *names* observed in the September 2026 form
   schema exports (`numeric_field`/`email` for Form 1, `input_text`/`email`
   for Form 9). If a form's fields are renamed in the Fluent Forms
   builder, this map goes stale silently — matching would simply stop
   working, with no error. The `vaid_leads_guard_field_map` filter
   exists so an admin/developer can override this without editing
   plugin files, but there is no automatic re-detection in v0.1.
3. **Secret rotation breaks continuity.** If the HMAC secret is ever
   regenerated (e.g. after an uninstall/reinstall), fingerprints for the
   same real-world phone will no longer match older rows. This is a
   deliberate trade-off (never storing plaintext) rather than a bug, but
   it means the observation history has a hard reset point whenever that
   happens.
4. **Timestamp precision.** The current-submission timestamp is
   `current_time('mysql')` taken inside the post-insert hook, not the
   `created_at` Fluent Forms itself recorded. In virtually all cases
   these are the same second; under significant hook-queue latency
   they could theoretically drift by a few seconds, which only matters
   right at the 2-minute/10-minute/24-hour bucket boundaries.
5. **Two-form scope only.** Any other Fluent Forms form on the site
   (including the separate Sampoorna `vaid_ts_lead`/`vaid_ts_registration`
   pipeline) is explicitly out of scope and is never touched or observed.
6. **Single-site matching.** Fingerprints are salted per-install; they
   cannot be compared across a staging/production pair or after a site
   migration without carrying the same secret forward.

## Rollback / removal plan

- **Deactivate** (Plugins screen): stops hook registration immediately.
  No data is touched; settings and the observation table remain intact.
  Reactivating resumes observation with full history preserved.
- **Delete/uninstall** (Plugins screen, after deactivation): runs
  `uninstall.php`, which removes only the plugin's own options
  (`vaid_leads_guard_settings`, the HMAC secret). It deliberately does
  **not** drop the `{prefix}vaid_leads_guard_observations` table or its
  rows — that data holds no raw PII, so there is no privacy reason to
  force its deletion, and silently destroying an audit trail on
  uninstall is exactly what the v0.1 brief prohibits. A future version
  may add an explicit, separately-confirmed "delete all shadow-audit
  data" admin action.
- **Full manual removal** (if ever needed): an administrator can drop
  the table directly (`DROP TABLE {prefix}vaid_leads_guard_observations;`)
  and delete the `vaid_leads_guard_db_version` option — this is not
  automated by the plugin itself, by design.

## Testing strategy

`Normalizer`, `Classifier`, `Fingerprint`, and `Form_Map` are pure PHP
with zero WordPress dependency, guarded by `VAID_LEADS_GUARD_TEST_MODE`
so they can be required directly from the CLI. `DB`, `Observer`, and
`Admin` depend on `$wpdb`/WordPress hooks and are exercised indirectly:
`tests/test-scenarios.php` re-implements the observer's exact
match-then-classify decision path against an in-memory fake table,
covering every required scenario (same-phone/same-form at ≤2m, 2–10m,
≤24h, >24h; a distinct new identity; cross-form repeat) without needing
a live WordPress+MySQL environment. `tests/test-static-checks.php`
source-scans the shipped code for the things that can't be captured as
a unit assertion: that no pre-insert hook is referenced, that no
blocking/termination call exists in the observer, that no plaintext
OTP/secret pattern or raw phone/email column appears anywhere, and that
the chat-session-only codename (see naming rule in the audit brief)
never leaks into shipped code. Run everything with `bash tests/run-all.sh`.
