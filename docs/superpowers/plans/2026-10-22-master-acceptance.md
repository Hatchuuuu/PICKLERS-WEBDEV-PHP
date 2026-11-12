# PICKLERS master acceptance execution plan

**Goal:** Verify the existing Symfony application and repair confirmed essential defects while preserving the entire pre-existing working tree and normal database.

**Architecture:** Retain Symfony 7.4 controllers, Security, Twig, injected DBAL repositories, existing static assets and PHP compatibility routes. Keep MariaDB 10.11 and the existing production/development/test Compose targets. No speculative feature additions or dependency upgrades.

**Specification:** User attachment, “PICKLERS WEB APP — MASTER EXECUTION PROMPT”, received October 22, 2026 (Asia/Manila).

## Execution and evidence

- [x] Inspect attachment, project instructions, Git state, Composer constraints, routes/verification tooling and previous reports; query Graphify first. Large pre-existing uncommitted migration, Docker and UI work is preserved.
- [x] Read applicable installed process/design/review skills. Apply the user's explicit ordinary-step authorization in place of skill approval gates.
- [x] Run fresh native quick verification: all gates passed, including 315 PHP files, 59 Twig templates and 48 routes. This does not establish database or browser acceptance.
- [x] Research official Symfony compatibility, Docker readiness and OWASP authorization guidance.
- [x] Reproduce/fix owner payout authorization; add staff/foreign staffed-owner denial tests and retain owner/admin behavior.
- [x] Reproduce/fix payout entitlement from uncollected or refundable bookings without altering gross revenue reporting; add real ledger/booking regressions.
- [x] Repair landing mobile navigation, keyboard FAQ and inaccurate payment/refund statements; regression-test interactions and render desktop/mobile in both themes.
- [x] Run complete disposable database suites serially per server, then review changes independently and resolve concrete findings, including the observed phone-race deadlock with a bounded standalone-write retry.
- [x] Start/diagnose Docker, validate production/dev/test Compose configuration, build, start, check health/migrations, exercise HTTP journeys and persistence without using normal XAMPP data.
- [x] Refresh Graphify after edits; write acceptance report, findings register, skill inventory, commands, results and precise limitations.

Final evidence: `docs/symfony-migration/master-acceptance-2026-10-22.md`. All 15 Docker gates passed: 287 tests / 2,307 assertions plus 406 service assertions. Exhaustive browser/hardware coverage, live external providers and public deployment remain outside measured acceptance; the recurring host Docker Desktop socket issue is documented.

## Initial confirmed findings

| Finding | Priority | Evidence / impact | Smallest correction | Risk / verification |
| --- | --- | --- | --- | --- |
| Staff may request venue payouts to a chosen destination | P1 | Owner portal includes staff; requestPayout uses portalFacility | Restrict payout to owned facility | Preserve operational staff actions; HTTP 403 and unchanged payout rows |
| Venue-paid and manual/unverified bookings fund platform withdrawals | P1 | getOwnerFinancials uses all confirmed/completed booking prices | Derive withdrawable entitlement from eligible collected credit payments separately from gross | Preserve revenue metrics and existing reserve; regression checks real debit/refund and booking lifecycle |
| Mobile landing navigation has no handler | P1 | <=768px hides nav-actions; mobileToggle has no listener | Toggle existing navigation with native button semantics | Keyboard/Escape/link behavior and 390px browser check |
| FAQ cannot be operated by keyboard | P2 | Click handler on a div, no native button/expanded state | Native button and synchronized expansion state | Keep existing layout and accordion behavior |
| Landing assistant invents instant external payments/refunds | P2 | Hard-coded GCash/Maya top-ups and unconditional refund statements | Match actual existing backend policy | Copy regression against landing content |
| Docker CLI/config present but Linux engine unavailable | P1 environment | Missing dockerDesktopLinuxEngine pipe; desktop status/start disagree | Diagnose safe startup and inspect actual health | No data reset, process termination or speculative success claim |

No code change is justified solely by a historical report. Runtime criteria require fresh execution evidence.
