# Whole-flow and 10,000-user readiness implementation plan

> **For agentic workers:** Use subagent-driven-development or executing-plans for implementation, with independent review after each task. This document is a plan, not authorization to implement it.

**Goal:** Verify and stabilize existing Picklers workflows, preserve their behavior, and measure capacity against a reproducible 10,000-user workload.

**Architecture:** Keep Symfony controllers, services, Doctrine DBAL, existing MariaDB schema and Twig/JavaScript UI. Fix demonstrated correctness problems at their shared boundary; profile before changing persistence or adding infrastructure.

**Tech stack:** Existing PHP >=8.2, Symfony 7.4, Doctrine DBAL, MariaDB, Twig, vanilla JavaScript, PHPUnit and Node test runner. No dependency upgrades proposed.

**Spec:** User's 2026-10-12 request in this chat: whole-app flow audit, 10,000 users, concrete repair plan, no application code changes or new features. Existing acceptance and constraints: `docs/symfony-migration/phase-2-repair-report.md`.

## Scope and evidence

This pass reviewed current sources, route/action dispatch, existing tests and prior verification artifacts, with independent player, owner/admin and capacity reviews. It reran `node --test tests/Frontend/*.test.js`: **72 passed, zero failed**. It did not start servers, change application code, migrate databases, create users or execute a 10,000-user load test.

Prior evidence, not rerun here: 221 PHPUnit tests / 1,738 assertions, 299 business assertions, 30 HTTP checks, 14/15 verification gates. The missing gate was a live dependency advisory check that timed out. Prior browser checks were partial. None of these establishes 10,000-user capacity.

Finding labels:
- **Source-confirmed:** mechanism or inconsistency visible in inspected code; runtime reproduction still required before repair.
- **Capacity exposure:** known unbounded work or serialization; no measured failure rate or capacity ceiling yet.
- **Investigation:** plausible interleaving or coverage gap, not a confirmed defect.

## Global constraints

- No new product features, redesign, new gateway, social authentication or self-service password reset.
- Follow continues to use Favorites. Staff can manage assigned courts; only owners manage staff/settings, retaining existing admin support. Do not expand unrelated financial permissions.
- Preserve existing pricing, fees, refund rules, slot grid, opening-hours behavior, tournament rules and historical identifier compatibility.
- Preserve all existing dirty work. Snapshot task-specific files before any approved implementation. No reset, clean, blanket rollback, database dropping or automatic deletion of conflicting records.
- All write/load tests use disposable synthetic data, separate private documents, sessions and limiter storage, loopback/test-only endpoints and disabled external delivery/payment effects.
- Do not silently truncate lists to improve speed. Older records must remain reachable through the existing workflow. If an optimization needs a new visible interaction, submit that specific design for approval.
- No automatic architecture expansion to Redis, queues, WebSockets, sharding or new services. First measure the existing stack.

## Findings and ordered repair tasks

Priority order is F09 first (authentication protection), then F01/F02/F10–F13 correctness, then measured capacity work. IDs remain stable for selecting individual tasks.

### F01 — Phone uniqueness is not atomic (high correctness priority)

**Evidence:** `AuthController.php:184` checks phone availability before creation; `AccountService.php:92` checks before profile update. `UserRepository.php:38` signs in through email/phone with LIMIT 1. `SchemaInstaller.php:127` declares phone without a unique constraint; inspected migrations do not add one. Two independent requests can pass the same availability check before either writes. This is source-confirmed; overlapping HTTP reproduction has not run in this pass.

**Solution:** Enforce the already intended unique phone rule at persistence, for every writer. First inventory nonempty canonical duplicates and empty strings on a restored copy. Preserve `Format::phMobile` behavior; do not invent international-number rules. Add a non-destructive migration only after collision handling is resolved; do not merge/delete accounts or discard phone numbers automatically. Align fresh installs and migrations. Translate uniqueness conflicts into the existing friendly duplicate-phone response at registration/profile/admin boundaries. Empty optional numbers remain optional; deactivated accounts retain their current reservation of their phone.

