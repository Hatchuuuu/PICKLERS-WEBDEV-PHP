# Picklers repair evidence — 2026-10-11

The approved R01–R17 repairs and U01 are implemented, and all selected automated gates pass. Independent SOL repair review remains pending because the assigned agents reached the account usage limit. This report does **not** certify the application as 100% bug-free or give final R18 acceptance.

## Scope and recovery

- Preserved the existing layout, assets, business rules and migration work. No new product features or packages were added.
- U01 was explicitly authorized: Follow uses the existing Favorites behavior and storage.
- The working branch remains `migration/symfony-completion-20261010`, with the user's pre-existing uncommitted migration changes intact. No commit, reset, deployment or live database migration was performed.
- Before-repair recovery material is in `var/repair-audit-20261011/before/`: tracked binary patch, Git status and copies of previously untracked source/tests/docs. Reconstruct that baseline in a separate checkout before recovering individual files; do not reset this working directory or overwrite later user changes. Secrets and private uploads are excluded.

## Defect register

All rows below are **implemented and tested by the coordinator; independent review pending**. Detailed original evidence and acceptance criteria remain in the repair plan.

| ID | Repair and preserved behavior | Focused evidence |
|---|---|---|
| R01 | Escape venue values for their HTML/JavaScript context; safe payment-card lookup | `venue-context.test.js`; `VenueHandlerTest.php` covers actual Twig handlers with quotes, backslashes, newlines and injection-shaped names |
| R02 | Bind staff grants to the selected registered account ID, retaining canonical name/email | `StaffIdentityTest.php`, `staff-selection.test.js`; browser selection recorded `usr_fx_p1` |
| R03 | Reject missing/unauthorized tournament facility scope before fetching records | `TournamentScopeTest.php`: five scope/authorization cases |
| R04 | Keep selected facility ID in owner navigation and API URLs | `owner-scope.test.js`, `OwnerUrlTest.php`, `OwnerFacilitySelectionTest.php`: real HTTP selects a second owned facility, changes only it, denies another owner's facility |
| R05 | Resolve an actual listed court before accepting or charging a reservation | `BookingCourtIdentityTest.php`: unknown ID/name rejected, wallet unchanged, legitimate legacy name resolves to canonical ID |
| R06 | Account deletion, cancellations and refunds commit together; fresh account lock prevents bookings after deletion | `AccountTest.php`: refused cancellation and injected final-write failure roll back; `AccountConcurrencyTest.php`: two processes race deletion and booking |
| R07 | Interpret booking dates in Manila, retain future-year dates, prevent expired/blocked fallback slots | `booking-date.test.js`, `QuickBookDefaultsTest.php`, `quick-book-availability.test.js`; browser excludes Court 1's booked hour from an October 14 scan |
| R08 | Ended-early bookings retain history but release remaining availability | `BookingCourtIdentityTest.php`: availability and overlapping new booking pass while the old booking status/price stay unchanged |
| R09 | Mark only the joined Open Play session and dated occurrence | `DiscoverOccurrenceTest.php`: independent courts/sessions and dated booking keys; shared `BookingRules::joinedOccurrenceKeys()` |
| R10 | Preserve legitimate zero fees throughout checkout | `free-openplay.test.js`, `voucher-zero.test.js`: fixed entry fee and authoritative zero voucher total remain zero |
| R11 | Validate venue address/hours against existing database limits before writes | `OwnerPagesTest.php`, `ApplicationsTest.php`: oversized current and legacy application inputs rejected without provisioning |
| R12 | Keep application submission writes atomic; remove newly uploaded files when persistence fails | `UploadAtomicityTest.php`: injected application/account and logo-write failures leave no new orphan file or partial application |
| R13 | Notification UI changes only after persisted success; rejected deletes preserve the card | `notification-persistence.test.js` for player and owner; `notification-swipe.test.js` verifies the next gesture starts from its restored position |
| R14 | Serialize post-like toggles and derive the counter from persisted liker rows | `LikeConcurrencyTest.php`: two real processes; `post-like.test.js`: rejected requests keep the old client state |
| R15 | Apply a common lock before checking/removing administrator privileges or roles | `PrivilegeConcurrencyTest.php`: concurrent CLI revokes and authenticated-service web demotions preserve an administrator; `UsersTest.php` retains ordinary authorization/self-action guards |
| R16 | Compare migration identities through Doctrine, not version-row counts | `SystemHealthTest.php`: an unknown executed version cannot hide a missing current version; browser health reports Up to date on the complete fixture schema |
| R17 | Unknown maintenance commands and failed constraint changes return failure exit codes | `MaintenanceExitTest.php`: real invalid CLI command and isolated DDL-failure harness; no cleanup or constraint mutation ran against live data |
| U01 | Follow reads/writes Favorites and restores Following on reopen/reload | `follow-favorite.test.js`, existing discovery tests; actual browser reload showed Remove from favorites and Following |

