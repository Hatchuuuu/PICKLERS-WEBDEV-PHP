# Picklers Phase 2 Audit and Repair Plan

> **For agentic workers:** After the owner selects task IDs, use subagent-driven-development or executing-plans for those tasks only. This document authorizes no implementation. Checkboxes track future work.

**Goal:** Repair the remaining evidenced gaps in existing workflows and establish local-use readiness without adding features or changing established business rules.

**Architecture:** Keep Symfony's current request lifecycle, Doctrine DBAL repositories, MySQL/MariaDB, Twig and existing JavaScript. Repair validation, transaction and UI state at their current boundaries; retain existing layouts, endpoints, pricing and refund policy.

**Tech stack:** PHP 8.2+, Symfony 7.4, Doctrine DBAL, MySQL/MariaDB, Twig, plain JavaScript, PHPUnit, Node's existing test runner, XAMPP/development router.

**Spec:** The owner's Phase 2 scanning request in this chat: inspect existing bugs, errors, missing parts and logical gaps; provide the plan first; do not code; the owner chooses subsequent work. The later “continue!” continues this audit, not implementation.

## Constraints and evidence

- This audit creates only this plan. No application source, tests, dependencies, configuration or database records are changed. No services are started or stopped.
- Preserve the substantial existing uncommitted migration/repair work. Before selected repairs, capture a fresh recoverable snapshot excluding secrets and compare it with the existing `var/repair-audit-20261011/before` recovery material. Do not reset the checkout.
- No new payment gateway, messaging product, staff role system, social login, new notification channels, redesign or speculative feature is proposed.
- Follow remains backed by Favorites, as already approved by the owner.
- Graphify queries and actual source/callers were inspected. Two SOL reviewers supplied independent source findings for owner and tournament components; the root checked the relevant code chains. This is not independent acceptance of the whole prior migration.
- **Executed:** `node --test tests/Frontend/*.test.js`: 18 passed, 0 failed. Five isolated source-extracted JavaScript probes reproduced Quick Book date mismatch, owner conversation response mismatch, stale voucher totals, direct-book availability cache leakage, and the wrong discovery refresh branch. They used dummy DOM/network responses, with no HTTP writes or live data.
- **Source-confirmed:** A concrete defect follows from the inspected control/data flow. Database failure and concurrency cases below have not been executed in this scan; their reproduction steps are future acceptance cases.
- **Historical only:** The previous repair report records 15 verification gates, 181 Symfony tests / 1,404 assertions and 254 business assertions passing, plus a synthetic legacy upgrade/rollback/restore rehearsal. Those full suites were not rerun in Phase 2 and do not cover the newly found cases.
- **Not certified:** Neither Symfony migration status nor passing existing tests proves 100% local readiness. The actual configured local database, full browser matrix and outstanding independent acceptance still need S2-12.
- P1 means authorization/data integrity risk to address before acceptance; P2 means an existing workflow or reliability defect. Line references describe the source as inspected on 2026-10-11.

## Task selection and order

| ID | Priority | Existing area | Proposed repair |
|---|---|---|---|
| S2-01 | P1 | Staff membership | Prevent duplicate grants and make revocation remove the intended membership |
| S2-02 | P1 | Hosting Open Play | Make replacement/publication atomic and recheck active participants under locks |
| S2-03 | P2 | Court booking UI | Bind availability to the selected court/date/time and reject unknown availability |
| S2-04 | P2 | Slot API | Validate the requested venue/court/date and reflect existing booking restrictions |
| S2-05 | P2 | Venue detail refresh | Refresh the visible view and ignore responses for obsolete venue selections |
| S2-06 | P2 | Voucher checkout | Keep asynchronous quotes tied to their booking and promo code |
| S2-07 | P2 | Owner messages | Keep responses, drafts and send completion tied to the intended recipient |
| S2-08 | P2 | Tournament edits | Apply roster, bracket-shape and entrant-capacity invariants to updates |
| S2-09 | P2 | Tournament persistence | Report lock contention accurately and permit a safe retry |
| S2-10 | P2 | Staff selection | Offer only accounts eligible for the existing staff grant flow |
| S2-11 | P2 | Owner applications | Enforce the existing one-pending-application rule across sessions |
| S2-12 | Acceptance gate | Local operation | Rehearse installation/data recovery and execute the complete acceptance matrix |

