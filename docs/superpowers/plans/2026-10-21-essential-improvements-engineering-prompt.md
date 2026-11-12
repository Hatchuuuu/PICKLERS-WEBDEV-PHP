# PICKLERS Essential Improvements — Full Loop Engineering Plan Prompt

> **For agentic workers:** Use the installed `subagent-driven-development` or `executing-plans` skill when executing approved implementation slices. Track steps with checkboxes. This document is a researched execution brief and reusable prompt, not a claim that proposed changes have been implemented or tested.

**Goal:** Make the existing PICKLERS booking platform easier to use, operationally complete for its chosen launch scope, and demonstrably reliable without adding speculative features.

**Architecture:** Retain Symfony controllers, Twig views, existing JavaScript/CSS, Doctrine DBAL repositories, and shared domain services. Repair common rules at their existing shared boundary and carry each change through the API, UI, persistence, tests, and operations.

**Tech stack:** PHP >=8.2, Symfony 7.4, Twig 3, Doctrine DBAL 4, MySQL/MariaDB, PHPUnit 11, Node-based frontend regression tests; Windows/XAMPP and subdirectory routing must remain supported.

**Spec:** The user requests a researched engineering implementation prompt covering essential missing features, logical gaps, UI/UX improvements, agent responsibilities, and a complete verification loop. The scope and acceptance requirements below form the execution brief; resolve conditional business decisions before implementing the affected behavior.

**Research date:** October 21, 2026, Asia/Manila.

**Selected scope after user clarification:** Local use, local testing and a professor presentation; no email/SMS provider or monitored support channel. Execute the local essentials only. Real payment processing, public deployment, automated recovery and production capacity are conditional future work, not requirements for this presentation. Current implementation evidence and remaining boundaries are recorded in `../essential-2026-10-21/end-to-end-readiness.md` and the local presentation runbook.

## 1. What this review established

This preparation used the existing graph query, selected current source files, project manifests, brand guidance, previous engineering reports, and external primary sources. It did not execute the application, rerun tests, perform a fresh browser audit, or reproduce old load measurements.

The working directory contains extensive tracked and untracked work. Treat that work as the baseline. Reports describe earlier snapshots; their test counts and performance results are historical evidence, not proof that the current working tree passes.

| Finding | Evidence | Classification and consequence |
|---|---|---|
| Production wallet top-ups are intentionally disabled; development/test simulation requires an explicit flag. | `src/Web/Api/PlayerActions.php`, `topUp()`, around lines 256–274 | Confirmed capability gap if real wallet funding is part of launch. Preserve the fail-closed guard. A manual-payment launch may remain valid. |
| Password recovery currently routes to support rather than self-service recovery. | `templates/web/auth.html.twig:644`, forgot-password view around line 721; inspected `AuthController` and `AuthService` | Confirmed feature gap. Essential for a public account-based launch; a verified support process can serve a bounded pilot. |
| Player and staff cancellation rules intentionally differ; external payments are not refunded by the internal wallet. | `src/Repositories/BookingRepository.php`, `isRefundable()` / `cancelBooking()`; `src/Domain/BookingRules.php`, `cancelAsStaff()` | Existing policy, not automatically a defect. Show the actual outcome before cancellation and trace external refund handling. Do not silently unify the policies. |
| Shared modal focus handling, scroll locking, announcements, and submit guards already exist. | `public/assets/js/ux-core.js` | Reuse and verify wiring. Do not report missing dialog roles from raw Twig alone because JavaScript adds them. |
| Earlier capacity testing passed short read stages through 500 sessions and failed at 1,000. | `docs/symfony-migration/whole-flow-repair-report.md` | Historical measured limitation: 4.99% failures and approximately 12.56 s p95 at the failed stage. Production-scale acceptance remains open. |
| The earlier report measured a roughly 16.3 MB feed response, but found no active player feed renderer. | Same report; `src/Repositories/CommunityRepository.php` | Backend payload concern, not proof of a visible feed defect. Verify exposure and consumers before changing contracts or building a feed. |
| Message history retrieval is unbounded in the inspected method. | `src/Repositories/CommunityRepository.php`, `getMessages()` | Confirmed query shape; practical impact requires measurement and tracing its current consumers. |
| Favorites, notifications, owner staff management, tournament integrity, cancellation/refund logic, and numerous concurrency regressions already exist. | Existing services, `tests/Web`, `tests/Admin`, `tests/Frontend`, prior reports | Preserve and extend these features; do not propose them as absent. |
| Identity verification request submission changes status to `pending_review` and creates a notification. | `src/Web/Service/AccountService.php`, `requestVerification()` | Trace through admin decision and player outcome. This method alone does not prove that the entire verification workflow is missing. |
| Existing branding specifies navy, mint, sky blue, Inter, and Montserrat branding. | `docs/PICKLERS_BRAND_GUIDE.md` | Improve clarity and consistency within this identity; no unrelated visual rebrand. |

