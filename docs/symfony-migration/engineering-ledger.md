# Symfony completion engineering ledger — 2026-10-10

## Mission and recovery

Complete the existing migration while preserving UI, routes, JSON contracts, business rules and data. No new product features or dependency upgrades. Work starts at `b680b25` plus 15 pre-existing modified application files and untracked `AGENTS.md`. Those edits tighten repository/service dependencies and partially migrate owner views; they are retained. Working branch: `migration/symfony-completion-20261010`.

The pre-existing tracked diff is saved in ignored `var/migration-audit-20261010/preexisting.patch`; status is recorded beside it. Recovery means reversing only this session's changes, never resetting the user's baseline. No live database migration or destructive operation is authorized without a safe recovery path. Verification uses the existing disposable test databases. No credentials are copied into documentation.

## Verified discovery / inventory

| Section | Current implementation and dependencies | Acceptance / remaining work |
|---|---|---|
| HTTP lifecycle | `public/index.php`, `Kernel`, attribute controllers, Symfony routing/DI | Keep existing `.php` aliases and subdirectory installation |
| Security/account | Symfony firewalls, session authenticators, CSRF, impersonation; `AuthService`, user and notification persistence | Preserve idle expiry, password-change invalidation, scope checks and error contracts; remove Core persistence consumers |
| Discover/player | Twig pages, facility/match/booking/wallet repositories, API handlers | Preserve quotes, bookings, collisions, refunds, wallet and sync; one persistence implementation |
| Community | Feed, likes, comments, direct messages and moderation | Preserve visibility and ownership checks; repository-backed read/write paths |
| Owner | Applications, court settings, open play, staff, payouts, owner dashboard | Finish partial DI; nested-route JS/form/image URLs must work |
| Tournaments | DB-backed `TournamentService`, bracket engine, migration `20261009000001` | Preserve entrants, brackets, match reporting and existing throttling |
| Admin | Symfony controllers, services, DBAL, audit/privilege/money domain | Preserve access, audit, concurrency and transactions |
| Schema/operations | Duplicate Core and `SchemaInstaller` implementations; legacy-based test/bootstrap/maintenance scripts | One installer, explicit console ownership, fresh/stale/populated-copy checks |
| Assets/rendering | Existing CSS/JS/media and Twig | No redesign; smoke root and XAMPP-style routes; no pixel-equivalence claim without comparison |

`graphify-out/graph.json` and wiki index were absent. Source inspection is the discovery fallback; run `graphify update .` after changes.

## Baseline evidence

- PHP CLI: 8.2.12. Composer targets PHP >=8.2 and Symfony 7.4; vendor is present.
- Initial verification: PHP syntax (173 files), Composer strict validation, platform requirements and dependency audit passed. Remaining gates running; do not infer application success.
- Static failures before our edits: incompatible PricingService construction in Core/OpenPlay and tests; removed OwnerPortalView import/property still referenced; nested owner URLs resolve to unregistered `/app/api.php` and `/app/owner.php`.

## Ordered tasks and file ownership

| ID | Objective / owned components | Dependency | Measurable acceptance and independent review |
|---|---|---|---|
| MIG-01 | Finish strict DI callers: OwnerPortalView, OpenPlayRepository, Core pricing call, affected constructor tests | Baseline inspection | Pricing, owner capacity/expiry and constructors tested; reviewer checks all callers and unchanged pricing semantics |
| MIG-02 | Correct nested owner URLs: owner.js, owner application Twig, payment image paths, URL regression test | Baseline inspection; independent of MIG-01 | Root and subdirectory URLs resolve from nested pages; no layout or endpoint-contract changes |
| MIG-03 | Replace remaining production Core consumers in Web actions/controllers/services/security/Twig with existing repositories and domain helpers | MIG-01 wiring contracts | No Core calls in production Web; security, booking, community, owner HTTP suites pass |
| MIG-04 | Consolidate schema/bootstrap and migrate operations/tests; remove duplicate Core persistence and kernel bridge | MIG-03 | One persistence/schema implementation; fresh/stale schema, all tests and migration-copy rehearsal pass |
| MIG-05 | Independently audit combined result, browser smoke, update setup and actual ownership docs | MIG-01–04 | Full gates pass, concrete findings resolved, limitations recorded; repeat targeted audit for missed issues |

Every implementation must inspect original methods/callers, retain validation and transactional error handling, add meaningful tests for changed nontrivial behavior, report modified files and checks, and receive review by another agent. Shared database suites run serially under root coordination. No overlapping file edits. Existing dependency versions and assets stay in scope only when a demonstrated defect requires changes.

## Defect register