Recommended: S2-01/S2-02 first, S2-04 before finalizing S2-03, then the remaining workflow repairs and S2-12. The owner chooses IDs; this is not permission to execute them all.

Do not edit shared files concurrently: S2-01/S2-02 affect owner actions; S2-03/S2-05/S2-06 affect app.js; S2-07/S2-10 affect owner.js; S2-08/S2-09 affect TournamentService. Run disposable-database suites serially because they rebuild shared test databases. Each selected task ends with an independent review of its acceptance evidence.

## S2-01 — Duplicate staff grants leave access after one revocation

**Evidence / status:** Source-confirmed. `src/Web/Api/OwnerPortalActions.php:521` calls `insertStaff()` without checking membership. `src/Repositories/OwnerRepository.php:59` creates a new random row on every grant; `src/Persistence/SchemaInstaller.php:184` supplies nonunique facility/user indexes. Authorization at `OwnerRepository.php:35` accepts any matching row, while `deleteStaff()` at line 87 deletes only one row. The roster renders both rows (`templates/web/partials/owner/_tab-staff.html.twig:24`), so this is not a claim that the duplicate is hidden.

**Reproduction:** Grant the same registered player access twice, then use Revoke on one entry. The remaining grant still authorizes the account despite the person-oriented “Revoke staff access” flow.

**Smallest solution:** Treat facility + stable user ID as one membership. Make repeated/retried grants idempotent or return the existing conflict response. Revoke that intended membership consistently, including historical duplicates. Use serialization or a uniqueness constraint after inspecting existing duplicates; do not delete ambiguous legacy rows blindly or bind them by reused email. If a migration is needed, retain a recoverable duplicate inventory and choose a deterministic survivor without broadening privileges.

**Scope:** OwnerPortalActions, OwnerRepository; schema/migration only if needed; existing `tests/Web/StaffIdentityTest.php`. Preserve other-facility memberships and owner/admin authorization.

- [ ] Add a failing sequential duplicate-grant → revoke → portal-denied regression.
- [ ] Add two-session concurrent/retry coverage; verify one effective membership.
- [ ] Implement the selected membership invariant and safe handling of existing duplicates.
- [ ] Verify a different facility's grant survives, old-email reuse remains denied, and repeated revocation is safe.
- [ ] Independent reviewer inspects migration/recovery and authorization before acceptance.

## S2-02 — Open Play replacement can partially commit

**Evidence / status:** Source-confirmed failure boundary. `OwnerPortalActions.php:338` checks current participants, then lines 348–364 call cancellation, insertion and court occupation with no encompassing transaction. `OpenPlayRepository::cancelOpenPlaySessions()` commits at line 158 before returning; `MatchRepository::insertMatch()` writes at line 51. A later insertion/occupation failure reaches the generic failure response without restoring earlier changes.

**Reproduction:** With an existing replaceable session, inject a database failure on the replacement match insert in a disposable test database. The prior session has already been removed even though publication reports failure. A separate join between the initial participant check and cancellation is also a source-level race requiring a two-connection reproduction: cancellation can affect a participant who joined after the check.

**Smallest solution:** One transaction owns the locked court, replaceability check, existing-session removal, new session and court state. Recheck participants using current locked state. Coordinate lock order with `joinMatch()`/booking code. Keep cancellation/refund policy unchanged. Audit cancellation's post-commit notification/cache effects before nesting it; failures must not emit a success/cancellation notification for rolled-back work.

**Scope:** `src/Web/Api/OwnerPortalActions.php::hostOpenPlay`, `src/Repositories/OpenPlayRepository.php`, `MatchRepository.php`, relevant FacilityRepository helpers; existing `tests/Web/ApiTest.php` hosting coverage plus a new focused `tests/Web/OpenPlayPublicationTest.php` for failure/concurrency cases.