## 2. Copy-ready execution prompt

Everything from “Mission” through “Final handoff” below can be supplied to an engineering agent together with repository access.

### Mission

Act as the engineering lead for PICKLERS at `C:\xampp\htdocs\PICKLERS WEBDEV PROJECT`.

Investigate, prioritize, implement, verify, independently review, and document only improvements needed for the existing player, owner/staff, and admin workflows. Complete vertical slices rather than leaving disconnected backend features, decorative UI, or untested migrations.

The core journeys are:

1. Find a venue → select a valid court/date/time → obtain authoritative pricing → reserve → owner decision → attend or cancel → correct payment/refund outcome.
2. Discover an Open Play occurrence → join within capacity → approve/decline → release the correct occurrence's seat when cancelled.
3. Sign up/sign in → maintain an account → recover access → retain correct authorization across password and staff changes.
4. Apply as an owner → receive an actionable approval/rejection → configure courts and hours → connect real staff accounts → operate bookings.
5. Review exceptions as admin → make an authorized decision → preserve an audit trail and correct financial/history records.
6. Use existing tournaments, messages, notifications, and wallet views without broken state or misleading success claims.

### Global constraints

- Read repository `AGENTS.md` and relevant skills. Query graphify first when its graph exists; use path/explain for relationships. Consult the wiki if present. Verify graph results against current source.
- Preserve all pre-existing changes. Do not reset, clean, overwrite, or stage unrelated files. An isolated checkout must include the intended baseline; a default-branch checkout would omit this working directory's uncommitted work.
- Retain existing routes and aliases, JSON contracts unless explicitly versioned, subdirectory support, role boundaries, and existing historical data.
- Reuse `BookingRules`, `WalletLedger`, `Money`, `PricingService`, `OwnerScope`, existing repositories, and `ux-core` before introducing a parallel implementation.
- Do not introduce React, another backend framework, a UI kit, a queue, Redis, microservices, or new dependencies without a demonstrated requirement that the current stack cannot reasonably satisfy.
- Use Asia/Manila for user-facing dates and PHP/Peso amounts for product presentation. Inspect actual storage/time conventions before changing them. Preserve exact monetary arithmetic and existing currency boundaries.
- Distinguish confirmed defect, confirmed missing capability, historical finding, product decision, and unverified hypothesis.
- Never infer that “no search result” proves a feature is absent. Trace routes, callers, UI consumers, schema, tests, and documentation.
- Do not change cancellation windows, fees, eligibility, approval rules, wallet cash-out, identity policy, or retention policy without resolving the applicable product decision.
- Implement and test external integrations in a sandbox. Production credentials, real charges/refunds, deployments, paid services, and external messages require the relevant explicit authorization.
- Use disposable databases for tests. Read reset/seed/migration scripts before running them. Keep production data and private uploads out of test artifacts.
- After code changes, run `graphify update .`; report failure honestly if the tool cannot complete.

### Agent team and scheduling

Use `gpt-6.1-sol` for each role if available, as requested. These are responsibilities, not a requirement to keep every role running simultaneously.

| Role | Owns | Required output |
|---|---|---|
| Lead / integrator | Scope, dependency order, contracts, shared-file ownership, integration, completion ledger | Prioritized backlog and evidence-backed release decision |
| UI/UX agent | Existing player/owner/admin templates, styling, interaction and accessibility | Before/after browser evidence and complete UI states |
| Flow / domain agent | Booking, Open Play, pricing, cancellation, owner onboarding, cross-role state transitions | State-transition matrix, root-cause changes, concurrency regressions |
| Account / payment specialist | Recovery, money and provider boundaries when included | Abuse/replay tests, reconciliation evidence, explicit external blockers |
| QA / operations agent | Browser journeys, test fixtures, migrations, payload and load measurements | Reproducible commands, environment details, results and failure evidence |
| Independent reviewer | Authorization, data integrity, accessibility, contracts, regression risks | Findings with severity, file/line, reproduction and acceptance decision |

