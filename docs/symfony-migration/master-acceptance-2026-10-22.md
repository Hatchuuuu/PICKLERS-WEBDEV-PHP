# PICKLERS engineering and acceptance record — October 22, 2026

## Executive status

The starting working tree already contained a large uncommitted Symfony migration, Docker setup and UI repairs. That work was preserved. The application is genuinely Symfony 7.4.20: its kernel, 48 routes, dependency injection, Security, CSRF, sessions, Twig and Console serve the actual application. Doctrine DBAL repositories and eight Doctrine migrations own persistence. PHP compatibility URLs route through `public/index.php`; static frontend assets need no Node build.

This continuation found and repaired payout authorization/accounting defects and material landing/account accessibility and truthfulness gaps. Docker Desktop initially could not start; targeted recovery restored the Linux engine without resetting images, volumes, settings or unrelated projects. The normal application runs at **http://localhost:8081**, with debug and simulated payments disabled. The separate synthetic demo runs at **http://localhost:8082**. Another project's phpMyAdmin owns port 8080 and was left alone.

This is local demonstration acceptance with the evidence below. It is not certification of every possible browser interaction, formal WCAG conformance, public deployment capacity or live external financial integrations.

**Final continuation result:** all 15 Docker verification gates passed, including **287 PHPUnit tests / 2,307 assertions** and **406 service assertions**. The native full verification also passed (287 tests / 2,301 assertions). Production and demo each passed 21/21 HTTP checks again, and the authenticated multi-module demo journeys passed after host recovery. The application and demo remain running; the disposable test database was stopped after verification.

## Findings and resolutions

| Finding / priority | Evidence and user impact | Resolution | Verification / remaining status |
| --- | --- | --- | --- |
| P1 Staff could redirect venue payouts | Owner portal permitted staffed facilities and arbitrary destination fields | `requestPayout` now resolves an owned facility before validating or writing | Red/green staff and foreign staffed-owner HTTP denials, no payout writes; owner/admin requests retained — PASS |
| P1 Uncollected bookings funded platform withdrawals | All confirmed/completed booking gross contributed to `available`, including Pay at Venue/manual methods | Separate payout entitlement requires same-user linked `booking_payment` debit covering price, no linked credit, and elapsed normalized session end; retain gross reporting, 5% fee, 20% reserve and prior payout deductions | Ten financial assertions failed before; 17/17 passed after; LaunchHardening 45 assertions passed — PASS |
| P1 Owner could request payout then refund the elapsed booking | Shared staff cancellation refunded elapsed confirmed/completed bookings | Owner-facing decline evaluates its guard under the booking lock; started confirmed/completed/ended-early sessions rejected; unaccepted pending/upcoming requests remain refundable | Regression failed before; targeted owner/admin suites passed 14 tests / 84 assertions — PASS |
| P1 Withdrawal accounting failed open on SQL error | Previous payout-total read caught database exceptions and returned zero | Let database failure abort financial request instead of granting apparent availability | Source/caller review and integrated suite — PASS |
| P1 Mobile landing menu did nothing | Mobile CSS hid login/signup/theme and its toggle had no handler | Accessible menu state, Escape dismissal and link activation | Red/green frontend test; real 390px browser menu and theme interaction — PASS |
| P2 FAQ and account settings blocked keyboard users | Clickable divs without keyboard behavior; six unassociated modal labels | FAQ native buttons/expanded/hidden states; seven settings controls get button semantics, focusability and Enter/Space; six labels link to inputs | Red/green frontend tests; real keyboard opens profile modal and Escape closes — PASS for inspected controls |
| P2 Light booking labels and prices were invisible | Fixed white colors overrode light theme | Existing theme tokens, inherited subtab label colors and correct light specificity | Regression and real 1440px/390px screenshots — PASS |
| P2 Landing invented product/payment/social proof | Fake split-payment capability, testimonials, activity totals, ranking/partner claims, instant GCash/Maya top-ups, unconditional refunds | Existing capabilities and actual listed dataset counts; remove unsupported endorsements/dead destinations/cosmetic favorites; truthful external payment/approval/refund wording | Frontend copy regression and browser inspection — PASS |
| P2 Reduced-motion counter still animated | JS scheduled frames despite CSS preference | Immediately render the formatted final value when reduced motion is preferred | Regression asserts final value and zero scheduled frames — PASS |
| P2 Fresh landing had silent empty grids | Production database is intentionally empty | Explain missing facilities and Open Play, link owners to existing application | Twig rendering regression and actual fresh Docker page — PASS |
| P2 Existing Earnings screen was unreachable | Active Twig page and tested route had no visible navigation | Owner-only dashboard link preserves selected facility; staff do not see the payout trigger; restore summary padding and wrapping ledger header | Red/green functional link/role test, 1 test / 9 assertions; actual desktop/mobile owner browser inspection — PASS |
| P2 Concurrent phone claim surfaced generic signup error | InnoDB's retained deadlock at 01:40:56 matched the failed signup/profile test; signup INSERT lost on `uniq_users_phone` | Retry a failed standalone user INSERT/UPDATE once on DBAL retryable exceptions; caller-owned transactions propagate failure unchanged | Deterministic injected-deadlock tests failed 4/4 before; retry plus real phone races passed 7 tests / 45 assertions; independent review passed; final integrated rerun recorded below |
| P1 Docker runtime unavailable | Stale socket reparse points, stale WSL bootstrap and an overmount hid the data disk; bootstrap reported misleading ENOSPC | Back up runtime socket directories, stop only failed Docker processes, terminate only `docker-desktop` WSL distro, restart Desktop | Engine 29.5.2, healthy app/database and actual Docker tests — PASS |
| P2 Demo setup was not reproducible with authorized sample identities | Normal production intentionally has no demo users | `compose.demo.yaml`, dedicated project/database and documented synthetic fixture accounts | Fresh demo initialization, actual authenticated HTTP journeys and populated-volume persistence — PASS |