- [ ] Reproduce failure after cancellation and after insertion in an isolated fixture.
- [ ] Add a publish-versus-join race with two independent connections.
- [ ] Implement atomic publication and consistent lock order without introducing a second hosting service or new scheduling rules.
- [ ] Assert failed publication preserves the original session, bookings, wallet/ledger, court and notifications; successful publication creates exactly one intended session.
- [ ] Review both lock acquisition orders and retry/failure behavior independently.

## S2-03 — Booking availability belongs to the wrong selection, or is unknown

**Evidence / status:** Executed source-function reproductions plus source-confirmed adjacent gaps.

1. `public/assets/js/app.js:2842` captures Quick Book date/time, but controls remain mutable while its delayed fetch runs. `bookCourtFromQuickBookResults()` at line 2999 reads current controls instead of the checked snapshot. The probe checked **Oct 12**, displayed Oct 12 results, then opened checkout for **Oct 13** after a selection change.
2. `findNextFreeSlotForCourt()` at line 1434 ignores its facility/court arguments and reads a global map. `bookCourtDirectToPayment()` at line 1727 calls it before fetching the new court's availability. The probe showed Court A's blocked map returning no slots for Court B.
3. `fetchAndApplySlotAvailability()` at line 1361 clears the map on failure; `renderConfirmBookingTimes()` at line 1616 treats missing slots as non-conflicting. A network error can enable an unverified selection. Server collision validation remains in place; this finding does not establish a double charge or server-side double booking.
4. Quick Book's `QB_TIMES` at line 2630 begins at 6 AM, while `BookingRepository.php:476` supplies slots starting at 7 AM. A future-date minimum of index 0 can select a range that Quick Book's own server-label check can never satisfy. Confirm-book logic currently treats the missing label differently.

**Smallest solution:** Key/capture availability by facility, court, date and selected time range. Ignore obsolete requests after date/court changes, closing or reopening a modal. Keep progression disabled while availability is loading, failed or missing requested labels. Pick the next free slot only after loading the relevant court. Drive supported initial selections from the existing slot contract; do not silently invent new operating hours or remove a supported business rule to align constants.

**Scope:** app.js functions above, Quick Book/confirm callers and modal controls. Extend `tests/Frontend/quick-book-availability.test.js` and `booking-date.test.js`; add a narrowly scoped direct-book async test. Depend on S2-04's agreed slot response behavior.

- [ ] Turn the Oct 12/13 and Court A/B probes into regressions.
- [ ] Test failed, delayed, missing-label and reversed-order responses; verify checkout remains disabled until the current selection is confirmed.
- [ ] Align initial date/time selection with real returned slots; verify an empty day stays empty and future-day defaults produce a valid choice when one exists.
- [ ] Verify closing/reopening, two venues, two courts, date rollover and a changed selection during scanning.
- [ ] Browser-check the existing mobile/desktop controls and server rejection of a conflict created after the availability check.

## S2-04 — The slot API can advertise an invalid/unbookable target

**Evidence / status:** Source-confirmed. `src/Web/Service/DiscoverService.php:77` silently replaces an invalid date with today. `BookingRepository.php:494` resolves court name by ID without validating membership in the requested facility, then produces slots even for missing IDs. Lines 516–538 mark only today's elapsed hours as past, so an earlier calendar date can be returned as available. The read does not apply suspension/maintenance rules enforced by `src/Domain/BookingRules.php:30` on writes.

**Reproduction:** Request a nonexistent court, a court belonging to another facility, an invalid date, a past date, or an unbooked maintained court/suspended facility. The inspected branches can return success/available although booking that target is rejected elsewhere. This scan did not send these HTTP requests to a live database.

**Smallest solution:** Resolve the court within the requested venue; return the established 400/404 response for invalid input/identity. Preserve legitimate display-date formats and explicitly supported default-date behavior. Past days and existing suspension/maintenance restrictions must not be advertised as bookable. Reuse existing rules rather than copy policy into another class.