With four available slots, keep the lead plus at most three workers active. Start with UI/UX discovery, flow discovery, and independent risk review. Schedule the specialist and QA roles as slots free up. A reviewer must not approve their own implementation; rotate ownership if necessary.

Delegate only bounded tasks with exact owned files, dependencies, forbidden edits, acceptance cases, and expected evidence. Shared database schema, shared CSS/JS, route dispatch, and common services have one writer at a time. Parallelize independent discovery first; serialize changes that depend on shared contracts.

Use `graphify`, `writing-plans`, and `systematic-debugging` where applicable. Use `impeccable` for focused UI evaluation/polish and the existing brand guide for visual decisions. Use `verification-before-completion` and `requesting-code-review` for delivery gates. Read each selected skill rather than assuming its instructions. Do not load every design skill or redesign the entire application.

### Phase 0 — Establish evidence and scope

- [ ] Record Git status and commit identifier, preserve the working-tree baseline, and list relevant untracked files without copying secrets.
- [ ] Read the prior audit/repair reports and identify which changes are already present.
- [ ] Inventory current routes, role/capability boundaries, schema/migrations, user-facing screens, background/maintenance behavior, and verification scripts.
- [ ] Establish a fresh baseline with the existing verification runner after checking its disposable database configuration. Record pre-existing failures separately.
- [ ] Trace each core journey through UI → API/controller → service → repository → database → notification → refreshed UI.
- [ ] Produce a feature inventory marked complete, incomplete, broken, intentionally unsupported, or unknown.
- [ ] For every proposed task, record evidence, affected user, failure scenario, impact, confidence, dependencies, implementation scope, acceptance tests, and rollback.
- [ ] Choose the launch boundary: bounded demo/pilot, public manual-payment release, or public integrated-payment release. Continue independent work while requesting any unresolved essential business decision.

A useful finding is: “A player loses access because the only recovery action opens an email client; implement verified recovery or validate a real support recovery procedure.” An unacceptable finding is: “Add AI recommendations because competing platforms have them.”

### Phase 1 — Close booking and financial ambiguities

**Likely files:** `src/Repositories/BookingRepository.php`, `src/Repositories/OpenPlayRepository.php`, `src/Domain/BookingRules.php`, `src/Domain/WalletLedger.php`, `src/Services/PricingService.php`, `src/Web/Api/PlayerActions.php`, owner/admin action services, associated Twig/JS and existing booking tests.

- [ ] Document actual states and allowed transitions for reservations, owner approval, payment, cancellation, refunds, and Open Play occurrences. Keep payment state distinct from booking approval; introduce storage only if current fields cannot truthfully express the chosen flow.
- [ ] Verify that pending reservations and Open Play seats have an intentional lifecycle. Reproduce abandoned/never-approved requests before proposing expiry. Any new timeout needs a policy and explicit UI disclosure.
- [ ] Verify authoritative quote recalculation, stale availability handling, simultaneous reservations, duplicate submission, lost HTTP responses, and safe retry. Reuse existing serialization; add request idempotency only where a concrete duplicate-mutation case requires it.
- [ ] Show total, date, venue, court/occurrence, payment method, approval status, and cancellation consequences before commitment.
- [ ] Preview the actual refund outcome from the same server rule used by cancellation. Preserve pending versus confirmed and player versus staff differences.
- [ ] Trace cash/GCash/manual payment evidence and external refund follow-up. Never display a successful external refund merely because the booking became cancelled.
- [ ] Trace facility suspension, court maintenance, changed operating hours, and staff revocation against existing and new bookings. Do not silently cancel historical or future reservations.

**Acceptance:** Two competing requests cannot both win an exclusive slot; the last Open Play seat cannot oversell; failed writes cannot leave partial debits; repeated cancellation cannot refund twice; all roles see the same persisted outcome; retries after an uncertain response reconcile before inviting another payment.

**Tests:** Extend `tests/SlotCollisionTest.php`, `tests/PricingServiceTest.php`, `tests/Web/ApiTest.php`, `tests/Admin/BookingsTest.php`, and the existing concurrency-worker patterns. Test the 24-hour boundary on both sides, midnight sessions, zero-price bookings, insufficient balance, foreign venue IDs, and duplicate requests.

### Phase 2 — Account recovery and operational completion

