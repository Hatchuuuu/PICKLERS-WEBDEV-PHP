# Essential flow discovery — October 21, 2026

Read-only discovery against the current dirty working tree, followed by a separately authorized bounded owner-decline repair. Graph query executed first; inspected current controllers, services, repositories, templates, JavaScript and test callers. Historical findings are not assumed to remain defects. No database commands or browser journeys were run during discovery.

## Current journey and state matrix

| Journey | Current boundary and persisted behavior | Assessment |
|---|---|---|
| Court quote → reserve | PlayerActions::quoteBooking/bookCourt resolve court and server quote. BookingRepository::createBooking locks user and court, checks collision and writes pending reservation plus wallet debit/promo in transaction. | Implemented; hours gap below. |
| Open Play join | OpenPlayRepository::joinMatch locks user/court/match, validates target occurrence, counts pending+confirmed, charges wallet and inserts pending with match_id and booking_date. | Implemented; no missing join/capacity feature inferred. |
| Owner approval | OwnerActions::approveBooking → BookingRules::confirmRequest locks booking and validates allowed state/capacity, increments approved count and notifies once. | Implemented; staff-facing mismatch below. |
| Player cancellation | BookingRepository::cancelBooking checks ownership, preserves pending withdrawal and 24-hour confirmed refund policy, refunds credits once. | Implemented; external payment outcome correctly refers player to facility. |
| Staff/admin cancellation | BookingRules::cancelAsStaff returns refunded/refund_amount/external_payment, releases confirmed seat and notifies. | Implemented; owner adapter/UI discard this truthful outcome. |
| Owner onboarding | OwnerPagesController locks applicant and rechecks pending application; ApplicationReviewService approves/rejects transactionally, creates facility and notifies. Rejection permits resubmission; existing tests cover it. | No absent onboarding workflow found. |
| Staff linking/revocation | OwnerPortalActions links a real registered user; OwnerScope checks stable current membership. StaffIdentityTest and StaffCourtPermissionsTest cover revoked and reused-email cases. | Implemented; dashboard requests mismatch remains. |
| Identity review | AccountService::requestVerification sets pending_review; UserAdminService::setVerification updates, audits and notifies approvals. | Exists. Admin pending filter uses wrong vocabulary; an unverified decision emits no outcome notification. |
| Account deletion | AccountService::deleteOwnAccount locks user, cancels active future holdings, soft-deletes and destroys session; owner/admin refusals explicit. AccountTest and AccountConcurrencyTest cover rollback/race. | Implemented; no need to recreate deletion. |

## Confirmed findings

### P2 — Owner decline claims refunds that never occurred

Evidence: src/Web/Api/OwnerActions.php:183–194 consumes a boolean wrapper and always returns “player refunded successfully”; public/assets/js/owner.js:417 always displays “Player hold refunded to wallet”; templates/web/partials/owner/_modals.html.twig:129 promises refund before confirmation. BookingRules::cancelAsStaff correctly reports external_payment and never credits GCash. tests/Web/ApiTest.php:154–156 already asserts GCash refunded=false, but did not inspect wording.

Reproduction: decline a pending GCash or Pay at Venue booking. The owner sees a successful wallet refund claim despite no wallet credit. For GCash, real refund follow-up is obscured.

Minimal repair: consume cancelBookingAsStaff structured result at the owner API boundary, preserve existing fields, add actual refund fields and truthful message; use response message in owner.js; neutralize universal promise in modal. Cover wallet, external, Pay at Venue, zero-fee and rejected transition; no gateway change.

Status: bounded repair assigned to this discovery agent; evidence in var/essential-20261021. Modal belongs to UI workstream.

### P2 — Staff receive request controls that cannot succeed

Evidence: OwnerScope.php:29–35 admits active staff to portal; OwnerPortalView.php:72 supplies pendingRequests without staff restriction; _tab-dashboard.html.twig:272 renders accept and adjacent decline buttons unconditionally. OwnerActions.php:53–60, 142–152, 165–175 restrict refresh/approval/decline to owners/admins and actual ownership. verifyBookingOwner is ownership-only at BookingRepository.php:126–133. API dispatch does not promote staff flags.

Reproduction: link a player as active Front Desk staff, sign in, open assigned facility dashboard containing a pending request, then Accept or Decline. Request receives 403. Subsequent queue refresh also receives 403. Court operations remain available under separately scoped policy.

Minimal repair under current documented owner-only approval policy: hide owner-only request controls from staff using existing canManageFacility, provide an explanation that owner approval is required, and avoid owner-only polling for staff. Do not expand staff authorization incidentally. Extend StaffCourtPermissionsTest and frontend queue tests to assert the chosen presentation and retained API denial.

### P2 — Configured facility hours do not govern advertised/reserved slots

Evidence: BookingRepository::getSlotAvailability (506–563) iterates hard-coded AVAILABILITY_DAY_START_MIN/END_MIN (07:00–22:00), checks occupied/past/status only. createBooking (575 onward), validateBookingSlot (413–434), BookingRules::blockReason (30–50), PlayerActions::bookCourt and PricingService do not check selected slot against facility hours. isFacilityOpen is a current-time helper, not reservation validation.