## Second targeted audit

The coordinator checked neighboring callers after the initial fixes and reproduced these additional defects before repairing them:

1. Server-rendered venue names containing a newline broke an inline Favorites handler. Twig now emits JSON inside its normally escaped attribute; actual rendered handlers execute safely in `VenueHandlerTest.php`.
2. A synthetic PHP file in the general Apache upload directory executed. `public/uploads/.htaccess` now denies executable extensions across the upload tree. The same harmless marker changed from HTTP 200/executed to HTTP 403 under both Apache layouts. Permit probes remained forbidden. The probe files were removed.
3. The PHP development router served a synthetic general-upload `.cmd` file. Its deny rule now matches the general upload protection; `DevelopmentRouterTest.php` includes this case. The existing E2E router already applies that broader rule.
4. Quick Book could scan/check out a zero-length expired interval. Both entry points now reject it. The template also held an old September date despite selecting today's date pill; both hidden dates and the summary now use the existing `upcoming_days()` data.
5. Quick Book's scanner trusted a court's overall status instead of its selected hourly availability. It now checks the existing slot API for every selected hour and fails closed on an unsuccessful response. The browser scan offered only Court 2 when Court 1's booked hour overlapped the requested range.
6. Applying a voucher with an authoritative total of zero displayed the original price. Null fallback now preserves zero.
7. A failed swipe delete restored the card visually but retained the old gesture offset. Pointer-down now reads its restored position.
8. A late empty-query autocomplete response could replace the current staff/walk-in search. Both callers discard responses for a different current input value; the browser retest showed Paula Player and retained her stable ID.

These repairs reuse existing workflows. They do not create subscriptions, staff invitations, payment integrations or new screens.

## Runtime and local evidence

Verified runtime: XAMPP PHP 8.2.12, installed Symfony 7.4.20, MariaDB 10.4.32, Node 24.16.0. Composer lockfiles were retained.

Tests used a separate MariaDB data directory under `var/migration-audit-20261010/mysql/`, never XAMPP's normal database data directory. PHP runners used only disposable `picklers_test`, `picklers_admin_test` and schema-bootstrap test databases. Browser checks used newly installed and migrated `picklers_repair_e2e` with synthetic fixtures and isolated private documents/state.

Two temporary loopback Apache configurations exercised:

- Port 8089, project `public/` document root.
- Port 8090, real `htdocs` layout with `/PICKLERS%20WEBDEV%20PROJECT/` and the project's actual rewrite files.

Observed browser checks: root player discovery; Follow/Favorites save and reload; owner sign-in and portal switch under the subdirectory; owner booking approval and persistence; owner mobile dashboard and staff search/selection; mobile Quick Book collision filtering and payment review; sign-out; privileged-admin sign-in; subdirectory admin navigation/System & Audit. Captured warning/error logs were empty for these successful Apache flows. Mobile width was 390 pixels; the temporary viewport override was reset. No pixel-identical comparison is claimed.

Screenshots and local command logs are under `var/repair-audit-20261011/` (ignored): `follow-persisted.png`, `owner-mobile.png`, `quick-book-mobile.png`, `admin-health.png`, `verification-final.log`, `rehearsal-final.log`.