| ID | Evidence | Status |
|---|---|---|
| D01 | PricingService requires FacilityRepository + PromoRepository; old construction in OpenPlay/Core and tests | MIG-01 implementing |
| D02 | OwnerPortalView retains `Database::` and `$this->db` after removing import/property | MIG-01 implementing |
| D03 | Relative fetch/form/image URLs fail on supported nested owner routes | MIG-02 implementing |
| D04 | Duplicate Core persistence remains active alongside DBAL repositories | MIG-03/04 pending |
| R01 | ApiController throttle comment contradicts RATE_LIMITED list | Preserve security behavior; resolve stale comment, not policy change |

## Verification states

Implemented, tested/passed, tested/failed, untested, blocked and not applicable are separate states. Agent completion reports alone are not verification. Live-data upgrade and pixel-perfect visual comparison remain unverified until explicitly evidenced.

## Continuation — 2026-10-11 (Asia/Manila)

The entries above preserve the initial investigation; their pending labels are historical. This continuation began on the same branch at `b680b25` with the existing migration diff retained. Tracked changes at continuation start are saved in ignored `var/migration-audit-20261011/preexisting.patch`, with Git status beside it; existing untracked files are retained in place. Recovery must reverse only continuation changes, never reset the entire working tree. No dependency versions, live data, or product features have been changed.

### Actual migration ownership

MIG-01–04 are present in the working tree: production Web services depend on injected repositories; Core persistence classes and the kernel bridge are removed; explicit schema installation and operations/tests use the shared Doctrine connection. Symfony owns request lifecycle, attribute routing, DI, security, Twig and errors. DBAL and existing repositories own SQL persistence; an ORM rewrite is unnecessary for these SQL business rules. Existing JavaScript/CSS/media and PHP URL aliases remain. Independent source/caller review found no remaining production Core consumers or missing Web injected-service methods.

### Audit-and-fix cycle

| ID | Confirmed defect / acceptance | State |
|---|---|---|
| MIG-06 | Required ALTER errors falsely succeeded; unknown production environments received demo seeds. Required DDL must fail installation without stamping success; only explicit dev/development/local/test may seed. | Implemented, independent review passed; mock regressions and fresh/stale/idempotent install checks passed |
| MIG-07 | Partial audit migration retry skipped append-only triggers. Fresh and retained-table retries must create each missing protection. | Implemented, independent review passed; five trigger-state regressions passed |
| MIG-08 | Failed rehearsal left personal-data dumps; tournament JSON never rehearsed. Fail fast, clean target/dump/state even on exceptions; verify ordered nested tournament records through import/rollback. | Implemented; failure regressions and independently repeated real copy rehearsal passed; original attributes retained and intended additions verified |
| MIG-09 | Partial tournament import silently completed on retry; corrupt rollback rows could be discarded; dry-run rollback wrote JSON. Preserve existing rows, import missing records, abort corrupt export, keep dry-run filesystem untouched through configured Doctrine events. | Implemented, independent review passed; eight focused regressions and real dev/prod configured dry-run checks passed |
| MIG-10 | Same-second password reset and active impersonation kept stale credentials. Bind sessions to hash actually verified/written, revoke reset actor, preserve valid changing session; fail closed for legacy equal timestamps. | Implemented, independent review passed; five focused regressions and integrated suites passed |
| MIG-11 | PHP GPCS omitted inherited OS values from $_ENV, allowing .env to override deployment settings. Explicit process/test overrides must win, then inherited environment, then file defaults. | Implemented; subprocess regressions, independent review and integrated suites passed |
| MIG-12 | Documented plain PHP development server returns 404 for preserved `/auth.php`. A development router must route Symfony aliases and safely serve public assets. | Implemented; independent SOL review accepted; isolated HTTP regression passed 1 test / 23 assertions; actual browser confirmed corrected startup command renders `/auth.php` |
| MIG-13 | Existing fixture router concatenated decoded paths and bypassed Apache hidden-file/private-permit protections; subdirectory requests could serve PHP source. Apply public realpath containment and protection rules in both fixture layouts. | Implemented, independent SOL review accepted; isolated HTTP test passed 1 test / 83 assertions; final integrated suite passed |

The second environment audit also fixed configured signup credits for unknown production environments and restricted simulated top-ups to canonical dev/test plus explicit opt-in. Both money-boundary regressions passed standalone (2 tests / 40 assertions), with independent review.

### Baseline and evidence boundaries

Initial restricted verification failed because sandbox denied sockets/cache/bootstrap reads. With approved execution, syntax, Composer validation/platform/audit, all three containers, 59 Twig templates, 48 routes and prod warmup passed. Database gates initially failed because no MySQL listener was running. The pre-existing isolated MariaDB data directory in ignored `var/migration-audit-20261010/mysql` was started bound to localhost; the real XAMPP data directory was not used.

Integrated baseline on that isolated server: schema fresh/stale/trusted checks passed; business suite passed 254 assertions; PHPUnit passed 138 tests / 1016 assertions. These counts precede the final session/env/tournament fixes and must not be presented as final verification. Expected provisioning-failure log output is exercised by the rollback test and did not fail the suite.