**Files:** `src/Repositories/UserRepository.php`, `src/Web/Controller/AuthController.php`, `src/Web/Service/AccountService.php`, relevant admin user writer, `src/Persistence/SchemaInstaller.php`, one new Doctrine migration selected at execution; `tests/Web/AccountConcurrencyTest.php`, auth/API and migration tests.

- [ ] List every phone writer and inspect restored-copy duplicates without mutation.
- [ ] Reproduce two different sessions registering different emails with the same phone; repeat profile/profile and registration/profile, including equivalent accepted formats.
- [ ] Implement the shared constraint/error handling after a safe migration path exists.
- [ ] Require exactly one successful claimant, no 500, no partial account/profile mutation, and unambiguous email and phone login.
- [ ] Independent review verifies nullable/empty values, existing records, deactivation, rollback and every caller.

### F02 — Session-end alert can duplicate or be lost (high correctness priority)

**Evidence:** `src/Repositories/BookingRepository.php:72–105` selects eligible bookings, unconditionally marks `end_alert_sent`, then inserts a notification. Claim and insertion have no shared transaction. `Notifier.php:21–31` performs the insert. Distinct authenticated sessions can both act on the selected row; an insert failure after the update leaves no retryable alert. Source-confirmed, not runtime reproduced here.

**Solution:** Claim a still-eligible booking using a conditional update/current locked recheck inside a DBAL transaction; only the winning claim inserts the notification. Roll back the claim when notification insertion fails. Revalidate status/date/end condition inside the transaction so cancellation does not produce a stale alert. Return an ended-session payload only for a committed winner. Preserve today's-only rule, wording and existing bell behavior. Do not promise exactly-once browser delivery after a lost HTTP response; the persistent notification must survive.

**Files/tests:** `BookingRepository.php`, `tests/SessionEndedAlertTest.php`, new controlled concurrency test alongside existing Web concurrency workers; inspect `NotificationRepository.php`, `Notifier.php`, `PlayerActions::sync` callers.

- [ ] Barrier two separate sessions/connections after eligibility; demonstrate duplicate behavior before the repair.
- [ ] Inject notification insertion failure; demonstrate the existing lost-alert condition.
- [ ] Implement atomic claim/insert, then require one persistent notification and one successful alert payload across contenders; failure leaves the booking retryable.
- [ ] Race with cancellation and early end; preserve status, money and existing end-time rules.
- [ ] Independently review transaction participation and rollback.

### F03 — Redundant polling and expensive sync (capacity exposure)

**Evidence:** `public/assets/js/app.js:4266–4321` polls all matches every four seconds without an in-flight guard. `ux-core.js:349` uses 12-second visible/60-second hidden shared sync. `NotificationRepository.php:47–51` hashes every booking belonging to the account on each sync. `PlayerActions::sync` also performs session-end work and retrieves notifications. The four-second loop runs from app initialization, not only the Play tab.

**Demand model:** 10,000 active player tabs imply approximately 2,500 match requests/s plus 833 shared-sync requests/s before user actions. Browser timer throttling, latency and actual active-tab mix affect realized traffic; these are arithmetic estimates, not benchmark results.

**Solution:** First capture per-route query counts/latency at increasing history sizes. Add non-overlap protection, visibility handling and stale-response protection to the existing match poll; retain immediate refresh when returning to a relevant view. Share existing change signals where this preserves current freshness. Reduce sync projection work without changing response semantics. Only if history hashing dominates, design a transactional per-account revision covering every booking mutation; no incomplete cache invalidation shortcut. Avoid lengthening visible freshness silently.

**Files/tests:** `app.js`, `ux-core.js`, `PlayerActions.php`, `NotificationRepository.php`; existing frontend race tests plus new poll lifecycle tests, account sync tests and request metrics.

