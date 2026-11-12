# Picklers existing-feature repair and local-readiness plan

> **For agentic workers:** When the owner selects a task, use subagent-driven-development or executing-plans for that task only. Implementation is NOT authorized by this document. Steps use checkboxes to track future work.

**Goal:** Repair evidenced defects in existing Picklers workflows, preserve the current interface and business rules, and demonstrate local installation and use through a defined acceptance checklist.

**Architecture:** Retain the current Symfony request lifecycle, Twig, existing JavaScript, Doctrine DBAL repositories and MySQL schema. Correct each defect at its existing ownership boundary; reuse current validation, transaction, authorization and response conventions.

**Tech stack:** PHP 8.2+, Symfony 7.4, Doctrine DBAL, MySQL/MariaDB, Twig, plain JavaScript, PHPUnit, XAMPP or the existing PHP development router.

**Spec:** The owner's 2026-10-11 instruction in this chat: audit bugs/errors/missing parts/logical gaps; provide solutions and a plan first; no code changes, no added features; owner selects subsequent work.

## Constraints and evidence

- This turn changes only this plan document. No application code, tests, configuration, dependencies or database records are changed. No services are started or stopped.
- The existing uncommitted migration work is preserved. Do not reset or replace it.
- Before the first approved repair, record Git status and preserve an exact recoverable snapshot of the current tracked and untracked work, excluding secrets from public or committed backups. Establish each task's rollback boundary before editing; do not postpone recovery preparation until R18.
- No redesign, new social login, payment-provider integration, messaging feature, notifications feature or speculative enhancement is authorized.
- Preserve supported root and XAMPP subdirectory URLs, current labels/layouts, existing authorization policy, pricing/refund rules and historical records.
- Three read-only reviewers examined player/backend, owner/account and operations/admin paths. Root checked the reported source chains and ran isolated in-memory JavaScript probes with dummy values.
- **Executed reproduction** means a source-extracted JavaScript function was executed with a controlled clock or dummy DOM. These checks do not establish full browser behavior or mutate the application.
- **Source-confirmed** means a concrete defective control/data flow was traced. Database failure/concurrency scenarios below are future regression cases, not claims of executed DB reproductions.
- The prior run passed all 13 verification gates and 154 PHPUnit tests / 1213 assertions. Those results are historical baseline evidence; the new cases here are not covered merely because that suite passed. No full suite was rerun in this plan-only audit.
- P1 = address before treating the application as ready for local demonstration with multiple users; P2 = existing workflow/reliability defect to repair before final acceptance.
- Framework migration completion does not establish full feature readiness. This audit found confirmed gaps; do not call the project 100% locally ready yet.

## Proposed order

Start with R01–R06 and R15 (security, scope and account/data integrity), then R07–R14 (booking/UI/onboarding consistency), then R16–R17 (truthful operational diagnostics). Execute only IDs selected by the owner. R18 is the final verification gate after the selected fixes and any remaining confirmed defects are resolved.

Tasks touching the same files must run sequentially: R01/R07/R09/R10/R13/R14 affect app.js; R02/R04/R12 affect owner actions/repositories; R05/R08 affect booking persistence. Shared disposable-database suites also run serially.

## R01 — P1: Safe venue names in interactive UI

**Evidence:** `public/assets/js/app.js:1109` inserts `escapeHtml(f.name)` inside a single-quoted inline JavaScript argument. HTML entities are decoded before a handler runs. The project already documents this distinction in `jsAttr()` at line 249. Gallery handlers at line 1131 use the same pattern; payment lookup at line 3020 embeds `fName` directly in a CSS selector. `OwnerPortalActions.php:415–425` accepts quote characters in valid facility names.

**Reproduction executed:** The source-generated handler for `O'Connor Courts` fails with a JavaScript SyntaxError. A dummy quoted name executed an inert `auditMarker` assignment in an isolated VM, demonstrating the unsafe script context. No payload was saved or sent to the application. Double-quoted selector failure is source-confirmed, not browser-reproduced.

