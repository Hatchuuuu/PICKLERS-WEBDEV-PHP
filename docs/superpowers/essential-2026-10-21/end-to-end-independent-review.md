# Independent review — local presentation increment

Reviewed on 2026-10-21. Scope assumes a local professor presentation using isolated synthetic fixture databases, without an SMS or payment provider. This is not production certification.

What this change does: Owner booking decisions now show the selected request before submission and keep submitted dialogs open while the server responds. Staff suggestions, authentication labels, GPS fallback, scanner sound preferences, and payment/payout wording make the local presentation more predictable. The fixture reset removes generated dependent records and recreates the documented accounts and wallet history.

## Findings at review time

1. **Promo use counters survive deleted fixture redemptions** (`scripts/seed-fixtures.php:65`).
   - **What this is:** Redeeming a promo records a redemption and increments `promo_codes.times_used` in `src/Repositories/PromoRepository.php`.
   - **Problem:** The reset deletes fixture users' generated redemptions, including uses of retained default promos such as `WELCOME100`, but does not subtract those uses from retained promo counters. A usage-limited promo can remain exhausted after the fixture activity that exhausted it has been removed. `PricingService` and `PromoRepository` enforce this counter.
   - **Fix:** Before deleting redemptions, subtract the removed counts from the corresponding retained promos, preserving unrelated uses. Include a reset regression with one fixture redemption and one unrelated redemption of a retained promo.
   - **If skipped:** Repeated local presentations can accumulate phantom promo use and eventually reject an otherwise available code.

No other actionable failure was found in the inspected UI delta.

## Scope and evidence

- Started with `graphify query "Owner approval decline confirmation busy lock fixture reseed cleanup refunds opening balances" --budget 1800`.
- Compared `owner.js`, auth, owner modals/dashboard, and `scripts/seed-fixtures.php` with `var/essential-20261021/e2e-confirmation-before`.
- Read connected request rendering/refresh, acceptance/decline handlers, staff selection and escaping, shared dialog close/focus/backdrop behavior, player cancellation submission, owner preferences/settings, schema relationships, and promo counter writers/readers.
- Confirmed literal fixture-prefix matching, CLI/database-name guard, transactional database reset, child-before-parent deletion, regenerated root privilege, and opening-wallet entries. Unrelated rows are excluded except dependent content on fixture entities and aggregate recounts for removed fixture activity. Append-only audit events remain.
- Owner acceptance starts only after confirmation. Both owner decision handlers disable controls and mark the dialog busy; the shared close path blocks Escape, backdrop, and explicit dismissal while busy. Player cancellation rejects duplicate in-flight submissions and releases the busy state on success, API rejection, and network rejection.
- Dynamic handlers use JSON plus HTML escaping. Initial Twig request handlers use JSON literals with normal template autoescaping; summaries use `textContent`.
- Independently ran nine relevant frontend test files: **21 tests passed, 0 failed**. These cover owner request context, decline outcomes, pending cancellation, shared keyboard/busy dialogs, GPS denial/offline coordinates, scanner preference, staff keyboard selection, payment policy, and authentication labels.
- Inspected `var/essential-20261021/e2e-release-verified.log`: it records all 15 gates passing, including 268 PHPUnit tests / 2213 assertions and 387 legacy assertions. This review did not rerun those database-mutating suites.

Verdict at review time: address finding 1 for a repeatable reset. The inspected UI changes are suitable for the stated local scope.

Not checked: No independent browser session or database mutation was performed; the parent agent's runtime refund proof and full verification results are separate evidence. No external provider or production behavior was certified.

## Narrow follow-up review — finding 1 resolved

Re-read the revised reset and expanded `FixtureReseedTest`. The reset now uses the same fixture-redemption predicate for the grouped counter subtraction and subsequent deletion. Both execute inside the reset transaction, before fixture promos are replaced. `GREATEST(0, ...)` avoids creating a negative counter from historical inconsistent data. It subtracts only the deleted redemptions, preserving unrelated uses on retained promos.

The regression creates a retained promo with two uses, one fixture redemption and one redemption belonging to a separate non-fixture account. It checks that reset leaves one use, preserves the unrelated redemption and wallet, and removes the fixture redemption. Inspected the recorded red/green logs: the regression failed with a retained counter of 2 before the fix and passed afterward with 1 test / 14 assertions. No database suite was run concurrently by this reviewer.