Reproduction from source: set a listed venue's hours to 9 AM–5 PM, request tomorrow's availability, select 7 AM–8 AM or 8 PM–9 PM and reserve. Both advertised slots remain available and reservation validation permits them if otherwise free. A direct request may also reserve 6 AM–7 AM.

Minimal repair after confirming hours semantics: share slot-within-hours validation between availability and creation. Preserve overnight ranges and unknown/legacy hours intentionally. Recheck against current facility settings in reservation transaction. Tests should cover exactly opening/closing, overnight, malformed hours, stale UI and changed settings. This is a confirmed missing enforcement boundary; business treatment of malformed hours remains a product decision.

### P2 — Decline reason input is discarded

Evidence: _modals.html.twig:130 offers declineReason choices; confirmDecline in owner.js:386–405 never reads or transmits it; OwnerActions::declineBooking has no reason input. Domain cancelAsStaff already accepts reason and actor_id and includes reason in notification/status metadata.

Reproduction: choose “Court is scheduled for maintenance & cleaning”, confirm decline, inspect API payload and player notification. The selected reason is absent.

Minimal repair: pass validated selected reason and actor_id to existing domain opts; add API and frontend tests proving notification/metadata retains it. Preserve existing callers with no reason. Not part of bounded truthful-refund implementation unless lead selects it.

### P3 — Identity review denial has no promised notification

Evidence: AccountService.php:150 promises notification when approved; request notification says team will review shortly. UserAdminService.php:84–88 notifies only status=verified, and silently changes pending_review to unverified otherwise. Admin action exists and is audited, so workflow is not absent.

Reproduction: request verification as player, set unverified as admin. Player's pending state ends on refresh without outcome notification or reason. They can request again.

Minimal repair: notify only when an admin resolves pending_review to unverified, with actionable wording consistent with actual support policy. Preserve ordinary admin unverification semantics. Extend UsersTest and AccountTest for the cross-role round trip.

### P2 — Admin pending-verification filter misses actual player requests

Evidence: AccountService::requestVerification persists pending_review. templates/admin/panels/_users.html.twig:25 offers ['verified','pending','unverified']; AccountRepository::VERIFICATION_FILTERS uses that legacy list; PanelBuilder::query passes the allowlist to ListQuery, and AccountRepository compares verification_status exactly. Therefore selecting Pending filters for pending and excludes submitted pending_review users; directly requesting pending_review is rejected by the allowlist.

Reproduction: player submits verification, admin opens Users and selects Pending. Player is absent despite a successful submitted request. They remain visible only in unfiltered list/search.

Minimal repair: include actual pending_review (and intentionally retained pending legacy status) consistently in filter constant/template; focused admin filter regression must create a pending_review player and assert its presence. No new identity verification feature needed.

## Deferred and limits

No reservation expiry, gateway, self-service recovery, waitlist or new feature is proposed here. Existing external payment methods encode selection rather than evidence of settlement; Open Play roster labels “Paid via GCash” at OpenPlayRepository.php:305 also overstate evidence and deserve the shared payment-truthfulness slice. Owner.js:3081 treats this text as paid. It should display manual/unverified wording without inventing a payment processing system.

No fresh runtime or database verification was done for discovery; reproduction procedures above are source-backed scenarios awaiting disposable integration/browser execution. Baseline verification runs owned by lead must finish before shared test DB commands. No application files were edited during discovery; subsequent authorized decline change is separately logged.

## Authorized repair handoff

Owner decline now consumes cancelBookingAsStaff's structured outcome, returns existing refunded boolean plus refund_amount/external_payment, rejects failed transitions, validates optional reason to500characters and sends actor/reason through shared domain notification/metadata. Owner.js displays response outcome and submits selected reason. Modal discloses wallet-only automatic refund and external follow-up. UI agent independently reviewed this slice with no blocking findings and reran4/4frontend regressions.

Facility hours repair now validates whole-slot containment at shared new-booking boundary for court reservation, Open Play join and publication (including transactional recheck). Availability uses same rule; parseable configured hours expose24civil-day hourly slots, unknown legacy configuration retains15slot7AM–10PM defaults. Existing approvals are not refused after hours edits. Shared court booking windows include adjacent days, offset intervals across midnight, and parse legacy nullable interval columns; cancelled/declined/ended-early exclusions remain.

Fresh checks under lead-released exclusive disposable DB slot: targeted red8tests19assert8failures2expectedmissing-fieldwarnings; targeted green10tests84assert; complete focused ApiTest+FacilityHoursTest+DiscoverTest green28tests229assert. Logs: var/essential-20261021/flow-api-red.log, flow-api-green.log, flow-focused-suites.log. Pure hours selfcheck17cases and frontend decline4cases green; red/green logs retained. Pre-edit copies: var/essential-20261021/flow-before. No commit or schema changes.

Remaining acceptance: UI agent integrates API-derived picker and verifies screenshots/keyboard; one multi-hour selection spanning midnight remains a disclosed picker limitation. Lead coordinates independent hours review, final combined checks and graphify update. Staff controls and pending-verification filter repairs are owned by lead. External payment settlement/refunds and identity denial outcome notification remain separately documented boundaries, not implemented by this slice.