**Proposed solution:** Use the existing `jsAttr()` for inline arguments or bind handlers without generated JavaScript. Use stable facility IDs/DOM data for payment lookup, or properly escape the selector. Do not forbid legitimate punctuation in venue names.

**Files:** `public/assets/js/app.js`; relevant dynamic venue/gallery callers. Add a focused frontend regression using the project's available JS test tooling after inspecting it; no dependency is required just to test source functions.

- [ ] Reproduce apostrophe and double-quote names through facility detail and checkout.
- [ ] Correct only affected output contexts and inspect sibling handlers.
- [ ] Verify normal names, quotes, ampersands and harmless injection-shaped names remain plain data; actions still work and no injected code executes.

## R02 — P1: Bind staff access to the account, not a reusable email

**Evidence:** `src/Repositories/OwnerRepository.php:64–80` inserts staff without `user_id`, although the column exists. Authorization at lines 44–46 matches current account email OR ID, and `getStaffByFacility()` uses the same broad OR. `src/Web/Service/AccountService.php:101–109` permits normal profile email changes. `OwnerScope::portalFacilities()` consumes these grants.

**Source-confirmed scenario:** Owner grants player A access using A's email. A changes that email. A loses email-based access; player B who subsequently uses the old address matches the grant. Even a bound row must not authorize a different user merely through its old email.

**Proposed solution:** Resolve the registered player during grant creation and store its stable user ID. Make a bound ID authoritative in both authorization and roster joins. Preserve the existing registered-player selection UI. Review existing unbound rows separately: do not blindly assign an old grant to whoever currently owns an email when identity history is uncertain; require existing owner re-grant/review for ambiguous rows. No new invitation system.

**Files:** `OwnerRepository.php`, `src/Web/Api/OwnerPortalActions.php::addStaff`, `public/assets/js/owner.js` staff selection, `OwnerScope.php`; existing owner/account test suites.

- [ ] Add regression for grant → email change → old email reused.
- [ ] Bind and authorize by ID, with an explicit recovery plan for legacy rows.
- [ ] Verify A retains access, B gets none, revoke still works and cross-facility access remains denied.

## R03 — P1: Fail closed on tournament list scope

**Evidence:** `src/Web/Api/TournamentActions.php:31–33` passes null to `grouped()` when `ownedFacility()` rejects an ID. `src/Services/TournamentService.php:131–145,170` interprets null as all facilities. This contradicts the owner-scoped API contract and the scoped get/write handlers.

**Source-confirmed scenario:** Owner A requests `list_tournaments` with B's facility ID or an unknown ID. Scope resolution fails but the list becomes unfiltered, including other facilities' tournament/participant records.

**Proposed solution:** Return the existing unauthorized/not-found response when no valid facility scope resolves; never translate denied scope into unrestricted scope. Preserve intended admin access explicitly.

**Files:** `TournamentActions.php`; `tests/Web/ApiTest.php` or focused tournament HTTP tests.

- [ ] Test another owner's ID, unknown ID and an owner without a facility.
- [ ] Reject these before listing data.
- [ ] Verify legitimate owner/admin requests still return only their intended scope.

## R04 — P1: Preserve the selected facility on owner requests

**Evidence:** `src/Web/Service/OwnerPortalView.php:41–47` displays a requested facility. `public/assets/js/owner.js:2752–2760` builds API/owner URLs without that ID, and settings FormData at lines 2059–2071 also omits it. `OwnerPortalActions::facility()` then selects the first allowed facility. Live queue requests likewise lose context.

**Source-confirmed scenario:** An owner/admin with facilities A and B opens B through `?facility_id=B` and saves settings. The request can update A. Other actions may either use A or fail authorization against an item from B.

**Proposed solution:** Expose the selected, server-validated facility ID in existing page data and carry it through relevant reads/writes and navigation. Keep backend ownership checks; never trust the client ID by itself.

**Files:** `templates/web/owner.html.twig` and its actual script/context partials, `owner.js`, owner actions/scope tests. Inspect template ownership before choosing the exact data attribute.

- [ ] Test one account managing two facilities and open B.
- [ ] Carry context through settings, courts, staff, payouts, tournaments and pending-queue refresh.
- [ ] Assert B changes, A remains identical, unauthorized C is denied, and root/subdirectory navigation retains B.