**Scope:** DiscoverService, DiscoverController and API compatibility dispatch, BookingRepository/BookingRules; existing `tests/Web/DiscoverTest.php` and `tests/SlotCollisionTest.php`. Do not change pricing or the final transaction guard.

- [ ] Add HTTP cases for foreign/missing courts and malformed dates.
- [ ] Add past-day, maintenance and suspension fixtures with no bookings.
- [ ] Correct the read contract and keep both canonical and compatibility endpoints consistent.
- [ ] Verify valid free/booked/ended-early slots, today's boundary and future dates; execute S2-03 tests against that contract.

## S2-05 — Open venue details do not receive the intended live refresh

**Evidence / status:** Executed branch reproduction. `silentRefreshDiscover()` at `app.js:625` returns through the facilities-grid branch whenever that DOM element exists. `templates/web/partials/app/_tab-play.html.twig:52,132` keeps both grid and detail containers in the page; `openFacilityDetail()` hides the grid's parent, not the grid element. The probe with a visible detail view fetched only `action=facilities`. Separately, the detail request at `app.js:1113` has no selection/request-generation guard, so an older response can replace a newly selected venue.

**Smallest solution:** Choose the currently visible view, keep detail/court cache and selected venue synchronized, and ignore a response after navigation changes its target. Reuse the existing PickSync mechanism and renderers. Preserve selected list filters when refreshing that view.

**Scope:** app.js `silentRefreshDiscover`, `openFacilityDetail`, `closeFacilityDetail`, `renderDetailCourtsList`; a new focused `tests/Frontend/discovery-refresh.test.js`.

- [ ] Test hidden grid + visible detail fetches only the selected detail endpoint.
- [ ] Test A→B navigation with A responding last, and closing details during an in-flight request.
- [ ] Implement current-view selection and stale-response checks.
- [ ] Verify owner court/status changes appear in the open player detail without a page reload; list search/filter and Follow state remain intact.

## S2-06 — A late voucher quote overwrites another checkout

**Evidence / status:** Executed source-function reproduction. `applyVoucherCode()` at `app.js:3230` captures request fields but applies its response to shared checkout fields at lines 3280–3297 without checking they still describe that booking. `openPaymentReview()` resets shared voucher state at line 3048. The probe started a quote for Court A, switched current checkout to Court B with base total 660, and the old response overwrote it with 300. Server-side repricing remains authoritative, so the proven problem is misleading checkout state, not a proven discounted server charge.

**Smallest solution:** Capture the checkout identity and voucher request generation; discard responses after changing/closing the checkout or applying a newer code. Prevent confirmation while the currently requested quote is unresolved, or explicitly cancel it while preserving the established UI flow. Keep code, discount and displayed total consistent on rejection; preserve the repaired zero-total behavior.

**Scope:** app.js `openPaymentReview`, `applyVoucherCode`, final-confirm controls and existing `tests/Frontend/voucher-zero.test.js`.

- [ ] Add delayed A→B quote and newer-code-wins regressions.
- [ ] Verify invalid second code, network failure, zero total and close/reopen cannot retain an unrelated quote.
- [ ] Implement only checkout-state guards, keeping PricingService authoritative.
- [ ] Browser-check that displayed code/total matches the submitted booking context and success receipt uses the server amount.

## S2-07 — Owner messaging can show another recipient's thread

**Evidence / status:** Executed source-function reproduction. `public/assets/js/owner.js:859` renders any successful message response without checking its requested partner. `selectConversation()` at line 868 updates the header immediately. Resolving B first and A last left header **Player B** with **Player A's message**, also classified using B's identity. `sendChatMessage()` at line 888 clears the shared input and reloads the current recipient when a prior send finishes, which can erase text typed for another conversation.

**Smallest solution:** Bind request/render/send completion to the captured recipient and current request generation; clear or replace the prior thread on selection with the existing loading/empty pattern. A successful send should clear only the submitted draft if it remains unchanged. Keep existing polling, API permissions and design.

