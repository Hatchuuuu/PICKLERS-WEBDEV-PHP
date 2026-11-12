# PICKLERS flow audit and repair — October 26, 2026

This record covers the repository state and checks performed during this audit. Earlier October reports are historical evidence, not fresh verification. Verification results and their limits are recorded below.

## Baseline and preservation

- The starting checkout had extensive uncommitted Symfony, persistence, Docker, frontend, and test changes. They were preserved. Work continued on `codex/flow-audit-20261026` without resetting, stashing, committing, or overwriting that work.
- Starting tracked changes and Git status are retained in ignored `var/flow-audit-2026-10-26/baseline.patch` and `baseline-status.txt`. `baseline-tree/` reconstructs the starting tracked-file contents for precise review. These are comparison checkpoints, not a complete backup of every untracked file or database.
- Actual stack: PHP 8.2, Symfony 7.4, Twig, Symfony Security/CSRF, Doctrine DBAL repositories and eight existing migrations, MySQL-compatible persistence, static JavaScript/CSS. There is no frontend build requirement or Doctrine ORM entity layer to invent.
- Native PHP is 8.2.12; the rebuilt Docker image uses PHP 8.2.35. Node is 24.16.0. Composer, PHPUnit, Docker Compose, Graphify, and browser automation were available.
- `AGENTS.md` and the installed Graphify, Systematic Debugging, Ponytail, test-driven development, verification, and computer-use instructions were read. The frontend agent additionally used Impeccable guidance for failure states. Three agents handled backend, frontend, and QA; the QA agent independently reviewed the resulting repairs.
- Graphify queries guided navigation. There was no `graphify-out/wiki/index.md` at inspection. The graph was refreshed after code changes.
- Initially no Docker containers were running. Native Apache/MySQL listeners and unrelated stopped containers were left alone. Native tests use disposable `picklers_test`, `picklers_admin_test`, and schema-bootstrap databases. Browser/HTTP checks use synthetic `picklers_e2e` fixtures. Docker verification uses the separate disposable test database service.
- Baseline verification passed syntax, Composer validation/platform/advisories, all 111 original frontend tests, dev/test/prod container lint, 59 Twig templates, 48 routes, production warmup, schema-bootstrap scenarios, and 406 service assertions.
- New regression files were added while that baseline was running. Its PHPUnit stage therefore ran 301 tests / 2,356 assertions and failed four new tests: two Open Play settlement cases and two password whitespace cases. It reported no other failures. Separate red runs reproduced the remaining defects before their repairs.

## Existing workflow map and actual coverage

| Workflow and execution chain | Fresh verification and limits |
| --- | --- |
| Landing → registration/login → Symfony session → player application → logout | Existing functional/security suites and frontend accessibility/recovery tests; real browser synthetic owner login and portal navigation. HTTP tests additionally verify fresh logins and password-session revocation. Recovery/social providers are explicitly unavailable. |
| Player Play/Explore → facility detail/favorites → availability/quote/voucher → court reservation → pending request | Frontend date, availability, race, zero-fee/voucher and payment tests; HTTP favorites persistence and both Pay at Venue/Pickle Credits booking flows. Server-derived price, duplicate denial and foreign-owner denial checked. |
| Owner Requests → approval → player confirmed booking → cancellation → ledger/refund | Existing API/admin/concurrency suites and HTTP state/wallet/transaction checks. Credit refund occurs once; repeated cancellation does not change balance. Venue payments produce no in-app credit/refund. |
| Owner Courts → publish Open Play → join/debit → current roster → approve → cancel/refund → remove session | Publication, roster, settlement, failure-injection, occurrence and concurrency suites. Browser roster and HTTP journey evidence are distinguished from unit/functional evidence below. |
| Owner dashboard → court Schedule → populated upcoming/completed roster | New actual Twig → HTML parser → JavaScript execution regression; real browser `Schedule 1` opens the synthetic confirmed booking with the correct player and time. |
| Owner earnings → collected entitlement → payout request | Existing payout authorization/collection/refund-boundary tests plus new canonical and historical Open Play timing tests. No real disbursement provider was invoked. |
| Owner settings/courts/staff → scoped mutations and refreshed views | Existing ownership, court identity/hours, staff identity/revocation/permissions, upload/settings and concurrency tests; HTTP owner pages render. No real staff grants or personal uploads through the browser. |
| Tournament creation → entrant search/add → draw → result → champion → deletion | New frontend races tested on creation and bracket screens; HTTP Singles journey verifies category-change refusal, foreign-owner denial, persisted champion, and cleanup. |
| Profile/password/avatar → account API → persistence/session effects | New exact-password form/JSON tests, frontend submitted-value/avatar-error tests, HTTP profile persistence/restoration and exact spaced-password login/revocation/restoration. Avatar failure behavior is automated frontend evidence, not an actual hardware file-selection run. |
| Community APIs → post/like/comment → messages/read receipts | HTTP checks persist posts, toggle likes, retain comments and mark messages read. Owner messaging is reachable. Current player navigation exposes Play, Explore, Wallet, Bookings and Settings; legacy player chat/feed code is not presented as a reachable browser journey. |
| Notifications → read/delete → sync state | Existing frontend/API/ownership tests; HTTP read persistence agrees with sync unread count. New fault-injection tests verify notification failures roll back associated financial operations. |
| Admin console → capability-gated operations → audit/persistence | Existing security, user/application/booking/facility/moderation/promo/ledger/document/impersonation/concurrency suites. HTTP verifies admin and normal-user pages plus ordinary-admin denial of privileged wallet adjustment. No privileged action is granted merely for testing. |
| Startup → schema/migrations → cache → Apache/public routes/assets | Native verifier, Docker verifier, isolated Docker startup and HTTP smoke checks, with final outcomes below. Existing production data is not reset or migrated for this audit. |