## R05 — P1: Reject bookings for nonexistent courts before charging

**Evidence:** `src/Web/Api/PlayerActions.php:138` accepts optional court ID; `PricingService.php:174` falls back to a facility rate for an unresolved name; `BookingRules.php:44` allows a missing court row; `BookingRepository.php:685` can insert that supplied name with null court ID.

**Source-confirmed scenario:** A valid facility with a published fallback rate receives `book_court` with no court ID and an invented court name, valid future slot and sufficient credits. The traced path permits a booking/debit without a real court.

**Proposed solution:** Resolve name-only legacy requests to an actual court belonging to that facility, then use its canonical ID/name for pricing, validation, locking and insertion. Reject missing or ambiguous resolution before financial writes. Keep working name-only clients compatible.

**Files:** `PricingService.php`, `PlayerActions.php`, `BookingRepository.php`, shared `BookingRules.php` callers; existing pricing/collision/HTTP tests.

- [ ] Add invented-name and cross-facility-ID cases; both must leave wallet, ledger and bookings unchanged.
- [ ] Canonicalize valid name-only requests.
- [ ] Verify ID-based and legacy-name requests collide on the same real court and retain current prices/refunds.

## R06 — P1: Do not delete an account after cancellation fails

**Evidence:** `src/Web/Service/AccountService.php:175–187` ignores each `cancelBooking()` result, then deactivates the user and destroys the session. `BookingRepository.php:790–793` refuses cancellation of a started confirmed session; DB failures also return `success=false`.

**Source-confirmed scenario:** A player deletes the account during an ongoing confirmed booking, or a future booking cancellation fails. The account still becomes inaccessible while the booking remains.

**Proposed solution:** Preflight existing cancellation restrictions; check every result and stop deletion on failure. Keep the account/session usable with an actionable existing-style error. Evaluate a transaction-safe all-or-nothing boundary across the existing cancellation/refund calls; do not blindly nest current transactions or change the cancellation policy. Handle concurrent new bookings during deletion as part of this boundary.

**Files:** `AccountService.php`, `BookingRepository.php`, `AuthService.php` only where needed for the shared transaction/account boundary; `tests/Web/AccountTest.php` and cancellation tests.

- [ ] Test active confirmed session, forced cancellation failure and multiple future bookings with failure on a later item.
- [ ] Ensure no deletion success is reported after a failed prerequisite, and retries cannot double-refund.
- [ ] Verify normal successful cancellation/deactivation and owner/admin self-delete restrictions remain intact.

## R07 — P2: Correct future-date booking time selection

**Evidence:** `public/assets/js/app.js:1385,1405` treats any date containing the current year as today. `PlayerViewExtension.php:41` emits dates containing the year. Both confirmation and quick-book controls call this helper.

**Reproduction executed:** At a fixed Manila clock of 2026-10-11 15:30, `getMinAllowedStartIdx('Mon Oct 12 2026')` offers 16:00 as the earliest UI option, instead of the first configured future-day option (06:00). Backend availability uses a separate 07:00–22:00 grid; the UI-grid mismatch needs verification against existing venue hours, not an invented new schedule.

**Proposed solution:** Compare complete canonical calendar dates in the application's Manila timezone. Apply elapsed-time restrictions only to today. Reconcile the displayed selectable slots with existing server availability/hours; do not change opening-hour business rules.

**Files:** `app.js` date helpers and both booking controls; `PlayerViewExtension.php` only if canonical data needs to accompany existing labels.

- [ ] Cover today, tomorrow, same-year future dates, year boundary and a browser timezone different from Manila.
- [ ] Verify valid future morning slots are selectable while elapsed same-day slots remain disabled.
- [ ] Verify no valid selection disappears due to UI/server grid differences; sold-out/end-of-day states do not fall back to expired slots.

## R08 — P2: Release slots after ending a session early

**Evidence:** `FacilityRepository.php:617` sets `ended_early=1` and reports the court free; its occupancy query excludes the flag. `BookingRepository.php:508,618,626` still includes such rows in availability/overlap checks.

