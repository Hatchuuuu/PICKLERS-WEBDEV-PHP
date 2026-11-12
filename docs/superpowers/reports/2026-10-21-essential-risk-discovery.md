# Essential risk/account/operations discovery — 2026-10-21

Read-only source discovery; application/database untouched. Baseline retains existing dirty work. Used graphify query before source tracing; graphify wiki index is absent. Reviewed the engineering prompt, AGENTS.md, graphify and Ponytail skills. No graph rebuild needed because this task changes documentation only.

## Confirmed defects worth a minimal implementation slice

| Priority | Evidence and concrete failure | Smallest complete change / acceptance |
|---|---|---|
| High, financial truthfulness | `src/Web/Api/OwnerActions.php:194` always returns “player refunded successfully” even when `refunded` is false; `public/assets/js/owner.js:417` independently promises a wallet refund. Declining GCash/Pay at Venue therefore reports money moved when it did not. Domain implementation `BookingRules.php:364–382` deliberately limits wallet credit. | Return truthful message conditional on actual refund result and method; JS displays server message. Preserve idempotent cancellation. Extend owner decline API/frontend tests: positive credits refund, external payment no credit/manual follow-up, Pay at Venue no charge, duplicate decline no second credit. Do not modify policy. |
| High, false integration claim | `templates/web/partials/app/_modals.html.twig:715` claims secure GCash/Maya/bank gateway processing and end-to-end tokenization. `src/Web/Api/PlayerActions.php:256–268` explicitly states no gateway and rejects production top-up. | Replace claim with actual supported/manual-payment behavior and no unsupported technical promises. Template assertion and rendered critical-flow review. |
| Medium, contradictory cancellation terms | `_modals.html.twig:733` promises 100% credits refund at six hours. `BookingRepository.php:475–487` and cancellation `:854` use pending OR >=24h, credits only. `app.js:2253` incorrectly describes an eligible pending reservation less than 24h away as >=24h away. | Correct Terms to existing server policy; pending refund description must avoid false time assertion or use status-aware copy. Check pending within24h, confirmed just below/at/above24h, external methods, zero price, owner decline. Use existing server eligibility; no new policy. |
| Medium, unavailable capability advertised | `_tab-wallet.html.twig:7` promises “instant top-ups”; `_head.html.twig:4` advertises topping up. Production API always returns503. | Truthful unavailable/manual-launch copy and visible availability in wallet UI; preserve production fail-closed guard and simulation restrictions. Test production and dev/test flags via existing `PaymentEnvironmentTest`. |
| Medium, active unbounded message reads | `CommunityRepository.php:142–144` fetches every bilateral message, order only created_at. `:180–223` selects20 partners then calls full-history query per partner and embeds all histories. `OwnerPortalView.php:73` calls this during owner view construction; owner Twig `partials/owner/_tab-messages.html.twig:48` displays history; player `app.js:2028` and owner `owner.js:863` consume messages API. Cost grows with lifetime message history, including owner page payload. | For immediate smallest reduction, remove full history from inactive conversation summaries and fetch active history only. A bounded history change must reach repository/API/player+owner UI/Twig with stable `(created_at,id)` cursor and “older” affordance. Tests: >page messages, tied timestamps, concurrent new arrival, foreign conversation isolation, read markers and scroll preservation. Do not silently truncate existing conversation access. |

## Recovery and verified-channel reality

`templates/web/auth.html.twig:644,725–728` only offers mailto support@picklers.ph and promises support help. `AuthController.php:90–95` accepts signin/signup only; `AuthService.php:75–108` changes passwords only after current password verification. `SchemaInstaller.php:123–132`, `UserRepository.php:63–77` store email/phone and a general verification_status, not independent evidence that email/phone belongs to this user. No email_verified/phone_verified channel evidence or mailer integration surfaced in source/config/composer searches. Identity moderation must not be treated as verified recovery-channel ownership.

Classification: missing self-service capability; an external operational blocker for public self-service, not justification for an improvised reset endpoint. A bounded pilot needs the human to confirm a monitored mailbox, named operator, identity-check procedure, escalation and turnaround. No message was sent and no support responsiveness was verified. Never claim the existing mailto is a working recovery system.

If public self-service selected, scope verified-channel enrollment/migration, delivery transport/config, random expiring hashed single-use tokens, non-enumerating responses and throttling, atomic token consume/password update, PasswordPolicy and session-revocation reuse. Existing `tests/Web/PasswordSessionRevocationTest.php:45–80` covers same-second and concurrent-reset session binding; extend with unknown user, malformed/expired/reused/concurrent token, channel change, delivery failure and no automatic sign-in. Credentials, monitored support and channel ownership are blockers; no production email/provider activity authorized here.