Role policy is the existing two-tier administrator capability matrix: ordinary and explicitly privileged administrators, alongside player/owner/staff access. No separate Developer Console or developer role exists in the inspected routes/security model. This audit preserves that policy instead of inventing a developer account or silently changing who may promote administrators.

## Defect and resolution register

Every entry below was reproduced with a failing regression before its production repair. All repairs retain the established routes and service architecture.

### F01 — P1: password changes alter the credential

- **Flow / expected:** signed-in player settings, compatibility form and JSON REST password updates must preserve the exact current/new/confirmation strings accepted by registration/login.
- **Actual / root cause:** frontend `.trim()` and `ActionInput::string()` removed surrounding spaces. A valid current password was refused; a new credential or mismatching confirmation could silently be normalized.
- **Repair / files:** remove the three trims in `public/assets/js/app.js`; read raw merged scalar fields with explicit nonscalar rejection in `src/Web/Controller/AccountController.php`; correct stale six-character help in the password modal.
- **Impact / regression risk:** blocked account maintenance or an unexpected password. Retain CSRF, authorization, policy, compatibility precedence and current-session preservation.
- **Verification:** `password-change-values.test.js` (exact payload and mismatch), `PasswordWhitespaceTest.php` (form/JSON persisted hashes, current session, mismatch/array rejection), connected account/session tests, and real HTTP spaced-password login/revocation/restoration.

### F02 — P1: financial writes commit before notification failure

- **Flow / expected:** court booking, Open Play join, player cancellation, and simulated top-up must return failure without committing only part of the operation.
- **Actual / root cause:** each committed booking/wallet changes before notification INSERTs. Injected storage failures returned failure after balance changed: court request 1,200→870, Open Play 1,200→1,035, cancellation 1,200→1,500, top-up 1,200→1,300.
- **Repair / files:** move existing database-only notification writes inside the existing transactions in `BookingRepository.php`, `OpenPlayRepository.php`, and `WalletRepository.php`. Error propagation and rollback remain intact.
- **Impact / regression risk:** misleading failed requests, duplicate retries, lost booking/payment consistency. Additional transaction-held notification writes were reviewed for shared connection and lock behavior; no external transport runs inside the transaction.
- **Verification:** four `BookingNotificationAtomicityTest.php` trigger regressions compare exact booking, wallet, ledger and notification state; connected API, booking, cancellation, publication and service suites verify success paths.

### F03 — P1: collected Open Play payments never become payout-eligible

- **Flow / expected:** genuinely collected, unrefunded credit payments become eligible only after the scheduled session end, using existing fee/reserve rules.
- **Actual / root cause:** Open Play joins omitted canonical minutes, while the payout query required `end_min`. Historical rows were excluded forever too.
- **Repair / files:** persist parsed start/end minutes in `OpenPlayRepository.php`; in `OwnerRepository.php`, retain the SQL collection/refund gates and add a strict recorded-time fallback for older rows without minutes. No data rewrite or migration.
- **Impact / regression risk:** owner earnings unavailable despite collection. Payout trust gates, future/overnight boundaries and refund exclusion must remain closed; ending a session early must not release funds before scheduled end.
- **Verification:** `OpenPlaySettlementTest.php` verifies new/historical rows, overnight minutes, elapsed availability, early-end denial, future/unknown times, absent collection metadata and refund exclusion; payout service and refund-boundary suites retained.
- **Remaining limit:** older collections without authoritative linked debit metadata still require reconciliation. Timing fallback does not invent collection evidence.