Browser smoke used only `picklers_e2e`, synthetic fixture accounts and `scripts/e2e-router.php`: landing, player sign-in, Discover, bookings/settings, sign-out and owner dashboard rendered. Screenshot inspection confirms existing styled layouts, not pixel equivalence. Additional authenticated checks and final suite results are recorded below.

Live upgrade, external payment-provider integration (none implemented), and pixel-perfect old/new comparison are outside the verified evidence. The existing simulated payment path must remain development-only. No automatic migration of live data has been performed.

### Final integrated evidence before development-router correction

`php scripts/verify.php` returned exit 0: all 13 gates passed. PHP syntax covered 184 files, strict Composer validation/platform requirements/dependency audit passed, dev/test/prod containers passed, 59 Twig templates passed, 48 Symfony routes were discovered, production warmup passed, fresh/stale/trusted schema checks passed, business tests passed 254 assertions, and PHPUnit passed 152 tests / 1097 assertions. The first attempt at the expanded suite caught an evolving rehearsal test fixture missing its dummy autoloader; the fixture was corrected and the coherent integrated rerun passed. No application checks were skipped to make the suite green.

The seeded disposable source `picklers_migration_test` was copied, installed, migrated, rolled back and restored successfully. All original attribute catalog rows/map pairs were retained; 41 expected tags were added; 29 migration facts and five full nested tournament records were reconciled. Temporary SQL dump/state were cleaned. An actual configured `doctrine:migrations:execute ... --down --dry-run` against `picklers_e2e` preserved a sentinel JSON file's SHA-256 hash. No source database was modified.

Browser checks additionally passed desktop/mobile owner layouts, supported `/app/owner?tab=settings` under the encoded XAMPP base, successful same-value settings save, owner logout and privileged admin console sign-in/rendering. `/app/owner/settings` is not a registered route and correctly returned 404; settings uses the existing query parameter. The documented plain PHP development server's `/auth.php` 404 led to MIG-12, rather than declaring local setup complete prematurely.

Graph refresh ran AST-only successfully (2025 nodes / 4273 edges at this stage). It reports 24 zero-node sources, including JSON/config files; these are graph coverage limitations, not application verification failures.

MIG-12 introduces only `scripts/dev-router.php` and its HTTP regression test. Static realpaths stay within `public`, hidden paths/traversal/private legacy permits/executable uploads are denied, PHP requests use the front controller, and public asset aliases remain compatible. The normal development command is now `php -S 127.0.0.1:8000 -t public scripts/dev-router.php`. Database/environment selection remains normal application configuration. The independent reviewer checked symlink containment in source; the test exercises traversal and private document denial, not separate Windows symlink creation.

After MIG-12, a fresh complete verification run returned exit 0: all 13 gates passed, including 186 PHP files, all three containers, Twig/routes/production warmup, schema scenarios, 254 business assertions and PHPUnit 153 tests / 1130 assertions. The production container was then explicitly cleared after the final configuration changes; an actual production-profile tournament rollback dry-run again preserved the sentinel file hash. Cleanup review subsequently found MIG-13 in the pre-existing fixture router, so that helper is being corrected before final local-readiness acceptance. Real private files were not requested to reproduce the vulnerability; regression tests use dummy markers.

### Final acceptance

MIG-13 now uses canonical public-directory containment for root, subdirectory and explicit-public URL layouts. Hidden files, traversal, NUL paths, private permits and executable uploads are denied; arbitrary PHP requests reach only the fixture front controller. The implementer, root and independent reviewer each passed the isolated HTTP regression (1 test / 83 assertions). Windows symlink creation was unavailable; the canonical target/boundary protections were independently source-reviewed.

After the final code change, the complete PHPUnit suite returned exit 0: **154 tests / 1213 assertions**. Both affected PHP files passed syntax checks, and `git diff --check` passed. The other 13-gate results above remain applicable; the final change was confined to the fixture router and its regression test. AST graph refresh completed with 2033 nodes / 4282 edges / 144 communities; the same 24 zero-node source warnings remain.

MIG-01–13 are implemented and accepted within the verified scope. No confirmed audit defect remains open. Existing uncommitted work is retained; no commit, deployment or live database migration was performed. The verified temporary PHP server was stopped and isolated audit MariaDB shut down gracefully, releasing their ports. Test artifacts remain in ignored audit/fixture directories for recovery.

Local startup instructions use `scripts/dev-router.php`; start the normal configured MySQL instance and follow the setup guide for the intended database. Live-data upgrade, external payment processing and pixel-perfect visual equivalence remain unverified. Browser smoke predates the final fixture-router hardening; its valid URL layouts are covered by the final dummy-file HTTP regression, without another visual comparison.