## Payment state and manual launch assumption

Bookings persist payment_method and booking status (`BookingRepository.php:714`), which represent method selection and approval, not proof GCash money arrived. No provider integration was demonstrated. `BookingRepository.php:880–885` already tells external-payment users to contact facility and Pay at Venue users nothing was charged. `BookingRules.php:381–382` similarly avoids external wallet refund claims. Retain those safeguards.

Smallest defensible current boundary: bounded manual-payment pilot, with clearly disclosed owner confirmation/payment follow-up and disabled real top-up. A public integrated release remains blocked on merchant/provider selection, sandbox credentials, webhook signature/amount/reference matching, durable event deduplication, atomic ledger effects and reconciliation. Do not add payment-status storage before a selected operational flow needs it; do not display booking approval as verified payment.

## Feed exposure

`CommunityRepository.php:25–45` fetches all visible posts plus all comments; `CommunityActions.php:26–28` serves it with no login requirement; API dispatch is `ApiController.php:160`. Source search found no active player feed_posts fetch in app.js, but audit flows use the exposed endpoint (`scripts/audit/flows.mjs:181`). Therefore backend unauthenticated payload amplification is real; a visible feed performance defect has not been reproduced. If this endpoint remains public, bounded posts and comments are justified independently of building a new social UI. Preserve moderation filters and contract compatibility; add deterministic cursor/bounds tests before changing output. Historical16.3MB report is not a fresh measurement.

## Verification and harness safety

Fresh checks run successfully during this discovery:

- `node --test scripts/audit/flows.test.mjs scripts/audit/load.test.mjs`:10 tests passed,0 failed; local fake HTTP only.
- `php scripts/audit/ledger-check.test.php`: tied-order, invalid-chain, missing-evidence and bounded-search checks passed.

Full application suites were not run by this worker (lead owns baseline execution).

`phpunit.xml.dist` forces APP_ENV=test and bootstraps `tests/Admin/bootstrap.php`, which forces picklers_admin_test and rebuilds it unless PICKLERS_SKIP_DB_REBUILD=1. `tests/run.php:20–37` forces picklers_test; --fresh drops only that DB. They inherit DB host/port/credentials through config/bootstrap. `scripts/verify-schema-bootstrap.php:25–37` directly reads .env for DB host/credentials; environment overrides alone do not redirect that probe. It drops/recreates picklers_test_boot at :85–106. Thus database names are protected, but the server still needs private confirmation as disposable local/dev infrastructure. Do not print credentials.

Suggested fresh baseline sequence after confirming .env host/port and test database ownership privately:

```powershell
node --test scripts/audit/flows.test.mjs scripts/audit/load.test.mjs
php scripts/audit/ledger-check.test.php
php scripts/verify.php --quick
php scripts/verify.php
```

Quick mode still warms caches and performs container/Twig/router/dependency checks; it skips explicit DB suites. Do not run PHPUnit suites concurrently because their shared test DB/cache are rebuilt. Retain command exit statuses and full logs. `scripts/rehearse-upgrade.php:25–44,101–110` reads source picklers_db by default and creates a SQL dump before importing into disposable rehearsal DB: exclude from incidental baseline and use a protected synthetic/copy source deliberately.

`load.mjs:78–91` requires loopback HTTP, declared isolated DB, confirmed root/services-disabled flags, paths contained in project var and fresh output. Flags are declarative; they do not prove the web server actually uses that DB. Verify isolated PHP process configuration/document root/session/private/state directories before launch. Load driver `:107–123` exercises closed-loop sync/bookings/wallet/matches reads, default300s per stage, stages through10000, stopping after >1% error or >5s p95. This threshold differs from proposed pilot1s read target. It does not certify mixed writes, booking races,30min stability or recovery; no hardware/resource instrumentation. A10,000 fixture is not10,000-concurrent readiness. Login is sequential and per-stage identities differ; report role distribution and generator/server bottlenecks.

No load stage was executed. Historical500-session success/1000 failure is historical only. First actual run should be a tiny isolated smoke stage with synthetic users, then agreed bounded stages/thresholds; obtain hardware/SQL/connection metrics before diagnosing Windows port exhaustion or changing global limits.

