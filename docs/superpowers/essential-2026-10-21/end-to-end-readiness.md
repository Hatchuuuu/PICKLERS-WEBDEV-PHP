# End-to-end local readiness audit

This audit follows the user's request to check complete flows, missing implementation, logical gaps, confirmation modals and UI/UX while preserving existing behavior. The binding deployment remains local testing and a professor presentation with synthetic accounts. Real payment processing, email/SMS delivery and public hosting are unavailable. A percentage is not proof of readiness: the acceptance evidence below defines what was checked.

## Confirmed problems and concrete repairs

| Problem reproduced or traced | Repair | Proof |
|---|---|---|
| Owner Accept immediately mutates a booking; Decline does not identify its target | Review player, reference, court, date/time, amount and selected method before approval; show the same context for decline and reset its reason | Actual browser #PKL-CC3021 request → owner review → approval → player confirmed view → cancellation history; owner-request-confirmation.test.js |
| A slow confirmation could be dismissed with a button implying the decision was cancelled | Disable controls and announce submission; shared dialog close refuses aria-busy=true; unlock on server error/success; guard duplicate player cancellation | ux-dialog-keyboard.test.js; booking-cancel-pending.test.js; actual browser decline shows pending controls disabled |
| Sign-in labels were visually present but not associated with inputs; positive tabindex skipped normal order | Associate labels, restore DOM keyboard order, name sign-up inputs and visible owner settings | auth-accessibility.test.js; actual named sign-in controls |
| Staff lookup choices were clickable divs; selecting a hidden choice lost focus | Native buttons preserve existing lookup/selection; return focus to email input; name staff inputs and dialog close | owner-staff-keyboard.test.js; staff-selection.test.js; actual Tab/Enter selects a candidate without granting access |
| GPS denied/unavailable overwrote the real address with Bunao and reported success | Preserve manual address on denial; use actual signed coordinates if address lookup fails; remove invented location success | owner-location.test.js denial, unavailable and offline cases |
| Sound setting only displayed a toast; no-provider SMS switch claimed settings were saved | Sound preference is stored on this browser and actually gates scanner chimes; SMS switch disabled and explained | owner-alert-preferences.test.js; settings rendering |
| Owner payout settings implied automatic disbursement | Explain that saved details/manual requests do not transfer money; retain the legacy submitted enum so existing callers still work | Final owner settings/modal source and targeted Twig validation |
| Raw demo reseed retained generated refund keys and related records while reusing fixed booking IDs | Transactionally remove fixture-owned generated dependencies, including stale moderation cases, before recreating fixtures; preserve unrelated records and audit history | FixtureReseedTest.php first failed on retained refund, then passed; independent cleanup review |
| Two initial fixture wallet balances lacked matching ledger entries; documented privileged fixture lacked its standalone grant | Explicit synthetic opening credits reconcile wallets; seed only the documented synthetic privileged account in the isolated database | FixtureReseedTest.php: zero fixture wallet mismatches and expected privilege; runtime JSON proof |
| Reset removed fixture promo uses but kept their contribution to retained promo counters | Subtract only removed redemption counts in the same transaction; preserve unrelated uses and balances | Expanded raw-reseed regression failed at2vs1, then passed with1test/14assertions; final independent review approved |
| Owner switches had zero-size native checkbox hit areas and no visible keyboard focus | Give the native input the44×24 slider hit area and a visible focus outline | Actual pointer off, reload retainedoff, Space backon; named controls and disabled SMS state verified |
| Approval button used white text on bright green (1.86:1 contrast) | Retain brand green and use dark text; normal contrast9.55:1, hover11.78:1 | Computed colors verified in browser; desktop/mobile final screenshots refreshed |
| Concurrent CLI changes added a bootstrap dependency missing from the rehearsal fixture; overlapping suites could drop each other's test database | Supply the real bootstrap in the isolated fixture; acquire a connection-owned MySQL lock before shared cache/database rebuild; print full failed-gate diagnostics | Focused12tests/76assertions passed; independent harness review found no actionable issue |

Existing booking endpoints, quote computation, 24-hour confirmed refund threshold, pending refund exception, court/Open Play locking, staff permissions and payment aliases were retained. No dependency, schema migration, live-data reset or repository-wide rollback was introduced.

## Journey coverage

| Journey / invariant | Evidence checked |
|---|---|
| Authentication, validation and session expiry/revocation | Full Account/Phone/PasswordSessionRevocation/Security suites; actual player/owner/admin sign-in and sign-out |
| Discovery → availability → quote → pending reservation | Direct/Quick Book regressions, configured-hours/adjacent-day tests and actual cash request receipt/navigation |
| Player → owner → player approval handoff | Browser #PKL-CC3021 traversed all roles, then cancelled with truthful no-charge outcome |
| Credits refund and duplicate prevention | API/admin suites, raw-reseed regression and actual fresh owner decline; e2e-runtime-refund-proof.json records balance1500, exactly1 refund, zero wallet mismatches, retained reason |
| Cancel/Decline/Accept confirmation and failures | Actual desktop/mobile dialogs, safe Escape/focus return, pending-write guards and server-error/duplicate-submission tests |
| Staff lookup, assignment identity and restricted operations | Actual keyboard lookup; StaffIdentity/StaffCourtPermissions tests and staff frontend regressions. No live access was granted through browser testing |
| Admin document review and confirmation | Actual missing-document detail and nested approval confirmation, cancelled without granting access; Applications/Users/Bookings/Impersonation/Security suites |
| Court maintenance/identity, occupied sessions and foreign venue access | CourtWriteConcurrency, BookingCourtIdentity, MaintenanceExit, SessionEnded and scope tests |
| Open Play publication → join → approve/cancel/capacity | Publication, occurrence, race and booking suites; earlier live HTTP journey evidence remains separately dated |
| Tournament scope, persistence, registration and locking | TournamentScope/Lock/MigrationRetry plus existing HTTP journey coverage |
| Community/messages/favorites/notifications | Full frontend and admin/web regressions; earlier HTTP journey report covers persisted mutations, not manual visual review of every social screen |
| Settings and local capability disclosures | GPS/sound tests, actual settings view, no-provider recovery/SMS/payment text |
| Fresh/stale schema, root/subdirectory routing and private files | Full verifier bootstrap plus DevelopmentRouter/E2eRouterSafety/DataPath/Upload/Document tests; local root runtime restarted without touching normal XAMPP |