Also reviewed the final native owner toggle CSS: the transparent input covers its 44×24 pixel label, retains native checkbox keyboard behavior, and the adjacent visible slider receives a focus outline. Independently reran PHP syntax checks for the reset and regression, and the scanner preference frontend test; all passed. Actual pointer/reload/Space behavior was tested by the parent agent, not independently repeated here.

UI/reset review verdict: the inspected changes are suitable for the stated local professor presentation scope. Finding 1 is resolved, and no remaining actionable issue was found in that review. Overall release readiness remains conditional on the current full verification run, as described below.

Not checked: No independent browser session, database-mutating suite, external provider integration, or production load test. The review added documentation only.

## Current full-gate failure investigation

The parent agent subsequently reported five backend failures in the current full gate run (268 tests / 2178 assertions), with the other fourteen gates passing. This supersedes any overall readiness inference from the earlier passing run. The failing test names and assertion details are still being collected by the parent agent; this reviewer has not rerun database tests.

Read-only inspection did not substantiate the proposed morning/evening explanation. `DiscoverTest` compares same-day slot availability with the actual current minute and uses future/past dates for fixed availability expectations. `OpenPlayPublicationTest` uses dates three days ahead. `OwnerPortalViewTest` intentionally uses daily sessions whose target date rolls forward after their end time. `PagesTest` does assume that the explore tab contains a match card; persistent default matches are inserted with concrete relative dates only when the matches table is empty, so old base matches could invalidate that assumption, but this is not proof of the five current failures. The current assertion output must determine the fix.

Current overall verdict: **Pending diagnosis and a passing current verification run.**

Concurrent workspace changes were observed during this investigation: `tests/Web/CliEnvironmentTest.php` was written at 19:10:30; `scripts/seed-fixtures.php`, `scripts/verify-schema-bootstrap.php`, and `scripts/rehearse-upgrade.php` at 19:11:12; `scripts/docker-smoke.php` at 19:14:53. This reviewer did not create or edit those files. The three existing CLI scripts now reuse `config/bootstrap.php` for inherited-environment precedence instead of parsing `.env` independently. The shared bootstrap's own modification time was 2026-10-11, so the observed concurrent change is its adoption by those scripts. The direct run now discovers 271 tests because of the three added environment-probe cases. These observations explain the changed test count; they do not by themselves diagnose the earlier five failures.

## Final test-harness follow-up

The parent agent diagnosed the original five failures as the five `RehearsalSafetyTest` cases whose copied script fixture lacked the newly required `config/bootstrap.php`. Separate missing-database/table diagnostic errors were caused by overlapping suite rebuilds. These were test-harness failures; the earlier clock hypothesis was not substantiated.

Read-only reviewed the repairs in `tests/Admin/bootstrap.php`, `tests/Web/TestSuiteIsolationTest.php`, and `tests/Web/RehearsalSafetyTest.php`:

- The main suite takes a nonblocking MySQL advisory lock before deleting the shared compiled cache or dropping the shared test database. A competing suite exits with status 75 before either destructive step.
- The lock belongs to a separate server connection. The registered shutdown closure captures that PDO connection, keeping it alive for the entire suite and explicitly releasing the lock on normal shutdown. The server also releases connection-owned locks when a process disconnects.
- Existing concurrency workers inherit `PICKLERS_SKIP_DB_REBUILD=1` after successful main-suite setup. Workers that require this bootstrap skip both lock acquisition and database reconstruction, so they can continue participating in the parent suite's database scenarios.
- The isolation regression launches another real bootstrap with the skip flag forced to zero and asserts the rejected process's status and message. It exercises the actual protection before database deletion.
- The rehearsal fixture now supplies the actual shared bootstrap, a stub vendor autoloader, and its temporary config directory. Its existing recursive cleanup also removes those new fixture files.

No actionable issue was found in these narrow harness changes. Independently ran PHP syntax checks for all three files; all passed. Did not run database tests concurrently. Overall readiness still requires the parent's final complete verification result.

## Parent verification result after independent review

The parent subsequently completed `e2e-final-current-gates.log` with exit0 and all15 gates passing, including275 PHPUnit tests/2243assertions,103frontend tests and387legacy assertions. This resolves the pending complete-suite condition above. These counts are the parent's verification evidence, not an additional independent database run. The separate concurrently edited Docker smoke script received a final syntax check; Docker runtime acceptance remains outside the selected XAMPP presentation scope.
