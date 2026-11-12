# Phase 2 repair and local verification report

Completed 2026-10-12 (Asia/Manila). The approved code repairs and confirmed follow-up defects are implemented, tested and independently reviewed. No new product features, dependency upgrades, schema replacements or UI redesign were introduced. Existing dirty migration work remains uncommitted.

The tested local application runs with Symfony 7.4.20 / PHP 8.2.12 / MariaDB 10.4.32 / Node 24.16.0. Final acceptance is **14 of 15 verification gates passed**: the current online dependency advisory check was unavailable because Packagist connections timed out. This report does not claim exhaustive manual browser coverage or freedom from every possible bug.

## Repair register

| ID | Existing behavior repaired | Evidence / disposition |
|---|---|---|
| S2-01 | Duplicate concurrent staff grants; revoke historical duplicates by stable account identity | Controlled overlapping workers, HTTP/frontend regressions; independently approved |
| S2-02 | Partial Open Play publication and stale seat-count snapshots after court lock waits | Atomic rollback/publication/join/capacity regressions; review-required stale-read correction completed |
| S2-03 | Availability selection races, closed modal responses and exhausted Quick Book defaults | Direct/Quick Book async regressions and actual browser checks; independently approved |
| S2-04 | Invalid slot dates and mismatched court/venue targets | HTTP validation and shared booking rules verified |
| S2-05 | Venue refresh targeting the wrong visible view or obsolete request | Detail refresh regressions; later combined list-refresh gap repaired in S2-14 |
| S2-06 | Stale voucher totals and repeated submission during pending reservation | Quote identity + submission lock regressions; later cross-checkout completion gap repaired in S2-14 |
| S2-07 | Wrong recipient messages and lost replacement drafts | Reversed response and send/draft regressions; independently approved |
| S2-08 | Tournament team validation, live shape changes and mix capacity bypasses | Service/API integrity regressions; supported edits and metadata preserved |
| S2-09 | Tournament lock failures falsely described as missing records | Actual second-connection lock tests; precise retryable 503 through both HTTP boundaries; Singles normalization follow-up verified |
| S2-10 | Ineligible staff accounts consuming search results | Eligibility filtered before top ten, general searches preserved, stable ID revalidation retained |
| S2-11 | Two independent sessions filing duplicate pending applications | RED returned 200/200; GREEN 200/409, one private document pair, coherent user state, cleanup/reapplication tests; independently repeated 7 tests / 71 assertions |
| S2-12 | Clock-dependent tests and local acceptance/recovery evidence | Fixed-time hours tests and frontend clock harness; runtime, restore, browser and verification evidence below; live dependency audit remains unavailable |
| S2-13 | Inconsistent staff court rights versus owner-only staff/settings management | Assigned-venue court controls permitted, foreign access denied, owner/admin management retained; mixed-venue end target rejected |
| S2-14 | Older discovery list state or reservation completion overwriting a newer UI context | Ten regressions fail against saved before-source; independent 72/72 frontend pass, original probes resolved and extra failure/new-checkout probe passed |
| S2-15 | Same-venue mismatched court ID/name ending another session | Explicit ID authoritative; canonical-name fallback restricted to historical NULL/empty booking IDs; name-only callers retained; 39 tests / 292 assertions plus 15 service assertions |
| S2-16 | Rejected owner application leaving pending_owner_review on account | Both pending literals cleared; owner/verified/deactivated states preserved; 14 application tests / 92 assertions |

Task-specific snapshots, exact diffs, red/green evidence, independent reviews and the orchestration ledger are retained in `.superpowers/sdd/2026-10-11-phase-2-audit-plan/`. The combined UI/backend audits found S2-14–16; their subsequent targeted reviews found no unresolved confirmed P1/P2 defect in the reviewed scope.

## Combined verification

`C:/xampp/php/php.exe scripts/verify.php` completed on disposable databases. Evidence: `var/phase2-repairs-20261011/final-verification.log`.

- PASS: PHP syntax (262 files, including test/recovery helpers); Composer configuration and platform requirements; JavaScript syntax; dev/test/prod container lint; 59 Twig templates; 48 Symfony routes; production cache warmup; fresh/stale schema bootstrap.
- PASS: 72 frontend tests, 299 business assertions, 221 PHPUnit tests / 1,738 assertions. Database suites ran serially.
- UNAVAILABLE: fresh Composer advisory audit. Command failed with curl 28 connecting to `https://packagist.org/api/security-advisories/`; direct locked audit and documented IPv4 retry also timed out. Baseline audit on 2026-10-11 passed, but that is not presented as a fresh audit. Dependencies/lockfiles were not changed. Retry `composer audit --locked` when access is restored. IPv4 retry used only a process environment setting, following https://getcomposer.org/doc/03-cli.md#composer-ipresolve; no TLS or security checks were disabled.
- PASS: 30 HTTP protection/asset checks across Apache public root, real XAMPP subdirectory layout and the actual development router (wrapped only to select synthetic fixtures). Sensitive/private paths returned 403/404; public auth/CSS/JS responses were nonempty. Evidence: `http-protection-final.json`. An initial manual-check assertion misclassified public auth pages; the corrected full 30-check run passed.
- PASS: Graphify AST update completed, 2,381 nodes / 4,750 edges / 177 communities; recovery snapshot nodes excluded. No semantic API expense was incurred.

