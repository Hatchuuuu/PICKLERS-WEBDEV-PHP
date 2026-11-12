# Customer testing and local readiness — October 22, 2026

The second-pass findings and verification are recorded at the end of this report. **Latest result: all 15 gates passed, with 296 PHPUnit tests / 2,353 assertions, 406 service assertions and 111 frontend tests.** The original pass below remains as its historical evidence.

## Executive result

The existing Symfony application was inspected, exercised through its browser interface and HTTP APIs, repaired, rebuilt, and retested in Docker. Production is healthy at http://localhost:8081. A newly created, isolated synthetic demonstration is healthy at http://localhost:8082, under Compose project `picklers-customer-20261022`.

Final verification passed all 15 existing gates: **294 PHPUnit tests / 2,334 assertions**, **406 service assertions**, and **111 frontend tests**. Production and demo each passed **21/21 Apache HTTP checks**. No known unresolved critical/high defect remains in the scope examined during this run. This is bounded local acceptance, not a claim that every control, possible concurrency pattern, hardware feature or external integration has been certified.

The starting tree already contained extensive uncommitted migration, Docker, business-rule and UI work on `migration/symfony-completion-20261010`. That work was retained. The tracked diff and status were recorded in `var/customer-audit-20261022/baseline.patch`, `baseline-status.txt`, and `baseline-untracked.txt`; this is a tracked-diff checkpoint and untracked-file inventory, not a full data backup. No commit, reset, existing application database deletion, volume deletion, dependency upgrade, or framework replacement was performed. Automated suites rebuilt only their disposable test databases.

## Application and workflow map

- Symfony 7.4, PHP 8.2, Twig, Symfony Security/CSRF, Doctrine DBAL and MySQL 8.4; eight migrations and 48 Symfony routes. Static frontend assets require no Node build to serve the app.
- `public/index.php` dispatches public/authentication/player, owner and protected administrator routes, including compatibility PHP URLs.
- Player actions cover discovery/favorites, quotes, court requests, Open Play, wallet history, cancellation, notifications, community, messages, account settings and tournaments.
- Owner actions cover facility selection, courts, requests, schedules, staff, earnings/payout requests and facility settings. Administrator actions use the separate protected console, capabilities and audit trail.
- Shared booking rules, repositories, ledger and server authorization own status transitions and financial writes. Browser state alone does not establish success.
- End-of-session notifications are request/poll driven; this application has no independent background push service.
- Production payments are disabled for real-money top-ups. Demo credits are synthetic; GCash/Maya selections arrange manual payment outside the application. Payout requests do not execute bank transfers.

Graphify queries guided source navigation. `graphify update .` completed after source edits: 2,891 nodes, 5,455 edges, 237 communities. It reported 24 files producing no AST nodes, primarily configuration/data; semantic document rebuilding was not run.

## Defect register and disposition

### CUST-01 — High / Functional and data integrity: overnight sessions end at midnight

**Reproduce:** a confirmed booking starts October 21 at 11 PM and ends October 22 at 1 AM (`end_min=1500`). Evaluate it at October 22, 12:30 AM. The previous shared rule immediately classified every earlier booking date as past.

**Expected:** remain active until 1 AM. **Actual:** premature Completed display and expired check-in; related court lookups excluded yesterday entirely, losing occupancy, active-booking protection and End Session Early.

**Root cause:** calendar-date shortcuts and same-day SQL filters ignored the normalized next-day end minute.

**Correction:** compare the complete booking end timestamp; preserve cancellation and early-end semantics and the existing missing-time fallback. Live occupancy and early-ending queries include yesterday and shift those minute ranges relative to today. The active-court guard considers yesterday before applying the shared past check. No stored booking dates, prices or statuses are rewritten by this correction.

**Files:** `src/Domain/BookingRules.php`, `src/Repositories/FacilityRepository.php`; `tests/Web/OvernightBookingTest.php`, `tests/Web/OvernightCourtTest.php`.