The upgrade rehearsal used a **synthetic legacy-schema source** `picklers_repair_upgrade_test`, copied from the browser fixture database and rolled back in its own isolated state directory. The rehearsal upgraded a second disposable copy, reconciled 29 measured counts/ID/money/status facts, preserved five nested tournament records, rolled back, restored the dump, and finally rechecked source counts, IDs, totals and columns. All checks passed; rehearsal dumps and its disposable target were removed.

An initial rehearsal against the already-migrated browser database failed the legacy tournament expectation. This script is a legacy-to-current rehearsal: it assumes a legacy source and newly added admin privileges/status columns. That failed run is not evidence of lost source data or a failed application migration; it was cleaned up and replaced by the appropriate legacy-source rehearsal. Already-current sources need a different expectation set before using this legacy-specific script.

One combined verification run was invalidated by accidentally overlapping a separate test runner's database rebuild. Its 13 suite errors are not accepted test evidence. A serial combined rerun is required and recorded below.

## Remaining acceptance and operational notes

- Independent SOL review is blocked by the account usage limit. No repaired item is marked independently accepted, and R18 remains pending until that review and all outstanding manual acceptance checks are completed.
- The actual local application database was neither upgraded nor proven ready with these synthetic tests. Use the existing setup/backup instructions and a verified database backup before any real-data upgrade; do not substitute fixture evidence for that acceptance.
- Broad backend workflows are covered by the existing business and Symfony suites. This repair cycle did not manually repeat every registration, password, payout, tournament, community, messaging, QR and administration workflow at every viewport and URL base. There is no claim of exhaustive browser or concurrency coverage.
- Legacy staff rows without a stable `user_id` now fail closed. A venue owner must revoke and re-grant these existing memberships by selecting the registered player in Staff Management. No guessed email backfill was applied.
- The all-gates command now also requires Node on PATH for existing frontend syntax and regression tests. Node 24.16.0 was verified here; no JavaScript package install is needed.
- Real gateway settlement, external payouts, dormant social sign-in and notification enhancements remain outside scope. The browser health page explicitly reported development-only simulated top-ups and no gateway.
- Graphify was updated with AST-only extraction. Its warnings about JSON/config files producing zero AST nodes describe graph coverage, not verified application failures.

## Final command results

- `php scripts/verify.php`: **15 gates passed**, exit 0. PHP syntax: 200 files; Composer strict/platform/audit passed (no advisories); app/owner JavaScript syntax passed; 18 frontend regression tests passed; dev/test/prod container lint passed; 59 Twig templates passed; 48 Symfony routes verified; production cache warmup passed; fresh/stale/trusted schema bootstrap scenarios passed.
- Business suite: **254 assertions passed**.
- Symfony suite: **181 tests, 1,404 assertions passed**. This is the serial final run, including concurrency, default-date and development-router acceptance tests.
- `php scripts/rehearse-upgrade.php picklers_repair_upgrade_test`: upgrade/rollback/restore and final source-integrity checks all passed, exit 0.
- Actual development router smoke on port 8091, wrapped only to select synthetic data/state: home, auth page and app JavaScript HTTP 200; the real `facilities` API HTTP 200, `success: true`, 10 facilities; private permits and general executable upload paths HTTP 403. An initial attempt to isolate this server with PHP `auto_prepend_file` did not affect the router and produced a missing-fixture-database error; the explicit wrapper corrected the harness without changing application code or `.env`. A mistakenly named `get_facilities` action returned 400; the verified action is `facilities`.
- Root and subdirectory Apache general executable upload paths both returned HTTP 403 after the repair.
- `graphify update .`: succeeded (AST-only); final graph contains 2,239 nodes and 4,536 edges.
- `git diff --check`: passed with the repository's normal CRLF configuration. Temporary audit browser/viewport and servers were cleaned up; synthetic source databases remain in the isolated audit data directory for reproducibility. Your normal XAMPP services were not started or stopped.