- [ ] Record baseline idle requests, payload bytes, SQL count and latency by route and history size.
- [ ] Test delayed response longer than four seconds, hidden/visible transition, navigation, failed response and two reordered responses.
- [ ] Require at most one pending match poll, no stale UI regression, correct owner approval/refund propagation, and measured reduction under the same workload.

### F04 — Unbounded history reads and eager page loading (capacity exposure)

**Evidence:** `CommunityRepository.php:28–44` loads every visible post and comment; `:143` loads full conversations; `:203` repeats transcripts for up to 20 owner inbox partners. `BookingRepository.php:254–266` and `WalletRepository.php:35` return entire account histories. `AppController.php:54–58` requests all major tab datasets on every initial render. `OwnerPortalView.php:50–73` builds finances and conversations even outside those tabs.

**Solution:** Profile first, then remove unnecessary transcript/page work while keeping template requirements valid. Inbox summaries should retrieve last message/unread data without all messages; fetch the selected conversation through the existing conversation endpoint. For histories, use stable bounded retrieval with deterministic ordering and continuation integrated into current views; keep old content reachable. Apply filtering and ordering before limits. Retain moderation filtering, ownership, totals, unread behavior and identical displayed results. Do not replace financial totals with totals over only one page.

**Files/tests:** Those repositories/controllers/view builder, `CommunityActions.php`, existing player/owner JS and affected Twig partials; API/community/owner view/ledger/frontend tests.

Pagination/continuation is conditional on existing navigation or separately approved UI behavior. Selecting F04 does not itself authorize a new visible interaction; first complete transparent query/summary improvements and present any required navigation change concretely.

- [ ] Measure 10/100/10,000-message histories and growing posts, bookings and wallet histories separately.
- [ ] Compare old/new results for tied timestamps, hidden content, cross-user data, deleted users and all history pages.
- [ ] Require no duplicated/skipped history records, no transcript embedded for unrelated inbox threads, unchanged financial totals and lower memory/payload/query work.
- [ ] Split implementation by inbox, player history and owner view so each is independently reviewable.

### F05 — All tournaments serialize through one lock (capacity exposure)

**Evidence:** `TournamentService.php:48,67,82–87` uses one `picklers_tournaments` lock, waits up to ten seconds, and reads/decodes every tournament before a mutation. Previous repairs correctly return retryable 503 on lock failure; this does not remove cross-venue serialization.

**Solution if benchmark confirms contention:** Keep current JSON-per-row storage and bracket rules. Read and mutate the target tournament under a per-record transaction/lock; use direct insert for creation and scoped delete for deletion. Preserve creation ordering, authorization, sync updates and precise busy errors. Changing only the lock name while retaining a whole-table read/transform/write is unsafe and is not the proposed fix.

**Files/tests:** `TournamentService.php`, `TournamentActions.php`, legacy owner creation caller; `TournamentIntegrityTest.php`, `TournamentLockTest.php`, `TournamentScopeTest.php`, API tests.

- [ ] Benchmark same-tournament and different-tournament/venue edits separately.
- [ ] Require independent events to progress concurrently, same-event edits to serialize, and no lost teams/results/reset changes.
- [ ] Repeat complete Singles/Doubles/Mixed and mix-pool workflows before accepting a storage access change.

### F06 — Lock-order interaction needs controlled reproduction (investigation)

**Evidence:** application submission locks user then pending application (`OwnerPagesController.php:213–216`); approval locks application then user (`ApplicationReviewService.php:143–151`). Prior report also records booking/approval/cancellation/join lock-order risks. A reachable deadlock is not claimed as reproduced.

**Solution only after reproduction:** Establish one resource acquisition order for the actual overlapping operations, re-read under locks, and retain atomic money/document effects. Do not add blind whole-request retries that can repeat uploads or financial effects. Known contention may receive a precise recoverable response if safely rolled back; unexpected failures stay logged.