**Existing anchors:** `src/Web/Controller/AuthController.php`, `src/Services/AuthService.php`, `src/Services/LoginThrottle.php`, `src/Domain/PasswordPolicy.php`, `src/Web/Service/AccountService.php`, `templates/web/auth.html.twig`, `tests/Web/PasswordSessionRevocationTest.php`.

- [ ] For a public self-service release, implement the smallest secure recovery flow using a verified account recovery channel; do not assume every stored email/phone has been verified.
- [ ] Use random single-use tokens, store token hashes, enforce expiry and request throttling, return non-enumerating responses, and atomically consume tokens when updating the password.
- [ ] Reuse password policy and session-revocation behavior. Do not automatically sign the user in after reset. Do not log tokens or include them in analytics.
- [ ] Define secure delivery configuration and a test transport. If a new mail dependency is necessary, justify it and inspect current integration options first. Do not build fake email delivery or use security questions.
- [ ] For a pilot that retains support recovery, verify an actual monitored channel and identity-check procedure; do not claim the mailto link is a complete secure recovery system.
- [ ] Trace owner application rejection/resubmission, verification decisions, staff-account linking, notification receipt, and account deletion consequences. Repair only demonstrated dead ends.

**Proposed files if self-service is selected:** `src/Web/Service/PasswordRecoveryService.php`, `tests/Web/PasswordRecoveryTest.php`, and the next uniquely named Doctrine migration under `src/Migrations/`. Finalize their interfaces after checking the current user schema and recovery-channel integration; do not invent a migration number that collides with ongoing work.

**Acceptance:** Expired, reused, malformed, and concurrent token submissions cannot reset an account twice; unknown users receive equivalent public responses; reset invalidates prior sessions; delivery failure remains recoverable; unrelated identity-verification status is unchanged.

### Phase 3 — Payment launch boundary

**Existing anchors:** `PlayerActions::topUp()`, `WalletLedger`, `WalletRepository`, `Money`, `tests/Web/PaymentEnvironmentTest.php`, `tests/Web/WalletDisplayTest.php`.

**Manual-payment release:** Clearly communicate supported methods and pending/manual confirmation. Keep unavailable top-ups unavailable and provide a truthful recovery path. Verify owner/admin recording and refund follow-up without pretending a gateway exists.

**Integrated-payment release, only when selected:**

- [ ] Select one provider based on the actual merchant account, supported Philippine payment methods, settlement/refund needs, and operating costs. PayMongo documentation is research input, not an automatic vendor selection.
- [ ] Specify payment initiation, pending, succeeded, failed/expired, and refund reconciliation behavior separately from booking approval.
- [ ] Verify provider signatures over the required raw payload, match amount/currency/account/reference to server records, and deduplicate provider events with durable uniqueness.
- [ ] Commit event processing and wallet credit atomically. A browser redirect is never payment evidence.
- [ ] Test delayed, repeated, invalid, and out-of-order events, provider timeout, database failure, and process interruption.
- [ ] Define a minimal reconciliation procedure for uncertain outcomes and unresolved external refunds. Reuse the existing admin/ledger surfaces.
- [ ] Keep simulation explicitly limited to development/test and visibly labelled there.

**Acceptance:** A repeated provider event credits once; tampered amount/signature credits nothing; client-side claims cannot fund wallets; a lost response can be reconciled; sandbox end-to-end evidence exists before production enablement.

**Dependency:** Complete the booking/payment state contract before UI or gateway code relies on it. Provider credentials and merchant onboarding are external blockers, not reasons to weaken safeguards.

### Phase 4 — Improve the existing UI/UX

**Files:** `public/assets/js/ux-core.js`, `public/assets/css/ux-core.css`, `theme.css`, `app.css`, `owner.css`, `admin.css`; relevant `templates/web/partials/app`, `templates/web/partials/owner`, auth and admin templates; corresponding frontend tests.