Legacy credits lacking authoritative linked ledger metadata now require reconciliation before withdrawal. Historical transactions were not fabricated or rewritten. Exceptional administrator refunds remain governed/audited operations; this app does not implement a bank settlement processor.

## Skill inventory and contribution

The installed catalog and actual `SKILL.md` files were inspected. User instructions to execute ordinary reversible work took precedence over skill approval/plan-only gates. No optional skill was installed.

| Exact installed name | Location | Actual contribution |
| --- | --- | --- |
| using-superpowers | `C:/Users/Nitro/.agents/skills/using-superpowers/SKILL.md` | Process-skill discovery and precedence |
| ponytail:ponytail | `C:/Users/Nitro/.codex/plugins/cache/ponytail/ponytail/5.3.2/skills/ponytail/SKILL.md` | Minimal changes, caller checks, no new dependencies or speculative redesign |
| graphify | `C:/Users/Nitro/.agents/skills/graphify/SKILL.md` | Scoped architecture/workflow queries before source navigation; AST update after changes |
| systematic-debugging | `C:/Users/Nitro/.agents/skills/systematic-debugging/SKILL.md` | Reproduce, trace root cause, isolate correction, retain failing evidence |
| test-driven-development | `C:/Users/Nitro/.agents/skills/test-driven-development/SKILL.md` | Payout, refund, menu, labels, theme and reduced-motion red/green regressions |
| verification-before-completion | `C:/Users/Nitro/.agents/skills/verification-before-completion/SKILL.md` | Fresh output, separate implementation/source/runtime claims |
| requesting-code-review | `C:/Users/Nitro/.agents/skills/requesting-code-review/SKILL.md` | Independent security/backend and UX agents; payout/refund loophole found and corrected |
| brainstorming; writing-plans | Respective directories under `C:/Users/Nitro/.agents/skills/` | Existing behavior and smallest bounded remedies; execution checklist in `docs/superpowers/plans/2026-10-22-master-acceptance.md` |
| impeccable | `C:/Users/Nitro/.agents/skills/impeccable/SKILL.md` | Actual `scripts/impeccable.cmd context` succeeded: `NO_PRODUCT_MD`, incumbent implementation, `SCOPED_EXISTING_ALLOWED`; audit/craft-floor guidance used |
| ui-ux-pro-max | `C:/Users/Nitro/.agents/skills/ui-ux-pro-max/SKILL.md` | Actual `python scripts/search.py 'contrast light theme keyboard focus motion' --domain ux -n 3`; focus appearance/states/not-obscured guidance assessed, without claiming AAA compliance |
| emil-design-eng | `C:/Users/Nitro/.agents/skills/emil-design-eng/SKILL.md` | Purposeful existing motion; reduced-motion final state, no new decorative animation |