**Tests:** distinct sessions/connections and controlled barriers for submit/approve/reject, booking/cancel/decline, join/publish, wallet refund and staff revoke/action. Record SQL/lock order and final invariants. An untriggered scenario is an investigation result, not evidence of a fixed defect.

### F07 — Login limiter/storage and session contention (capacity investigation)

**Evidence:** `LoginThrottle.php` reads and exclusively rewrites one JSON store for failure/clear operations; `RateLimiter.php` uses per-identity files and fails open on open/lock failure. Symfony uses native session storage (`config/symfony/packages/framework.php`). These are implementation facts, not measured 10,000-user failures.

**Solution:** Measure distinct-account login and same-account multi-tab contention independently; include limiter storage failures in isolated tests. Preserve existing attempt ceilings and lockout behavior. If global limiter file serialization dominates, use existing database persistence for atomic scoped counters or a smaller scoped file design with safe cleanup; select only after measurements. Do not remove security controls to make a benchmark pass. Confirm session persistence and safe release before shortening lock lifetime.

### F08 — Complete acceptance evidence and advisory check

**Evidence:** previous report explicitly leaves manual coverage incomplete and fresh advisory check unavailable.

**Solution:** Execute the matrix below on synthetic data, retry `composer audit --locked`, preserve its actual exit status and output, and independently review all approved changes. No unavailable check may be marked passed. Known disabled social login and support-based password recovery remain current limitations, not new implementation tasks.

### F09 — Equivalent phone spellings bypass login counters (P1)

**Evidence:** `AuthController.php:105,112,121` passes the raw identifier; `LoginThrottle.php:158–164` hashes lowercase/trimmed text; `UserRepository.php:27–38` resolves accepted phone aliases using `Format::phMobile`. Independent read-only PHP probe found `09171234567`, `0917-123-4567`, `+63 917 123 4567` and `(0917) 123 4567` map to one phone but four throttle keys. This confirms the identity mismatch, not an executed password attack.

**Solution:** Normalize valid Philippine phones with the existing formatter before both account-only and IP/account key generation. Preserve existing email handling, attempt ceilings, expiry, clear behavior and phone acceptance. Reuse one normalization path for check/failure/clear; do not merely normalize the controller while leaving other callers inconsistent.

**Files/tests:** `src/Services/LoginThrottle.php`, `tests/LoginThrottleTest.php`; inspect AuthController and other throttle callers. No schema dependency on F01.

- [ ] Add a test distributing failures across equivalent phone spellings: the fifth same-IP failure locks every alias under current policy.
- [ ] Repeat the account-wide threshold across distinct IPs; unrelated numbers/emails remain independent.
- [ ] Verify successful sign-in clears the normalized identity and lockout expiry still works.
- [ ] Implement only shared key normalization and independently review. Keep existing limiter state compatibility/short-lived transition explicit so deployment does not silently discard active lockouts.

### F10 — New venue application overwrites existing verification (P2)

**Evidence:** `OwnerPagesController.php:235` always sets `pending_owner_review`, although existing verified players/owners can apply. `ApplicationReviewService.php:229` preserves owner status on rejection, so an already verified owner can remain pending. Source-confirmed; full submit/reject runtime reproduction pending.

**Solution:** Read the current verification/owner state from the locked user row; retain already verified status while storing the new application's own pending status. Preserve the existing pending-to-unverified lifecycle for ordinary unverified applicants. Recheck rejection behavior for both verified player and verified owner histories; do not add a new verification model.

**Files/tests:** `OwnerPagesController.php`, inspect `ApplicationReviewService.php`; `OwnerPagesTest.php`, `ApplicationsTest.php`, existing application concurrency/upload tests.

- [ ] Reproduce verified player and verified owner submit/reject cycles alongside unverified control.
- [ ] Require verification retained for previously verified accounts, correct application status, owner rights unchanged, and upload cleanup/duplicate-submit protections retained.
- [ ] Independently review using locked current state rather than stale session state.

