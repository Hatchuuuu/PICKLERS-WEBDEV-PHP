# Implementation ledger and slice contracts

Final status: selected local-demo repairs verified. This ledger consolidates execution decisions; it is not a claim that every slice had a separately approved advance design. Detailed hours plan: `../plans/2026-10-21-facility-hours-enforcement.md`. Final counts, limitations, images and release decision: `verification-and-release.md`.

## Slice plans and completed steps

- [x] Discovery: graph queries, current source, independent flow/UI/risk reports, baseline status/diff and prior evidence reviewed. Bind local-only scope before selecting provider-dependent work.
- [x] Decline: reproduce false refund message → reuse structured cancellation result in `src/Web/Api/OwnerActions.php` → carry optional bounded reason and actual financial fields → render server outcome in `public/assets/js/owner.js`/owner modal → regress credit/external/cash and duplicate transition behavior. No dependency on a new payment integration. Rollback uses flow-before copies.
- [x] Hours: apply existing parser at `src/Domain/Schedule.php` → call shared rule in BookingRules/BookingRepository/OpenPlayRepository/OwnerPortalActions under existing lock → include adjacent calendar days in occupancy → publish grid through existing availability endpoint → hydrate both pickers and reject stale responses → test opening/closing/midnight/overlap/legacy cases. No new endpoint/schema. Preserve existing paid approvals. Rollback uses flow-before and UI copies; compare incremental edits first.
- [x] Cancellation: expose existing `BookingRepository::hasBookingStarted` predicate to Twig → update `templates/web/partials/app/_tab-bookings.html.twig` → test pending/future-confirmed/started rendering → open actual refund preview and verify Escape focus restoration. Do not change the server's refund threshold. Rollback uses ui-before copies.
- [x] Recovery/local copy: use existing auth/modal system → explain unavailable recovery instead of a nonfunctional contact promise → correct player terms, wallet/help/checkout disclosures → share `src/Domain/Payments.php::methodLabel` across player/owner views → carry selected-method wording through cancellation notifications/admin responses → add native method radios with existing change handler and focus styles → test copy/selection/zero totals/stale quote/submit lock and actual keyboard arrows. No external delivery or collection. Rollback uses ui-before/payment-label-before/checkout-before.
- [x] Admin filter: add `pending_review` beside legacy `pending` in `src/Admin/Repository/AccountRepository.php` and admin users filter → submit/filter actual fixture record in `tests/Web/UsersTest.php`. No status migration. Rollback uses admin-before copies.
- [x] Staff queue: pass existing can-manage flag through owner scripts → hide review controls and show explanation in dashboard → early-return polling for read-only staff in owner.js → verify rendered staff view and frontend behavior. No capability changes. Rollback uses staff-before copies.
- [x] Rehearsal: extend existing audit Session/manifest primitives in `scripts/audit/mixed.mjs` → require local/external-disabled flags, bounded actors/cycles and surface failed checks → self-test → run synthetic booking/credit/refund/read/owner/Open Play operations → compare SQL integrity before/after → run existing broader HTTP journeys and final invariants. Keep credential manifest ignored. Remove only the new harness if reverting; never reset normal data.
- [x] Integration: fix out-of-hours assumptions in `tests/SlotCollisionTest.php`; run all project gates; resolve independent review findings; rebuild matching cache; collect final browser proof; update graph; record local release and limitations.

## Requirement rulings

| Requirement | Final status / evidence |
|---|---|
| Essential inventory and rejected ideas | Verified: scope-and-evidence.md and three discovery reports |
| Core transition matrix | Verified: scope-and-evidence.md |
| Concrete source/tests/rollback per slice | Verified: contracts above, detailed hours plan, scoped backups and final report |
| Current full verification | Verified: verified-final.log, all 15 gates passed |
| Independent final review | Verified for local scope: final-independent-review.md |
| UI comparison and critical keyboard behavior | Verified on selected player screens: final report and PNGs; before views explicitly reconstructed |
| Owner/staff/admin exception coverage | API/rendered tests and HTTP scope documented; exhaustive manual browser traversal of every role was not performed |
| Slow/stale requests, financial invariants and authorization | Existing focused regressions plus whole suites; mixed smoke is not a concurrency certificate |
| Local operations and recovery | Verified: runbook, disposable schema rehearsals, rollback procedure and matching cache rebuild |
| Secure public recovery | Conditional, unselected: no verified provider/channel; local unavailable disclosure implemented |
| Real payment integration | Conditional, unselected: no provider; existing fail-closed guard preserved |
| Public deployment/sustained load | Conditional, unselected: local classroom release only |
| More social/feed features or infrastructure | Deferred: no demonstrated essential local need |

No unresolved critical/high finding remains in the selected reviewed increments. This does not certify the whole pre-existing migration, every device, external hardware, or unselected production integrations. Source review and automated checks cover broader behavior than the selected manual browser screenshots. Keep those evidence types distinct.
