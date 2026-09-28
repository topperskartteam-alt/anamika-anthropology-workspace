# Changelog

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