### F11 — Concurrent court creation/rename can duplicate names (P2)

**Evidence:** `OwnerPortalActions.php:86–114` allocates/checks court number before `FacilityRepository::insertCourt` (`:644–674`); no shared lock covers check/insert, and inspected schema has no facility/name unique key. Two independent requests can both claim Court 3. Some existing booking/Open Play paths still identify courts by name. Source-confirmed race; runtime overlap pending.

**Solution:** Serialize number allocation, normalized-name duplicate check and write under the existing facility-row lock pattern. Include rename and every application/admin/provisioning court writer found by caller search; do not protect only the visible add button. Revalidate ownership/current court inside the transaction. Coordinate lock order with booking/court operations before implementation. Prefer this narrow repair first; any unique index requires duplicate-data review and safe migration.

**Files/tests:** `OwnerPortalActions.php`, `FacilityRepository.php`, inspect `FacilityProvisioner.php` and admin writers; `StaffCourtPermissionsTest.php`, `CourtAttributesTest.php`, new separate-session court concurrency test.

- [ ] Barrier two same-name additions, two auto-number allocations, add/rename and rename/rename at one venue; include distinct venues as an independent control.
- [ ] Require one success plus a friendly 409 for explicit duplicates; automatic allocation chooses distinct available names. No lost fields, duplicate names or wrong-venue writes.
- [ ] Preserve historic name-only booking compatibility and assigned staff permissions.

### F12 — Court row and attributes are not one atomic save (P2)

**Evidence:** `OwnerPortalActions.php:109–118,194–201` inserts/updates court fields before calling `setCourtAttributes`. That helper starts a separate transaction (`FacilityRepository.php:156`). Its failure cannot undo an already committed court row. Source-confirmed failure boundary; injected runtime failure pending.

**Solution:** Use one outer transaction on the shared connection for court fields, attribute replacement and related persistent effects; make the helper participate safely. Invalidation/sync must correspond to a committed change. Coordinate with F11 to avoid overlapping file edits and incompatible transaction nesting.

**Files/tests:** same action/repository as F11; `CourtAttributesTest.php`, owner HTTP tests and failure-injection regression.

- [ ] Force attribute insertion failure after a valid add and after an edit.
- [ ] Require no new court after failed add; unchanged fields and full original tags after failed edit; successful retry performs one coherent save.
- [ ] Verify empty tag selection still clears tags and unknown slugs retain current handling.

### F13 — Pre-draw category change allows incompatible tournament roster (P2)

**Evidence:** `TournamentService.php:315–346` blocks incompatible category shape only when a bracket exists. `generateBracket` (`:645–665`) checks team count but not required player count. Register two Singles entrants, change to Doubles, then draw: one-player entries remain. The inverse retains pair entries in Singles. Source-confirmed; executable scenario pending.

**Solution:** Validate the existing roster against proposed category/pairing before accepting the change; reject incompatible populated-roster transitions with a clear existing-style error. Retain empty-roster changes and supported Doubles/Mixed same-shape changes. Revalidate roster at bracket generation as a trust boundary for historical invalid records. Do not auto-delete/re-pair entrants or invent tournament rules.

**Files/tests:** `TournamentService.php`, `TournamentIntegrityTest.php`, tournament API/scope tests.

- [ ] Reproduce Singles-to-Doubles and Doubles-to-Singles with registered teams and no bracket.
- [ ] Require rejection without persistent changes, valid empty/same-shape changes still succeed, and invalid historical rosters cannot start a bracket.
- [ ] Independently review mix-pool behavior, team metadata and existing live-bracket guards.

### F14 — Bounded court display identity issue (investigation/legacy data)