The 13-group HTTP run and ten-session mixed rehearsal documented in verification-and-release.md occurred earlier in this session. They are not represented as a new sustained test after these UI/fixture repairs. The fresh complete project suite includes the relevant backend behavior and the new regression cases.

## Verification evidence

Evidence directory: `var/essential-20261021/` (private synthetic audit artifacts stay ignored).

**Final current result:** `e2e-final-current-gates.log` completed with exit0 and all15 gates passing: 315 PHP syntax checks; 275 PHPUnit tests / 2,243 assertions; 103 frontend tests; 387 legacy assertions; all59 Twig templates; dev/test/prod containers; 48 routes; Composer validation/platform/audit; production cache warmup; fresh/stale schema bootstrap. This result supersedes the earlier failed and historical runs below. The only scoped hash change during this run was the separately edited `scripts/docker-smoke.php`; its final version passed an additional PHP syntax check. Docker runtime acceptance was not run for this local XAMPP presentation.

- `e2e-release-verified.log`: all 15 combined gates passed; 311 PHP syntax checks, 268 PHPUnit tests / 2,213 assertions, legacy387 assertions, all59 Twig templates, three containers, routes, Composer validation/platform/audit and cache/schema gates.
- `e2e-frontend-final.log`: 103 tests passed, zero failures/skips, including the final small settings copy/label refinement.
- `e2e-fixture-green.log`: raw-reseed financial regression passed, 1test/10assertions.
- `e2e-runtime-refund-proof.json`: actual fresh browser decline persisted exactly one refund and selected reason, wallet1500, fixture ledger mismatch0, privileged fixture configured.
- The earlier combined pass is historical. A subsequent run failed five rehearsal cases after concurrent CLI edits introduced a shared bootstrap dependency. Its separate diagnostic run also encountered a database rebuild from an overlapping suite. Both causes were repaired in the test harness; the focused rerun passed12tests/76assertions and the final complete run above passed.
- Independent review found the promo-counter repair and test-harness fixes sound, with no remaining actionable finding in the reviewed increment. Final AST graph refresh succeeded; the known24 zero-node source warning remains a graph-tool coverage limitation.

## Presentation operation and known boundaries

Use local-presentation-runbook.md, run the demo before class, and reload after rebuilding the matching cache. Cold PHP/container startup on this Windows machine can be slow; allow the first page to finish before interacting. The running local server can stop when the desktop execution environment restarts; use the runbook to restart it rather than changing normal Apache/MySQL services.

Raw reseeding now resets fixture-owned generated data as well as fixed IDs. That intentionally removes synthetic reservations, staff assignments, posts, messages and moderation state associated with these fixture accounts/facilities. Preserve anything needed from a rehearsal before reseeding. It refuses normal database names. Do not use fixture accounts for personal data.

Remaining product boundaries are explicit: no real payment/payout transport, no email/SMS recovery, no operational support hotline, no public deployment certificate, and no physical camera/GPS/audio-device certification. The time picker selects within a civil day; API-level overnight collision protection exists, but a single cross-midnight range picker has not been added. Staff request lists refresh on page reload. There is no new automatic expiry, waitlist, loyalty system or social feed expansion.

No exposed walk-in creation control exists in the current owner navigation; unused walk-in modal/handler residue was traced and recorded, not marketed as a working feature or expanded during this audit. Missing applicant documents are visibly flagged for the administrator; this audit did not change the existing administrator approval policy.

Rollback only this increment and compare against subsequent edits first. Original scoped copies in `var/essential-20261021/e2e-confirmation-before/` cover owner.js, auth, owner dashboard/modals and the fixture seeder. They are not a full checkout snapshot. For other files, revert only the documented additions: player cancellation busy guard in app.js, busy-close guard in ux-core.js, owner toggle/autocomplete/approval-color rules in owner.css, and owner settings labels/disclosures/preferences wiring. Preserve earlier goal changes and migration work. Never restore an entire older file blindly. A code rollback does not restore discarded synthetic fixture history. No real funds were moved.

## Final browser observations

- Current root owner route and `PICKLERS%20WEBDEV%20PROJECT/` subdirectory owner/player routes render and retain their installation prefix through navigation/sign-in/sign-out.
- Mobile390px and desktop1280px confirmation views show the full target booking, financial limitation and explicit safe/submit actions. No document horizontal overflow observed in these targeted views. This is not a whole-site/device accessibility certification.
- Keep Pending dismisses without changing the request; Escape completes its exit animation and restores focus to Accept. During actual submitted decline, controls were disabled and the pending message was visible.
- Final sound preference was switched off by pointer, remained off after reload, then switched on with Space. The input measured44×24px. SMS remained disabled with an unavailable explanation.
- The player's wallet was reopened through the subdirectory installation and visibly showed ₱1,500 with one new ₱300 refund datedOct21.
- Saved current evidence: `e2e-accept-mobile-final.png`, `e2e-accept-subdirectory-desktop-final.png`, `e2e-owner-settings-mobile-final.png`, `e2e-refund-wallet-final.png`. Earlier before/after captures remain separate; the latest confirmation captures include the final contrast correction.