**Source-confirmed scenario:** End 14:00–17:00 at 14:20, then try 15:00–16:00. Court display says free while reservation checks retain the conflict.

**Proposed solution:** Apply the existing ended-early meaning consistently in all reservation-blocking queries. Preserve original booking history, amount and refund policy.

**Files:** `BookingRepository.php`; inspect `FacilityRepository.php` and existing `EndCourtSessionEarlyTest.php`/`SlotCollisionTest.php` callers.

- [ ] Test both availability output and an actual replacement booking after early termination.
- [ ] Verify future remainder is bookable, history/payment are unchanged, and an ordinary active booking still blocks overlap.

## R09 — P2: Mark only the joined Open Play occurrence

**Evidence:** `DiscoverService.php:106,122` uses facility-wide membership without date in its fallback. `app.js:3313` marks every cached facility court joined after one successful join. Rendering at line 1294 disables Join.

**Source-confirmed scenario:** Join one of two sessions at the same venue; both appear joined. A previous occurrence may also mark a later recurring occurrence joined.

**Proposed solution:** Match the actual session occurrence by the existing stable match/date identifiers. For legacy records use the established court/date/time fallback, not facility alone. Update only the matching client entry.

**Files:** `DiscoverService.php`, `app.js`; existing Open Play and discover tests.

- [ ] Two courts/sessions at one venue: joining A leaves B joinable.
- [ ] Yesterday's booking does not mark today's occurrence joined.
- [ ] Refresh, cancellation and live sync agree with persisted membership and seat counts.

## R10 — P2: Preserve zero-price Open Play in checkout

**Evidence:** `PricingService.php::quoteMatchJoin()` explicitly supports free sessions. `app.js:1884` uses `parseFloat(price) || 200`; the checkout restore and `openPaymentReview()` also replace zero with fallback prices.

**Reproduction executed:** Calling the actual `joinOpenPlay()` with price 0 stores a checkout price of 200. Server pricing remains authoritative, but the displayed amount misrepresents the existing free-session flow.

**Proposed solution:** Distinguish missing/invalid values from a valid zero throughout this checkout path. Keep existing fee calculation and server authority; do not add payment integration.

**Files:** `app.js` join, checkout restore and payment review; compare with `PricingService.php` behavior.

- [ ] A zero-price session displays zero from discovery through checkout and receipt.
- [ ] A paid session retains the correct entry share/service fee and stored charge.

## R11 — P2: Align application validation with approval storage

**Evidence:** `OwnerPagesController.php:177–185` permits address 500 and operating hours 100 characters. `FacilityProvisioner.php:44,50` copies them into `facilities.location VARCHAR(200)` and `hours VARCHAR(50)` (`SchemaInstaller.php:148,154`).

**Source-confirmed scenario:** An accepted application with address length 201–500 or hours length 51–100 fails provisioning under strict SQL rather than completing approval.

**Proposed solution:** Validate against destination constraints at submission and before approval, return a useful field-specific error and preserve submitted data. Inspect existing over-limit applications on an authorized disposable copy before deciding whether a data-preserving schema adjustment is needed. Do not silently truncate records or expand the schema without that evidence.

**Files:** `OwnerPagesController.php`, `FacilityProvisioner.php`, `ApplicationReviewService.php`, existing application form and application/provisioning tests.

- [ ] Test destination maximum and maximum+1 for each field.
- [ ] Valid applications approve; invalid historical rows fail clearly without partial facility/court/user/audit writes.

## R12 — P2: Make owner submissions and uploads failure-safe

**Evidence:** `OwnerPagesController.php:193–221` stores two private documents, inserts the application and updates user status without one transaction/exception cleanup. `OwnerPortalActions.php:462–464` deletes a new logo only on a false return; `FacilityRepository.php:329` can throw instead.

**Source-confirmed scenarios:** Application insert failure leaves orphan documents; user-update failure leaves a partial submission. Facility update exception leaves an unused uploaded logo.

**Proposed solution:** Transactionally commit application row and status; compensate newly stored documents when persistence fails. Clean up only the new logo on failed update and remove the previous logo only after successful persistence. Do not delete unrelated or previously referenced files.