**Risks checked:** ordinary dates, exact end boundary, parsed legacy ranges, cancellation/early-end behavior, live occupancy, active-booking count, and ending a session without changing its price or confirmation status. An optional current-time argument supports deterministic pure tests without changing existing callers.

**Verification:** pure regression failed before correction, then passed 2 tests / 10 assertions. The combined focused Docker suite passed 15 tests / 92 assertions. Final full suite passed. The occupancy fixture has an explicit one-minute exclusion at 23:59, when its maximum valid next-day range has already finished; no test was skipped in the recorded run.

### CUST-02 — Medium / Reliability: overnight session-end alerts never arrive

**Reproduce:** leave an unalerted confirmed booking dated yesterday with a normalized end after midnight today; poll session sync after its end. The previous query only selected `booking_date=today`.

**Expected:** exactly one persisted notification and one alert payload after the session ends. **Actual:** no candidate and no alert.

**Root cause:** both initial eligibility and the locked-row recheck excluded prior-day overnight sessions.

**Correction:** include today and yesterday, then allow yesterday only when its scheduled end reaches today. Recheck the same eligibility after obtaining the booking row lock. Old ordinary bookings remain outside the notification window. Existing transaction rollback and duplicate prevention remain intact.

**Files:** `src/Repositories/BookingRepository.php`, `tests/Web/SessionEndedConcurrencyTest.php`.

**Evidence:** the first Docker run included the newly added regression tests before repository corrections: 292 tests / 2,322 assertions, exactly three failures for the overnight alert, legacy time-range fallback, and post-lock window change. All other verification gates passed. Those failures are new regression demonstrations, not evidence that unrelated existing tests failed.

**Verification:** the focused suite and final complete suite passed, including overlapping polls, notification insertion rollback, changed date/end-time eligibility, cancellation and early end.

### CUST-03 — Low / UX: production recovery tells customers to use presentation credentials

**Reproduce:** open production `/auth`, then Forgot Password. **Actual:** the screen calls production a local demo and instructs the user to use demonstration credentials.

**Expected:** accurately explain the installation's unavailable recovery service and give an appropriate next step.

**Correction:** installation-neutral wording directs the user to the person managing the installation. It retains the explicit statement that no reset email or SMS will be sent, keyboard focus behavior, and the return-to-sign-in control.

**Files:** `templates/web/auth.html.twig`, `tests/Frontend/auth-recovery-view.test.js`.

**Verification:** updated regression failed before the copy change and passed afterward; the rebuilt production page was opened again in the browser and displayed the corrected text. No recovery provider was invented or bypass added.

### ENV-01 — Local blocker / Configuration: Docker Desktop failed to start

**Evidence:** the Linux engine pipe was absent. Desktop logs identified inaccessible stale `Docker/run/dockerInference` and `docker-secrets-engine/engine.sock` runtime socket entries.

**Action:** stopped the failed Desktop processes, preserved both runtime directories together under timestamped `*.customer-audit-*` names, recreated only those runtime directories, and restarted Desktop. No factory reset, image prune, WSL reset or data-volume removal was used.

**Verification:** Docker Engine 29.5.2 became available; MySQL, setup and Apache started; builds, migrations, HTTP checks and test suites executed successfully. This restores the current session; the recurring host socket fault is not permanently repaired by application code. Preserve the runtime backups and seek Desktop diagnosis if it recurs.

## Customer testing results