**Scope:** owner.js chat functions, `templates/web/partials/owner/_tab-messages.html.twig` if a state hook is necessary; new `tests/Frontend/owner-chat-state.test.js`. Similar player-side functions exist in app.js but no active Twig callers were found in this scan; do not build or enable a player chat interface as part of this repair.

- [ ] Test reversed A/B response order, empty thread and request failure after switching.
- [ ] Test send to A, switch to B and type, then receive A's send success: B's draft survives.
- [ ] Add recipient/version guards without changing who the server sends messages to.
- [ ] Verify initial conversation, polling and repeated switching in the existing owner page.

## S2-08 — Tournament updates bypass established integrity rules

**Evidence / status:** Independently reviewed, source-confirmed; no database reproduction in this scan.

- `src/Services/TournamentService.php:530` accepts blank/identical/duplicate player names on team edits, bypassing `buildTeam()` at line 991 and cross-team validation at line 438. The API exposes these fields in `src/Web/Api/TournamentActions.php:113`.
- `TournamentService.php:314` changes category and resolves pairing mode before bracket-shape guards. A live Doubles/mix event can become Singles/fixed while retaining its two-person teams and matches.
- `TournamentService.php:338` checks a reduced maximum against `teams` only. Mix entrants are stored in `players_pool`; eight entrants and zero drawn teams can be reduced to two teams. `mixDraw()` at line 586 then draws only four players. Its unpaired loop starts at `pairCount * 2` (line 608), so with an even pool the omitted players are not even reported as unpaired; they remain in the pool but disappear from that draw's teams and unpaired list.

**Smallest solution:** Validate the complete proposed edit against the same required-player/duplicate rules used when adding entrants. Compare original and proposed bracket shape before applying changes. Apply the existing capacity floor to mix entrants as well as fixed teams. Preserve supported team renaming, existing bracket reset and draw behavior. Whether replacing actual participants during live play is allowed is a policy question; do not invent that permission or prohibition.

**Scope:** TournamentService, TournamentActions contract, `tests/TournamentIntegrityTest.php` and existing tournament HTTP tests.

- [ ] Add failing blank partner, identical partners, participant-on-another-team and valid rename/edit cases.
- [ ] Add live Doubles/mix→Singles rejection and post-reset supported transition cases.
- [ ] Add eight-pool-players/max-two rejection and valid max-four cases, preserving fixed-team checks.
- [ ] Reuse existing validators and ensure rejected edits persist nothing.
- [ ] Review saved roster, bracket, scores, player visibility and all invariant checks independently.

## S2-09 — Tournament lock contention becomes “not found”

**Evidence / status:** Independently reviewed, source-confirmed. `TournamentService.php:81` returns silently when `GET_LOCK(..., 10)` fails. `mutate()` at line 931 retains “Tournament not found”; the API converts it to 422 at `TournamentActions.php:199`. Create already verifies persistence, so this audit does not claim all write callers falsely report success.

**Smallest solution:** Surface an explicit retryable persistence/lock failure consistently through create/update/delete callers. Keep the existing storage and lock architecture. Never advise retrying an operation as if its target were absent.

**Scope:** TournamentService writeRows/create/delete/mutate; TournamentActions and legacy owner create response; focused existing tournament tests.

- [ ] Hold the named lock using a second disposable connection and call each write path.
- [ ] Assert an explicit retryable failure and unchanged rows; then release and verify retry succeeds once.
- [ ] Preserve real missing-record and authorization responses.

## S2-10 — Staff search offers accounts that granting rejects

**Evidence / status:** Source-confirmed. `src/Web/Api/OwnerActions.php:199` excludes only deleted accounts from search; `owner.js:2384` renders results for staff selection. `OwnerRepository.php:83` accepts only `role = 'player'`. A returned owner/admin/deactivated result can be selected and then rejected with a request to select a registered player.

**Smallest solution:** Apply the existing eligibility rule to the staff selection context. This search is also used by walk-in/tournament callers; inspect all callers and do not globally narrow legitimate searches. Continue validating the stable ID on the server and retain the prior stale-autocomplete fix.