### F04 — P2: owner roster contains another session's players

- **Flow / expected:** owner current-session roster contains only that session/occurrence, including valid legacy rows without a match ID.
- **Actual / root cause:** a court-name OR branch also matched different explicit session IDs; nonrecurring legacy rows were not limited by date/time. Reproduction returned five rows instead of two.
- **Repair / files:** in `OpenPlayRepository.php`, use the name/time fallback only when match ID is missing and require its current occurrence date. Explicit IDs remain authoritative.
- **Impact / regression risk:** wrong attendance/payment information; must preserve recurring and valid legacy memberships.
- **Verification:** `OpenPlayRosterScopeTest.php` contrasts current ID, foreign ID, prior legacy day, other legacy time and current legacy player; connected occurrence/publication tests.

### F05 — P2: stale or denied roster looks valid

- **Flow / expected:** changing owner court rosters must retain the selected court's results; failure must be distinguishable from no joined players.
- **Actual / root cause:** unguarded asynchronous responses could replace a newer roster; `success:false` was converted to an empty list and the old count remained while loading.
- **Repair / files:** request generation checks, loading count, response validation and escaped retry guidance in `public/assets/js/owner.js`.
- **Impact / regression risk:** staff view wrong players or mistake permission/network failure for no attendance. Successful empty lists retain their existing state.
- **Verification:** `owner-roster-state.test.js` tests reversed replies, stale rejection, loading count, denied/malformed payloads and successful retry; actual browser roster opens and finishes loading.

### F06 — P2: populated Schedule button cannot execute

- **Flow / expected:** owner dashboard Schedule opens upcoming/completed booking arrays.
- **Actual / root cause:** raw JSON quotes broke a double-quoted HTML `onclick` attribute; Node execution of the browser-decoded handler failed with `Unexpected end of input`.
- **Repair / files:** remove the two `raw` filters from JSON expressions in `templates/web/partials/owner/_tab-dashboard.html.twig`, retaining Twig HTML autoescaping.
- **Impact / regression risk:** every nonempty schedule could be inaccessible. Arrays and player names with quotes/HTML-like text must remain intact and inert.
- **Verification:** `OwnerScheduleHandlerTest.php` renders the actual handler, parses HTML and executes JavaScript with populated arrays; real browser Schedule opens the confirmed synthetic booking. Screenshot: `var/flow-audit-2026-10-26/owner-schedule-verified.png`.

### F07 — P2: tournament entrant search offers the wrong query's players

- **Flow / expected:** creation and bracket-console autocomplete must correspond to the current input.
- **Actual / root cause:** an older reply for Alice could replace Bob's current results; late failure could hide the newer list, including during the debounce interval.
- **Repair / files:** per-input request generations and immediate obsolete-list clearing in `public/assets/js/owner.js` and `public/assets/js/tournament.js`.
- **Impact / regression risk:** wrong entrant selection. Both single/doubles search slots retain the existing selection behavior.
- **Verification:** four `tournament-search-state.test.js` tests cover reversed success/failure and edited/cleared inputs on both screens; full frontend suite and HTTP tournament lifecycle.

### F08 — P2: failed avatar save displays an unsaved photo

- **Flow / expected:** settings/mobile/sidebar show the persisted account image; failure preserves the previous image.
- **Actual / root cause:** all three displays changed before the profile API completed and never rolled back on refusal/offline failure.
- **Repair / files:** update the displays only after a valid success payload, using the server-returned avatar and escaped HTML attributes in `public/assets/js/app.js`.
- **Impact / regression risk:** false saved state until refresh. Existing downscaling, backend validation and error feedback remain.
- **Verification:** three `avatar-save-state.test.js` tests cover pending, rejected/offline/malformed and persisted success responses; account functional tests retain server persistence coverage.

### F09 — P3: mistyped targeted test command falsely passes

- **Flow / expected:** a suite filter that matches nothing must fail before database preparation.
- **Actual / root cause:** `exit('No suites match…')` prints an error but PHP exits zero.
- **Repair / files:** write to STDERR and explicitly exit 1 in `tests/run.php`.
- **Impact / regression risk:** CI/manual checks could falsely claim coverage. Matching suite execution remains unchanged.
- **Verification:** `ServiceTestRunnerTest.php` executes the real runner with an unmatched filter and deliberately invalid DB host; verifies failure status/message without database preparation. Red then green, four assertions.