| Journey | Evidence and result |
| --- | --- |
| First visit and guest boundaries | Actual production landing/empty state and mobile menu; guest player/owner/admin redirects, API restrictions, CSRF denial and private-path/upload execution denial. HTTP checks passed on both instances. |
| Sign-in | Browser rejected an incorrect fixture password with useful feedback, then accepted the correct one. HTTP sessions authenticated player, two distinct owners and administrator. |
| Discovery/favorites | Browser filtered Fixture Arena and opened real court availability. HTTP favorite change persisted across reads and was restored. |
| Court booking | HTTP quote → booking → authoritative price/debit → duplicate slot refusal → foreign-owner refusal → owner approval → player cancellation → one refund passed. |
| Browser Quick Book | Selected October 25, 12–1 PM; server returned two courts. Requested Court 1 with Pickle Credits for ₱330. Receipt and Bookings showed pending reference `PKL-346493`. Cancellation review explained full credit refund. |
| Persistence/refund | Browser Wallet returned to ₱1,200 and showed one ₱330 debit and one ₱330 refund. Direct demo SQL verified cancelled status, unchanged ₱330 price, restored balance and exactly two linked ledger entries. |
| Open Play | HTTP owner host → player pending join → credit debit → cancellation/refund → session cancellation passed. |
| Community/messages | Synthetic post, like/unlike persistence, message delivery and read receipt passed through HTTP. These writes remained inside the dedicated fixture project. |
| Tournaments | HTTP Singles entrants → generated bracket → result → champion → cleanup passed. |
| Notifications | Read-state persistence passed. Automated tests verified end-alert idempotency, rollback and concurrency. |
| Owner/admin | Browser entered owner portal using Switch to Owner View, opened earnings, and saw gross revenue separated from zero withdrawable funds. HTTP rendered wallet/bookings, owner staff/earnings and administrator console. Automated suite covered roles and protected operations. |
| Responsive/accessibility | Mobile menu and player booking/cancellation/wallet were used at the browser's narrow default viewport; owner dashboard/earnings inspected at 1440×900. Temporary viewport override was reset. Recovery focus and existing dialog/keyboard regressions passed. This is sampled inspection, not a WCAG certification. |

Visual refund evidence: [wallet screenshot](../var/customer-audit-20261022/browser-wallet-refund.png). Direct SQL evidence: `var/customer-audit-20261022/browser-db-verification.log`. HTTP journey output: `var/customer-audit-20261022/journeys.log`.

## Regression and independent review

Commands actually run include:

```powershell
node --test tests/Frontend/*.test.js
php scripts/verify.php --quick
docker compose --env-file .env.docker --profile test run --build --rm test
php scripts/docker-smoke.php http://localhost:8081
php scripts/docker-smoke.php http://localhost:8082
node var/master-20261022-http.mjs http://127.0.0.1:8082/
docker compose --env-file .env.docker exec -T app php bin/console doctrine:migrations:status --no-ansi
graphify update .
```

The final production HTTP rerun used `http://127.0.0.1:8081` and passed 21/21 again. The authenticated journey driver was already present under ignored `var/`; it was inspected and reused, not installed as a new dependency.

All 15 final Docker gates passed: PHP syntax; Composer strict validation, platform requirements and vulnerability audit; JavaScript syntax; frontend tests; Symfony container lint for dev/test/prod; 59 Twig templates; 48 routes; production cache warmup; fresh/stale schema bootstrap; service suite; and PHPUnit. Composer reported no known vulnerability advisories. Native quick verification also passed. These are the repository's available checks, not PHPStan/Psalm or a separate penetration test.

Evidence is in `var/customer-audit-20261022/docker-final.log`, `native-quick.log`, `http-production-final.log`, and `http-demo.log`. The disposable test database was stopped after the suite; existing application and demo remain running.

A separate read-only reviewer examined the final scoped changes and their callers and found no actionable regression. They did not run competing database tests. Optional additional coverage for overnight court rows with NULL minute columns remains a worthwhile extension; the shared time parser and legacy end-alert path already have coverage.

## Verified local setup and operation

Existing `.env.docker` credentials were retained and were never printed. For a new installation, copy `.env.docker.example` only when the private file does not already exist, then set distinct database passwords. Docker Desktop with Linux containers is required; local XAMPP PHP/Apache/MySQL are not needed for the Docker app.

From the project directory:

