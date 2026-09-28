# VAID Leads Guard

Shadow-mode duplicate-submission observer for the two Fluent Forms lead
forms on `vaidsics.com/anthropology`.

**v0.1.0 is shadow mode only.** It observes and logs likely duplicate
submissions for reporting. It never blocks, gates, merges, deletes, or
alters a real Fluent Forms entry, and it makes no changes to WordPress,
forms, notifications, redirects, or Meta events.

## Why this exists

A full-data audit (2026-09-28) of the two lead forms found a real,
measured duplicate-submission rate — 17.9% by phone across 1,079
combined entries, 21.1% on Form #9 (Course Page) and 13.3% on Form #1
(Reserve My Free Seat). About two-thirds of duplicate entries look
mechanical (same phone resubmitting within 10 minutes); the rest look
like genuine repeat enquiries. This plugin is the first, observation-only
step toward a dedupe/OTP system — establishing a live, ongoing measurement
before anything is ever blocked.

## Supported forms (locked mapping)

| Form | Title | URL | WP Page ID |
|---|---|---|---|
| 9 | Course Page | `/anthropology/optional-coaching/` | 8621 |
| 1 | Reserve My Free Seat | `/anthropology/workshop/` | 7489 |

## Installation (not yet deployed)

1. Upload `vaid-leads-guard-v0.1.0.zip` via **Plugins → Add New → Upload Plugin**.
2. Activate. This creates one new database table
   (`{prefix}vaid_leads_guard_observations`) and a per-install HMAC
   secret. No existing data is touched.
3. Go to **VAID Leads Guard → Settings** to confirm monitoring is on
   for both forms (on by default).
4. Go to **VAID Leads Guard → Shadow Report** to see observations
   accumulate as new submissions come in. It cannot see submissions
   made before activation.

This plugin has been built, versioned, and tested in this session but
**has not been deployed to the live site.** Deployment is a separate,
explicit action for the site owner.

## Privacy

No second copy of raw leads is created. The plugin never stores phone
numbers, email addresses, or names — only:

- a salted HMAC-SHA256 fingerprint of the normalized phone, email, and
  phone+email pair (irreversible; used only for equality matching)
- the Fluent Forms `entry_id` and `form_id` (already public inside your
  own Fluent Forms data)
- a time-delta-since-prior-match and a classification label
- `action = 'shadow_only'` on every row, always

The admin report and CSV export show only a short (10-character)
fingerprint prefix — never the fingerprint in full, and never the
underlying phone/email.

## What v0.1.0 does NOT do

- Does not block any submission
- Does not gate, delay, or fail Fluent Forms' own submission-creation
  flow (it hooks in strictly after the entry is already saved)
- Does not implement OTP
- Does not implement blocking/enforcement dedupe
- Does not change notifications, redirects, or Meta/Pixel behavior
- Does not delete data on uninstall (see `uninstall.php`)
- Does not make any network calls

See `ARCHITECTURE.md` for the technical rationale, schema, and known
limitations, and `tests/` for the automated test/lint/static-check
evidence (run `bash tests/run-all.sh`).
