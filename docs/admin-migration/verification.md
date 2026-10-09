# Verification log

All results below were produced in this working tree on 6 Oct 2026 (Asia/Manila) with PHP 8.2.12 CLI, Apache 2.4 + PHP 8.2.12 (XAMPP), MariaDB 10.4.32. Only disposable databases were written: `picklers_test`, `picklers_admin_test`, `picklers_test_boot`, `picklers_e2e`, `picklers_upgrade_rehearsal`. The live `picklers_db` was only **read** (anonymous HTTP checks and a read-only dump for the rehearsal) and was confirmed unchanged afterwards.

## One-command gate run — `php scripts/verify.php`

```
php -l (all project PHP files)               PASS  190 files
composer validate --strict                   PASS  ./composer.json is valid
composer check-platform-reqs                 PASS
composer audit                               PASS  No security vulnerability advisories found.
lint:container (dev)                         PASS
lint:container (test)                        PASS
lint:container (prod)                        PASS
lint:twig templates                          PASS  All 25 Twig files contain valid syntax.
debug:router (admin routes)                  PASS  13 Symfony routes
cache:warmup (prod)                          PASS
schema bootstrap (fresh + stale stamp)       PASS
legacy suite: php tests/run.php --fresh      FAIL  253 passed, 1 failed
admin suite: vendor/bin/phpunit              PASS  OK (75 tests, 552 assertions)
```

### The one legacy failure (not caused by the migration)
`OwnerProvisioningTest`: "a newly provisioned facility appears in getFacilities()". It fails because of an **uncommitted edit that was already in the working tree before this work**: `getFacilitiesUncached()` now filters `WHERE f.is_verified = 1 AND f.image IS NOT NULL AND f.image != ''`, and a newly provisioned facility has no image, so it is hidden from Discover. Whether that is intended is a product decision; the edit was preserved untouched. See progress.md → "Needs your decision".

### Baseline → after (legacy suite)
| | Before | After |
|---|---|---|
| `php tests/run.php` | 31 passed, 13 suite errors (missing `picklers_test` tables) | 253 passed, 1 failed (above) — also run against the Doctrine-migrated schema |

Root causes fixed: directory schema stamp trusted without checking tables; stale `.seeded` flag; seed users skipped because the dev account was inserted first; `seedCourtAttributeCatalog()` never called.

## Admin functional/integration suite — `php vendor/bin/phpunit`
`OK (75 tests, 552 assertions)` — rebuilds `picklers_admin_test` from nothing on every run (legacy bootstrap → migrations → synthetic fixtures → privileged grant via the CLI).

| Class | Covers |
|---|---|
| SecurityTest (17) | anonymous/player/owner/admin/privileged, deactivated, stale role, idle timeout, password-reset invalidation, CSRF, 405, 404, every action guarded, every read action answers, `/api` + `/api.php` aliases, `switch_user`, route ownership, sub-directory base URLs, headers + correlation id, no internals in errors |
| ApplicationsTest (7) | approve/reject rules, notes/reasons, history, **4-process concurrent approval → 1 facility**, provisioning failure rollback, filters, document delivery + traversal/missing/unauthorised |
| FacilitiesTest (4) | real aggregates, suspension blocking court booking / Open Play / owner hosting / Discover, existing bookings untouched, court maintenance, governed edits |
| BookingsTest (8) | stale edits, forbidden transitions, refund once across admin/player paths, legacy refunds recognised, external payments, **4-process concurrent cancel → 1 refund**, Open Play capacity and seat release, filters/totals |
| UsersTest (8) | escalation refused (and audited as denied), self-protection, privileged role management, last privileged admin, deactivation integrity, deletion only without history, password policy + session invalidation, truthful labels |
| WalletTest (7) | privilege, validation, exact centavos, no overdraft, idempotent replay, **5-process duplicate key → 1 entry**, **5 concurrent debits → exactly 2 succeed, balance never negative** |
| PromosTest (6) | validation, Manila end-of-day expiry, toggle rules, archive vs delete, exhausted promo refused at commit with nothing charged, **4-process race for the last use → 1 redemption** |
| ModerationTest (3) | flag/hide/restore/dismiss/resolve lifecycle, public feed/like/comment exclusion, comment counts |
| NotificationsTest (3) | notice dedupe, broadcast privilege + preview + confirmed count + replay, per-admin read state |
| ImpersonationTest (5) | eligibility, banner/restrictions/closed console/return, expiry, revocation, action audit |
| LedgerAndReportsTest (7) | payment evidence totals, wallet reconciliation, CSV filters + formula neutralisation + no secrets, single KPI definition, Manila day boundaries, append-only audit + redaction + no audit-editing route, list bounds/validation |