```powershell
# Build/start the normal installation (this workstation uses 8081).
docker compose --env-file .env.docker up --build --wait app
docker compose --env-file .env.docker ps --all
docker compose --env-file .env.docker logs --tail=100 app setup database

# Run complete verification against the separate disposable database.
docker compose --env-file .env.docker --profile test run --build --rm test

# Stop normally, retaining data and uploaded files.
docker compose --env-file .env.docker down
```

Setup installs the base schema, applies migrations and warms the cache before Apache starts. The verified normal database has eight executed migrations, zero pending and zero unavailable. Production real-money top-ups are disabled; the database has no exposed host port. Never add `--volumes` to normal shutdown.

The exact fresh synthetic demo command used in this run was:

```powershell
$env:DOCKER_HTTP_PORT = '8082'
docker compose -p picklers-customer-20261022 --env-file .env.docker -f compose.yaml -f compose.dev.yaml -f compose.demo.yaml up --wait app
docker compose -p picklers-customer-20261022 --env-file .env.docker -f compose.yaml -f compose.dev.yaml -f compose.demo.yaml exec -T app php scripts/seed-fixtures.php picklers_e2e
Remove-Item Env:DOCKER_HTTP_PORT
```

Use the same Compose arguments with `down` to stop this demo without deleting its volumes. Its source/config/templates/assets are mounted for live changes. Fixture seeding was used only in this new demo project. Re-seeding replaces its synthetic records; do not run it against an installation containing test records you need to retain.

Synthetic demo credentials are `p1@fixture.test`, `owner1@fixture.test`, `owner2@fixture.test`, `admin@fixture.test` and `root@fixture.test`, all with `Fixture-Pass-2026`. Use them only at the local demo, never as real credentials. Normal production received no fixture accounts. See [README](../README.md) and [demo guide](local-demo.md) for the existing broader setup documentation.

## Remaining limitations and completion assessment

- Live payment collection, bank disbursement, password-reset email/SMS and social sign-in are unavailable integrations. No claim of successful live transactions or self-service recovery is made. Provider implementation/configuration and their integration tests are required before relying on those capabilities.
- Camera/QR hardware, real GPS permissions, background push, offline/network interruption on physical devices, exhaustive browser/control coverage, full accessibility conformance, sustained load/capacity, public TLS hosting and disaster-restore rehearsal were not verified in this run.
- Overnight behavior was verified deterministically and through database integration/concurrency tests, without changing the workstation clock or waiting through midnight. Browser testing covered ordinary daytime booking/refund behavior.
- The Docker Desktop socket fault can recur after host restart. Current engine/application health is verified; permanent host repair remains outside the application diff.
- Existing unrelated uncommitted work remains uncommitted. No blanket assurance about every pre-existing change is implied by this report.

The inspected defects were corrected and the recorded local workflows pass. The application is usable locally for the verified booking/community/owner/admin scope. The missing external capabilities and untested areas above prevent an honest claim of unconditional 100% completion.

## Second customer-testing pass

The user's request to run the exercise again prompted additional browser testing of account settings, first-time registration and owner-application navigation. Production and the isolated demo were already healthy. This pass reused the same demo without resetting its data. A new tracked-diff checkpoint and copies of the touched account source files were saved under `var/customer-audit-20261022-pass2/`.

### CUST-04 — Medium / Data and UX: removing a phone number did not persist

**Reproduction:** sign in as the synthetic player, open Settings → Phone Number, clear the number, save, then reload. Before correction, the old number returned despite successful feedback.

**Cause:** `AccountService::updateProfile` removed empty values before writing. The controller also supplied empty defaults for omitted keys, making an intentional removal indistinguishable from an avatar-only update.

**Fix:** the controller passes only supplied scalar fields; the account service includes an explicitly supplied phone even when blank. The existing repository normalizes it to SQL `NULL`. Omitted phone fields preserve the saved number; existing format and uniqueness validation still apply. No schema change or client-side workaround was needed.

