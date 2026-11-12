# Whole-flow repair execution record

Status: **selected repairs verified; normal local startup accepted**, updated October 13, 2026. Existing features and rules remain the scope. This report does not certify zero possible bugs or 10,000 concurrent users. Two legacy staff memberships require venue-owner account selection, as confirmed by the project owner.

## Verified repair boundaries

| Boundary | Repair and evidence |
|---|---|
| Phone identity | Canonical phone uniqueness across signup/profile/admin writers; actual signup/profile collision workers. Additive migration rehearsed, then applied to normal data: 31 application tables preserved, except two separately backed-up blank optional phones becoming NULL. |
| Login throttling | Existing phone normalization shared by throttle keys; independent 73-assertion regression. Existing throttle store retained. |
| Session-end alerts | Flag and notification creation share a transaction, with locked state recheck; real duplicate/failure/stale-state workers. Browser receipt after a lost response is not guaranteed. |
| Owner applications | Current locked verification status preserved; approve/reject and submission acquire user/application locks consistently. Real overlapping workers reproduce the previous deadlock. |
| Court writes | Per-venue court-name serialization and row/attribute atomicity; independent test cleanup correction retained. Explicit match court IDs are authoritative in display; absent-ID legacy fallback remains. |
| Tournaments | Incompatible populated roster/category changes and invalid historical draw attempts rejected; supported category changes and existing bracket guards retained. |
| Open Play polling | One active poll, stale-generation rejection and bounded request timeout; frontend regression evidence. Poll interval and product interactions preserved. |
| Open Play booking counts | Additive `(match_id, status, booking_date)` index; unchanged count/date semantics and rows verified. Actual one-session endpoint time about 2,038 → 72 ms; ten-session p95 about 3,142 → 86 ms. Independent source review approved. |
| New player ledger evidence | Booking, Open Play and top-up write exact post-update `balance_after` in existing locked transactions. Failing-before/passing-after evidence; affected suites 33 tests / 237 assertions; independent source review approved. Historical NULL metadata is retained. |
| Wallet payment display | Existing same-user booking records supply known payment methods to linked ledger rows, including cancelled history; full hyphenated Open Play codes are retained. Unknown methods are not guessed. Actual Symfony rendering and legacy/generic tests passed: 37 tests / 343 assertions; independent source review approved. |

Evidence files for individual slices are under `var/whole-flow-20261012/`; the execution ledger is `.superpowers/sdd/2026-10-12-whole-flow-and-10000-user-audit/progress.md`.

## Combined verification and HTTP journeys

`var/whole-flow-20261012/final-combined-verification.log` passed all **15 gates** after the final ledger and wallet display repairs: **256 Symfony tests / 2,137 assertions**, **385 legacy assertions**, syntax checks for 306 PHP files and all 59 Twig templates, frontend regressions, container checks, route checks, schema bootstrap and production cache warmup. Composer online audit reported no vulnerability advisories. The audit harness separately passed 10 Node tests and the ledger-check self-tests.

The corrected HTTP journey runner passed **13/13 groups** on the isolated capacity database: guest/CSRF boundaries; Favorites; court CRUD and foreign ownership; cash and credits quote/book/approve/cancel; Open Play pending join/cancel; Singles draw/result/delete; community/messages; profile restoration; password-session revocation; notification reads; ordinary admin access and privileged-wallet denial. Reports retain the original incorrect confirmed-seat assertion and its corrected rerun. Pending joins reserve capacity; only approval increments the confirmed count.

These HTTP journeys supplement the database and frontend suites. They do not cover every browser gesture, camera hardware, privileged-admin action or upload workflow themselves; those have separate test anchors in the implementation plan.

## Dataset and measured limits

The create-only isolated dataset contains **10,000 users and 571,600 initial rows**, including 100 venues, 400 courts, 100 matches, 100,000 historical bookings and 200,000 ledger entries. Normal data and credentials were not used. Hardware: i5-13420H, 8 cores / 12 logical processors, approximately 16 GB RAM; Apache 2.4.58 / PHP 8.2.12 / MariaDB 10.4.32. The audit Apache uses 150 worker threads and loopback port 8089.

The matching 1/10-session read smoke completed 44 requests with zero failures after indexing. Subsequent one-minute stages passed at 50, 100, 250 and 500 independent sessions. The 500-session stage completed 7,500 requests, approximately 125 requests/s, with p95 end-to-end latency about 71 ms and zero failures.