- [ ] Capture baseline screens for guest discovery, booking review, checkout, bookings, wallet, owner requests, staff management, and the admin exception flow.
- [ ] Preserve the brand: navy `#0A121F`, mint `#55C39E`, sky blue `#69A2D0`, Inter text, Montserrat brand mark. Measure contrast; brand colors do not guarantee readable combinations.
- [ ] Give each state one obvious primary action. Show venue/court/date/time/price and approval status where the decision is made.
- [ ] Keep filter and form input through recoverable errors. Show field-level errors and a clear retry path; do not rely exclusively on transient toasts.
- [ ] Cover loading, empty, no-results, validation failure, timeout, stale conflict, permission loss, unavailable payment, success, and cancellation/refund follow-up.
- [ ] Verify every modal uses the existing shared manager: initial focus, contained tab order, Escape, restored focus, background interaction prevention, nested-dialog behavior, and scroll restoration.
- [ ] Check touch targets, semantic controls, labels, keyboard navigation, visible focus, reduced motion, screen-reader announcements, zoom, and mobile keyboard overlap.
- [ ] Validate at 360/390 px mobile, 768 px tablet, and 1280/1440 px desktop; include 200% zoom and 320 CSS-pixel reflow where applicable.
- [ ] Aim for 44 px touch controls on primary mobile actions; evaluate the actual WCAG 2.2 AA 24 px target-size rule and exceptions rather than mislabelling 44 px as an AA requirement.
- [ ] Consolidate conflicting styles only where needed for the touched flow. Avoid a wholesale CSS rewrite or decorative animation that slows booking.

**Acceptance:** A keyboard-only user can complete the chosen booking and cancellation journeys. Totals, payment and approval states remain clear on mobile. No clipped action buttons or unexplained disabled controls. Before/after screenshots and interaction results support each claimed improvement.

### Phase 5 — Bound payloads and establish realistic capacity

**Files:** `CommunityRepository.php`, its actual API/UI callers, `scripts/audit/flows.mjs`, `scripts/audit/load.mjs`, existing audit self-tests, and relevant operations documentation.

- [ ] Verify the consumers and exposure of feed/message endpoints. Measure payload size and query cost using representative histories.
- [ ] If an active message history needs pagination, add bounded retrieval with stable ordering, ownership checks, and a working “load older” interaction. Preserve scroll position, unread behavior, and no-duplicate/no-skip semantics when timestamps tie or new messages arrive.
- [ ] Treat unused feed optimization as conditional on exposure/abuse cost and launch needs; do not add a social-feed interface just to justify pagination.
- [ ] Investigate the historical Windows connection/port exhaustion using observed connections, database limits, request concurrency, and application connection lifecycle.
- [ ] Reuse the current load harness, validate it first, and measure on an isolated production-shaped environment with app/database/generator roles documented.
- [ ] Set a realistic supported concurrency target before testing. Separate registered-user count from active sessions and simultaneous writes.
- [ ] Run a bounded mixed workload: discovery, booking, joins, owner approval, cancellation, notifications, and messages. Use synthetic accounts and no real payment provider calls.
- [ ] Agree latency/error thresholds before execution. Proposed initial pilot criteria are p95 reads under 1 second, p95 internal mutations under 2 seconds, unexpected errors under 1%, and zero money/booking invariant violations. These are proposed targets, not measured guarantees.
- [ ] Include a 30-minute steady stage and a recovery check after load. Stop on data-integrity failure, escalating error rates, or exhausted resources.

**Acceptance:** Report achieved concurrency, request rate, durations, dataset, hardware, p50/p95/p99, error types, and invariant results. Never label the system “10,000-concurrent-user ready” from a 10,000-account fixture or a short read-only test. Do not enable persistent PDO or increase global limits blindly.

### Phase 6 — Verification, release and recovery

For every implementation slice:

1. Reproduce the problem or document the selected feature's expected behavior.
2. Identify all callers, affected tests, fixtures, configuration, routes, migrations and UI consumers.
3. Add a focused failing regression for nontrivial logic and confirm the failure represents the intended defect.
4. Implement the smallest complete change.
5. Run focused tests, then exercise the full affected user journey.
6. Obtain independent correctness/security review and UI review when relevant.
7. Fix findings and rerun the affected checks.
8. Update evidence, release/rollback notes and the graph.

Use existing commands after inspecting configuration:

```powershell
php scripts/verify.php --quick
php scripts/verify.php
node --test scripts/audit/flows.test.mjs scripts/audit/load.test.mjs
php scripts/audit/ledger-check.test.php
```

The full verification script includes disposable schema bootstrap, legacy assertions, PHPUnit, frontend regressions, PHP/Twig/container validation and dependency checks. Record command, timestamp, environment, exit status, and log path. Do not reuse old pass counts as new results.

Browser verification must cover player → owner/staff → player and admin exception paths, root and XAMPP subdirectory installations, refresh/back navigation, slow requests, and authorization loss. A DOM unit test alone does not establish correct visual layout or keyboard behavior.