## Upgrade, rollback and restore rehearsal — `php scripts/rehearse-upgrade.php`
Read-only dump of `picklers_db` → disposable `picklers_upgrade_rehearsal`, production mode (no demo seeding):
```
read-only dump of source                                       PASS  (124 KiB)
import into disposable copy                                    PASS
copy matches source exactly                                    PASS
doctrine:migrations:migrate                                    PASS
legacy rows, ids and money totals unchanged                    PASS  (29 facts reconciled)
admin tables created                                           PASS  (7/7)
existing facilities stay active                                PASS  (8 active)
no administrator auto-promoted to privileged                   PASS
re-running migrations is a no-op                               PASS
rollback to baseline (down migrations)                         PASS
schema columns back to original                                PASS
data identical after rollback                                  PASS
admin tables removed by rollback                               PASS
restore from dump                                              PASS
OK — upgrade, rollback and restore rehearsed; source untouched
```
Reconciled facts: row counts of all 22 legacy data tables, user/booking/facility id sets (hashes), total wallet balances, total booking prices, credit/debit totals, booking status distribution. The dump (personal data) is deleted after the run.

## Fresh install from the lockfile
Isolated copy in the session scratch directory: `composer install` → "Installing dependencies from lock file … 86 installs"; `lint:container` OK; `lint:twig` OK; 13 admin routes; `cache:warmup --env=prod` OK.

## HTTP checks through Apache (anonymous, read-only) — `http://localhost/PICKLERS%20WEBDEV%20PROJECT`
| Request | Result |
|---|---|
| GET `/admin`, `/admin.php?tab=users`, `/admin/document?file=x.png` | 302 → `/PICKLERS%20WEBDEV%20PROJECT/auth?next=admin` |
| GET `/admin/nonexistent` | 404 (Symfony) |
| POST `/admin/api` admin_stats | 401 JSON |
| POST `/api` admin_update_role; GET `/api.php?action=switch_user` | 401 JSON (Symfony) |
| GET `/api?action=facilities` | 200 (legacy, unchanged) |
| GET `/`, `/auth` | 200; `/app`, `/owner` → 302 to sign-in (legacy, unchanged) |
| GET `/public/admin` (public/ in URL) | 302 → `/PICKLERS%20WEBDEV%20PROJECT/public/auth?next=admin` |
| GET `/assets/css/admin.css`, `/assets/js/admin.js` | 200 |
| Headers | `X-Request-Id`, `X-Frame-Options: SAMEORIGIN`, `Cache-Control: no-store, private` |

## Browser verification (built-in browser, fixture server `php -S 127.0.0.1:8099 -t public scripts/e2e-router.php`, database `picklers_e2e`)
Signed in through the real sign-in form with fixture accounts (`scripts/seed-fixtures.php`).