## Browser evidence and limits

Synthetic database `picklers_phase2_e2e` was used throughout. The normal XAMPP data directory was untouched. Root-layout desktop checks covered sign-in denial/success/logout, Discover/detail, Follow persistence through Favorites, booking date refresh, checkout identity/price, invalid voucher, Cash on Site reservation receipt/pass/pending booking and owner acceptance. Owner court edit and staff autocomplete were exercised. Mobile 390 px checks covered venue/Quick Book presentation, Escape dismissal and exhausted-day/future-day defaults.

XAMPP subdirectory routing, assets and a court edit POST passed. The real development router rendered the existing tournament console and completed a synthetic Singles tournament: two registered players, draw, 11–7 winner and Completed/champion state. The audit-only wrapper initially failed to propagate the router's false return for static assets; correcting the wrapper restored styled rendering, with production router unchanged.

Admin Control Center reflected the synthetic confirmed reservation. System&Audit showed runtime/database/migrations/timezone/private storage/cache/session healthy and explicitly reported no payment gateway, with development simulation only. Browser warning/error log was empty at that check. Screenshots are in `var/phase2-repairs-20261011/` (booking receipt, completed tournament, system health and mobile views).

Full manual browser permutations were **not** executed. In particular, registration/password changes, camera scanning, staff permission changes, document uploads/reviews, every payment variant, community/message interaction and admin moderation/export mutation were not all manually exercised on every layout/viewport. Automated service/HTTP/frontend suites provide separate evidence; they are not called manual browser passes. No pixel-perfect or all-interleavings claim is made.

## Recovery and local use

Exact pre-repair source snapshots were retained under `var/phase2-repairs-20261011/before/` and task snapshots, preserving the user's dirty branch. Actual local MySQL cold backup and private documents are outside the web root in the protected `picklers-phase2-recovery` directory documented in `docs/admin-migration/setup-and-rollback.md`.

A separate restored current-data MariaDB instance on loopback 3307 had all six migrations. Running migrate performed zero new migrations and preserved all 32 tables / 458 rows by sorted row hashes. 410 database files and 16 private documents were checksum-verified against the immutable backup. The final post-verification check again found zero source/backup mismatches (`var/phase2-repairs-20261011/final-preservation-check.json`). Do not use the legacy-source rehearsal's expectations to downgrade this current database.

For ordinary local use, start the normal XAMPP Apache/MySQL services and use `http://localhost/PICKLERS%20WEBDEV%20PROJECT/`. The installed vendor dependencies and existing environment remain in place. After code deployment with a compiled production container, run `php bin/console cache:clear --env=prod`. Check `php bin/console doctrine:migrations:status` and the admin System&Audit panel before applying any migrations. The alternative supported developer command is `php -S 127.0.0.1:8000 -t public scripts/dev-router.php` from the project root, with the configured database running. Fresh-install and recovery instructions remain in the setup document; do not overwrite a configured `.env` with the example.

## Engineering decisions and remaining risks

The complete ruling ledger remains in `progress.md`; these are the consequential decisions:

1. Continue the existing dirty branch with exact before-snapshots, rather than an isolated HEAD checkout that omits user work. No reset, clean, commit or push was performed.
2. Review task-specific before-diffs, rather than attributing the entire pre-existing migration diff to each repair. Python UTF-8 snapshot/diff orchestration replaced a Windows Git Bash ACL failure; application files were not replaced by recovery copies.
3. Parallelize only independent source scopes; serialize shared files and all shared test-database suites. Combined verification remains mandatory.
4. Apply the human clarification to assigned-venue court management and owner-only staff/settings management, retaining admin support. Do not broaden unrelated booking/financial/tournament permissions.
5. Preserve the existing ambiguous opening-hours/advance-booking policy. Fixed 7 AM–10 PM slot grid and closed-now entry behavior were not redefined.
6. Reuse Favorites for Follow as explicitly approved. No separate following feature was introduced.
7. Use the already-migrated copy's own row-hash baseline, not legacy-schema assumptions. Keep immutable backups/private documents outside the web root.
8. Exclude `.superpowers` scratch recovery sources through `.graphifyignore`; application source remains in the graph.
9. Treat mixed court identifiers as required target-integrity repairs, with explicit ID authoritative and historical name-only compatibility retained. Explicit IDs now determine the target; cross-venue mismatches are rejected and historical name-only callers remain supported.
10. Treat missed UI races and stale rejection status as repairs to existing flows. Freeze test clocks without bypassing production timing validators; no new product behavior was added.
11. Retain uncommitted recovery/review artifacts for rollback and evidence. Audit-only server processes were stopped; user services preserved.
12. Do not turn unreachable advisory data into a passing security gate or silently upgrade dependencies. The cost is a clearly recorded outstanding external check.

Unreproduced lock-order risks remain around application review/submission and other booking/join interleavings. Existing transactions roll back and ledger operations are idempotent, but transient contention failures are possible; exhaustive concurrency safety is not established. The global tournament lock retains its ten-second wait. Concurrent court rename/delete and historical rename mapping were not expanded. Existing online payment integration is absent: development top-ups are simulated and external payments retain the existing manual handling. No gateway was invented.