Skipped: application edits, live browser journeys, live DB/migrations, capacity runs, support verification and external integrations.
Risk: public self-service recovery and integrated payments remain unproven; false payment/refund copy should be corrected before even a manual-payment pilot.

## Follow-up: smallest complete owner-message pagination proposal (not implemented)

Current fixture is misleading for this issue: `scripts/audit/seed.php:49` creates100000 messages over10000 directed neighbor pairs, exactly10 messages per directed pair; each user has two partners and20 total bilateral messages. All share one timestamp. A100k global fixture therefore does not reproduce long conversation cost. Active owner messages are real (`owner.html.twig:26`, owner partial, API); initial server-rendered thread does not automatically poll until a sidebar selection (`owner.js:883–890`). No production history size measured.

Smallest low-risk first slice is summary bounding: `getConversationPartners()` needs only newest message content/time per partner, not every message for20 partners. Keep list shape and only include a bounded initial thread for conversations[0]; adapt `_tab-messages` to that initial page and older button. Do not merely limit getMessages globally because player/owner/audit callers presently expect full history.

For a complete owner-only incremental contract, retain unpaginated legacy response initially and add explicit `limit=50` and `before=<message-id>` to existing messages action. Limit must have server maximum; reject invalid cursor/limit, resolve cursor only within the authenticated bilateral conversation. Fetch limit+1 in descending `(created_at,id)` order, reverse returned page to chronological, add `has_more` and `next_before`, preserve existing messages and partner keys. Cursor by ID avoids accepting client timestamps; tie-breaking ID need only be stable, not chronological within a second. A strict total order prevents gaps/duplicates. Existing `idx_dm_thread(sender_id,receiver_id,created_at)` exists (`SchemaInstaller.php:523`); id is primary. Inspect EXPLAIN with representative skew before proposing migration; output bounding alone does not prove bounded query work.

Owner JS changes reach `renderChatThread/loadChatThread/selectConversation/sendChatMessage` (`owner.js:843–923`) and owner Twig initial state. Maintain message-id map/order, append newest polling results without dropping older loaded pages, prepend older page and restore scrollTop by scrollHeight delta, retain request-version + partner guard, prevent overlapping older loads. Initial first page and after-send should scroll newest; polling must not force-scroll users reading older history. Keep unread behavior intentional: current API marks entire bilateral conversation read before fetch (`CommunityActions.php:89`). Pagination need not change that established meaning silently. An owner-only opt-in leaves player behavior and audit/API tests compatible, but does not bound legacy player endpoint; classify that remaining risk explicitly. A universal bound requires simultaneous player UI changes (`app.js:2028–2045`, message stream Twig) and is a larger coordinated slice.

Focused regressions: extend `tests/Frontend/owner-chat-state.test.js` for older prepend with retained viewport, poll preserving older pages, same timestamp dedup, delayed older response after partner switch, send during older fetch, retry after failure. Extend `tests/Web/ApiTest.php:134` or dedicated MessagePaginationTest with151 messages on one pair, >50 sharing timestamp, mixed directions, another conversation and a second authenticated user. Assert50 newest, next50 then remainder, no gaps/duplicates, forged foreign cursor rejected, malformed parameters400, all existing message fields retained, unknown/empty partner behavior, existing read-marker semantics. Add a long-thread synthetic fixture explicitly; no need to reseed whole capacity dataset.

Safe environment preflight must only print pass/fail metadata (loopback host boolean, expected port boolean, test DB names), never env values/DSN/password. Inspect `.env` privately because schema-bootstrap probe bypasses process override. If not approved local infrastructure, do not run full verify; make the schema probe respect normal config precedence as a separate reviewed harness fix first. Check `PICKLERS_SKIP_DB_REBUILD` absent before fresh baseline. Existing test suites share DB/cache and should run serially.

## Local mixed-workload harness implementation — 2026-10-21

Scope updated by user: local professor presentation/testing only, no mail/SMS/provider. Added `scripts/audit/mixed.mjs` and `mixed.test.mjs`; no application/schema/JS changes. Existing `flows.mjs` manifest/API helpers and load Session are reused. CLI requires all flows manifest checks plus `localPresentationOnly: true`, `externalServicesDisabled: true`, integer `mixedClients`2–9 (default3) and `mixedCycles`1–3(default1). Includes one owner: maximum10 logged-in synthetic sessions. No provider/top-up endpoints are called.

