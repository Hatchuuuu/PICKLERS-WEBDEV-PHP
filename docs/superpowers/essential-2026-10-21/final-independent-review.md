# Independent final local-demo review

What this change does: The session aligns configured venue hours and overnight occupancy across booking paths, makes booking pickers use server availability, exposes valid cancellation, preserves staff review permissions, and makes refund outcomes explicit. This review assumes a local classroom presentation with synthetic accounts and no external payment or recovery service.

Reviewed session increments against `var/essential-20261021/{flow-before,ui-before,admin-before,staff-before,payment-label-before,fixture-before}` and relevant new tests/scripts. The extensive preexisting migration working tree was not treated as this session's diff. Read repository AGENTS.md, graphify skill, master execution brief, and binding scope; ran a scoped graphify query before source review and used current code as authority.

## Initial findings, now resolved

1. **[P2] External cancellation still asserts payment occurred** (`public/assets/js/app.js:2241`, `src/Domain/BookingRules.php:389`).
   - What this is: The player cancellation explanation and cancellation notification describe an external payment method.
   - Problem: Select a GCash/Maya booking and open Cancel, or have the owner decline it. The dialog/notification says it “was paid via” the selected method despite having no provider confirmation; the notification also directs users to unverified Picklers support. These lines predate the session, but fall directly inside its selected truthful-payment outcome scope.
   - Fix: Say “Selected payment method” and direct external payment questions to the facility. Keep actual wallet refund wording tied to the shared rule's refund result.
   - If skipped: The presentation continues to claim collection and support that the local application cannot substantiate.

2. **[P2] Help modal advertises an operational support service** (`templates/web/partials/app/_modals.html.twig:690`).
   - What this is: Help & Customer Support presents an email, hotline, and daily availability schedule.
   - Problem: Open the support modal. It advertises `support@picklers.ph`, `+63 (02) 8888-PICK (7425)`, and service hours although the binding local scope explicitly says no monitored support/recovery channel exists. This is an adjacent existing claim in the same policy/modal surface.
   - Fix: Replace the operational contact claims with an explicit local-demo unavailable state.
   - If skipped: Users see contradictory recovery/support instructions within the same app.

The lead corrected both findings. Independent follow-up source review confirms that the player cancel preview, player cancel success notification, and staff cancellation notification now describe the selected external method and lack of app payment confirmation, with facility-only follow-up. The Help modal now explicitly states that no monitored support email or hotline is configured and directs presentation assistance to the presenter. The updated payment-policy regression passes. No application code was edited by this reviewer.

Final verdict: **Ship for the selected local classroom demo, scoped to the evidence below. No open P2 or higher finding remains in this review.** No additional critical local-demo blocker was found in the inspected increments.

## Checks and evidence

- Ran `node --test tests/Frontend/direct-book-availability.test.js scripts/audit/mixed.test.mjs`: 17 tests passed, zero failed. Read the actual deferred-response, modal dismissal, selection snapshot, midnight endpoint, configured-grid, wallet-corruption, and failure-cleanup cases.
- Ran `php scripts/audit/facility-hours.test.php`: 17 pure hours cases passed.
- Read `tests/Web/FacilityHoursTest.php`, updated decline API regressions, `PaymentLabelsTest.php`, and `BookingCancellationDisplayTest.php`; did not execute their database suites.
- Traced the new optional hours argument through court creation, Open Play joins, and publication before/inside the transaction. Court reservations retain their court-row lock before the adjacent-day occupancy read; interval offsets preserve touching endpoints and cross-midnight blocking. Hours reads inside transactions use facility shared locks. Existing bookings are not rewritten.
- Inspected direct/Quick Book generation guards, shared Escape/backdrop invalidation, court identity, current selection checks, and fail-closed checkout. API availability remains advisory; the mutation rechecks shared server rules.
- Traced both `Payments::methodLabel` callers and roster output. Twig/roster output retains escaping. Roster coloring uses the credits method, but its visible label does not claim external settlement.
- Reviewed mixed harness manifest isolation, same-origin loopback HTTP, disposable database restrictions, bounded clients/cycles, exact wallet readback, rejected duplicate cancellation, cross-user message readback, owner approval, Open Play cancellation, cleanup, invariant checks, and failure reporting. Its own report correctly limits this to synthetic local smoke and documents response-loss/reset limitations; it is not a contested-slot or sustained-capacity proof.
- Confirmed staff controls/refresh remain suppressed by the owner capability flag and backend owner authorization remains required. Admin `pending_review` is added alongside legacy `pending`.

## Follow-up verification after corrections

- Ran `node --test tests/Frontend/direct-book-availability.test.js tests/Frontend/quick-book-availability.test.js tests/Frontend/payment-policy-copy.test.js tests/Frontend/ux-dialog-keyboard.test.js scripts/audit/mixed.test.mjs`: **22 passed, zero failed**. This reruns the dynamic-grid, delayed-response, stale selection, modal dismissal, mixed-harness failure, corrected copy, and shared keyboard-dialog cases against the corrected current files.
- Inspected all eight confirm/Quick Book previous/next controls: each is now a native `button type="button"`, retains its original ID/class and shift handler, and therefore supports normal keyboard activation without submitting a form. The shared `.qb-time-slot-subtle` rule resets background/border, inherits the font family, and keeps a 32px minimum height. No custom keyboard implementation or changed booking rule was added. Actual focus/activation in a browser remains the lead's gate.
- Read the lead-generated `var/essential-20261021/mixed-run.log`: it records ten sessions, 200 measured requests, zero request errors, zero failed checks, and passing before/after SQL invariants. This reviewer did not execute that HTTP workload. Its short duration and serialized owner/Open Play steps remain local smoke evidence, not capacity/race certification.

## Final checkout increment review

Reviewed only the additional checkout delta against `var/essential-20261021/checkout-before` (`app.js`, `app.css`, `_tab-play.html.twig`). The reservation heading, external-method descriptions, submit labels and trust strip now correctly describe a local request requiring facility approval; they no longer promise instant QR, card collection, zero fees, or guaranteed secure checkout. Both dynamic button resets preserve the total element and request label.

The four native radios share one name, have distinct IDs/values and accessible names, and remain focusable despite their visual hiding. Focus is indicated on the surrounding card. Their change handler synchronizes the hidden submitted method, selected method variable, checked states and visible active card. A radio click also bubbles to its own card's handler, which selects the same method; this is idempotent and has no booking/network side effect. There is no prevent-default handler blocking native arrows or Space. Card clicks retain the existing method selection behavior. Actual browser keyboard verification remains the lead's responsibility.

The `Cash on Site` value remains unchanged and `PlayerActions::PAYMENT_METHOD_ALIASES` still maps it to `Pay at Venue`. No financial API semantics or pricing logic changed in this delta.

Ran `node --test tests/Frontend/payment-policy-copy.test.js tests/Frontend/voucher-async.test.js tests/Frontend/voucher-zero.test.js tests/Frontend/free-openplay.test.js`: **27 passed, zero failed**, including the new payment radio state/contract test and existing zero-price, quote-race and submission-lock regressions. No new actionable finding; final scoped approval above remains valid.

Not checked: no runtime/browser session, shared database suite, full verifier, actual mixed HTTP workload, or load certification was run by this reviewer. The lead's separate fresh evidence is required for those gates.
Risk: approval is limited to the inspected local-demo scope, passing pure/Node checks, and explicitly attributed lead workload evidence. It does not approve public deployment or real external payment/recovery services.