**Files:** `OwnerPagesController.php`, `OwnerPortalActions.php`, repository transaction boundaries; existing owner/application tests using temporary dummy uploads.

- [ ] Inject each write failure separately; assert no orphan new files or partial application/status changes.
- [ ] On failed logo save, old logo remains and new file is removed; successful replacement remains usable.
- [ ] Check cleanup failures are observable without masking the original error or exposing private paths.

## R13 — P2: Persist owner notifications and handle player failures honestly

**Evidence:** `public/assets/js/owner.js:1808–1860` dismisses/marks notifications solely in the DOM; no request is sent. Existing authenticated delete/read endpoints already exist. `app.js:894–980` sends requests but commits the visual change before success and never restores it on failure.

**Reproduction executed:** Source-extracted owner handlers removed a dummy row and reported read success with zero persistence requests. Source confirms reload fetches notifications again.

**Proposed solution:** Connect owner controls to the existing endpoints with the current CSRF token and correct installation base. In both clients, commit visual state after success or restore it on failure. Preserve swipe animation and unread badges.

**Files:** `owner.js`, `app.js`; existing notification templates/endpoints as reference, not a new notification system.

- [ ] Mark read and dismiss, then reload: stored result persists for the correct user.
- [ ] Reject CSRF, deny cross-user IDs and simulate network/server failure: no false removal/read state or success toast.

## R14 — P2: Keep community like counts consistent

**Evidence:** `CommunityRepository.php:105` reads membership, changes the relation and adjusts `like_count` in separate unlocked statements. Two unlikes can both see membership but both decrement although only one relation was removed. `app.js:3616–3640` also updates immediately and ignores HTTP failure responses.

**Source-confirmed scenario:** A post has two users' likes; one sends two concurrent toggle requests. An interleaving can leave count zero while the other user's relation still exists. Concurrent inserts may hit the unique constraint.

**Proposed solution:** Serialize the existing toggle on the post inside a transaction and keep count consistent with actual membership changes. Return the authoritative state/count; any active frontend caller must reflect that result and recover on failure. Preserve toggle semantics; do not add new community UI.

**Files:** `CommunityRepository.php`, `CommunityActions.php` response contract if needed, active `app.js` callers; community/HTTP regression tests.

- [ ] Disposable two-request test verifies no duplicate-key failure and count equals stored relations.
- [ ] Hidden/deleted post and unauthorized requests remain denied; rejected requests do not leave a false client count.

## R15 — P1: Serialize last-administrator protections

**Evidence:** `AdminPrivilegeCommand.php:116` checks the count before opening the revoke transaction. `UserAdminService.php:340–350` uses count guards while distinct target rows can be locked independently. The invariant is shared across all administrators.

**Source-confirmed race, not executed:** Concurrent removals of the final two privileged accounts can both observe two and proceed. This contradicts the explicit last-admin protection.

**Proposed solution:** Use a common transactional locking boundary for count/check/change across CLI revoke and web demotion/deactivation/privilege changes. Follow consistent lock ordering and re-read under the lock; preserve self-action restrictions and audit records.

**Files:** `AdminPrivilegeCommand.php`, `UserAdminService.php`, `Admin/Repository/AccountRepository.php`; existing admin/concurrency tests.

- [ ] Concurrent operations targeting the last two accounts leave at least one active privileged administrator and reject one operation.
- [ ] Repeat for ordinary last-admin protection where applicable; valid nonfinal removals remain permitted and audited.

## R16 — P2: Report migration readiness by identity

**Evidence:** `SystemHealthService.php:64–67` subtracts the count of executed rows from the count of migration files. Five current versions plus one obsolete executed version can hide a missing sixth current version and display Up to date.

**Proposed solution:** Use Doctrine's available/executed version identities, not count subtraction. Keep unavailable metadata visibly Unknown.

**Files:** `SystemHealthService.php`, existing service configuration only if metadata injection is needed; admin system diagnostics tests.

- [ ] Test current complete set, one missing current version plus one unknown executed version, and unavailable metadata.
- [ ] Only the genuinely complete set reports Up to date.