Workload: each concurrent player has a distinct future court/date, reads authoritative quote, reserves using existing synthetic credits, checks debit, cancels, verifies credit once and repeat cancellation; sends a unique ring message and verifies receiver visibility/read state; marks notifications read and checks sync count. Then one serialized owner approval/cancel/refund journey and pending Open Play join/cancel/count/wallet check run. All mutations are read back. On first observed failure later cycles/cross-role stages stop, active workers finish cleanup, run fails. Messages, read markers and cancelled booking history remain only in disposable fixture.

CLI invokes existing SQL invariants before and after workload; post-run failure is recorded and causes nonzero exit. Reports contain overall elapsed time, individual request elapsed time/outcome, action-level and overall p50/p95/p99 and error counts/rate, journey assertions and SQL evidence. HTTP errors and invariant violations fail the run. Session identities are synthetic; no credential/manifest dump appears in report. These are local bounded functional workload measurements, not throughput or production capacity certification.

Lead prepares a new authoritative manifest/server/dataset (do not reuse old manifest) with normal `seed.php` fixture and all physical isolated paths under `var/essential-20261021/`. Ensure actual PHP process points at the declared test DB/state/private/session paths; declarations are not process attestation. Schema/bootstrap and production DB are not invoked by mixed harness. Keep PAYMENTS_SIMULATED disabled: preseeded synthetic balances suffice. Do not run another audit concurrently on same fixture. Open Play uses Everyday current local occurrence; run before its session end or prepare a valid future synthetic match with same ID. A failed Open Play remains a failure, not skipped coverage.

After isolated setup is independently checked:

```powershell
node scripts/audit/mixed.mjs var/essential-20261021/NEW-ISOLATED-MANIFEST.json http://127.0.0.1:DEDICATED-PORT
```

Use actual dedicated origin; no workload was launched by this worker. JSON is saved with exclusive-create mode under manifest auditRoot. Run against disposable synthetic dataset only; a lost create response can leave a pending reservation before its ID is known, so inspect failure and reset fixture before rerun rather than blindly retrying mutation. SQL invariant script checks wallet/ledger/relations; contested booking slot races remain covered by separate suites.

Self-test evidence under `var/essential-20261021/`: `mixed-red.log` records expected missing-module failure before implementation; `mixed-green.log` records4/4 passing new tests; `audit-selfchecks.log` records14/14 combined existing/new audit tests. New tests use an in-memory fake API, assert concurrent writes/approval/Open Play/notifications, enforce upper bounds and local-only opt-in, verify metric failure accounting, and deliberately corrupt wallet/refuse send to establish failure/cleanup behavior. `node --check scripts/audit/mixed.mjs` passed. No DB/network workload result is claimed.

## Independent UI slice review — 2026-10-21

Compared current app.js/auth/player Twig against `var/essential-20261021/ui-before`, not entire Git diff (which includes earlier dirty work). Did not review/approve my own mixed-workload harness. Focused fresh command: `node --test tests/Frontend/payment-policy-copy.test.js tests/Frontend/auth-recovery-view.test.js tests/Frontend/ux-dialog-keyboard.test.js`:5 passed,0 failed.

**P2: move focus when replacing the sign-in view.** `templates/web/auth.html.twig:877–884` only changes display. New keyboard-focusable Forgot button at:644 hides its own ancestor when activated; no heading/control in revealed `authViewForgot` is focused, and return at:728 does not restore the trigger. This makes the new state change unclear for screen-reader/keyboard use and browser focus can end up on body/hidden trigger. `auth-recovery-view.test.js:7` labels this accessible but asserts visibility only. Minimal repair: focus a tabindex=-1 recovery heading/container or Back button when opening, and restore Forgot button when returning; test focus targets as well as displays. No need to add modal semantics to this in-page view.

No other blocking findings in scoped UI edits. The pending cancellation explanation now avoids inventing24-hour lead time. Terms matches pending/full and confirmed24-hour existing server behavior without changing policy. Privacy/payment text removes false gateway claims. Top-up copy explicitly labels unavailable real payments and simulation scope; production guard stays intact. Auth recovery accurately describes local demo limits and sends no external communication. Shared dialog tests cover forward/back Tab, Escape, focus restoration and stacked scroll lock at runtime level; they do not establish browser screen-reader behavior or every modal's wiring. Current `_tab-bookings` equals the slice backup (no agent changes).

Scope limits: no live browser interaction by this reviewer; screenshot files exist but this review did not independently inspect/reproduce them. Existing global legal/compliance claims were not newly introduced or verified. No app edits made during review.