`OwnerPortalView.php:140–147` permits a match with a conflicting explicit court ID to fall through name/type or the single-court fallback. Current normal hosting uses name-based `matches.type`, so the explicit-ID case mainly involves historical/imported data. Display contamination is source-supported; wrong-session termination was not established. Test explicit conflicting IDs and a deleted-court legacy session before repair. If reproduced, make explicit IDs authoritative while retaining name fallback only for absent IDs; verify current normal hosting still displays correctly. Files: OwnerPortalView and OwnerPortalViewTest. Do not broaden this into historical data rewriting.

### Supporting measurement rules

- Existing indexes already cover several booking, message and notification lookups. Inspect deployed indexes and representative EXPLAIN output before adding indexes; a leading-wildcard search is not fixed by a routine name index.
- Sync channels are global, so one court mutation can trigger many discovery refreshes. Include this fanout in the workload.
- Unread count currently derives from the latest 20 notifications. Establish whether the existing UI intends all unread versus displayed unread before changing semantics; include an older-unread fixture. Do not label a policy interpretation a confirmed bug.
- Preserve internal full-history callers such as account deletion when introducing bounded UI reads.

## Whole-app workflow acceptance matrix

All rows are required coverage in the next approved execution; this table does not mark them newly passed. Use guest, player A/B, owner A/B, assigned/revoked staff, limited/privileged admin and deactivated accounts. For every mutation verify database outcome, refresh persistence, duplicate submission, invalid input, permissions and failure recovery.

| Area | Complete existing journey and negative cases | Existing evidence anchors |
|---|---|---|
| Authentication/accounts | Register, email/phone login, invalid/locked login, logout, profile, password change and other-session revocation, deactivate; duplicate identity races | `AccountTest`, `AccountConcurrencyTest`, `PasswordSessionRevocationTest`, auth controller |
| Discovery/Favorites | Search/filter/sort, venue detail, Follow persistence, venue switching, stale responses, unavailable/suspended venue | `DiscoverTest`, `DiscoverOccurrenceTest`, frontend discovery/favorite tests |
| Court booking | Standard and Quick Book, today/tomorrow boundary, availability, fee/voucher quote, wallet/cash/manual method, receipt/pass, history, owner decision, cancellation/refund, check-in, early end | `BookingCourtIdentityTest`, `ApiTest`, `BookingsTest`, `SlotCollisionTest`, frontend booking/voucher tests |
| Open Play | Publish/change/end, roster, join/final seat, repeated join, cancel/refund, expired session, owner/player live updates | `OpenPlayPublicationTest`, `OpenPlayRolloverTest`, existing join/publication workers |
| Wallet/payments/promos | Existing development simulation, production denial, insufficient balance, deductions/refunds, repeated actions, redemption limits, admin adjustment and ledger reconciliation | `PaymentEnvironmentTest`, `WalletTest`, `PromosTest`, `LedgerAndReportsTest`, pricing tests |
| Owner applications | Upload valid/invalid/private documents, duplicate submit, approve/reject/reapply, document authorization, failed upload/transaction cleanup | `OwnerPagesTest`, `OwnerApplicationConcurrencyTest`, `UploadAtomicityTest`, `ApplicationsTest` |
| Owner/staff/courts | Venue selection, court CRUD/status, assigned staff rights, revoke active staff session, owner-only settings/staff, requests, existing earnings/payout flow | `StaffCourtPermissionsTest`, `StaffIdentityTest`, `OwnerFacilitySelectionTest`, owner repository tests |
| Tournaments | Create/edit/delete, entrants, fixed/mix, Singles/Doubles/Mixed, draw, result/reset, completion, invalid live shape changes, concurrent edits | `TournamentIntegrityTest`, `TournamentScopeTest`, `TournamentLockTest` |
| Community/messages | Existing reachable feed post/like/comment and moderation, owner/player/support conversation, unread state, recipient switch, send/retry and stale polling | `ModerationTest`, `LikeConcurrencyTest`, owner chat/frontend tests, `CommunityActions` |
| Notifications | Create/read/delete, persistence, bell, older unread interpretation, end alerts across devices and transaction failure | `NotificationsTest`, `SessionEndedAlertTest`, frontend notification tests |
| Admin | Capability checks, applications, users, facilities, bookings, wallet/ledger, promo, moderation, notifications, analytics/export, audit, impersonation and exit | `SecurityTest`, `PrivilegeConcurrencyTest`, `ImpersonationTest`, admin service suites |
| Runtime/install | Fresh isolated install, restored-current copy, migrations, private path protection, assets, cache/session writable, restart persistence and setup instructions | `SchemaInstallerTest`, `DataPathTest`, `DevelopmentRouterTest`, prior HTTP/recovery evidence |