The **1,000-session stage failed**: 749/15,000 requests (4.99%), p95 end-to-end about 12.56 s, 587 HTTP 500s, 161 timeouts and one transport failure. Escalation stopped; 2,500 through 10,000 were not run. Apache logs contain actual Doctrine connection exceptions with Windows socket-address allocation errors. Post-failure observation found 14,397 TIME_WAIT connections to port 3306 and 671 to port 8089; Windows reported a 16,384-port dynamic TCP range. MySQL's configured maximum is 151 connections and its observed peak was 150. Raw evidence is retained in `capacity/reports/`; no global network setting or persistent PDO option was changed.

High TIME_WAIT connection churn is consistent with Microsoft's documented port-exhaustion diagnosis. A separated production-shaped app/database/load environment and explicit connection-lifecycle validation are the appropriate next capacity investigation; moving only the generator does not eliminate the Windows app's database connections. Persistent PDO is not a safe blind workaround because reused connections can retain transaction and lock state. See [Microsoft troubleshooting](https://learn.microsoft.com/en-us/troubleshoot/windows-client/networking/tcp-ip-port-exhaustion-troubleshooting) and [PHP connection management](https://www.php.net/manual/en/pdo.connections.php).

The small 1/10-session recovery run passed after stopping load. These are short closed-loop read measurements, **not** mixed-workload, sustained-load or 10,000-concurrent-user certification. The audit writer's shared drain promise and nonzero exit status on failed gates are now regression-tested; the original failed run remains unchanged.

Feed profiling returns approximately **16.3 MB** for the 10,000-post history. Further source investigation found no active feed renderer, feed polling subscriber or comment-expansion UI in the shipped five-tab player interface. Thus this establishes a large API response, not a demonstrated current browser-feed failure. No records were truncated and no feed/pagination interaction was added. The endpoint's concurrent capacity remains unverified. Broader tournament lock contention and security/session capacity must be measured before changing those mechanisms.

## Data-checker corrections and further work

Original post-journey checks flagged two deleted court references and three ledger-step mismatches. Investigation established that the court rows were cancelled booking history intentionally retained after court deletion. Ledger IDs are random and timestamps have second resolution, so sorting tied entries by ID invents an order. The corrected checker validates feasible tied orders with strict bounds and reports incomplete/indeterminate evidence explicitly. Wallet totals matched every time; missing new debit balance metadata prompted the repair above.

Browser inspection found and prompted the wallet display repair above: a Pickle Credits Open Play entry displayed GCash, and its full booking code was shortened to `PKL-OP`. Amounts and payment/refund rules are unchanged. Independent source review and actual Symfony-rendered regression tests pass. A post-repair interactive wallet screenshot was not repeated; rendered regression evidence is the final check for that boundary.

Before normal startup or upgrade, all **410 original database files and their immutable copies**, and all **16 private documents and their copies**, matched the protected saved hashes with zero mismatches. Normal migrations then advanced from **6 to 8**. All **31 application tables** matched expected row hashes; the only intended value change was two backed-up optional blank phones becoming NULL. Guest HTTP checks left application rows unchanged. Evidence: `local-upgrade-result.json`, `local-before.json`, `local-after.json`, and `local-after-http.json` under the report evidence directory. Running database files legitimately differ from their cold pre-upgrade hashes.

Normal startup passed **14 development-router checks** and **13 real Apache XAMPP subdirectory checks**, including assets, guest authorization and private-path denial. The normal home page was inspected in a browser with no observed console errors; screenshot: `var/whole-flow-20261012/local-startup.jpg`. Temporary audit/database/Apache services were stopped. Normal MySQL and the development server remain running at **http://127.0.0.1:8000/**; restart instructions are in `docs/admin-migration/setup-and-rollback.md`.

The project owner confirmed that venue owners will connect Juana and kim to the correct registered accounts through existing Staff Management. Both original staff records remain unchanged; neither identity was guessed from an email. This is an owner onboarding action, not an unresolved permission-code repair.

Manual camera hardware and external payment/email delivery remain unverified. High-load escalation stopped at its recorded failure. F04 payload and F05/F07 contention findings remain measured limitations or conditional investigations, not authorization to add features or weaken security. The selected code repairs and supported local startup checks are complete; sustained mixed-workload and 10,000-concurrent-user acceptance are not complete.