## R17 — P2: Make maintenance command results trustworthy

**Evidence:** `scripts/maintenance.php:143–160` catches ALTER failures and prints FAILED, but callers at lines 175–184 still exit successfully. Unknown commands use `exit(string)` at line 187, which also has exit status 0.

**Proposed solution:** Propagate failure counts to a nonzero process result and distinguish invalid commands. Preserve the existing maintenance operations and their data policy; this task does not authorize executing cleanup/reset operations against real data.

**Files:** `scripts/maintenance.php`; a focused command harness with dummy database behavior.

- [ ] Simulated constraint failure returns nonzero; successful/idempotent work returns zero; invalid command returns nonzero before mutation.
- [ ] Post-run verification cannot report success with required constraints still missing.

## Existing control requiring a product clarification, not an invented feature

**U01 — Facility Follow:** `app.js:983–1000` toggles only local appearance and toast, while line 1109 always initializes it to false. The executed dummy probe confirmed zero persistence requests. Favorites already persist via a separate control/API. There is insufficient evidence that Follow means Favorites or subscriptions.

Record this as an incomplete existing UI control. Before implementing anything, the owner must select the intended meaning: reuse the existing favorite behavior if that was the intent, or explicitly leave the unfinished control out of the local demo. Do not invent subscriptions, alerts, new tables or a new social feature. No change is proposed without that clarification.

Disabled Google/Facebook “Coming soon” controls and other clearly disabled future options are not authorization to build those features. Real payment-provider processing is also outside this repair scope.

## R18 — Local-readiness acceptance gate (after approved repairs)

The goal is complete evidence for the agreed local-use scope, not a promise that no undiscovered bug can exist. Currently **pending**, not passed by this audit.

- [ ] Establish an exact code snapshot and preserve existing uncommitted work; record the chosen local environment, PHP extensions, configured database and writable private/cache/session paths without publishing credentials.
- [ ] Rehearse clean installation using only a disposable database and the documented schema install/migration order. Start via `scripts/dev-router.php`; separately verify the actual XAMPP Apache layout if it is the intended everyday setup.
- [ ] Run all selected new regressions and `php scripts/verify.php`; record actual counts/output and distinguish known failures from untested cases.
- [ ] Rehearse upgrade/rollback on a disposable copy and prove source data unchanged. Any later actual local-data upgrade needs its own verified backup/recovery path; do not silently perform it as part of testing.
- [ ] Player: registration/sign-in/sign-out, profile/password changes, discovery/favorites, future court booking, collision rejection, owner approval/decline, cancellation/refund, wallet history, QR pass, notifications, account deletion restrictions.
- [ ] Open Play: two independent sessions at one venue, capacity, occurrence/date matching, free and paid checkout, cancellation and live sync.
- [ ] Owner: application → admin review → provisioned facility; two-facility scoping; court add/edit/status/early end; registered staff grant/revoke/email change; existing payout requests and settings/logo; tournament entrants/brackets/results; existing messages and notification actions.
- [ ] Admin: access restrictions, applications, booking actions, wallet adjustments, moderation, notifications, exports, impersonation/reset/revocation, last-admin guard and truthful system diagnostics.
- [ ] Existing community API/UI callers: create/read/like/comment/message flows and failed-request behavior. Verify reachability of current controls; do not create new screens for dormant handlers.
- [ ] Repeat critical paths at desktop/mobile sizes and both supported URL bases. Inspect console/network failures, quoted venue names, error states and persisted state after reload. Compare existing visuals; do not claim pixel equality without an actual comparison.
- [ ] For payment demonstration, use only the currently supported manual/development paths and clearly labeled simulation. No claim of real gateway settlement or completed external payouts without such an integration.
- [ ] Independent review evaluates each repaired case and a second targeted pass checks its neighboring callers. No confirmed blocker remains open; every untested item is disclosed before local-ready acceptance.

## Handoff

Choose task IDs to implement. Recommended first batch: **R01, R02, R03, R04, R05, R06 and R15**. Each approved task must be reproduced, minimally repaired, tested and independently reviewed before marking it complete. This plan itself grants no implementation approval.