For schema changes, rehearse on a protected copy with fresh install, existing populated schema, retry after interruption, and old-data compatibility. Inspect `scripts/rehearse-upgrade.php` before use because it reads the configured database. Verify restore procedures and retention of private documents. Do not run a live migration as an incidental test.

Release only the verified scope. Disable incomplete external capabilities truthfully. Separate application rollback from financial reconciliation: rolling back code does not undo a real payment. Define failure indicators, responsible operator, health checks, and a usable rollback/restore procedure.

### Essential versus deferred

**Essential now:** Truthful booking/payment/refund states, preserved financial and permission invariants, complete core journeys, usable error recovery, accessible critical interactions, fresh verification, and a realistic documented operating limit.

**Essential for a public self-service launch:** Secure account recovery with a verified channel and reliable operational support.

**Essential only for an integrated-payment launch:** Real provider integration, event verification, durable deduplication, reconciliation, and refund handling.

**Investigate before adding:** Reservation expiry, rescheduling, no-show tracking, reminders, waitlists, payment receipts, identity-review evidence collection, message pagination, and stronger privileged-account authentication. Elevate an item only after identifying the actor, concrete failure, existing alternative, policy and acceptance test. A wallet history row is not automatically a legally valid receipt.

**Defer without evidence:** AI matchmaking, recommendations, loyalty points, subscriptions, native apps, broad PWA/offline support, social-feed expansion, new tournament formats, elaborate dashboards, automatic payouts, and architecture rewrites.

### Deliverables and final handoff

Maintain these artifacts within a dated folder under `docs/superpowers/`:

- Evidence inventory and priority backlog, including rejected ideas and reasons.
- Core journey/state-transition matrix and unresolved product decisions.
- A detailed plan for each selected slice, with exact file paths, current interfaces, concrete tests, dependencies and rollback.
- Implementation ledger showing not started, in progress, blocked, verified or deferred, with evidence per item.
- UI comparison screenshots and interaction/accessibility results.
- Test/review report and explicit external-integration limitations.
- Operational launch checklist, supported capacity, restore instructions and release decision.

Completion means every selected acceptance criterion has current evidence, no unresolved critical/high findings remain in that scope, and required external dependencies are either proven or explicitly prevent release of their capability. Documented deferral is not implementation completion.

Final response: state what changed, which journeys now work, exact checks run, unresolved blockers, measured limits, and rollback location. End with what was skipped/not checked and material risk. Do not claim “production-ready,” “fully secure,” or “all flows tested” beyond the evidence.

## 3. Research supporting this prompt

- [OWASP — Forgot Password Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Forgot_Password_Cheat_Sheet.html): supports non-enumerating recovery responses, rate limits, expiring single-use tokens, secure token handling and session considerations. Applied to the confirmed support-only recovery gap.
- [W3C — Modal Dialog Pattern](https://www.w3.org/WAI/ARIA/apg/patterns/dialog-modal/): supports contained focus, accessible naming and focus restoration. Applied to verifying existing `ux-core` behavior rather than adding a competing modal implementation.
- [W3C — Target Size Minimum](https://www.w3.org/WAI/WCAG22/Understanding/target-size-minimum): specifies the 24 CSS-pixel AA target-size criterion and its exceptions. The larger 44 px mobile goal above is a design recommendation.
- [PayMongo — Webhook Key Concepts](https://docs.paymongo.com/docs/developer-tools-webhooks-key-concepts): documents signed events and automatic delivery retries. Supports signature verification and duplicate-event handling if this provider is selected; does not establish that PICKLERS already uses PayMongo.
- [Microsoft — TCP/IP Port Exhaustion Troubleshooting](https://learn.microsoft.com/en-us/troubleshoot/windows-client/networking/tcp-ip-port-exhaustion-troubleshooting): supplies a diagnostic basis for the earlier Windows load failure. Connection exhaustion remains a hypothesis to remeasure in the intended deployment.
- [CourtReserve — Player FAQ](https://help.courtreserve.com/en/articles/4900932-player-faq): illustrates that booking availability and eligibility depend on club policy. This informs explicit policy communication; it is not evidence that PICKLERS needs every competitor feature.

## 4. Preparation limits

This is a source-informed planning artifact, not a comprehensive security audit or legal/compliance opinion. Current browser behavior, external payment/email delivery, camera hardware, and fresh capacity/test results were not verified in this preparation. No application code or database changes were made to produce it.