- All 10 panels, both sub-views of Moderation and Ledger, and all six detail dialogs render (status 200), invalid query values show the "Ignored invalid …" notice; unknown admin paths 404.
- Sidebar navigation loads panels in place, updates URL/title/history, moves focus to the panel heading; the top search relabels to the active panel's scope and hides where there is nothing to search.
- Application approval end to end (stacked dialog, note, provisioning, audit, notification) — persisted values checked in MySQL.
- Private documents stream from the isolated fixture store; each view audited.
- Flag-for-review with an empty reason: focus moves to the field, `aria-invalid`, visible error; input kept.
- Row menu: fixed-positioned (not clipped), `menu`/`menuitemradio` roles, arrow-key navigation, Enter opens the wallet dialog.
- Wallet adjustment **double-click** → one transaction (₱80.50 + ₱12.34 = ₱92.84, actor and kind recorded).
- Broadcast: preview shows exact audience (2 fixture owners), fields lock, button reads "Send to 2 account(s)"; **double-click** → one broadcast, 2 notifications.
- Impersonation: reason required; player app shows the banner with "Return to admin"; `/admin` returns the 403 notice page; password change refused (403) **and audited** (gap found and fixed during this check); Return to admin restores the admin and is audited.
- Player regression: sign-in, `me`, bookings, wallet, feed, facilities, matches; booking with Pickle Credits (debit tagged `booking_payment` + booking id), booking a maintenance court refused, self-cancel refunded once with key `refund:booking:<id>`.
- Owner regression: owner portal dashboard/courts/earnings/settings render; `approve_booking` via the shared rule (repeat → "already confirmed"); `decline_booking` refunds once (repeat refused).
- Responsive: 1440×900 (all tables fit, row actions pinned when a table must scroll), 720×450 (≈200% zoom: no page-level horizontal scroll), 375×812 (no page-level horizontal scroll, drawer opens/focuses/closes on Escape and returns focus, detail dialogs become bottom sheets, table actions reachable at the end of the labelled scroll region).

## Before/after screenshots (same fixtures, same 1440×900 / 375×812 viewports)
`screenshots/before/` (legacy console, 14 images) and `screenshots/after/` (Symfony console, 21 images).

Retained: logo + shimmer wordmark, navy surfaces, PICKLERS green active state, sidebar groupings/order and labels, avatar rings, card/table layout, toast/dialog style, Inter/Montserrat.

Deliberate, documented refinements:
| Area | Change | Why |
|---|---|---|
| Labels | "Analytics BI" → "Analytics & Reports"; "Lead Developer"/"System" → "System & Audit"; "SUPER_ADMIN" badge → real role labels ("Admin", "Privileged admin", "Owner") | Truthful wording (A05) |
| KPIs | Same 4-column cards, now 8 real figures with short captions + expandable definitions; no "dispute-free" | A04 / R01 |
| Headings & tables | Title 900→800 weight, 26→24px; table labels 11.5px/700 (was 11px/800); numbers right-aligned, tabular | Calmer, readable (A13) |
| Status pills | Text + shape marker (dot / square / diamond) | Not colour-only |
| Tables | Scrollable labelled regions; row actions pinned on desktop/tablet; IDs/names open details | Baseline cut off actions on phones and clipped the user menu |
| Search | One contextual top search; facilities' second search box removed | A10 |
| Bell | Real unread state per admin | A12 |
| Moderation | Placeholder replaced by queue + content views | A02 |

Known visual limitation: the impersonation banner is sticky at the top of player/owner pages and covers the top ~44px of the fixed player sidebar (its logo) while impersonating. Functional controls remain visible.

## Not verified / limits
- No formal WCAG audit or screen-reader session; checks were keyboard, focus, roles, labels, zoom and reduced-motion CSS.
- No load/performance testing; concurrency tests use 4–5 parallel processes.
- Real browsers other than the built-in Chromium pane were not tested.
- The live `picklers_db` has **not** been migrated (deployment decision; see setup-and-rollback.md). Until it is, the admin console on the XAMPP site returns a 503 "database schema is out of date" page for signed-in admins; player/owner pages keep working because every new column is optional in legacy code.
