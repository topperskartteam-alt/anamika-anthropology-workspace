# Changelog

## 0.1.1 — Adversarial red-team + bounded repair

Red-team pass over v0.1.0 (build/test only, no deployment). Full defect
table, evidence, and deployment recommendation in ARCHITECTURE.md.

- **Fixed (P0):** observer now registers on BOTH the current Fluent
  Forms hook `fluentform/submission_inserted` and the deprecated
  `fluentform_submission_inserted` it replaced in FF 5.0 (removal
  scheduled for FF 7.0). v0.1.0 registered on the deprecated name only,
  which would have gone permanently silent on any site past that
  removal.
- **Fixed (P0):** `on_submission_inserted()` now catches `Throwable`,
  not just `Exception`, closing a gap where a PHP `Error` could have
  escaped the fail-open guarantee.
- **Fixed (P0):** added `UNIQUE KEY (form_id, entry_id)` to the schema
  so the dual-hook registration above can never create two rows for one
  real submission; a rejected duplicate insert is recognized as
  expected and not logged as an error.
- **Fixed (P1):** timestamps are now stored and compared in UTC
  throughout (`current_time( 'mysql', true )` + explicit
  `DateTimeZone('UTC')`), removing a latent DST-unsafe naive local-time
  diff (no practical impact for this site's India timezone, which
  observes no DST, but not safe in general as written in v0.1.0).
- **Fixed (P1):** replaced four single-column indexes with three
  composite `(fingerprint, created_at)` indexes matching the actual
  query pattern used by duplicate lookup.
- **Fixed (P1):** CSV export now runs every cell through a
  formula/CSV-injection guard (`VAID_Leads_Guard_Csv_Sanitizer`) before
  writing, defense-in-depth even though no current column can trigger
  it.
- **Fixed (P2):** a submission with neither a valid phone nor a valid
  email no longer produces a noise observation row with nothing
  matchable in it.
- **Fixed (P2):** a failed observation insert (other than the expected
  UNIQUE-KEY duplicate case above) is now logged for admin visibility.
- **Documented, not code-changed:** fingerprint key lifecycle (already
  sound, now explicit); the concurrent-submission race (bounded,
  proven non-corrupting by a new deterministic simulation, intentionally
  not "fixed" per the brief's own anti-overengineering instruction); the
  `entry_id`/`prior_entry_id` re-identification nuance in CSV export.
- **Tests:** grew from 137 to 187 assertions (new: CSV-sanitizer,
  DB-version/migration-idempotency, concurrency simulation, and
  significantly hardened static checks — both hook-name forms, UTC
  usage, UNIQUE KEY presence, fail-open structure). All passing.
  `tests/run-all.sh` also now greps for pre-insert hooks in both naming
  forms and confirms no files outside `vaid-leads-guard/` changed.

## 0.1.0 — Shadow-mode idempotency build

- Initial release. Shadow-mode observer only: no blocking, no OTP, no
  live WordPress changes, no deployment.
- Observes Fluent Forms `fluentform_submission_inserted` for the two
  locked forms (Form 9 — Course Page, Form 1 — Reserve My Free Seat).
- Normalizes phone (India-variant aware) and email; rejects malformed
  values rather than guessing.
- Stores only salted HMAC-SHA256 identity fingerprints, never raw
  phone/email/name.
- Classifies each submission against the most recent matching prior
  submission: `strong_mechanical_repeat` (≤2m), `short_repeat` (2–10m),
  `repeat_same_day` (10m–24h), `returning_enquiry` (>24h),
  `cross_form_repeat` (match in the other supported form),
  `new_identity` (no match).
- Minimal admin UI: per-form/master monitoring toggles, shadow report
  screen, safe (fingerprint-prefix-only) CSV export.
- Uninstall preserves observation-table data by default.
