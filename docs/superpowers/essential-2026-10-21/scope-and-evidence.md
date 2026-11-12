# Local presentation implementation — scope and evidence

## Binding launch decision

The project owner clarified during execution: this app is for local use, local testing and presentation to a professor. They have no email/SMS provider or monitored recovery channel. Accordingly, this execution targets a local demonstration, not a public service launch. Real charges, external delivery, public deployment and production capacity certification are outside this selected release.

Use existing explicit development/test credit simulation with synthetic accounts. Keep real top-ups disabled. Present unsupported recovery truthfully; do not add a recovery backdoor or claim support/email is operational. The public self-service and integrated-provider branches of the master brief remain conditional and unselected.

## Inventory and priority backlog

| Item | Current evidence | Decision / acceptance |
|---|---|---|
| Booking/Open Play locking, credits, refunds | Existing shared rules and extensive concurrency suites | Preserve; rerun focused and combined gates |
| Owner decline financial outcome | API and owner toast always claimed refund regardless of method | Repair API and UI together; credits/external/pay-at-venue/no-refund regressions |
| Cancellation terms | Player terms claimed six hours; server uses pending exception / 24-hour confirmed rule | Correct wording and preserve actor-specific policy |
| Top-up interface and privacy claims | Instant/gateway promises despite no provider | Describe local simulation truthfully and retain server production guard |
| Account recovery | mailto / promise of support recovery with no verified channel | Explicit local unavailable state; no external email action or unsafe reset |
| Configured venue hours | Availability grid and mutations ignored persisted hours | Enforce shared server rule, align usable picker, test boundaries and overnight cases |
| Staff pending request controls | Portal renders buttons but owner-only API correctly rejects staff | Preserve permissions; show read-only request state and owner-only explanation |
| Admin verification review | Writer uses pending_review but UI/filter allowlist only offer pending | Add current status while retaining legacy pending; real rendered-filter regression |
| Messages/feed payload | Active unbounded chat; fixture has few messages per pair | Investigate; bound existing consumers only if demonstrated local need; do not build social feed |
| Local operating evidence | Historical load report is not current | Run isolated mixed journey smoke at up to ten synthetic sessions; explicitly no public capacity claim |

## State-transition and recovery matrix

| Journey / state | Permitted transition | Persisted side effects / recovery |
|---|---|---|
| Court quote | Current server price; does not reserve a slot | Recheck availability and price when booking |
| New court booking | Available, in configured hours, authorized player → pending | One reservation; credits debit and ledger commit together |
| Open Play join | Available occurrence with capacity → pending | Reserve occurrence capacity; no oversell; credits debit atomically |
| Owner approval | Pending → confirmed | Existing shared confirm rule; staff do not inherit owner approval rights |
| Player cancellation | Eligible active booking → cancelled | Pending credits refundable; confirmed credits follow existing 24-hour rule; no second refund |
| Owner decline | Pending/allowed current status → cancelled | Actual credits refund once; external follow-up remains external; cash has no wallet refund |
| Staff portal | Assigned facility read/authorized court operations | Owner-only approval/settings hidden; foreign/revoked access remains denied |
| Verification request | Unverified → pending_review → admin decision | Findable admin filter; preserve existing audit and notification behavior |
| Password change | Authenticated current-password proof → new password | Existing session revocation; recovery unavailable without a verified channel |
| Simulated top-up | Explicit development/test opt-in only | Synthetic credits; production/unset guard remains closed |
| Changed venue hours | Applies to new reservations/publication/joins | Preserve existing paid holds; do not cancel history or strand approval silently |
| Network failure | Unknown mutation result → refresh authoritative state | Never claim external payment/refund occurred from a client redirect or toast |

## Evidence status

Baseline HEAD/status/diff are recorded under `var/essential-20261021/`. Per-slice backups and red/green logs live there. Reports in `docs/superpowers/reports/2026-10-21-essential-*-discovery.md` distinguish current source findings from earlier measurements.

This inventory is not completion evidence. Final tests, browser interactions, reviews and the requirement-by-requirement audit will be recorded separately after execution.

## Deliberate exclusions for this local release

No new payment vendor, SMTP/SMS service, automatic payouts, arbitrary public recovery method, production networking changes, Redis/queue, architecture rewrite, native app, AI features, loyalty, waitlist or new tournament formats. No automatic pending expiry, fee/cancellation policy changes or historical-data rewrite. Those require demonstrated need and their own policy/acceptance scope.

The proposed public-release 30-minute capacity exercise is not a local classroom acceptance requirement. A bounded mixed read/write smoke is appropriate here; its report must explicitly say it is not sustained-load certification. This scope follows the owner's local-only clarification, not the likelihood of passing a test.
