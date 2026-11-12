# Local presentation completion report

The selected local testing/classroom scope is implemented and verified on October 21, 2026. This report supersedes earlier pending statuses and interrupted verification attempts. The reusable engineering prompt remains in `../plans/2026-10-21-essential-improvements-engineering-prompt.md`; the user's local-only/no-provider clarification governs this release.

## Implemented slices and acceptance

| Slice | Authoritative implementation | Acceptance and evidence |
|---|---|---|
| Owner decline | OwnerActions, BookingRules, owner.js and decline modal | Actual refund result, amount, external follow-up and reason survive API/UI; API and frontend regressions pass |
| Venue hours | Schedule, BookingRules, BookingRepository, OpenPlayRepository and OwnerPortalActions | New bookings/joins/publications obey configured hours under shared locking; previous/next-day overlap protected; 17 standalone boundary checks and database regressions pass |
| Booking picker | app.js, player modals and app.css | API-derived slots, numeric midnight endpoint, stale-response guards, closed-venue future dates and native time buttons; direct/Quick Book frontend tests pass; browser verified 6 AM accepted, 5 AM disabled |
| Cancellation | BookingRepository and player bookings template | Future confirmed Cancel available, started booking hidden; shared eligibility and refund policies retained; rendered regression and real preview verified |
| Honest local interface | Auth, wallet, help, terms and checkout templates; Payments::methodLabel and consumers | No operational recovery/gateway/support promises; method selection is not settlement; credits simulation explicitly identified; native keyboard method radios match submission state |
| Admin verification | AccountRepository and admin users view | pending_review is filterable; legacy pending retained; rendered filter regression passes |
| Staff queue | Owner dashboard/scripts and owner.js | Read-only explanation and no owner-only polling/actions for staff; existing authorization preserved; rendered and frontend regressions pass |
| Local operating rehearsal | scripts/audit/mixed.mjs and self-tests | Explicit disposable manifest, at most ten sessions, failures surfaced, SQL invariants before/after; no production capacity claim |

No dependency or schema migration was added. Existing collision fixtures were moved inside their configured venue hours, preserving collision/date-alias/notification assertions. No historical bookings were cancelled to enforce new hours. No business refund threshold or staff authority was broadened.

## Fresh verification

Evidence lives under `var/essential-20261021/` in this workspace. It includes synthetic fixture output; do not publish the private audit manifest or session files.

| Gate | Current result | Evidence |
|---|---|---|
| Whole project verifier | All 15 gates passed, exit 0 | verified-final.log |
| PHP suite | 267 tests, 2,203 assertions passed | verified-final.log |
| Legacy suite | 387 assertions passed | verified-final.log |
| Frontend suite | 91 tests passed, zero failures/skips | frontend-final.log; also rerun inside whole verifier |
| Syntax/config/dependencies | 310 PHP files, JS, all 59 Twig templates, three containers, routes, Composer validation/platform/audit and production warmup passed | verified-final.log |
| Schema rehearsal | Fresh and stale-stamp disposable scenarios passed | verified-final.log |
| Audit harness checks | 14 Node checks, ledger self-check and 17 pure hours cases passed | audit-selfchecks-final.log, ledger-selfcheck-final.log, hours-selfcheck-final.log |
| HTTP journeys | 13 groups passed | http-journeys-final.log |
| Mixed local workload | 10 synthetic sessions, 200 requests, 12 check groups, zero errors/failures; about 6.3 seconds, p95 about 276 ms | mixed-run.log and capacity/mixed-audit-mixed-1792542455941.json |
| Persistent integrity | Before/after mixed checks and post-HTTP ledger/relational checks passed | mixed-run.log; capacity/invariants-after-http-final.json |
| Independent review | Initial findings fixed; final scoped approval, no open actionable P2+ findings | final-independent-review.md |
| Knowledge graph | Final AST update succeeded; expected zero-node JSON warning, no topology change | graphify-frozen.log |

Earlier failed/interrupted logs remain for diagnosis. They are not the final result: an interrupted run was incomplete; the legacy fixture exposed an out-of-hours assumption; an admin wording assertion required lowercase `no in-app refund`. Each was corrected and the full verifier rerun successfully. No test was removed to make those failures disappear.

## Browser acceptance and visual comparison

Used the isolated `picklers_e2e` server on loopback 8098 and synthetic player. Verified recovery heading/back focus, cancellation preview and delayed focus restoration after Escape, configured early-hour validity, and final checkout keyboard arrows through GCash → Maya → Credits → Cash. Native Cash radio selected at the end. Final checkout had no document horizontal overflow at 1280 and 390 px; both sizes were captured. This is targeted browser acceptance, not exhaustive accessibility/device certification.

The demo initially served an old compiled Twig checkout despite current JavaScript. Rebuilt dev cache with APP_DEBUG=0 matching the running demo, then reloaded and verified the current heading, disclosures and native radios. Runbook now specifies that matching environment before cache clear.

The before images below are **reconstructed from saved pre-edit view/assets with current synthetic data/backend**, through a temporary GET-only loopback helper. They are not historical captures of an entire earlier application. Current source was never restored over the working tree.

| View | Reconstructed before | Verified current after |
|---|---|---|
| Wallet, 1280 px | var/essential-20261021/reconstructed-before-wallet.png | var/essential-20261021/ui-wallet-desktop-final.png |
| Top-up, 1280 px | var/essential-20261021/reconstructed-before-topup.png | var/essential-20261021/ui-topup-desktop-final.png |
| Booking actions, 1280 px | var/essential-20261021/reconstructed-before-bookings.png | var/essential-20261021/ui-bookings-desktop-final.png |
| Current checkout | No reconstructed comparison asserted | ui-checkout-keyboard-desktop-final.png; ui-checkout-mobile-final.png |

![Final local checkout at 390px](C:/xampp/htdocs/PICKLERS%20WEBDEV%20PROJECT/var/essential-20261021/ui-checkout-mobile-final.png)

## Release decision and limits

Ready for the selected local professor presentation using the accompanying local-presentation-runbook.md and disposable accounts. Use a fresh browser reload after rebuilding caches. Walk through the relative-date fixture sequence before the presentation. Existing normal XAMPP services/database were not reset; all test writes used named disposable databases. Extensive prior migration edits remain preserved and uncommitted.

The ten-session run is a short rehearsal against 10,000 synthetic registered users and initially 571,600 seeded rows. Registered users are not simultaneous users. Owner/Open Play steps are partly serialized; this is not a sustained-load, race or public-hosting certificate.

The picker selects ranges within one civil day. API-level overnight hours and adjacent-day collision protection are covered, but a single cross-midnight range picker was not added. Missing/malformed legacy hours retain the existing fallback. Staff requests are read-only snapshots refreshed on reload. No demonstrated local message-volume need justified pagination or a new feed.

Real collection/refunds outside credits, email/SMS recovery, public deployment and public capacity remain unselected. No provider, fake support channel, reset backdoor or new production infrastructure was introduced. The user should present these limitations explicitly.

Rollback: compare each changed file against the scoped `*-before` copies and baseline patch before reverting only this execution's edits; backups are incremental. Never reset the whole dirty repository. No schema rollback is needed. Stop only the temporary audit/before-view servers; retain the loopback demo while presenting. The runbook contains startup, fixture credentials and shutdown instructions.