**Scope:** OwnerActions search callers, owner.js staff picker, OwnerRepository eligibility; `tests/Web/StaffIdentityTest.php`, `tests/Frontend/staff-selection.test.js`, `player-search-race.test.js`.

- [ ] Fixture results include eligible player, owner, admin, deactivated and deleted accounts.
- [ ] Verify only eligible players are selectable for staff; other search contexts preserve their intended behavior.
- [ ] Recheck changed/deactivated accounts at submission and preserve field-edit/stale-response protections.

## S2-11 — Concurrent owner applications bypass the one-pending rule

**Evidence / status:** Source-confirmed race, not runtime reproduced. `src/Web/Controller/OwnerPagesController.php:144` checks for pending applications before saving documents and before the transaction at line 212. `OwnerRepository.php:92` always inserts; the base application schema at `SchemaInstaller.php:338` has no pending-membership constraint. Two independent login sessions can both pass the check. Native PHP session locking can serialize one session; it does not serialize two sessions for the same account.

**Smallest solution:** Serialize by the applicant's stable account row, recheck pending applications inside the insertion transaction, and reuse the current 409/redirect response. Preserve upload rollback: documents from a rejected duplicate attempt must be removed while documents for the accepted application remain private and intact. Do not prevent a legitimate later application after a resolved review.

**Scope:** OwnerPagesController submitApplication, OwnerRepository pending lookup; `tests/Web/OwnerPagesTest.php`, `UploadAtomicityTest.php` and a focused two-session regression.

- [ ] Submit two valid applications from separate sessions against a disposable database.
- [ ] Assert exactly one pending row, one coherent verification status and no abandoned new document pair.
- [ ] Verify ordinary sequential duplicate rejection, failed insert cleanup and later permitted reapplication.

## Policy gaps and risks requiring evidence before a behavior change

These are not instructions to introduce new permissions or features.

1. **Staff capabilities are inconsistent, but the intended permission matrix is not established.** `OwnerScope.php:30,68` admits assigned staff to the portal. `OwnerPortalActions::addCourt/addStaff/updateFacilitySettings` use portal facility scope, while court edit/toggle/delete and revoke use ownership checks (`OwnerPortalActions.php:143,192,238,563,624`; `FacilityRepository.php:515`). Do not simply grant staff every blocked action. Before choosing a repair, map current visible controls and actions for owner/admin/assigned staff/outsider; use project requirements or the owner's decision to establish which are intended. Then align enforcement and visible controls with that matrix.
2. **Operating hours express conflicting concepts.** Booking entry blocks a venue that is closed *now* (`app.js:1727,1777`), while the API uses fixed 7 AM–10 PM slots rather than a venue-specific bookable interval. `validateBookingSlot()` validates future time/duration, not venue hours. Decide from existing requirements whether opening hours restrict requested play times, access to advance booking, or both. Do not silently add overnight/24-hour scheduling or redefine booking policy. S2-03/S2-04 can repair proven selection, identity and existing restriction defects independently.
3. **Current-data rehearsal remains unverified.** The documented script supports a legacy-schema source. Do not run it against the real database merely to obtain a pass, or downgrade that database to match the fixture. S2-12 must choose expectations appropriate to a protected copy of the actual local state.

## S2-12 — Local-readiness acceptance and recovery gate

**Evidence / status:** Pending acceptance, not a new product feature. Historical tests used synthetic data. `docs/admin-migration/setup-and-rollback.md:75` explicitly limits the current upgrade rehearsal to a legacy source. `scripts/rehearse-upgrade.php:199` expects all facilities active and line 202 expects no admin privileges, which cannot establish preservation for an already-migrated source with existing statuses/privileges. `tests/FacilityOperatingHoursTest.php:20` assumes the clock is outside 1–2 AM; that assertion is time-dependent.

**Scope:** Existing verification/rehearsal tools, their tests and setup documentation; isolated database/state only until backup and rehearsal evidence exist. No dependency upgrade or real-data migration is implied by approving verification.