Run primary journeys on public-root Apache and real XAMPP subdirectory; smoke the actual development router. Check desktop and mobile layouts, keyboard/modal navigation, browser console/network, slow/reversed responses, lost connections and reconnect. Camera check-in requires a real camera/browser permission test; distinguish manual code lookup from camera verification. External payment/email delivery is not simulated into a real integration claim.

## Reproducible 10,000-user test design

### Separate claims

1. **Dataset scale:** 10,000 distinct synthetic registered users and realistic histories. This tests query/data volume, not simultaneous traffic.
2. **Active usage:** authenticated independent virtual users following existing journeys with think time and real CSRF/cookies. Report concurrency and achieved request rate separately.
3. **10,000 concurrent users:** only claim this after 10,000 independent active virtual users actually sustain the defined workload on specified hardware. A loop of 10,000 requests or one shared session is not equivalent.

### Isolation and fixture contract

- Dedicated audit database, private-document directory, sessions, rate-limit store and runtime; verify resolved paths and database name before seeding. Never point a seeder/load generator at normal XAMPP data.
- Dataset proposal (a workload assumption, not product requirements): 10,000 users with player/owner/staff/admin roles; 100 venues/400 courts; 100,000 bookings split historical/future and statuses; 200,000 wallet ledger rows with reconciled balances; 10,000 posts/50,000 comments; 100,000 messages; 100,000 notifications; 1,000 tournaments. Include skew: busy venue, long-history player, busy inbox. Generate valid relational and monetary states; store fixture seed/checksum.
- Seed through isolated fixture tooling after implementation approval, not public signup 10,000 times; separately test real signup/login with existing limits enabled. Use only synthetic documents and no real charges/deliveries.
- Capture machine CPU/RAM, web/PHP worker limits, PHP/debug/cache settings, DB limits/configuration, dataset counts and generator machine. PHP development server results are smoke evidence, not a production capacity benchmark.

### Workload and progression

Starting journey mix: 50% discovery/availability, 20% booking/Open Play, 10% account/history, 10% messages/community, 8% owner operations, 2% admin/tournament operations. Treat this as an explicit assumption; report actual route request mix because poll traffic alters it. Retain the real 4s/12s/5s poll patterns for the baseline, realistic think times, distinct sessions, and realistic shared-resource collisions.

Stages: 1-user correctness smoke; then 10, 50, 100, 250, 500, 1,000, 2,500, 5,000 and 10,000 active users. At each stage ramp for two minutes and hold for five minutes. Stop escalation when a gate fails; preserve the first failure and last stable stage. Sustain the highest passing target for 30 minutes, then run a bounded spike and recovery test. A constrained local machine may stop far below 10,000; report that result instead of overwhelming it or extrapolating success.

Use an approved HTTP load runner selected at execution; no runtime dependency added to the application. Prefer a separate generator for high stages. Track generator CPU/saturation and achieved arrivals so client overload cannot masquerade as server capacity.

### Measurements and proposed acceptance gates