No dedicated installed Symfony/PHP, Docker/DevOps or general application-security skill was found in the available catalog. Their work used repository evidence, independent review, existing tools and official documentation. The native-Windows computer-use skill was inspected; browser verification used the supported CUA browser APIs, not native Windows UI automation.

Authoritative research: [Symfony 7.4 setup](https://symfony.com/doc/7.4/setup.html), [Symfony releases](https://symfony.com/releases), [Docker dependency readiness](https://docs.docker.com/compose/how-tos/startup-order/), [OWASP authorization](https://cheatsheetseries.owasp.org/cheatsheets/Authorization_Cheat_Sheet.html). Existing compatible framework/dependency constraints were retained.

The late concurrency correction also follows [Doctrine DBAL's documented retryable exception boundary](https://www.doctrine-project.org/projects/doctrine-dbal/en/4.4/reference/transactions.html#error-handling). Only the single standalone statement is retried, once; a caller-owned transaction is never partially restarted.

## Architecture, schema and implementation record

No framework rewrite, dependency upgrade or schema migration was necessary for these fixes. Existing normal XAMPP data was not used for test fixtures, dropped, reset, imported or migrated. Existing Docker application data stayed in its named volumes; the production application currently has zero users. Fresh demo setup applied all eight migrations; disposable schema verification tested fresh/stale bootstrap behavior. There are no ORM entities to reconcile: the current architecture deliberately uses DBAL, SQL repositories and a shared schema installer.

| Meaningful files | Change |
| --- | --- |
| `src/Repositories/OwnerRepository.php` | Collected/elapsed payout entitlement; financial reads fail closed |
| `src/Web/Api/OwnerPortalActions.php` | Payout ownership boundary |
| `src/Web/Api/OwnerActions.php`; `src/Domain/BookingRules.php` | Locked owner refund eligibility; retain unaccepted and governed admin paths |
| `src/Repositories/UserRepository.php`; `tests/Web/UserWriteRetryTest.php`; `PhoneConcurrencyTest.php` | One bounded standalone-write retry; deterministic retry/transaction tests and retained worker failure diagnostics |
| `templates/web/home.html.twig`; `public/assets/css/style.css` | Mobile navigation, accessible FAQ, truthful copy/counts, reduced motion and empty states |
| `public/assets/css/app.css` | Shared theme-aware prices and booking subtab contrast |
| `templates/web/partials/app/_modals.html.twig`; `_tab-settings.html.twig` | Associated labels and keyboard settings actions |
| `templates/web/partials/owner/_tab-dashboard.html.twig`; `_tab-earnings.html.twig`; `public/assets/css/owner.css`; `tests/Web/OwnerPagesTest.php` | Reachable owner earnings, staff payout-trigger exclusion, accurate eligibility explanation, responsive summary spacing |
| `tests/PayoutCollectionTest.php`; `tests/LaunchHardeningTest.php` | Real collection eligibility and retained revenue/refund regression coverage |
| `tests/Web/PayoutAuthorizationTest.php`; `PayoutRefundBoundaryTest.php`; `PagesTest.php` | Real HTTP authorization/refund boundaries; empty landing rendering |
| `tests/Frontend/landing-controls.test.js`; `booking-theme.test.js`; `auth-accessibility.test.js` | Interaction/state/copy/theme/keyboard regressions |
| `compose.demo.yaml`; `docs/local-demo.md`; `README.md` | Isolated reproducible professor demonstration and verified port guidance |

The private ignored `.env.docker` changed only its HTTP port from 8080 to the verified-free 8081. Passwords were retained. Existing unrelated working-tree changes were not committed, reset or overwritten.

## Testing and runtime evidence

Local evidence is under ignored `var/master-20261022-*`. These are actual outputs, not fixtures for claimed results.

| Command/check | Actual result |
| --- | --- |
| `php scripts/verify.php --quick` | All quick gates passed: syntax, Composer strict/platform/audit, frontend regressions, three Symfony environments, 59 Twig templates, 48 routes, prod warmup |
| `php tests/run.php --fresh` | 406 assertions passed on disposable `picklers_test` |
| `php vendor/bin/phpunit` before final pending-preservation addition | 280 tests / 2,274 assertions passed |
| Targeted final payout/refund/admin regressions | 14 tests / 84 assertions passed |
| `docker compose --env-file .env.docker --profile test run --build --rm test` on final backend snapshot | All gates passed; service suite 406 assertions; PHPUnit 281 tests / 2,283 assertions |
| Final empty-state rendering regression | 1 test / 3 assertions passed; container Twig lint passed |
| Final Earnings navigation/role regression | 1 test / 9 assertions passed |
| Continuation standalone-write retry + real phone races | Native 7 tests / 45 assertions passed after deterministic 4-test red run |
| Final continuation native `php scripts/verify.php` | All gates passed; 287 tests / 2,301 assertions and 406 service assertions, including the deadlock fix |
| Final continuation Docker full verification | All 15 gates passed; 287 tests / 2,307 assertions and 406 service assertions, including deterministic user-write retry and real phone races |
| Continuation production/demo HTTP and authenticated journeys | Both 21/21 HTTP checks passed; expanded booking, Open Play, community, tournament and notification journeys passed again |
| `docker compose ... config --quiet` | Production, development/test and demo configurations validate |
| Production/test/development image builds | Passed on PHP 8.2.35 / Apache, development includes Node; no host `.env`/data copied |
| `docker compose --env-file .env.docker up --build --wait app` | Setup exited 0; app/database healthy, 127.0.0.1:8081 -> 8080 |
| `php scripts/docker-smoke.php http://127.0.0.1:8081` | 21/21 after production replacement |
| `php scripts/docker-smoke.php http://127.0.0.1:8082` | 21/21 for isolated development demo |
| Actual Node HTTP journeys against Docker demo | Four synthetic identities, guest/CSRF denial, favorites, quote/book/debit/collision/foreign-owner denial/approval/cancel/single refund, owner/admin page rendering, Open Play host/join/refund/cleanup, community likes/messages/read receipts, Singles entrants/draw/result/champion/delete, notification-read persistence all passed |
| Populated demo application and database container replacement | Same 22-user, booking and wallet-ledger fingerprints plus five file-volume markers survived; markers removed afterwards |
| Production container replacement | Same empty user-table fingerprint plus five file-volume markers survived; markers removed afterwards |
| Browser CUA | Inspected landing/auth/player discovery/bookings/settings and owner dashboard; desktop 1440x900 and mobile 390x844; landing/player light/dark; actual menu, theme, FAQ Enter, settings Enter and modal Escape |
| Independent review | Staff payout defect, financial eligibility, refund cycle and expiry-preservation reviewed; no further actionable defect reported in changed backend scope |

The initial native audit caught a deliberately failing newly added label test while its fix was being implemented. A Docker image build captured a new elapsed-pending test before the matching correction and failed that regression; rebuilding with the coherent backend passed. A later full 283-test Docker run exposed the genuine phone-race deadlock described above (one failure); it was not accepted as final success. One Docker registry token request returned HTTP 522; a retry succeeded. These failures were investigated rather than suppressed. Expected provisioning/publication failure logs come from rollback tests and do not indicate a failing suite by themselves.

## UI/UX and motion assessment

Preserved the existing PICKLERS logo, green/navy brand, fonts, imagery, page structure and density. Operational correctness and readability took priority over a visual replacement.

| Before | After | Why |
| --- | --- | --- |
| Dead mobile menu hid sign-in/sign-up | Working expanded menu with Escape dismissal | Restore essential mobile access |
| Click-only FAQ/settings | Keyboard activation and meaningful state/labels | Make existing tasks reachable |
| Fixed white prices/subtabs in light mode | Existing semantic tokens and inherited colors | Restore readable information |
| JS counter always animated | Reduced-motion preference shows final value immediately | Preserve information without motion |
| Fictional statistics/testimonials/payments | Sourced listing counts and existing capabilities | Align UI claims with actual product behavior |
| Unreachable Earnings page and unpadded summary cards | Owner-only dashboard link, padded cards and wrapping ledger heading | Expose the existing workflow with readable mobile content |

The site retains some incumbent glow/card decoration. No unsupported “award-winning”, exhaustive contrast, physical touch-device or frame-performance claim is made. Responsive viewport inspection is browser emulation, not a hardware gesture test. Full formal accessibility certification and manual coverage of every control/page/theme combination remain UNVERIFIED.

## Acceptance matrix

| Category / criterion | Status | Evidence or boundary |
| --- | --- | --- |
| Framework identified; genuine lifecycle/routing/DI/security/Twig; compatibility entry points | PASS | Kernel/routes/container lint and actual Apache requests |
| Existing business behavior preserved, except explicitly corrected security/financial eligibility | PASS | Service/integration regression suites; corrections above |
| Functional modules have explicit test anchors | PASS | Account/auth/Discover/booking/wallet/community/owner/staff/tournament/admin suites and HTTP journeys |
| Valid/invalid input, role boundaries, transactions and persistence | PASS | Functional/concurrency/security tests and booking/ledger HTTP journey |
| Every essential control manually clicked in every browser/theme | UNVERIFIED | Representative real browser inspection plus automated tests; no exhaustive manual claim |
| Consistent UI, important loading/error/empty states, confirmations | PASS for assessed scope | Existing regressions, repaired empty landing and keyboard/modal checks |
| Light/dark responsive landing/player; owner desktop | PASS for inspected views | CUA screenshots/AX at 1440 and 390 widths |
| Formal WCAG/physical touch/performance conformance | UNVERIFIED | No conformance certification or hardware run |
| Existing schema/migrations/bootstrap; no data destruction | PASS | Eight current migrations, disposable bootstrap and persistence checks |
| Import and upgrade of real XAMPP personal data | NOT APPLICABLE to new Docker demo; UNVERIFIED for future import | No real-data migration attempted |
| Compose/build/start/readiness/network/ports/PHP/Symfony/database | PASS | Healthy services, console, migrations, 21/21 HTTP in prod/dev |
| Database/files survive intended container replacement | PASS | Empty production and populated demo fingerprints/markers |
| Repeatable setup/fixtures/logs/shutdown instructions | PASS | README and local-demo guide, actual commands exercised |
| Static, unit/service, functional/integration and changed-flow regressions | PASS | Measured suites above |
| Independent implementation review and resolved identified P1 findings | PASS | Security/UX reviews and red/green corrections |
| Live payment/SMS/social login/recovery-provider integrations | BLOCKED / outside local demo | No configured verified production providers; unavailable features remain explicit/fail closed |
| Public deployment, capacity, backup/restore disaster rehearsal | UNVERIFIED | Domain/TLS, operator monitoring, real backup/restore and load certification require separate deployment work |

## Local startup and troubleshooting

Follow [README](../../README.md) for the normal application and [local demo](../local-demo.md) for fixture identities. The live normal setup uses `.env.docker` port 8081; this file remains ignored/private. Run:

```powershell
docker compose --env-file .env.docker up --build --wait app
docker compose --env-file .env.docker ps --all
docker compose --env-file .env.docker logs --tail=100 app setup database
docker compose --env-file .env.docker exec app php bin/console doctrine:migrations:status
docker compose --env-file .env.docker --profile test run --build --rm test
docker compose --env-file .env.docker down
```

Use `down` without `--volumes`; production DB/files/session/secret volumes persist. The database has no exposed host port. The test profile uses a different tmpfs MariaDB service and disposable database names. The separate demo project is `picklers-acceptance-20261022` for this run; use `picklers-demo` for a newly created classroom installation.

Docker recovery preserved runtime socket backups beneath `%LOCALAPPDATA%/Docker/` and `%LOCALAPPDATA%/docker-secrets-engine*`. No cleanup of these backups, global WSL shutdown, image prune or data-disk resize was performed. The corrected data disk had approximately 941 GiB virtual free space; the erroneous ENOSPC came from a tiny distro root overmount. If the host problem returns, inspect Desktop logs/mounts before resetting data.

On the morning continuation, the same Desktop socket fault recurred after the host session had stopped Docker. Verified runtime-only socket directories were again moved intact to timestamped `run.recovery-*` / `docker-secrets-engine.recovery-*` backups and recreated; the Linux engine recovered without WSL termination this time. This recurring **host Docker Desktop issue is not permanently fixed by application changes**. Keep the backups; do not factory-reset or prune volumes to address it. If it recurs, fully quit the failed Desktop instance, inspect the specific runtime sockets and preserve/rename only those runtime directories before restarting, or use vendor support. All final application verification requires a healthy engine first.

## Remaining limits

Real-money collection and external disbursement are not implemented/verified integrations. Legacy metadata-free collections require reconciliation. Synthetic demo facilities/reviews/accounts are demonstration data, not endorsements or production activity. Native XAMPP PHP remains 8.2.12; verified Docker PHP is 8.2.35. No dependency or host-runtime upgrade was silently performed. Exhaustive manual control coverage, real hardware camera/QR/GPS, external provider calls, public hosting and disaster restore remain unverified. Docker Desktop's recurring host socket fault may require operator recovery on a future restart. No known fixable P0/P1 defect found in the changed application scope remains concealed by these qualifications.