- [ ] Confirm PHP/extensions, Composer platform requirements, Node, database availability, writable cache/private uploads, installed migrations and configured URL base without exposing credentials.
- [ ] Snapshot the exact source state and back up the actual local database/private documents outside public directories; verify restore to a separate target before any difficult-to-reverse operation.
- [ ] Inspect whether the source is legacy or already migrated. Use the legacy rehearsal only for its supported source. For current data, compare a protected copy against its own baseline: IDs, balances, ledger totals, bookings/statuses, staff grants, facility statuses, privileges and tournament records must be preserved. Never force the source into legacy expectations.
- [ ] Remove clock assumptions from the operating-hours test using controlled time or deterministic relative ranges; test ordinary and midnight-crossing boundaries explicitly.
- [ ] Run `node --test tests/Frontend/*.test.js`, then `php scripts/verify.php` serially on the documented disposable databases. Require every applicable gate to pass and record exact totals. A missing service is a blocked check, not a pass.
- [ ] Complete a browser acceptance matrix at root and XAMPP subdirectory paths, desktop and mobile: sign-up/sign-in/logout/password/session revocation; Discover/Favorites; standard booking/Quick Book/vouchers; pending approval/decline/cancellation/refund/check-in/end early; Open Play join/capacity/rollover/cancel/host; wallet simulation; owner application/private documents/review; owner settings/courts/staff; existing community actions and owner messages; tournament roster/draw/scoring/reset; admin access, balances, moderation, exports and health.
- [ ] For each flow include wrong-role/foreign-facility denial, invalid input, a failed request, reload persistence and the relevant concurrency regression. Preserve the existing UI; compare available screenshots and observe interactions rather than claim pixel-perfect equivalence.
- [ ] Verify uploads/private files are protected on both server layouts and the actual development router. Confirm no separate legacy backend owns migrated routes and no duplicate business implementation was introduced by repairs.
- [ ] Independently review the combined selected changes, then run a second targeted audit of adjacent paths and previously missed edge cases. New confirmed defects re-enter this register; do not declare acceptance with unresolved P1/P2 findings.
- [ ] Record every criterion as passed, failed, blocked, not tested or not applicable. Document commands to install/start/use the tested local environment and restore the backup. Stop only audit-owned processes and preserve user services.

**Acceptance language:** Once these checks pass, report readiness for the explicitly tested local configuration and workflows, with remaining limitations listed. Do not promise the absence of every possible bug. Simulated payments, manual external-payment handling and lack of an integrated gateway must remain explicit; adding a gateway is outside this plan.

## Review and rollback rules for each selected task

- Reproduce the selected failure before editing; record a failing regression or concrete controlled reproduction.
- Inspect all callers and shared helpers. Make the smallest coherent repair; avoid architecture/dependency churn.
- Run the focused acceptance cases, then relevant combined checks; run database suites serially.
- Assign independent review using actual code and test evidence. A developer's success report alone is insufficient.
- Keep a task-specific diff and recovery boundary; do not use destructive Git cleanup. Any data migration requires a tested backup/restore path before execution.
- After code changes, run `graphify update .` per AGENTS.md. No graph update is needed for this read-only source scan.

## Exclusions and remaining limits

No application fixes are made in Phase 2. No new feature is proposed to fill dormant/marketing-only UI claims. Existing prior repairs are not listed as unfixed merely because their files remain uncommitted.

The scan does not establish exhaustive browser coverage, actual-local-data readiness, real payment settlement, every possible race or freedom from all bugs. Database failure/concurrency findings are source-confirmed until the selected task's isolated reproduction is executed. The owner should select task IDs before implementation begins.


## Implementation disposition —2026-10-12

The later authorization selected the repair work. S2-01–11 and the clarified S2-13 policy are implemented and independently reviewed; follow-up audits found and repaired S2-14–16. S2-12 has local runtime/recovery/automated evidence, but exhaustive manual browser coverage was not performed and the fresh advisory endpoint was unreachable. Historical scan checkboxes above are not silently reclassified as manual passes. Current evidence and remaining limits: `docs/symfony-migration/phase-2-repair-report.md`.