**Files:** `src/Web/Controller/AccountController.php`, `src/Web/Service/AccountService.php`, `tests/Web/AccountTest.php`.

**Verification:** regression failed with the original stored phone instead of NULL; focused account/phone/concurrency/session tests then passed **22 tests / 153 assertions**. The actual settings form was used again and reloaded: Phone Number remained **Not set**. Direct SQL confirmed NULL. The test also covers avatar-only preservation, legacy form removal, JSON whitespace removal and a subsequent account read.

### CUST-05 — Medium / Functional: registration ignored password confirmation

**Reproduction:** enter different Password and Confirm password values on Create Account. Browser testing created and signed into a synthetic account before correction.

**Cause:** the form submitted `password_confirm`, but the server never compared it with `password`; the browser's custom submission only checked empty inputs.

**Fix:** the existing signup validation rejects a submitted blank or mismatched confirmation before hashing or creating a user. Existing API callers that omit the confirmation field retain their previous contract; the actual registration form always supplies it. Password values are compared without trimming or changing them.

**Files:** `src/Web/Controller/AuthController.php`, `tests/Web/PagesTest.php`.

**Verification:** regression first failed because HTTP 200 was returned instead of 400. After correction, focused page/account/phone tests passed **33 tests / 269 assertions**. They assert no account is written on mismatched/blank confirmation and matching confirmation succeeds. The browser displayed **Passwords do not match. Please re-enter them.**; correcting only the confirmation then created `second-pass-checked@fixture.test`. Settings and direct SQL confirmed a player account with no owner/admin privileges and zero starting credits. All identities used here are synthetic and belong only to the demo.

### Other findings, review and limits

The owner application was reached from the new player's Settings, and its empty required-field validation prevented progression. This pass did not submit a new business application through the browser; existing functional/upload/transaction tests remain the evidence for application submission.

A source-level race was found in the old player-chat handler, but tracing its callers showed the current player UI has no corresponding view or DOM hooks and rejects the former community tab. Experimental changes and tests were removed; `app.js` was verified byte-for-byte against this pass's checkpoint (SHA256 `67F1C22EF2F99FCF3464766DD66F9199EA9C1D68F45937B6EC6974F07E673AB9`). Dormant legacy cleanup is deferred. Community/messaging evidence in this report refers to HTTP API journeys and owner functionality, not a currently reachable player chat screen.

An independent read-only reviewer found no concrete regression in the two shipped fixes, including partial profile callers and existing signup callers. No dependencies, routes, migrations, visual redesign or authentication bypass were introduced. The first pass's external-integration, hardware, load and exhaustive-coverage limitations still apply.

Evidence directory: `var/customer-audit-20261022-pass2/`, including `phone-red.log`, `phone-green.log`, `signup-red.log`, `signup-green.log`, `browser-db-verification.log`, `final-verification.log`, `journeys.log`, `production-rebuild.log`, `http-production.log`, `http-demo.log`, and `graphify.log`.

Visual evidence: [phone remains removed after refresh](../var/customer-audit-20261022-pass2/phone-cleared.png), [mismatched signup rejected](../var/customer-audit-20261022-pass2/signup-mismatch-rejected.png).

Production was rebuilt with the same documented Compose startup command. `graphify update .` completed with 2,906 nodes, 5,473 edges and 243 communities. No permanent Docker host repair was necessary or attempted in this pass.

**Final second-pass verification:** all 15 Docker gates passed: **296 PHPUnit tests / 2,353 assertions**, **406 service assertions**, and **111 frontend tests**. Both rebuilt production and demo passed **21/21 HTTP checks**. The authenticated quote/book/approve/cancel/refund, Open Play, community/message, tournament and notification journeys all passed again. The disposable test database was stopped afterward; production at 8081 and the isolated demo at 8082 remain running. The two new synthetic signup accounts remain in the demo only; the fixture player's optional phone is now NULL as intentionally tested. These results verify the recorded scope without changing the remaining limitations above.