### F10 — P2: Open Play receipt always says two hours

- **Flow / expected:** joined booking duration reflects the session's actual parsed schedule.
- **Actual / root cause:** `joinMatch()` stored the literal `2 Hours`; a 10 PM–1 AM session incorrectly displayed two hours.
- **Repair / files:** derive the duration from the same parsed window in `OpenPlayRepository.php` using the existing Hour/Hours convention.
- **Impact / regression risk:** incorrect reservation information. Session pricing remains per-entry and unchanged.
- **Verification:** added settlement assertion first failed with expected `3 Hours` vs actual `2 Hours`, then passed; overnight and connected booking suites retained.

### F11 — P1: one installation's locks block another database

- **Flow / expected:** tournament mutations and payout requests in independent databases on the same MySQL server must not contend; concurrent operations in the same database must remain serialized.
- **Actual / root cause:** MySQL advisory locks are server-wide, but tournament writes used `picklers_tournaments` and payouts used `picklers_payout_<facilityId>` without database scope. While the native test suite held a tournament lock in `picklers_admin_test`, an actual `picklers_e2e` tournament request returned HTTP 503. The analogous payout collision was reproduced with the same facility ID in a foreign schema.
- **Repair / files:** compute bounded, 64-character SHA-256 lock names from the database and resource identity in `TournamentService.php` and `OwnerRepository.php`; acquire/release the same key. Update existing same-database contention tests to the scoped tournament key.
- **Impact / regression risk:** testing or another installation could block normal tournament/payout work. Same-database contention and retry protection must remain intact; hashing avoids MySQL's lock-name length limit for long schema names.
- **Verification:** three `DatabaseLockScopeTest.php` cases first failed against the original implementation. They hold both the original global and foreign scoped keys on another real test schema, then verify current-database success; a separate case retains same-database payout denial/retry. The connected lock, tournament scope and payout tests passed 18 tests / 157 assertions. Independent review checked actual database-name resolution, case aliases, key length and acquire/release identity.
- **Deployment implication:** restart application workers together when deploying this change; overlapping old and new code uses different advisory-lock names. No migration or data conversion is required.

## Improvement register

| Classification | Decision / benefit / risk |
| --- | --- |
| Required repairs — implemented | F01–F11 restore existing flows, installation isolation and truthful failure states. No visual redesign, dependency, route/API removal or schema migration was introduced. |
| Important reliability improvement — implemented | Focused regression coverage now captures hidden partial commits, historical settlement, populated inline handlers and asynchronous identity races. Tests exercise actual requests/queries or production handlers rather than only searching source text. |
| Important operational improvement — recommended | Keep service, frontend, functional and Docker checks as a release gate; archived historical reports should not substitute for a fresh run. This uses existing tooling and avoids production resets. |
| Optional — not implemented | A broader visual redesign, new recovery/social/payment providers, new developer-role policy, live roster push updates, richer community navigation and new cross-midnight range-picker UI. These require product/integration decisions beyond repair of the established flows. |

## Verification and independent review

Evidence lives under ignored `var/flow-audit-2026-10-26/`. Intentional fault-injection errors in logs are expected regression stimuli, not concealed production failures.