These are proposed engineering targets, not existing contractual promises:
- Zero cross-account/venue access, duplicate winning booking, oversold seat, duplicate financial effect or balance/ledger discrepancy.
- Eligible ordinary requests: unexpected errors <0.5%; p95 reads <=1s, writes <=2s; p99 <=5s. Record each endpoint, not just blended averages.
- Expected conflict/validation/rate-limit responses reported separately with scenario assertions; do not hide genuine failures by calling every 4xx expected. Tournament busy rates remain visible.
- Capture requests/s, p50/p95/p99, payload bytes, PHP memory/workers, DB active connections/slow queries/lock waits/deadlocks, CPU/RAM/disk and generator utilization.
- Stop immediately for any data-integrity/security failure; stop escalation for >1% unexpected errors or p95 >5s for one minute, DB connection exhaustion, or sustained memory pressure. Preserve diagnostics; allow recovery before another run.
- Following spike/recovery, ordinary flows return to baseline behavior and no pending/partial transaction or unreconciled wallet state remains.
- Compare before/after with identical fixture seed, hardware, timings and mix. Publish raw reports and final database invariant checks.

## Execution order, review and rollback

1. Approve selected tasks; establish isolated fixtures and baseline evidence.
2. Reproduce F09, F01/F02 and F10–F13 and implement the smallest verified correctness repairs, each independently reviewed. F11/F12 share files and must be coordinated serially.
3. Run controlled F06 concurrency tests; fix only demonstrated failures.
4. Profile F03–F05/F07; implement measured bottlenecks in separate reviewed slices. No arbitrary dependency or schema changes.
5. Complete workflow matrix, staged load, advisory check and a second targeted audit of changed boundaries.
6. Run combined existing verification with database suites serialized; update graph after application changes and final evidence report.
7. Stop audit-owned services, preserve reports/backups and compare normal data/private-document hashes.

For each approved task: retain exact before-snapshot/diff, record failing reproduction, implement, pass targeted tests, independent review, then combined verification. Revert only that task's files if rejected; never roll back unrelated dirty work. Rehearse migrations on a restored copy and use tested recovery rather than destructive down migrations against user data.

**Completion means:** all selected repair criteria pass; every matrix row has an honest tested/failed/blocked/not-applicable disposition; measured load results state hardware, achieved concurrency and limits; local install/recovery works. It does not mean a guarantee of no possible bug.

## Plan review record

### Execution finding F15 — New player wallet entries omit existing balance evidence

Confirmed by the isolated HTTP journeys: both ordinary booking and Open Play debit rows have NULL `balance_after`, while matching refunds store the correct balance. Aggregate wallet reconciliation passes; this finding concerns incomplete existing ledger evidence, not lost money. `WalletRepository::addTransaction` documents resulting-balance metadata for new entries. Its three production callers (booking, Open Play and top-up) already update wallets inside transactions holding the user row lock.

Repair only those callers: pass the exact database balance after their existing update to the existing ledger metadata. Preserve amounts, fees, refund rules, response contracts and historical rows; do not infer past balances or add a gateway. Require failing-before/passing-after checks for ordinary booking, concurrent Open Play joins and decimal top-ups, then independent review and combined verification.

- [x] Record exact post-update balances for all three existing callers.
- [x] Verify only successful charges produce a ledger row and refunds remain unchanged.
- [x] Independently review and repeat the relevant acceptance flows.

Final F15 evidence: failing-before checks, affected 33 tests / 237 assertions, independent source review, 13/13 corrected HTTP journey groups, and combined 256 tests / 2,137 assertions. F16 additionally preserves linked wallet payment methods and complete Open Play booking codes; independent review and 37 tests / 343 assertions include actual Symfony rendering. Normal data upgrade, local startup and capacity limits are recorded in `docs/symfony-migration/whole-flow-repair-report.md`.

Three independent read-only reviews informed this plan. Root repeated the phone-key probe and confirmed four aliases share one canonical phone but four limiter keys, without making a login attempt or writing limiter state. Independent owner/admin plan review checked F10–F14 against source and required the explicit F04 UI approval boundary above. Application code, dependencies and databases were unchanged in this planning pass; only this document was added.