| Command / evidence | Actual outcome |
| --- | --- |
| Initial `php scripts/verify.php` / `baseline-verification.log` | All non-PHPUnit gates passed; 406 service assertions. PHPUnit 301 tests / 2,356 assertions, four newly added regression failures detailed above. |
| `node --test tests/Frontend/*.test.js` | 123 tests passed, zero failures (original baseline 111). |
| Standalone schedule/venue/owner URL tests with `--no-configuration --bootstrap vendor/autoload.php` | 5 tests / 28 assertions passed. Independent schedule rerun 1 test / 3 assertions passed. |
| Focused connected PHPUnit filter / `backend-focused-green.log` | 74 tests / 482 assertions passed. Includes notification atomicity, roster, settlement, API/account/bookings, hours, payout refund boundaries, publication, session concurrency, overnight and court identity. |
| `php tests/run.php PayoutCollection` / `backend-payout-service-green.log` | 17 assertions passed. |
| F11 focused PHPUnit filter / `database-lock-green.log` | 18 tests / 157 assertions passed, including retained same-database contention and retry behavior. Red proof: all three new isolation/locking regressions failed before the repair. |
| QA-only audit harness checks | 14 JavaScript audit tests, 17 facility-hours checks and ledger self-checks passed; Composer/Compose configuration checks passed. |
| First complete native verification after F01–F10 | All 15 gates passed; 327 PHP syntax checks, 406 service assertions and 307 PHPUnit tests / 2,396 assertions. F11 was subsequently discovered during concurrent HTTP testing and receives a fresh verification run. |
| First complete Docker verification after F01–F10 | All 15 gates passed; 248 PHP syntax checks, 406 service assertions and 307 PHPUnit tests / 2,399 assertions using PHP 8.2.35/MySQL 8.4. |
| Final `php scripts/verify.php` / `final-native-verification.log` | All 15 gates passed after F11: 328 PHP syntax checks, 123 frontend tests, 406 service assertions and 310 PHPUnit tests / 2,413 assertions on native PHP 8.2.12. |
| Final `docker compose --env-file .env.docker --profile test run --build --rm test` / `final-docker-verification.log` | All 15 gates passed after F11: 249 PHP syntax checks, 123 frontend tests, 406 service assertions and 310 PHPUnit tests / 2,419 assertions using PHP 8.2.35/MySQL 8.4. |
| Isolated production Compose startup / `docker-startup-final.log` | Final source rebuilt; app/database healthy, setup exited 0, loopback port 8106. Symfony 7.4.20 production/debug off, all eight migrations current. Uses new `picklers-flow-audit-20261026` project volumes, not the existing application database. |
| `php scripts/docker-smoke.php http://127.0.0.1:8106` / `docker-http-smoke.log` | 21/21 HTTP checks passed: public/compatibility pages, guest restrictions, CSRF, assets, hidden/private paths and uploaded executable denial. |
| `node var/flow-audit-2026-10-26/journeys.mjs` / `journeys.log` | Final post-F11 run passed all 11 journey groups, zero failures. The earlier tournament 503 during native global-lock tests led to F11. An earlier 30-second local Open Play timeout did not recur on the final run. Harness corrections (CSRF acquisition, administrator token source, authorized missing-tournament caller, roster endpoint and booking-ID field) were test-harness corrections, not application defects. |
| `http-financial-invariants.json` | Zero synthetic fixture wallet/ledger mismatches and zero duplicate booking refunds. The extra Pay at Venue Schedule booking was cancelled after browser verification; no funds moved for it. |
| Real browser | Synthetic owner sign-in, player Settings → owner portal, populated Schedule modal and Open Play roster exercised. No browser password change, real payment, personal upload or hardware permission was performed. |
| Independent QA agent review | No actionable regression in reviewed password, roster, schedule, financial timing/collection and notification transaction changes. Original/current file comparison used the preserved baseline. Frontend tests and standalone schedule execution independently rerun. |
| `graphify update .` / `graphify-update.log` | Final post-F11 refresh succeeded: 2,993 nodes / 5,593 edges / 245 communities. Tool warns that 24 source files produced no nodes; this is a graph extraction coverage limit. |

The continuation initially found Docker Desktop and the temporary native HTTP server stopped. Restarting those services restored verification; the first connection-refused attempts were environment failures, not accepted as application test results. The final runs above use restored services and current source.

## Readiness and boundaries

- **Fixed and verified:** F01–F11 have targeted red/green regression evidence and independent review. The full verification result and runtime checks above determine the broader acceptance scope.
- **Implemented but not fully verified:** no changed flow is represented as exhaustively exercised on every browser/device/theme; asynchronous failure behavior and avatar save states rely on deterministic handler tests in addition to backend coverage.
- **Unresolved:** no additional reproducible in-scope application defect remains identified in the tested scope. This is not a claim that every possible interaction or failure combination has been certified.
- **Blocked by external dependencies / environment:** real payment collection/disbursement, email/SMS/password-recovery and social-auth providers are unavailable. The existing application discloses/fails closed on these capabilities. No live charge or external notification was initiated. Native host PHP remains the installed version; runtime updates are not bundled into this repair.
- **Optional enhancements not implemented:** listed in the improvement register. There is no claim of public deployment readiness, load capacity, formal accessibility conformance, real-data import/restore, or camera/GPS/audio hardware certification.

All database rebuilds and fixture writes were confined to the documented test databases. Synthetic community history/cancelled bookings and notification read state may remain there. The temporary native audit server was stopped, the new audit Compose containers/network were removed, and the disposable Docker test database was stopped after verification. Named volumes were retained; no volume pruning occurred. No production reset, destructive real-data migration, dependency upgrade or unrelated container cleanup was performed.

The repair increment changes 13 existing files and adds 11 regression-test files plus this report. Existing broader uncommitted migration work remains present. No commit or pull request was created.
